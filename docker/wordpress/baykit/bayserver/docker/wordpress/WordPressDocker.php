<?php

namespace baykit\bayserver\docker\wordpress;

use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\docker\base\RerouteBase;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\docker\Town;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;

class WordPressDocker extends RerouteBase {

    public $townPath;

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Docker
    ////////////////////////////////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent): void
    {
        parent::init($elm, $parent);

        $this->townPath = $parent->location;
    }

    ////////////////////////////////////////////////////////////////////////////////
    // Implements Reroute
    ////////////////////////////////////////////////////////////////////////////////
    ///
    public function reroute(Town $twn, string $uri): string
    {
        $uriParts = explode("?", $uri);
        $uri2 = $uriParts[0];
        if(!$this->match($uri2))
            return $uri;

        $relPath = substr($uri2, strlen($twn->name));
        if(StringUtil::startsWith($relPath, "/"))
            $relPath = substr($relPath, 1);

        $relParts = explode("/", $relPath);
        $checkPath = "";

        foreach($relParts as $pathItem) {
            if($checkPath != "")
                $checkPath .= DIRECTORY_SEPARATOR;
            $checkPath .= $pathItem;
            if(file_exists(SysUtil::joinPath($twn->location, $checkPath)))
                return $uri;
        }

        if(!file_exists(SysUtil::joinPath($twn->location, "/", $checkPath)))
            return $twn->name . "index.php/" . substr($uri, strlen($twn->name));
        else
            return $uri;
    }
}