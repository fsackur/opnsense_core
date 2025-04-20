<?php

namespace OPNsense\OpenApi\Parsing;

use Error;
use Exception;
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


class Field {
    private ReflectionClass $class;
    public string $type;
    public string $reference;
    public ?string $tag;
    public bool $is_ass_array;
    public bool $is_container;
    public bool $is_required;
    public array $children = [];

    public function __construct(BaseField $node) {
        $fakeUuid = "00000000-0000-0000-0000-000000000000";
        $hex = "a-zA-Z0-9";
        $uuidPattern = "/[$hex]{8}-[$hex]{4}-[$hex]{4}-[$hex]{4}-[$hex]{12}/";
        $reference = preg_replace($uuidPattern, $fakeUuid, $node->__reference);

        $class = new ReflectionClass($node::class);

        $this->is_required = $node->isRequired();
        $this->class = $class;
        $this->type = $class->name;
        $this->tag = $class->getProperty("internalXMLTagName")->getValue($node);
        $this->reference = $reference;
        $this->is_ass_array = $node->isArrayType();
        $this->is_container = $node->isContainer();

        // doesn't iterate over ArrayField
        foreach ($node->iterateItems() as $key => $child) {
            $this->children[$key] = new Field($child);
        }

        if ($this->is_ass_array) {
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

        } elseif ($this->is_container) {
            $childSchemas = [];
            $required = [];
            foreach ($this->children as $prop => $child) {
                $childSchemas[$prop] = $child->getSchema();
                if ($child->is_required) {
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
        } elseif ($this->is("OPNsense\Base\FieldTypes\IntegerField") || $this->is("OPNsense\Base\FieldTypes\AutoNumberField")) {
            $schema["type"] = "integer";

        // } elseif ($this instanceof NumericField) {
        } elseif ($this->is("OPNsense\Base\FieldTypes\NumericField")) {
            $schema["type"] = "number";

        } else {
            $schema["type"] = "string";
            // $properties = $this->walk($this);
            // var_dump($this);
            // die();
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

dump_json($schemas, $output_file, true);


?>
