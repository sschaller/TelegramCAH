<?php

include_once('include/globals.php');
include_once('src/Player.php');
include_once('src/Card.php');

define('WHITECARDS_NUM', 10);
define('MIN_PLAYERS', 3); // At least 2 players need to provide white cards -> 3 players in total
define('ROUNDS_DEFAULT', 10);

interface iMessages
{
    /**
     * @param Game $game
     * @param string $text
     * @param bool|integer $replyTo Id of Message to reply to
     * @return bool|integer message_id or false
     */
    function sendMessage($game, $text, $replyTo = false);

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

    /**
     * @param Game $game
     * @param int $messageId
     */
    function deleteMessage($game, $messageId);
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

    /* @var int $rounds */
    public $rounds;

    /* @var int $messageId */
    public $messageId;

    /* @var Card $blackCard */
    public $blackCard;

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
    }

    /**
     * Check if round count is reached, otherwise prepare new round (remove last game's picks, reset player done state)
     * @param int $round
     * @param Player $player Player that won last round -> Draws black card
     */
    function startRound($round, $player)
    {
        $this->round = $round;

        if ($this->round > $this->rounds) {
            $this->sendMessage(MessageType::GameEnded);
            $this->delete();
        }

        $stmt = $this->db->prepare('UPDATE `cah_game` SET round=:round WHERE id=:id');
        $stmt->execute(['id' => $this->id, 'round' => $round]);

        $stmt = $this->db->prepare('UPDATE `cah_ref` SET `current`=FALSE WHERE `player` IN (SELECT `id` FROM `cah_player` WHERE `chat`=:chat)');
        $stmt->execute(['chat' => $this->id]);

        // Pick random black card
        $this->blackCard = $this->pickCards($player->id, CardType::Black, 1)[0];

        // Reload any cards by players -> picked should be gone now
        $this->updateCards();

        // No need first round
        if ($round > 1)
        {
            $this->sendMessage(MessageType::NewRound);
            $this->checkRoundState();
        }
    }

    /**
     * Send message to chat (New Round, Round Update, New Player, Game Ended)
     * @param MessageType $messageType
     * @param array $details
     */
    function sendMessage($messageType, $details = [])
    {
        switch ($messageType)
        {
            case MessageType::NewRound:
                $this->messageInterface->sendGame($this, true);
                break;
            case MessageType::RoundUpdate:
                $waiting = $details['waiting'];
                $count = $details['count'];

                if ($count > 0)
                {
                    $text = $this->getMessageHeader() . sprintf(translate('waiting_more'), $count);
                    $this->messageInterface->editGameMessage($this, $text);
                } else if (count($waiting) > 0) {
                    $text = '';
                    foreach ($waiting as $player)
                    {
                        $text .= "- {$player->firstName}\n";
                    }

                    $text = $this->getMessageHeader() . sprintf(translate('waiting_for'), $text);
                    $this->messageInterface->editGameMessage($this, $text);
                } else {

                    $bestPlayer = isset($details['bestPlayer']) ? $details['bestPlayer'] : null;

                    // Answers
                    $picks = [];
                    foreach ($this->players as $player)
                    {
                        if (!$player->done) continue;

                        $pickContents = array_map(function($card)
                        {
                            /* @var Card $card */
                            return htmlspecialchars($card->content);
                        }, $player->picks);

                        $playerPicks = implode(', ', $pickContents);

                        if ($bestPlayer && $bestPlayer === $player) $playerPicks = "<b>{$playerPicks}</b>";
                        $picks[] = $playerPicks;
                    }

                    array_walk($picks, function(&$s, $i)
                    {
                        $s = ($i+1) . '. ' . $s;
                    });

                    $picksText = implode("\n", $picks);

                    // Already decided
                    if ($bestPlayer)
                    {
                        $scores = $this->getScores();
                        $scoresText = '';
                        foreach ($scores as $score)
                        {
                            $scoresText .= "- {$score['name']}: <b>{$score['score']}</b>\n";
                        }
                        $text = $this->getMessageHeader() . sprintf(translate('player_scored'), $picksText, htmlspecialchars($bestPlayer->firstName), $scoresText);
                        $this->messageInterface->sendMessage($this, $text, false);
                    } else {
                        $dealer = $this->getBlackCardPlayer();
                        $text = $this->getMessageHeader() . sprintf(translate('player_choosing'), $picksText, $dealer->userId, htmlspecialchars($dealer->firstName));
                        $this->messageInterface->editGameMessage($this, $text);
                    }
                }
                break;
            case MessageType::PlayerJoined:
                $text = sprintf(translate('player_joined'), $details['firstName']);
                $this->messageInterface->sendMessage($this, $text);
                break;
            case MessageType::GameEnded:
                $scores = $this->getScores();
                $scoresText = '';
                foreach ($scores as $score)
                {
                    $scoresText .= "- {$score['name']}: <b>{$score['score']}</b>\n";
                }
                $text = sprintf(translate('final_score'), $scoresText);
                $this->messageInterface->sendMessage($this, $text, false);
                if ($this->messageId)
                {
                    // Delete last message - don't clutter up chat.
                    $this->messageInterface->deleteMessage($this, $this->messageId);
                }
                break;
            default:
                logEvent('Missing ' . $messageType, EventSeverity::Error);
                break;
        }
    }

    /**
     * Pick $limit number of cards for player of card type (white / black)
     * @param $playerId
     * @param $cardType
     * @param $limit
     * @return array
     */
    function pickCards($playerId, $cardType, $limit)
    {
        if ($limit < 1) return [];

        $stmt = $this->db->prepare('SELECT id FROM `cah_card` WHERE `type` = :cardType AND `id` NOT IN (SELECT `card` FROM `cah_ref` WHERE `player` IN (SELECT `id` FROM `cah_player` WHERE `chat`=:chat)) ORDER BY RAND() LIMIT 0,:limit');
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

        $this->allCards = array_merge($this->allCards, $cards);
        return $cards;
    }

    /**
     * Player plays $refIds cards (in that order if multiple) -> mark as current pick and as used
     * @param $player Player
     * @param $refIds array
     * @return bool
     */
    function playCards($player, $refIds)
    {
        // Select all cards that were picked from our unused white cards
        $pickedCards = array_filter($this->allCards, function($card) use ($refIds, $player)
        {
            if ($card->player != $player->id) return false;
            if ($card->pick > 0) return false;
            if (!in_array($card->id, $refIds)) return false;
            return true;
        });

        // Check if the number of unused & picked white cards is the same as the required one from the black card
        if (count($pickedCards) != $this->blackCard->required) {
            logEvent('sanity test failed', EventSeverity::Error);
            return false;
        }

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
        $player->done = true;
        $player->picks = $this->getPickedCardsForPlayer($player);

        return true;
    }

    /**
     * Player picks best answer to win -> start next round
     * @param Player $player
     * @param array $refIds
     * @return bool
     */
    function pickBestCards($player, $refIds)
    {
        // Need to be black card player
        if ($player->id != $this->blackCard->player) return false;

        $bestPlayer = null;
        foreach ($this->players as $p)
        {
            if (!$p->done) continue;
            if (!in_array($p->picks[0]->id, $refIds)) continue;
            $bestPlayer = $p;
            break;
        }

        if (!$bestPlayer) return false;

        $bestPlayer->setScore($bestPlayer->score + 1);

        $this->sendMessage(MessageType::RoundUpdate, [
            'count' => 0,
            'waiting' => [],
            'bestPlayer' => $bestPlayer
        ]);

        $this->startRound($this->round + 1, $bestPlayer);

        return true;
    }

    /**
     * @param int $chatId
     * @param array $args
     * @return bool
     */
    function startGame($chatId, $args = [])
    {
        $found = $this->loadGame($chatId);
        if ($found) return false;

        $stmt = $this->db->prepare('INSERT INTO `cah_game` (chatId, rounds) VALUES (:chatId, :rounds)');
        $stmt->execute(['chatId' => $chatId, 'rounds' => isset($args['rounds']) ? $args['rounds'] : ROUNDS_DEFAULT]);
        $this->loadGame($chatId);
        return true;
    }

    /**
     * Load game for chat
     * @param int $chatId
     * @return bool successful
     */
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
        $this->rounds = $chat['rounds'];
        $this->messageId = $chat['messageId'];

        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE chat = :chat');
        $stmt->execute(['chat' => $this->id]);

        $this->players = $stmt->fetchAll(PDO::FETCH_CLASS, 'Player', [$this->db]);

        $this->updateCards();

        return true;
    }

    /**
     * Load cards and set each players current pick / if done
     */
    function updateCards() {
        $this->allCards = $this->getAllCurrentCards();
        $this->blackCard = $this->getBlackCard();

        if ($this->blackCard)
        {
            foreach ($this->players as $player)
            {
                $player->picks = $this->getPickedCardsForPlayer($player);
                $player->done = count($player->picks) == $this->blackCard->required;
            }
        }
    }

    /**
     * Return player with userId for the game if exists, otherwise null
     * @param int $userId
     * @return Player|null
     */
    function getPlayer($userId)
    {
        assert($this->id > 0, 'Game is not loaded');

        foreach ($this->players as $player) {
            if ($player->userId == $userId) {
                return $player;
            }
        }
        return null;
    }

    /**
     * Add player to game if not exists
     * @param int $userId
     * @param string $firstName
     * @return bool created
     */
    function addPlayer($userId, $firstName)
    {
        if ($this->getPlayer($userId)) return false;

        $stmt = $this->db->prepare('INSERT INTO `cah_player` (userId, firstName, chat) VALUES (:userId, :firstName, :chat)');
        $stmt->execute(['chat' => $this->id, 'userId' => $userId, 'firstName' => $firstName]);

        $stmt = $this->db->prepare('SELECT * FROM `cah_player` WHERE id = :id');
        $stmt->execute(['id' => $this->db->lastInsertId()]);
        $stmt->setFetchMode(PDO::FETCH_CLASS, 'Player', [$this->db]);
        $this->player = $stmt->fetch();

        $this->players[] = $this->player;
        return true;
    }

    /**
     * Check if round can continue (if all players picked their white cards) -> tell black card player to pick winner
     */
    function checkRoundState()
    {
        $this->checkAI();

        $needMore = $this->getWaitingList($waiting);

        $this->sendMessage(MessageType::RoundUpdate, [
            'count' => $needMore,
            'waiting' => $waiting,
        ]);
    }

    /**
     * Set status of current player to join
     * @return bool did join?
     */
    function join()
    {
        if (!$this->player) {
            logEvent('Try to join current player, does not exist', EventSeverity::Error);
        }
        # already joined
        if ($this->player->joined == PlayerJoinedStatus::Joined) return false;

        $this->player->join(PlayerJoinedStatus::Joined);
        $this->sendMessage(MessageType::PlayerJoined, ['firstName' => $this->player->firstName]);

        // No black card > first one to join
        if (!$this->blackCard) {
            $this->startRound(1, $this->player);
        }

        $this->checkRoundState();
        return true;
    }

    /**
     * If player enters / leave -> leave game if possible (not black card player)
     * @return bool successful
     */
    function leave()
    {
        if ($this->blackCard->player == $this->player->id) return false;
        $this->player->delete();
        return true;
    }

    /**
     * Load the game and current player using a player token.
     * Used when the player enters the web view to pick his cards or pick the best answer
     * @param string $token
     * @return bool
     */
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

    /**
     * Load the game for chat and add current user if not already joined
     * @param int $chatId
     * @param int $userId
     * @param string $firstName
     * @return bool
     */
    function loadForChatAndUser($chatId, $userId, $firstName = null)
    {
        $found = $this->loadGame($chatId);
        if (!$found) return false;

        $this->addPlayer($userId, $firstName);
        $this->player = $this->getPlayer($userId);

        return true;
    }

    /**
     * Delete the game for current chat
     * @return bool
     */
    function delete()
    {
        // can't stop a game without a chat Id
        if (!$this->id) return false;

        $stmt = $this->db->prepare('DELETE FROM `cah_game` WHERE id = :id');
        return $stmt->execute(['id' => $this->id]);
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
        $cards = array_filter($this->allCards, function($card) use ($player)
        {
            /* @var Card $card */
            if ($card->type != CardType::White) return false;
            if ($card->player != $player->id) return false;
            if ($card->current != true) return false;
            return true;
        });
        usort($cards, function($card1, $card2)
        {
            /* @var Card $card1 */
            /* @var Card $card2 */
            return $card1->pick - $card2->pick;
        });
        return array_values($cards);
    }

    function getWhiteCardsForPlayer($player)
    {
        $cards = array_values(array_filter($this->allCards, function ($card) use ($player)
        {
            /* @var Card $card */
            if ($card->type != CardType::White) return false;
            if ($card->player != $player->id) return false;
            return true;
        }));

        // Draw new cards if needed
        return array_merge($cards, $this->pickCards($player->id, CardType::White, WHITECARDS_NUM - count($cards)));
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

    /**
     *
     * @param $waiting
     * @return int how many players are still needed
     */
    function getWaitingList(&$waiting)
    {
        $playerBlackCardId = $this->blackCard->player;

        // All players currently in the game that have not picked their cards yet
        $waiting = array_filter($this->players, function($player) use ($playerBlackCardId)
        {
            // Ignore blackCard-Player
            if ($player->id == $playerBlackCardId) return false;

            // Ignore non-joined players
            if ($player->joined != PlayerJoinedStatus::Joined) return false;

            // Ignore players that already picked their white cards
            if ($player->done) return false;

            return true;
        });

        // at least "min_players-1" different options need to be provided for best answer to make sense (at least 2)
        $potential = array_filter($this->players, function($player) use ($playerBlackCardId)
        {
            // Ignore blackCard-Player
            if ($player->id == $playerBlackCardId) return false;

            // Potential Player: Joined or already Done&Left
            return ($player->joined == PlayerJoinedStatus::Joined || $player->done);
        });

        return max(0, MIN_PLAYERS - 1 - count($potential));
    }

    function getMessageHeader()
    {
        $blackCardText = str_replace('_', '___', $this->blackCard->content);
        $dealer = $this->getBlackCardPlayer();

        return sprintf(translate('game_header'), $this->round, $this->rounds, htmlspecialchars($dealer->firstName), htmlspecialchars($blackCardText));
    }

    function getShortMessageHeader()
    {
        $blackCardText = str_replace('_', '___', $this->blackCard->content);

        return sprintf(translate('short_header'), htmlspecialchars($blackCardText));
    }

    function setMessageId($messageId)
    {
        if (!$messageId) return;

        $this->messageId = $messageId;
        $stmt = $this->db->prepare('UPDATE `cah_game` SET messageId=:messageId WHERE id=:id');
        $stmt->execute(['messageId' => $messageId, 'id' => $this->id]);
    }

    function getScores()
    {
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
        return $scores;
    }

    function checkAI()
    {
        // All AI players currently in the game
        $bots = array_filter($this->players, function($player)
        {
            return $player->userId < 100 && substr($player->firstName, 0, 6) == 'dummy_';
        });

        $botDeciding = null;
        $botsPlaying = [];
        foreach ($bots as $bot) {
            if ($bot->id == $this->blackCard->player) {
                $botDeciding = $bot;
                continue;
            }
            if ($bot->joined == PlayerJoinedStatus::Joined && !$bot->done) {
                $botsPlaying[] = $bot;
            }
        }

        foreach ($botsPlaying as $bot) {
            // Pick random cards

            $whiteCards = $this->getWhiteCardsForPlayer($bot);
            logEvent('dummy ' . $bot->id . ' has ' . count($whiteCards) . ' white cards');

            $randomKeys = array_rand($whiteCards, $this->blackCard->required);

            $refIds = [];
            if (is_array($randomKeys)) {
                foreach($randomKeys as $key) {
                    $refIds[] = $whiteCards[$key]->id;
                }
            } else {
                $refIds[] = $whiteCards[$randomKeys]->id;
            }

            $this->playCards($bot, $refIds);
        }

        $needMore = $this->getWaitingList($waiting);
        if ($botDeciding != null && $needMore == 0 && count($waiting) == 0) {
            // pick a random player to win

            $players = array_filter($this->players, function($player) {
                return $player->done;
            });
            $this->pickBestCards($botDeciding, array_column($players[array_rand($players)]->picks, 'id'));
            return;
        }
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