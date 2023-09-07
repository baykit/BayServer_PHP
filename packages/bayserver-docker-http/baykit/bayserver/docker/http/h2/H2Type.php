<?php

namespace baykit\bayserver\docker\http\h2;

class H2Type {
    const PREFACE = -1;
    const DATA = 0;
    const HEADERS = 1;
    const PRIORITY = 2;
    const RST_STREAM = 3;
    const SETTINGS = 4;
    const PUSH_PROMISE = 5;
    const PING = 6;
    const GOAWAY = 7;
    const WINDOW_UPDATE = 8;
    const CONTINUATION = 9;
}