<?php

include_once('globals.php');

class Player
{
    private $id, $game, $token;
    protected $db;

    function __construct($db)
    {
        $this->db = $db;
        $this->id = 0;
    }

    function loadByToken($token)
    {
        if(!$token) return false;
        $this->db->prepare('SELECT * FROM `cah_user` WHERE token = :token LIMIT');
        $this->db->execute(['token' => $token]);
        $result = $this->db->fetch(PDO::FETCH_ASSOC);

        // TODO: check if more than once
        if(!$result) return false;

        $this->id = $result['id'];
        $this->game = new Game($result['game']);

        return true;
    }

    function loadByPlayerId($playerId)
    {
        $this->db->prepare('SELECT * FROM `cah_user` WHERE id = :id');
        $this->db->execute(['id' => $playerId]);
        $result = $this->db->fetch(PDO::FETCH_ASSOC);

        if(!$result) return false;

        $this->id = $result['id'];
        $this->game = new Game($result['game']);

        return true;
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
        if ($this->token) return $this->token;

        // Make sure token is unique
        $stmt = $this->db->prepare("SELECT id FROM `cah_user` WHERE token = :token LIMIT 0, 1");

        do {
            $this->token = $this->getRandomToken();
            $stmt->execute(['token' => $this->token]);
        } while ($stmt->fetch(PDO::FETCH_ASSOC) !== false);

        $stmt = $this->db->prepare('UPDATE `cah_user` SET token = :token WHERE id = :id');
        $stmt->execute(['token' => $this->token, 'id' => $this->id]);

        return $this->token;
    }

    function getRandomToken()
    {
        return substr(strtr(base64_encode(openssl_random_pseudo_bytes(16)), '+/=', '-_0'), 0, 16);
    }
}