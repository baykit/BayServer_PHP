<?php
namespace baykit\bayserver\docker\built_in;

use baykit\bayserver\BayServer;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\ConfigException;
use baykit\bayserver\Symbol;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Trouble;
use baykit\bayserver\docker\base\DockerBase;

use baykit\bayserver\util\Groups;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;

class BuiltInHarborDocker extends DockerBase implements Harbor
{
    const DEFAULT_MAX_SHIPS = 100;
    const DEFAULT_GRAND_AGENTS = 0;
    const DEFAULT_TRAIN_RUNNERS = 8;
    const DEFAULT_TAXI_RUNNERS = 8;
    const DEFAULT_WAIT_TIMEOUT_SEC = 120;
    const DEFAULT_KEEP_TIMEOUT_SEC = 20;
    const DEFAULT_TOUR_BUFFER_SIZE = 1024 * 1024;  # 1M
    const DEFAULT_TRACE_HEADER = False;
    const DEFAULT_CHARSET = "UTF-8";
    const DEFAULT_CONTROL_PORT = -1;
    const DEFAULT_MULTI_CORE = True;
    const DEFAULT_GZIP_COMP = False;
    const DEFAULT_FILE_SEND_METHOD = Harbor::FILE_SEND_METHOD_SELECT;
    const DEFAULT_PID_FILE = "bayserver.pid";

    # Default charset
    public $charset = self::DEFAULT_CHARSET;

    # Default locale
    public $locale = null;

    # Number of ship agents
    public $grandAgents = self::DEFAULT_GRAND_AGENTS;

    # Number of train runners
    public $trainRunners = self::DEFAULT_TRAIN_RUNNERS;

    # Number of taxi runners
    public $taxiRunners = self::DEFAULT_TAXI_RUNNERS;

    # Max count of ships
    public $maxShips = self::DEFAULT_MAX_SHIPS;

    # Socket timeout in seconds
    public $socketTimeoutSec = self::DEFAULT_WAIT_TIMEOUT_SEC;

    # Keep-Alive timeout in seconds
    public $keepTimeoutSec = self::DEFAULT_KEEP_TIMEOUT_SEC;

    # Internal buffer size of Tour
    public $tourBufferSize = self::DEFAULT_TOUR_BUFFER_SIZE;

    # Trace req/res header flag
    public $traceHeader = self::DEFAULT_TRACE_HEADER;

    # Trouble docker
    public $trouble = null;

    # Auth groups
    public $groups = null;

    # File name to redirect stdout/stderr
    public $redirectFile = null;

    # Gzip compression flag
    public $gzipComp = self::DEFAULT_GZIP_COMP;

    # Port number of signal agent
    public $controlPort = self::DEFAULT_CONTROL_PORT;

    # Multi core flag
    public $multiCore = self::DEFAULT_MULTI_CORE;

    # Method to send file
    public $fileSendMethod = self::DEFAULT_FILE_SEND_METHOD;

    # PID file name
    public $pidFile = self::DEFAULT_PID_FILE;

    public function __construct()
    {
        $this->groups = new Groups();
    }

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////
    ///
    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        parent::init($elm, $parent);

        if ($this->grandAgents <= 0)
            $this->grandAgents = SysUtil::processor_count();

        if ($this->trainRunners <= 0)
            $this->trainRunners = 1;

        if ($this->maxShips <= 0)
            $this->maxShips = self::DEFAULT_MAX_SHIPS;
        if ($this->maxShips < self::DEFAULT_MAX_SHIPS) {
            $this->maxShips = self::DEFAULT_MAX_SHIPS;
            BayLog::warn(BayMessage::get(Symbol::CFG_MAX_SHIPS_IS_TO_SMALL, $this->maxShips));
        }

        if ($this->multiCore and !SysUtil::supportFork()) {
            BayLog::warn(BayMessage::get(Symbol::CFG_MULTI_CORE_NOT_SUPPORTED));
            $this->multiCore = false;
        }

        if ($this->fileSendMethod == Harbor::FILE_SEND_METHOD_SELECT and !SysUtil::supportSelectFile()) {
            BayLog::warn(BayMessage::get(Symbol::CFG_FILE_SEND_METHOD_SELECT_NOT_SUPPORTED));
            $this->fileSendMethod = Harbor::FILE_SEND_METHOD_SPIN;
        }

        if ($this->fileSendMethod == Harbor::FILE_SEND_METHOD_SPIN and !SysUtil::supportNonblockFileRead()) {
            BayLog::warn(BayMessage::get(Symbol::CFG_FILE_SEND_METHOD_SPIN_NOT_SUPPORTED));
            $this->fileSendMethod = Harbor::FILE_SEND_METHOD_TAXI;
        }

        if ($this->fileSendMethod == Harbor::FILE_SEND_METHOD_TAXI) {
            throw new ConfigException($elm->fileName, $elm->lineNo, "Taxi not supported");
        }

        if (!$this->multiCore && $this->grandAgents > 1) {
            BayLog::warn("This platform does not support multi threading. So set grantAgents to 1");
            $this->grandAgents = 1;
        }
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////
    ///
    public function initDocker(Docker $dkr) : bool
    {
        if ($dkr instanceof Trouble)
            $this->trouble = $dkr;
        else
            return parent::initDocker($dkr);
    }

    public function initKeyVal(BcfKeyVal $kv) : bool
    {
        $key = strtolower($kv->key);
        switch($key) {
            case  "loglevel":
                BayLog::set_log_level($kv->value);
                break;
            case "charset":
                $this->charset = $kv->value;
                break;
            case "locale":
                $this->locale = $kv->value;
                break;
            case "groups":
                $fname = BayServer::parsePath($kv->value);
                if (!$fname)
                    throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_FILE_NOT_FOUND, $kv->value));
                else
                    $this->groups->init($fname);
                break;
            case "trains":
                $this->trainRunners = intval($kv->value);
                break;
            case "taxis":
            case "taxies":
                $this->taxiRunners = intval($kv->value);
                break;
            case "grandagents":
                $this->grandAgents = intval($kv->value);
                break;
            case "maxships":
                $this->maxShips = intval($kv->value);
                break;
            case "timeout":
                $this->socketTimeoutSec = intval($kv->value);
                break;
            case "keeptimeout":
                $this->keepTimeoutSec = intval($kv->value);
                break;
            case "tourbuffersize":
                $this->tourBufferSize = StringUtil::parseSize($kv->value);
                break;
            case "traceheader":
                $this->traceHeader = StringUtil::parseBool($kv->value);
                break;
            case "redirectfile":
                $this->redirectFile = $kv->value;
                break;
            case "controlport":
                $this->controlPort = intval($kv->value);
                break;
            case "multicore":
                $this->multiCore = StringUtil::parseBool($kv->value);
                break;
            case "gzipcomp":
                $this->gzipComp = StringUtil::parseBool($kv->value);
                break;
            case "sendfilemethod":
                $v = strtolower($kv->value);
                switch ($v) {
                    case "select":
                        $this->fileSendMethod = Harbor::FILE_SEND_METHOD_SELECT;
                        break;
                    case "spin":
                        $this->fileSendMethod = Harbor::FILE_SEND_METHOD_SPIN;
                        break;
                    case "taxi":
                        $this->fileSendMethod = Harbor::FILE_SEND_METHOD_TAXI;
                        breka;
                    default:
                        throw new ConfigException($kv->fileName, $kv->lineNo,
                            BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, $kv->value));
                }
                break;
            case "pidfile":
                $this->pidFile = $kv->value;
                break;
            default:
                return false;
        }

        return true;
    }


}