<?php

namespace baykit\bayserver\docker\built_in;

use baykit\bayserver\BayMessage;
use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\DockerBase;
use baykit\bayserver\docker\Permission;
use baykit\bayserver\HttpException;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\Groups;
use baykit\bayserver\util\Headers;
use baykit\bayserver\util\HostMatcher;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\HttpUtil;
use baykit\bayserver\util\IpMatcher;

class CheckItem {
    private $matcher;
    public $admit;

    public function __construct(PermissionMatcher $matcher, bool $admit) {
        $this->matcher = $matcher;
        $this->admit = $admit;
    }

    public function socketAdmitted($ch) : bool {
        return $this->matcher->matchSocket($ch) == $this->admit;
    }

    public function tourAdmitted(Tour $tour) : bool {
        return $this->matcher->matchTour($tour) == $this->admit;
    }
}

interface PermissionMatcher {
    public function matchSocket($ch) : bool;
    public function matchTour(Tour $tour) : bool;
}

class HostPermissionMatcher implements PermissionMatcher {

    private $mch;

    public function __construct(string $hostPtn)
    {
        $this->mch = new HostMatcher($hostPtn);
    }

    public function matchSocket($ch) : bool
    {
        $name = stream_socket_get_name($ch, true);
        list($host, $port) = explode(":", $name);
        return $this->mch->matchSocket(HttpUtil::resolveHost($host));
    }

    public function matchTour(Tour $tour) : bool
    {
        return $this->mch->matchTour($tour->req->remoteHost());
    }
}

class IpPermissionMatcher implements PermissionMatcher {

    private $mch;

    public function __construct(string $ipDesc)
    {
        $this->mch = new IpMatcher($ipDesc);
    }

    public function matchSocket($ch) : bool
    {
        try {
            $name = stream_socket_get_name($ch, true);
            list($host, $port) = explode(":", $name);
            return $this->mch->match($host);
        }
        catch(\Exception $e) {
            BayLog::error_e($e);
            return false;
        }
    }

    public function matchTour(Tour $tour) : bool
    {
        try {
            return $this->mch->match($tour->req->remoteAddress);
        } catch (\Exception $e) {
            BayLog::error($e);
            return false;
        }
    }
}

class BuiltInPermissionDocker extends DockerBase implements  Permission
{

    private $checkList = [];
    private $groups = [];

    //////////////////////////////////////////////////////
    // Implements Docker
    //////////////////////////////////////////////////////

    public function init($elm, $parent) : void
    {
        parent::init($elm, $parent);
    }

    //////////////////////////////////////////////////////
    // Implements DockerBase
    //////////////////////////////////////////////////////
    public function initKeyVal(BcfKeyVal $kv) : bool
    {
        try {
            switch(strtolower($kv->key)) {
                default:
                    return false;

                case "admit":
                case "allow": {
                    foreach ($this->parseValue($kv) as $pm) {
                        $this->checkList[] = new CheckItem($pm, true);
                    }
                    break;
                }

                case "refuse":
                case "deny": {
                    foreach ($this->parseValue($kv) as $pm) {
                        $this->checkList[] = new CheckItem($pm, false);
                    }
                    break;
                }

                case "group": {
                    foreach (explode(" ", $kv->value) as $grp) {
                        $g = Groups::getGroup($grp);
                        if ($g == null) {
                            throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_GROUP_NOT_FOUND, $kv->value));
                        }
                        $this->groups[] = $g;
                    }
                    break;
                }
            }
            return true;
        }
        catch(ConfigException $e) {
            throw $e;
        }
        catch(\Exception $e) {
            throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PERMISSION_DESCRIPTION, $kv->value), $e);
        }
    }


    //////////////////////////////////////////////////////
    // Implements Permission
    //////////////////////////////////////////////////////

    public function socketAdmitted($ch): void
    {
        // Check remote host
        $isOk = true;
        foreach ($this->checkList as $chk) {
            if ($chk->admit) {
                if ($chk->socketAdmitted($ch)) {
                    $isOk = true;
                    break;
                }
            } else {
                if (!$chk->socketAdmitted($ch)) {
                    $isOk = false;
                    break;
                }
            }
        }

        if (!$isOk) {
            BayLog::error("Permission error: socket not admitted: %s", $ch);
            throw new HttpException(HttpStatus::FORBIDDEN);
        }
    }

    public function tourAdmitted(Tour $tur): void
    {
        // Check remote host
        $isOk = true;
        foreach($this->checkList as $chk) {
            if($chk->admit) {
                if($chk->tourAdmitted($tur)) {
                    $isOk = true;
                    break;
                }
            }
            else {
                if(!$chk->tourAdmitted($tur)) {
                    $isOk = false;
                    break;
                }
            }
        }

        if(!$isOk)
            throw new HttpException(HttpStatus::FORBIDDEN, $tur->req->uri);

        if(count($this->groups) == 0)
            return;

        // Check member
        $isOk = false;
        if($tur->req->remoteUser != null) {
            foreach($this->groups as $g) {
                if($g->validate($tur->req->remoteUser, $tur->req->remotePass)) {
                    $isOk = true;
                    break;
                }
            }
        }

        if(!$isOk) {
            $tur->res->headers->set(Headers::WWW_AUTHENTICATE, "Basic realm=\"Auth\"");
            throw new HttpException(HttpStatus::UNAUTHORIZED);
        }
    }


    //////////////////////////////////////////////////////
    // Private methods
    //////////////////////////////////////////////////////

    private function parseValue(BcfKeyVal $kv) : array
    {
        $type = null;
        $matchStr = [];

        foreach(explode(" ",$kv->value) as $val) {
            if($type == null)
                $type = $val;
            else
                $matchStr[] = $val;
        }

        if(count($matchStr) == 0) {
            throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PERMISSION_DESCRIPTION, $kv->value));
        }

        $pmList = [];
        switch(strtolower($type)) {
            case "host":
                foreach ($matchStr as $m) {
                    $pmList[] = new HostPermissionMatcher($m);
                }
                return $pmList;
            case "ip":
                foreach ($matchStr as $m) {
                    $pmList[] = new IpPermissionMatcher($m);
                    return $pmList;
                }
            default:
                throw new ConfigException($kv->fileName, $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_PERMISSION_DESCRIPTION, $kv->value));
        }
    }
}