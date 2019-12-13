<?php
namespace App;

class Car {
    public $color;

    public function __construct(Color $color)
    {
        $this->color = $color;
    }

    public function getColor()
    {
        return $this->color->getColor();
    }
}