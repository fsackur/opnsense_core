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

use Exception;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionException;
use OPNsense\Mvc\Controller as ControllerRootClass;

require_once(dirname(__FILE__) . '/ParserBase.php');


class Parameter {
    public $name;
    public $has_default;
    public $default;

    public function __construct(ReflectionParameter $rparam) {
        $this->name = $rparam->name;
        if ($rparam->isDefaultValueAvailable()) {
            $this->has_default = true;
            $this->default = $rparam->getDefaultValue();
        } else {
            $this->has_default = false;
        }
    }
}


class Method {
    protected static $BASE_METHOD_HTTP_METHODS = [
        "request->isPost" => "POST",
        "request->hasPost" => "POST",
        "request->getPost" => "POST",
        "setAction" => "POST",
        "delBase" => "POST",
        "addBase" => "POST",
        "setBase" => "POST",
        "toggleBase" => "POST",
        "searchBase" => "POST",  // GET works for backward-compatibility, but isn't worth publishing
        "searchRecordsetBase" => "POST",
    ];

    protected static $BASE_METHOD_REQUIRE_BODY = [
        "request->getPost",
        "setAction",
        "addBase",
        "setBase",
    ];

    public $name;
    public $method;  // HTTP method!
    public $parameters = [];
    public $doc;
    public $requires_body;
    public $model_path_map;
    public $request_model;
    public $response_model;

    public function __construct(ReflectionMethod $rmethod, string $src)
    {
        $name = preg_replace("/Action\$/", "", $rmethod->name);
        $request_model = null;
        $response_model = null;

        // Presence in source code of, e.g., "this->request->getPost(" implies POST.
        // See comment in Controller ctor.
        $matches = null;
        $after_deref = "(?<=\\\$this->)";   // lookbehind for "$this->"
        $call = "([^\(]+)";                 // match until opening bracket
        $round_bracket = "(?:\(\\s*)";      // don't capture
        $first_argument = "([^,^\)^\s]*)";  // not comma, closing bracket, or space
        $pattern = "/" . $after_deref . $call . $round_bracket . $first_argument . "/";
        preg_match_all($pattern, $src, $matches);

        $http_method = null;
        $requires_body = False;
        foreach ($matches[1] as $idx => $call) {
            if (array_key_exists($call, self::$BASE_METHOD_HTTP_METHODS)) {
                $http_method = self::$BASE_METHOD_HTTP_METHODS[$call];
                $requires_body = $requires_body || in_array($call, self::$BASE_METHOD_REQUIRE_BODY);
            }
        }
        if (!$http_method) {$http_method = "GET";}

        $matches = null;
        $pattern = "/\\\$this->((search|searchRecordset|get|add|del|set|toggle)Base|request->getPost)\(([^\)]*)\)/";
        preg_match_all($pattern, $src, $matches);

        $model_path_map = null;
        if ($matches[0]) {
            // Multiple calls to delBase in IPsec\Api\ConnectionsController and
            // Unbound\Api\SettingsController. In both cases, the earlier calls are
            // "cascade deletes", and it seems probable that the last call is always
            // going to be the "main" model change. So we'll take the last match
            // for each capture group.
            $call = array_slice($matches[0], -1)[0];
            $base_method = array_slice($matches[1], -1)[0];
            $args = preg_split("/,\s*/", trim(array_slice($matches[3], -1)[0]));

            if (in_array($base_method, ["searchBase", "searchRecordsetBase"])) {
                $request_model = "search.request";
                $response_model = "search.response";
            } elseif (in_array($base_method, ["addBase", "setBase", "getBase"])) {
                $model_path_map = $args[0] . ":" . $args[1];
            } elseif (in_array($base_method, ["toggleBase", "delBase"])) {
                $response_model = "result";
            } elseif ($base_method === "request->getPost") {
                $model_path_map = $args[0] . ":";
            } else {
                $model_path_map = ":" . $args[0];
            }

            if ($model_path_map) {
                $model_path_map = preg_replace("/\"|'/", "", $model_path_map);
            }
        }

        $params = [];
        $rparams = $rmethod->getParameters();
        foreach ($rparams as $rparam) {
            $params[] = new Parameter($rparam);
        }

        $doc = $rmethod->getDocComment();
        if (preg_match("/@inheritdoc/", $doc)) {
            $rclass = $rmethod->getDeclaringClass();
            $rparent = $rclass->getParentClass();
            $parent = ControllerRegistry::get($rparent->getName());
            $parent_method = array_filter($parent->methods, function ($method) use ($name) {
                return $method->name === $name;
            })[0];
            $doc = $parent_method->doc;
        }

        $this->name = $name;
        $this->method = $http_method;
        $this->parameters = $params;
        $this->doc = $doc;
        $this->requires_body = $requires_body;
        $this->model_path_map = $model_path_map;
        $this->request_model = $request_model;
        $this->response_model = $response_model;
    }
}


class Controller extends ParsedBase {
    public $methods = [];
    public $model;
    public $model_name;

    public function __construct(ReflectionClass $rclass, Controller | null $parent)
    {
        parent::__construct($rclass, $parent);
        $name = $this->name;

        $model = null;
        try {
            $prop = $rclass->getProperty("internalModelClass");
            if ($prop) {
                $model = $prop->getDefaultValue();
            }
        } catch (ReflectionException $e) {
            if ($parent) {
                $model = $parent->model;
            }
        }

        $model_name = null;
        try {
            $prop = $rclass->getProperty("internalModelName");
            if ($prop) {
                $model_name = $prop->getDefaultValue();
            }
        } catch (ReflectionException $e) {
            if ($parent) {
                $model_name = $parent->model_name;
            }
        }

        if ($model && $model[0] == "\\") {
            $model = substr($model, 1);
        }

        $doc = $rclass->getDocComment();
        if (preg_match("/@inheritdoc/", $doc)) {
            // there's only one class with no parent, and it doesn't use @inheritdoc
            $doc = $parent->doc;
        }

        $this->model = $model;
        $this->model_name = $model_name;

        /**
         * A route does not define an HTTP method - all routes accept all methods.
         *
         * `collect_api_endpoints.py` in the `docs` repo looks for method calls that imply
         * POST. This makes me nervous. There is no way to defend against, e.g.:
         *     if (request->isPost()) {throw WrongMethod("ha ha sucker")}
         *
         * In my view, when Ad talks about complexity and maintenance, this is the danger.
         *
         * I presume that unusual usage would be picked up in plugin code review, though. So I
         * assume: it's been working for years, it's good enough.
         *
         * I considered nikic/PHP-Parser, but I don't think it's worth adding a dependency.
         */
        $filename = $rclass->getFileName();
        $fd = fopen($filename, "r") or die("Failed to read '" . $filename . "'");
        $src = fread($fd, 1000000);
        fclose($fd);
        $src_lines = explode("\n", $src);

        $methods = [];
        if ($parent) {
            foreach ($parent->methods as $m) {
                $methods[$m->name] = $m;
            }
        }
        foreach ($rclass->getMethods(ReflectionMethod::IS_PUBLIC) as $rmethod) {
            // skip inherited (we already added them)
            if ($rmethod->getDeclaringClass()->name != $name) {continue;}

            $method_name = $rmethod->name;
            if (!str_ends_with($method_name, "Action")) {continue;}  // algorithm in Dispatcher.php

            // get the body of the method as raw source code - see earlier comment
            $start = $rmethod->getStartLine();
            $length = $rmethod->getEndLine() - $start;
            $method_src = implode("\n", array_slice($src_lines, $start, $length));

            $method = new Method($rmethod, $method_src);
            $methods[$method->name] = $method;
        }

        if ($name == "OPNsense\\Base\\ApiMutableModelControllerBase") {
            $methods["set"]->response_model = "result";
        } elseif ($name == "OPNsense\\Base\\ApiMutableServiceControllerBase") {
            $methods["start"]->response_model = "response";
            $methods["stop"]->response_model = "response";
            $methods["restart"]->response_model = "response";
            $methods["reconfigure"]->response_model = "status";
            $methods["status"]->response_model = "status";
        } elseif ($name == "OPNsense\\Monit\\Api\\SettingsController") {
            $methods["getGeneral"]->model_path_map = "monit:general";
        }

        $this->methods = array_values($methods);
    }
}


class ControllerRegistry extends Registry {
    public static function get_schema_name(string $class_name) {
        $name = strtolower($class_name);
        $name = str_replace(["\\api", "controller"], "", $name);
        return str_replace("\\", ".", $name);
    }
}


$base_path = $config->__get("application")->controllersDir;
$parser = new Parser(
    new ReflectionClass(Controller::class),
    new ReflectionClass(ControllerRootClass::class),
    new ReflectionClass(ControllerRegistry::class),
    $path_regex = "/\/Api\/\w+Controller/",
);

echo $parser->export($base_path, $output_file, true);

?>
