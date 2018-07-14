<?php

class Game
{
    public $id;

    function __construct($game)
    {
        $this->id = $game['id'];
    }
}