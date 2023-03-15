<?php

namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;

class SysUtil
{
    static $parallelSupported = null;

    static function runOnWindows() : bool
    {
        return strtolower(substr(PHP_OS, 0, 3)) === 'win';
    }

    static function runOnPhpStorm(): bool
    {
        return getenv("PHPSTORM") == "1";
    }

    static function isAbsolutePath($path): bool
    {
        if(SysUtil::runOnWindows()) {
            // Check drive letters
            if(strlen($path) > 2 && $path[1] == ":") {
                return $path[2] == "\\" || $path[2] == "/";
            }
            return false;
        }
        else {
            return substr($path, 0, 1) == "/";
        }
    }

    static function supportFork(): bool
    {
        if (self::runOnPhpStorm())
            return !self::runOnWindows();

        try {
            $pid = pcntl_fork();
            if ($pid == -1)
                return false;
            elseif ($pid == 0) {
                # Child process
                exit(0);
            }
            else {
                pcntl_waitpid($pid, $status);
                return true;
            }
        }
        catch(\Error $e) {
            BayLog::debug_e($e, "fork error");
            return false;
        }
    }

    static function supportSelectFile(): bool
    {
        $f = fopen(BayServer::$bservPlan, "r");
        try {
            $ra = [$f];
            $wa = [];
            $ea = [];
            $n = stream_select($ra, $wa, $ea, 10);
            if ($n === false) {
                BayLog::debug(SysUtil::lastErrorMessage());
                return false;
            }
            else
                return true;
        } finally {
            fclose($f);
        }
    }

    static function supportNonblockFileRead(): bool
    {
        $f = fopen(BayServer::$bservPlan, "r");
        try {
            $res = stream_set_blocking($f, false);
            if ($res === false) {
                BayLog::debug(SysUtil::lastErrorMessage());
                return false;
            }
            else
                return true;
        } finally {
            fclose($f);
        }
    }

    static function supportNonblockFileWrite(): bool
    {
        $fname = self::joinPath(sys_get_temp_dir(), "bserv_test_file");
        $f = fopen($fname, "wb");
        try {
            $res = stream_set_blocking($f, false);
            if ($res === false) {
                BayLog::debug(SysUtil::lastErrorMessage());
                return false;
            }
            else
                return true;
        } finally {
            fclose($f);
            unlink($fname);
        }
    }

    static function supportSelectPipe() : bool
    {
        $fds = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );
        $cmdArgs = "ls";
        BayLog::debug("Spawn: %s", $cmdArgs);

        $process = proc_open($cmdArgs, $fds, $pips, null, null);
        if($process === false) {
            BayLog::warn(SysUtil::lastErrorMessage());
            return false;
        }

        $stdIn = $pips[0];

        try {
            $ra = [$stdIn];
            $wa = [];
            $ea = [];
            $n = stream_select($ra, $wa, $ea, 10);
            if ($n === false) {
                BayLog::debug(SysUtil::lastErrorMessage());
                return false;
            }
            else
                return true;
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

    }

    static function supportNonblockPipeRead() : bool
    {
        $fds = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );
        $cmdArgs = "ls";
        BayLog::debug("Spawn: %s", $cmdArgs);

        $process = proc_open($cmdArgs, $fds, $pips, null, null);
        if($process === false) {
            BayLog::warn(SysUtil::lastErrorMessage());
            return false;
        }

        $stdIn = $pips[0];

        try {
            $res = stream_set_blocking($stdIn, false);
            if ($res === false) {
                BayLog::debug(SysUtil::lastErrorMessage());
                return false;
            }
            else
                return true;
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

    }

    static function pid() : int
    {
        return getmypid();
    }

    static function processor_count(): int
    {
        return 4;
    }

    static function joinPath(string $dir, string ... $files) : string
    {
        $path = $dir;
        if(!StringUtil::endsWith($dir, "/"))
            $path .= DIRECTORY_SEPARATOR;

        foreach ($files as $i => $file) {
            if($i != 0)
                $path .= DIRECTORY_SEPARATOR;
            $path .= $file;
        }

        return $path;
    }

    static function lastErrorMessage() : string
    {
        $err = error_get_last();
        if($err === null)
            return "";
        else
            return "{$err["message"]} (at {$err["file"]}:{$err["line"]})";
    }

    static function lastSocketErrorMessage() : string
    {
        return socket_strerror(socket_last_error());
    }

    static function supportUnixDomainSocketAddress() : bool
    {
        return !self::runOnWindows();
    }

    static function supportParallel() : bool
    {
        if(self::$parallelSupported === null) {
            try {
                $runtime = new \paralle\Runtime();
                self::$parallelSupported = true;
            }
            catch(\Exception $e) {
                BayLog::warn_e($e, "Parallel not supported");
                self::$parallelSupported = false;
            }
        }

        return self::$parallelSupported;
    }
}
