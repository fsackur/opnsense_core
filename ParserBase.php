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
use ReflectionClass;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use ReflectionException;

$DEFAULT_SOURCE_DIR = "/usr/local/opnsense/mvc/app";


abstract class ParsedBase {
    protected ReflectionClass $class;
    public string $name;
    public ?string $schema_name;
    public ?string $parent;
    public bool $is_abstract;
    public string $doc;

    abstract public static function get_schema_name(string $class_name);

    public function __construct(ReflectionClass $rclass, ParsedBase | null $parent)
    {
        $name = $rclass->getName();
        $is_abstract = $rclass->isAbstract();
        $schema_name = null;
        if (!$is_abstract) {
            $schema_name = static::get_schema_name($name);
        }

        $parent_name = null;
        $doc = $rclass->getDocComment();
        if ($parent)
        {
            $parent_name = $parent->name;

            if (preg_match("/@inheritdoc/", $doc)) {
                $doc = $parent->doc;
            }
        }

        $this->class = $rclass;
        $this->name = $name;
        $this->schema_name = $schema_name;
        $this->parent = $parent_name;
        $this->is_abstract = $is_abstract;
        $this->doc = $doc;
    }
}


/**
 * INVARIANT: parent is always registered before child
 */
abstract class Registry {
    private static ReflectionClass $generic_class;
    private static ReflectionClass $root_class;
    private static array $registry = [];
    private static array $schema_registry = [];

    public static function init(ReflectionClass $generic_class, ReflectionClass $root_class) {
        $generic_base_name = "OPNsense\OpenApi\Parsing\ParsedBase";
        if (!$generic_class->isSubclassOf($generic_base_name)) {
            throw new ReflectionException("$generic_class->name is not a $generic_base_name");
        }
        static::$generic_class = $generic_class;
        static::$root_class = $root_class;
    }

    public static function register(ReflectionClass $rclass) {
        $name = $rclass->getName();
        if (array_key_exists($name, static::$registry)) {
            return;
        }

        $rparent = $rclass->getParentClass();
        if ($rparent) {
            static::register($rparent);
            $parent = static::get($rparent->name);
        } else {
            $parent = null;
        }

        if (!$parent && $rclass != static::$root_class) {
            return;
        }

        $obj = static::$generic_class->newInstance($rclass, $parent);
        static::$registry[$name] = $obj;

        $translator = static::$generic_class->getMethod("get_schema_name");
        $schema_name = $translator->invoke(null, $name);
        static::$schema_registry[$schema_name] = $obj;
    }

    public static function get(string $name) {
        if (array_key_exists($name, static::$registry)) {
            return static::$registry[$name];
        }
    }

    public static function dump() {
        return array_values(static::$registry);
    }
}


class Parser {
    public string $base_path;
    private ReflectionClass $generic_class;
    private ReflectionClass $root_class;
    private ReflectionClass $registry;
    private string $path_regex;
    private array $class_names = [];

    public function __construct(
        string $base_path,
        ReflectionClass $generic_class,
        ReflectionClass $root_class,
        ReflectionClass $registry,
        string $path_regex,
    ) {
        $this->base_path = $base_path;
        $this->generic_class = $generic_class;
        $this->root_class = $root_class;
        $this->registry = $registry;
        $this->path_regex = $path_regex;
        $registry->getMethod("init")->invoke(null, $generic_class, $root_class);
    }

    public function find_classes() {
        if ($this->class_names) {
            return $this->class_names;
        }

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base_path));
        $class_names = array();

        foreach ($rii as $file) {
            if (
                $file->isDir() ||
                !str_ends_with($file, ".php") ||
                !preg_match($this->path_regex, $file)
            ) {continue;}

            $rel_path = preg_replace("/.*(?=OPNsense)/", "", $file);
            $class_name = preg_replace("/\.php$/", "", $rel_path);
            $class_name = preg_replace("/\//", "\\", $class_name);
            $class_names[] = $class_name;
        }

        $this->class_names = $class_names;
        return $class_names;
    }

    public function register_classes(array $class_names)
    {
        $register = $this->registry->getMethod("register");
        foreach ($class_names as $c) {
            $rclass = new ReflectionClass($c);
            $register->invoke(null, $rclass);
        }
    }

    public function get_all()
    {
        $class_names = $this->find_classes();
        $this->register_classes($class_names);

        $dump = $this->registry->getMethod("dump");
        return $dump->invoke(null);
    }

    public function get(string $class_name)
    {
        $this->register_classes([$class_name]);

        $get = $this->registry->getMethod("get");
        return $get->invoke(null, $class_name);
    }

    public function get_by_schema_name(string $schema_name)
    {
        $class_names = $this->find_classes();
        $translator = $this->generic_class->getMethod("get_schema_name");
        $get = $this->registry->getMethod("get");

        foreach ($class_names as $class_name) {
            $name = $translator->invoke(null, $class_name);
            if ($name === $schema_name) {
                return $get->invoke(null, $class_name);
            }
        }
    }
}


//region argparse
$opts = getopt("s:o:", ["source-folder:", "output-file:"]);
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
//endregion argparse


function dump_json($data, $output_file = null, $pretty = false)
{
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

function load_json($input_file = null, $associative = true)
{
    $depth = 512;
    $flags = JSON_THROW_ON_ERROR;

    $fd = fopen($input_file, "r") or die("Failed to open '" . $input_file . "'");
    $json = fread($fd, filesize($input_file));
    return json_decode($json, $associative, $depth, $flags);
}


$config = require $app_dir . "/config/config.php";
$config->update('globals.config_path', __DIR__ . "/");
$config->update('application.contribDir', $contrib_base . "/contrib");

set_include_path($contrib_dir);
require $app_dir . "/config/loader.php";

?>
