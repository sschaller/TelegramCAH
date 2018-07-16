<?php

include_once('globals.php');
include_once('class.player.php');

define('WHITECARDS_NUM', 10);

class Game
{
    const BlackCard = 0;
    const WhiteCard = 1;

    /* @var $player Player */
    public $chatId, $player, $blackCard, $whiteCards;

    /* @var $db PDO */
    protected $db, $players;

    function __construct($db)
    {
        $this->db = $db;
        $this->chatId = 0;
    }

    function loadGameState()
    {
        if (!$this->chatId) return false;

        $stmt = $this->db->prepare('SELECT `cah_ref`.*, `cah_card`.`type`, `cah_card`.`content`, `cah_card`.`pick` FROM `cah_ref` LEFT JOIN cah_card ON cah_card.id = card WHERE used = FALSE AND player IN (SELECT id FROM `cah_player` WHERE chatId = :chatId)');
        $stmt->execute(['chatId' => $this->chatId]);

        // All still to use cards for all players in the game
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->blackCard = null;
        $this->whiteCards = [];

        foreach ($cards as $card)
        {
            if ($card['type'] == self::BlackCard)
            {
                $this->blackCard = $card;
            } else if ($card['player'] == $this->player->id)
            {
                $this->whiteCards[] = $card;
            }
        }

        if (!$this->blackCard) $this->blackCard = $this->pickCards(self::BlackCard, 1)[0];
        if (count($this->whiteCards) < WHITECARDS_NUM)
        {
            $this->whiteCards = array_merge($this->whiteCards, $this->pickCards(self::WhiteCard, WHITECARDS_NUM - count($this->whiteCards)));
        }

        return true;
    }

    function pickCards($cardType, $limit)
    {
        $stmt = $this->db->prepare('SELECT * FROM `cah_card` WHERE `type` = :cardType AND `id` NOT IN (SELECT `id` FROM `cah_ref` WHERE `player` IN (SELECT `id` FROM `cah_player` WHERE `chatId` = :chatId)) ORDER BY RAND() LIMIT 0,:limit');
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':cardType', $cardType, PDO::PARAM_BOOL);
        $stmt->bindParam(':chatId', $this->chatId, PDO::PARAM_INT);
        $stmt->execute();
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare('INSERT INTO `cah_ref` (card, player) VALUES (:card, :player)');
        foreach ($cards as &$card)
        {
            $stmt->execute(['card' => $card['id'], 'player' => $this->player->id]);
            $card['player'] = $this->player->id;
        }
        unset($card);

        return $cards;
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
        $this->chatId = $player['chatId'];

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
        $this->chatId = $chatId;

        return true;
    }

    function delete()
    {
        // can't stop a game without a chat Id
        if (!$this->chatId) return false;

        // Remove all players in the game
        $stmt = $this->db->prepare('DELETE FROM `cah_player` WHERE chatId = :chatId');
        return $stmt->execute(['chatId' => $this->chatId]);
    }

    function loadPlayers()
    {
        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE chatId = :chatId');
        $stmt->execute(['chatId' => $this->chatId]);

        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->players = [];
        foreach ($players as $player)
        {
            $this->players[] = new Player($this->db, $player);y
        }

    }

    function getAllCurrentCards()
    {
        $stmt = $this->db->prepare('SELECT ref.id, ref.player, ref.pick, card.id as cardId, card.content, card.pick as req, card.type FROM cah_ref as ref LEFT JOIN cah_card as card ON ref.card=card.id WHERE ref.pick = 0 OR ref.current = TRUE AND ref.player IN (SELECT id FROM `cah_player` WHERE chatId = :chatId)');
        $stmt->execute(['chatId' => $this->chatId]);

        // All still to use cards for all players in the game
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}