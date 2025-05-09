<?php

namespace OPNsense\OpenApi\Parsing;

use Error;
use Exception;
use OPNsense\Base\FieldTypes\AuthGroupField;
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

require_once __DIR__ . '/ParserBase.php';
require_once __DIR__ . '/MockBackendBase.php';


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
        // trigger_error("Stripped regex flags $f from '$orig'", E_USER_WARNING);
    }

    // strip the PHP delimiters
    $pattern = substr($pattern, 1, -1);
    // '\x{00A0}' => '\u00A0'
    $pattern = preg_replace("/\\\x\\{(....)\}/", "\\u\\1", $pattern);

    return $pattern;
}


class Field {
    private BaseField $node;
    private ReflectionClass $class;
    private array $validators;
    public string $type;
    public string $reference;
    public ?string $tag;
    public ?string $default;
    public bool $isAssArray;
    public bool $isContainer;
    public bool $isList;
    public bool $isEnum;
    public bool $isRequired;
    public array $children = [];
    // public array $options = [];

    private function getValue(string $property)
    {
        if ($this->class->hasProperty($property)) {
            return $this->class->getProperty($property)->getValue($this->node);
        }
    }

    public function __construct(BaseField $node)
    {
        $node->eventPostLoading();

        $fakeUuid = "00000000-0000-0000-0000-000000000000";
        $hex = "a-zA-Z0-9";
        $uuidPattern = "/[$hex]{8}-[$hex]{4}-[$hex]{4}-[$hex]{4}-[$hex]{12}/";
        $reference = $node->__reference;
        if (!$reference) {
            $reference = "";
        }
        // $reference = preg_replace($uuidPattern, $fakeUuid, $reference);

        $class = new ReflectionClass($node::class);
        $class->getProperty("internalReference")->setValue($node, $reference);
        $node->setAttributeValue("uuid", $fakeUuid);

        // echo "$class->name\n";

        $this->node = $node;
        $this->class = $class;

        $this->type = $class->name;
        $this->reference = $reference;
        $this->tag = $this->getValue("internalXMLTagName");
        $this->default = $this->getValue("internalDefaultValue");
        $this->isAssArray = $node->isArrayType();
        $this->isContainer = $node->isContainer();
        $this->isList = $this->getValue("internalAsList") || $this->getValue("internalMultiSelect");
        $this->isEnum = $this->is("OPNsense\Base\FieldTypes\BaseListField");
        $this->isRequired = $node->isRequired();


        // doesn't iterate over ArrayField
        foreach ($node->iterateItems() as $key => $child) {
            $this->children[$key] = new Field($child);
        }

        if ($this->isAssArray) {
            // refs will be random uuids, but we regex those in the ctor
            // $childNodes = $class->getProperty("internalChildnodes")->getValue($node);
            // $firstKey = array_keys($childNodes)[0];
            // $child = $childNodes[$firstKey];

            $child = $class->getMethod("getTemplateNode")->invoke($node);

            $this->children[$fakeUuid] = new Field($child);
        }
    }

    public function is(string $className)
    {
        return $this->type === $className || $this->class->isSubclassOf($className);
    }

    public function getSchema()
    {
        $hex = "a-zA-Z0-9";
        $uuidPattern = "^[$hex]{8}-[$hex]{4}-[$hex]{4}-[$hex]{4}-[$hex]{12}$";

        $schema = [];
        $schema["x-type"] = $this->type;

        if (
            $this->is("OPNsense\Base\FieldTypes\ModelRelationField") ||
            $this->is("OPNsense\Base\FieldTypes\JsonKeyValueStoreField") ||
            $this->is("OPNsense\Base\FieldTypes\ConfigdActionsField") ||
            $this->is("OPNsense\Firewall\FieldTypes\ScheduleField")
        ) {
            $schema["type"] = "string";
            $schema["enum"] = ["TODO"];

        } elseif ($this->isAssArray) {
            $childSchemas = [];
            foreach ($this->children as $prop => $child) {
                $childSchemas[$uuidPattern] = $child->getSchema();
                break;
            }
            $schema["type"] = "object";
            $schema["additionalProperties"] = false;
            $schema["patternProperties"] = $childSchemas;

        } elseif ($this->isList) {
            // $childSchema = [
            //     "type" => "object",
            //     "additionalProperties" => false,
            //     "required" => ["value", "selected"],
            //     "properties" => [
            //         "value" => ["type" => "string"],
            //         "selected" => [
            //             "type" => "integer",
            //             "enum" => [0, 1]
            //         ],
            //     ],
            // ];

            // $schema["type"] = "object";
            // $schema["additionalProperties"] = $childSchema;

            $schema["type"] = "string";

        } elseif ($this->isContainer) {
            $childSchemas = [];
            $required = [];
            foreach ($this->children as $prop => $child) {
                $childSchemas[$prop] = $child->getSchema();
                if ($child->isRequired && !$child->is("OPNsense\Base\FieldTypes\AutoNumberField")) {
                    $required[] = $prop;
                }
            }
            $schema["type"] = "object";
            $schema["additionalProperties"] = false;
            if ($required) {
                $schema["required"] = $required;
            }
            $schema["properties"] = $childSchemas;

        } elseif ($this->isEnum) {
            $childSchemas = [];

            $optionProp = $this->class->getProperty("internalOptionList");
            $options = array_keys($optionProp->getValue($this->node));

            $stringOptions = [];
            $first = null;
            $last = null;
            foreach ($options as $option) {
                if (is_numeric($option)) {
                    if ($first === null) {
                        $first = $option;
                        $last = $option;
                    } else {
                        if ($option == $last + 1) {
                            $last = $option;
                        } else {
                            $stringOptions = $options;
                            $first = null;
                            $last = null;
                            break;
                        }

                    }
                } else {
                    $stringOptions[] = $option;
                }
            }

            if ($last !== null) {
                $childSchemas[] = [
                    "x-type" => $this->type,
                    "type" => "integer",
                    "minimum" => $first,
                    "maximum" => $last,
                ];
            } elseif ($first !== null) {
                $stringOptions[] = $first;
            }

            if (count($stringOptions)) {
                $childSchemas[] = [
                    "x-type" => $this->type,
                    "type" => "string",
                    "enum" => $stringOptions,
                ];
            }

            if (count($childSchemas) == 0) {
                // OPNsense\Interfaces\FieldTypes\LaggInterfaceField
                // OPNsense\Interfaces\FieldTypes\VlanInterfaceField
                // OPNsense\Base\FieldTypes\InterfaceField
                // OPNsense\Base\FieldTypes\CertificateField
                // OPNsense\Base\FieldTypes\VirtualIPField
                $schema["type"] = "string";
                $schema["enum"] = ["TODO"];
                echo "$this->type\n";
            } elseif (count($childSchemas) == 1) {
                $schema = $childSchemas[0];
            } else {
                $schema = ["oneOf" => $childSchemas];
            }

            // if ($this->is("OPNsense\\Base\\FieldTypes\\PortField")) {
            //     $schema["type"] = "integer";
            //     $first = $options[0];
            //     $last = $options[array_key_last($options)];

            //     if (is_numeric($first) && is_numeric($last)) {
            //         $schema["minimum"] = $first;
            //         $schema["maximum"] = $last;
            //     } else {
            //         throw new Exception("fuck you");
            //     }
            // } else {
            //     $schema["type"] = "string";
            //     $schema["enum"] = $options;
            // }

        } elseif ($this->is("OPNsense\Base\FieldTypes\BooleanField")) {
            $schema["type"] = "integer";  // because fuck you, that's why
            $schema["enum"] = [0, 1];

        // } elseif ($this instanceof IntegerField || $this instanceof AutoNumberField) {
        } elseif ($this->is("OPNsense\Base\FieldTypes\IntegerField")) {
            $schema["type"] = "integer";

            // doesn't apply to child classes
            if ($this->type === "OPNsense\Base\FieldTypes\IntegerField") {
                $min = $this->getValue("minimum_value");
                if ($min !== null) {
                    $min = (int) $min;
                    if ($min !== PHP_INT_MIN) {
                        $schema["minimum"] = $min;
                    }
                }
                $max = $this->getValue("maximum_value");
                if ($max !== null) {
                    $max = (int) $max;
                    if ($max !== PHP_INT_MAX) {
                        $schema["maximum"] = $max;
                    }
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
                    if ($min !== -99999999999999.0) {
                        $schema["minimum"] = $min;
                    }
                }
                $max = $this->getValue("maximum_value");
                if ($max !== null) {
                    $max = (float) $max;
                    if ($max !== 99999999999999.0) {
                        $schema["maximum"] = $max;
                    }
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

        $value = $this->node->getCurrentValue();
        if ($value !== "") {
            if ($schema["type"] == "integer") {
                $value = (int) $value;
            } elseif ($schema["type"] == "number") {
                $value = (float) $value;
            }
            $schema["example"] = $value;
        }

        return $schema;
    }
}


class Model extends ParsedBase {
    private BaseModel $instance;
    protected array $reflectionProperties = [];
    public Field $field;

    public static function getSchemaName(string $className)
    {
        $name = strtolower($className);
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

        if ($this->isAbstract) {
            return;
        }

        // echo "$rclass->name\n";
        $model = $rclass->newInstance();
        $this->instance = $model;

        $dataProp = $this->reflectionProperties["internalData"];
        $internalData = $dataProp->getValue($model);
        $this->field = new Field($internalData);
    }

    public function getSchema()
    {
        return $this->field->getSchema();
    }

    public function validate(array $data)
    {
        $this->instance->setNodes($data);
        return $this->instance->validate(null, "", true);
    }
}


class ModelRegistry extends Registry
{
}
