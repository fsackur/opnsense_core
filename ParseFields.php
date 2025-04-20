<?php

namespace OPNsense\OpenApi\Parsing;

use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionException;
use OPNsense\Base\FieldTypes\BaseField;

require_once(dirname(__FILE__) . '/ParserBase.php');


class Field extends ParsedBase {
    public $methods;
    public $is_array;
    public $is_container;
    public $is_required;

    public function __construct(ReflectionClass $rclass, Field | null $parent)
    {
        parent::__construct($rclass, $parent);

        if ($this->is_abstract) {
            return;
        }
        $this->instance = $rclass->newInstance();
    }
}


class FieldRegistry extends Registry {
    public static function get_schema_name(string $class_name) {
        $name = strtolower($class_name);
        return str_replace("\\", ".", $name);
    }
}


$base_path = $config->__get("application")->modelsDir;
$parser = new Parser(
    new ReflectionClass(Field::class),
    new ReflectionClass(BaseField::class),
    new ReflectionClass(FieldRegistry::class),
    $path_regex = "/FieldTypes\/\w+\.php/",
);

echo $parser->export($base_path, $output_file, true);

?>
