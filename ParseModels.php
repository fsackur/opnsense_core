<?php

namespace OPNsense\OpenApi\Parsing;

use Error;
use Exception;
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
    public string $type;
    public ?string $reference;
    public ?string $tag;
    public bool $is_ass_array;
    public bool $is_container;
    public bool $is_required;
    public array $children = [];

    public function __construct(BaseField $node) {
        $class = new ReflectionClass($node::class);

        // $class->getMethod("actionPostLoadingEvent")->invoke($node);

        $this->is_required = $node->isRequired();
        $this->type = $class->name;
        $this->tag = $class->getProperty("internalXMLTagName")->getValue($node);
        $this->reference = $node->__reference;
        $this->is_ass_array = $node->isArrayType();
        $this->is_container = $node->isContainer();

        $children = $class->getProperty("internalChildnodes")->getValue($node);
        // foreach ($node->iterateItems() as $key => $child) {
        foreach ($children as $key => $child) {
            $this->children[$key] = new Field($child);
        }
        //         "alias": {
        //             "type": "OPNsense\\Firewall\\FieldTypes\\AliasField",
        //             "reference": "aliases.alias",
        //             "tag": "alias",
        //             "is_ass_array": true,
        //             "is_container": true,
        //             "is_required": false,
        //             "children": {
        //                 "00000000-0000-0000-0000-000000000000": {
        //                     "type": "OPNsense\\Base\\FieldTypes\\ContainerField",
        //                     "reference": "aliases.alias.00000000-0000-0000-0000-000000000000",
        //                     "tag": "alias",
        //                     "is_ass_array": false,
        //                     "is_container": true,
        //                     "is_required": false,
        //                     "children": []
        //                 }
        //             }
        //         }
        return;
        // if ($this->is_ass_array) {
        //     $fakeUuid = "00000000-0000-0000-0000-000000000000";
        //     $ref = "{$this->reference}.{$fakeUuid}";

        //     $class->getMethod("actionPostLoadingEvent")->invoke($node);

        //     // $newField = $class->getMethod("newContainerField");
        //     // $child = $newField->invoke($node, $ref, $this->tag);
        //     $newField = $class->getMethod("getTemplateNode");
        //     $child = $newField->invoke($node);

        //     $this->children[$fakeUuid] = new Field($child);
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

$model = $parser->get("OPNsense\\Firewall\\Alias");

// $output = $model;
$output = $model->field;

$json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
echo json_encode($output, $json_flags) . "\n";


?>
