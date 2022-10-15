<?php

namespace baykit\bayserver\docker\built_in;

/*************************************************/
/* Implemented classes                           */
/*************************************************/

use baykit\bayserver\tour\Tour;

class TextItem extends LogItem {

    public static $factory;

    /** text to print */
    private $text;

    public function __construct(string $text)
    {
        $this->text = $text;
    }

    public function getItem(Tour $tour) : ?string
    {
        return $this->text;
    }
}
TextItem::$factory = function () { return new TextItem(); };


/**
 * Return null result
 */
class NullItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return null;
    }
}
NullItem::$factory = function () { return new NullItem(); };

/**
 * Return remote IP address (%a)
 */
class RemoteIpItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->remoteAddress;
    }
}
RemoteIpItem::$factory = function () { return new RemoteIpItem(); };

/**
 * Return local IP address (%A)
 */
class ServerIpItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->serverAddress;
    }
}
ServerIpItem::$factory = function () { return new ServerIpItem(); };

/**
 * Return number of bytes that is sent from clients (Except HTTP headers)
 * (%B)
 */
class RequestBytesItem1 extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        $bytes = $tour->req->headers->contentLength();
        if ($bytes < 0)
            $bytes = 0;
        return strval($bytes);
    }
}
RequestBytesItem1::$factory = function () { return new RequestBytesItem1(); };

/**
 * Return number of bytes that is sent from clients in CLF format (Except
 * HTTP headers) (%b)
 */
class RequestBytesItem2 extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        $bytes = $tour->req->headers->contentLength();
        if ($bytes <= 0)
            return "-";
        else
            return strval($bytes);
    }
}
RequestBytesItem2::$factory = function () { return new RequestBytesItem2(); };

/**
 * Return connection status (%c)
 */
class ConnectionStatusItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        if ($tour->isAborted())
            return "X";
        else
            return "-";
    }
}
ConnectionStatusItem::$factory = function () { return new ConnectionStatusItem(); };

/**
 * Return file name (%f)
 */
class FileNameItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->scriptName;
    }
}
FileNameItem::$factory = function () { return new FileNameItem(); };

/**
 * Return remote host name (%H)
 */
class RemoteHostItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->remoteHost();
    }
}
RemoteHostItem::$factory = function () { return new RemoteHostItem(); };

/**
 * Return remote log name (%l)
 */
class RemoteLogItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return null;
    }
}
RemoteLogItem::$factory = function () { return new RemoteLogItem(); };

/**
 * Return request protocol (%m)
 */
class ProtocolItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->protocol;
    }
}
ProtocolItem::$factory = function () { return new ProtocolItem(); };

/**
 * Return requested header (%{Foobar}i)
 */
class RequestHeaderItem extends LogItem
{
    public static $factory;

    /** Header name */
    private $name;

    public function init(?string $param): void
    {
        if ($param == null)
            $param = "";
        $this->name = $param;
    }

    public function getItem(Tour $tour): ?string
    {
        return $tour->req->headers->get($this->name);
    }
}
RequestHeaderItem::$factory = function () { return new RequestHeaderItem(); };


/**
 * Return request method (%m)
 */
class MethodItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->method;
    }
}
MethodItem::$factory = function () { return new MethodItem(); };

/**
 * Return responde header (%{Foobar}o)
 */
class ResponseHeaderItem extends LogItem
{
    public static $factory;

    /** Header name */
    private $name;

    public function init(?string $param) : void
    {
        if ($param == null)
            $param = "";
        $this->name = $param;
    }

    public function getItem(Tour $tour) : ?string
    {
        return $tour->res->headers->get($this->name);
    }
}
ResponseHeaderItem::$factory = function () { return new ResponseHeaderItem(); };

/**
 * The server port (%p)
 */
class PortItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return strval($tour->req->serverPort);
    }
}
PortItem::$factory = function () { return new PortItem(); };

/**
 * Return query string (%q)
 */
class QueryStringItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        $qStr = $tour->req->queryString;
        if ($qStr != null)
            return '?' . $qStr;
        else
            return null;
    }
}
QueryStringItem::$factory = function () { return new QueryStringItem(); };

/**
 * The start line (%r)
 */
class StartLineItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->method . " " . $tour->req->uri . " " . $tour->req->protocol;
    }
}
StartLineItem::$factory = function () { return new StartLineItem(); };

/**
 * Return status (%s)
 */
class StatusItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return strval($tour->res->headers->status);
    }
}
StatusItem::$factory = function () { return new StatusItem(); };

/**
 * Return current time (%{format}t)
 */
class TimeItem extends LogItem
{
    public static $factory;

    /** Header name */
    private $format = "[Y-m-d H:i:s Z]";

    public function init(?string $param) : void
    {
        if ($param != null)
            $this->format = $param;
    }

    public function getItem(Tour $tour) : ?string
    {
        return date($this->format);
    }
}
TimeItem::$factory = function () { return new TimeItem(); };

/**
 * Return how long request took (%T)
 */
class IntervalItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return strval($tour->interval / 1000);
    }
}
IntervalItem::$factory = function () { return new IntervalItem(); };

/**
 * Return remote user (%u)
 */
class RemoteUserItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->remoteUser;
    }
}
RemoteUserItem::$factory = function () { return new RemoteUserItem(); };

/**
 * Return requested URL(not content query string) (%U)
 */
class RequestUrlItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        $url = $tour->req->uri == null ? "" : $tour->req->uri;
        $pos = strpos($url, '?');
        if ($pos !== false)
            $url = substr($url, 0, $pos);
        return $url;
    }
}
RequestUrlItem::$factory = function () { return new RequestUrlItem(); };

/**
 * Return the server name (%v)
 */
class ServerNameItem extends LogItem
{
    public static $factory;

    public function getItem(Tour $tour) : ?string
    {
        return $tour->req->serverName;
    }
}
ServerNameItem::$factory = function () { return new ServerNameItem(); };


