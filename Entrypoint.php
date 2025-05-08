<?php
/**
 * Parse controller classes using reflection, to minimise regex parsing.
 *
 * I do the bare minimum in PHP because a) I don't know PHP and b) type system
 * is not great.
 *
 * Called from `parse_endpoints.py`.
 *
 * USAGE:
 *      php ParseControllers.php [ARGS]
 *
 * ARGS:
 *      -o, --output-file      path to write a JSON file
 */

namespace OPNsense\OpenApi\Parsing;


use InvalidArgumentException;

use Attribute;
use ReflectionClass;

#[Attribute]
class Argument
{
    public function __construct(string $shortArg, string $longArg, ?string $default = null) {}
}


class CliOptions {
    private static Self $instance;
    public readonly string $appDir;
    public readonly string $contribDir;

    #[Argument("s:", "source-folder:", "/usr/local/opnsense/mvc/app")]
    public readonly string $sourceFolder;

    #[Argument("o:", "output-folder:", __DIR__)]
    public readonly string $outputFolder;

    #[Argument("t", "trace")]
    public readonly bool $trace;

    #[Argument("m", "generate-models")]
    public readonly bool $generateModels;

    #[Argument("g", "generate-schemas")]
    public readonly bool $generateSchemas;

    #[Argument("x", "generate-examples")]
    public readonly bool $generateExamples;


    private function __construct() {
        $class = new ReflectionClass(__CLASS__);
        $argProps = [];
        $shortOpts = "";
        $longOpts = [];
        $values = [];
        foreach ($class->getProperties() as $prop) {
            $attrs = $prop->getAttributes();
            if ($attrs) {
                $propName = $prop->name;
                $attrArgs = $attrs[0]->getArguments();
                $shortOpts .= $attrArgs[0];
                $longOpts[] = $attrArgs[1];
                $argProps[str_replace(":", "", $attrArgs[0])] = $propName;
                $argProps[str_replace(":", "", $attrArgs[1])] = $propName;
                if (count($attrArgs) > 2) {
                    $values[$propName] = $attrArgs[2];
                } elseif ($prop->getType()->getName() == "bool") {
                    $values[$propName] = false;
                } elseif (strrchr($attrArgs[0], ":") === false) {
                    throw new InvalidArgumentException("Switch $attrArgs[1] should be defined as bool");
                } else {
                    $values[$propName] = new InvalidArgumentException("Parameter $attrArgs[1] is mandatory");
                }
            }
        }

        $opts = getopt($shortOpts, $longOpts);
        foreach ($opts as $arg => $value) {
            $propName = $argProps[$arg];
            if ($value === false) {
                $value = true;  // PHP weirdness
            }
            $values[$propName] = $value;
        }

        foreach (["sourceFolder", "outputFolder"] as $prop)

        foreach ($values as $prop => $value) {
            if (gettype($value) == "object") {
                throw $value;
            }
            $this->$prop = $value;
        }


        // walk backwards looking for /mvc/app folder, for developer ergonomics
        // $appBase = $this->sourceFolder;
        // while ($appBase != "/") {
        //     $appDir = realpath("$appBase/mvc/app");
        //     if ($appDir) {break;}
        //     $appBase = dirname($appBase);
        // }
        // if (!$appDir) {
        //     throw new InvalidArgumentException("Could not find 'mvc/app' folder in any parent of $this->sourceFolder");
        // }

        // $contribBase = $appBase;
        // while ($contribBase != "/") {
        //     $contribDir = realpath("$contribBase/contrib");
        //     if ($contribDir && realpath("$contribBase/contrib/tzdata/iso3166.tab")) {break;}
        //     $contribBase = dirname($contribBase);
        // }
        // if (!$contribDir) {
        //     throw new InvalidArgumentException("Could not find 'contrib' folder in any parent of $appDir");
        // }

        // $this->appDir = $appDir;
        // $this->contribDir = $contribDir;

        var_dump($this);

    }

    public static function read() {
        if (!isset(self::$instance)) {
            self::$instance = new static();
        }
        return self::$instance;
    }
}

$options = CliOptions::read();
