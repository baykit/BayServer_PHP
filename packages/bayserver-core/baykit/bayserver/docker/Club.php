<?php
namespace baykit\bayserver\docker;

use baykit\bayserver\docker\Docker;
use baykit\bayserver\tour\Tour;

interface Club extends Docker
{
    public function matches(string $fname): bool;
    public function arrive(Tour $tur);
}