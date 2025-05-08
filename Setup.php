<?php

namespace OPNsense\OpenApi\Parsing;

$service_tempfile = "/tmp/configdmodelfield.data";

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

require_once __DIR__ . "/CliOptions.php";
$options = CliOptions::get();

require_once __DIR__ . '/MockBackendBase.php';
MockBackendBase::setAppDir($options->appDir);

function setup_mocks() {
    global $service_tempfile, $options;

    if ($options->trace) {
        include_once __DIR__ . '/TracingBackend.php';
        if (file_exists($service_tempfile)) {
            unlink($service_tempfile);
        }
    } else {
        include_once __DIR__ . '/MockBackend.php';
        MockBackendBase::$calls = load_mocks($options->backendMockFile);
    }
}

function finish_mocks() {
    global $options;

    if ($options->trace) {
        dump_mocks(MockBackendBase::$calls, $options->backendMockFile);
    }
}


$config = include "$options->appDir/config/config.php";
$config->update('globals.config_path', "$options->outputFolder/");
$config->update('application.contribDir', $options->contribDir);
set_include_path($options->contribDir);
require_once "$options->appDir/config/loader.php";
