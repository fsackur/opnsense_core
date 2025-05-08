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

$arguments = [
    new Param("source-folder", $default = "/usr/local/opnsense/mvc/app"),
    new Param("output-folder"),
];

function toCamelCase(string $arrowCase) {
    $camelCase = ucwords($arrowCase, "-");
    $camelCase = str_replace("-", "", $camelCase);
    $camelCase[0] = strtolower($camelCase[0]);
    return $camelCase;
}

class Arg {
    public readonly string $property;
    public readonly string $arg;
    public readonly string $shortArg;
    public readonly ?string $default;
    public function __construct(string $arg = null, ?string $property = null, ?string $shortArg = null, ?string $default = null) {
        $this->arg = $arg;

        if ($property === null) {
            $property = toCamelCase($arg);
        }
        $this->property = $property;

        if ($shortArg === null) {
            $shortArg = $arg[0];
        }
        $this->shortArg = $shortArg;
        $this->default = $default;
    }

    public function buildOpts(string $shortOpts, array $longOpts) {
        $shortOpts .= $this->shortArg;
        $longOpts = [...$longOpts, $this->arg];
        return [$shortOpts, $longOpts];
    }
}


class Param extends Arg {
    public function buildOpts(string $shortOpts, array $longOpts) {
        $optArgs = parent::buildOpts($shortOpts, $longOpts);
        $optArgs[0] .= ":";
        $optArgs[1][count($optArgs[1]) - 1] .= ":";
        return $optArgs;
    }
}

class CliOptions {
    private static Self $instance;

    // private static string $DEFAULT_SOURCE_FOLDER = "/usr/local/opnsense/mvc/app";

    public readonly string $sourceFolder;
    public readonly string $outputFolder;


    private function __construct() {
        global $argc, $arguments;

        // $shortOpts = "";
        // $longOpts = [];
        $optArgs = ["", []];
        foreach ($arguments as $argument) {
            $optArgs = $argument->buildOpts(...$optArgs);
        }
        var_dump($optArgs);

        // $shortOpts = implode(array_values(static::$arguments));
        // $opts = getopt($shortOpts, array_keys(static::$arguments));

        // $sourceFolder = array_pop($opts);
        // if (!isset($sourceFolder)) {
        //     $sourceFolder = static::$DEFAULT_SOURCE_FOLDER;
        // }
        // $this->sourceFolder = $sourceFolder;
        // // echo "$this->sourceFolder\n";

        // $outputFolder = array_pop($opts);
        // if (!isset($outputFolder)) {
        //     $outputFolder = __DIR__;
        // }
        // $this->outputFolder = $outputFolder;
        // echo "$this->outputFolder\n";
        // var_dump(new Arg("source-folder"));
        // var_dump(new Param("source-folder"));
        var_dump($arguments);
    }

    public static function read() {
        if (!isset(self::$instance)) {
            self::$instance = new static();
        }
        return self::$instance;
    }
}

$options = CliOptions::read();

// if (array_key_exists("s", $opts)) {
//     $source_folder = $opts["s"];
// } elseif (array_key_exists("source-folder", $opts)) {
//     $source_folder = $opts["source-folder"];
// } else {
//     $source_folder = null;
// }

// if (!$source_folder) {
//     $source_folder = $DEFAULT_SOURCE_DIR;
//     $app_dir = realpath($source_folder);
// } else {
//     $app_base = $source_folder;
//     while ($app_base != "/") {
//         $app_dir = realpath($app_base . "/mvc/app");
//         if ($app_dir) {break;}
//         $app_base = dirname($app_base);
//     }
// }
// if (!$app_dir) {
//     throw new InvalidArgumentException("Could not find 'mvc/app' folder in any parent of " . $source_folder);
// }

// $contrib_base = $app_dir;
// while ($contrib_base != "/") {
//     $contrib_dir = realpath($contrib_base . "/contrib");
//     if ($contrib_dir && realpath($contrib_base . "/contrib/tzdata/iso3166.tab")) {break;}
//     $contrib_base = dirname($contrib_base);
// }
// if (!$contrib_dir) {
//     throw new InvalidArgumentException("Could not find 'contrib' folder in any parent of " . $app_dir);
// }

// $should_trace = array_key_exists("t", $opts);

// if (array_key_exists("m", $opts)) {
//     $model_file = "models.json";
// } else {
//     $model_file = null;
// }
// if (array_key_exists("z", $opts)) {
//     $schema_file = "schemas.json";
// } else {
//     $schema_file = null;
// }

// $should_generate_examples = array_key_exists("g", $opts);

// if (array_key_exists("x", $opts)) {
//     $example_file = "examples.json";
// } else {
//     $example_file = null;
// }
