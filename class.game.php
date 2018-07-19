<?php

include_once('globals.php');
include_once('class.player.php');
include_once('class.card.php');

define('WHITECARDS_NUM', 10);

interface iMessages
{
    function sendMessage($chatId, $text, $replyTo = false);
    function sendGame($game, $silent = false);
    function editGameMessage($game, $text);
}

class Game
{
    /* @var PDO $db */
    protected $db;

    /* @var int $id */
    public $id;

    /* @var int $chatId */
    public $chatId;

    /* @var int $round */
    public $round;

    /* @var int $maxRounds */
    public $maxRounds;

    /* @var int $messageId */
    public $messageId;

    /* @var Card $blackCard */
    public $blackCard;

    /* @var Card[] $whiteCards */
    public $whiteCards;

    /* @var Card[] $allCards */
    public $allCards;

    /* @var Player[] $players */
    private $players;

    /* @var Player $player */
    public $player;

    /* @var iMessages $messageInterface */
    private $messageInterface;

    function __construct($db, $messageInterface)
    {
        $this->db = $db;
        $this->messageInterface = $messageInterface;
        $this->id = 0;
        $this->maxRounds = ROUNDS_DEFAULT;
    }

    function loadGameState()
    {
        if (!$this->id) return false;

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

        // No need first round
        if ($round > 1)
        {
            $this->sendMessage(MessageType::NewRound);
        }

        $this->sendMessage(MessageType::RoundUpdate);
    }

    function sendMessage($messageType, $details = [])
    {
        switch ($messageType)
        {
            case MessageType::PickWinner:
                $dealer = $this->getBlackCardPlayer();
                $picks = [];
                foreach ($this->players as $player)
                {
                    if ($player->done) $picks[] = implode(', ', $player->picks);
                }
                shuffle($picks);
                array_walk($picks, function($s, $i)
                {
                    return ($i+1) . '. ' . $s;
                });

                $str = implode("\n", $picks);

                $text = sprintf(translate('player_choosing'), $this->round, $this->maxRounds, $str, $dealer->firstName);

                $this->messageInterface->sendMessage($this->chatId, $text, $this->messageId);
                break;
            case MessageType::NewRound:
                $this->messageInterface->sendGame($this);

                break;
            case MessageType::RoundUpdate:
                $blackCardText = str_replace('_', '___', $this->blackCard->content);

                $dealer = $this->getBlackCardPlayer();
                $text = null;

                $waiting = $this->getWaitingFor();

                array_walk($waiting, function($s)
                {
                    return '- ' . $s;
                });

                $str = implode("\n", $waiting);

                $text = sprintf(translate('waiting_for'), $this->round, $this->maxRounds, $dealer->firstName, $blackCardText, $str);

                $this->messageInterface->editGameMessage($this, $text);
                break;
            default:
                logEvent('Missing ' . $messageType);
                break;
        }
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
        if (count($pickedCards) != $this->blackCard->required) return false;

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

        $this->checkRoundState(true);

        return true;
    }

    function startGame($chatId, $args = [])
    {
        $found = $this->loadGame($chatId);
        if ($found) return false;

        $stmt = $this->db->prepare('INSERT INTO `cah_game` (chatId) VALUES (:chatId)');
        $stmt->execute(['chatId' => $chatId]);
        $this->loadGame($chatId);
        return true;
    }

    function loadGame($chatId)
    {
        $stmt = $this->db->prepare('SELECT * FROM `cah_game` WHERE chatId=:chatId');
        $stmt->execute(['chatId' => $chatId]);
        $chat = $stmt->fetch( PDO::FETCH_ASSOC);

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
        $stmt->setFetchMode(PDO::FETCH_CLASS, 'Player');
        $this->player = $stmt->fetch();

        $this->players[] = $this->player;
    }

    function checkRoundState($changed = false)
    {
        // called after a player picks white cards. Check if all players submitted their cards and num players > 3
        $waiting = $this->getWaitingFor();
        // Wait for more
        if (count($waiting) > 0)
        {
            $firstNames = array_map(function($player)
            {
                return $player->firstName;
            }, $waiting);

            $this->sendMessage(MessageType::RoundUpdate);
            return false;
        }

        if (!$changed) return true;

        // Not waiting anymore. Player with Black Card has to pick winner now
        $this->sendMessage(MessageType::PickWinner);
        return true;
    }

    function join()
    {
        $success = $this->player->join($this->round);
        if (!$success) return false;

        if ($this->blackCard) $this->checkRoundState();

        return true;
    }

    /**
     * @return bool successful
     */
    function leave()
    {
        if (!$this->player) return true; // success only tracks if we can leave / or already did
        if ($this->blackCard->player == $this->player->id)
        {
            return false;
        }
        $this->player->delete();
        return true;
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

        $this->players = $stmt->fetchAll(PDO::FETCH_CLASS, "player", [$this->db]);

        foreach ($this->players as $player)
        {
            if ($player->userId == $userId)
            {
                $this->player = $player;
                break;
            }
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
        $stmt->setFetchMode(PDO::FETCH_CLASS, 'Card');

        // All still to use cards for all players in the game
        return $stmt->fetch();
    }

    function getPickedCardsForPlayer($player)
    {
        $playerId = $player->id;
        return array_filter($this->allCards, function($card) use ($playerId)
        {
            /* @var Card $card */
            if ($card->type != CardType::White) return false;
            if ($card->player != $playerId) return false;
            if ($card->current != true) return false;
            return true;
        });
    }

    function getWhiteCardsForCurrentPlayer()
    {
        $playerId = $this->player->id;
        return array_filter($this->allCards, function ($card) use ($playerId)
        {
            /* @var Card $card */
            if ($card->type != CardType::White) return false;
            if ($card->player != $playerId) return false;
            return true;
        });
    }

    function getBlackCard()
    {
        $cards = array_filter($this->allCards, function($card)
        {
            if ($card->type != CardType::Black) return false;
            return true;
        });
        return count($cards) > 0 ? $cards[0] : null;
    }
    function getBlackCardPlayer()
    {
        foreach ($this->players as $player)
        {
            if ($this->blackCard->player == $player->id) return $player;
        }
        return null;
    }

    function getWaitingFor()
    {
        $playerBlackCardId = $this->blackCard->player;

        $playing = array_filter($this->players, function($player) use ($playerBlackCardId)
        {
            // Ignore non-joined players
            if ($player->joined < 1) return false;

            // Ignore blackCard-Player
            if ($player->id == $playerBlackCardId) return false;

            return true;
        });

        $round = $this->round;
        $fixed = array_filter($playing, function($player) use ($round)
        {
            // Ignore recently joined players
            if ($player->joined >= $round) return false;

            return true;
        });

        $fixedAndReady = array_filter($fixed, function($player)
        {
            return $player->done;
        });

        // Wait for more
        if (count($fixedAndReady) < count($fixed) || count($fixed) < 2)
        {
            $waiting = array_filter($playing, function($player)
            {
                return !$player->done;
            });
            return $waiting;
        }
        return [];
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