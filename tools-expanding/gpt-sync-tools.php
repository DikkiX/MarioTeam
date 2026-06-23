<?php
/**
 * Tool-test MET GPT: sync CHATGPT() + chat_tools.php (geen worker).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

include_once toolsExpandingProjectRoot() . '/include/ChatFunction.php';

function gptSyncToolsVoorbeeldBerichten(): array
{
    return [
        'Waar is mijn bestelling 12345? Mijn e-mail is klant@voorbeeld.nl',
        'Hebben jullie Just Dance?',
        'Hebben jullie spellen die lijken op Xenoblade?',
        'Hoi, hoe gaat het?',
    ];
}

function gptSyncToolsSystemPrompt(): string
{
    return <<<'TXT'
Je bent een test-assistent voor Mario Team (games webshop).
Gebruik de beschikbare database-tools als de klant om een bestelling, voorraad of productaanraders vraagt.
Verzin geen order- of voorraadgegevens — alleen wat uit een tool komt.
Antwoord in het Nederlands, kort en duidelijk.
TXT;
}

/**
 * Verwerkt formulier → chatGptBepaalToolKeuze() → CHATGPT(..., true) → antwoord.
 */
function gptSyncToolsVerwerk(PDO $conn, string $bericht, string $assistant0): array
{
    if ($bericht === '') {
        return ['fout' => 'Vul een testbericht in.', 'antwoord' => null, 'tool_choice' => '', 'duur_ms' => null];
    }

    $toolChoice = chatGptBepaalToolKeuze($bericht, $assistant0);
    $toolChoiceWeergave = is_array($toolChoice)
        ? json_encode($toolChoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : (string) $toolChoice;

    $mode = function_exists('getChatModelMode') ? getChatModelMode() : 2;
    $start = microtime(true);

    // HIER: CHATGPT() met tools — zie ChatFunction.php → chatGptMetTools() → chat_tools.php
    $antwoord = CHATGPT(
        $bericht,
        gptSyncToolsSystemPrompt(),
        0.2,
        $mode,
        [],
        1,
        true,
        $conn,
        $toolChoice
    );

    return [
        'fout' => '',
        'antwoord' => $antwoord,
        'tool_choice' => $toolChoiceWeergave,
        'duur_ms' => (int) round((microtime(true) - $start) * 1000),
    ];
}

$bericht = '';
$assistant0 = '';
$antwoord = null;
$fout = '';
$toolChoiceWeergave = '';
$duurMs = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $bericht = isset($_POST['bericht']) ? trim((string) $_POST['bericht']) : '';
    $assistant0 = isset($_POST['assistant0']) ? trim((string) $_POST['assistant0']) : '';
    $resultaat = gptSyncToolsVerwerk($conn, $bericht, $assistant0);
    $fout = $resultaat['fout'];
    $antwoord = $resultaat['antwoord'];
    $toolChoiceWeergave = $resultaat['tool_choice'];
    $duurMs = $resultaat['duur_ms'];
}

toolsExpandingRenderHead([
    'titel' => 'CHATGPT + tools',
    'subtitel' => 'Sync test — OpenAI roept chat_tools.php aan (zelfde tools als worker).',
    'type' => 'gpt',
    'toon_terug' => true,
]);
?>

<div class="flow">
    <strong>Keten:</strong>
    <code>chatGptBepaalToolKeuze()</code> →
    <code>CHATGPT(..., true)</code> →
    <code>chatGptMetTools()</code> →
    <code>voerChatToolUit()</code>
</div>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="assistant0">Optioneel: system0-label (bijv. ProductFinder)</label>
    <input type="text" name="assistant0" id="assistant0" value="<?= htmlspecialchars($assistant0, ENT_QUOTES, 'UTF-8') ?>">

    <label for="bericht">Testbericht (als klant)</label>
    <textarea name="bericht" id="bericht" required><?= htmlspecialchars($bericht, ENT_QUOTES, 'UTF-8') ?></textarea>

    <p class="hint">Voorbeelden:</p>
    <?php foreach (gptSyncToolsVoorbeeldBerichten() as $vb): ?>
        <button type="button" class="voorbeeld-knop" data-tekst="<?= htmlspecialchars($vb, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars(mb_strimwidth($vb, 0, 42, '…'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    <?php endforeach; ?>

    <br>
    <button type="submit" class="primary">Verstuur → CHATGPT() met tools</button>
</form>

<?php if ($isPost && $fout === '' && $antwoord !== null): ?>
    <div class="meta">
        <strong>tool_choice:</strong> <?= htmlspecialchars($toolChoiceWeergave, ENT_QUOTES, 'UTF-8') ?><br>
        <strong>Duur:</strong> <?= (int) $duurMs ?> ms
    </div>
    <div class="ok"><?= htmlspecialchars((string) $antwoord, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<script>
document.querySelectorAll('.voorbeeld-knop').forEach(function (knop) {
    knop.addEventListener('click', function () {
        document.getElementById('bericht').value = knop.getAttribute('data-tekst') || '';
    });
});
</script>
<?php
toolsExpandingRenderFoot();
