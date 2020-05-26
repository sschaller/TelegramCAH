<?php

include_once('include/globals.php');

class User
{
    public $id;
    public $firstName;

    function __construct($user)
    {
        $this->id = $user['id'];
        $this->firstName = $user['first_name'];
    }
}

class Chat
{
    public $id;
    public $type;

    function __construct($chat)
    {
        $this->id = $chat['id'];
        $this->type = $chat['type'];
    }
}

class Message
{
    public $id;
    public $from;
    public $date;
    public $chat;
    public $text;

    function __construct($message)
    {
        $this->id = $message['message_id'];
        $this->from = new User($message['from']);
        $this->date = $message['date'];
        $this->chat = new Chat($message['chat']);
        $this->text = key_exists('text', $message) ? $message['text'] : '';
    }

    function log()
    {
        logEvent($this->id . ' ' . $this->from->firstName . ' ' . $this->date . ' ' . $this->chat->type);
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