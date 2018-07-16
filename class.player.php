<?php

include_once('globals.php');

class Player
{
    public $id, $token, $score;

    protected $round;

    private $firstName;

    /* @var $db PDO */
    private $db;

    function __construct($db, $player)
    {
        $this->db = $db;
        $this->id = $player['id'];
        $this->firstName = $player['firstName'];
        $this->token = $player['token'];
        $this->round = $player['round'];
        $this->score = $player['score'];
    }

    function generateToken()
    {
        if ($this->token) return $this->token;
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

    function join()
    {
        if ($this->round > 0) return false;
        $stmt = $this->db->prepare('UPDATE `cah_player` SET round=1 WHERE id = :id');
        return $stmt->execute(['id' => $this->id]) !== false;
    }

    function delete()
    {
        $stmt = $this->db->prepare('DELETE `cah_player` WHERE id = :id');
        return $stmt->execute(['id' => $this->id]) !== false;
    }

    static function getRandomToken()
    {
        return substr(strtr(base64_encode(openssl_random_pseudo_bytes(16)), '+/=', '-_0'), 0, 16);
    }
}