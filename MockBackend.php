<?php

namespace OPNsense\Core;

use \OPNsense\OpenApi\Parsing\MockBackendBase;

require_once __DIR__ . "/MockBackendBase.php";

class MockBackend extends MockBackendBase
{
    public function configdStream($event, $detach = false, $connect_timeout = 10, $poll_timeout = 2)
    {
        if (array_key_exists($event, static::$calls)) {
            $output = static::$calls[$event];
            return $this->toStream($output);
        } else {
            trigger_error("No mock found for '$event'", E_USER_WARNING);
            return parent::configdStream($event, $detach, $connect_timeout, $poll_timeout);
        }
    }
}

class Backend extends MockBackend {}
