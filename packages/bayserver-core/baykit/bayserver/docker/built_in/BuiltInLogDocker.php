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
use baykit\bayserver\common\RudderState;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\DockerBase;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\docker\Log;
use baykit\bayserver\rudder\StreamRudder;
use baykit\bayserver\Sink;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\SysUtil;


class BuiltInLogDocker_AgentListener implements LifecycleListener {

    private $logDocker;

    public function __construct(BuiltInLogDocker $logDocker)
    {
        $this->logDocker = $logDocker;
    }

    public function add(int $agentId) : void
    {
        $agt = GrandAgent::get($agentId);

        $fileName = $this->logDocker->filePrefix . "_" . $agentId . "." . $this->logDocker->fileExt;
        if(file_exists($fileName))
            $size = filesize($fileName);
        else
            $size = 0;

        $outFile = fopen($fileName, "ab");
        BayLog::debug("file open: %s res=%s", $fileName, $outFile);
        $rd = new StreamRudder($outFile);
        if(BayServer::$harbor->logMultiplexer() == Harbor::MULTIPLEXER_TYPE_SPIDER) {
            $mpx = $agt->spiderMultiplexer;
        }
        else {
            throw new \Exception("Multiplexer not supported");
        }

        $st = new RudderState($rd);
        $st->bytesWrote = $size;
        $mpx->addRudderState($rd, $st);

        $this->logDocker->multiplexers[$agentId] = $mpx;
        $this->logDocker->rudders[$agentId] = $rd;
    }

    public function remove(int $agentId) : void
    {
        $rd = $this->logDocker->rudders[$agentId];
        $this->logDocker->multiplexers[$agentId] = null;
        $this->logDocker->rudders[$agentId] = null;
    }
}



class BuiltInLogDocker extends DockerBase implements Log
{
    /** Mapping table for format */
    public static $map = [];

    /** Log file name parts */
    public string $filePrefix;
    public string $fileExt;

    /**
     *  Logger for each agent.
     *  Map of Agent ID => LogBoat
     */
    public $loggers = [];

    /** Log format */
    public string $format;

    /** Log items */
    public $logItems = [];

    public $rudders = [];

    /** Multiplexer to write to file */
    public $multiplexers = [];

    public static $lineSep = PHP_EOL;

    public static function initClass()
    {
        new LogItems();   // To load LogItems.php

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

        GrandAgent::addLifecycleListener(new BuiltInLogDocker_AgentListener($this));
    }

    public function initKeyVal(BcfKeyVal $kv) : bool {
        switch (strtolower($kv->key)) {
            default:
                return false;

            case "format":
                $this->format = $kv->value;
                break;
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
            $this->multiplexers[$tour->ship->agentId]->reqWrite(
                $this->rudders[$tour->ship->agentId],
                $sb,
                null,
                "Log"
            );
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