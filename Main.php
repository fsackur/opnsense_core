<?php

namespace OPNsense\OpenApi\Parsing;

use Exception;
use ReflectionClass;
use OPNsense\Base\BaseModel;

require_once __DIR__ . "/Setup.php";
require_once __DIR__ . "/ParseModels.php";


setup_mocks();

$parser = new Parser(
    $basePath = $config->__get("application")->modelsDir,
    new ReflectionClass(Model::class),
    new ReflectionClass(BaseModel::class),
    new ReflectionClass(ModelRegistry::class),
    $pathRegex = "/models\/\w+\/\w+\/\w+\.php/",
);


$schemaNames = [];
foreach ($options->models as $className) {
    $schemaNames[] = Model::getSchemaName($className);
}

$generateExamples = $options->generateExamples;
$generateSchemas = $options->generateSchemas || ($generateExamples && !file_exists($options->schemaFile));
$generateModels = $options->generateModels;


$models = [];
if ($options->models) {
    foreach ($options->models as $modelName) {
        $model = $parser->get($modelName);
        $models[$model->schemaName] = $model;
    }
} else {
    $models = $parser->getAll();
}

if ($generateModels) {
    dump_json($models, $options->modelFile, true);
}


if ($generateSchemas) {
    $schemas = [];
    foreach ($models as $model) {
        if ($model->isAbstract) {
            continue;
        }
        $schemas[$model->schemaName] = $model->getSchema();
    }
    dump_json($schemas, $options->schemaFile, true);
}

if ($options->generateExamples) {
    $pyOutput = null;
    $pyResultCode = null;

    $command = __DIR__ . "/generate_examples.py";
    $command .= " --schema-file $options->schemaFile";
    $command .= " --output-file $options->exampleFile";
    if ($schemaNames) {
        $command .= " " . implode(" ", $schemaNames);
    }

    exec($command, $pyOutput, $pyResultCode);
    if ($pyResultCode) {
        var_dump($pyOutput);
        throw new Exception($command);
    }

    $examples = load_json($options->exampleFile);

    $results = [];
    foreach ($examples as $schemaName => $example) {
        $model = $parser->getBySchemaName($schemaName);
        $modelResults = $model->validate($example);
        foreach ($modelResults as $ref => $msg) {
            $results["$model->schemaName.$ref"] = $msg;
        }
    }

    foreach ($results as $ref => $msg) {
        echo "$ref: $msg\n";
    }
    $c1 = count($results);
    $c2 = count($examples);
    $errMsg = "$c1 validation error(s) from $c2 model(s)";
    echo "$errMsg\n";
}

finish_mocks();
