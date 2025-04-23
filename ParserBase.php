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


require_once dirname(__FILE__) . '/Setup.php';


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
        $registry = static::$schema_registry;
        return $registry;
    }
}


class Parser {
    public string $base_path;
    private ReflectionClass $generic_class;
    private ReflectionClass $root_class;
    private ReflectionClass $registry;
    private string $path_regex;
    private array $class_names = [];

    /**
     * @param string $base_path path to mvc/app
     * @param \ReflectionClass $generic_class subclass of ParsedBase
     * @param \ReflectionClass $root_class the base of the inheritance tree in src
     * @param \ReflectionClass $registry static class to parse parents before children
     * @param string $path_regex should match the path relative to mvc/app, including leading slash
     */
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
                !preg_match($this->path_regex, $file, $matches)
            ) {
                continue;
            }

            $rel_path = preg_replace("/^\w+\//", "", $matches[0]);
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
                return $this->get($class_name);
            }
        }
    }
}
