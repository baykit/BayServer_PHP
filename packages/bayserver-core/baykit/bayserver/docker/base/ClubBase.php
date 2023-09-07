<?php
namespace baykit\bayserver\docker\base;

use baykit\bayserver\docker\Club;

use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\util\StringUtil;

abstract class ClubBase extends DockerBase implements Club
{
    public $fileName;
    public $extension;
    public $charset;
    public $decodePathInfo = true;

    ///////////////////////////////////////////////////////////////////////
    // Implements Docker
    ///////////////////////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        parent::init($elm, $parent);

        $p = strrpos($elm->arg, '.');
        if ($p === false) {
            $this->fileName = $elm->arg;
            $this->extension = null;
        }
        else {
            $this->fileName = substr($elm->arg, 0, $p);
            $this->extension = substr($elm->arg, $p+ 1);
        }
    }

    ///////////////////////////////////////////////////////////////////////
    // Implements DockerBase
    ///////////////////////////////////////////////////////////////////////

    public function initKeyVal(BcfKeyVal $kv) : bool
    {
        switch(strtolower($kv->key)) {
            default:
                return parent::initKeyVal($kv);

            case "decodepathinfo":
                $this->decodePathInfo = StringUtil::parseBool($kv->value);
                break;
            case "charset":
                $cs = $kv->value;
                if(StringUtil::isSet($cs))
                    $this->charset = $cs;
                break;
        }
        return true;
    }

    ///////////////////////////////////////////////////////////////////////
    // Implements Club
    ///////////////////////////////////////////////////////////////////////


    public function matches(string $fname): bool
    {
        // check club
        $pos = strpos($fname, ".");
        if($pos === false) {
            // fname has no extension
            if($this->extension !== null)
                return false;

            if($this->fileName == "*")
                return true;

            return $fname == $this->fileName;
        }
        else {
            //fname has extension
            if($this->extension === null)
                return false;

            $nm = substr($fname, 0, $pos);
            $ext = substr($fname, $pos + 1);

            if($this->extension != "*" && $ext != $this->extension)
                return false;

            if($this->fileName == "*")
                return true;
            else
                return $nm == $this->fileName;
        }
    }

}