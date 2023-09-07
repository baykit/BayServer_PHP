<?php

namespace baykit\bayserver\docker\http\h2;

class H2Settings 
{
    const DEFAULT_HEADER_TABLE_SIZE = 4096;
    const DEFAULT_ENABLE_PUSH = true;
    const DEFAULT_MAX_CONCURRENT_STREAMS = -1;
    const DEFAULT_MAX_WINDOW_SIZE = 65535;
    const DEFAULT_MAX_FRAME_SIZE = 16384;
    const DEFAULT_MAX_HEADER_LIST_SIZE = -1;

    public $headerTableSize;
    public $enablePush;
    public $maxConcurrentStreams;
    public $initialWindowSize;
    public $maxFrameSize;
    public $maxHeaderListSize;

    public function __construct()
    {
        $this->reset();
    }

    public function reset() : void
    {
        $this->headerTableSize = self::DEFAULT_HEADER_TABLE_SIZE;
        $this->enablePush = self::DEFAULT_ENABLE_PUSH;
        $this->maxConcurrentStreams = self::DEFAULT_MAX_CONCURRENT_STREAMS;
        $this->initialWindowSize = self::DEFAULT_MAX_WINDOW_SIZE;
        $this->maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE;
        $this->maxHeaderListSize = self::DEFAULT_MAX_HEADER_LIST_SIZE;
    }
}