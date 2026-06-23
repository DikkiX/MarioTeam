<?php
/**
 * Testpagina: sync CHATGPT() met database-tools (zonder ChatGptMrM).
 *
 * Beveiliging: POST vereist hetzelfde geheim als CHAT_WORKER_SECRET in .env.
 * Zet CHATGPT_GEBRUIK_TOOLS=1 niet nodig hier — deze pagina zet tools altijd aan.
 *
 * Alleen gebruiken op test/staging, niet als klant-chat.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$baseDir = $documentRoot !== '' ? $documentRoot : __DIR__;

include_once $baseDir . '/include/env.php';
include_once $baseDir . '/include/ChatFunction.php';
include_once $baseDir . '/include/db.inc';

if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    exit('Databaseverbinding ontbreekt.');
}

function testChatGptToolsGeheimOk(): bool
{
    $required = getProjectEnvValue('CHAT_WORKER_SECRET');
    if ($required === null || trim($required) === '') {
        return false;
    }

    $given = '';
    if (isset($_POST['test_secret']) && is_string($_POST['test_secret'])) {
        $given = $_POST['test_secret'];
    } elseif (isset($_GET['secret']) && is_string($_GET['secret'])) {
        $given = $_GET['secret'];
    }

    return hash_equals(trim($required), trim($given));
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$bericht = '';
$assistant0 = '';
$antwoord = null;
$fout = '';
$toolChoiceWeergave = '';
$duurMs = null;
$voorbeeldBerichten = [
    'Waar is mijn bestelling 12345? Mijn e-mail is klant@voorbeeld.nl',
    'Hebben jullie Just Dance?',
    'Hebben jullie spellen die lijken op Xenoblade?',
    'Hoi, hoe gaat het?',
];

if ($isPost) {
    $bericht = isset($_POST['bericht']) ? trim((string) $_POST['bericht']) : '';
    $assistant0 = isset($_POST['assistant0']) ? trim((string) $_POST['assistant0']) : '';

    if (!testChatGptToolsGeheimOk()) {
        $fout = 'Ongeldig of ontbrekend test-geheim. Vul CHAT_WORKER_SECRET uit .env in.';
    } elseif ($bericht === '') {
        $fout = 'Vul een testbericht in.';
    } else {
        $toolChoice = chatGptBepaalToolKeuze($bericht, $assistant0);
        $toolChoiceWeergave = is_array($toolChoice)
            ? json_encode($toolChoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $toolChoice;

        $system = <<<'TXT'
Je bent een test-assistent voor Mario Team (games webshop).
Gebruik de beschikbare database-tools als de klant om een bestelling, voorraad of productaanraders vraagt.
Verzin geen order- of voorraadgegevens — alleen wat uit een tool komt.
Antwoord in het Nederlands, kort en duidelijk.
TXT;

        $mode = function_exists('getChatModelMode') ? getChatModelMode() : 2;
        $start = microtime(true);

        $antwoord = CHATGPT(
            $bericht,
            $system,
            0.2,
            $mode,
            [],
            1,
            true,
            $conn,
            $toolChoice
        );

        $duurMs = (int) round((microtime(true) - $start) * 1000);
    }
}

$geheimInGet = isset($_GET['secret']) && is_string($_GET['secret']) ? $_GET['secret'] : '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test CHATGPT + tools</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        h1 { font-size: 1.25rem; }
        label { display: block; font-weight: 600; margin-top: 1rem; }
        input[type="password"], input[type="text"], textarea {
            width: 100%; padding: 0.5rem; margin-top: 0.25rem; font: inherit;
        }
        textarea { min-height: 6rem; }
        button { margin-top: 1rem; padding: 0.5rem 1rem; font: inherit; cursor: pointer; }
        .hint { color: #555; font-size: 0.9rem; }
        .fout { background: #fee; border: 1px solid #c99; padding: 0.75rem; margin-top: 1rem; }
        .meta { background: #f4f4f4; padding: 0.75rem; margin-top: 1rem; font-size: 0.9rem; }
        .antwoord { background: #eef8ee; border: 1px solid #9c9; padding: 1rem; margin-top: 1rem; white-space: pre-wrap; }
        .voorbeelden { margin-top: 0.5rem; }
        .voorbeelden button { margin: 0.25rem 0.25rem 0 0; font-size: 0.85rem; }
    </style>
</head>
<body>
    <h1>Test: CHATGPT() met database-tools</h1>
    <p class="hint">
        Test sync <code>CHATGPT()</code> + <code>chat_tools.php</code> zonder ChatGptMrM.
        Vereist <code>CHAT_WORKER_SECRET</code> en <code>OPENAI_API_KEY</code> in .env.
        Log bij tool-calls: <code>storage/logs/chat_worker.log</code> (regels met <code>CHATGPT functie aangeroepen</code>).
    </p>

    <?php if ($fout !== ''): ?>
        <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" id="test-form">
        <label for="test_secret">Test-geheim (CHAT_WORKER_SECRET)</label>
        <input type="password" name="test_secret" id="test_secret" autocomplete="off" required
            value="<?= htmlspecialchars($geheimInGet, ENT_QUOTES, 'UTF-8') ?>">

        <label for="assistant0">Optioneel: system0-label (bijv. <code>ProductFinder</code>)</label>
        <input type="text" name="assistant0" id="assistant0" placeholder="Leeg laten of ProductFinder"
            value="<?= htmlspecialchars($assistant0, ENT_QUOTES, 'UTF-8') ?>">

        <label for="bericht">Testbericht (als klant)</label>
        <textarea name="bericht" id="bericht" required><?= htmlspecialchars($bericht, ENT_QUOTES, 'UTF-8') ?></textarea>

        <div class="voorbeelden">
            <span class="hint">Voorbeelden:</span><br>
            <?php foreach ($voorbeeldBerichten as $vb): ?>
                <button type="button" class="voorbeeld-knop" data-tekst="<?= htmlspecialchars($vb, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(mb_strimwidth($vb, 0, 48, '…'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endforeach; ?>
        </div>

        <button type="submit">Verstuur naar CHATGPT (tools aan)</button>
    </form>

    <?php if ($isPost && $fout === '' && $antwoord !== null): ?>
        <div class="meta">
            <strong>tool_choice:</strong> <?= htmlspecialchars($toolChoiceWeergave, ENT_QUOTES, 'UTF-8') ?><br>
            <strong>Duur:</strong> <?= (int) $duurMs ?> ms
        </div>
        <div class="antwoord"><?= htmlspecialchars((string) $antwoord, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <script>
        document.querySelectorAll('.voorbeeld-knop').forEach(function (knop) {
            knop.addEventListener('click', function () {
                document.getElementById('bericht').value = knop.getAttribute('data-tekst') || '';
            });
        });
    </script>
</body>
</html>
