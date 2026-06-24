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
    static $cached = null;

    if ($cached instanceof PDO) {
        return $cached;
    }

    // db.inc zet $conn in de scope van deze functie (niet in $GLOBALS).
    include_once toolsExpandingProjectRoot() . '/include/db.inc';

    if (!isset($conn) || !($conn instanceof PDO)) {
        http_response_code(500);
        exit('Databaseverbinding ontbreekt.');
    }

    $cached = $conn;

    return $cached;
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
            'titel' => 'Bestelling opzoeken',
            'beschrijving' => 'Direct zoek_bestelling — geen GPT, ruwe tool-output.',
            'type' => 'direct',
            'url' => 'bestelling-zonder-ai.php',
            'functie' => 'voerChatToolUit(zoek_bestelling)',
        ],
        [
            'titel' => 'Bestelling opzoeken',
            'beschrijving' => 'CHATGPT() + zoek_bestelling — GPT maakt klantantwoord uit tool-data.',
            'type' => 'gpt',
            'url' => 'bestelling-met-ai.php',
            'functie' => 'CHATGPT() → zoek_bestelling',
        ],
        [
            'titel' => 'Productvoorraad opzoeken',
            'beschrijving' => 'Direct zoek_productvoorraad — één zoekterm, max. 5 producten.',
            'type' => 'direct',
            'url' => 'voorraad-zonder-ai.php',
            'functie' => 'voerChatToolUit(zoek_productvoorraad)',
        ],
        [
            'titel' => 'Productvoorraad opzoeken',
            'beschrijving' => 'CHATGPT() + zoek_productvoorraad — prijs/voorraad in natuurlijke taal.',
            'type' => 'gpt',
            'url' => 'voorraad-met-ai.php',
            'functie' => 'CHATGPT() → zoek_productvoorraad',
        ],
        [
            'titel' => 'Productaanraders opzoeken',
            'beschrijving' => 'Direct zoek_productaanraders — meerdere zoektermen, alleen op voorraad.',
            'type' => 'direct',
            'url' => 'aanraders-zonder-ai.php',
            'functie' => 'voerChatToolUit(zoek_productaanraders)',
        ],
        [
            'titel' => 'Productaanraders opzoeken',
            'beschrijving' => 'CHATGPT() + zoek_productaanraders — suggesties via chat_product_zoek.php.',
            'type' => 'gpt',
            'url' => 'aanraders-met-ai.php',
            'functie' => 'CHATGPT() → zoek_productaanraders',
        ],
        [
            'titel' => 'Zending / track & trace',
            'beschrijving' => 'Direct zoek_traceer — PostNL/GLS status, geen traceernummer in output.',
            'type' => 'direct',
            'url' => 'traceer-zonder-ai.php',
            'functie' => 'voerChatToolUit(zoek_traceer)',
        ],
        [
            'titel' => 'Zending / track & trace',
            'beschrijving' => 'CHATGPT() + zoek_traceer — bezorgstatus in klanttaal, nooit traceernummer.',
            'type' => 'gpt',
            'url' => 'traceer-met-ai.php',
            'functie' => 'CHATGPT() → zoek_traceer',
        ],
        [
            'titel' => 'Besteladres wijzigen',
            'beschrijving' => 'Direct wijzig_bestelling_adres — huidig adres ophalen en opslaan.',
            'type' => 'direct',
            'url' => 'adres-zonder-ai.php',
            'functie' => 'voerChatToolUit(wijzig_bestelling_adres)',
        ],
        [
            'titel' => 'Besteladres wijzigen',
            'beschrijving' => 'CHATGPT() + wijzig_bestelling_adres — adreswijziging in klanttaal.',
            'type' => 'gpt',
            'url' => 'adres-met-ai.php',
            'functie' => 'CHATGPT() → wijzig_bestelling_adres',
        ],
    ];
}
