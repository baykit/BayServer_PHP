<?php

namespace baykit\bayserver\protocol;

use baykit\bayserver\ship\Ship;
use baykit\bayserver\util\IOException;
use baykit\bayserver\util\Reusable;

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

    public function post(Ship $sip, Command $cmd, ?callable $lsnr=null)
    {
        $pkt = $this->pktStore->rent($cmd->type);

        try {
            $cmd->pack($pkt);

            $this->pkt_packer->post(
                $sip,
                $pkt,
                function () use ($lsnr, $pkt) {
                    $this->pktStore->Return($pkt);
                    if ($lsnr !== null) {
                        $lsnr();
                    }
                });
        } catch (IOException $e) {
            //BayLog::debug_e($e, $e->getMessage());
            $this->pktStore->Return($pkt);
            throw $e;
        }
    }
}