<?php

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