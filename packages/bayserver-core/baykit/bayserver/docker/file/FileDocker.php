<?php
namespace baykit\bayserver\docker\file;


use baykit\bayserver\BayLog;
use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\base\ClubBase;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;
use baykit\bayserver\util\URLDecoder;

/**
 * FileDocker after the Direct Boarding / Barge refactor.
 *
 * The FileStore management that used to live here has moved to the
 * harbor-/city-/town-level Barge Docker, so this class only resolves
 * the filesystem path and hands the tour off to FileContentHandler.
 */
class FileDocker extends ClubBase
{
    public bool $listFiles = false;

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        parent::init($elm, $parent);
    }

    public function initKeyVal(BcfKeyVal $kv) : bool
    {
        switch (strtolower($kv->key)) {
            default:
                return parent::initKeyVal($kv);

            case "listfiles":
                $this->listFiles = StringUtil::parseBool($kv->value);
                break;
        }
        return true;
    }

    public function arrive(Tour $tur) : void
    {
        $relPath = $tur->req->rewrittenURI !== null ? $tur->req->rewrittenURI : $tur->req->uri;
        if(!StringUtil::isEmpty($tur->town->name))
            $relPath = substr($relPath, strlen($tur->town->name));
        $pos = strpos($relPath, '?');
        if($pos !== false)
            $relPath = substr($relPath, 0, $pos);

        try {
            $relPath = URLDecoder::decode($relPath, $tur->req->charset);
        }
        catch (\Throwable $e) {
            BayLog::error("Cannot decode path: %s: %s", $relPath, $e);
        }

        $real = SysUtil::joinPath($tur->town->location, $relPath);

        $handler = new FileContentHandler($tur, $real, $tur->res->charset, $this->listFiles);
        $tur->req->setContentHandler($handler);
    }
}
