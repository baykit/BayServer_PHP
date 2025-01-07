<?php

namespace baykit\bayserver\agent\multiplexer;


use baykit\bayserver\agent\NextSocketAction;
use baykit\bayserver\agent\UpgradeException;
use baykit\bayserver\BayLog;
use baykit\bayserver\common\Multiplexer;
use baykit\bayserver\common\Transporter;
use baykit\bayserver\protocol\ProtocolException;
use baykit\bayserver\rudder\Rudder;
use baykit\bayserver\ship\Ship;
use baykit\bayserver\Sink;
use baykit\bayserver\util\DataConsumeListener;
use baykit\bayserver\util\IOException;

class PlainTransporter implements Transporter {

    private Multiplexer $multiplexer;
    private bool $serverMode;
    private bool $traceSsl;
    protected Ship $ship;
    private bool $closed;
    protected int $readBufferSize;


    public function __construct(Multiplexer $mpx, Ship $sip, bool $serverMode, int $bufSiz, bool $traceSsl)
    {
        $this->multiplexer = $mpx;
        $this->ship = $sip;
        $this->serverMode = $serverMode;
        $this->traceSsl = $traceSsl;
        $this->readBufferSize = $bufSiz;
        $this->closed = false;
    }

    public function __toString(): string {
        return "tp[" . $this->ship . "]";
    }

    ////////////////////////////////////////////
    // Implements Reusable
    ////////////////////////////////////////////
    public function reset(): void
    {
        $this->closed = false;
    }

    ////////////////////////////////////////////
    // Implements Transporter
    ////////////////////////////////////////////
    public final function init(): void
    {
    }

    public final function onConnected(Rudder $rd): int
    {
        $this->checkRudder($rd);
        return $this->ship->notifyConnect();
    }

    public final function onRead(Rudder $rd, string $buf, ?string $adr): int
    {
        $this->checkRudder($rd);

        if(strlen($buf) == 0) {
            return $this->ship->notifyEof();
        }
        else {
            try {
                return $this->ship->notifyRead($buf);
            } catch (UpgradeException $e) {
                BayLog::debug("%s Protocol upgrade", $this->ship);
                return $this->ship->notifyRead($buf);
            }
            catch(ProtocolException $e) {
                $close = $this->ship->notifyProtocolError($e);

                if (!$close && $this->serverMode)
                    return NextSocketAction::CONTINUE;
                else
                    return NextSocketAction::CLOSE;
            }
            catch(IOException $e) {
                // IOError which occur in notify_XXX must be distinguished from
                // it which occur in handshake or readNonBlock.
                $this->onError($rd, $e);
                return NextSocketAction::CLOSE;
            }

        }

    }

    public function onError(Rudder $rd, \Exception $e): void
    {
        $this->checkRudder($rd);
        $this->ship->notifyError($e);
    }

    public function onClosed(Rudder $rd): void {
        $this->checkRudder($rd);
        $this->ship->notifyClose();
    }

    public function reqConnect(Rudder $rd, string $adr): void {
        $this->checkRudder($rd);
        $this->multiplexer->reqConnect($rd, $adr);
    }

    public function reqRead(Rudder $rd): void {
        $this->checkRudder($rd);
        $this->multiplexer->reqRead($rd);
    }

    public function reqWrite(Rudder $rd, string $buf, $adr, $tag, ?callable $callback): void {
        $this->checkRudder($rd);
        $this->multiplexer->reqWrite($rd, $buf, $adr, $tag, $callback);
    }

    public function reqClose(Rudder $rd): void {
        $this->checkRudder($rd);
        $this->closed = true;
        $this->multiplexer->reqClose($rd);
    }

    public function checkTimeout(Rudder $rd, int $durationSec): bool {
        $this->checkRudder($rd);
        return $this->ship->checkTimeout($durationSec);
    }

    public function getReadBufferSize(): int {
        return $this->readBufferSize;
    }

    public final function printUsage(int $indent) : void {
    }

    ////////////////////////////////////////////
    // Custom methods
    ////////////////////////////////////////////

    public function secure(): bool
    {
        return false;
    }

    private function checkRudder(Rudder $rd)
    {
        if ($rd != $this->ship->rudder) {
            throw new Sink("Invalid rudder: rd=%s check=%s", $this->ship->rudder, $rd);
        }
    }
}









