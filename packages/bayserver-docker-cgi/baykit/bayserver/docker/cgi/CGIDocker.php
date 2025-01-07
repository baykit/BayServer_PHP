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

    public $interpreter;
    public $scriptBase;
    public $docRoot;
    public $timeoutSec = self::DEFAULT_TIMEOUT_SEC;

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

            case "processreadmethod":
                $v = strtolower($kv->value);
                switch ($v) {
                    case "select":
                        $this->procReadMethod = Harbor::FILE_SEND_METHOD_SELECT;
                        break;
                    case "spin":
                        $this->procReadMethod = Harbor::FILE_SEND_METHOD_SPIN;
                        break;
                    case "taxi":
                        $this->procReadMethod = Harbor::FILE_SEND_METHOD_TAXI;
                        break;
                    default:
                        throw new ConfigException($kv->fileName, $kv->lineNo,
                            BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, $kv->value));
                }
                break;

            case "timeout":
                $this->timeoutSec = intval($kv->value);
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

        $bufsize = $tur->ship->protocolHandler->maxResPacketDataSize();
        $handler = new CGIReqContentHandler($this, $tur);

        $tur->req->setContentHandler($handler);
        $handler->startTour($env);
        $fname = "cgi#" . (string)$handler->pid;

        $agt = GrandAgent::get($tur->ship->agentId);

        switch(BayServer::$harbor->cgiMultiplexer()) {
            case Harbor::MULTIPLEXER_TYPE_SPIN: {
                throw new Sink();
            }

            case Harbor::MULTIPLEXER_TYPE_SPIDER: {
                stream_set_blocking($handler->stdOutRd->key(), false);
                if($handler->stdErrRd != null)
                    stream_set_blocking($handler->stdErrRd->key(), false);

                $mpx = $agt->spiderMultiplexer;
                break;
            }

            default:
                throw new IOException("Multiplexer not supported: %d", BayServer::$harbor->cgiMultiplexer());
        }

        $outShip = new CGIStdOutShip();
        $outTp = new PlainTransporter($mpx, $outShip, false, $bufsize, false);
        $outTp->init();
        $outShip->initOutShip($handler->stdOutRd, $tur->ship->agentId, $tur, $outTp, $handler);

        $mpx->addRudderState($handler->stdOutRd, new RudderState($handler->stdOutRd, $outTp));

        $sid = $tur->ship->shipId;
        $tur->res->setConsumeListener(function ($len, $resume) use ($outShip, $sid){
            if($resume)
                $outShip->resumeRead($sid);
        });

        $mpx->reqRead($handler->stdOutRd);

        if($handler->stdErrRd != null) {
            $errShip = new CGIStdErrShip();
            $errTp = new PlainTransporter($mpx, $errShip, false, $bufsize, false);
            $errTp->init();
            $errShip->initErrShip($handler->stdErrRd, $tur->ship->agentId, $handler);

            $mpx->addRudderState($handler->stdErrRd, new RudderState($handler->stdErrRd, $errTp));
            $mpx->reqRead($handler->stdErrRd);
        }
    }

    //////////////////////////////////////////////////////
    // Other Methods
    //////////////////////////////////////////////////////

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

