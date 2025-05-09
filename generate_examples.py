#! /usr/bin/env python3

"""
Build an OpenApi spec.

It's intended to be called by configd, but CLI args will be added.

Calls `parse_endpoints.py` and `parse_xml_models.py` if their cached JSON output is not found.
"""

import argparse
import os
import re
from re._constants import (
    ANY,
    AT,
    BRANCH,
    CATEGORY,
    CATEGORY_DIGIT,
    CATEGORY_WORD,
    IN,
    LITERAL,
    MAX_REPEAT,
    MAXREPEAT,
    MIN_REPEAT,
    NEGATE,
    NOT_LITERAL,
    RANGE,
    SUBPATTERN,
)
import json
import pathlib
import yaml
import random
import sys

sys.path.insert(0, f"{os.path.dirname(__file__)}/lib/python3.11/site-packages")

from niltype import Nil, Nilable
from collections import defaultdict
from typing import *
from pprint import pformat
from uuid import uuid4

from openapi_schema_validator.validators import OAS31Validator
from openapi_schema_validator import validate as _validate
from jsonschema.exceptions import _Error, SchemaError, ValidationError, best_match
from blahblah import RegexGenerator, Random

_T = TypeVar("_T")
SeedType = TypeVar("SeedType", int, float, str, bytes, bytearray)

SchemaType = Literal["string"] | Literal["number"] | Literal["integer"] | Literal["boolean"] | Literal["array"] | Literal["object"]
SchemaPrimitive = str | int | float | bool | None
SchemaDict =  Dict[str, "SchemaPrimitive | SchemaDict | List[SchemaPrimitive | SchemaDict]"]
Example = SchemaPrimitive | List["Example"] | Dict[str, "Example"]

class Schema(TypedDict):
    type: SchemaType
    items: NotRequired["Schema"]
    required: NotRequired[List[str]]
    properties: NotRequired[Dict[str, "Schema"]]
    additionalProperties: NotRequired["bool | Schema"]
    patternProperties: NotRequired[Dict[str, "Schema"]]
    enum: NotRequired[List[SchemaPrimitive]]
    pattern: NotRequired[str]
    minimum: NotRequired[int | float]
    maximum: NotRequired[int | float]
    example: NotRequired[SchemaPrimitive]
    description: NotRequired[str]


TYPE_CASTERS = dict(
    string=str,
    number=lambda val: float(val) if val else 0.0,
    integer=lambda val: int(val) if val else 0,
    boolean=bool,
)


FAKE_UUID = "00000000-0000-0000-0000-000000000000"


def partial(**kwargs) -> Dict[str, Example]:
    return dict(__PARTIAL__=True, **kwargs)


EXAMPLES: Dict[str, Example] = {
    # f"opnsense.cron.cron.jobs.job.{FAKE_UUID}.minutes": 42,
    # f"opnsense.cron.cron.jobs.job.{FAKE_UUID}.hours": 3,
    # f"opnsense.cron.cron.jobs.job.{FAKE_UUID}.days": 3,
    # f"opnsense.cron.cron.jobs.job.{FAKE_UUID}.months": 3,
    # f"opnsense.cron.cron.jobs.job.{FAKE_UUID}.weekdays": 3,
    f"opnsense.cron.cron.jobs.job.{FAKE_UUID}": partial(
        minutes="42",
        hours="3",
        days="3",
        months="3",
        weekdays="3",
    )
}


def get_example(path: str, example: Dict[str, Example] = EXAMPLES):
    keys = [k for k in example.keys() if path.startswith(k)]
    if not keys:
        raise KeyError(path)

    key = sorted(keys, key=len)[-1]
    _example = example[key]
    is_dict = isinstance(_example, Mapping)

    if key == path:
        if is_dict and _example.get("__PARTIAL__"):
            raise KeyError(path)
        return _example

    if not is_dict:
        raise KeyError(path)

    sub_path = re.sub(fr"^{re.escape(key)}\.", "", path)
    try:
        return get_example(sub_path, _example)
    except KeyError:
        raise KeyError(path)


class Deterministic(Random):
    def __init__(self, seed: str):
        self.set_seed(seed)

    def random_int(self, start: int, end: int) -> int:
        start, end = sorted([start, end])
        diff = end - start
        if diff > 0:
            diff = int(pow(diff, 0.4))
            return start + diff
        return start

    def random_str(self, length: int, alphabet: str) -> str:
        chars = list(alphabet)
        for m in ("isprintable", "isascii", "isalnum", "isalpha"):
            method = getattr(str, m)
            _chars = [char for char in chars if method(char)]
            if _chars: chars = _chars
        return "".join(random.choice(chars) for _ in range(length))


class ReadableRegexGenerator(RegexGenerator):
    def _generate_any(self, value: None) -> str:
        return self._random.random_choice(self._alphabet["word"])

    def _generate_in(self, value: List[Any]) -> str:
        (opcode, val), *other = value
        if opcode == NEGATE:
            return self._generate_not_in(other)

        alphabet = ""
        for opcode, val in value:
            if opcode == LITERAL:
                alphabet += chr(val)
            elif opcode == RANGE:
                start, end = val
                if end > 255:
                    end = self._random.random_int(start, end)
                alphabet += "".join(chr(i) for i in (range(start, end)))
            elif opcode == CATEGORY:
                alphabet += self._get_category_alphabet(val)
            else:
                print(f"{self.__class__.__name__}: Not generating a readable string for {opcode}: deferring to super")
                return super(ReadableRegexGenerator, self)._generate_in(value)
        return self._random.random_str(1, alphabet)


def generate_string(pattern: str | None = None) -> str:
    if pattern == "^[a-zA-Z0-9]{8}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{12}$":
        return FAKE_UUID
    elif pattern is None:
        pattern = r"[a-z0-9]{8}"

    try:
        compiled = re.compile(pattern)
    except re.PatternError as ex:
        ex.add_note(pattern)
        raise ex.with_traceback(None)
    generator = ReadableRegexGenerator(Deterministic(str(pattern)))
    value = generator.generate(pattern)
    assert compiled.match(value), f"generated string '{value}' does not match '{pattern}'"
    return value


def make(
    path: str,
    type: SchemaType | None = None,  # required, but we validate in the body
    oneOf: List[Schema] | None = None,
    items: Optional["Schema"] = None,
    required: Optional[List[str]] = None,
    properties: Optional[Dict[str, "Schema"]] = None,
    additionalProperties: Optional["bool | Schema"] = None,
    patternProperties: Optional[Dict[str, "Schema"]] = None,
    enum: Optional[List[SchemaPrimitive]] = None,
    pattern: Optional[str] = None,
    minimum: Optional[int | float] = None,
    maximum: Optional[int | float] = None,
    example: Optional[SchemaPrimitive] = None,
    description: Optional[str] = None,
    **kwargs,
) -> Example:

    try:
        return get_example(path)
    except KeyError:
        pass

    try:
        if type is None:
            assert oneOf is not None, "must have type or oneOf"
            assert len(oneOf) > 1, "oneOf cannot be empty"
            return make(f"{path}.oneOf", **oneOf[0])

        if kwargs:
            assert not any(k for k in kwargs if not k.startswith("x-"))

        if type == "object":
            if isinstance(additionalProperties, Mapping):
                assert properties is None, f"'properties' is invalid with 'additionalProperties'"
                assert patternProperties is None, f"'patternProperties' is invalid with 'additionalProperties'"
                properties = {generate_string(): additionalProperties}

            elif isinstance(patternProperties, Mapping):
                assert properties is None, f"'properties' is invalid with 'patternProperties'"
                assert additionalProperties in (None, False, True), f"'additionalProperties' is invalid with 'patternProperties'"
                properties = {generate_string(_pattern): _schema for _pattern, _schema in patternProperties.items()}

            else:
                assert isinstance(properties, Mapping), f"'properties' is required to be Mapping"

            if required:
                properties = {k: v for k, v in properties.items() if k in required}

            return {k: make(f"{path}.{k}", **_schema) for k, _schema in properties.items()}

        elif type == "array":
            assert items is not None, f"'items' is required in '{type}'"
            return [make(path, **items)]

        else:
            if example is not None:
                value = TYPE_CASTERS[type](example)
                if pattern:
                    assert re.match(pattern, str(value)), f"'{value}' does not match 'pattern' ({pattern})"
                if enum:
                    assert value in enum, f"'{value}' is not in 'enum' ({enum})"
            elif enum:
                value = enum[0]
            elif type == "string":
                value = generate_string(pattern)
            elif minimum:
                value = minimum
            else:
                value = TYPE_CASTERS[type](None)
            return value

    except Exception as ex:
        notes = getattr(ex, "__notes__", None)
        if not (notes and path in notes[-1]):
            ex.add_note(path)
        raise



def generate_examples(schemas: Dict[str, Schema], should_validate: bool = False) -> Dict[str, Schema]:
    examples = {}
    for model_name, schema in schemas.items():
        if should_validate:
            validate_schema(schema, model_name)
        example = make(model_name, **schema)
        if should_validate:
            validate(schema, example, model_name)
        examples[model_name] = example

    return examples


def validate_schema(schema: Schema, model_name: str = ""):
    try:
        print(f"Validating {model_name}...", end=" ")
        OAS31Validator.check_schema(schema)
        print("passed.")
    except _Error as ex:
        print("failed.")
        if model_name:
            ex.path.appendleft(model_name)
        raise ex.with_traceback(None)


def format(instance) -> str:
    formatted = str(instance)
    if len(formatted) > 80:
        formatted = formatted[0:77] + " ..."
    return formatted


def validate(schema: Schema, instance: Example, model_name: str = ""):
    try:
        print(f"Validating {format(instance)} against {model_name}...", end=" ")
        validator = OAS31Validator(schema)
        error = best_match(validator.iter_errors(instance))
        if error is not None:
            raise error
        print("passed.")
    except _Error as ex:
        print("failed.")
        if model_name:
            ex.path.appendleft(model_name)
        raise ex.with_traceback(None)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="generate an OpenApi spec")
    parser.add_argument("-s", "--schema-file", default="schemas.json")
    parser.add_argument("-o", "--output-file", default="examples.json")
    parser.add_argument("models", action="extend", nargs="*")
    parser.add_argument("-m", "--module", help="filter endpoints by module")
    parser.add_argument("-c", "--controller", help="filter endpoints by controller name (excluding Controller suffix)")
    parser.add_argument("--cache-folder", default=None)
    parser.add_argument("-v", "--validate", action="store_true")
    args = parser.parse_args()

    schema_file: str = os.path.realpath(args.schema_file)
    output_file: str = os.path.realpath(args.output_file)

    with open(schema_file) as file:
        schema_content = file.read()
    schemas = json.loads(schema_content)

    if args.models:
        schemas = {name: schema for name, schema in schemas.items() if name in args.models}

    examples = generate_examples(schemas, should_validate=args.validate)

    content = json.dumps(examples, indent=4)

    pathlib.Path(output_file).parent.mkdir(parents=True, exist_ok=True)
    with open(output_file, "w") as file:
        file.write(content)
