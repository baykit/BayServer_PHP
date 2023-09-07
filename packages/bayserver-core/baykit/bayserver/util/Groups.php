<?php
namespace baykit\bayserver\util;

use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\bcf\BcfParser;

class Groups_Member {
    public $name;
    public $digest;

    public function __construct(string $name, string $digest)
    {
        $this->name = $name;
        $this->digest = $digest;
    }

    public function validate(string $password) : bool
    {
        if($password == null)
            return false;
        $dig = (new MD5Password())->encode($password);
        return $this->digest == $dig;
    }
}

class Groups_Group
{
    public $name;
    public $members = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function add(string $mem) : void
    {
        $this->members[] = $mem;
    }

    public function validate(string $mName, string $pass) : bool
    {
        if(!in_array($mName, $this->members))
            return false;
        $m = Groups::$allMembers[$mName];
        if($m == null)
            return false;
        return $m->validate($pass);
    }
}



class Groups
{
    public static $allGroups = [];
    public static $allMembers = [];

    public static function init(string $conf) : void
    {
        $p = new BcfParser();
        $doc = $p->parse($conf);

        foreach($doc->contentList as $o) {
            if ($o instanceof BcfElement) {
                if (StringUtil::eqIgnorecase($o->name, "group")) {
                    Groups::initGroups($o);
                }
                elseif(StringUtil::eqIgnorecase($o->name, "member")) {
                    Groups::initMembers($o);
                }
            }
        }
    }

    public static function getGroup(string $name) : ?Groups_Group {
        return self::$allGroups[$name];
    }

    //////////////////////////////////////////////////////////////////////////
    // private methods
    //////////////////////////////////////////////////////////////////////////
    private static function initGroups($elm) : void
    {
        foreach($elm->contentList as $o) {
           if($o instanceof BcfKeyVal) {
                $g = new Groups_Group($o->key);
                Groups::$allGroups[$g->name] = $g;
                foreach(explode(" ", $o->value) as $mName) {
                    $g->add($mName);
                }
            }
        }
    }

    private static function initMembers($elm) : void
    {
        foreach($elm->contentList as $o) {
            if($o instanceof BcfKeyVal) {
                $m = new Groups_Member($o->key, $o->value);
                Groups::$allMembers[$m->name] = $m;
            }
        }
    }
}

