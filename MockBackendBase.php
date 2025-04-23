<?php

/*
 * Copyright (C) 2015 Deciso B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\OpenApi\Parsing;

use Exception;
use ValueError;
use ReflectionClass;


function dump_mocks(array $data, $output_file = null) {
    $chunks = [];
    foreach ($data as $event => $output) {
        $chunks[] = "[$event]";
        $chunks[] = (string) $output;
    }
    $content = implode("\n", $chunks);

    if ($output_file) {
        if (!trim($content)) {
            throw new ValueError("Not writing empty mock data");
        }
        $fd = fopen($output_file, "w") or die("Failed to touch '" . $output_file . "'");
        fwrite($fd, $content);
        fclose($fd);
    } else {
        echo $content;
    }
}


function load_mocks($input_file) {
    $fd = fopen($input_file, "r") or die("Failed to open '" . $input_file . "'");
    $content = fread($fd, filesize($input_file));
    $lines = explode("\n", ltrim($content));

    $mocks = [];
    $acc = [];
    $event = null;
    foreach ($lines as $line) {
        if (preg_match("/^\[(.+)\]$/", $line, $matches)) {
            if ($event) {
                $mocks[$event] = implode("\n", $acc);
            } elseif (count($acc)) {
                throw new ValueError("Should not have data before the first header.");
            }
            $event = $matches[1];
            $acc = [];
        } else {
            $acc[] = $line;
        }

        if ($event && count($acc)) {
            $mocks[$event] = implode("\n", $acc);
        }
    }
    return $mocks;
}



abstract class MockBackendBase
{
    private static string $app_dir;
    private $instance;
    private static ReflectionClass $class;
    public static array $calls = [];

    public static function setAppDir($app_dir) {
        static::$app_dir = $app_dir;
    }

    private function getInstance() {
        if (!isset($this->instance)) {
            if (!isset(static::$class)) {
                $sourcePath = static::$app_dir . '/library/OPNsense/Core/Backend.php';
                $fd = fopen($sourcePath, "r") or die("Failed to open '" . $sourcePath . "'");
                $sourceCode = fread($fd, filesize($sourcePath));

                $sourceCode = preg_replace("/^<\?php/", "", $sourceCode);
                $sourceCode = preg_replace("/class Backend/", "class RealBackend", $sourceCode);
                eval($sourceCode);
                static::$class = new ReflectionClass("OPNsense\\Core\\RealBackend");
            }

            $this->instance = static::$class->newInstance();
        }
        return $this->instance;
    }

    public function __call($name, $arguments) {
        $instance = $this->getInstance();
        return $instance->$name(...$arguments);
    }

    protected function getLogger($ident = 'configd.py') {
        $instance = $this->getInstance();
        $getLogger = static::$class->getMethod("getLogger");
        return $getLogger->invoke($instance, $ident);
    }


    protected static function toStream(string $output)
    {
        $stream = fopen('php://memory','r+');
        fwrite($stream, $output);
        rewind($stream);
        return $stream;
    }

    protected function processStream($stream, $event, $timeout)
    {
        $endOfStream = chr(0) . chr(0) . chr(0);
        $errorOfStream = 'Execute error';
        $resp = '';

        //region lifted from base configdRun
        // read response data
        $starttime = time();
        while (is_resource($stream)) {
            $resp = $resp . stream_get_contents($stream);

            if (strpos($resp, $endOfStream) !== false) {
                // end of stream detected, exit
                break;
            }

            // handle timeouts
            if ((time() - $starttime) > $timeout) {
                $this->getLogger()->error("Timeout (" . $timeout . ") executing : " . $event);
                return null;
            } elseif (feof($stream)) {
                $this->getLogger()->error("Configd disconnected while executing : " . $event);
                return null;
            }
        }

        if (
            strlen($resp) >= strlen($errorOfStream) &&
            substr($resp, 0, strlen($errorOfStream)) == $errorOfStream
        ) {
            return null;
        }

        return str_replace($endOfStream, '', $resp);
        //endregion lifted from base configdRun
    }

    public function configdRun($event, $detach = false, $timeout = 120, $connect_timeout = 10)
    {
        $stream = $this->configdStream($event, $detach, $connect_timeout);
        $output = $this->processStream($stream, $event, $timeout);
        return $output;
    }
}
