<?php
/**
 * Tool-test ZONDER GPT: direct voerChatToolUit(zoek_productaanraders).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

include_once toolsExpandingProjectRoot() . '/include/chat_tools.php';

/** Comma of newline gescheiden zoektermen → array. */
function aanradersToolParseZoektermen(string $raw): array
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

function aanradersToolDirectOpzoeken(PDO $conn, array $zoektermen, int $maxResults): array
{
    $args = ['max_results' => $maxResults];
    if ($zoektermen !== []) {
        $args['zoektermen'] = $zoektermen;
    }

    return voerChatToolUit($conn, 'zoek_productaanraders', $args);
}

function aanradersToolRenderResultaat(array $data): void
{
    if (empty($data['gevonden'])) {
        echo '<div class="fout">' . htmlspecialchars((string) ($data['message'] ?? 'Geen aanraders.'), ENT_QUOTES, 'UTF-8') . '</div>';
        if (!empty($data['gebruikte_zoektermen']) && is_array($data['gebruikte_zoektermen'])) {
            echo '<p class="hint">Gebruikte zoektermen: '
                . htmlspecialchars(implode(', ', $data['gebruikte_zoektermen']), ENT_QUOTES, 'UTF-8')
                . '</p>';
        }
        return;
    }

    $rijen = isset($data['resultaat']) && is_array($data['resultaat']) ? $data['resultaat'] : [];
    ?>
    <div class="ok"><?= (int) count($rijen) ?> aanrader(s) op voorraad.</div>
    <?php if (!empty($data['gebruikte_zoektermen']) && is_array($data['gebruikte_zoektermen'])): ?>
        <p class="hint">Gebruikte zoektermen: <?= htmlspecialchars(implode(', ', $data['gebruikte_zoektermen']), ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <ul>
        <?php foreach ($rijen as $row): ?>
            <?php if (!is_array($row)) {
                continue;
            } ?>
            <li>
                <strong><?= htmlspecialchars((string) ($row['titel'] ?? 'Product'), ENT_QUOTES, 'UTF-8') ?></strong>
                — €<?= htmlspecialchars((string) ($row['prijs'] ?? '?'), ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($row['product_url'])): ?>
                    <br><a href="<?= htmlspecialchars((string) $row['product_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Productpagina</a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <details style="margin-top:1rem;">
        <summary class="hint">Ruwe JSON (tool-output)</summary>
        <pre class="json"><?= htmlspecialchars(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        ) ?></pre>
    </details>
    <?php
}

$zoektermenRaw = '';
$maxResults = '5';
$resultaat = null;
$fout = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $zoektermenRaw = isset($_POST['zoektermen']) ? trim((string) $_POST['zoektermen']) : '';
    $maxResults = isset($_POST['max_results']) ? trim((string) $_POST['max_results']) : '5';
    $max = (int) $maxResults;
    if ($max < 1) {
        $max = 1;
    }
    if ($max > 10) {
        $max = 10;
    }

    $termen = aanradersToolParseZoektermen($zoektermenRaw);
    $resultaat = aanradersToolDirectOpzoeken($conn, $termen, $max);
}

toolsExpandingRenderHead([
    'titel' => 'Productaanraders opzoeken',
    'subtitel' => 'Direct voerChatToolUit(zoek_productaanraders) — via chat_product_zoek.php in de database.',
    'type' => 'direct',
    'toon_terug' => true,
]);
?>

<div class="flow">
    <strong>Keten (zonder AI):</strong>
    formulier → <code>voerChatToolUit('zoek_productaanraders')</code> →
    <code>chat_product_zoek.php</code> → <code>Winkel</code> (alleen op voorraad)
</div>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="zoektermen">Zoektermen (komma of nieuwe regel, optioneel leeg = algemene aanraders)</label>
    <textarea name="zoektermen" id="zoektermen" placeholder="dans, dance, party&#10;of: rpg, jrpg"><?= htmlspecialchars($zoektermenRaw, ENT_QUOTES, 'UTF-8') ?></textarea>

    <label for="max_results">Max. resultaten (1–10)</label>
    <input type="number" name="max_results" id="max_results" min="1" max="10"
        value="<?= htmlspecialchars($maxResults, ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit" class="primary">Zoeken (zonder GPT)</button>
</form>

<?php if ($isPost && $fout === '' && is_array($resultaat)): ?>
    <?php aanradersToolRenderResultaat($resultaat); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
