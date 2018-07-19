<?php

include_once('class.telegrambot.php');
include_once('lessc.inc.php');

include_once('class.game.php');

define('TEMPLATE_DIR', 'templates/');
define('DIR', dirname(__FILE__));
define('ROUNDS_DEFAULT', 10);

class CardsAgainstHumanityGame implements iMessages, iBotSubscriber
{
	static public $config = null;
    private $db, $bot, $whitelist;

    function __construct()
    {
        if (self::$config == null) self::$config = include('include/config.php');

        $this->db = new PDO("mysql:host=localhost;dbname=" . self::$config['dbName'], self::$config['dbUser'], self::$config['dbPassword']);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->bot = new TelegramBot(self::$config['shortName'], self::$config['telegramAPIToken']);
        $this->bot->subscribe($this);
        $this->whitelist = self::$config['whitelist'];
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
                $this->receiveTelegramUpdate();
                return;
            case 'play':
                if (count($args) < 2) break;
                $game = new Game($this->db, $this);
                $game->loadWithPlayerToken($args[1]);
                $this->showContent($game);
                return;
            case 'setup':
                $successful = $this->bot->setWebhook('https://' . $_SERVER['HTTP_HOST'] . self::$config['urlPrefix'] . self::$config['botSecret'], array('message', 'callback_query'));
                logEvent('Webhook set: ' . ($successful ? 1 : 0));
                // prettyPrint($this->bot->getWebhookInfo());
                return;
            case 'cards':
                $this->importCards();
                return;
            case 'reset':
                $sql = file_get_contents(__DIR__ . '/tables.sql');
                $this->db->exec($sql);
                $this->importCards();
                return;
            case 'test':
                $stmt = $this->db->query('SELECT p.userId, c.chatId FROM `cah_player` p LEFT JOIN `cah_game` c ON p.chat = c.id WHERE p.firstName="Sebastian"');
                $player = $stmt->fetch();
                if (!$player) break;

                $game = new Game($this->db, $this);
                $game->loadGame($player['chatId'], $player['userId']);

                $message = $this->bot->sendRequest('sendMessage', [
                    'chat_id' => $game->chatId,
                    'text' => 'hm',
                    'reply_to_message_id' => $game->messageId,
                ]);

                print_r($message);

                return;
        }
    }

    /**
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
        } else if (!$game->player->joined && (!key_exists('action', $_REQUEST) || $_REQUEST['action'] != 'join'))
        {
            $message = translate('join_game');
            include(TEMPLATE_DIR . 'button.php');
        } else {

            $game->join();
            $message = $game->loadGameState();

            if ($message)
            {
                $this->sendMessage($game->chatId, $message['text']);
            }

            include(TEMPLATE_DIR . 'cards.php');
        }

        $content = ob_get_clean();
        include(TEMPLATE_DIR . 'default.php');
    }

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

                $game->loadGameState();

                $success = $game->playCards($_POST['picks']);
                if (!$success)
                {
                    $response['status']= JsonResult::Error;
                    $response['text'] = translate('cant_play_cards');
                    break;
                }

                $response['status'] = JsonResult::Success;

                break;
        }

        header('Content-Type: application/json');
        echo json_encode($response);
    }

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

        $token = $game->player->generateToken();

        // generate token for player / game (add to DB). Invalidate when we made a move
        return 'https://' . $_SERVER['HTTP_HOST'] . self::$config['urlPrefix'] . 'play/' . $token;
    }

    /**
     * @param Card $card
     * @param int $required
     */
    function drawCard($card, $required = 0)
    {
        $content = str_replace('_', '<span>____</span>', $card->content);
        include('templates/card.php');
    }

    /**
     * @param Game $game
     * @param bool $silent
     * @return bool
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
            $this->bot->sendRequest('deleteMessage', [
                'chat_id' => $game->chatId,
                'message_id' => $game->messageId
            ]);
        }

        $game->setMessageId($message['message_id']);
    }

    function sendMessage($chatId, $text, $replyTo = false)
    {
        $data = [
            'chat_id' => $chatId,
            'text' => $text
        ];

        if ($replyTo != false)
        {
            $data['disable_notification'] = true;
            $data['reply_to_message_id'] = $replyTo;
        }

        $this->bot->sendRequest('sendMessage', $data);
    }

    /** Tries to edit message, if not possible creates new message
     * @param Game $game
     * @param string $text
     * @return bool success
     */
    function editGameMessage($game, $text)
    {
        $message = $this->bot->sendRequest('editMessageText', [
            'chat_id' => $game->chatId,
            'message_id' => $game->messageId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
        if (!$message)
        {
            $this->sendGame($game);
            return false;
        }

        return true;
    }

    /**
     * @param Message $message
     */
    function handleMessage($message)
    {
        $command = explode(' ', $message->text);

        switch ($command[0])
        {
            case '/start':
                // Ignore anyone not on whitelist
                if ($this->whitelist && !in_array($message->from->userId, $this->whitelist)) break;

                $command = explode(' ', $message->text);

                $args = [];

                if (count($command) > 1 && is_numeric($command[1]) && intval($command[1], 10) > 0) {
                    $args['rounds'] = intval($command[1], 10);
                }

                $game = new Game($this->db, $this);
                $success = $game->startGame($message->chat->chatId, $args);
                if ($success)
                {
                    $this->sendGame($game);
                } else {
                    $this->sendMessage($game->chatId, translate('game_already_started'), $message->id);
                }

                break;
            case '/join':
                // Add user to game, set confirm flag to 1 (so it waits for him)
                $game = new Game($this->db, $this);

                $success = $game->loadForChatAndUser($message->chat->chatId, $message->from->userId, $message->from->firstName);
                if (!$success)
                {
                    $this->sendMessage($game->chatId, translate('no_game_call_start'), $message->id);
                    break;
                }

                $success = $game->join();
                if (!$success)
                {
                    $this->sendMessage($game->chatId, translate('already_joined'), $message->id);
                    break;
                }

                break;
            case '/leave':
                // Remove user from game (just delete player object for this chat)
                $game = new Game($this->db, $this);

                $success = $game->loadForChatAndUser($message->chat->chatId, $message->from->userId, $message->from->firstName);
                if (!$success)
                {
                    $this->sendMessage($game->chatId, translate('no_game_call_start'), $message->id);
                    break;
                }
                $wasJoined = $game->player->joined;

                $success = $game->leave(); // delete player again (was created just above)
                if (!$success)
                {
                    $this->sendMessage($game->chatId, translate('cant_leave_now'), $message->id);
                    break;
                }

                if (!$wasJoined)
                {
                    $this->sendMessage($game->chatId, translate('not_joined'), $message->id);
                    break;
                }

                $this->sendMessage($game->chatId, translate('left_successfully'), $message->id);
                break;
            case '/stop':
                // Delete game (+ all players)
                $game = new Game($this->db, $this);
                $found = $game->loadGame($message->chat->chatId);
                if ($found)
                {
                    $game->delete();
                    $this->sendMessage($game->chatId, translate('game_stopped'), $message->id);
                } else {
                    $this->sendMessage($message->chat->chatId, translate('no_game_call_start'), $message->id);
                }
                break;

        }
    }

    /**
     * @param CallbackQuery $callbackQuery
     */
    function handleCallbackQuery($callbackQuery)
    {
        if (!$callbackQuery->message) return;

        $url = $this->getPlayerUrl($callbackQuery->message->chat->chatId, $callbackQuery->from->userId, $callbackQuery->from->firstName);

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

    function importCards()
    {
        $json = file_get_contents('cards.json');
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

    function receiveTelegramUpdate()
    {
        try
        {
            $json = json_decode(file_get_contents('php://input'), true);
            $this->bot->receiveUpdate($json);
        }
        catch(PDOException $e)
        {
            logEvent($e->__toString());
        }
        catch(Exception $e)
        {
            logEvent($e->getMessage());
        }
    }

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

}