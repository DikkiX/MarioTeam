<?php
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';

// Dit script is nu nog simpel.
// In US06 loggen we alleen dat de worker gestart is.
// In US07 krijgt dit script de echte queue-logica.

ignore_user_abort(true);
set_time_limit(0);

function schrijfWorkerLog($message)
{
    $logMap = $_SERVER['DOCUMENT_ROOT'] . '/storage/logs';
    $logBestand = $logMap . '/chat_worker.log';

    if (!is_dir($logMap)) {
        mkdir($logMap, 0775, true);
    }

    $regel = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logBestand, $regel, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Alleen POST is toegestaan.');
}

$messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;

// We loggen het bericht-id zodat je later kunt zien dat de worker echt gestart is.
schrijfWorkerLog('Worker gestart voor bericht_id ' . $messageId);

echo 'ok';
