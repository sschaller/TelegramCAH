<?php

include_once('globals.php');
include_once('class.player.php');

define('WHITECARDS_NUM', 10);

class Game
{
    const BlackCard = 0;
    const WhiteCard = 1;

    /* @var $player Player */
    public $player;

    public $chatId, $blackCard, $whiteCards, $allCards, $done;

    /* @var $db PDO */
    protected $db;

    /* @var $players Player[] */
    private $players;

    function __construct($db)
    {
        $this->db = $db;
        $this->chatId = 0;
    }

    function loadGameState()
    {
        if (!$this->chatId) return false;

        $message = null;

        $this->allCards = $this->getAllCurrentCards();

        $this->done = false;
        $this->blackCard = null;
        $this->whiteCards = [];

        foreach ($this->allCards as $card)
        {
            if ($card['type'] == self::BlackCard)
            {
                $this->blackCard = $card;
            } else if ($card['player'] == $this->player->id)
            {
                $this->whiteCards[] = $card;

                // Any card pick > 0 -> we are already done
                if ($card['pick'] > 0) $this->done = true;
            }
        }

        // No black card > first one to join
        if (!$this->blackCard)
        {
            $this->startRound(1, $this->player);
            $message = ['text' => 'New Round: 1'];
        }

        if (count($this->whiteCards) < WHITECARDS_NUM)
        {
            $this->whiteCards = array_merge($this->whiteCards, $this->pickCards($this->player->id,self::WhiteCard, WHITECARDS_NUM - count($this->whiteCards)));
        }

        return $message;
    }

    function startRound($round, $player)
    {
        $stmt = $this->db->prepare('UPDATE `cah_ref` SET `current`=FALSE WHERE `player` IN (SELECT `id` FROM `cah_player` WHERE `chatId` = :chatId)');
        $stmt->execute(['chatId' => $this->chatId]);

        // Pick random black card
        $this->blackCard = $this->pickCards($player->id, self::BlackCard, 1)[0];

        foreach ($this->players as $p)
        {
            if ($p->round > 0) $p->setRound($round);
        }
    }

    function pickCards($playerId, $cardType, $limit)
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
            $stmt->execute(['card' => $card['id'], 'player' => $playerId]);
            $card['player'] = $this->player->id;
        }
        unset($card);

        return $cards;
    }

    function playCards($refIds)
    {
        // Check if number of picks match required number from black card
        if (count($refIds) != $this->blackCard['req']) return false;

        // Check if we got all these cards on our hands
        $unused = array_filter($this->whiteCards, function($e)
        {
            return $e['pick'] == 0;
        });
        $whiteCardIds = array_map(function($e)
        {
            return $e['id'];
        }, $this->whiteCards);

        $failed = false;
        foreach ($refIds as $refId)
        {
            if (!in_array($refId, $whiteCardIds))
            {
                $failed = true;
                break;
            }
        }

        if ($failed) return false;

        // Set pick number and current for each card

        $stmt = $this->db->prepare('UPDATE `cah_ref` SET `pick`=:pick, `current`=TRUE WHERE id=:id');
        foreach ($refIds as $i => $refId)
        {
            $stmt->execute(['pick' => $i+1, 'id' => $refId]);
        }

        foreach ($this->whiteCards as &$whiteCard)
        {
            $pick = array_search($whiteCard['id'], $refIds);
            if ($pick === false) continue;
            $whiteCard['pick'] = $pick + 1;
            $whiteCard['curr'] = true;
        }
        unset ($whiteCard);

        return true;
    }

    function loadGame($chatId, $userId)
    {
        $this->chatId = $chatId;

        $this->loadPlayers($userId);

        // Return if game was found
        return (count($this->players) > 0);
    }

    function addPlayer($userId, $firstName)
    {
        $stmt = $this->db->prepare('INSERT INTO `cah_player` (userId, firstName, chatId) VALUES (:userId, :firstName, :chatId)');
        $stmt->execute(['chatId' => $this->chatId, 'userId' => $userId, 'firstName' => $firstName]);

        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE id = :id');
        $stmt->execute(['id' => $this->db->lastInsertId()]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->player = new Player($this->db, $player);
        $this->players[] = $this->player;
    }

    function join()
    {
        if ($this->player->round > 0) return;

        $maxRound = 0;
        foreach ($this->players as $player)
        {
            if ($player->round > $maxRound) $maxRound = $player->round;
        }

        // min round for joined players
        if ($maxRound < 1) $maxRound = 1;

        $this->player->setRound($maxRound);
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

        $this->loadGame($player['chatId'], $player['userId']);

        return true;
    }

    function startGameForChatAndUser($chatId, $userId, $firstName, $allowCreate = false)
    {
        $found = $this->loadGame($chatId, $userId);

        if (!$found && !$allowCreate)
        {
            logEvent("Game could not be created for chat \"{$chatId}\", user \"{$firstName}\"");
            return false;
        }

        if (!$this->player)
        {
            $this->addPlayer($userId, $firstName);
        }

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

    function loadPlayers($userId)
    {
        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE chatId = :chatId');
        $stmt->execute(['chatId' => $this->chatId]);

        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->players = [];
        foreach ($players as $player)
        {
            $tmp = new Player($this->db, $player);
            $this->players[] = $tmp;
            if ($tmp->userId == $userId) $this->player = $tmp;
        }
    }

    function getAllCurrentCards()
    {
        $stmt = $this->db->prepare('SELECT ref.id, ref.player, ref.pick, ref.current as curr, card.id as cardId, card.content, card.pick as req, card.type FROM cah_ref as ref LEFT JOIN cah_card as card ON ref.card=card.id WHERE ref.pick = 0 OR ref.current = TRUE AND ref.player IN (SELECT id FROM `cah_player` WHERE chatId = :chatId)');
        $stmt->execute(['chatId' => $this->chatId]);

        // All still to use cards for all players in the game
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function getCurrentCardsForPlayer($playerId)
    {
        return array_filter($this->allCards, function($card) use ($playerId)
        {
            if ($card['player'] != $playerId) return false;
            if ($card['current'] != true) return false;
            return true;
        });
    }
}