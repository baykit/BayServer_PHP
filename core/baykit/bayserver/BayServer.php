<?php

namespace baykit\bayserver;

require_once 'baykit/bayserver/BayLog.php';

spl_autoload_register(function ($class_name) {

    $fileName = str_replace('\\', '/', $class_name) . ".php";
    //$fileName = strtolower(preg_replace('/([a-z])([A-Z])/', '\1_\2', $fileName)) . ".php";

    $success = include($fileName);
    if (!$success) {
        BayLog::error("Cannot load class: %s (file=%s)", $class_name, $fileName);
        throw new \Exception("Cannot load class: {$class_name}");
    }
});


use baykit\bayserver\agent\signal\SignalSender;
use baykit\bayserver\bcf\BcfParser;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\agent\GrandAgent;
use baykit\bayserver\agent\PortMap;
use baykit\bayserver\agent\signal\SignalAgent;
use baykit\bayserver\docker\base\InboundShipStore;
use baykit\bayserver\protocol\PacketStore;
use baykit\bayserver\protocol\ProtocolHandlerStore;
use baykit\bayserver\tour\TourStore;
use baykit\bayserver\util\Cities;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\MD5Password;
use baykit\bayserver\util\Selector;
use baykit\bayserver\util\SysUtil;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\Locale;
use baykit\bayserver\util\Mimes;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\docker\Harbor;
use baykit\bayserver\docker\Port;
use baykit\bayserver\docker\City;

class BayServer
{

    const ENV_BAYSERVER_HOME = "BSERV_HOME";
    const ENV_BAYSERVER_LIB = "BSERV_LIB";
    const ENV_BAYSERVER_PLAN = "BSERV_PLAN";

    # Host name
    public static $myHostName = NULL;

    # BSERV_HOME directory
    public static $bservHome = null;

    # Configuration file name (full path)
    public static $bservPlan = null;

    # Agent list
    public static $agentList = [];

    # Dockers
    public static $dockers = null;

    # Port docker
    public static $portDockerList = [];

    # Harbor docker
    public static $harbor = null;

    # BayAgent
    public static $bayAgent = null;

    # City dockers
    public static $cities;

    # Software name
    public static $softwareName = null;

    public static function main($args)
    {
        $cmd = null;
        $home = getenv(BayServer::ENV_BAYSERVER_HOME);
        $plan = getenv(BayServer::ENV_BAYSERVER_PLAN);
        $mkpass = null;
        BayLog::set_full_path(SysUtil::runOnPhpStorm());

        //BayLog::info("arg1=%s", $args[0]);

        foreach ($args as $arg) {
            $arg = strtolower($arg);
            if ($arg == "-start") {
                $cmd = null;
            } elseif ($arg == "-stop" || $arg == "-shutdown") {
                $cmd = SignalAgent::COMMAND_SHUTDOWN;
            } elseif ($arg == "-restartagents") {
                $cmd = SignalAgent::COMMAND_RESTART_AGENTS;
            } elseif ($arg == "-reloadcert") {
                $cmd = SignalAgent::COMMAND_RELOAD_CERT;
            } elseif ($arg == "-memusage") {
                $cmd = SignalAgent::COMMAND_MEM_USAGE;
            } elseif ($arg == "-abort") {
                $cmd = SignalAgent::COMMAND_ABORT;
            } elseif (StringUtil::startsWith($arg, "-home=")) {
                $home = substr($arg, 6);
            } elseif (StringUtil::startsWith($arg, "-plan=")) {
                $plan = substr($arg, 6);
            } elseif (StringUtil::startsWith($arg, "-mkpass=")) {
                $mkpass = substr($arg, 8);
            } elseif (StringUtil::startsWith($arg, "-loglevel=")) {
                BayLog::set_log_level(substr($arg, 10));
            }
        }

        if ($mkpass !== null) {
            echo(MD5Password::encode($mkpass) . PHP_EOL);
            exit(0);
        }

        self::init($home, $plan);

        if ($cmd === null) {
            self::start();
        } else {
            (new SignalSender())->sendCommand($cmd);
        }
    }

    public static function init($home, $plan)
    {
        if ($home !== null && $home !== false) {
            BayServer::$bservHome = $home;
        } elseif (getenv(self::ENV_BAYSERVER_HOME) !== false) {
            BayServer::$bservHome = getenv(BayServer::ENV_BAYSERVER_HOME);
        } elseif (StringUtil::isEmpty(BayServer::$bservHome)) {
            BayServer::$bservHome = '.';
        }

        BayLog::info("BayServer Home: %s", BayServer::$bservHome);

        $bserv_lib = getenv(BayServer::ENV_BAYSERVER_LIB);
        if ($bserv_lib === null) {
            $bserv_lib = BayServer::$bservHome . "/lib";
        }

        if (is_dir($bserv_lib)) {
            $path = get_include_path();
            $dirs = glob($path . '*', GLOB_ONLYDIR);

            foreach ($dirs as $dir) {
                $path = $path . PATH_SEPARATOR . "$bserv_lib/$dir";
            }
            ini_set('include_path', $path);
        }

        // Get plan file
        if ($plan != "")
            self::$bservPlan = $plan;
        elseif (getenv(BayServer::ENV_BAYSERVER_PLAN) != null)
            self::$bservPlan = getenv(BayServer::ENV_BAYSERVER_PLAN);
        else
            self::$bservPlan = "plan/bayserver.plan";

        if (!SysUtil::isAbsolutePath(self::$bservPlan))
            self::$bservPlan = self::$bservHome . "/" . self::$bservPlan;

        BayLog::info("BayServer Plan: " . self::$bservPlan);
        if (!file_exists(self::$bservPlan))
            throw new BayException("Plan file is not a file: " . self::$bservPlan);
    }

    public static function start()
    {
        try {
            BayMessage::init(self::$bservHome . "/lib/conf/messages", new Locale('ja', 'JP'));

            self::$dockers = new BayDockers();

            self::$dockers->init(self::$bservHome . "/lib/conf/dockers.bcf");

            Mimes::init(self::$bservHome . "/lib/conf/mimes.bcf");
            HttpStatus::init(self::$bservHome . "/lib/conf/httpstatus.bcf");

            if (self::$bservPlan !== null)
                self::loadPlan(self::$bservPlan);

            if (count(self::$portDockerList) == 0)
                throw new BayException(BayMessage::get(Symbol::CFG_NO_PORT_DOCKER));

            $redirectFile = self::$harbor->redirectFile;

            if ($redirectFile != "") {
                $redirectFile = self::getLocation($redirectFile);
                fclose(STDOUT);
                fclose(STDERR);
                $STDOUT = fopen($redirectFile, "w+");
                $STDERR = $STDOUT;
            }
            #$f = fopen($redirect_file, "a");
            #sys.stdout = $f;
            #sys.stderr = $f;

            self::printVersion();

            self::$myHostName = gethostname();
            BayLog::debug("Host name    : " . self::$myHostName);

            $anrhorablePortMap = array();   // TCP server port map
            $unanchorablePortMap = array();  // UDB server port map
            foreach (self::$portDockerList as $dkr) {
                # open port
                $adr = $dkr->address();

                if($dkr->anchored) {
                    // Open TCP port

                    BayLog::info(BayMessage::get(Symbol::MSG_OPENING_TCP_PORT, $dkr->host(), $dkr->port(), $dkr->protocol()));

                    if($dkr->secure()) {
                        $skt = stream_socket_server(
                            "ssl://{$adr[0]}:{$adr[1]}",
                            $errno,
                            $errstr,
                            STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
                            $dkr->sslCtx());
                    }
                    elseif ($adr[1]) {
                        $skt = stream_socket_server(
                            "tcp://{$adr[0]}:{$adr[1]}",
                            $errno,
                            $errstr);
                    }
                    else {
                        if(file_exists($adr[0])) {
                            if (!unlink($adr[0])) {
                                throw new IOException("Cannot remove file: " . SysUtil::lastErrorMessage());
                            }
                        }
                        $skt = stream_socket_server(
                            "unix://{$adr[0]}",
                            $errno,
                            $errstr);
                    }

                    if ($skt === false)
                        throw new \Exception("Cannot open port: $errstr ($errno)");

                    // Non blocking mode does not work for accepting
                    //if (stream_set_blocking($skt, false) == false)
                    //    throw new \Exception("Cannot set non blocking: " . socket_strerror(socket_last_error()));

                    BayLog::debug(" socket=%s", $skt);
                    $anrhorablePortMap[] = new PortMap($skt, $dkr);
                }
                else {
                    # Open UDP port
                    BayLog::error("Unanchord port note supported");
                }
            }


            PacketStore::init();
            InboundShipStore::init();
            ProtocolHandlerStore::init();
            TourStore::init(TourStore::MAX_TOURS);
            #MemUsage.init()
            self::createPidFile(SysUtil::pid());
            GrandAgent::init(self::$harbor->grandAgents, $anrhorablePortMap, $unanchorablePortMap, self::$harbor->maxShips, self::$harbor->multiCore);
            SignalAgent::init(self::$harbor->controlPort);

            if (SysUtil::runOnPhpStorm()) {
                $handler = function($signo, $siginfo) {
                    BayLog::debug("sig: {$signo}");
                    GrandAgent::abortAll();
                };
                BayLog::debug("Unset Signals");
                pcntl_signal(SIGINT, $handler);
            }

            while (count(GrandAgent::$monitors) > 0) {
                $sel = new Selector();
                $pipToMonMap = [];
                foreach (GrandAgent::$monitors as $mon) {
                    BayLog::debug("Monitoring pipe of %s", $mon);
                    $sel->register($mon->recvPipe[0], Selector::OP_READ);
                    $pipToMonMap[intval($mon->recvPipe[0])] = $mon;
                }

                $serverSkt = null;
                if (SignalAgent::$signalAgent) {
                    $serverSkt = SignalAgent::$signalAgent->serverSkt;
                    $sel->register($serverSkt, Selector::OP_READ);
                }

                try {
                    $selkeys = $sel->select();
                }
                catch (IOException $e) {
                    BayLog::error("HOGEHOGE");
                    BayLog::error_e($e);
                    pcntl_signal_dispatch();
                    continue;
                }

                foreach($selkeys as $selkey) {
                    if ($selkey->channel == $serverSkt) {
                        SignalAgent::$signalAgent->onSocketReadable();
                    }
                    else {
                        $mon = $pipToMonMap[intval($selkey->channel)];
                        $mon->onReadable();
                    }
                }
            }

            SignalAgent::term();


        } catch (\Throwable $e) {
            BayLog::fatal_e($e, "%s", $e->getMessage());
        }

        exit(1);
    }

    /**
     * Get the BayServer version
     */
    public static function getVersion() : string
    {
        return Version::VERSION;
    }

    /**
     * Get the software name.
    */
    public static function  getSoftwareName() : string{
        if (self::$softwareName === null)
            self::$softwareName = "BayServer/" . self::getVersion();
        return self::$softwareName;
    }


    public static function findCity(string $name) : ?City
    {
        return self::$cities->findCity($name);
    }

    public static function loadPlan($bserv_plan)
    {
        $p = new BcfParser();
        $doc = $p->parse($bserv_plan);

        foreach ($doc->contentList as $obj) {
            if ($obj instanceof BcfElement) {
                $dkr = self::$dockers->createDocker($obj, null);
                if ($dkr instanceof Port)
                    self::$portDockerList[] = $dkr;
                elseif ($dkr instanceof Harbor)
                    self::$harbor = $dkr;
                elseif ($dkr instanceof City) {
                    self::$cities->add($dkr);
                } else
                    throw new ConfigException($obj->fileName, $obj->lineNo, BayMessage::get(Symbol::CFG_INVALID_DOCKER, $obj->name));
            }
        }
    }

    public static function printVersion(): void
    {
        $version = "Version " . self::getVersion();
        while (strlen($version) < 28)
            $version = ' ' . $version;

        echo("        ----------------------\n");
        echo("       /     BayServer        \\\n");
        echo("-----------------------------------------------------\n");
        echo(" \\");
        for ($i = 0; $i < 47 - strlen($version); $i++)
            echo(" ");

        echo($version . "  /\n");
        echo("  \\           Copyright (C) 2021 Yokohama Baykit  /\n");
        echo("   \\                     http://baykit.yokohama  /\n");
        echo("    ---------------------------------------------\n");
    }

    public static function parsePath(string $val): ?string
    {
        $val = self::getLocation($val);

        if (!file_exists($val))
            return null;

        return $val;
    }

    public static function getLocation(string $val): string
    {
        if (!SysUtil::isAbsolutePath($val))
            $val = self::$bservHome . "/" . $val;

        return $val;
    }

    public static function createPidFile($pid)
    {
        file_put_contents(self::$harbor->pidFile, strval($pid));
    }
}

/////////////////////////////////////////////
// initialize static members
/////////////////////////////////////////////
BayServer::$cities = new Cities();
