<?php

include_once('globals.php');
include_once('class.player.php');
include_once('class.card.php');

define('WHITECARDS_NUM', 10);

interface iMessages
{
    function sendGameMessage($game, $messageType, $details);
}

class Game
{
    public $id, $chatId, $round, $messageId;

    /* @var Card $blackCard */
    public $blackCard;

    /* @var Card[] $whiteCards */
    public $whiteCards;

    /* @var Card[] $allCards */
    public $allCards;

    /* @var PDO $db */
    protected $db;

    /* @var Player $player */
    public $player;

    /* @var Player[] $players */
    private $players;

    /* @var iMessages $messageInterface */
    private $messageInterface;

    function __construct($db, $messageInterface)
    {
        $this->db = $db;
        $this->messageInterface = $messageInterface;
        $this->id = 0;
    }

    function loadGameState()
    {
        if (!$this->chatId) return false;

        $message = null;

        $this->allCards = $this->getAllCurrentCards();

        $this->blackCard = $this->getBlackCard();
        $this->whiteCards = $this->getWhiteCardsForCurrentPlayer();

        // No black card > first one to join
        if (!$this->blackCard)
        {
            $this->startRound(1, $this->player);
        }

        foreach ($this->players as $player)
        {
            $player->picks = $this->getPickedCardsForPlayer($player);
            if (count($player->picks) == $this->blackCard->required) $player->done = true;
        }

        if (count($this->whiteCards) < WHITECARDS_NUM)
        {
            $this->whiteCards = array_merge($this->whiteCards, $this->pickCards($this->player->id, CardType::White, WHITECARDS_NUM - count($this->whiteCards)));
        }

        return $message;
    }

    function startRound($round, $player)
    {
        $this->round = $round;

        $stmt = $this->db->prepare('UPDATE `cah_ref` SET `current`=FALSE WHERE `player` IN (SELECT `id` FROM `cah_player` WHERE `chat`=:chat)');
        $stmt->execute(['chat' => $this->id]);

        // Pick random black card
        $this->blackCard = $this->pickCards($player->id, CardType::Black, 1)[0];

        $this->sendMessage(MessageType::NewRound, [
            'dealer' => $player->firstName,
            'cardText' => str_replace('_', '___', $this->blackCard['content']),
        ]);
    }

    function sendMessage($messageType, $details = [])
    {
        $this->messageInterface->sendGameMessage($this, $messageType, $details);
    }

    function pickCards($playerId, $cardType, $limit)
    {
        $stmt = $this->db->prepare('SELECT id FROM `cah_card` WHERE `type` = :cardType AND `id` NOT IN (SELECT `id` FROM `cah_ref` WHERE `player` IN (SELECT `id` FROM `cah_player` WHERE `chat`=:chat)) ORDER BY RAND() LIMIT 0,:limit');
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':cardType', $cardType, PDO::PARAM_BOOL);
        $stmt->bindParam(':chat', $this->id, PDO::PARAM_INT);
        $stmt->execute();
        $cardIds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare('INSERT INTO `cah_ref` (card, player) VALUES (:card, :player)');
        $cards = [];
        foreach ($cardIds as $cardId)
        {
            $stmt->execute(['card' => $cardId['id'], 'player' => $playerId]);
            $cards[] = $this->getCardWithId($this->db->lastInsertId());
        }

        return $cards;
    }

    function playCards($refIds)
    {
        // Select all cards that were picked from our unused white cards
        $pickedCards = array_filter($this->whiteCards, function($card) use ($refIds)
        {
            if ($card->pick > 0) return false;
            if (!in_array($card->id, $refIds)) return false;
            return true;
        });

        // Check if the number of unused & picked white cards is the same as the required one from the black card
        if (count($pickedCards) != $this->blackCard['req']) return false;

        // Sanity Test ok. Set cards to correct state


        $stmt = $this->db->prepare('UPDATE `cah_ref` SET `pick`=:pick, `current`=TRUE WHERE id=:id');
        foreach ($pickedCards as $card)
        {
            $pickIndex = array_search($card->id, $refIds);
            $stmt->execute(['pick' => $pickIndex+1, 'id' => $card->id]);
            $card->pick = $pickIndex + 1;
            $card->current = true;
        }

        // Update player state
        $this->player->done = true;
        $this->player->picks = $this->getPickedCardsForPlayer($this->player);

        $this->checkRoundState();

        return true;
    }

    function startGame($chatId, $args = [])
    {
        $found = $this->loadGame($chatId);
        if ($found) return false;

        $stmt = $this->db->prepare('INSERT INTO `cah_game` (chatId) VALUES (:chatId)');
        $stmt->execute(['chatId' => $chatId]);
        $this->loadGame($chatId);
    }

    function loadGame($chatId)
    {
        $stmt = $this->db->prepare('SELECT * FROM `cah_game` WHERE chatId=:chatId');
        $stmt->execute(['chatId' => $chatId]);
        $chat = $stmt->fetch(PDO::FETCH_ASSOC);

        // Game was not found for chatId
        if (!$chat) return false;

        $this->id = $chat['id'];
        $this->chatId = $chat['chatId'];
        $this->round = $chat['round'];
        $this->messageId = $chat['messageId'];

        return true;
    }

    function addPlayer($userId, $firstName)
    {
        $stmt = $this->db->prepare('INSERT INTO `cah_player` (userId, firstName, chat) VALUES (:userId, :firstName, :chat)');
        $stmt->execute(['chat' => $this->id, 'userId' => $userId, 'firstName' => $firstName]);

        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE id = :id');
        $stmt->execute(['id' => $this->db->lastInsertId()]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->player = new Player($this->db, $player);
        $this->players[] = $this->player;
    }

    function checkRoundState()
    {
        // called after a player picks white cards. Check if all players submitted their cards and num players > 3

        /* @var string[] $waiting */
        $waiting = [];

        foreach ($this->players as $player)
        {
            // Ignore players that haven't joined yet
            if (!$player->joined) continue;
            if ($player->id == $this->blackCard->player) continue;

            // Not done yet
            if (!$player->done)
            {
                $waiting[] = $player->firstName;
            }
        }

        if (count($waiting) > 0)
        {
            $this->sendMessage(MessageType::RoundUpdate, [
                'waiting' => $waiting
            ]);
            return false;
        }

        // Not waiting anymore. Player with Black Card has to pick winner now
        $this->sendMessage(MessageType::PickWinner, )

    }

    function join()
    {
        $success = $this->player->join();
        if (!$success) return false;

        $this->sendMessage(MessageType::RoundUpdate);
    }

    function leave()
    {
        if (!$this->player->joined) return false;
        if ($this->blackCard->player == $this->player->id)
        {
            // can't leave for this round..
        }
    }

    function loadWithPlayerToken($token)
    {
        if(!$token) return false;

        $stmt = $this->db->prepare('SELECT p.userId, c.chatId FROM `cah_player` p LEFT JOIN `cah_game` c ON p.chat = c.id WHERE p.token = :token');
        $stmt->execute(['token' => $token]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$player)
        {
            logEvent("Player not found for token \"{$token}\"");
            return false;
        }

        $this->loadGame($player['chatId']);
        $this->loadPlayers($player['userId']);

        return true;
    }

    function loadForChatAndUser($chatId, $userId, $firstName)
    {
        $found = $this->loadGame($chatId);
        if (!$found) return false;

        $this->loadPlayers($userId);

        if (!$this->player)
        {
            $this->addPlayer($userId, $firstName);
        }

        return true;
    }

    function delete()
    {
        // can't stop a game without a chat Id
        if (!$this->id) return false;

        $stmt = $this->db->prepare('DELETE FROM `cah_game` WHERE id = :id');
        return $stmt->execute(['id' => $this->id]);
    }

    function loadPlayers($userId)
    {
        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE chat = :chat');
        $stmt->execute(['chat' => $this->id]);

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
        $stmt = $this->db->prepare('SELECT ref.id, ref.player, ref.pick, ref.current, card.content, card.pick as `required`, card.type FROM cah_ref as ref LEFT JOIN cah_card as card ON ref.card=card.id WHERE ref.pick = 0 OR ref.current = TRUE AND ref.player IN (SELECT id FROM `cah_player` WHERE chat = :chat)');
        $stmt->execute(['chat' => $this->id]);

        // All still to use cards for all players in the game
        return $stmt->fetchAll(PDO::FETCH_CLASS, 'Card');
    }

    function getCardWithId($refId)
    {
        $stmt = $this->db->prepare('SELECT ref.id, ref.player, ref.pick, ref.current, card.content, card.pick as `required`, card.type FROM cah_ref as ref LEFT JOIN cah_card as card ON ref.card=card.id WHERE ref.id = :id');
        $stmt->execute(['id' => $refId]);

        // All still to use cards for all players in the game
        return $stmt->fetch(PDO::FETCH_CLASS, 'Card');
    }

    function getPickedCardsForPlayer($player)
    {
        $playerId = $player->id;
        return array_filter($this->allCards, function($card) use ($playerId)
        {
            if ($card['type'] != CardType::White) return false;
            if ($card['player'] != $playerId) return false;
            if ($card['current'] != true) return false;
            return true;
        });
    }

    function getWhiteCardsForCurrentPlayer()
    {
        $playerId = $this->player->id;
        return array_filter($this->allCards, function ($card) use ($playerId)
        {
            if ($card['type'] != CardType::White) return false;
            if ($card['player'] != $playerId) return false;
            return true;
        });
    }

    function getBlackCard()
    {
        $cards = array_filter($this->allCards, function($card)
        {
            if ($card['type'] != CardType::Black) return false;
            return true;
        });
        return count($cards) > 0 ? $cards[0] : null;
    }

    function setMessageId($messageId)
    {
        if (!$messageId) return false;

        $stmt = $this->db->prepare('UPDATE `cah_game` SET messageId=:messageId WHERE id=:id');
        $success = $stmt->execute(['messageId' => $messageId, 'id' => $this->id]);
        if ($success)
        {
            $this->messageId = $messageId;
            return true;
        }
        return false;
    }
}