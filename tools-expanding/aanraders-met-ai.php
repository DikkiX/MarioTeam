<?php
/**
 * Tool-test MET GPT: CHATGPT() + zoek_productaanraders.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_gpt_tool_run.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

/** Comma of newline gescheiden zoektermen → array. */
function aanradersMetAiParseZoektermen(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $delen = preg_split('/[,\n]+/', $raw) ?: [];
    $termen = [];

    foreach ($delen as $deel) {
        $t = trim((string) $deel);
        if ($t !== '') {
            $termen[] = $t;
        }
    }

    return $termen;
}

$zoektermenRaw = '';
$antwoord = null;
$fout = '';
$toolChoice = '';
$duurMs = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $zoektermenRaw = isset($_POST['zoektermen']) ? trim((string) $_POST['zoektermen']) : '';
    $termen = aanradersMetAiParseZoektermen($zoektermenRaw);

    if ($termen === []) {
        $bericht = 'Welke games op voorraad raad je me aan?';
    } else {
        $bericht = 'Welke spellen die lijken op ' . implode(', ', $termen) . ' hebben jullie op voorraad?';
    }

    $resultaat = toolsExpandingGptUitvoeren($conn, $bericht, 'zoek_productaanraders');
    $fout = $resultaat['fout'];
    $antwoord = $resultaat['antwoord'];
    $toolChoice = $resultaat['tool_choice'];
    $duurMs = $resultaat['duur_ms'];
}

toolsExpandingRenderHead([
    'titel' => 'Productaanraders opzoeken',
    'subtitel' => 'CHATGPT() met geforceerde tool zoek_productaanraders — via chat_product_zoek.php.',
    'type' => 'gpt',
    'toon_terug' => true,
]);

toolsExpandingGptRenderFlow('zoek_productaanraders');
?>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="zoektermen">Zoektermen (komma of nieuwe regel, leeg = algemene aanraders)</label>
    <textarea name="zoektermen" id="zoektermen" placeholder="rpg, jrpg&#10;of: dans, dance"><?= htmlspecialchars($zoektermenRaw, ENT_QUOTES, 'UTF-8') ?></textarea>

    <button type="submit" class="primary">Verstuur → CHATGPT() + zoek_productaanraders</button>
</form>

<?php if ($isPost && $fout === '' && is_string($antwoord)): ?>
    <?php toolsExpandingGptRenderMetaEnAntwoord($toolChoice, (int) $duurMs, $antwoord); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
