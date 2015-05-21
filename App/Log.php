<?php

namespace App;

use Exception;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
* Utilities
*/
class Log
{
    
    static $LOGGER;

    public static function initMonolog($log_path) {
        // init monolog
        self::$LOGGER = new Logger('nt');

        // format stream
        $stream = new StreamHandler($log_path, Logger::DEBUG);
        $stream->setFormatter(new LineFormatter("[%datetime%] %channel%.%level_name% %message%\n", "Y-m-d H:i:s", true));

        self::$LOGGER->pushHandler($stream);
    }

    public static function debug($text) { self::wlog($text, Logger::DEBUG); }
    public static function info($text) { self::wlog($text, Logger::INFO); }
    public static function warn($text) { self::wlog($text, Logger::WARNING); }

    public static function wlog($text, $level = Logger::INFO) {
        if (self::$LOGGER === null) { throw new Exception("log not inited", 1); }

        self::$LOGGER->log($level, $text);
    }

    public static function logError(Exception $e) {
        self::wlog("Error at ".$e->getFile().", line ".$e->getLine().": ".$e->getMessage()."\n\n".$e->getTraceAsString());
    }


}

