#! /usr/bin/env python3

"""
Build an OpenApi spec.

It's intended to be called by configd, but CLI args will be added.

Calls `parse_endpoints.py` and `parse_xml_models.py` if their cached JSON output is not found.
"""

import argparse
import os
import re
import json
import pathlib
import yaml
from collections import defaultdict
from typing import *
from pprint import pformat

from openapi_schema_validator.validators import OAS31Validator
from jsonschema.exceptions import _Error, SchemaError, ValidationError, best_match
from regex_string_generator import generate_string as _generate_string


SchemaType = Literal["string"] | Literal["number"] | Literal["integer"] | Literal["boolean"] | Literal["array"] | Literal["object"]
SchemaPrimitive = str | int | float | bool | None
SchemaDict =  Dict[str, "SchemaPrimitive | SchemaDict | List[SchemaPrimitive | SchemaDict]"]
Sample = SchemaPrimitive | List["Sample"] | Dict[str, "Sample"]

from pydantic import BaseModel
# class Schema(BaseModel):
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


def foo(type, items, required, properties, additionalProperties, patternProperties, pattern, minimum, maximum):
    pass


class SampleGenerationError(Exception):
    pass


# def type_cast(value)
TYPE_CASTERS = dict(
    string=str,
    number=lambda val: float(val) if val else 0.0,
    integer=lambda val: int(val) if val else 0,
    boolean=bool,
    # array
    # object
)


FAKE_UUID = "00000000-0000-0000-0000-000000000000"
from uuid import uuid4
KEYS_TO_TRY = [
    "mock_key",
    uuid4,
    "opt7"
]
def get_key(pattern: str | None = None) -> str:
    for key in KEYS_TO_TRY:
        if isinstance(key, Callable):
            key = key()
        key = str(key)
        if pattern:
            if re.match(pattern, key):
                return key
        else:
            return key
    raise ValueError(f"No sample key matched '{pattern}")


def generate_string(pattern: str | None = None, *args, **kwargs) -> str:
    _pattern = pattern or r"[a-z0-9]{8}"
    return _generate_string(_pattern, *args, **kwargs)


def make(
    type: SchemaType,
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
) -> Sample:

    if kwargs:
        assert not any(k for k in kwargs if not k.startswith("x-"))

    try:
        match type:
            case "object":
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

                # skip optional properties
                if required:
                    properties = {k: v for k, v in properties.items() if k in required}

                return {k: make(**_schema) for k, _schema in properties.items()}

            case "array":
                assert items is not None, f"'items' is required in '{type}'"
                return [make(**items)]

            case _:
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

    except AssertionError as ex:
        raise ex.with_traceback(None)



def generate_samples(schemas: Dict[str, Schema], should_validate: bool = False) -> Dict[str, Schema]:
    samples = {}
    for model_name, schema in schemas.items():
        if should_validate:
            validate_schema(schema, model_name)
        samples[model_name] = make(**schema)

    return samples


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


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="generate an OpenApi spec")
    parser.add_argument("-s", "--schema-file", default="schemas.json")
    parser.add_argument("-o", "--output-file", default="sample_data.json")
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

    model_name = "opnsense.auth.group"
    # schemas = {model_name: schemas[model_name]}

    samples = generate_samples(schemas, should_validate=args.validate)

    content = json.dumps(samples, indent=4)

    pathlib.Path(output_file).parent.mkdir(parents=True, exist_ok=True)
    with open(output_file, "w") as file:
        file.write(content)
