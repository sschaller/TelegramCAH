<?php

abstract class CardType
{
    const CardBlack = 0;
    const CardWhite = 1;
}

$localization = array(
    'no_game_found' => ['en' => 'No game found for this chat', 'de' => 'Kein Spiel gefunden für Chat']
);

function translate($key, $language = 'en')
{
    global $localization;
    if (!key_exists($key, $localization)) return '';
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
* @param string $severity (DEBUG, INFO, WARNING, ERROR, CRITICAL)
*/
function logEvent($msg, $severity = 'INFO')
{
    $line = date('Y-m-d H:i:s') . ' - ' . str_pad($severity, 8) . ' - ' . $msg . PHP_EOL;
    file_put_contents('logs/log_' . date('Ymd') . '.txt', $line, FILE_APPEND);
    echo $line . '<br />';
}