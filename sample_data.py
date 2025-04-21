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
from uuid import uuid4

from openapi_schema_validator.validators import OAS31Validator
from openapi_schema_validator import validate as _validate
from jsonschema.exceptions import _Error, SchemaError, ValidationError, best_match
from regex_string_generator import generate_string as _generate_string


SchemaType = Literal["string"] | Literal["number"] | Literal["integer"] | Literal["boolean"] | Literal["array"] | Literal["object"]
SchemaPrimitive = str | int | float | bool | None
SchemaDict =  Dict[str, "SchemaPrimitive | SchemaDict | List[SchemaPrimitive | SchemaDict]"]
Sample = SchemaPrimitive | List["Sample"] | Dict[str, "Sample"]

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


def partial(**kwargs) -> Dict[str, Sample]:
    return dict(__PARTIAL__=True, **kwargs)


SAMPLES: Dict[str, Sample] = {
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
# private static array $EXAMPLES = [
#         "OPNsense\\Auth\\FieldTypes\\ApiKeyField" => "l5NS9+rPKAb2LseJZzdCnY/BXpIHtGgjaVTWiFkquRD04c78mUExMo3y1fwhv6QO",
#         "OPNsense\\Auth\\FieldTypes\\UsernameField" => "hopper",
#         // "OPNsense\\Base\\FieldTypes\\ArrayField" => "",
#         //   "OPNsense\\Core\\FieldTypes\\TunableField" => "",
#         //   "OPNsense\\Firewall\\FieldTypes\\AliasField" => "",
#         //   "OPNsense\\Firewall\\FieldTypes\\FilterRuleField" => "",
#         //   "OPNsense\\Firewall\\FieldTypes\\GroupField" => "",
#         //   "OPNsense\\Firewall\\FieldTypes\\SourceNatRuleField" => "",
#         //   "OPNsense\\IDS\\FieldTypes\\PolicyRulesField" => "",
#         //   "OPNsense\\Interfaces\\FieldTypes\\NeighborField" => "",
#         //   "OPNsense\\Interfaces\\FieldTypes\\VipField" => "",
#         //   "OPNsense\\IPsec\\FieldTypes\\ConnnectionField" => "",
#         //   "OPNsense\\IPsec\\FieldTypes\\SPDField" => "",
#         //   "OPNsense\\IPsec\\FieldTypes\\VTIField" => "",
#         //   "OPNsense\\OpenVPN\\FieldTypes\\InstanceField" => "",
#         //   "OPNsense\\Routing\\FieldTypes\\GatewayField" => "",
#         //   "OPNsense\\Trust\\FieldTypes\\CAsField" => "",
#         //   "OPNsense\\Trust\\FieldTypes\\CertificatesField" => "",
#         //   "OPNsense\\Wireguard\\FieldTypes\\ClientField" => "",
#         //   "OPNsense\\Wireguard\\FieldTypes\\ServerField" => "",
#         "OPNsense\\Base\\FieldTypes\\AutoNumberField" => 99,
#         //   "OPNsense\\Firewall\\FieldTypes\\FilterSequenceField" => "99",
#         // "OPNsense\\Base\\FieldTypes\\BaseListField" => "",
#           "OPNsense\\Auth\\FieldTypes\\GroupMembershipField" => "docker,libvirt",
#           "OPNsense\\Auth\\FieldTypes\\MemberField" => "hopper,billj",
#           "OPNsense\\Auth\\FieldTypes\\PrivField" => "logon",
#           "OPNsense\\Base\\FieldTypes\\AuthenticationServerField" => "luna,krb01",
#           "OPNsense\\Base\\FieldTypes\\AuthGroupField" => "vpn_users",
#           "OPNsense\\Base\\FieldTypes\\CertificateField" => "",
#           "OPNsense\\Base\\FieldTypes\\ConfigdActionsField" => "configctl template reload openapi",
#           "OPNsense\\Base\\FieldTypes\\CountryField" => "NL",
#           "OPNsense\\Base\\FieldTypes\\InterfaceField" => "opt99",
#           "OPNsense\\Base\\FieldTypes\\JsonKeyValueStoreField" => "/bin/bash",
#         //   "OPNsense\\Base\\FieldTypes\\ModelRelationField" => "",
#           "OPNsense\\Base\\FieldTypes\\NetworkAliasField" => "rfc1918",
#         //   "OPNsense\\Base\\FieldTypes\\OptionField" => "",
#           "OPNsense\\Base\\FieldTypes\\PortField" => "853",
#           "OPNsense\\Base\\FieldTypes\\ProtocolField" => "TCP",
#         //   "OPNsense\\Base\\FieldTypes\\VirtualIPField" => "",
#         //   "OPNsense\\Diagnostics\\FieldTypes\\InterfaceField" => "",
#         //   "OPNsense\\Firewall\\FieldTypes\\InterfaceField" => "",
#         //   "OPNsense\\Firewall\\FieldTypes\\ScheduleField" => "",
#         //   "OPNsense\\Firewall\\FieldTypes\\TosField" => "",
#         //   "OPNsense\\IDS\\FieldTypes\\PolicyContentField" => "",
#         //   "OPNsense\\Interfaces\\FieldTypes\\BridgeMemberField" => "",
#         //   "OPNsense\\Interfaces\\FieldTypes\\LaggInterfaceField" => "",
#         //   "OPNsense\\Interfaces\\FieldTypes\\VipInterfaceField" => "",
#         //   "OPNsense\\Interfaces\\FieldTypes\\VlanInterfaceField" => "",
#           "OPNsense\\IPsec\\FieldTypes\\CharonLogLevelField" => 3,
#         //   "OPNsense\\IPsec\\FieldTypes\\IPsecProposalField" => "",
#         //   "OPNsense\\IPsec\\FieldTypes\\PoolsField" => "",
#         //   "OPNsense\\OpenVPN\\FieldTypes\\OpenVPNServerField" => "",
#         //   "OPNsense\\Unbound\\FieldTypes\\UnboundInterfaceField" => "",
#         "OPNsense\\Base\\FieldTypes\\BooleanField" => 1,
#         // "OPNsense\\Base\\FieldTypes\\ContainerField" => ,
#         "OPNsense\\Base\\FieldTypes\\CSVListField" => "",
#         "OPNsense\\Base\\FieldTypes\\EmailField" => "billj@opnsense.local",
#         "OPNsense\\Base\\FieldTypes\\HostnameField" => "luna.opnsense.local",
#           "OPNsense\\Dnsmasq\\FieldTypes\\AliasesField" => "pluto.opnsense.local",
#         "OPNsense\\Base\\FieldTypes\\IntegerField" => 99,
#           "OPNsense\\Auth\\FieldTypes\\GidField" => 1000,
#           "OPNsense\\Auth\\FieldTypes\\UidField" => 1000,
#           "OPNsense\\OpenVPN\\FieldTypes\\VPNIdField" => 1,
#         "OPNsense\\Base\\FieldTypes\\IPPortField" => "10.0.10.12:3128",
#         "OPNsense\\Base\\FieldTypes\\LegacyLinkField" => 1,
#         "OPNsense\\Base\\FieldTypes\\MacAddressField" => "99:de:ad:be:ef:99",
#         "OPNsense\\Base\\FieldTypes\\NetworkField" => "10.0.202.1",
#           "OPNsense\\Dnsmasq\\FieldTypes\\DomainIPField" => "10.0.200.16",
#           "OPNsense\\Dnsmasq\\FieldTypes\\RangeAddressField" => "10.0.200.1-10.0.200.50",
#         "OPNsense\\Base\\FieldTypes\\NumericField" => 25.1,
#         // "OPNsense\\Base\\FieldTypes\\TextField" => "randomstring",
#         //   "OPNsense\\Auth\\FieldTypes\\ExpiresField" => "",
#           "OPNsense\\Auth\\FieldTypes\\StoreB64Field" => "rOKCtR5BXDvkn023",
#           "OPNsense\\Base\\FieldTypes\\Base64Field" => "fqa1hdePiKIY3CVy",
#           "OPNsense\\Base\\FieldTypes\\DescriptionField" => "do stuff",
#           "OPNsense\\Base\\FieldTypes\\UpdateOnlyTextField" => "write once read never",
#           "OPNsense\\Interfaces\\FieldTypes\\VipNetworkField" => "10.12.0.0",
#         "OPNsense\\Base\\FieldTypes\\UniqueIdField" => "deadbeef-dead-beef-dead-beefdeadbeef",
#         "OPNsense\\Base\\FieldTypes\\UrlField" => "https://saturn.opnsense.local",
#         "OPNsense\\Diagnostics\\FieldTypes\\HostField" => "10.0.200.19",
#         "OPNsense\\Firewall\\FieldTypes\\AliasContentField" => "10.0.200.16`",
#         "OPNsense\\Firewall\\FieldTypes\\AliasNameField" => "printservers",
#         "OPNsense\\Firewall\\FieldTypes\\GroupNameField" => "opt7",
#         "OPNsense\\Interfaces\\FieldTypes\\LinkAddressField" => "10.5.0.6",
#         "OPNsense\\IPsec\\FieldTypes\\IKEAddressField" => "10.5.0.0/24,10.0.200.0/24",
#         "OPNsense\\Kea\\FieldTypes\\KeaPoolsField" => "10.0.200.50-10.0.200.240",
#         "OPNsense\\OpenVPN\\FieldTypes\\RemoteHostField" => "71.88.231.157",
#     ];

def get_sample(path: str, sample: Dict[str, Sample] = SAMPLES):
    keys = [k for k in sample.keys() if path.startswith(k)]
    if not keys:
        raise KeyError(path)

    key = sorted(keys, key=len)[-1]
    _sample = sample[key]
    is_dict = isinstance(_sample, Mapping)

    if key == path:
        if is_dict and _sample.get("__PARTIAL__"):
            raise KeyError(path)
        return _sample

    if not is_dict:
        raise KeyError(path)

    sub_path = re.sub(fr"^{re.escape(key)}\.", "", path)
    try:
        return get_sample(sub_path, _sample)
    except KeyError:
        raise KeyError(path)


def generate_string(pattern: str | None = None, *args, **kwargs) -> str:
    if pattern == "^[a-zA-Z0-9]{8}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{12}$":
        return FAKE_UUID
    elif pattern is None:
        pattern = r"[a-z0-9]{8}"

    try:
        compiled = re.compile(pattern)
    except re.PatternError as ex:
        ex.add_note(pattern)
        raise ex.with_traceback(None)
    value = _generate_string(pattern, *args, **kwargs)
    assert compiled.match(value), f"generated string '{value}' does not match '{pattern}'"
    return value


def make(
    path: str,
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

    try:
        return get_sample(path)
    except KeyError:
        pass

    try:
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



def generate_samples(schemas: Dict[str, Schema], should_validate: bool = False) -> Dict[str, Schema]:
    samples = {}
    for model_name, schema in schemas.items():
        if should_validate:
            validate_schema(schema, model_name)
        sample = make(model_name, **schema)
        if should_validate:
            validate(schema, sample, model_name)
        samples[model_name] = sample

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


def format(instance) -> str:
    formatted = str(instance)
    if len(formatted) > 80:
        formatted = formatted[0:77] + " ..."
    return formatted


def validate(schema: Schema, instance: Sample, model_name: str = ""):
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

    # model_name = "opnsense.auth.group"
    # model_name = "opnsense.cron.cron"
    # schemas = {model_name: schemas[model_name]}

    samples = generate_samples(schemas, should_validate=args.validate)

    content = json.dumps(samples, indent=4)

    pathlib.Path(output_file).parent.mkdir(parents=True, exist_ok=True)
    with open(output_file, "w") as file:
        file.write(content)
