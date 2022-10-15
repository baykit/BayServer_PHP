<?php

namespace baykit\bayserver\docker\built_in;




use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\LifecycleListener;
use baykit\bayserver\agent\transporter\PlainTransporter;
use baykit\bayserver\agent\transporter\SpinWriteTransporter;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\DockerBase;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Log;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\SysUtil;

include "baykit/bayserver/docker/built_in/LogItems.php";

class BuiltInLogDocker_AgentListener implements LifecycleListener {

    private $logDocker;

    public function __construct(BuiltInLogDocker $logDocker)
    {
        $this->logDocker = $logDocker;
    }

    public function add(int $agentId) : void
    {
        $fileName = $this->logDocker->filePrefix . "_" . $agentId . "." . $this->logDocker->fileExt;
        $boat = new LogBoat();

        $outFile = fopen($fileName, "ab");
        BayLog::debug("file open: %s res=%s", $fileName, $outFile);
        if($this->logDocker->logWriteMethod == BuiltInLogDocker::LOG_WRITE_METHOD_SELECT) {
            $tp = new PlainTransporter(False, 0, true);  # write only
            $tp->init(GrandAgent::get($agentId)->nonBlockingHandler, $outFile, $boat);
        }
        elseif($this->logDocker->logWriteMethod == BuiltInLogDocker::LOG_WRITE_METHOD_SPIN) {
            $tp = new SpinWriteTransporter();
            $tp->init(GrandAgent::get($agentId)->spinHandler, $outFile, $boat);
        }
        else {
            throw new \Exception("Taxi not supported");
        }

        try {
            $boat->initBoat($fileName, $tp);
        }
        catch(\Exception $e) {
            BayLog::fatal(BayMessage::get(Symbol::INT_CANNOT_OPEN_LOG_FILE, $fileName));
            BayLog::fatal($e);
        }
        $this->logDocker->loggers[$agentId] = $boat;
    }

    public function remove(int $agentId) : void
    {
        unset($this->logDocker->loggers[$agentId]);
    }
}



class BuiltInLogDocker extends DockerBase implements Log
{
    const LOG_WRITE_METHOD_SELECT = 1;
    const LOG_WRITE_METHOD_SPIN = 2;
    const LOG_WRITE_METHOD_TAXI = 3;
    const DEFAULT_LOG_WRITE_METHOD = BuiltInLogDocker::LOG_WRITE_METHOD_SELECT;

    /** Mapping table for format */
    public static $map = [];

    /** Log file name parts */
    public $filePrefix;
    public $fileExt;

    /**
     *  Logger for each agent.
     *  Map of Agent ID => LogBoat
     */
    public $loggers = [];

    /** Log format */
    public $format;

    /** Log items */
    public $logItems = [];

    /** Log write method */
    public $logWriteMethod = BuiltInLogDocker::DEFAULT_LOG_WRITE_METHOD;

    public static $lineSep = PHP_EOL;

    public static function initClass()
    {
        // Create mapping table
        BuiltInLogDocker::$map["a"] = RemoteIpItem::$factory;
        BuiltInLogDocker::$map["A"] = ServerIpItem::$factory;
        BuiltInLogDocker::$map["b"] = RequestBytesItem1::$factory;
        BuiltInLogDocker::$map["B"] = RequestBytesItem2::$factory;
        BuiltInLogDocker::$map["c"] = ConnectionStatusItem::$factory;
        BuiltInLogDocker::$map["e"] = NullItem::$factory;
        BuiltInLogDocker::$map["h"] = RemoteHostItem::$factory;
        BuiltInLogDocker::$map["H"] = ProtocolItem::$factory;
        BuiltInLogDocker::$map["i"] = RequestHeaderItem::$factory;
        BuiltInLogDocker::$map["l"] = RemoteLogItem::$factory;
        BuiltInLogDocker::$map["m"] = MethodItem::$factory;
        BuiltInLogDocker::$map["n"] = NullItem::$factory;
        BuiltInLogDocker::$map["o"] = ResponseHeaderItem::$factory;
        BuiltInLogDocker::$map["p"] = PortItem::$factory;
        BuiltInLogDocker::$map["P"] = NullItem::$factory;
        BuiltInLogDocker::$map["q"] = QueryStringItem::$factory;
        BuiltInLogDocker::$map["r"] = StartLineItem::$factory;
        BuiltInLogDocker::$map["s"] = StatusItem::$factory;
        BuiltInLogDocker::$map[">s"] = StatusItem::$factory;
        BuiltInLogDocker::$map["t"] = TimeItem::$factory;
        BuiltInLogDocker::$map["T"] = IntervalItem::$factory;
        BuiltInLogDocker::$map["u"] = RemoteUserItem::$factory;
        BuiltInLogDocker::$map["U"] = RequestUrlItem::$factory;
        BuiltInLogDocker::$map["v"] = ServerNameItem::$factory;
        BuiltInLogDocker::$map["V"] = NullItem::$factory;
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////
    // Implements DockerBase
    ///////////////////////////////////////////////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        parent::init($elm, $parent);
        $p = strpos($elm->arg, '.');
        if($p == -1) {
            $this->filePrefix = $elm->arg;
            $this->fileExt = "";
        }
        else {
            $this->filePrefix = substr($elm->arg, 0, $p);
            $this->fileExt = substr($elm->arg, $p + 1);
        }

        if($this->format == null) {
            throw new ConfigException(
                $elm->fileName,
                $elm->lineNo,
                BayMessage::get(
                    Symbol::CFG_INVALID_LOG_FORMAT,
                    ""));
        }

        if(!SysUtil::isAbsolutePath($this->filePrefix))
            $this->filePrefix = SysUtil::joinPath(BayServer::$bservHome, $this->filePrefix);

        $logDir = dirname($this->filePrefix);
        if(!is_dir($logDir))
            mkdir($logDir);

        // Parse format
        $this->compile($this->format, $this->logItems, $elm->fileName, $elm->lineNo);

        // Check log write method
        if($this->logWriteMethod == self::LOG_WRITE_METHOD_SELECT && !SysUtil::supportSelectFile()) {
            BayLog::warn(BayMessage::get(Symbol::CFG_LOG_WRITE_METHOD_SELECT_NOT_SUPPORTED));
            $this->logWriteMethod = self::LOG_WRITE_METHOD_TAXI;
        }

        if($this->logWriteMethod == self::LOG_WRITE_METHOD_SPIN && !SysUtil::supportNonblockFileWrite()) {
            BayLog::warn(BayMessage::get(Symbol::CFG_LOG_WRITE_METHOD_SPIN_NOT_SUPPORTED));
            $this->logWriteMethod = self::LOG_WRITE_METHOD_TAXI;
        }

        GrandAgent::addLifecycleListener(new BuiltInLogDocker_AgentListener($this));
    }

    public function initKeyVal(BcfKeyVal $kv) : bool {
        switch (strtolower($kv->key)) {
            default:
                return false;

            case "format":
                $this->format = $kv->value;
                break;

            case "logwritemethod":
                switch(strtolower($kv->value)) {
                    case "select":
                        $this->logWriteMethod = self::LOG_WRITE_METHOD_SELECT;
                        break;
                   case "spin":
                        $this->logWriteMethod = self::LOG_WRITE_METHOD_SPIN;
                        break;
                    case "taxi":
                        $this->logWriteMethod = self::LOG_WRITE_METHOD_TAXI;
                        break;
                    default:
                        throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, $kv->value));
                }
        }
        return true;
    }

    ///////////////////////////////////////////////////////////////////////////////
    //  Implements Log                                                           //
    ///////////////////////////////////////////////////////////////////////////////
    public function log(Tour $tour) : void
    {
        $sb = "";
        foreach ($this->logItems as $logItem) {
            $item = $logItem->getItem($tour);
            if ($item === null)
                $sb .= "-";
            else
                $sb .= $item;
        }

        // If threre are message to write, write it
        if (strlen($sb) > 0) {
            $this->getLogger($tour->ship->agent)->log($sb);
        }
    }

    ///////////////////////////////////////////////////////////////////////////////
    //  Custom methods                                                           //
    ///////////////////////////////////////////////////////////////////////////////

    ///////////////////////////////////////////////////////////////////////////////
    //  Private methods                                                          //
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Compile format pattern
     */
    private function compile(string $str, array &$items, string $fileName, int $lineNo) : void
    {
        // Find control code
        $pos = strpos($str, '%');
        if ($pos !== false) {
            $text = substr($str, 0, $pos);
            $items[] = new TextItem($text);
            $this->compileCtl(substr($str, $pos + 1), $items, $fileName, $lineNo);
        }
        else {
            $items[] = new TextItem($str);
        }
    }

    /**
     * Compile format pattern(Control code)
     */
    private function compileCtl(string $str, array &$items, string $fileName, int $lineNo): void
    {
        $param = null;

        // if exists param
        if ($str[0] == '{') {
            // find close bracket
            $pos = strpos($str, '}');
            if ($pos === false) {
                throw new ConfigException($fileName, $lineNo, BayMessage::CFG_INVALID_LOG_FORMAT($this->format));
            }
            $param = substr($str, 1, $pos - 1);
            $str = substr($str, $pos + 1);
        }

        $ctlChar = "";
        $error = false;

        if (strlen($str) == 0)
            $error = true;

        if (!$error) {
            // get control char
            $ctlChar = substr($str, 0, 1);
            $str = substr($str, 1);

            if ($ctlChar == ">") {
                if (strlen($str) == 0) {
                    $error = true;
                } else {
                    $ctlChar = substr($str, 0, 1);
                    $str = substr($str, 1);
                }
            }
        }

        $fct = null;
        if (!$error) {
            $fct = BuiltInLogDocker::$map[$ctlChar];
            if ($fct == null)
                $error = true;
        }

        if ($error) {
            throw new ConfigException(
                $fileName,
                $lineNo,
                BayMessage::get(Symbol::CFG_INVALID_LOG_FORMAT, $this->format . " (unknown control code: '%" . $ctlChar . "')"));
        }

        $item = $fct();
        $item->init($param);
        $items[] = $item;
        $this->compile($str, $items, $fileName, $lineNo);
    }

    private function getLogger(GrandAgent $agt) : LogBoat
    {
        return $this->loggers[$agt->agentId];
    }
}

BuiltInLogDocker::initClass();