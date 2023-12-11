<?php

namespace baykit\bayserver;

class BayLog {

    const LOG_LEVEL_TRACE = 0;
    const LOG_LEVEL_DEBUG = 1;
    const LOG_LEVEL_INFO = 2;
    const LOG_LEVEL_WARN = 3;
    const LOG_LEVEL_ERROR = 4;
    const LOG_LEVEL_FATAL = 5;
    const LOG_LEVEL_NAME = ["TRACE", "DEBUG", "INFO ", "WARN ", "ERROR", "FATAL"];

    public static $logLevel = BayLog::LOG_LEVEL_INFO;
    public static $fullPath = False;

    public static function set_log_level(string $lvl) : void
    {
        $lvl = strtolower($lvl);
        switch ($lvl) {
            case "trace":
                BayLog::$logLevel = BayLog::LOG_LEVEL_TRACE;
                break;
            case "debug":
                BayLog::$logLevel = BayLog::LOG_LEVEL_DEBUG;
                break;
            case "info":
                BayLog::$logLevel = BayLog::LOG_LEVEL_INFO;
                break;
            case "warn":
                BayLog::$logLevel = BayLog::LOG_LEVEL_WARN;
                break;
            case "error":
                BayLog::$logLevel = BayLog::LOG_LEVEL_ERROR;
                break;
            case "fatal":
                BayLog::$logLevel = BayLog::LOG_LEVEL_FATAL;
                break;
            default:
                warn(BayMessage::get(Symbol::INT_UNKNOWN_LOG_LEVEL, $lvl));
        }
    }

    public static function set_full_path(bool $fullPath) : void
    {
        BayLog::$fullPath = $fullPath;
    }

    public static function info(string $fmt, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_INFO, 3, null, $fmt, $args);
    }

    public static function info_e(\Throwable $err, string $fmt, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_INFO, 3, $err, $fmt, $args);
    }

    public static function trace(string $fmt, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_TRACE, 3, null, $fmt, $args);
    }

    public static function debug(string $fmt, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_DEBUG, 3, null, $fmt, $args);
    }

    public static function debug_e(\Throwable $err, string $fmt=null, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_DEBUG, 3, $err, $fmt, $args);
    }

    public static function warn(string $fmt, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_WARN, 3, null, $fmt, $args);
    }

    public static function warn_e(\Throwable $err, string $fmt=null, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_WARN, 3, $err, $fmt, $args);
    }

    public static function error(string $fmt, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_ERROR, 3, null, $fmt, $args);
    }

    public static function error_e(\Throwable $err, string $fmt=null, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_ERROR, 3, $err, $fmt, $args);
    }

    public static function fatal(string $fmt, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_FATAL, 3, null, $fmt, $args);
    }

    public static function fatal_e(\Throwable $err, string $fmt, ...$args) : void
    {
        BayLog::log(BayLog::LOG_LEVEL_FATAL, 3, $err, $fmt, $args);
    }

    public static function log(int $lvl, int $stack_idx, ?\Throwable $err, ?string $fmt, ?array $args) : void
    {
        list($file, $line) = BayLog::getCaller($stack_idx);
        if (!BayLog::$fullPath) {
            $file = basename($file);
        }
        $pos = "{$file}:{$line}";

        if ($fmt !== null) {
            if ($lvl >= BayLog::$logLevel) {
                try {
                    if ($args === null || count($args) == 0) {
                        $msg = sprintf("%s", $fmt);
                    } else {
                        $msg = sprintf($fmt, ...$args);
                    }
                } catch (\Exception $e) {
                    var_dump($e->getTrace());
                    $msg = $fmt;
                }

                echo("[" . date('r') . "] " . BayLog::LOG_LEVEL_NAME[$lvl] . ". {$msg} (at {$pos})\n");
            }
        }

        if ($err !== null) {
            if (self::isDebugMode() || self::$logLevel == self::LOG_LEVEL_FATAL)
                self::printStackTrace($err);
            else
                BayLog::log($lvl, 4, null, "%s", [$err->getMessage()]);
        }
    }

    private static function getCaller(int $idx) : array
    {
        $trace = debug_backtrace();
        $frame = $trace[$idx - 1];
        return array($frame["file"], $frame["line"]);
    }

    private static function printStackTrace(\Throwable $err)
    {
        $class = get_class($err);
        echo("{$class}: {$err->getMessage()}\n");
        echo("  {$err->getFile()}({$err->getLine()})\n");
        foreach($err->getTrace() as $line) {
            if(array_key_exists("line", $line))
                echo("  {$line["file"]}({$line["line"]})\n");
            else
                echo("  {$line["file"]}\n");
        }
    }

    public static function isDebugMode() : bool
    {
        return self::$logLevel <= self::LOG_LEVEL_DEBUG;
    }

    public static function isTraceMode() : bool
    {
        return self::$logLevel == self::LOG_LEVEL_TRACE;
    }
}
