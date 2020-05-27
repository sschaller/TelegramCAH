<?php

include_once('include/globals.php');
include_once('src/Messages.php');

interface iBotSubscriber
{
    function handleMessage($message);
    function handleCallbackQuery($callbackQuery);
}

class TelegramBot
{
    /* @var string $name */
    private $name;

    /* @var string $token */
    private $token;

    /* @var iBotSubscriber $subscriber */
    private $subscriber;

    function __construct($name, $token)
    {
        $this->name = $name;
        $this->token = $token;
    }

    function subscribe($subscriber)
    {
        $this->subscriber = $subscriber;
    }

    /**
     * @link https://core.telegram.org/bots/api#setwebhook
     * @param string $callbackUrl
     * @param array $allowedUpdates
     * @return bool successful
     */
    function setWebhook($callbackUrl, $allowedUpdates = array())
    {
        $data = array(
            'url' => $callbackUrl,
            'allowed_updates' => $allowedUpdates
        );

        return $this->sendRequest('setWebhook', $data);
    }

    /**
     * @link https://core.telegram.org/bots/api#getwebhookinfo
     * @return array|bool
     */
    function getWebhookInfo()
    {
        return $this->sendRequest('getWebhookInfo');
    }

    /**
     * @link https://core.telegram.org/bots/api#deletewebhook
     * @return array|bool
     */
    function deleteWebhook()
    {
        return $this->sendRequest('deleteWebhook');
    }

    /**
     * Differentiate between message and callback_query -> route to correct method
     * @param $update
     */
    function receiveUpdate($update)
    {
        if (!$this->subscriber) return;

        if (key_exists('message', $update))
        {
            $message = new Message($update['message']);
            $this->subscriber->handleMessage($message);
        } else if (key_exists('callback_query', $update))
        {
            $callbackQuery = new CallbackQuery($update['callback_query']);
            $this->subscriber->handleCallbackQuery($callbackQuery);
        }
    }

    /**
     * Send a request to Telegram API using method & data
     * @param string $method
     * @param array $data
     * @return bool|array
     */
    function sendRequest($method, $data = array())
    {
        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;

        if (count($data) > 0) {
            $url .= '?' . http_build_query($data);
        }

        $context = stream_context_create(array(
            'http' => array('ignore_errors' => true),
        ));
        $response = file_get_contents($url, false, $context);

        if (!$response) {
            logEvent($method . ': Empty Response', EventSeverity::Error);
            return false;
        }

        $responseObject = json_decode($response, true);

        if (!$responseObject || !$responseObject['ok']) {
            logEvent($method . ': ' . $response, EventSeverity::Error);
            return false;
        }

        return $responseObject['result'];
    }
}