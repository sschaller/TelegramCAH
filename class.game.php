<?php

include_once('globals.php');
include_once('class.player.php');

class Game
{
    const BlackCard = 0;
    const WhiteCard = 1;

    /* @var $player Player */
    public $chatId, $player, $blackCard, $whiteCards;

    /* @var $db PDO */
    private $db;

    function __construct($db)
    {
        $this->db = $db;
        $this->chatId = 0;
    }

    function loadGameState()
    {
        if (!$this->chatId) return false;



        return true;
    }

    function loadWithPlayerToken($token)
    {
        if(!$token) return false;
        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE token = :token');
        $stmt->execute(['token' => $token]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$player)
        {
            logEvent("Player not found for token \"{$token}\"");
            return false;
        }

        $this->player = new Player($this->db, $player);

        return true;
    }

    function startGameForChatAndUser($chatId, $userId, $firstName, $allowCreate = false)
    {
        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE chatId = :chatId AND userId = :userId');
        $stmt->execute(['chatId' => $chatId, 'userId' => $userId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player)
        {
            $this->player = new Player($this->db, $player);
            $this->chatId = $chatId;
            return true;
        }

        $stmt = $this->db->prepare('SELECT id FROM `cah_player` WHERE chatId = :chatId');
        $stmt->execute(['chatId' => $chatId]);

        $isFirst = !$stmt->fetch(PDO::FETCH_ASSOC);

        // Not allowed to start a new game here if first
        if (!$allowCreate && $isFirst)
        {
            logEvent("Game could not be created for chat \"{$chatId}\", user \"{$firstName}\"");
            return false;
        }

        $stmt = $this->db->prepare('INSERT INTO `cah_player` (userId, firstName, chatId, turn) VALUES (:userId, :firstName, :chatId, :turn)');
        $stmt->execute(['chatId' => $chatId, 'userId' => $userId, 'firstName' => $firstName, 'turn' => $isFirst]);

        $player = [
            'id' => $this->db->lastInsertId(),
            'userId' => $userId,
            'firstName' => $firstName,
            'token' => '',
            'turn' => $isFirst
        ];
        $this->player = new Player($this->db, $player);

        return true;
    }
}