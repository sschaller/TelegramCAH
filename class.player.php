<?php

include_once('globals.php');

class Player
{
    private $id, $game;
    protected $db;

    function __construct($db)
    {
        $this->db = $db;
        $this->id = 0;
    }

    function loadByToken($token)
    {

        $this->db->prepare('SELECT id,game FROM users WHERE token = :token LIMIT 0, 1');
        $this->db->execute(['token' => $token]);
        $result = $this->db->fetch(PDO::FETCH_ASSOC);

        if (!$result) return $this;

        $this->id = $result['id'];
        $this->game = new Game($result['game']);

        return $this;
    }

    /**
     * @param CardType $cardType
     */
    function getNewCard($cardType)
    {
        $this->db->preparse('SELECT * FROM ')
    }

    function loadByPlayerAndGame($playerId, $gameId)
    {

    }

    function generateToken()
    {

    }
}