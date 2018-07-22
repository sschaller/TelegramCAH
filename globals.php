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
    const NeedMore = 6;
    const PlayerJoined = 7;
}
abstract class PlayerJoinedStatus
{
    const NotJoined = 0;
    const Waiting = 1;
    const Joined = 2;
}

$localization = array(
    'no_game_found' => ['en' => 'No game found for this chat', 'de' => 'Kein Spiel gefunden für Chat'],
    'token_not_found' => ['en' => 'Oh! I don\'t recognize you. Please try again!', 'de' => 'Nicht erkannt. Bitte nochmals versuchen'],
    'submit' => ['en' => 'Submit', 'de' => 'Abschicken'],
    'no_game_call_start' => ['en' => 'No game running. Use /start', 'de' => 'Kein Spiel läuft. Zum Starten: /start'],
    'already_joined' => ['en' => 'You already joined the game.', 'de' => 'Du bist bereits im Spiel.'],
    'join_game' => ['en' => 'Join the Game!', 'de' => 'Spiel beitreten!'],
    'cant_play_cards' => ['en' => 'Can\'t play cards', 'de' => 'Konnte die Karten nicht spielen.'],
    'play_game' => ['en' => 'Play Cards Against Humanity', 'de' => 'Play Cards Against Humanity'],
    'player_choosing' => ['en' => "Answers:\n%s\n[%s](tg://user?id=%d) is choosing!"],
    'waiting_for' => ['en' => "Waiting for:\n%s"],
    'game_already_started' => ['en' => 'Game already started. Use /stop to restart'],
    'game_stopped' => ['en' => 'Game stopped, use /start to restart'],
    'waiting_more' => ['en' => "Waiting for *%d* more..."],
    'game_header' => ['en' => "*Round %d / %d*. %s's Card:\n%s\n"],
    'player_joined' => ['en' => "%s joined the game!"],
    'black_card_player_waiting' => ['en' => "You are the player with the black-card.<br />Still waiting for:<br /><ul>%s</ul>"],
    'black_card_player_need_more' => ['en' => "You are the player with the black-card.<br />Waiting for %d more player%s"],
    'picks_saved' => ['en' => "Your picks are saved"],
);

function translate($key, $language = 'en')
{
    global $localization;
    if (!key_exists($key, $localization)) return $key;
    if (!key_exists($language, $localization[$key])) return '';
    return $localization[$key][$language];
}

function escapeMarkdown($str)
{
    return addcslashes ($str, '_*[`');
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
* @param string $severity (DEBUG, INFO, WARNING, ERROR, CRITICAL)
*/
function logEvent($msg, $severity = 'INFO')
{
    $line = date('Y-m-d H:i:s') . ' - ' . str_pad($severity, 8) . ' - ' . $msg . PHP_EOL;
    file_put_contents('logs/log_' . date('Ymd') . '.txt', $line, FILE_APPEND);
}