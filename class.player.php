<?php

class Player
{
    protected $connection;

    function __construct($connection)
    {
        $this->connection = $connection;
    }

    function loadByToken($token)
    {

    }

    function loadByPlayerAndGame($playerId, $gameId)
    {

    }

    function generateToken()
    {

    }
}