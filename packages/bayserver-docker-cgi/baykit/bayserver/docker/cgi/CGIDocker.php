<?php

namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\ClubBase;
use baykit\bayserver\docker\base\SecurePort;
use baykit\bayserver\docker\base\SecurePortDockerHelper;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\docker\http\h1\H1InboundHandler_InboundProtocolHandlerFactory;
use baykit\bayserver\HttpException;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\Sink;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\CGIUtil;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;

class CGIDocker extends ClubBase
{
    const DEFAULT_TIMEOUT_SEC = 0;

    public ?string $interpreter = null;
    public ?string $scriptBase = null;
    public ?string $docRoot = null;
    public int $timeoutSec = self::DEFAULT_TIMEOUT_SEC;
    private int $maxProcesses = 0;
    private int $processCount = 0;
    private int $waitCount = 0;
    /** Method to read stdin/stderr */

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////
    public function init(BcfElement $elm, ?Docker $parent): void
    {
        parent::init($elm, $parent);
    }



    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

    public function initKeyVal($kv): bool
    {
        switch (strtolower($kv->key)) {
            default:
                return parent::initKeyVal($kv);

            case "interpreter":
                $this->interpreter = $kv->value;
                break;

            case "scriptase":
                $this->scriptBase = $kv->value;
                break;

            case "docroot":
                $this->docRoot = $kv->value;
                break;

            case "timeout":
                $this->timeoutSec = intval($kv->value);
                break;

            case "maxprocesses":
                $this->maxProcesses = intval($kv->value);
                break;
        }
        return true;
    }


    //////////////////////////////////////////////////////
    // Implements Club
    //////////////////////////////////////////////////////

    public function arrive(Tour $tur) : void
    {
        if (strpos($tur->req->uri, "..") !== false) {
            throw new HttpException(HttpStatus::FORBIDDEN, $tur->req->uri);
        }

        $base = $this->scriptBase;
        if($base === null)
            $base = $tur->town->location;

        if(StringUtil::isEmpty($base)) {
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $tur->town . " scriptBase of cgi docker or location of town is not specified.");
        }

        $root = $this->docRoot;
        if($root === null)
            $root = $tur->town->location;

        if(StringUtil::isEmpty($root)) {
            throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $tur->town . " docRoot of cgi docker or location of town is not specified.");
        }

        $env = CGIUtil::getEnvHash($tur->town->name, $root, $base, $tur);
        if (BayServer::$harbor->traceHeader()) {
            foreach ($env as $name => $value) {
                BayLog::info("%s cgi: env: %s=%s", $tur, $name, $value);
            }
        }

        $fileName = $env[CGIUtil::SCRIPT_FILENAME];
        if (!is_file($fileName)) {
            throw new HttpException(HttpStatus::NOT_FOUND, $fileName);
        }

        $handler = new CGIReqContentHandler($this, $tur, $env);
        $tur->req->setContentHandler($handler);
        $handler->reqStartTour();

    }

    //////////////////////////////////////////////////////
    // Other Methods
    //////////////////////////////////////////////////////

    public function getWaitCount(): int
    {
        return $this->waitCount;
    }

    public function addProcessCount(): bool
    {
        if($this->maxProcesses <= 0 || $this->processCount < $this->maxProcesses) {
            $this->processCount += 1;
            BayLog::debug("%s Process count: %d", $this, $this->processCount);
            return true;
        }

        $this->waitCount += 1;
        return false;
    }

    public function subProcessCount()
    {
        $this->processCount -= 1;
    }

    public function subWaitCount()
    {
        $this->waitCount -= 1;
    }

    public function createCommand(array &$env) : string
    {
        $script = $env[CGIUtil::SCRIPT_FILENAME];
        if($this->interpreter === null) {
            $command = $script;
        }
        else
            $command = $this->interpreter . " " . $script;

        return $command;
    }
}

