<?php

namespace baykit\bayserver\docker\ajp;

class AjpType {
    const DATA = 0;
    const FORWARD_REQUEST = 2;
    const SEND_BODY_CHUNK = 3;
    const SEND_HEADERS = 4;
    const END_RESPONSE = 5;
    const GET_BODY_CHUNK = 6;
    const SHUTDOWN = 7;
    const PING = 8;
    const CPING = 10;
}