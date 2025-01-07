<?php

namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\Sink;

class Selector_Key {
    public $channel;
    public $operation;

    public function __construct($ch, int $op)
    {
        $this->channel = $ch;
        $this->operation = $op;
    }

    public function __toString() : string
    {
        return "SelectorKey(ch={$this->channel}, op={$this->operation})";
    }

    public function readable() : bool
    {
        return ($this->operation & Selector::OP_READ) != 0;
    }

    public function writable() : bool
    {
        return ($this->operation & Selector::OP_WRITE) != 0;
    }
}

class Selector {
    const OP_READ = 1;
    const OP_WRITE = 2;

    public $keys = [];

    public function register($ch, $op) : void
    {
        if($ch == null || !$this->isChannel($ch))
            throw new Sink("Invalid stream: %s", get_class($ch));

        foreach ($this->keys as $key) {
            if($key->channel == $ch) {
                $key->operation |= $op;
                return;
            }
        }

        $this->keys[] = new Selector_Key($ch, $op);
    }

    public function unregister($ch) : void
    {
        $pos = -1;
        for($i = 0; $i < count($this->keys); $i++) {
            if($this->keys[$i]->channel == $ch) {
                $pos = $i;
                break;
            }
        }
        if ($pos != -1) {
            ArrayUtil::removeByIndex($pos, $this->keys);
        }
    }

    public function modify($ch, $op) : void
    {
        if($ch == null || !$this->isChannel($ch))
            throw new Sink("Invalid stream: %s", $ch);

        foreach ($this->keys as $key) {
            if($key->channel == $ch) {
                $key->operation = $op;
                return;
            }
        }

        $this->keys[] = new Selector_Key($ch, $op);
    }

    public function getOp($ch)
    {
        foreach ($this->keys as $key) {
            if($key->channel == $ch) {
                return $key->operation;
            }
        }

        return null;
    }

    public function select(int $timeout=-1) : array
    {
        $except_list = [];
        $read_list = [];
        $write_list = [];

        //Mutex::lock($this->lock);
        try {
            foreach ($this->keys as $key) {
                if($key->channel == null)
                    throw new Sink();

                if ($key->readable())
                    $read_list[] = $key->channel;

                if ($key->writable())
                    $write_list[] = $key->channel;
            }

            if(empty($read_list) && empty($write_list)) {
                throw new IOException("No channel registered");
            }

            //var_dump( $read_list);
            //var_dump( $write_list);
            //BayLog::debug("SELECTING");
            if (stream_select($read_list, $write_list, $except_list, $timeout < 0 ? null : $timeout) === false) {
                throw new IOException("Select failed: err=" . SysUtil::lastErrorMessage() . ", skt_err=" . SysUtil::lastSocketErrorMessage());
            }
            //BayLog::debug("SELECTED");
            //var_dump( $read_list);
            //var_dump( $write_list);

            $result = [];
            foreach ($read_list as $ch) {
                $result[] = new Selector_Key($ch, self::OP_READ);
            }
            foreach ($write_list as $ch) {
                $found = false;
                foreach ($result as $key) {
                    if($key == $ch) {
                        $key->operation |= self::OP_WRITE;
                        $found = true;
                        break;
                    }
                }
                if (!$found)
                    $result[] = new Selector_Key($ch, self::OP_WRITE);
            }

            return $result;
        } finally {
            //Mutex::unlock($this->lock);
        }
    }


    private function isChannel($ch): bool {
        return is_resource($ch) || get_class($ch) === 'stream';
    }
}