<?php

namespace OPNsense\OpenApi\Parsing;

use Error;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionException;
use OPNsense\Base\BaseModel;
use OPNsense\Base\FieldTypes\BaseField;

require_once(dirname(__FILE__) . '/ParserBase.php');


class Model extends ParsedBase {
    private BaseModel $instance;

    public function __construct(ReflectionClass $rclass, Model | null $parent)
    {
        parent::__construct($rclass, $parent);
        $name = $this->name;

        if ($this->is_abstract) {
            return;
        }
        $this->instance = $rclass->newInstance();
    }

    // public function __call($method, $args)
    // {
    //     try {
    //         $modelClass = new ReflectionClass(get_class($this->instance));
    //         $modelMethod = $modelClass->getMethod($method);
    //     } catch (Error $e) {
    //         $class_name = get_class($this);
    //         throw new Error("Call to undefined method {$class_name}::{$method}()");
    //     }

    //     return $modelMethod->invoke($this->instance, $args);
    // }

    public function getSchema()
    {
        $result = array ();
        foreach ($this->instance->iterateItems() as $key => $node) {
            $result[$key] = $this->walk($node);
        }
        return $result;
    }

    private function walk(BaseField $node)
    {
        // ini_set('memory_limit', '2048M');  # TODO: profile memory. DO NOT MERGE

        $schema = array ();
        $required = array();
        $schema["type"] = "object";
        $schema["properties"] = array();

        foreach ($node->iterateItems() as $key => $child) {
            $subSchema = $this->createChildSchema($key, $child, $required);
            $schema["properties"][$key] = $subSchema;
        }

        if ($required) {$schema["properties"] = $required;}
        return $schema;
    }

    private function createChildSchema(string $key, BaseField $child, &$required)
    {
        $uuidPattern = "^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$";

        if ($child->isRequired()) {$required[] = $key;}

        $childClass = $child::class;

        $isAssArray = $child->isArrayType();
        $isObject = $child->isContainer();

        $schema = array();
        if ($isAssArray || $isObject) {

            $schema["type"] = "object";
            $properties = $this->walk($child);

            if ($isAssArray) {
                $schema["patternProperties"] = [$uuidPattern => $properties];
            } else {
                $schema["properties"] = $properties;
            }

            $schema["additionalProperties"] = false;

        } elseif ($child instanceof BooleanField) {
            $schema["type"] = "integer";  // because fuck you, that's why
            $schema["enum"] = [0, 1];
            $schema["description"] = "boolean";
        } elseif ($child instanceof IntegerField || $child instanceof AutoNumberField) {
            $schema["type"] = "integer";
        } elseif ($child instanceof NumericField) {
            $schema["type"] = "number";
        } else {
            $schema["type"] = "string";
            // $properties = $this->walk($child);
            // var_dump($child);
            // die();
        }

        return $schema;
    }

    public function validate($nodes) {
        $this->instance->setNodes($nodes);
        $this->instance->performValidation(true);
    }
}


class ModelRegistry extends Registry {
    public static function get_schema_name(string $class_name) {
        $name = strtolower($class_name);
        return str_replace("\\", ".", $name);
    }
}


$base_path = $config->__get("application")->modelsDir;
$parser = new Parser(
    new ReflectionClass(Model::class),
    new ReflectionClass(BaseModel::class),
    new ReflectionClass(ModelRegistry::class),
    $path_regex = "/models\/\w+\/\w+\/\w+\.php/",
);

// echo $parser->export($base_path, $output_file, true);

$model = $parser->get("OPNsense\\Firewall\\Alias");

// $nodes = $model->getNodes();
// $node = $nodes["aliases"]["alias"];
// var_dump($node);

// $fields = $model->iterateItems();
// $fields = $model->iterateRecursiveItems();
// foreach ($fields as $field) {
//     var_dump($field->getNodeData());
// }

// try {
//     $schema = $model->walk();
// } catch (\Error $e) {
//     echo $e::class ."\n";
// }
// $schema = $model->walk();
$schema = $model->getSchema();
$output = $schema;

// $input_file = "./mock_models.json";
// if ($input_file) {

//     $fd = fopen($input_file, "r") or die("Failed to touch '" . $input_file . "'");
//     $json = fread($fd, filesize($input_file));
//     fclose($fd);
//     $mocks = json_decode($json, $associative = true);

//     foreach($mocks as $schema_name => $mock) {
//         $model = $parser->get($schema_name);
//         // var_dump($mock);
//         echo "Validating $schema_name against $model->name\n";

//         $model->validate($mock);
//     }


//     // $models = $parser->get_all($base_path);
//     // foreach ($models as $model) {
//     //     if ($model->is_abstract) {continue;}
//     //     $data = $model->getData();

//     //     var_dump($data);
//     // }
// }

if (!isset($output)) {$output = "BLAH";}
$json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
echo json_encode($output, $json_flags) . "\n";

?>
