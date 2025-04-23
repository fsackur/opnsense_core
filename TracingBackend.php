<?php

namespace OPNsense\Core;

use \OPNsense\OpenApi\Parsing\MockBackendBase;

require_once __DIR__ . "/MockBackendBase.php";

class TracingBackend extends MockBackendBase
{
    public function configdStream($event, $detach = false, $connect_timeout = 10, $poll_timeout = 2)
    {
        $timeout = 120;

        $stream = parent::configdStream($event, $detach, $connect_timeout, $poll_timeout);

        if ($stream === null) {
            $output = null;
        } else {
            $output = $this->processStream($stream, $event, $timeout);
            rewind($stream);
        }
        static::$calls[$event] = $output;

        return $stream;
    }
}

class Backend extends TracingBackend {}
