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
use Backend;
use Loader;


$DEFAULT_SOURCE_DIR = "/usr/local/opnsense/mvc/app";


function dump_json($data, $output_file = null, $pretty = false) {
    $json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if ($pretty) {
        $json_flags = $json_flags | JSON_PRETTY_PRINT;
    }

    $json = json_encode($data, $json_flags) . "\n";

    if ($output_file) {
        $fd = fopen($output_file, "w") or die("Failed to touch '" . $output_file . "'");
        fwrite($fd, $json);
        fclose($fd);
    } else {
        echo $json;
    }
}


function load_json($input_file = null, $associative = true) {
    $depth = 512;
    $flags = JSON_THROW_ON_ERROR;

    $fd = fopen($input_file, "r") or die("Failed to open '" . $input_file . "'");
    $json = fread($fd, filesize($input_file));
    return json_decode($json, $associative, $depth, $flags);
}



//region argparse
$opts = getopt("s:o:tmzgx", ["source-folder:", "output-file:", ""]);
if (array_key_exists("o", $opts)) {
    $output_file = $opts["o"];
} elseif (array_key_exists("output-file", $opts)) {
    $output_file = $opts["output-file"];
} else {
    $output_file = null;
}

if (array_key_exists("s", $opts)) {
    $source_folder = $opts["s"];
} elseif (array_key_exists("source-folder", $opts)) {
    $source_folder = $opts["source-folder"];
} else {
    $source_folder = null;
}

if (!$source_folder) {
    $source_folder = $DEFAULT_SOURCE_DIR;
    $app_dir = realpath($source_folder);
} else {
    $app_base = $source_folder;
    while ($app_base != "/") {
        $app_dir = realpath($app_base . "/mvc/app");
        if ($app_dir) {break;}
        $app_base = dirname($app_base);
    }
}
if (!$app_dir) {
    throw new InvalidArgumentException("Could not find 'mvc/app' folder in any parent of " . $source_folder);
}

$contrib_base = $app_dir;
while ($contrib_base != "/") {
    $contrib_dir = realpath($contrib_base . "/contrib");
    if ($contrib_dir) {break;}
    $contrib_base = dirname($contrib_base);
}
if (!$contrib_dir) {
    throw new InvalidArgumentException("Could not find 'contrib' folder in any parent of " . $app_dir);
}

$should_trace = array_key_exists("t", $opts);

if (array_key_exists("m", $opts)) {
    $model_file = "models.json";
} else {
    $model_file = null;
}
if (array_key_exists("z", $opts)) {
    $schema_file = "schemas.json";
} else {
    $schema_file = null;
}

$should_generate_examples = array_key_exists("g", $opts);

if (array_key_exists("x", $opts)) {
    $example_file = "examples.json";
} else {
    $example_file = null;
}
//endregion argparse


require_once __DIR__ . '/MockBackendBase.php';
MockBackendBase::setAppDir($app_dir);

$mockPath = __DIR__ . "/backend_mocks.txt";

function setup_mocks() {
    global $mockPath, $should_trace;

    if (!isset($should_trace)) {
        return;
    }

    if ($should_trace) {
        require_once __DIR__ . '/TracingBackend.php';
    } else {
        require_once __DIR__ . '/MockBackend.php';
        MockBackendBase::$calls = load_mocks($mockPath);
    }
}

function finish_mocks() {
    global $mockPath, $should_trace;

    if (!isset($should_trace)) {
        return;
    }

    if ($should_trace) {
        echo "path: $mockPath\n";
        dump_mocks(MockBackendBase::$calls, $mockPath);
    }
}


$config = require $app_dir . "/config/config.php";
$config->update('globals.config_path', __DIR__ . "/");
$config->update('application.contribDir', $contrib_base . "/contrib");

set_include_path($contrib_dir);
require $app_dir . "/config/loader.php";

@ini_set('memory_limit', "512M");


$TEST_MODEL = "OPNsense\\Auth\\User";



?>
