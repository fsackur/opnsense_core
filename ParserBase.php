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
    public ?string $schemaName;
    public ?string $parent;
    public bool $isAbstract;
    public string $doc;

    abstract public static function getSchemaName(string $class_name);

    public function __construct(ReflectionClass $rclass, ParsedBase | null $parent)
    {
        $name = $rclass->getName();
        $isAbstract = $rclass->isAbstract();
        $schemaName = null;
        if (!$isAbstract) {
            $schemaName = static::getSchemaName($name);
        }

        $parentName = null;
        $doc = $rclass->getDocComment();
        if ($parent) {
            $parentName = $parent->name;

            if (preg_match("/@inheritdoc/", $doc)) {
                $doc = $parent->doc;
            }
        }

        $this->class = $rclass;
        $this->name = $name;
        $this->schemaName = $schemaName;
        $this->parent = $parentName;
        $this->isAbstract = $isAbstract;
        $this->doc = $doc;
    }
}


/**
 * INVARIANT: parent is always registered before child
 */
abstract class Registry {
    private static ReflectionClass $genericClass;
    private static ReflectionClass $rootClass;
    private static array $registry = [];
    private static array $schemaRegistry = [];

    public static function init(ReflectionClass $genericClass, ReflectionClass $rootClass)
    {
        $genericBaseName = "OPNsense\OpenApi\Parsing\ParsedBase";
        if (!$genericClass->isSubclassOf($genericBaseName)) {
            throw new ReflectionException("$genericClass->name is not a $genericBaseName");
        }
        static::$genericClass = $genericClass;
        static::$rootClass = $rootClass;
    }

    public static function register(ReflectionClass $rclass)
    {
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

        if (!$parent && $rclass != static::$rootClass) {
            return;
        }

        $obj = static::$genericClass->newInstance($rclass, $parent);
        static::$registry[$name] = $obj;

        $translator = static::$genericClass->getMethod("getSchemaName");
        $schemaName = $translator->invoke(null, $name);
        static::$schemaRegistry[$schemaName] = $obj;
    }

    public static function get(string $name)
    {
        if (array_key_exists($name, static::$registry)) {
            return static::$registry[$name];
        }
    }

    public static function dump() {
        $registry = static::$schemaRegistry;
        return $registry;
    }
}


class Parser
{
    public string $basePath;
    private ReflectionClass $genericClass;
    private ReflectionClass $rootClass;
    private ReflectionClass $registry;
    private string $pathRegex;
    private array $classNames = [];

    /**
     * @param string $basePath path to mvc/app
     * @param \ReflectionClass $genericClass subclass of ParsedBase
     * @param \ReflectionClass $rootClass the base of the inheritance tree in src
     * @param \ReflectionClass $registry static class to parse parents before children
     * @param string $pathRegex should match the path relative to mvc/app, including leading slash
     */
    public function __construct(
        string $basePath,
        ReflectionClass $genericClass,
        ReflectionClass $rootClass,
        ReflectionClass $registry,
        string $pathRegex,
    ) {
        $this->basePath = $basePath;
        $this->genericClass = $genericClass;
        $this->rootClass = $rootClass;
        $this->registry = $registry;
        $this->pathRegex = $pathRegex;
        $registry->getMethod("init")->invoke(null, $genericClass, $rootClass);
    }

    public function find_classes()
    {
        if ($this->classNames) {
            return $this->classNames;
        }

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->basePath));
        $class_names = array();

        foreach ($rii as $file) {
            if (
                $file->isDir() ||
                !str_ends_with($file, ".php") ||
                !preg_match($this->pathRegex, $file, $matches)
            ) {
                continue;
            }

            $rel_path = preg_replace("/^\w+\//", "", $matches[0]);
            $class_name = preg_replace("/\.php$/", "", $rel_path);
            $class_name = preg_replace("/\//", "\\", $class_name);
            $class_names[] = $class_name;
        }

        $this->classNames = $class_names;
        return $class_names;
    }

    public function registerClasses(array $class_names)
    {
        $register = $this->registry->getMethod("register");
        foreach ($class_names as $c) {
            $rclass = new ReflectionClass($c);
            $register->invoke(null, $rclass);
        }
    }

    public function getAll()
    {
        $class_names = $this->find_classes();
        $this->registerClasses($class_names);

        $dump = $this->registry->getMethod("dump");
        return $dump->invoke(null);
    }

    public function get(string $className)
    {
        $this->registerClasses([$className]);

        $get = $this->registry->getMethod("get");
        return $get->invoke(null, $className);
    }

    public function getBySchemaName(string $schemaName)
    {
        $class_names = $this->find_classes();
        $translator = $this->genericClass->getMethod("getSchemaName");

        foreach ($class_names as $class_name) {
            $name = $translator->invoke(null, $class_name);
            if ($name === $schemaName) {
                return $this->get($class_name);
            }
        }
    }
}
