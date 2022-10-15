<?php
namespace baykit\bayserver\util;


use baykit\bayserver\BayLog;
use baykit\bayserver\tour\Tour;

class HttpUtil
{
    /**
     * Send MIME headers This method is called from sendHeaders()
     */
    public static function sendMimeHeaders(Headers $hdr, SimpleBuffer $buf) : void
    {
        foreach($hdr->names() as $name) {
            foreach ($hdr->values($name) as $value) {
                $buf->put($name);
                $buf->put(":");
                $buf->put($value);
                HttpUtil::sendNewLine($buf);
            }
        }
    }

    public static function sendNewLine(SimpleBuffer $buf)
    {
        $buf->put(CharUtil::CRLF);
    }


    /**
     * Parse AUTHORIZATION header
     */
    public static function parseAuthrization(Tour $tur) : void
    {
        $auth = $tur->req->headers->get(Headers::AUTHORIZATION);
        if (!StringUtil::isEmpty($auth)) {
            if(preg_match("/Basic (.*)/", $auth, $mch) != 1) {
                BayLog::warn("Not matched with basic authentication format");
            }
            else {
                $auth = $mch[1];
                try {
                    $auth = base64_decode($auth);
                    if(preg_match("/(.*):(.*)/", $auth, $mch) == 1) {
                        $tur->req->remoteUser = $mch[1];
                        $tur->req->remotePass = $mch[2];
                    }
                } catch (Exception $e) {
                    BayLog::error($e);
                }
            }
        }
    }


    public static function parseHostPort(Tour $tur, int $defaultPort) : void
    {
        $tur->req->reqHost = "";

        $hostPort = $tur->req->headers->get(Headers::X_FORWARDED_HOST);
        if(StringUtil::isSet($hostPort)) {
            $tur->req->headers->remove(Headers::X_FORWARDED_HOST);
            $tur->req->headers->set(Headers::HOST, $hostPort);
        }

        $hostPort = $tur->req->headers->get(Headers::HOST);
        if(StringUtil::isSet($hostPort)) {
            $pos = strpos($hostPort, ':');
            if($pos === false) {
                $tur->req->reqHost = $hostPort;
                $tur->req->reqPort = $defaultPort;
            }
            else {
                $tur->req->reqHost = substr($hostPort, 0, $pos);
                try {
                    $tur->req->reqPort = intval(substr($hostPort, $pos + 1));
                }
                catch(\Exception $e) {
                    BayLog::error($e);
                }
            }
        }
    }

    public static function resolveHost(string $adr) : ?string
    {
        $name = gethostbyname($adr);
        if(!$name) {
            BayLog::warn("Cannot get remote host name");
            return null;
        }
        return $name;
    }

}