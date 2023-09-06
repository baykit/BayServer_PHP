<?php

namespace baykit\bayserver\docker\built_in;



use baykit\bayserver\BayLog;
use baykit\bayserver\BayMessage;
use baykit\bayserver\BayServer;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\ConfigException;
use baykit\bayserver\docker\base\DockerBase;
use baykit\bayserver\docker\Club;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Permission;
use baykit\bayserver\docker\Reroute;
use baykit\bayserver\docker\Town;
use baykit\bayserver\Symbol;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;




class BuiltInTownDocker extends DockerBase implements  Town  {

    public $location;
    public $welcome;
    public $clubs = [];
    public $permissions = [];
    public $reroutes = [];
    public $city;
    public $name;

    /////////////////////////////////////////////////////////////////////
    // Implements Docker
    /////////////////////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        $arg = $elm->arg;
        if(!StringUtil::startsWith($arg, "/"))
            $arg = "/" . $arg;
        $this->name = $arg;
        if(!StringUtil::endsWith($this->name, "/"))
            $this->name = $this->name . "/";
        $this->city = $parent;

        parent::init($elm, $parent);
    }

    /////////////////////////////////////////////////////////////////////
    // Implements DockerBase
    /////////////////////////////////////////////////////////////////////

    public function initDocker(Docker $dkr): bool
    {
        if ($dkr instanceof Club) {
            $this->clubs[] = $dkr;
        } elseif ($dkr instanceof Permission) {
            $this->permissions[] = $dkr;
        } elseif ($dkr instanceof Reroute) {
            $this->reroutes[] = $dkr;
        }
        else {
            return parent::initDocker($dkr);
        }
        return true;
    }

    public function initKeyVal(BcfKeyVal $kv): bool
    {
        switch(strtolower($kv->key)) {
            default:
                return false;

            case "location": {
                $this->location = $kv->value;
                $loc = BayServer::getLocation($this->location);
                if(!is_dir($loc))
                    throw new ConfigException($kv->fileName,  $kv->lineNo, BayMessage::get(Symbol::CFG_INVALID_LOCATION, $kv->value));
                $this->location = realpath($loc);
                break;
            }

            case "index":
            case "welcome":
                $this->welcome = $kv->value;
                break;
        }
        return true;
    }


    public function reroute(string $uri): string
    {
        foreach ($this->reroutes as $r) {
            $uri = $r->reroute($this, $uri);
        }

        return $uri;
    }

    public function matches(string $uri): int
    {
        if(StringUtil::startsWith($uri, $this->name))
            return self::MATCH_TYPE_MATCHED;
        elseif($uri . "/" == $this->name)
            return self::MATCH_TYPE_CLOSE;
        else
            return self::MATCH_TYPE_NOT_MATCHED;
    }

    public function checkAdmitted(Tour $tur) : void
    {
        foreach ($this->permissions as $p) {
            $p->tourAdmitted($tur);
        }
    }
}