<?php
namespace baykit\bayserver\docker\base;

use baykit\bayserver\BayMessage;
use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\Symbol;
use baykit\bayserver\ConfigException;

use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\util\ClassUtil;

class DockerBase implements Docker
{
    public $type = null;

    public function __toString()
    {
        return ClassUtil::localName(get_class($this));
    }

    ///////////////////////////////////////////////////////////////////////
    // Implements Docker
    ///////////////////////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        $this->type = $elm->name;

        foreach ($elm->contentList as $o) {
            if ($o instanceof BcfKeyVal) {
                try {
                    if (!$this->initKeyVal($o))
                        throw new ConfigException($o->fileName, $o->lineNo, BayMessage::get(Symbol::CFG_INVALID_PARAMETER, $o->key));
                } catch (ConfigException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    BayLog::error_e($e);
                    throw new ConfigException($o->fileName, $o->lineNo, BayMessage::get(Symbol::CFG_INVALID_PARAMETER_VALUE, $o->key));
                }
            } else {
                try {
                    $dkr = BayServer::$dockers->createDocker($o, $this);
                } catch (ConfigException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    BayLog::error_e($e);
                    throw new ConfigException($o->fileName, $o->lineNo, BayMessage::get(Symbol::CFG_INVALID_DOCKER, $o->name));
                }

                if (!$this->initDocker($dkr))
                    throw new ConfigException($o->fileName, $o->lineNo, BayMessage::get(Symbol::CFG_INVALID_DOCKER, $o->name));
            }
        }
    }

    ///////////////////////////////////////////////////////////////////////
    // Base methods
    ///////////////////////////////////////////////////////////////////////

    public function initDocker(Docker $dkr) : bool
    {
        return false;
    }

    public function initKeyVal(BcfKeyVal $kv) : bool
    {
        $key = strtolower($kv->key);
        if($key == "docker")
            return true;
        else
            return false;
    }

}