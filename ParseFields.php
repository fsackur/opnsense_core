<?php

namespace OPNsense\OpenApi\Parsing;

use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionException;
use OPNsense\Base\FieldTypes\BaseField;

require_once __DIR__ . '/ParserBase.php';


class Field extends ParsedBase
{
    // public $methods;
    // public $is_array;
    // public $is_container;
    // public $is_required;


    public static function getSchemaName(string $class_name)
    {
        return preg_replace("/.*\\\/", "", $class_name);
    }

    public function __construct(ReflectionClass $rclass, Field | null $parent)
    {
        parent::__construct($rclass, $parent);

        if ($this->isAbstract) {
            return;
        }
        // $this->instance = $rclass->newInstance();
    }
}


class FieldRegistry extends Registry
{
}


$basePath = $config->__get("application")->modelsDir;
$parser = new Parser(
    $basePath,
    new ReflectionClass(Field::class),
    new ReflectionClass(BaseField::class),
    new ReflectionClass(FieldRegistry::class),
    $pathRegex = "/FieldTypes\/\w+\.php/",
);

$fields = $parser->getAll();

$outputFile = "fields.json";
dump_json($fields, $outputFile, true);
