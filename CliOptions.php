<?php

namespace OPNsense\OpenApi\Parsing;


use InvalidArgumentException;
use Attribute;
use ReflectionClass;

function parseEnum($class, string $text)
{
    $text_ = strtolower($text);
    foreach ($class::cases() as $case) {
        if (str_starts_with($case->value, $text_)) {
            return $case;
        }
    }
    throw new InvalidArgumentException("Not a $class: $text");
}

enum BackendMock: string
{
    case Trace = "trace";
    case Replay = "replay";
    case Unmocked = "unmocked";
}

// echo parseEnum(BackendMock::class, "t")->value; die();

enum GenerationStep: string
{
    case None = "none";
    case Models = "models";
    case Schemas = "schemas";
    case Examples = "examples";
}

#[Attribute]
class Argument
{
    public readonly string $name;
    public readonly string $shortName;
    public readonly bool $isSwitch;
    public readonly string $shortArg;
    public readonly string $longArg;
    public readonly ?string $default;

    public function __construct(string $shortArg, string $longArg, ?string $default = null) {
        $this->shortArg = $shortArg;
        $this->longArg = $longArg;
        $this->default = $default;

        $name = str_replace(":", "", $this->longArg);
        $shortName = str_replace(":", "", $this->shortArg);
        $isSwitch = $name === $longArg;
        if ($isSwitch !== ($shortName === $shortArg)) {
            throw new InvalidArgumentException("$longArg and $shortArg have inconsistent colons");
        }
        if ($isSwitch && $default) {
            throw new InvalidArgumentException("Switch $name cannot have default value");
        }
        $this->name = $name;
        $this->shortName = $shortName;
        $this->isSwitch = $isSwitch;
    }
}

class CliOptions {
    private static Self $instance;
    public readonly string $appDir;
    public readonly string $contribDir;

    #[Argument("s:", "source-folder:", "/usr/local/opnsense/mvc/app")]
    public readonly string $sourceFolder;

    #[Argument("o:", "output-folder:", __DIR__ . "/output")]
    public readonly string $outputFolder;

    #[Argument("b:", "backend:", "replay")]
    public readonly BackendMock $backend;

    #[Argument("g:", "generate:", "examples")]
    public readonly GenerationStep $generate;

    #[Argument("m:", "model:", null)]
    public readonly ?array $models;

    #[Argument("v", "validate")]
    public readonly bool $validate;

    public readonly string $backendMockFile;
    public readonly string $modelFile;
    public readonly string $schemaFile;
    public readonly string $exampleFile;

    private function __construct()
    {
        global $argv;

        $class = new ReflectionClass(__CLASS__);
        $argProps = [];
        $shortOpts = "";
        $longOpts = [];
        $values = [];
        foreach ($class->getProperties() as $prop) {
            $attrs = $prop->getAttributes();
            if ($attrs) {
                $propName = $prop->name;
                $arg = $attrs[0]->newInstance();
                $hasDefault = count($attrs[0]->getArguments()) > 2;

                $longOpts[] = $arg->longArg;
                $argProps[$arg->name] = $propName;
                if ($arg->shortName) {
                    $shortOpts .= $arg->shortArg;
                    $argProps[$arg->shortName] = $propName;
                }

                if ($hasDefault) {
                    $values[$propName] = $arg->default;
                } elseif ($arg->isSwitch) {
                    $values[$propName] = false;
                } else {
                    $values[$propName] = new InvalidArgumentException("Parameter $arg->name is mandatory");
                }
            }
        }

        $restIndex = 0;
        $opts = getopt($shortOpts, $longOpts, $restIndex);
        foreach ($opts as $arg => $value) {
            $propName = $argProps[$arg];
            if ($value === false) {
                $value = true;  // PHP weirdness
            }
            $values[$propName] = $value;
        }
        $posArgs = array_slice($argv, $restIndex);
        if ($posArgs) {
            throw new InvalidArgumentException("Unexpected: " . implode(" ", $posArgs));
        }

        $sourceFolder = realpath($values["sourceFolder"]);
        if (!$sourceFolder) {
            throw new InvalidArgumentException("$prop is not a directory");
        }
        $values["sourceFolder"] = $sourceFolder;

        $outputFolder_ = $values["outputFolder"];
        $outputFolder = realpath($outputFolder_);
        if (!$outputFolder) {
            mkdir($$outputFolder_);
            $outputFolder = realpath($outputFolder_);
        }
        $values["outputFolder"] = $outputFolder;

        $values["backend"] = parseEnum(BackendMock::class, $values["backend"]);
        $values["generate"] = parseEnum(GenerationStep::class, $values["generate"]);

        $models = $values["models"];
        if ($models && !is_array($models)) {
            $values["models"] = [$models];
        }

        foreach ($values as $prop => $value) {
            if (gettype($value) == "object" && get_class($value) == "InvalidArgumentException") {
                throw $value;
            }
            $this->$prop = $value;
        }

        // walk backwards looking for /mvc/app folder
        $appBase = $this->sourceFolder;
        while ($appBase != "/") {
            $appDir = realpath("$appBase/mvc/app");
            if ($appDir) {break;}
            $appBase = dirname($appBase);
        }
        if (!$appDir) {
            throw new InvalidArgumentException("Could not find 'mvc/app' folder in any parent of $this->sourceFolder");
        }
        $this->appDir = $appDir;

        $contribBase = $appBase;
        while ($contribBase != "/") {
            $contribDir = realpath("$contribBase/contrib");
            if ($contribDir && realpath("$contribBase/contrib/tzdata/iso3166.tab")) {break;}
            $contribBase = dirname($contribBase);
        }
        if (!$contribDir) {
            throw new InvalidArgumentException("Could not find 'contrib' folder in any parent of $appDir");
        }
        $this->contribDir = $contribDir;


        $this->backendMockFile = "$this->outputFolder/backend_mocks.txt";
        $this->modelFile = "$this->outputFolder/models.json";
        $this->schemaFile = "$this->outputFolder/schemas.json";
        $this->exampleFile = "$this->outputFolder/examples.json";
    }

    public static function get() {
        if (!isset(self::$instance)) {
            self::$instance = new static();
        }
        return self::$instance;
    }
}
