<?php

namespace OPNsense\OpenApi\Parsing;

use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionException;
use OPNsense\Base\BaseModel;

require_once(dirname(__FILE__) . '/ParserBase.php');


class Model extends ParsedBase {
    private $instance;

    public function __construct(ReflectionClass $rclass, Model | null $parent)
    {
        parent::__construct($rclass, $parent);
        $name = $this->name;

        if ($this->is_abstract) {
            return;
        }
        $this->instance = $rclass->newInstance();
    }

    public function getData() {
        $this->instance->Default();
        return $this->instance->getNodes();
    }

    public function validate(BaseField $nodes) {
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

if ($output_file) {
    $parser->export($base_path, $output_file, true);
}

$models = $parser->get_all($base_path);


foreach ($models as $model) {
    if ($model->is_abstract) {continue;}
    $data = $model->getData();

    var_dump($data);
}

?>
