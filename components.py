#! /usr/bin/env python3

"""
Build an OpenApi spec.

It's intended to be called by configd, but CLI args will be added.

Calls `parse_endpoints.py` and `parse_xml_models.py` if their cached JSON output is not found.
"""

import argparse
import os
import json
import pathlib
import yaml
from collections import defaultdict
from typing import Any, Dict, List, Literal, Tuple, TypeAlias, Callable, Generic, TypeVar, cast


_DEFAULT_SOURCE_FOLDER = "/gitroot/upstream/opnsense/core/src/opnsense/mvc/app/"
_DEFAULT_MODEL_OUTPUT_FILE = f"{os.path.dirname(__file__)}/models.json"


SchemaDict =  Dict[str, "SchemaDict | str | bool | List[str | int]"]



def get_spec(models: SchemaDict) -> SchemaDict:
    return dict(
        openapi="3.1.0",
        info=dict(
            title="OPNsense API",
            version="25.1",
            description="API for managing your OPNsense firewall",
        ),
        components=dict(
            schema=models,
        )
    )


def validate_spec(spec: SchemaDict):
    print("Validating...")
    from openapi_schema_validator.validators import OAS31Validator
    OAS31Validator.check_schema(spec)
    print(" ...spec passed validation.")


def generate_openapi_spec(
    source_folder: str,
    should_validate: bool = False,
    module: str | None = None,
    controller: str | None = None,
    cache_folder: str | None = None,
) -> SchemaDict:

    with open(_DEFAULT_MODEL_OUTPUT_FILE) as file:
        json_data = file.read()
    models = json.loads(json_data)

    spec = get_spec(models)

    # validation is slow
    if should_validate:
        validate_spec(spec)

    return spec


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="generate an OpenApi spec")
    parser.add_argument("-s", "--schema-file", default="schemas.json")
    parser.add_argument("-o", "--output-file", default="openapi.yml")
    parser.add_argument("-m", "--module", help="filter endpoints by module")
    parser.add_argument("-c", "--controller", help="filter endpoints by controller name (excluding Controller suffix)")
    parser.add_argument("--cache-folder", default=None)
    parser.add_argument("-v", "--validate", action="store_true")
    args = parser.parse_args()

    output_file: str = os.path.realpath(args.output_file)

    spec = generate_openapi_spec(
        source_folder=args.source_folder,
        should_validate=args.validate,
        module=args.module,
        controller=args.controller,
        cache_folder=os.path.realpath(args.cache_folder) if args.cache_folder else None,
    )

    output_file_ext = output_file.split(".")[-1]
    if output_file_ext.lower() in ("yml", "yaml"):
        content = yaml.dump(spec)
    else:
        import json
        content = json.dumps(spec)

    pathlib.Path(output_file).parent.mkdir(parents=True, exist_ok=True)
    with open(output_file, "w") as file:
        file.write(content)
