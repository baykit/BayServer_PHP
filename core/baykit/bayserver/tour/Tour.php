<?php
namespace baykit\bayserver\tour;

use baykit\bayserver\BayLog;
use baykit\bayserver\BayServer;
use baykit\bayserver\docker\base\InboundShip;
use baykit\bayserver\HttpException;
use baykit\bayserver\Sink;
use baykit\bayserver\util\Counter;
use baykit\bayserver\util\HttpStatus;
use baykit\bayserver\util\Reusable;
use Couchbase\BaseException;

class Tour implements Reusable {

    const STATE_UNINITIALIZED = 0;
    const STATE_PREPARING = 1;
    const STATE_RUNNING = 2;
    const STATE_ABORTED = 3;
    const STATE_ENDED = 4;
    const STATE_ZOMBIE = 5;

    # class variables
    public static $oid_counter;
    public static $tour_id_counter;

    const TOUR_ID_NOCHECK = -1;
    const INVALID_TOUR_ID = 0;

    public $ship;
    public $shipId;
    public $objectId; // object id

    public $tourId; // tour id
    public $errorHandling;
    public $town;
    public $city;
    public $club;

    public $req;
    public $res;

    public $interval;
    public $isSecure;
    public $state = self::STATE_UNINITIALIZED;

    public $error;

    public function __construct()
    {
        $this->objectId = Tour::$oid_counter->next();
        $this->req = new TourReq($this);
        $this->res = new TourRes($this);
    }

    public function id() : int
    {
        return $this->tourId;
    }

    public function __toString()
    {
        return "{$this->ship} tour#{$this->tourId}/{$this->objectId} [key={$this->req->key}]";
    }



    //////////////////////////////////////////////////////////////////
    /// Implements Reusable
    //////////////////////////////////////////////////////////////////

    public function reset(): void
    {
        $this->city = null;
        $this->town = null;
        $this->club = null;
        $this->errorHandling = false;
        $this->tourId = Tour::INVALID_TOUR_ID;
        $this->interval = 0;
        $this->isSecure = false;
        $this->changeState(Tour::TOUR_ID_NOCHECK, Tour::STATE_UNINITIALIZED);
        $this->error = null;
        $this->req->reset();
        $this->res->reset();
    }

    //////////////////////////////////////////////////////////////////
    /// Other methods
    //////////////////////////////////////////////////////////////////

    public function init(int $key, InboundShip $sip)
    {
        if($this->isInitialized())
            throw new Sink("%s Tour already initialized: %s", $this->ship, $this);

        $this->ship = $sip;
        $this->shipId = $sip->id();
        $this->tourId = Tour::$tour_id_counter->next();
        $this->req->key = $key;

        $this->req->init($key);
        $this->res->init();

        $this->changeState(Tour::TOUR_ID_NOCHECK, self::STATE_PREPARING);
        BayLog::debug("%s initialized", $this);
    }

    public function go() : void
    {
        $this->changeState(Tour::TOUR_ID_NOCHECK, self::STATE_RUNNING);

        $this->city = $this->ship->portDocker->findCity($this->req->reqHost);
        if($this->city == null)
            $this->city = BayServer::findCity($this->req->reqHost);
        BayLog::debug("%s GO TOUR! ...( ^_^)/: city=%s url=%s", $this, $this->req->reqHost, $this->req->uri);

        if ($this->city === null)
            throw new HttpException(HttpStatus::NOT_FOUND, $this->req->uri);
        else {
            try {
                $this->city->enter($this);
            } catch (HttpException $e) {
                BayLog::error_e($e);
                throw $e;
            } catch (Sink $e) {
                throw $e;
            } catch (\Exception $e) {
                BayLog::error_e($e);
                throw new HttpException(HttpStatus::INTERNAL_SERVER_ERROR, $e->getMessage());
            }
        }
    }

    public function isValid() : bool
    {
        return $this->state == self::STATE_PREPARING || $this->state == self::STATE_RUNNING;
    }

    public function isPreparing() : bool
    {
        return $this->state == self::STATE_PREPARING;
    }

    public function isRunning() : bool
    {
        return $this->state == self::STATE_RUNNING;
    }

    public function isAborted() : bool
    {
        return $this->state == self::STATE_ABORTED;
    }

    public function isZombie() : bool
    {
        return $this->state == self::STATE_ZOMBIE;
    }

    public function isEnded() : bool
    {
        return $this->state == self::STATE_ENDED;
    }

    public function isInitialized() : bool
    {
        return $this->state != self::STATE_UNINITIALIZED;
    }

    public function changeState(int $chkId, int $new_state) : void
    {
        BayLog::trace("%s change state: %s", $this, $new_state);
        $this->checkTourId($chkId);
        $this->state = $new_state;
    }

    public function checkTourId(int $chkId) : void
    {
        if ($chkId == Tour::TOUR_ID_NOCHECK)
            return;

        if (!$this->isInitialized())
            throw new Sink("%s Tour not initialized", $this);

        if ($chkId != $this->tourId)
            throw new Sink("%s Invalid tours id: %d", $this, $chkId);
    }

}

Tour::$oid_counter = new Counter();
Tour::$tour_id_counter = new Counter();