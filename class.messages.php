<?php

include_once('globals.php');

class User
{
    public $userId;
    public $firstName;

    function __construct($user)
    {
        $this->userId = $user['id'];
        $this->firstName = $user['first_name'];
    }
}

class Chat
{
    public $chatId;
    public $type;
    public $title;

    function __construct($chat)
    {
        $this->chatId = $chat['id'];
        $this->type = $chat['type'];
        $this->title = $chat['title'] ?: $chat['first_name'] ?: $chat['username'];
    }
}

class Message
{
    public $messageId;
    public $from;
    public $date;
    public $chat;
    public $text;

    function __construct($message)
    {
        $this->messageId = $message['message_id'];
        $this->from = new User($message['from']);
        $this->date = $message['date'];
        $this->chat = new Chat($message['chat']);
        $this->text = $message['text'];
    }

    function log()
    {
        logEvent($this->messageId . ' ' . $this->from->firstName . ' ' . $this->date . ' ' . $this->chat->type);
    }
}

class CallbackQuery
{
    public $callbackId;
    public $from;
    public $gameShortName;
    public $message;

    function __construct($callbackQuery)
    {
        $this->callbackId = $callbackQuery['id'];
        $this->from = new User($callbackQuery['from']);
        if (key_exists('game_short_name', $callbackQuery)) $this->gameShortName = $callbackQuery['game_short_name'];
        if (key_exists('message', $callbackQuery)) $this->message = new Message($callbackQuery['message']);
    }

    function log()
    {
        logEvent($this->callbackId . ' ' . $this->from->firstName . ' ' . $this->gameShortName);
        if ($this->message) $this->message->log();
    }
}