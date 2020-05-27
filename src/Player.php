<?php

include_once('include/globals.php');

class Player
{
    public $id, $userId, $firstName, $token, $score, $joined, $done, $picks;

    /* @var $db PDO */
    private $db;

    function __construct($db)
    {
        $this->db = $db;
        $this->picks = [];
        $this->done = false;
    }

    function generateToken($new = false)
    {
        if (!$new && $this->token) return $this->token;
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

    function join($newState)
    {
        $stmt = $this->db->prepare('UPDATE `cah_player` SET joined=:joined WHERE id=:id');
        $this->joined = $newState;
        $stmt->execute(['id' => $this->id, 'joined' => $newState]);
        return true;
    }

    function setScore($score)
    {
        $this->score = $score;
        $stmt = $this->db->prepare('UPDATE `cah_player` SET score=:score WHERE id=:id');
        return $stmt->execute(['id' => $this->id, 'score' => $this->score]) !== false;
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