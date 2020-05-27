<?php

include_once('include/lessc.php');
include_once('src/TelegramBot.php');
include_once('src/Game.php');

define('ROOT_DIR', dirname(__DIR__));
define('TEMPLATE_DIR', ROOT_DIR . '/templates/');

class GameController implements iMessages, iBotSubscriber
{
	static public $config = null;
    private $db, $bot;

    function __construct()
    {
        if (self::$config == null) self::$config = include('include/config.php');

        $this->db = new PDO("mysql:host=localhost;dbname=" . self::$config['dbName'], self::$config['dbUser'], self::$config['dbPassword']);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->bot = new TelegramBot(self::$config['shortName'], self::$config['telegramAPIToken']);
        $this->bot->subscribe($this);
    }

    function main()
    {
        $args = self::parseURL();

        // No arguments
        if (!$args) {
            http_response_code(404);
            return;
        }

        if (key_exists('cmd', $_POST))
        {
            $this->handleCommand($_POST['cmd'], $args);
            return;
        }

        switch ($args[0])
        {
            case self::$config['botSecret']:
                try
                {
                    $json = json_decode(file_get_contents('php://input'), true);
                    $this->bot->receiveUpdate($json);
                }
                catch(PDOException $e)
                {
                    logEvent($e->__toString(), EventSeverity::Error);
                }
                catch(Exception $e)
                {
                    logEvent($e->getMessage(), EventSeverity::Error);
                }
                break;
            case self::$config['adminSecret']:
                ob_start();
                if (count($args) >= 2) {
                    $this->handleAdmin($args[count($args) - 1]);
                }
                include(TEMPLATE_DIR . 'admin.php');
                $content = ob_get_clean();
                include(TEMPLATE_DIR . 'default.php');
                break;
            case 'play':
                if (count($args) < 2) break;
                $game = new Game($this->db, $this);
                $game->loadWithPlayerToken($args[1]);

                if (key_exists('action', $_REQUEST) && $_REQUEST['action'] == 'join') {
                    $game->join();
                }

                $this->showContent($game);
                break;
        }
    }

    /**
     * Show content when user clicks on /play/ url
     * @param Game $game
     */
    function showContent($game)
    {
        $less = new lessc;

        ob_start();
        if (!$game->chatId)
        {
            $message = translate('token_not_found');
            include(TEMPLATE_DIR . 'message.php');
        } else if ($game->player->joined == PlayerJoinedStatus::NotJoined)
        {
            $message = translate('join_game');
            include(TEMPLATE_DIR . 'button.php');
        } else {
            if ($game->blackCard && $game->player === $game->getBlackCardPlayer())
            {
                $needMore = $game->getWaitingList($waiting);

                if ($needMore > 0)
                {
                    $message = sprintf(translate('black_card_player_need_more'), $needMore, ($needMore != 1 ? 's' : ''));
                    include(TEMPLATE_DIR . 'message.php');
                } else if (count($waiting) > 0)
                {
                    $text = '';
                    foreach ($waiting as $player)
                    {
                        $text .= "<li>{$player->firstName}</li>";
                    }
                    $message = sprintf(translate('black_card_player_waiting'), $text);
                    include(TEMPLATE_DIR . 'message.php');
                } else {
                    $players = array_filter($game->players, function($player) {
                        return $player->done;
                    });
                    shuffle($players);
                    include(TEMPLATE_DIR . 'best.php');
                }
            } else {
                $whiteCards = $game->getWhiteCardsForPlayer($game->player);
                include(TEMPLATE_DIR . 'cards.php');
            }
        }

        $content = ob_get_clean();
        include(TEMPLATE_DIR . 'default.php');
    }

    /**
     * Handle js submit actions when user clicks on buttons in /play/ url page
     * @param $command
     * @param $args
     */
    function handleCommand($command, $args)
    {
        $response = ['status' => JsonResult::Invalid];
        switch ($command)
        {
            case 'pick':

                if (count($args) < 2) break;
                if ($args[0] != 'play') break;
                $game = new Game($this->db, $this);
                $success = $game->loadWithPlayerToken($args[1]);

                if (!$success)
                {
                    $response['status']= JsonResult::Error;
                    $response['text'] = translate('token_not_found');
                    break;
                }

                $success = $game->playCards($game->player, $_POST['picks']);
                if (!$success)
                {
                    $response['status']= JsonResult::Error;
                    $response['text'] = translate('cant_play_cards');
                    break;
                }

                $response['status'] = JsonResult::Success;

                $game->checkRoundState();
                break;
            case 'best':

                if (count($args) < 2) break;
                if ($args[0] != 'play') break;
                $game = new Game($this->db, $this);
                $success = $game->loadWithPlayerToken($args[1]);

                if (!$success)
                {
                    $response['status']= JsonResult::Error;
                    $response['text'] = translate('token_not_found');
                    break;
                }

                $success = $game->pickBestCards($game->player, $_POST['picks']);
                if (!$success)
                {
                    $response['status']= JsonResult::Error;
                    $response['text'] = translate('cant_pick_best');
                    break;
                }

                $response['status'] = JsonResult::Success;
                $response['url'] = self::getPlayUrl($game->player->generateToken());

                $game->checkRoundState();
                break;
        }

        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * Handle admin environment
     * @param string $command
     */
    function handleAdmin($command) {
        switch($command) {
            case 'webhook':
                $successful = $this->bot->setWebhook('https://' . $_SERVER['HTTP_HOST'] . self::$config['urlPrefix'] . self::$config['botSecret'], array('message', 'callback_query'));
                logEvent(json_encode($this->bot->getWebhookInfo()), EventSeverity::Debug);
                echo 'webhook set: ' . ($successful ? 1 : 0);
                break;
            case 'reset':
                $sql = file_get_contents(ROOT_DIR . '/data/tables.sql');
                $this->db->exec($sql);
                $this->importCards();
                echo 'reset done';
                break;
            case 'import':
                $this->importCards();
                echo 'import done';
                break;
        }
    }

    /**
     * Return player specific /play/ url
     * @param int $chatId
     * @param int $playerId
     * @param string $firstName
     * @return string|null
     */
    function getPlayerUrl($chatId, $playerId, $firstName)
    {
        // check if player exists already for this chat
        $game = new Game($this->db, $this);
        $success = $game->loadForChatAndUser($chatId, $playerId, $firstName);

        // Could not create game
        if (!$success)
        {
            logEvent('Could not get token for Player', 'ERROR');
            return null;
        }

        return self::getPlayUrl($game->player->generateToken());
    }

    /**
     * Print card using $card and $required number of cards
     * @param Card $card
     * @param int $required
     */
    function drawCard($card, $required = 0)
    {
        include('templates/card.php');
    }

    /**
     * Send game to chat (includes Play Button), remove old game if exists
     * @param Game $game
     * @param bool $silent
     * @return bool success
     */
    function sendGame($game, $silent = false)
    {

        $button = [
            'text' => translate('play_game'),
            'callback_game' => self::$config['shortName'],
        ];

        $inline_keyboard = [
            [
                $button
            ]
        ];

        $data = array(
            'chat_id' => $game->chatId,
            'game_short_name' => self::$config['shortName'],
            'disable_notification' => $silent,
            'reply_markup' => json_encode(['inline_keyboard' => $inline_keyboard]),
        );
        $message = $this->bot->sendRequest('sendGame', $data);
        if (!$message) return false;

        if ($game->messageId)
        {
            // Delete last message - don't clutter up chat.
            $this->deleteMessage($game, $game->messageId);
        }

        $game->setMessageId($message['message_id']);
        return true;
    }

    /**
     * Send Message to chat
     * @param Game $game
     * @param string $text
     * @param bool|integer $replyTo Id of Message to reply to
     * @return bool|integer message_id or false
     */
    function sendMessage($game, $text, $replyTo = false)
    {
        $data = [
            'chat_id' => $game->chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($replyTo)
        {
            $data = array_merge($data, [
                'disable_notification' => true,
                'reply_to_message_id' => $replyTo,
            ]);
        }

        $message = $this->bot->sendRequest('sendMessage', $data);
        if (!$message) return false;

        return $message['message_id'];
    }

    /**
     * Tries to edit message, if not possible creates new message
     * @param Game $game
     * @param string $text
     * @return bool success
     */
    function editGameMessage($game, $text)
    {
        $button = [
            'text' => translate('play_game'),
            'callback_game' => self::$config['shortName'],
        ];

        $inline_keyboard = [
            [
                $button
            ]
        ];

        $this->bot->sendRequest('editMessageText', [
            'chat_id' => $game->chatId,
            'message_id' => $game->messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $inline_keyboard],
        ]);

        return true;
    }

    /**
     * Try to delete messsage for messageId
     * @param Game $game
     * @param int $messageId
     */
    function deleteMessage($game, $messageId)
    {
        $this->bot->sendRequest('deleteMessage', [
            'chat_id' => $game->chatId,
            'message_id' => $messageId
        ]);
    }

    /**
     * Try to reply to message
     * @param Message $message
     * @param string $text
     */
    function replyToMessage($message, $text)
    {
        $data = [
            'chat_id' => $message->chat->id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_notification' => true,
            'reply_to_message_id' => $message->id,
        ];
        $this->bot->sendRequest('sendMessage', $data);
    }

    /**
     * Handle incoming telegram messages
     * @param Message $message
     */
    function handleMessage($message)
    {
        $command = explode(' ', $message->text);

        $game = new Game($this->db, $this);

        switch ($command[0])
        {
            case '/start':
                // Ignore anyone not on whitelist if set
                if (self::$config['whitelist'] && !in_array($message->from->id, self::$config['whitelist'])) break;

                // Start a new game, if not already started
                $args = [];
                if (count($command) > 1 && is_numeric($command[1]) && intval($command[1], 10) > 0) {
                    $args['rounds'] = intval($command[1], 10);
                }

                if ($game->startGame($message->chat->id, $args))
                {
                    $this->sendGame($game);
                } else {
                    $this->replyToMessage($message, translate('game_already_started'));
                }

                break;
            case '/join':
                // Add user to game, set confirm flag to 1 (so it waits for him)
                if (!$game->loadForChatAndUser($message->chat->id, $message->from->id, $message->from->firstName)) {
                    $this->replyToMessage($message, translate('no_game_call_start'));
                    break;
                }
                if(!$game->join()) {
                    $this->replyToMessage($message, translate('already_joined'));
                }
                break;
            case '/leave':
                // Remove user from game (just delete player object for this chat)
                if (!$game->loadForChatAndUser($message->chat->id, $message->from->id, $message->from->firstName)) {
                    $this->replyToMessage($message, translate('no_game_call_start'));
                    break;
                }

                $wasJoined = $game->player->joined;
                $success = $game->leave(); // delete player again (was created just above)
                if (!$success) {
                    $this->replyToMessage($message, translate('cant_leave_now'));
                } else if($wasJoined == PlayerJoinedStatus::Joined) {
                    $this->replyToMessage($message, translate('not_joined'));
                } else {
                    $this->replyToMessage($message, translate('left_successfully'));
                }

                break;
            case '/stop':
                // Delete game (+ all players)
                $found = $game->loadGame($message->chat->id);
                if ($found)
                {
                    $game->delete();
                    $this->replyToMessage($message, translate('game_stopped'));
                } else {
                    $this->replyToMessage($message, translate('no_game_call_start'));
                }
                break;
            case '/dummy':
                // Add user to game, set confirm flag to 1 (so it waits for him)
                if (!$game->loadGame($message->chat->id)) {
                    $this->replyToMessage($message, translate('no_game_call_start'));
                    break;
                }

                // Try create a dummy player (max numDummyPlayers)
                for ($i = 0; $i < self::$config['maxDummyPlayers']; $i++) {

                    // Able to add player? (Otherwise exists already)
                    if ($game->addPlayer($i, 'Dummy ' . ($i+1))) {
                        $game->join();
                        break;
                    }
                }
                break;
        }
    }

    /**
     * Handle incoming CallbackQuery
     * @param CallbackQuery $callbackQuery
     */
    function handleCallbackQuery($callbackQuery)
    {
        if (!$callbackQuery->message) return;

        $url = $this->getPlayerUrl($callbackQuery->message->chat->id, $callbackQuery->from->id, $callbackQuery->from->firstName);

        $data = array(
            'callback_query_id' => $callbackQuery->callbackId
        );

        if ($url)
        {
            $data['url'] = $url;
        } else {
            $data['text'] = translate('no_game_found');
            $data['show_alert'] = true;
        }

        $this->bot->sendRequest('answerCallbackQuery', $data);
    }

    /**
     * Import white/black cards from cards.json
     */
    function importCards()
    {
        $json = file_get_contents(ROOT_DIR . '/data/cards.json');
        $cards = json_decode($json, true);

        $already = [];
        $results = $this->db->query('SELECT `id`, `name` FROM `cah_pack`', PDO::FETCH_ASSOC);
        foreach ($results as $row)
        {
            $already[$row['name']] = $row['id'];
        }

        foreach ($cards as $packName => $pack)
        {
            if (in_array($packName, ['blackCards', 'whiteCards', 'order'])) continue;

            // Pack already imported
            if (key_exists($packName, $already)) {
                logEvent("Pack \"{$packName}\" already imported.");
                continue;
            }

            // Add new entry
            $stmt = $this->db->prepare('INSERT INTO `cah_pack` (`name`,`title`) VALUES (:packName,:title)');
            $stmt->execute(['packName' => $packName, 'title' => $pack['name']]);

            $packId = $this->db->lastInsertId();

            $stmt = $this->db->prepare('INSERT INTO `cah_card` (`content`,`type`,`pick`,`pack`) VALUES (:content,:cardType,:pick,:pack)');

            foreach($pack['black'] as $id)
            {
                $card = $cards['blackCards'][$id];
                $stmt->execute(['content' => $card['text'], 'cardType' => CardType::Black, 'pick' => $card['pick'], 'pack' => $packId]);
            }

            foreach($pack['white'] as $id)
            {
                $card = $cards['whiteCards'][$id];
                $stmt->execute(['content' => $card, 'cardType' => CardType::White, 'pick' => 1, 'pack' => $packId]);
            }

            logEvent("Pack \"{$packName}\" successfully imported.");
        }
    }

    /**
     * Parse url into $_GET, $_REQUEST
     * @return array
     */
    static function parseURL()
    {
        $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        $query = explode('&', $query);

        $args = [];
        foreach ($query as $arg)
        {
            $arg = explode('=', $arg);
            if (count($arg) !== 2) continue;
            $args[$arg[0]] = $arg[1];
        }

        $_GET = array_merge($_GET, $args);
        $_REQUEST = array_merge($_REQUEST, $args);

        $url = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        if (strpos($url, self::$config['urlPrefix']) === 0) $url = substr($url, strlen(self::$config['urlPrefix']));
        $arr = explode('/', $url);
        return array_filter($arr);
    }

    /**
     * Return play url for user token
     * @param string $token
     * @return string
     */
    static function getPlayUrl($token)
    {
        // generate token for player / game (add to DB). Invalidate when we made a move
        return 'https://' . $_SERVER['HTTP_HOST'] . self::$config['urlPrefix'] . 'play/' . $token;
    }
}