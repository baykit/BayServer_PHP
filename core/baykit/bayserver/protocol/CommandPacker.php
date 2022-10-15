<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\BayLog;
use baykit\bayserver\util\DataConsumeListener;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\Reusable;
use baykit\bayserver\watercraft\Ship;

class CommandPacker implements Reusable
{

    protected $pkt_packer;
    protected $pktStore;

    public function __construct($pkt_packer, $pktStore)
    {
        $this->pkt_packer = $pkt_packer;
        $this->pktStore = $pktStore;
    }

    /////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////////////////////////////////////////////////

    public function reset(): void
    {
    }

    /////////////////////////////////////////////////////////////////////////////////
    // Implements Reusable
    /////////////////////////////////////////////////////////////////////////////////

    public function post(Ship $sip, Command $cmd, callable $lsnr=null)
    {
        $pkt = $this->pktStore->rent($cmd->type);

        try {
            $cmd->pack($pkt);

            $this->pkt_packer->post(
                $sip->postman,
                $pkt,
                function () use ($lsnr, $pkt) {
                    $this->pktStore->Return($pkt);
                    if ($lsnr !== null) {
                        $lsnr();
                    }
                });
        } catch (IOException $e) {
            BayLog::debug_e($e, $e->getMessage());
            $this->pktStore->Return($pkt);
            throw $e;
        }
    }

    public function flush(Ship $sip) : void
    {
        $this->pkt_packer->flush($sip->postman);
    }

    public function end(Ship $sip) : void
    {
        $this->pkt_packer->end($sip->postman);
    }
}