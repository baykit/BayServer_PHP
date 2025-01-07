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

use baykit\bayserver\common\Groups;
use baykit\bayserver\util\Locale;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;
use function baykit\bayserver\docker\getMultiplexerType;
use function baykit\bayserver\docker\getMultiplexerTypeName;
use function baykit\bayserver\docker\getRecipientType;
use function baykit\bayserver\docker\getRecipientTypeName;

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
    const DEFAULT_NET_MULTIPLEXER = Harbor::MULTIPLEXER_TYPE_SPIDER;
    const DEFAULT_FILE_MULTIPLEXER = Harbor::MULTIPLEXER_TYPE_SPIDER;
    const DEFAULT_LOG_MULTIPLEXER = Harbor::MULTIPLEXER_TYPE_SPIDER;
    const DEFAULT_CGI_MULTIPLEXER = Harbor::MULTIPLEXER_TYPE_SPIDER;
    const DEFAULT_RECIPIENT = Harbor::RECIPIENT_TYPE_SPIDER;
    const DEFAULT_PID_FILE = "bayserver.pid";

    # Default charset
    private string $charset = self::DEFAULT_CHARSET;

    # Default locale
    private ?Locale $locale = null;

    # Number of ship agents
    private int $grandAgents = self::DEFAULT_GRAND_AGENTS;

    # Number of train runners
    private int $trainRunners = self::DEFAULT_TRAIN_RUNNERS;

    # Number of taxi runners
    private int $taxiRunners = self::DEFAULT_TAXI_RUNNERS;

    # Max count of ships
    private int $maxShips = self::DEFAULT_MAX_SHIPS;

    # Socket timeout in seconds
    private int $socketTimeoutSec = self::DEFAULT_WAIT_TIMEOUT_SEC;

    # Keep-Alive timeout in seconds
    private int $keepTimeoutSec = self::DEFAULT_KEEP_TIMEOUT_SEC;

    # Internal buffer size of Tour
    private int $tourBufferSize = self::DEFAULT_TOUR_BUFFER_SIZE;

    # Trace req/res header flag
    private bool $traceHeader = self::DEFAULT_TRACE_HEADER;

    # Trouble docker
    private ?Trouble $trouble = null;

    # Auth groups
    private ?Groups $groups = null;

    # File name to redirect stdout/stderr
    private ?string $redirectFile = null;

    # Gzip compression flag
    private bool $gzipComp = self::DEFAULT_GZIP_COMP;

    # Port number of signal agent
    private int $controlPort = self::DEFAULT_CONTROL_PORT;

    # Multi core flag
    private bool $multiCore = self::DEFAULT_MULTI_CORE;

    # Multiplexer type of network I/O
    private int $netMultiplexer = self::DEFAULT_NET_MULTIPLEXER;

    # Multiplexer type of file read
    private int $fileMultiplexer = self::DEFAULT_FILE_MULTIPLEXER;

    # Multiplexer type of log output
    private int $logMultiplexer = self::DEFAULT_LOG_MULTIPLEXER;

    # Multiplexer type of CGI input
    private int $cgiMultiplexer = self::DEFAULT_CGI_MULTIPLEXER;

    # Recipient type
    private int $recipient = self::DEFAULT_RECIPIENT;


    # PID file name
    private $pidFile = self::DEFAULT_PID_FILE;

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

        if ($this->netMultiplexer != Harbor::MULTIPLEXER_TYPE_SPIDER) {
            BayLog::warn(
                BayMessage::get(
                    Symbol::CFG_NET_MULTIPLEXER_NOT_SUPPORTED,
                    getMultiplexerTypeName($this->netMultiplexer),
                    getMultiplexerTypeName(self::DEFAULT_NET_MULTIPLEXER)));
            $this->netMultiplexer = self::DEFAULT_NET_MULTIPLEXER;
        }

        if ($this->fileMultiplexer == Harbor::MULTIPLEXER_TYPE_SPIN and !SysUtil::supportNonblockFileRead() ||
            $this->fileMultiplexer != Harbor::MULTIPLEXER_TYPE_SPIDER) {
            BayLog::warn(
                BayMessage::get(
                    Symbol::CFG_FILE_MULTIPLEXER_NOT_SUPPORTED,
                    getMultiplexerTypeName($this->fileMultiplexer),
                    getMultiplexerTypeName(self::DEFAULT_FILE_MULTIPLEXER)));
            $this->fileMultiplexer = self::DEFAULT_FILE_MULTIPLEXER;
        }

        if ($this->logMultiplexer != Harbor::MULTIPLEXER_TYPE_SPIDER) {
            BayLog::warn(
                BayMessage::get(
                    Symbol::CFG_LOG_MULTIPLEXER_NOT_SUPPORTED,
                    getMultiplexerTypeName($this->logMultiplexer),
                    getMultiplexerTypeName(self::DEFAULT_LOG_MULTIPLEXER)));
            $this->logMultiplexer = self::DEFAULT_LOG_MULTIPLEXER;
        }

        if ($this->logMultiplexer != Harbor::MULTIPLEXER_TYPE_SPIDER) {
            BayLog::warn(
                BayMessage::get(
                    Symbol::CFG_LOG_MULTIPLEXER_NOT_SUPPORTED,
                    getMultiplexerTypeName($this->logMultiplexer),
                    getMultiplexerTypeName(self::DEFAULT_LOG_MULTIPLEXER)));
            $this->logMultiplexer = self::DEFAULT_LOG_MULTIPLEXER;
        }

        if (($this->cgiMultiplexer == Harbor::MULTIPLEXER_TYPE_SPIN and !SysUtil::supportNonblockFileRead()) ||
            $this->cgiMultiplexer != Harbor::MULTIPLEXER_TYPE_SPIDER) {
            BayLog::warn(
                BayMessage::get(
                    Symbol::CFG_CGI_MULTIPLEXER_NOT_SUPPORTED,
                    getMultiplexerTypeName($this->cgiMultiplexer),
                    getMultiplexerTypeName(self::DEFAULT_CGI_MULTIPLEXER)));
            $this->cgiMultiplexer = self::DEFAULT_CGI_MULTIPLEXER;
        }

        if ($this->netMultiplexer == Harbor::MULTIPLEXER_TYPE_SPIDER &&
            $this->recipient != Harbor::RECIPIENT_TYPE_SPIDER) {
            BayLog::warn(
                BayMessage::get(
                    Symbol::CFG_NET_MULTIPLEXER_DOES_NOT_SUPPORT_THIS_RECIPIENT,
                    getMultiplexerTypeName($this->netMultiplexer),
                    getRecipientTypeName($this->recipient),
                    getRecipientTypeName(Harbor::RECIPIENT_TYPE_SPIDER)));
            $this->recipient = Harbor::RECIPIENT_TYPE_SPIDER;
        }

        if (!$this->multiCore && $this->grandAgents > 1) {
            BayLog::warn("This platform does not support multi threading. So set grantAgents to 1");
            $this->grandAgents = 1;
        }
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////

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
            case "netmultiplexer":
                try {
                    $this->netMultiplexer = getMultiplexerType(strtolower($kv->value));
                }
                catch(\InvalidArgumentException $e) {
                    BayLog::error_e($e);
                    throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, kv.value));
                }
                break;
            case "filemultiplexer":
                try {
                    $this->fileMultiplexer = getMultiplexerType(strtolower($kv->value));
                }
                catch(\InvalidArgumentException $e) {
                    BayLog::error_e($e);
                    throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, kv.value));
                }
                break;
            case "logmultiplexer":
                try {
                    $this->logMultiplexer = getMultiplexerType(strtolower($kv->value));
                }
                catch(\InvalidArgumentException $e) {
                    BayLog::error_e($e);
                    throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, kv.value));
                }
                break;
            case "cgimultiplexer":
                try {
                    $this->cgiMultiplexer = getMultiplexerType(strtolower($kv->value));
                }
                catch(\InvalidArgumentException $e) {
                    BayLog::error_e($e);
                    throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, kv.value));
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

    //////////////////////////////////////////////////////
    // Implements Harbor
    //////////////////////////////////////////////////////

    /** Default charset */
    public function charset() : string
    {
        return $this->charset;
    }

    /** Default locale */
    public function locale(): Locale
    {
        return $this->locale;
    }

    /** Number of grand agents */
    public function grandAgents(): int
    {
        return $this->grandAgents;
    }

    /** Number of train runners */
    public function trainRunners(): int
    {
        return $this->trainRunners;
    }

    /** Number of taxi runners */
    public function taxiRunners(): int
    {
        return $this->taxiRunners;
    }

    /** Max count of ships */
    public function maxShips(): int
    {
        return $this->maxShips;
    }

    /** Trouble docker */
    public function trouble(): ?Trouble
    {
        return $this->trouble;
    }

    /** Socket timeout in seconds */
    public function socketTimeoutSec(): int
    {
        return $this->socketTimeoutSec;
    }

    /** Keep-Alive timeout in seconds */
    public function keepTimeoutSec(): int
    {
        return $this->keepTimeoutSec;
    }

    /** Trace req/res header flag */
    public function traceHeader(): bool
    {
        return $this->traceHeader;
    }

    /** Internal buffer size of Tour */
    public function tourBufferSize(): int
    {
        return $this->tourBufferSize;
    }

    /** File name to redirect stdout/stderr */
    public function redirectFile(): ?string
    {
        return $this->redirectFile;
    }

    /** Port number of signal agent */
    public function controlPort(): int
    {
        return $this->controlPort;
    }

    /** Gzip compression flag */
    public function gzipComp(): bool
    {
        return $this->gzipComp;
    }

    /** Multiplexer of Network I/O */
    public function netMultiplexer(): int
    {
        return $this->netMultiplexer;
    }

    /** Multiplexer of File I/O */
    public function fileMultiplexer(): int
    {
        return $this->fileMultiplexer;
    }

    /** Multiplexer of Log output */
    public function logMultiplexer(): int
    {
        return $this->logMultiplexer;
    }

    /** Multiplexer of CGI input */
    public function cgiMultiplexer(): int
    {
        return $this->cgiMultiplexer;
    }

    /** Recipient */
    public function recipient(): int
    {
        return $this->recipient;
    }

    /** PID file name */
    public function pidFile(): string
    {
        return $this->pidFile;
    }

    /** Multi core flag */
    public function multiCore(): bool
    {
        return $this->multiCore;
    }

}