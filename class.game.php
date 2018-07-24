<?php

include_once('globals.php');
include_once('class.player.php');
include_once('class.card.php');

define('WHITECARDS_NUM', 10);
define('MIN_PLAYERS', 2);

interface iMessages
{
    /**
     * @param Game $game
     * @param string $text
     * @param bool $replace
     * @param bool $replyTo
     * @return bool success
     */
    function sendMessage($game, $text, $replace = false, $replyTo = false);

    /**
     * @param Game $game
     * @param bool $silent
     * @return bool success
     */
    function sendGame($game, $silent = false);

    /**
     * @param Game $game
     * @param string $text
     * @return bool success
     */
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

    /* @var int $gameMessageId */
    public $messageId;

    /* @var int $gameMessageId */
    public $gameMessageId;

    /* @var Card $blackCard */
    public $blackCard;

    /* @var Card[] $whiteCards */
    public $whiteCards;

    /* @var Card[] $allCards */
    public $allCards;

    /* @var Player[] $players */
    public $players;

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
        $this->allCards = $this->getAllCurrentCards();
        $this->blackCard = $this->getBlackCard();

        if ($this->player->joined != PlayerJoinedStatus::Joined) return;

        $this->whiteCards = $this->getWhiteCardsForCurrentPlayer();

        // No black card > first one to join
        if (!$this->blackCard)
        {
            $this->startRound(1, $this->player);
        }

        foreach ($this->players as $player)
        {
            $player->picks = $this->getPickedCardsForPlayer($player);
            $player->done = count($player->picks) == $this->blackCard->required;
        }

        if (count($this->whiteCards) < WHITECARDS_NUM)
        {
            $this->whiteCards = array_merge($this->whiteCards, $this->pickCards($this->player->id, CardType::White, WHITECARDS_NUM - count($this->whiteCards)));
        }
    }

    function startRound($round, $player)
    {
        $this->round = $round;

        $stmt = $this->db->prepare('UPDATE `cah_game` SET round=:round WHERE id=:id');
        $stmt->execute(['id' => $this->id, 'round' => $round]);

        $stmt = $this->db->prepare('UPDATE `cah_ref` SET `current`=FALSE WHERE `player` IN (SELECT `id` FROM `cah_player` WHERE `chat`=:chat)');
        $stmt->execute(['chat' => $this->id]);

        // Pick random black card
        $this->blackCard = $this->pickCards($player->id, CardType::Black, 1)[0];

        // No need first round
        if ($round > 1)
        {
            $this->sendMessage(MessageType::NewRound);
            $this->loadGameState();
        }

        $this->checkRoundState();
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
                    $arr = array_map(function($card)
                    {
                        /* @var Card $card */
                        return $card->content;
                    }, $player->picks);
                    if ($player->done) $picks[] = implode(', ', $arr);
                }
                shuffle($picks);
                array_walk($picks, function(&$s, $i)
                {
                    $s = ($i+1) . '. ' . htmlspecialchars($s);
                });

                $text = $this->getMessageHeader() . sprintf(translate('player_choosing'), implode("\n", $picks), $dealer->userId, htmlspecialchars($dealer->firstName));

                $this->messageInterface->sendMessage($this, $text, $this->gameMessageId);
                break;
            case MessageType::NewRound:
                $this->messageInterface->sendGame($this);

                break;
            case MessageType::RoundUpdate:
                $waiting = $details['waiting'];
                $count = $details['count'];
                $bestPlayer = $details['bestPlayer'];

                if ($count > 0)
                {
                    $text = $this->getMessageHeader() . sprintf(translate('waiting_more'), $count);
                } else if (count($waiting) > 0) {
                    $text = '';
                    foreach ($waiting as $player)
                    {
                        $text .= "- {$player->firstName}\n";
                    }

                    $text = $this->getMessageHeader() . sprintf(translate('waiting_for'), $text);
                } else {

                    // Already decided
                    if ($bestPlayer)
                    {

                    }
                }

                $this->messageInterface->editGameMessage($this, $text);
                break;
            case MessageType::PlayerJoined:
                $text = sprintf(translate('player_joined'), $details['firstName']);
                $this->messageInterface->sendMessage($this, $text);
                break;
            case MessageType::NewScore:
                $scores = [];
                foreach ($this->players as $player)
                {
                    $scores[] = ['name' => $player->firstName, 'score' => $player->score];
                }
                usort($scores, function($score1, $score2)
                {
                    // sort by score then by name
                    if ($score1['score'] == $score2['score']) return strcmp(strtolower($score1['name']), strtolower($score2['name']));
                    return $score2['score'] > $score1['score'];
                });

                $text = '';
                foreach ($scores as $score)
                {
                    $text .= "- {$score['name']}: <b>{$score['score']}</b>\n";
                }

                $text = sprintf(translate('player_scored'), $details['firstName'], $text);
                $this->messageInterface->sendMessage($this, $text, true);
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

        $pick = 0;
        $curr = false;
        if ($cardType == CardType::Black)
        {
            $pick = 1;
            $curr = true;
        }

        $stmt = $this->db->prepare('INSERT INTO `cah_ref` (card, player, `pick`, `current`) VALUES (:card, :player, :pick, :curr)');
        $cards = [];
        foreach ($cardIds as $cardId)
        {
            $stmt->execute(['card' => $cardId['id'], 'player' => $playerId, 'pick' => $pick, 'curr' => $curr]);
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
        $this->player->generateToken(true);

        $this->checkRoundState(true);

        return true;
    }

    function pickBestCards($refIds)
    {
        // Need to be black card player
        if ($this->player->id != $this->blackCard->player) return false;

        $bestPlayer = null;
        foreach ($this->players as $player)
        {
            if (!$player->done) continue;
            if (!in_array($player->picks[0]->id, $refIds)) continue;
            $bestPlayer = $player;
            break;
        }

        if (!$bestPlayer) return false;

        $bestPlayer->setScore($bestPlayer->score + 1);

        $this->sendMessage(MessageType::NewScore, ['firstName' => $bestPlayer->firstName]);

        $this->startRound($this->round + 1, $bestPlayer);

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
        $this->gameMessageId = $chat['gameMessageId'];

        return true;
    }

    function addPlayer($userId, $firstName)
    {
        $stmt = $this->db->prepare('INSERT INTO `cah_player` (userId, firstName, chat) VALUES (:userId, :firstName, :chat)');
        $stmt->execute(['chat' => $this->id, 'userId' => $userId, 'firstName' => $firstName]);

        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE id = :id');
        $stmt->execute(['id' => $this->db->lastInsertId()]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, 'Player', [$this->db]);
        $this->player = $stmt->fetch();

        $this->players[] = $this->player;
    }

    /**
     * @param bool $changed
     * @return bool waiting for more / waiting for joined players
     */
    function checkRoundState($changed = false)
    {

        // Check if all players submitted their cards and num players > 3
        $needMore = $this->getWaitingList($waiting);

        if ($needMore > 0 || count($waiting) > 0)
        {
            // Still waiting for players to pick white cards, so "waiting to join" players can enter

            foreach ($this->players as $player)
            {
                if ($player->joined == PlayerJoinedStatus::Waiting)
                {
                    $player->join(PlayerJoinedStatus::Joined);
                    $this->sendMessage(MessageType::PlayerJoined, ['firstName' => $player->firstName]);
                }
            }
        }

        // Check again, in case player joined
        $needMore = $this->getWaitingList($waiting);

        if ($needMore > 0 || count($waiting) > 0)
        {
            $this->sendMessage(MessageType::RoundUpdate, [
                'count' => $needMore,
                'waiting' => $waiting,
            ]);
            return false;
        }

        if ($changed)
        {
            // Not waiting anymore. Player with Black Card has to pick winner now
            $this->sendMessage(MessageType::PickWinner);
        }

        return true;
    }

    /**
     * @return bool join straightaway
     */
    function join()
    {

        if ($this->player->joined != PlayerJoinedStatus::NotJoined) return true;

        if ($this->blackCard)
        {
            $this->player->join(PlayerJoinedStatus::Waiting);
            $waitNextRound = $this->checkRoundState();
            if ($waitNextRound) return false;
        } else {
            $this->player->join(PlayerJoinedStatus::Joined);
            $this->sendMessage(MessageType::PlayerJoined, ['firstName' => $this->player->firstName]);
        }

        // Load again
        $this->loadGameState();
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

        $this->loadForChatAndUser($player['chatId'], $player['userId']);

        return true;
    }

    function loadForChatAndUser($chatId, $userId, $firstName = null)
    {
        $found = $this->loadGame($chatId);
        if (!$found) return false;

        $this->loadPlayers($userId);

        if (!$this->player)
        {
            $this->addPlayer($userId, $firstName);
        }

        $this->loadGameState();

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

        $this->players = $stmt->fetchAll(PDO::FETCH_CLASS, 'Player', [$this->db]);

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
        return array_values(array_filter($this->allCards, function($card) use ($playerId)
        {
            /* @var Card $card */
            if ($card->type != CardType::White) return false;
            if ($card->player != $playerId) return false;
            if ($card->current != true) return false;
            return true;
        }));
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
        $cards = array_values(array_filter($this->allCards, function($card)
        {
            if ($card->type != CardType::Black) return false;
            return true;
        }));
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

    function getWaitingList(&$waiting)
    {
        $playerBlackCardId = $this->blackCard->player;

        $playing = array_filter($this->players, function($player) use ($playerBlackCardId)
        {
            // Ignore blackCard-Player
            if ($player->id == $playerBlackCardId) return false;

            // Ignore non-joined players
            if ($player->joined == PlayerJoinedStatus::NotJoined) return false;

            return true;
        });

        // Need so many more players
        if (count($playing) < MIN_PLAYERS)
        {
            return MIN_PLAYERS - count($playing);
        }

        $fixed = array_filter($playing, function($player)
        {
            // Ignore waiting players
            if ($player->joined == PlayerJoinedStatus::Waiting) return false;

            return true;
        });

        // not enough fixed players - use waiting players too
        if (count($fixed) < MIN_PLAYERS)
        {
            $waiting = array_filter($playing, function($player)
            {
                return !$player->done;
            });
            return 0;
        }

        $fixedAndReady = array_filter($fixed, function($player)
        {
            return $player->done;
        });

        if (count($fixedAndReady) < count($fixed))
        {
            $waiting = array_values(array_filter($fixed, function($player)
            {
                return !$player->done;
            }));
        }

        // ready to go
        return 0;
    }

    function getMessageHeader()
    {
        $blackCardText = str_replace('_', '___', $this->blackCard->content);
        $dealer = $this->getBlackCardPlayer();

        return sprintf(translate('game_header'), $this->round, $this->maxRounds, htmlspecialchars($dealer->firstName), htmlspecialchars($blackCardText));
    }

    function setGameMessageId($gameMessageId)
    {
        $this->gameMessageId = $gameMessageId;

        $stmt = $this->db->prepare('UPDATE `cah_game` SET gameMessageId=:gameMessageId WHERE id=:id');
        $stmt->execute(['gameMessageId' => $gameMessageId, 'id' => $this->id]);
    }

    function setMessageId($messageId)
    {
        $this->messageId = $messageId;

        $stmt = $this->db->prepare('UPDATE `cah_game` SET messageId=:messageId WHERE id=:id');
        $stmt->execute(['messageId' => $messageId, 'id' => $this->id]);
    }

    static function getPickIds($picks)
    {
        array_walk($picks, function(&$card)
        {
            /* @var Card $card */
            $card = $card->id;
        });
        return join(',', $picks);
    }
}