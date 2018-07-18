<?php

include_once('class.telegrambot.php');
include_once('lessc.inc.php');

include_once('class.game.php');

define('TEMPLATE_DIR', 'templates/');
define('DIR', dirname(__FILE__));
define('ROUNDS_DEFAULT', 10);

class CardsAgainstHumanityGame extends TelegramBotSubscriber
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
                $game = new Game($this->db);
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

            $game->player->join();
            $message = $game->loadGameState();

            if ($message)
            {
                logEvent('Chat: '. $game->chatId);
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
                $game = new Game($this->db);
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
        $game = new Game($this->db);
        $success = $game->startGameForChatAndUser($chatId, $playerId, $firstName);

        // Could not create game
        if (!$success) return null;

        $token = $game->player->generateToken();

        // generate token for player / game (add to DB). Invalidate when we made a move
        return 'https://' . $_SERVER['HTTP_HOST'] . self::$config['urlPrefix'] . 'play/' . $token;
    }

    function drawCard($card, $required = 0)
    {
        $content = str_replace('_', '<span>____</span>', $card['content']);
        include('templates/card.php');
    }

    function startGame($message)
    {
        $command = explode(' ', $message->text);

        $game = new Game($this->db);
        $success = $game->startGameForChatAndUser($message->chat->chatId, $message->from->userId, $message->from->firstName, true);

        if (count($command) > 1 && is_numeric($command[1]) && intval($command[1], 10) > 0) {
            $roundsToPlay = intval($command[1], 10);
        }

        if (!$success) {
            $this->sendMessage($message->chat->chatId, $message->text, $message->id);
            return;
        }

        $this->sendGame($game);
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
                $this->startGame($message);
                break;
            case '/join':
                // Add user to game, set confirm flag to 1 (so it waits for him)
                $game = new Game($this->db);

                $success = $game->startGameForChatAndUser($message->chat->chatId, $message->from->userId, $message->from->firstName);
                if (!$success)
                {
                    $this->sendMessage($game->chatId, translate('no_game_call_start'), $message->id);
                    break;
                }

                $success = $game->player->join();
                if (!$success)
                {
                    $this->sendMessage($game->chatId, translate('already_joined'), $message->id);
                    break;
                }


                // Update Scoreboard

                // Send confirm message - tell waiting for you
                break;
            case '/leave':
                // Remove user from game (just delete player object for this chat)
                $game = new Game($this->db);

                $success = $game->startGameForChatAndUser($message->chat->chatId, $message->from->userId, $message->from->firstName);
                if (!$success)
                {
                    $this->sendMessage($game->chatId, translate('no_game_call_start'), $message->id);
                    break;
                }
                $game->player->delete();
                // Update Scoreboard

                // Send confirm message - check game state, round will be over if master
                break;
            case '/stop':
                // Delete game (+ all players)
                $game = new Game($this->db);

                $success = $game->startGameForChatAndUser($message->chat->chatId, $message->from->userId, $message->from->firstName);
                if (!$success)
                {
                    $this->sendMessage($game->chatId, translate('no_game_call_start'), $message->id);
                    break;
                }
                $game->delete();
                break;
            case '/restart':
                // Check if game is running. Otherwise tell them to use start
                $game = new Game($this->db);
                $success = $game->startGameForChatAndUser($message->chat->chatId, $message->from->userId, $message->from->firstName);
                if (!$success)
                {
                    $this->sendMessage($message->chat->chatId, translate('no_game_call_start'), $message->id);
                    break;
                }
                $game->delete();

                // Restart game
                $this->startGame($message);
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
                $stmt->execute(['content' => $card['text'], 'cardType' => Game::BlackCard, 'pick' => $card['pick'], 'pack' => $packId]);
            }

            foreach($pack['white'] as $id)
            {
                $card = $cards['whiteCards'][$id];
                $stmt->execute(['content' => $card, 'cardType' => Game::WhiteCard, 'pick' => 1, 'pack' => $packId]);
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