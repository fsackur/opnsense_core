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
    private static array $registry = array();

    public static function init(ReflectionClass $generic_class, ReflectionClass $root_class) {
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
    private ReflectionClass $generic_class;
    private ReflectionClass $root_class;
    private ReflectionClass $registry;
    private string $path_regex;

    public function __construct(
        ReflectionClass $generic_class,
        ReflectionClass $root_class,
        ReflectionClass $registry,
        string $path_regex,
    ) {
        $this->generic_class = $generic_class;
        $this->root_class = $root_class;
        $this->registry = $registry;
        $this->path_regex = $path_regex;
        $registry->getMethod("init")->invoke(null, $generic_class, $root_class);
    }

    public function find_classes(string $base_path) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_path));
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

    public function get_all($base_path)
    {
        $class_names = $this->find_classes($base_path);
        $this->register_classes($class_names);

        $dump = $this->registry->getMethod("dump");
        return $dump->invoke(null);
    }

    public function get($class_name)
    {
        $register = $this->registry->getMethod("register");
        $rclass = new ReflectionClass($class_name);
        $register->invoke(null, $rclass);

        $get = $this->registry->getMethod("get");
        return $get->invoke(null, $class_name);
    }

    public function export($base_path, $output_file = null, $pretty = false)
    {
        $json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $json_flags = $json_flags | JSON_PRETTY_PRINT;
        }

        $output = $this->get_all($base_path);
        $json = json_encode($output, $json_flags) . "\n";

        if ($output_file) {
            $fd = fopen($output_file, "w") or die("Failed to touch '" . $output_file . "'");
            fwrite($fd, $json);
            fclose($fd);
        } else {
            return $json;
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


$config = require $app_dir . "/config/config.php";
$config->update('globals.config_path', __DIR__ . "/");
$config->update('application.contribDir', $contrib_base . "/contrib");

set_include_path($contrib_dir);
require $app_dir . "/config/loader.php";

?>
