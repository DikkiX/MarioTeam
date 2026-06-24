<?php

// GLS Track & Trace webhook-ontvanger.
// GLS stuurt een HTTP POST voor elke statuswijziging van een pakket.
// Dit endpoint slaat de status op in gls_pakket_status zodat zoek_traceer het kan uitlezen.
//
// Registreer deze URL bij GLS via api-portal.gls.nl → Track & Trace webhook.
// Optioneel: stel GLS_WEBHOOK_SECRET in .env in en geef die als bearer token door aan GLS,
// zodat alleen GLS dit endpoint kan aanroepen.

include_once $_SERVER['DOCUMENT_ROOT'] . '/include/db.inc';
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/gls_webhook_handler.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/include/env.php';

// Alleen POST accepteren.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Optioneel: bearer token verificatie.
$secret = getProjectEnvValue('GLS_WEBHOOK_SECRET');
if (is_string($secret) && trim($secret) !== '') {
    $authHeader = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $verwacht   = 'Bearer ' . trim($secret);
    if (!hash_equals($verwacht, $authHeader)) {
        http_response_code(401);
        exit;
    }
}

$rawJson = (string) file_get_contents('php://input');

if ($rawJson === '') {
    http_response_code(400);
    exit;
}

if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    exit;
}

$resultaat = verwerkGlsWebhookPayload($conn, $rawJson);

// GLS verwacht altijd HTTP 200 terug, anders herprobeert het.
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => $resultaat['ok'], 'message' => $resultaat['message']]);
