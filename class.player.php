<?php

include_once('globals.php');

class Player
{
    private $id, $firstName, $token;

    /* @var $db PDO */
    private $db;

    function __construct($db, $player)
    {
        $this->db = $db;
        $this->id = $player['id'];
        $this->firstName = $player['firstName'];
        $this->token = $player['token'];
    }

    function generateToken()
    {
        $stmt = $this->db->prepare("SELECT id FROM `cah_player` WHERE token = :token");

        // Check if generated token is unique
        do {
            $this->token = self::getRandomToken();
            $stmt->execute(['token' => $this->token]);
        } while ($stmt->fetch(PDO::FETCH_ASSOC) !== false);

        $stmt = $this->db->prepare('UPDATE `cah_player` SET token = :token WHERE id = :id');
        $stmt->execute(['token' => $this->token, 'id' => $this->id]);

        return $this->token;
    }

    static function getRandomToken()
    {
        return substr(strtr(base64_encode(openssl_random_pseudo_bytes(16)), '+/=', '-_0'), 0, 16);
    }
}