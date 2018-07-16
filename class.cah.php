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
        self::parseURL();

        if (!key_exists('token', $_REQUEST)) return;

        if ($_REQUEST['token'] == self::$config['botSecret'])
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
            return;
        }
        if ($_REQUEST['token'] == 'setup')
        {
            $successful = $this->bot->setWebhook('https://' . $_SERVER['HTTP_HOST'] . self::$config['urlPrefix'] . self::$config['botSecret'], array('message', 'callback_query'));
            echo 'Was ' . ($successful ? 'successful' : 'unsuccessful') . '<br />';
            prettyPrint($this->bot->getWebhookInfo());
            return;
        }
        if ($_REQUEST['token'] == 'cards')
        {
            $this->importCards();
            return;
        }

        $game = new Game($this->db);
        $success = $game->loadWithPlayerToken($_REQUEST['token']);

        $content = '';

        if ($success)
        {
            $game->loadGameState();

            if (key_exists('picks', $_POST))
            {
                // Check if we need as many picks

                // sanity checks

                header('Content-Type: application/json');
                $response = ['status' => JsonResult::Error];

                // picks submitted = picks required?
                if (count($_POST['picks']) != $game->blackCard['pick'])
                {
                    $response['text'] = translate('picks_not_matching');
                    echo $response;
                    return;
                }

                // TODO: Check if we got the card.id in our cards
                $found = true;
                foreach ($_POST['selected'] as $pick)
                {
                    // if (in_array())
                }

                // TODO: Remove cards from inventory, save order of picks

                $response['status'] = JsonResult::Success;
                $response['text'] = translate('picks_ok');
                return;
            }

            ob_start();
            include(TEMPLATE_DIR . 'cards.php');
            $content = ob_get_clean();
        } else {
            $message = translate('token_not_found');

            ob_start();
            include(TEMPLATE_DIR . 'message.php');
            $content = ob_get_clean();
        }

        // use less compiler
        $less = new lessc;
        include(TEMPLATE_DIR . 'default.php');
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
        return 'https://' . $_SERVER['HTTP_HOST'] . self::$config['urlPrefix'] . $token;
    }

    function drawCard($card)
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

        $data = array(
            'chat_id' => $message->chat->chatId,
            'game_short_name' => $config['shortName']
        );
        $this->bot->sendRequest('sendGame', $data);
    }

    function sendMessage($chatId, $text, $replyTo = false)
    {
        $data = [
            'chatId' => $chatId,
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

    static function parseURL()
    {
        $url = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        $arr = explode('/', $url);
        if (strpos($url, $config['urlPrefix']) === 0) $url = substr($url, strlen($config['urlPrefix']));
        $arr = array_filter($arr);

        $keys = array('token', 'chatId', 'playerId');
        foreach ($keys as $i => $key) {
            if (count($arr) <= $i) break;
            $_REQUEST[$key] = $arr[$i];
            $_GET[$key] = $arr[$i];
        }
    }

}