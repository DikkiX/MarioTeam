<?php
/**
 * Tool-test ZONDER GPT: direct voerChatToolUit(zoek_productvoorraad).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

include_once toolsExpandingProjectRoot() . '/include/chat_tools.php';

function voorraadToolDirectOpzoeken(PDO $conn, string $zoekterm): array
{
    return voerChatToolUit($conn, 'zoek_productvoorraad', [
        'zoekterm' => $zoekterm,
    ]);
}

function voorraadToolRenderResultaat(array $data): void
{
    if (empty($data['gevonden'])) {
        echo '<div class="fout">' . htmlspecialchars((string) ($data['message'] ?? 'Niet gevonden.'), ENT_QUOTES, 'UTF-8') . '</div>';
        return;
    }

    $rijen = isset($data['resultaat']) && is_array($data['resultaat']) ? $data['resultaat'] : [];
    $status = (string) ($data['status'] ?? '');
    ?>
    <div class="ok">
        <?= htmlspecialchars(count($rijen) . ' product(en) — status: ' . $status, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <ul>
        <?php foreach ($rijen as $row): ?>
            <?php if (!is_array($row)) {
                continue;
            } ?>
            <li>
                <strong><?= htmlspecialchars((string) ($row['titel'] ?? 'Product'), ENT_QUOTES, 'UTF-8') ?></strong>
                — €<?= htmlspecialchars((string) ($row['prijs'] ?? '?'), ENT_QUOTES, 'UTF-8') ?>
                — voorraad: <?= htmlspecialchars((string) ($row['op_voorraad'] ?? '?'), ENT_QUOTES, 'UTF-8') ?>
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

$zoekterm = '';
$resultaat = null;
$fout = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $zoekterm = isset($_POST['zoekterm']) ? trim((string) $_POST['zoekterm']) : '';

    if ($zoekterm === '') {
        $fout = 'Vul een zoekterm in (bijv. Just Dance).';
    } else {
        $resultaat = voorraadToolDirectOpzoeken($conn, $zoekterm);
    }
}

toolsExpandingRenderHead([
    'titel' => 'Productvoorraad opzoeken',
    'subtitel' => 'Direct voerChatToolUit(zoek_productvoorraad) — één product/titel in Winkel + info.',
    'type' => 'direct',
    'toon_terug' => true,
]);
?>

<div class="flow">
    <strong>Keten (zonder AI):</strong>
    formulier → <code>voerChatToolUit('zoek_productvoorraad')</code> →
    SQL op <code>Winkel</code> + <code>info</code> (max. 5 treffers)
</div>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="zoekterm">Zoekterm (titel of deel van titel)</label>
    <input type="text" name="zoekterm" id="zoekterm" required
        placeholder="bijv. Just Dance"
        value="<?= htmlspecialchars($zoekterm, ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit" class="primary">Opzoeken (zonder GPT)</button>
</form>

<?php if ($isPost && $fout === '' && is_array($resultaat)): ?>
    <?php voorraadToolRenderResultaat($resultaat); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
