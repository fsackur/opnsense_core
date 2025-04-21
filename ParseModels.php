<?php

namespace OPNsense\OpenApi\Parsing;

use Error;
use Exception;
use OPNsense\Base\FieldTypes\CSVListField;
use Reflection;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionParameter;
use ReflectionException;
use SimpleXMLElement;
use OPNsense\Base\BaseModel;
use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Base\FieldTypes\ContainerField;

require_once(dirname(__FILE__) . '/ParserBase.php');


/**
 * This is not comprehensive, but it should cover the majority of cases in the source code.
 * Case-insensitve flags are supported by ECMA, but not by OpenApi, so we strip those.
 * See https://gist.github.com/CMCDragonkai/6c933f4a7d713ef712145c5eb94a1816
 * @param string $pattern PCRE pattern, as supported by preg_match etc
 * @return string ECMA-compatible pattern
 */

function convert_pcre_to_ecma_regex(string $pattern) {
    $orig = $pattern;
    $flags = [];
    while ($pattern[-1] !== $pattern[0]) {
        $flags[] = $pattern[-1];
        $pattern = substr($pattern, 0, -1);
    }
    if ($flags) {
        $f = implode(',', $flags);
        trigger_error("Stripped regex flags $f from '$orig'", E_USER_WARNING);
    }

    // strip the PHP delimiters
    $pattern = substr($pattern, 1, -1);
    // '\x{00A0}' => '\u00A0'
    $pattern = preg_replace("/\\\x\\{(....)\}/", "\\u\\1", $pattern);

    return $pattern;
}


class Field {
    private static array $EXAMPLES = [
        "OPNsense\\Auth\\FieldTypes\\ApiKeyField" => "l5NS9+rPKAb2LseJZzdCnY/BXpIHtGgjaVTWiFkquRD04c78mUExMo3y1fwhv6QO",
        "OPNsense\\Auth\\FieldTypes\\UsernameField" => "hopper",
        // "OPNsense\\Base\\FieldTypes\\ArrayField" => "",
        //   "OPNsense\\Core\\FieldTypes\\TunableField" => "",
        //   "OPNsense\\Firewall\\FieldTypes\\AliasField" => "",
        //   "OPNsense\\Firewall\\FieldTypes\\FilterRuleField" => "",
        //   "OPNsense\\Firewall\\FieldTypes\\GroupField" => "",
        //   "OPNsense\\Firewall\\FieldTypes\\SourceNatRuleField" => "",
        //   "OPNsense\\IDS\\FieldTypes\\PolicyRulesField" => "",
        //   "OPNsense\\Interfaces\\FieldTypes\\NeighborField" => "",
        //   "OPNsense\\Interfaces\\FieldTypes\\VipField" => "",
        //   "OPNsense\\IPsec\\FieldTypes\\ConnnectionField" => "",
        //   "OPNsense\\IPsec\\FieldTypes\\SPDField" => "",
        //   "OPNsense\\IPsec\\FieldTypes\\VTIField" => "",
        //   "OPNsense\\OpenVPN\\FieldTypes\\InstanceField" => "",
        //   "OPNsense\\Routing\\FieldTypes\\GatewayField" => "",
        //   "OPNsense\\Trust\\FieldTypes\\CAsField" => "",
        //   "OPNsense\\Trust\\FieldTypes\\CertificatesField" => "",
        //   "OPNsense\\Wireguard\\FieldTypes\\ClientField" => "",
        //   "OPNsense\\Wireguard\\FieldTypes\\ServerField" => "",
        "OPNsense\\Base\\FieldTypes\\AutoNumberField" => 99,
        //   "OPNsense\\Firewall\\FieldTypes\\FilterSequenceField" => "99",
        // "OPNsense\\Base\\FieldTypes\\BaseListField" => "",
          "OPNsense\\Auth\\FieldTypes\\GroupMembershipField" => "docker,libvirt",
          "OPNsense\\Auth\\FieldTypes\\MemberField" => "hopper,billj",
          "OPNsense\\Auth\\FieldTypes\\PrivField" => "logon",
          "OPNsense\\Base\\FieldTypes\\AuthenticationServerField" => "luna,krb01",
          "OPNsense\\Base\\FieldTypes\\AuthGroupField" => "vpn_users",
          "OPNsense\\Base\\FieldTypes\\CertificateField" => "",
          "OPNsense\\Base\\FieldTypes\\ConfigdActionsField" => "configctl template reload openapi",
          "OPNsense\\Base\\FieldTypes\\CountryField" => "NL",
          "OPNsense\\Base\\FieldTypes\\InterfaceField" => "opt99",
          "OPNsense\\Base\\FieldTypes\\JsonKeyValueStoreField" => "/bin/bash",
        //   "OPNsense\\Base\\FieldTypes\\ModelRelationField" => "",
          "OPNsense\\Base\\FieldTypes\\NetworkAliasField" => "rfc1918",
        //   "OPNsense\\Base\\FieldTypes\\OptionField" => "",
          "OPNsense\\Base\\FieldTypes\\PortField" => "853",
          "OPNsense\\Base\\FieldTypes\\ProtocolField" => "TCP",
        //   "OPNsense\\Base\\FieldTypes\\VirtualIPField" => "",
        //   "OPNsense\\Diagnostics\\FieldTypes\\InterfaceField" => "",
        //   "OPNsense\\Firewall\\FieldTypes\\InterfaceField" => "",
        //   "OPNsense\\Firewall\\FieldTypes\\ScheduleField" => "",
        //   "OPNsense\\Firewall\\FieldTypes\\TosField" => "",
        //   "OPNsense\\IDS\\FieldTypes\\PolicyContentField" => "",
        //   "OPNsense\\Interfaces\\FieldTypes\\BridgeMemberField" => "",
        //   "OPNsense\\Interfaces\\FieldTypes\\LaggInterfaceField" => "",
        //   "OPNsense\\Interfaces\\FieldTypes\\VipInterfaceField" => "",
        //   "OPNsense\\Interfaces\\FieldTypes\\VlanInterfaceField" => "",
          "OPNsense\\IPsec\\FieldTypes\\CharonLogLevelField" => 3,
        //   "OPNsense\\IPsec\\FieldTypes\\IPsecProposalField" => "",
        //   "OPNsense\\IPsec\\FieldTypes\\PoolsField" => "",
        //   "OPNsense\\OpenVPN\\FieldTypes\\OpenVPNServerField" => "",
        //   "OPNsense\\Unbound\\FieldTypes\\UnboundInterfaceField" => "",
        "OPNsense\\Base\\FieldTypes\\BooleanField" => 1,
        // "OPNsense\\Base\\FieldTypes\\ContainerField" => ,
        "OPNsense\\Base\\FieldTypes\\CSVListField" => "",
        "OPNsense\\Base\\FieldTypes\\EmailField" => "billj@opnsense.local",
        "OPNsense\\Base\\FieldTypes\\HostnameField" => "luna.opnsense.local",
          "OPNsense\\Dnsmasq\\FieldTypes\\AliasesField" => "pluto.opnsense.local",
        "OPNsense\\Base\\FieldTypes\\IntegerField" => 99,
          "OPNsense\\Auth\\FieldTypes\\GidField" => 1000,
          "OPNsense\\Auth\\FieldTypes\\UidField" => 1000,
          "OPNsense\\OpenVPN\\FieldTypes\\VPNIdField" => 1,
        "OPNsense\\Base\\FieldTypes\\IPPortField" => "10.0.10.12:3128",
        "OPNsense\\Base\\FieldTypes\\LegacyLinkField" => 1,
        "OPNsense\\Base\\FieldTypes\\MacAddressField" => "99:de:ad:be:ef:99",
        "OPNsense\\Base\\FieldTypes\\NetworkField" => "10.0.202.1",
          "OPNsense\\Dnsmasq\\FieldTypes\\DomainIPField" => "10.0.200.16",
          "OPNsense\\Dnsmasq\\FieldTypes\\RangeAddressField" => "10.0.200.1-10.0.200.50",
        "OPNsense\\Base\\FieldTypes\\NumericField" => 25.1,
        // "OPNsense\\Base\\FieldTypes\\TextField" => "randomstring",
        //   "OPNsense\\Auth\\FieldTypes\\ExpiresField" => "",
          "OPNsense\\Auth\\FieldTypes\\StoreB64Field" => "rOKCtR5BXDvkn023",
          "OPNsense\\Base\\FieldTypes\\Base64Field" => "fqa1hdePiKIY3CVy",
          "OPNsense\\Base\\FieldTypes\\DescriptionField" => "do stuff",
          "OPNsense\\Base\\FieldTypes\\UpdateOnlyTextField" => "write once read never",
          "OPNsense\\Interfaces\\FieldTypes\\VipNetworkField" => "10.12.0.0",
        "OPNsense\\Base\\FieldTypes\\UniqueIdField" => "deadbeef-dead-beef-dead-beefdeadbeef",
        "OPNsense\\Base\\FieldTypes\\UrlField" => "https://saturn.opnsense.local",
        "OPNsense\\Diagnostics\\FieldTypes\\HostField" => "10.0.200.19",
        "OPNsense\\Firewall\\FieldTypes\\AliasContentField" => "10.0.200.16`",
        "OPNsense\\Firewall\\FieldTypes\\AliasNameField" => "printservers",
        "OPNsense\\Firewall\\FieldTypes\\GroupNameField" => "opt7",
        "OPNsense\\Interfaces\\FieldTypes\\LinkAddressField" => "10.5.0.6",
        "OPNsense\\IPsec\\FieldTypes\\IKEAddressField" => "10.5.0.0/24,10.0.200.0/24",
        "OPNsense\\Kea\\FieldTypes\\KeaPoolsField" => "10.0.200.50-10.0.200.240",
        "OPNsense\\OpenVPN\\FieldTypes\\RemoteHostField" => "71.88.231.157",
    ];

    private BaseField $node;
    private ReflectionClass $class;
    private array $validators;
    public string $type;
    public string $reference;
    public ?string $tag;
    public ?string $default;
    public bool $is_ass_array;
    public bool $is_container;
    public bool $is_list;
    public bool $is_required;
    public array $children = [];
    // public array $options = [];

    private function getValue(string $property) {
        if ($this->class->hasProperty($property)) {
            return $this->class->getProperty($property)->getValue($this->node);
        }
    }

    public function __construct(BaseField $node) {
        $fakeUuid = "00000000-0000-0000-0000-000000000000";
        $hex = "a-zA-Z0-9";
        $uuidPattern = "/[$hex]{8}-[$hex]{4}-[$hex]{4}-[$hex]{4}-[$hex]{12}/";
        $reference = preg_replace($uuidPattern, $fakeUuid, $node->__reference);

        $class = new ReflectionClass($node::class);
        $class->getProperty("internalReference")->setValue($node, $reference);
        $node->setAttributeValue("uuid", $fakeUuid);

        $this->node = $node;
        $this->class = $class;

        $this->type = $class->name;
        $this->reference = $reference;
        $this->tag = $this->getValue("internalXMLTagName");
        $this->default = $this->getValue("internalDefaultValue");
        $this->is_ass_array = $node->isArrayType();
        $this->is_container = $node->isContainer();
        $this->is_list = $this->getValue("internalAsList") || $this->getValue("internalMultiSelect");
        $this->is_required = $node->isRequired();


        // doesn't iterate over ArrayField
        foreach ($node->iterateItems() as $key => $child) {
            $this->children[$key] = new Field($child);
        }

        if ($this->is_ass_array) {
            // refs will be random uuids, but we regex those in the ctor
            $childNodes = $class->getProperty("internalChildnodes")->getValue($node);
            $firstKey = array_keys($childNodes)[0];
            $child = $childNodes[$firstKey];

            $this->children[$fakeUuid] = new Field($child);
        }
    }

    public function is(string $className) {
        return $this->type === $className || $this->class->isSubclassOf($className);
    }

    public function getSchema() {
        $hex = "a-zA-Z0-9";
        $uuidPattern = "^[$hex]{8}-[$hex]{4}-[$hex]{4}-[$hex]{4}-[$hex]{12}$";

        $schema = [];
        $schema["x-type"] = $this->type;

        if ($this->is_ass_array) {
            $childSchemas = [];
            foreach ($this->children as $prop => $child) {
                $childSchemas[$uuidPattern] = $child->getSchema();
            }
            $schema["type"] = "object";
            $schema["additionalProperties"] = false;
            $schema["patternProperties"] = $childSchemas;

        } elseif ($this->is_list) {
            $childSchema = [
                "type" => "object",
                "additionalProperties" => false,
                "required" => ["value", "selected"],
                "properties" => [
                    "value" => ["type" => "string"],
                    "selected" => [
                        "type" => "integer",
                        "enum" => [0, 1]
                    ],
                ],
            ];

            $schema["type"] = "object";
            $schema["additionalProperties"] = $childSchema;

        } elseif ($this->is_container) {
            $childSchemas = [];
            $required = [];
            foreach ($this->children as $prop => $child) {
                $childSchemas[$prop] = $child->getSchema();
                if ($child->is_required && !$child->is("OPNsense\Base\FieldTypes\AutoNumberField")) {
                    $required[] = $prop;
                }
            }
            $schema["type"] = "object";
            $schema["additionalProperties"] = false;
            if ($required) {
                $schema["required"] = $required;
            }
            $schema["properties"] = $childSchemas;

        // } elseif ($this->type === "OPNsense\Base\FieldTypes\BooleanField") {
        } elseif ($this->is("OPNsense\Base\FieldTypes\BooleanField")) {
            $schema["type"] = "integer";  // because fuck you, that's why
            $schema["enum"] = [0, 1];
            $schema["description"] = "boolean";

        // } elseif ($this instanceof IntegerField || $this instanceof AutoNumberField) {
        } elseif ($this->is("OPNsense\Base\FieldTypes\IntegerField")) {
            $schema["type"] = "integer";

            // doesn't apply to child classes
            if ($this->type === "OPNsense\Base\FieldTypes\IntegerField") {
                $min = $this->getValue("minimum_value");
                if ($min !== null) {
                    $min = (int) $min;
                    if ($min !== PHP_INT_MIN) {$schema["minimum"] = $min;}
                }
                $max = $this->getValue("maximum_value");
                if ($max !== null) {
                    $max = (int) $max;
                    if ($max !== PHP_INT_MAX) {$schema["maximum"] = $max;}
                }
            }

        } elseif ($this->is("OPNsense\Base\FieldTypes\AutoNumberField")) {
            $schema["type"] = "integer";

        } elseif ($this->is("OPNsense\Base\FieldTypes\NumericField")) {
            $schema["type"] = "number";

            // doesn't apply to child classes
            if ($this->type === "OPNsense\Base\FieldTypes\NumericField") {
                $min = $this->getValue("minimum_value");
                if ($min !== null) {
                    $min = (float) $min;
                    if ($min !== -99999999999999.0) {$schema["minimum"] = $min;}
                }
                $max = $this->getValue("maximum_value");
                if ($max !== null) {
                    $max = (float) $max;
                    if ($max !== 99999999999999.0) {$schema["maximum"] = $max;}
                }
            }

        } else {
            $schema["type"] = "string";
        }

        if (
            $this->is("OPNsense\Base\FieldTypes\TextField") ||
            $this->is("OPNsense\Base\FieldTypes\CSVListField")
        ) {
            $mask = $this->class->getProperty("internalMask")->getValue($this->node);
            if ($mask) {
                $pattern = convert_pcre_to_ecma_regex($mask);

                if ($this->is("OPNsense\Base\FieldTypes\CSVListField")) {
                    // TODO: handle anchors
                    $pattern = "($pattern,)*$pattern";
                }
                $schema["pattern"] = $pattern;
            }
        }

        if (!($this->is_ass_array || $this->is_container || $this->is_list)) {
            if (array_key_exists($this->type, static::$EXAMPLES)) {
                $example = static::$EXAMPLES[$this->type];
                $schema["example"] = $example;
                // $result = $this->validate($example);
                // if ($result) {
                //     $schema["x-validation-error"] = $result;
                // }
            }
        }

        return $schema;
    }

    public function validate($data) {
        // if ($this->type !== "OPNsense\OpenVPN\FieldTypes\VPNIdField") {
        //     return;
        // }
        $field = $this->node;
        $parent = $field->getParentNode();
        // $parentAttrs = $parent->getAttributes();
        // $gp = $parent->getParentNode();
        // $gpAttrs = $gp->getAttributes();
        // $ggp = $gp->getParentNode();
        // $ggpAttrs = $ggp->getAttributes();
        $attrs = $parent->getAttributes();
        // var_dump($attrs);

        $this_uuid = $attrs['uuid'];
        // ->getAttributes()['uuid']
        $validation = new \OPNsense\Base\Validation();

        foreach ($this->node->getValidators() as $item_validator) {
            $validation->add($this->reference, $item_validator);
        }
        $validation_data[$this->reference] = $data;

        $messages = $validation->validate($validation_data);

        $result = [];
        foreach ($messages as $msg) {
            $result[] = $msg->getMessage();
        }
        return $result;
        // if ($result) {
        //     var_dump($result);
        //     echo json_encode($this);
        //     // die();
        // }
    }
}


class Model extends ParsedBase {
    private BaseModel $instance;
    protected array $reflectionProperties = [];
    public Field $field;

    public static function get_schema_name(string $class_name) {
        $name = strtolower($class_name);
        return str_replace("\\", ".", $name);
    }

    public function __construct(ReflectionClass $rclass, Model | null $parent)
    {
        parent::__construct($rclass, $parent);

        if ($parent) {
            foreach ($parent->reflectionProperties as $prop) {
                $this->reflectionProperties[$prop->name] = $prop;
            }
        }

        foreach ($rclass->getProperties() as $prop) {
            $this->reflectionProperties[$prop->name] = $prop;
        }


        if ($this->is_abstract) {
            return;
        }
        $this->instance = $rclass->newInstanceWithoutConstructor();
        $this->init();
    }

    function init() {
        $class = $this->class;
        $model = $this->instance;

        $internalData = new ContainerField();
        $dataProp = $this->reflectionProperties["internalData"];
        $dataProp->setValue($model, $internalData);
        $model_xml = $class->getMethod("getModelXML")->invoke($model);

        $config_array = new SimpleXMLElement('<opnsense/>');
        $parseArgs = [&$model_xml->items, &$config_array, &$internalData];
        $class->getMethod("parseXml")->invokeArgs($model, $parseArgs);

        $this->field = new Field($internalData);
    }

    public function getSchema() {
        return $this->field->getSchema();
    }
}


class ModelRegistry extends Registry {}


$base_path = $config->__get("application")->modelsDir;
$parser = new Parser(
    new ReflectionClass(Model::class),
    new ReflectionClass(BaseModel::class),
    new ReflectionClass(ModelRegistry::class),
    $path_regex = "/models\/\w+\/\w+\/\w+\.php/",
);

$models = $parser->get_all($base_path);

$output_file = "models.json";
dump_json($models, $output_file, true);
// echo $parser->export($base_path, $output_file, true);

// $model = $parser->get("OPNsense\\Firewall\\Alias");

// // $output = $model;
// // $output = $model->field;
// $output = $model->getSchema();

$schemas = [];
foreach ($parser->get_all($base_path) as $model) {
    if ($model->is_abstract) {
        continue;
    }
    $schemas[$model->schema_name] = $model->getSchema();
}

$output_file = "schemas.json";
dump_json($schemas, $output_file, true);


?>
