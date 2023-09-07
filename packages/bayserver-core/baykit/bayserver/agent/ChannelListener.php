<?php

namespace baykit\bayserver\agent;

interface ChannelListener
{
    public function onReadable($chkCch) : int;

    public function onWritable($chkCh) : int;

    public function onConnectable($chkCh) : int;

    public function onError($chkCh, $err) : void;

    public function onClosed($chkCh) : void;

    public function checkTimeout($chkCh, $durationSec) : bool;
}
