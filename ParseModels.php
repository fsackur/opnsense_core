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
    public bool $is_ass_array;
    public bool $is_container;
    public bool $is_list;
    public bool $is_enum;
    public bool $is_required;
    public array $children = [];
    // public array $options = [];

    private function getValue(string $property) {
        if ($this->class->hasProperty($property)) {
            return $this->class->getProperty($property)->getValue($this->node);
        }
    }

    public function __construct(BaseField $node) {
        $node->eventPostLoading();

        $fakeUuid = "00000000-0000-0000-0000-000000000000";
        $hex = "a-zA-Z0-9";
        $uuidPattern = "/[$hex]{8}-[$hex]{4}-[$hex]{4}-[$hex]{4}-[$hex]{12}/";
        $reference = $node->__reference;
        if (!$reference) {$reference = "";}
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
        $this->is_ass_array = $node->isArrayType();
        $this->is_container = $node->isContainer();
        $this->is_list = $this->getValue("internalAsList") || $this->getValue("internalMultiSelect");
        $this->is_enum = $this->is("OPNsense\Base\FieldTypes\BaseListField");
        $this->is_required = $node->isRequired();


        // doesn't iterate over ArrayField
        foreach ($node->iterateItems() as $key => $child) {
            $this->children[$key] = new Field($child);
        }

        if ($this->is_ass_array) {
            // refs will be random uuids, but we regex those in the ctor
            // $childNodes = $class->getProperty("internalChildnodes")->getValue($node);
            // $firstKey = array_keys($childNodes)[0];
            // $child = $childNodes[$firstKey];

            $child = $class->getMethod("getTemplateNode")->invoke($node);

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

        } elseif ($this->is_enum) {
            $options = $this->class->getProperty("internalOptionList")->getValue($this->node);
            $schema["type"] = "string";
            $schema["enum"] = array_keys($options);

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

        return $schema;
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

        echo "$rclass->name\n";
        $model = $rclass->newInstance();
        $this->instance = $model;

        $dataProp = $this->reflectionProperties["internalData"];
        $internalData = $dataProp->getValue($model);
        $this->field = new Field($internalData);
    }

    public function getSchema() {
        return $this->field->getSchema();
    }

    public function validate(array $data) {
        $this->instance->setNodes($data);
        return $this->instance->validate(null, "", true);
    }
}


class ModelRegistry extends Registry {}


$base_path = $config->__get("application")->modelsDir;
$parser = new Parser(
    $base_path,
    new ReflectionClass(Model::class),
    new ReflectionClass(BaseModel::class),
    new ReflectionClass(ModelRegistry::class),
    $path_regex = "/models\/\w+\/\w+\/\w+\.php/",
);


if ($model_file) {
    // $models = $parser->get_all();
    $models = ["opnsense.core.hasync" => $parser->get("OPNsense\Core\Hasync")];
    dump_json($models, $model_file, true);
}

if ($schema_file) {
    $schemas = [];
    if (isset($TEST_MODEL)) {
        $models = [$parser->get_by_schema_name($TEST_MODEL)];
    } else {
        $models = $parser->get_all();
    }
    foreach ($models as $model) {
        if ($model->is_abstract) {
            continue;
        }
        $schemas[$model->schema_name] = $model->getSchema();
    }
    dump_json($schemas, $schema_file, true);
}

if ($should_generate_examples) {
    $py_output = null;
    $py_result_code = null;
    $command = "./generate_examples.py";
    exec($command, $py_output, $py_result_code);

    if ($py_result_code) {
        var_dump($py_output);
        throw new Exception($command);
    }
}


if ($example_file) {
    $examples = load_json($example_file);
    if (isset($TEST_MODEL)) {
        $examples = [$TEST_MODEL => $examples[$TEST_MODEL]];
    }
    $results = [];
    foreach ($examples as $schema_name => $example) {
        $model = $parser->get_by_schema_name($schema_name);
        $model_results = $model->validate($example);
        foreach ($model_results as $ref => $msg) {
            $results["$model->schema_name.$ref"] = $msg;
        }
    }

    foreach ($results as $ref => $msg) {
        echo "$ref: $msg\n";
    }
    $c1 = count($results);
    $c2 = count($examples);
    echo "$c1 validation error(s) from $c2 model(s)\n";
}

?>
