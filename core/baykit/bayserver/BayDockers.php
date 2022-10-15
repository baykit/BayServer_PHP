<?php
namespace baykit\bayserver;

use baykit\bayserver\BayException;
use baykit\bayserver\BayMessage;
use baykit\bayserver\Symbol;

use baykit\bayserver\bcf\BcfParser;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\util\StringUtil;

class BayDockers
{
    public $docker_map;

    public function init($conf_file)
    {
        $this->docker_map = [];
        $p = new BcfParser();
        $doc = $p->parse($conf_file);

        foreach ($doc->contentList as $obj) {
            if ($obj instanceof BcfKeyVal) {
                $this->docker_map[$obj->key] = $obj->value;
            }
        }
    }

    public function createDocker(BcfElement $elm, ?Docker $parent): Docker
    {
        $alias_name = $elm->getValue("docker");
        $d = $this->createDockerByAlias($elm->name, $alias_name);
        $d->init($elm, $parent);
        return $d;
    }

    private function createDockerByAlias($category, $alias_name) : Docker
    {
        if (StringUtil::isEmpty($alias_name))
            $key = $category;
        else
            $key = $category . ":" . $alias_name;

        if (!array_key_exists($key, $this->docker_map))
            throw new BayException(BayMessage::get(Symbol::CFG_DOCKER_NOT_FOUND, $key));
        $class_name = $this->docker_map[$key];

        $class_name = str_replace(".", "\\", $class_name);
        $cls = new \ReflectionClass($class_name);
        return $cls->newInstance();
    }
}