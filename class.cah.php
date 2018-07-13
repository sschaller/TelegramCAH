<?php

include_once('globals.php');
include_once('class.telegrambot.php');
include_once('lessc.inc.php');

define('TEMPLATE_DIR', 'templates/');
define('DIR', dirname(__FILE__));

class CardsAgainstHumanityGame extends TelegramBotSubscriber
{
    static public $config = null;
    private $db, $bot, $whitelist, $game, $player;

    public $blackCard, $whiteCards;

    const BlackCard = 0;
    const WhiteCard = 1;

    function __construct()
    {
        if (self::$config == null) self::$config = include('include/config.php');

        $this->db = new PDO("mysql:host=localhost;dbname=" . self::$config['dbName'], self::$config['dbUser'], self::$config['dbPassword']);
        $this->bot = new TelegramBot(self::$config['shortName'], self::$config['telegramAPIToken']);
        $this->bot->subscribe($this);
        $this->whitelist = self::$config['whitelist'];
    }

    function main()
    {
        self::parseURL();

        if ($_REQUEST['token'] == self::$config['botSecret'])
        {
            // $update = $this->bot->receiveUpdate();
            $json = json_decode(file_get_contents('php://input'), true);
            $this->bot->receiveUpdate($json);
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
            $json = file_get_contents('cards.json');
            $cards = json_decode($json, true);

            $already = [];
            $stmt = $this->db->query('SELECT `name`, `id` FROM `cah_pack`');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            {
                $already[$row['name']] = $row['id'];
            }

            foreach ($cards as $shortName => $sets)
            {
                if (in_array($shortName, ['blackCards', 'whiteCards'])) continue;

                // Pack already imported
                if (key_exists($shortName, $already)) {
                    logEvent("Pack {$shortName} already imported. Skip!");
                    continue;
                }

                // Add new entry
                $this->db->prepare('INSERT INTO `cah_pack` (`name`,`title`,`official`) VALUES (:name,:title,:official)');
            }

            echo '<pre>' . json_encode($cards, JSON_PRETTY_PRINT) . '</pre>';
        }

        // use less compiler
        $less = new lessc;

        include(TEMPLATE_DIR . 'default.html');
    }

    function getPlayerUrl($chatId, $playerId)
    {
        // generate token for player / game (add to DB). Invalidate when we made a move
        return 'https://' . $_SERVER['HTTP_HOST'] . self::$config['urlPrefix'] . 'play/' . $chatId . '/' . $playerId;
    }

    function loadGameAndPlayer($token)
    {
        // $this->player = get Player From token
        $this->player = new Player($this->db);
        $this->player->loadByToken($token);

    }

    static function parseURL()
    {
        $url = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        if (strpos($url, self::$config['urlPrefix']) === 0) $url = substr($url, strlen(self::$config['urlPrefix']));
        $arr = explode('/', $url);
        $arr = array_filter($arr);

        $keys = array('token', 'chatId', 'playerId');
        foreach ($keys as $i => $key) {
            if (count($arr) <= $i) break;
            $_REQUEST[$key] = $arr[$i];
            $_GET[$key] = $arr[$i];
        }
    }

    /**
     * @param Message $message
     */
    function handleMessage($message)
    {
        // Ignore anyone not on whitelist
        if ($this->whitelist && !in_array($message->from->userId, $this->whitelist)) return;

        switch ($message->text)
        {
            case '/start':
                $data = array(
                    'chat_id' => $message->chat->chatId,
                    'game_short_name' => self::$config['shortName']
                );

                $this->bot->sendRequest('sendGame', $data);
                break;
        }
    }

    /**
     * @param CallbackQuery $callbackQuery
     */
    function handleCallbackQuery($callbackQuery)
    {
        if (!$callbackQuery->message) return;
        $url = $this->getPlayerUrl($callbackQuery->message->chat->chatId, $callbackQuery->from->userId);

        $data = array(
            'callback_query_id' => $callbackQuery->callbackId,
            'url' => $url
        );

        $this->bot->sendRequest('answerCallbackQuery', $data);
    }

    function drawCard($card)
    {
        include('templates/card.html');
    }

}