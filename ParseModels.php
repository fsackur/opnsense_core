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


class Model extends ParsedBase {
    private BaseModel $instance;
    protected array $reflectionProperties = [];
    public BaseField $field;

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

        $this->field = $internalData;
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

$output = $model->field;

$json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
echo json_encode($output, $json_flags) . "\n";


?>
