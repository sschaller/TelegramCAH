<?php

abstract class CardType
{
    const Black = 0;
    const White = 1;
}
abstract class JsonResult
{
    const Error = 0;
    const Success = 1;
    const Invalid = 2;
}
abstract class MessageType
{
    const None = 0;
    const NewGame = 1;
    const NewRound = 2;
    const RoundUpdate = 3;
    const PickWinner = 4;
    const NewScore = 5;
    const PlayerJoined = 6;
    const GameEnded = 7;
}
abstract class PlayerJoinedStatus
{
    const NotJoined = 0;
    const Joined = 1;
}

abstract class EventSeverity
{
    const Info = 0;
    const Debug = 1;
    const Warning = 2;
    const Error = 3;
}

$localization = array(
    "no_game_found" => ["en" => "No game found for this chat"],
    "token_not_found" => ["en" => "Oh! I don't recognize you. Please try again!"],
    "submit" => ["en" => "Submit"],
    "no_game_call_start" => ["en" => "No game running. Use /start"],
    "already_joined" => ["en" => "You already joined the game."],
    "join_game" => ["en" => "Join the Game!"],
    "cant_play_cards" => ["en" => "Can't play cards"],
    "play_game" => ["en" => "Play Cards Against Humanity"],
    "player_choosing" => ["en" => "Answers:\n%s\n<a href=\"tg://user?id=%d\">%s</a> picks a winner!"],
    "waiting_for" => ["en" => "Waiting for:\n%s"],
    "game_already_started" => ["en" => "Game already started. Use /stop to restart"],
    "game_stopped" => ["en" => "Game stopped, use /start to restart"],
    "waiting_more" => ["en" => "Waiting for <b>%d</b> more..."],
    "game_header" => ["en" => "<b>Round %d / %d</b>. %s's <b>black card</b>:\n%s\n"],
    "player_joined" => ["en" => "%s joined the game!"],
    "black_card_player_waiting" => ["en" => "You are the player with the black card.<br />Still waiting for:<br /><ul>%s</ul>"],
    "black_card_player_need_more" => ["en" => "You are the player with the black card.<br />Waiting for %d more player%s"],
    "picks_saved" => ["en" => "Your pick was saved"],
    "nr_player" => ["en" => "%d Player"],
    "player_scored" => ["en" => "Answers:\n%s\n\n%s won!\n\nNew Scores:\n%s"],
    "final_score" => ["en" => "The Game has ended.\n\n<b>Final Scores:</b>\n%s"],
    "pick_best" => ["en" => "Pick Best"],
    "short_header" => ["en" => "%s's black card:\n"],
);

function translate($key, $language = 'en')
{
    global $localization;
    if (!key_exists($key, $localization)) return $key;
    if (!key_exists($language, $localization[$key])) return '';
    return $localization[$key][$language];
}

/**
 * @param array $object
 */
function prettyPrint($object)
{
    echo '<pre>' . json_encode($object, JSON_PRETTY_PRINT) . '</pre>';
}

/**
* log event
* @param string $msg
* @param EventSeverity $severity
*/
function logEvent($msg, $severity = EventSeverity::Info)
{
    $severities = ['INFO', 'DEBUG', 'WARNING', 'ERROR'];
    $line = date('Y-m-d H:i:s') . ' - ' . str_pad($severities[$severity], 8) . ' - ' . $msg . PHP_EOL;

    if (!file_exists('logs/')) {
        mkdir("logs/", 0700);
    }
    file_put_contents('logs/log_' . date('Ymd') . '.txt', $line, FILE_APPEND);
}