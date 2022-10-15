<?php

namespace baykit\bayserver\docker\cgi;

use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\agent\transporter\SpinReadTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\ClubBase;
use baykit\bayserver\docker\base\SecurePort;
use baykit\bayserver\docker\base\SecurePortDockerHelper;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\docker\http\h1\H1InboundHandler_InboundProtocolHandlerFactory;
use baykit\bayserver\HttpException;
use baykit\bayserver\Sink;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\CGIUtil;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;

class CGIDocker extends ClubBase
{

    const DEFAULT_PROC_READ_METHOD = Harbor::FILE_SEND_METHOD_SELECT;
    const DEFAULT_TIMEOUT_SEC = 60;

    public $interpreter;
    public $scriptBase;
    public $docRoot;
    public $timeoutSec = self::DEFAULT_TIMEOUT_SEC;

    /** Method to read stdin/stderr */
    public $procReadMethod = self::DEFAULT_PROC_READ_METHOD;

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////
    public function init(BcfElement $elm, ?Docker $parent): void
    {
        parent::init($elm, $parent);

        if($this->procReadMethod == Harbor::FILE_SEND_METHOD_SELECT && !SysUtil::supportSelectPipe()) {
            BayLog::warn(ConfigException::createMessage(CGIMessage::get(CGISymbol::CGI_PROC_READ_METHOD_SELECT_NOT_SUPPORTED), $elm->fileName, $elm->lineNo));
            $this->procReadMethod = Harbor::FILE_SEND_METHOD_TAXI;
        }

        if($this->procReadMethod == Harbor::FILE_SEND_METHOD_SPIN && !SysUtil::supportNonblockPipeRead()) {
            BayLog::warn(ConfigException::createMessage(CGIMessage::get(CGISymbol::CGI_PROC_READ_METHOD_SPIN_NOT_SUPPORTED), $elm->fileName, $elm->lineNo));
            $this->procReadMethod = Harbor::FILE_SEND_METHOD_TAXI;
        }
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
        if (BayServer::$harbor->traceHeader) {
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

        $outYat = new CGIStdOutYacht();
        $errYat = new CGIStdErrYacht();

        switch($this->procReadMethod) {
            case Harbor::FILE_SEND_METHOD_SELECT: {
                stream_set_blocking($handler->stdOut, false);
                stream_set_blocking($handler->stdErr, false);

                $outTp = new PlainTransporter(false, $bufsize);
                $outYat->init($tur, $outTp);
                $outTp->init($tur->ship->agent->nonBlockingHandler, $handler->stdOut, $outYat);
                $outTp->openValve();

                $errTp = new PlainTransporter(false, $bufsize);
                $errYat->init($tur);
                $errTp->init($tur->ship->agent->nonBlockingHandler, $handler->stdErr, $errYat);
                $errTp->openValve();
                break;
            }
            case Harbor::FILE_SEND_METHOD_SPIN: {
                stream_set_blocking($handler->stdOut, false);
                stream_set_blocking($handler->stdErr, false);

                $outTp = new SpinReadTransporter($bufsize);
                $outYat->init($tur, $outTp);
                $outTp->init($tur->ship->agent->spinHandler, $outYat, $handler->stdOut, -1, $this->timeoutSec, nil);
                $outTp->openValve();

                $errTp = new SpinReadTransporter($bufsize);
                $errYat->init($tur);
                $errTp->init($tur->ship->agent->spinHandler, $errYat, $handler->stdErr, -1, $this->timeoutSec, nil);
                $errTp->openValve();
                break;
            }

            case Harbor::FILE_SEND_METHOD_TAXI:
                throw new Sink();
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

