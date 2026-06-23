<?php
/**
 * Gedeelde bootstrap voor tools-expanding (testbibliotheek).
 * - Database
 * - Eenvoudige wachtwoord-login (sessie)
 */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

function toolsExpandingProjectRoot(): string
{
    $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

    return $documentRoot !== '' ? $documentRoot : dirname(__DIR__);
}

function toolsExpandingRequireDatabase(): PDO
{
    static $conn = null;

    if ($conn instanceof PDO) {
        return $conn;
    }

    include_once toolsExpandingProjectRoot() . '/include/db.inc';

    if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof PDO)) {
        http_response_code(500);
        exit('Databaseverbinding ontbreekt.');
    }

    $conn = $GLOBALS['conn'];

    return $conn;
}

/** Wachtwoord voor toegang tot tools-expanding (alleen test/staging). */
function toolsExpandingVerwachtWachtwoord(): string
{
    return 'Obed';
}

function toolsExpandingIsIngelogd(): bool
{
    return !empty($_SESSION['tools_expanding_ok']);
}

function toolsExpandingProbeerInloggen(string $wachtwoord): bool
{
    if (!hash_equals(toolsExpandingVerwachtWachtwoord(), trim($wachtwoord))) {
        return false;
    }

    $_SESSION['tools_expanding_ok'] = true;

    return true;
}

function toolsExpandingUitloggen(): void
{
    unset($_SESSION['tools_expanding_ok']);
}

/**
 * Redirect naar index als niet ingelogd.
 */
function toolsExpandingRequireLogin(): void
{
    if (!toolsExpandingIsIngelogd()) {
        header('Location: index.php');
        exit;
    }
}

/** Relatief pad terug naar de hub. */
function toolsExpandingHubUrl(): string
{
    return 'index.php';
}

/**
 * Geregistreerde tools in deze bibliotheek (hub-kaarten).
 *
 * type: 'gpt' = met OpenAI | 'direct' = alleen PHP / chat_tools.php
 *
 * @return list<array{titel: string, beschrijving: string, type: string, url: string, functie: string}>
 */
function toolsExpandingCatalogus(): array
{
    return [
        [
            'titel' => 'CHATGPT + tools (sync)',
            'beschrijving' => 'Test sync CHATGPT() met database-tools — zelfde keten als ChatGptMrM, zonder worker.',
            'type' => 'gpt',
            'url' => 'gpt-sync-tools.php',
            'functie' => 'CHATGPT() → chat_tools.php',
        ],
        [
            'titel' => 'Bestelling opzoeken',
            'beschrijving' => 'Direct zoek_bestelling via voerChatToolUit() — geen GPT, wel dezelfde tool als de chatbot.',
            'type' => 'direct',
            'url' => 'bestelling-zonder-ai.php',
            'functie' => 'voerChatToolUit(zoek_bestelling)',
        ],
    ];
}
