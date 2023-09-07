<?php
namespace baykit\bayserver\util;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\tour\Tour;

class CGIUtil
{

    const REQUEST_METHOD = "REQUEST_METHOD";
    const REQUEST_URI = "REQUEST_URI";
    const SERVER_PROTOCOL = "SERVER_PROTOCOL";
    const GATEWAY_INTERFACE = "GATEWAY_INTERFACE";
    const SERVER_NAME = "SERVER_NAME";
    const SERVER_PORT = "SERVER_PORT";
    const QUERY_STRING = "QUERY_STRING";
    const SCRIPT_NAME = "SCRIPT_NAME";
    const SCRIPT_FILENAME = "SCRIPT_FILENAME";
    const PATH_TRANSLATED = "PATH_TRANSLATED";
    const PATH_INFO = "PATH_INFO";
    const CONTENT_TYPE = "CONTENT_TYPE";
    const CONTENT_LENGTH = "CONTENT_LENGTH";
    const REMOTE_ADDR = "REMOTE_ADDR";
    const REMOTE_PORT = "REMOTE_PORT";
    const REMOTE_USER = "REMOTE_USER";
    const HTTP_ACCEPT = "HTTP_ACCEPT";
    const HTTP_COOKIE = "HTTP_COOKIE";
    const HTTP_HOST = "HTTP_HOST";
    const HTTP_USER_AGENT = "HTTP_USER_AGENT";
    const HTTP_ACCEPT_ENCODING = "HTTP_ACCEPT_ENCODING";
    const HTTP_ACCEPT_LANGUAGE = "HTTP_ACCEPT_LANGUAGE";
    const HTTP_CONNECTION = "HTTP_CONNECTION";
    const HTTP_UPGRADE_INSECURE_REQUESTS = "HTTP_UPGRADE_INSECURE_REQUESTS";
    const HTTPS = "HTTPS";
    const PATH = "PATH";
    const SERVER_SIGNATURE = "SERVER_SIGNATURE";
    const SERVER_SOFTWARE = "SERVER_SOFTWARE";
    const SERVER_ADDR = "SERVER_ADDR";
    const DOCUMENT_ROOT = "DOCUMENT_ROOT";
    const REQUEST_SCHEME = "REQUEST_SCHEME";
    const CONTEXT_PREFIX = "CONTEXT_PREFIX";
    const CONTEXT_DOCUMENT_ROOT = "CONTEXT_DOCUMENT_ROOT";
    const SERVER_ADMIN = "SERVER_ADMIN";
    const REQUEST_TIME_FLOAT = "REQUEST_TIME_FLOAT";
    const REQUEST_TIME = "REQUEST_TIME";
    const UNIQUE_ID = "UNIQUE_ID";
    /*
    const X_FORWARDED_HOST = "X_FORWARDED_HOST";
    const X_FORWARDED_FOR = "X_FORWARDED_FOR";
    const X_FORWARDED_PROTO = "X_FORWARDED_PROTO";
    const X_FORWARDED_PORT = "X_FORWARDED_PORT";
    const X_FORWARDED_SERVER = "X_FORWARDED_SERVER";
    */

    public static function getEnvHash(string $path, string $docRoot, string $scriptBase, Tour $tour) : array
    {

        $map = [];
        self::getEnv($path, $docRoot, $scriptBase, $tour, function ($name, $value) use (&$map) {
            $map[$name] = $value;
        });

        return $map;
    }

    public static function getEnv(string $path, string $docRoot, string $scriptBase, Tour $tour, callable $lis) : void
    {

        $reqHeaders = $tour->req->headers;
        
        $ctype = $reqHeaders->contentType();
        if($ctype !== null) {
            $pos = strpos($ctype, "charset=");
            if($pos !== false) {
                $tour->req->charset = trim(substr($ctype, $pos+8));
            }
        }

        self::addEnv($lis, self::REQUEST_METHOD, $tour->req->method);
        self::addEnv($lis, self::REQUEST_URI, $tour->req->uri);
        self::addEnv($lis, self::SERVER_PROTOCOL, $tour->req->protocol);
        self::addEnv($lis, self::GATEWAY_INTERFACE, "CGI/1.1");

        self::addEnv($lis, self::SERVER_NAME, $tour->req->reqHost);
        self::addEnv($lis, self::SERVER_ADDR, $tour->req->serverAddress);
        if($tour->req->reqPort >= 0)
            self::addEnv($lis, self::SERVER_PORT, (string)$tour->req->reqPort);
        self::addEnv($lis, self::SERVER_SOFTWARE, BayServer::getSoftwareName());

        self::addEnv($lis, self::CONTEXT_DOCUMENT_ROOT, $docRoot);


        foreach($tour->req->headers->names() as $name) {
            $newVal = null;
            foreach($tour->req->headers->values($name) as $value) {
                if ($newVal == null)
                    $newVal = $value;
                else {
                    $newVal = $newVal . "; " . $value;
                }
            }

            foreach($tour->req->headers->values($name) as $value) {
                $name = str_replace('-', '_', strtoupper($name));
                if(StringUtil::startsWith($name, "X_FORWARDED_")) {
                    self::addEnv($lis, $name, $newVal);
                }
                else {
                    switch ($name) {
                        case self::CONTENT_TYPE:
                        case self::CONTENT_LENGTH:
                            self::addEnv($lis, $name, $newVal);
                            break;

                        default:
                            self::addEnv($lis, "HTTP_" . $name, $newVal);
                            break;
                    }
                }
            }
        }

        self::addEnv($lis, self::REMOTE_ADDR, $tour->req->remoteAddress);
        self::addEnv($lis, self::REMOTE_PORT, (string)$tour->req->remotePort);
        //self::addEnv(map, REMOTE_USER, "unknown");

        self::addEnv($lis, self::REQUEST_SCHEME, $tour->isSecure ? "https": "http");

        $tmpSecure = $tour->isSecure;
        $fproto = $tour->req->headers->get(Headers::X_FORWARDED_PROTO);
        if($fproto !== null) {
            $tmpSecure = StringUtil::eqIgnorecase($fproto, "https");
        }
        if($tmpSecure)
            self::addEnv($lis, self::HTTPS, "on");

        self::addEnv($lis, self::QUERY_STRING, $tour->req->queryString);
        self::addEnv($lis, self::SCRIPT_NAME, $tour->req->scriptName);

        if($tour->req->pathInfo === null) {
            self::addEnv($lis, self::PATH_INFO, "");
        }
        else {
            self::addEnv($lis, self::PATH_INFO, $tour->req->pathInfo);
            try {
                $pathTranslated = realpath(SysUtil::joinPath($docRoot, $tour->req->pathInfo));
                if($pathTranslated !== false)
                    self::addEnv($lis, self::PATH_TRANSLATED, $pathTranslated);
            }
            catch(IOException $e) {
                BayLog::error_e($e);
            }
        }

        if(!StringUtil::endsWith($scriptBase, "/"))
            $scriptBase = $scriptBase . "/";
        self::addEnv($lis, self::SCRIPT_FILENAME, $scriptBase . substr($tour->req->scriptName, strlen($path)));
        self::addEnv($lis, self::PATH, getenv("PATH"));
    }
    
   
    private static function addEnv(callable $lis, string $key, ?string $value) : void
    {
        if($value === null)
            $value = "";

        $lis($key, (string)$value);
    }
}