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
    const DEBUG = false;

    /* @var $subscriber iBotSubscriber */
    private $name, $token, $subscriber;

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

    function getWebhookInfo()
    {
        return $this->sendRequest('getWebhookInfo');
    }

    function deleteWebhook()
    {
        return $this->sendRequest('deleteWebhook');
    }

    function receiveUpdate($update)
    {
        if (!$this->subscriber) return;

        if (self::DEBUG) logEvent(json_encode($update, JSON_PRETTY_PRINT));

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
     * @param string $method
     * @param array $data
     * @return bool|array
     */
    function sendRequest($method, $data = array())
    {
        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);

        if (is_array($data))
        {
            $query = http_build_query($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response)
        {
            logEvent($method . ': Empty Response', 'ERROR');
            return false;
        }

        $response = json_decode($response, true);

        if (!$response['ok'])
        {
            logEvent($method . ': ' . $response['description'], 'ERROR');
            return false;
        }

        if (!empty($response['description']))
        {
            logEvent($method . ': ' . $response['description']);
        }

        return $response['result'];
    }
}