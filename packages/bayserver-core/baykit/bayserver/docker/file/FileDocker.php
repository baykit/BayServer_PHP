<?php
namespace baykit\bayserver\docker\file;



use baykit\bayserver\bcf\BcfElement;
use baykit\bayserver\bcf\BcfKeyVal;
use baykit\bayserver\docker\base\ClubBase;
use baykit\bayserver\docker\Docker;
use baykit\bayserver\HttpException;
use baykit\bayserver\tour\Tour;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\StringUtil;
use baykit\bayserver\util\SysUtil;
use baykit\bayserver\util\URLDecoder;

class FileDocker extends ClubBase
{
    public $listFiles = false;

    ///////////////////////////////////////////////////////////////////////
    // Implements Docker
    ///////////////////////////////////////////////////////////////////////

    public function init(BcfElement $elm, ?Docker $parent) : void
    {
        parent::init($elm, $parent);
    }

    ///////////////////////////////////////////////////////////////////////
    // Implements DockerBase
    ///////////////////////////////////////////////////////////////////////

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

    ///////////////////////////////////////////////////////////////////////
    // Implements Club
    ///////////////////////////////////////////////////////////////////////

    public function arrive(Tour $tur) : void
    {
        $relPath = $tur->req->rewrittenURI !== null ? $tur->req->rewrittenURI : $tur->req->uri;
        if(!StringUtil::isEmpty($tur->town->name))
            $relPath = substr($relPath, strlen($tur->town->name));
        $pos = strpos($relPath, '?');
        if($pos !== false)
            $relPath = substr($relPath, 0, $pos);

        $relPath = URLDecoder::decode($relPath, $tur->req->charset);

        $real = SysUtil::joinPath($tur->town->location, $relPath);

        if(is_dir($real) && $this->listFiles) {
            //DirectoryTrain train = new DirectoryTrain(tur, real);
            //train.startTour();
            throw new HttpException(HttpStatus::NOT_FOUND, $relPath);
        }
        else {
            $handler = new FileContentHandler($real);
            $tur->req->setContentHandler($handler);
        }
    }

}