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
            $backend_output = null;
        } else {
            if (!stream_get_meta_data($stream)["seekable"]) {
                $seekable_stream = fopen('php://memory','r+');
                stream_copy_to_stream($stream, $seekable_stream);
                $stream = $seekable_stream;
                rewind($stream);
            }

            $backend_output = $this->processStream($stream, $event, $timeout);
            rewind($stream);
        }
        static::$calls[$event] = $backend_output;

        return $stream;
    }
}

class Backend extends TracingBackend {}
