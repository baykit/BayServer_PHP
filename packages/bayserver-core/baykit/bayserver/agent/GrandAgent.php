<?php

namespace baykit\bayserver\agent;

use baykit\bayserver\agent\letter\AcceptedLetter;
use baykit\bayserver\agent\letter\ClosedLetter;
use baykit\bayserver\agent\letter\ConnectedLetter;
use baykit\bayserver\agent\letter\ErrorLetter;
use baykit\bayserver\agent\letter\Letter;
use baykit\bayserver\agent\letter\ReadLetter;
use baykit\bayserver\agent\letter\WroteLetter;
use baykit\bayserver\agent\multiplexer\PlainTransporter;
use baykit\bayserver\agent\multiplexer\SpiderMultiplexer;
use baykit\bayserver\agent\multiplexer\SpinMultiplexer;
use baykit\bayserver\agent\transporter\WriteUnit;
use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\common\Postpone;
use baykit\bayserver\common\Recipient;
use baykit\bayserver\common\RudderState;
use baykit\bayserver\common\RudderStateStore;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\HttpException;
use baykit\bayserver\MemUsage;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\util\ArrayUtil;
use baykit\bayserver\util\IOException;
use parallel\Runtime;
use baykit\bayserver\BayServer;
use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;
use baykit\bayserver\util\IOUtil;





class GrandAgent
{
    const SELECT_TIMEOUT_SEC = 10;

    const CMD_OK = 0;
    const CMD_CLOSE = 1;
    const CMD_RELOAD_CERT = 2;
    const CMD_MEM_USAGE = 3;
    const CMD_SHUTDOWN = 4;
    const CMD_ABORT = 5;
    const CMD_CATCHUP = 6;

    #
    # class variables
    #
    public static $agentCount = 0;
    public static $maxShips = 0;
    public static $maxAgentId = 0;
    public static $multiCore = false;

    public static $agents = [];
    public static $listeners = [];

    public static $finale = false;

    #
    # instance variables
    #
    public int $agentId;
    public bool $anchorable;
    public Multiplexer $netMultiplexer;
    public SpiderMultiplexer $spiderMultiplexer;
    public SpinMultiplexer $spinMultiplexer;
    public Recipient $recipient;

    public int $maxInboundShips;
    public bool $aborted;
    /**
     * Tracks the netMultiplexer's busy/free state. Java / Ruby
     * mirror: we flip this in onAccepted (= when a new rudder pushes
     * channelCount across maxInboundShips) and onClosed (= when a
     * close drops channelCount back). Initial state is "not busy"
     * since the agent boots with only listen / pipe rudders that
     * don't count toward maxInboundShips.
     */
    public bool $busy = false;
    public CommandReceiver $commandReceiver;
    private array $timerHandlers = [];
    private int $lastTimeoutCheck = 0;
    private array $letterQueue = [];
    private array $postponeQueue = [];

    public function __construct(int $agentId,
        int $maxShips,
        bool $anchorable)
    {
        $this->agentId = $agentId;
        $this->anchorable = $anchorable;
        $this->maxInboundShips = $maxShips;

        $this->spiderMultiplexer = new SpiderMultiplexer($this, $anchorable);
        $this->spinMultiplexer = new SpinMultiplexer($this, $anchorable);
        $this->netMultiplexer = $this->spiderMultiplexer;
        $this->recipient = $this->spiderMultiplexer;

        $this->aborted = false;
    }

    public function __toString() : string
    {
        return "agt#{$this->agentId}";
    }

    public function run()
    {
        BayLog::info(BayMessage::get(Symbol::MSG_RUNNING_GRAND_AGENT, $this));

        $this->netMultiplexer->reqRead($this->commandReceiver->rudder);


        if($this->anchorable) {
            // Adds server socket channel of anchorable ports
            foreach(BayServer::$anchorablePortMap as $portMap) {
                $st = RudderStateStore::getStore($this->agentId)->rent();
                $st->init($portMap->rudder);
                $this->netMultiplexer->addRudderState($portMap->rudder, $st);
            }
        }

        // Mirror Java / Ruby: busy/free transitions live in
        // onAccepted (= rudder added) and onClosed (= rudder removed),
        // not in the main loop. A per-iteration isBusy() check would
        // flap onBusy / onFree every time a transient rudder
        // (SendFileShip file pipe, warp upstream, CGI pipe) is added
        // or removed -- under H2 c=32 m=32 load that counts dozens
        // of net rudderCount oscillations across maxShips per second
        // and the listen socket gets unregistered and re-registered
        // just as often, starving new accepts.
        $this->netMultiplexer->onFree();

        try {
            while (true) {
                if (!$this->spinMultiplexer->isEmpty()) {
                    // If "SpinHandler" is running, the select function does not block.
                    $received = $this->recipient->receive(false);
                    $this->spinMultiplexer->processData();
                } else {
                    $received = $this->recipient->receive(true);
                }

                if ($this->aborted) {
                    // agent finished
                    BayLog::debug("%s aborted by another thread", $this);
                    break;
                }

                if ($this->spinMultiplexer->isEmpty() && empty($this->letterQueue)) {
                    # timed out
                    # check per 10 seconds
                    if (time() - $this->lastTimeoutCheck >= 10) {
                        $this->ring();
                    }
                }

                while(!empty($this->letterQueue)) {
                    $let = array_shift($this->letterQueue);

                    if($let instanceof AcceptedLetter) {
                        $this->onAccepted($let);
                    }
                    else if($let instanceof ConnectedLetter) {
                        $this->onConnected($let);
                    }
                    else if($let instanceof ReadLetter) {
                        $this->onRead($let);
                    }
                    else if($let instanceof WroteLetter) {
                        $this->onWrote($let);
                    }
                    else if($let instanceof ClosedLetter) {
                        $this->onClosed($let);
                    }
                    else if($let instanceof ErrorLetter) {
                        $this->onError($let);
                    }
                }

            }
        }
        catch (\Throwable $e) {
            BayLog::fatal_e($e, "%s fatal error", $this);
            $this->shutdown();
        }
        finally {
            BayLog::debug("Agent end: %d", $this->agentId);
            $this->abort(null, 0);
        }

    }

    public function shutdown() : void
    {
        BayLog::debug("%s shutdown", $this);
        if($this->aborted)
            return;
        $this->aborted = true;

        $this->netMultiplexer->shutdown();

        foreach (GrandAgent::$listeners as $lis) {
            $lis->remove($this->agentId);
        }

        $this->commandReceiver->end();

        # remove from array
        self::$agents = array_filter(self::$agents,
            function ($item)  {
                return $item->agentId != $this->agentId;
            });

        if(BayServer::$harbor->multiCore()) {
            exit(1);
        }
        $this->agentId = -1;
    }

    public function abort() : void
    {
        BayLog::info("%s abort", $this);
    }

    public function reqShutdown() : void
    {
        $this->aborted = true;
        $this->wakeup();
    }

    public function reloadCert() : void
    {
        foreach(GrandAgent::$anchorablePortMap as $map) {
            if ($map->docker->secure()) {
                try {
                    $map->docker->secureDocker->reloadCert();
                }
                catch(\Exception $e) {
                    BayLog::error_e(e);
                }
            }
        }
    }

    public function printUsage() : void
    {
        # print memory usage
        BayLog::info("Agent#%d MemUsage", $this->agentId);
        BayLog::info(" PHP version: %s", phpversion());
        BayLog::info(" PHP Allocated memory: %.3f MBytes", memory_get_usage(true) / 1024.0 / 1024);
        BayLog::info(" PHP Current memory usage: %.3f MBytes", memory_get_usage() / 1024.0 / 1024);
        BayLog::info(" PHP Peak memory usage: %.3f MBytes", memory_get_peak_usage() / 1024.0 / 1024);

        MemUsage::get($this->agentId)->printUsage(1);
    }



    public function wakeup() : void
    {
        IOUtil::writeInt32($this->selectWakeupPipe[1], 0);
    }

    public function clean() {
        $this->nonBlockingHandler->closeAll();
    }

    public function addTimerHandler($handler)
    {
        $this->timerHandlers[] = $handler;
    }

    public function removeTimerHandler($handler)
    {
        ArrayUtil::remove($handler, $this->timerHandlers);
    }

    // The timer goes off
    private function ring(): void {
        foreach($this->timerHandlers as $th) {
            $th->onTimer();
        }
        $this->lastTimeoutCheck = time();
    }

    public function addCommandReceiver(Rudder $rd)
    {
        $this->commandReceiver = new CommandReceiver();
        $comTransporter = new PlainTransporter($this->netMultiplexer, $this->commandReceiver, true, 8, false);
        $this->commandReceiver->init($this->agentId, $rd, $comTransporter);
        $st = RudderStateStore::getStore($this->agentId)->rent();
        $st->init($this->commandReceiver->rudder, $comTransporter);
        $this->netMultiplexer->addRudderState($this->commandReceiver->rudder, $st);
        BayLog::info("CommandReceiver=%s", $this->commandReceiver);
    }

    public function sendAcceptedLetter(RudderState $st, Rudder $clientRd, bool $wakeup) : void {
        $this->sendLetter(new AcceptedLetter($st, $clientRd), $wakeup);
    }

    public function sendConnectedLetter(RudderState $st, bool $wakeup) : void {
        $this->sendLetter(new ConnectedLetter($st), $wakeup);
    }

    public function sendReadLetter(RudderState $st, int $n, ?string $adr, bool $wakeup) : void {
        $this->sendLetter(new ReadLetter($st, $n, $adr), $wakeup);
    }

    public function sendWroteLetter(RudderState $st, int $n, bool $wakeup) : void {
        $this->sendLetter(new WroteLetter($st, $n), $wakeup);
    }

    public function sendClosedLetter(RudderState $st, bool $wakeup) : void {
        $this->sendLetter(new ClosedLetter($st), $wakeup);
    }

    public function sendErrorLetter(RudderState $st, \Throwable $e, bool $wakeup) : void {
        $this->sendLetter(new ErrorLetter($st, $e), $wakeup);
    }

    public function addPostpone(Postpone $p) : void
    {
        $this->postponeQueue[] = $p;
    }

    private function countPostpone() : int
    {
        return count($this->postponeQueue);
    }

    public function reqCatchUp(): void
    {
        BayLog::debug("%s Req catchUp", $this);
        if($this->countPostpone() > 0) {
            $this->catchUp();
        }
        else {
            try {
                $this->commandReceiver->sendCommandToMonitor($this, self::CMD_CATCHUP, false);
            }
            catch (IOException $e) {
                BayLog::error($e);
                $this->abort();
            }
        }
    }

    public function catchUp(): void
    {
        BayLog::debug("%s catchUp", $this);
        if(!empty($this->postponeQueue)) {
            $p = array_shift($this->postponeQueue);
            $p->run();
        }
    }

    ######################################################
    # Private methods
    ######################################################

    public function sendLetter(Letter $let, bool $wakeup) : void {
        $this->letterQueue[] = $let;
        if($wakeup)
            $this->recipient->wakeup();
    }

    private function onAccepted(AcceptedLetter $let) : void
    {
        BayLog::debug("%s onAccepted", $this);
        $st = $let->state;
        try {
            $p = PortMap::findDocker($st->rudder, BayServer::$anchorablePortMap);
            $p->onConnected($this->agentId, $let->clientRudder);
        }
        catch (HttpException $e) {
            $st->transporter->onError($st->rudder, $e);
            $this->nextAction($st, NextSocketAction::CLOSE, false);
        }

        // Java / Ruby pattern: the only place we flip the netMultiplexer
        // into busy state. If accepting this connection pushed channelCount
        // across maxInboundShips, unregister the listen socket; otherwise
        // arm the next accept.
        if ($this->netMultiplexer->isBusy()) {
            BayLog::warn("%s net multiplexer is busy: %s", $this, $this->netMultiplexer);
            $this->netMultiplexer->onBusy();
            $this->busy = true;
        }
        else {
            $let->state->multiplexer->nextAccept($let->state);
        }
    }

    private function onConnected(ConnectedLetter $let) : void {
        $st = $let->state;
        if ($st->closed) {
            BayLog::debug("%s Rudder is already closed: rd=%s", $this, $st->rudder);
            return;
        }

        BayLog::debug("%s connected rd=%s", $this, $st->rudder);

        try {
            $nextAct = $st->transporter->onConnected($st->rudder);
            BayLog::debug("%s nextAct=%s", $this, $nextAct);
        }
        catch (IOException $e) {
            $st->transporter->onError($st->rudder, $e);
            $nextAct = NextSocketAction::CLOSE;
        }

        if($nextAct == NextSocketAction::READ) {
            // Read more
            $st->multiplexer->cancelWrite($st);
        }

        $this->nextAction($st, $nextAct, false);
    }

    private function onRead(ReadLetter $let) : void {
        $st = $let->state;
        if ($st->closed) {
            BayLog::debug("%s Rudder is already closed: rd=%s", $this, $st->rudder);
            return;
        }

        try {
            BayLog::debug("%s read %d bytes (rd=%s) ", $this, $let->nBytes, $st->rudder);
            $st->bytesRead += $let->nBytes;

            if ($let->nBytes <= 0) {
                BayLog::debug("%s EOF", $this);
                $st->readBuf = "";
                $nextAct = $st->transporter->onRead($st->rudder, $st->readBuf, $let->address);
            }
            else {
                $nextAct = $st->transporter->onRead($st->rudder, $st->readBuf, $let->address);
            }
        }
        catch (IOException $e) {
            $st->transporter->onError($st->rudder, $e);
            $nextAct = NextSocketAction::CLOSE;
        }

        $this->nextAction($st, $nextAct, true);
    }

    private function onWrote(WroteLetter $let) : void {
        $st = $let->state;
        if ($st->closed) {
            BayLog::debug("%s Rudder is already closed: rd=%s", $this, $st->rudder);
            return;
        }

        BayLog::debug("%s wrote %d bytes rd=%s qlen=%d", $this, $let->nBytes, $st->rudder, count($st->writeQueue));
        $st->bytesWrote += $let->nBytes;

        if(empty($st->writeQueue))
            throw new Sink("%s Write queue is empty: rd=%s", $this, $st->rudder);

        $unit = $st->writeQueue[0];
        if (strlen($unit->buf) > 0) {
            BayLog::debug("Could not write enough data: len=%d", strlen($unit->buf));
        }
        else {
            $st->multiplexer->consumeOldestUnit($st);
        }

        $writeMore = true;
        if (empty($st->writeQueue)) {
            $writeMore = false;
            $st->writing = false;
        }

        if ($writeMore) {
            $st->multiplexer->nextWrite($st);
        }
        else {
            if($st->finale) {
                // Close
                BayLog::debug("%s finale return Close", $this);
                $this->nextAction($st, NextSocketAction::CLOSE, false);
            }
            else {
                // Write off
                $st->multiplexer->cancelWrite($st);
            }
        }
    }

    private function onClosed(ClosedLetter $let) : void {
        $st = $let->state;
        BayLog::debug("%s onClose rd=%s", $this, $st->rudder);
        if ($st->closed) {
            BayLog::debug("%s Rudder is already closed: rd=%s", $this, $st->rudder);
            return;
        }

        $st->multiplexer->removeRudderState($st->rudder);

        while($st->multiplexer->consumeOldestUnit($st)) {
        }

        if ($st->transporter != null)
            $st->transporter->onClosed($st->rudder);

        $st->closed = true;
        $st->access();

        // Return the RudderState to the per-agent pool so it can be
        // reused for the next connection.
        RudderStateStore::getStore($this->agentId)->Return($st);

        // Java / Ruby pattern: the only place we flip the
        // netMultiplexer out of busy state. If we were busy and this
        // close drops channelCount back below maxInboundShips,
        // re-register the listen socket so new accepts can resume.
        if ($this->busy && !$this->netMultiplexer->isBusy()) {
            BayLog::warn("%s net multiplexer is free: %s", $this, $this->netMultiplexer);
            $this->netMultiplexer->onFree();
            $this->busy = false;
        }
    }

    private function onError(ErrorLetter $let) : void {

        try {
            throw $let->err;
        }
        catch (IOException | HttpException $e) {
            if($let->state->transporter != null) {
                $let->state->transporter->onError($let->state->rudder, $e);
                $this->nextAction($let->state, NextSocketAction::CLOSE, false);
            }
            else {
                // Accept error
                BayLog::debug_e($e, "Accept Error");
            }
        }
    }

    private function nextAction(RudderState $st, int $act, bool $reading) : void {
        BayLog::debug("%s next action: %s (reading=%b)", $this, $act, $reading);
        $cancel = false;

        switch($act) {
            case NextSocketAction::CONTINUE:
                if($reading)
                    $st->multiplexer->nextRead($st);
                break;

            case NextSocketAction::READ:
                $st->multiplexer->nextRead($st);
                break;

            case NextSocketAction::WRITE:
                if($reading)
                    $cancel = true;
                break;

            case NextSocketAction::CLOSE:
                if($reading)
                    $cancel = true;
                $st->multiplexer->reqClose($st->rudder);
                break;

            case NextSocketAction::SUSPEND:
                if($reading)
                    $cancel = true;
                break;

            default:
                throw new Sink("NextAction=" + $act);
        }

        if($cancel) {
            $st->multiplexer->cancelRead($st);
            BayLog::debug("%s Reading off %s", $this, $st->rudder);
            $st->reading = false;
        }

        $st->access();
    }

    ######################################################
    # class methods
    ######################################################

    public static function init(array $agtIds, int $maxShips)
    {
        self::$agentCount = count($agtIds);
        self::$maxShips = $maxShips;

        if (BayServer::$harbor->multiCore()) {
            if(count(BayServer::$unanchorablePortMap) > 0) {
                self::add($agtIds[0], false);
                ArrayUtil::removeByIndex(0, $agtIds);
            }

            foreach ($agtIds as $id) {
                #BayLog::debug("Add agent: %d", $id);
                self::add($id, true);
            }
        }
    }

    public static function get(int $id) : GrandAgent
    {
        return self::$agents[$id];
    }

    public static function add(int $agtId, bool $anchorable) : void
    {
        if ($agtId == -1)
            $agtId = self::$maxAgentId + 1;

        BayLog::debug("Add agent: id=%d", $agtId);

        if ($agtId > self::$maxAgentId)
            self::$maxAgentId = $agtId;

        $agt = new GrandAgent($agtId, BayServer::$harbor->maxShips(), $anchorable);
        self::$agents[$agtId] = $agt;

        foreach (self::$listeners as $lis) {
            $lis->add($agt->agentId);
        }
    }


    public static function addLifecycleListener(LifecycleListener $lis) : void
    {
        self::$listeners[] = $lis;
    }
}
