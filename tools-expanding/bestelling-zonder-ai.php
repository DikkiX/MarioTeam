<?php
/**
 * Tool-test ZONDER GPT: direct voerChatToolUit(zoek_bestelling).
 * Admin-stijl — zelfde data als de chatbot-tool, geen OpenAI.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

include_once toolsExpandingProjectRoot() . '/include/chat_tools.php';

/**
 * Roept de gedeelde tool aan (geen GPT in deze keten).
 */
function bestellingToolDirectOpzoeken(PDO $conn, int $bestellingId, string $email): array
{
    return voerChatToolUit($conn, 'zoek_bestelling', [
        'bestelling_id' => $bestellingId,
        'email' => $email,
    ]);
}

/** Toont belangrijkste velden uit het tool-resultaat. */
function bestellingToolRenderResultaat(array $data): void
{
    if (empty($data['gevonden'])) {
        echo '<div class="fout">' . htmlspecialchars((string) ($data['message'] ?? 'Niet gevonden.'), ENT_QUOTES, 'UTF-8') . '</div>';
        return;
    }

    $order = isset($data['resultaat']) && is_array($data['resultaat']) ? $data['resultaat'] : [];
    ?>
    <div class="ok">Bestelling gevonden.</div>
    <dl class="resultaat">
        <?php
        $velden = [
            'id' => 'Bestelnummer',
            'email' => 'E-mail',
            'status' => 'Status (code)',
            'verzend_status' => 'Verzendstatus',
            'verzending' => 'Verzending',
            'inpakdatum' => 'Inpakdatum',
            'track_code' => 'Trackcode (intern)',
            'betaalmethode' => 'Betaalmethode',
            'totaal' => 'Totaal',
        ];
        foreach ($velden as $key => $label) {
            if (!array_key_exists($key, $order)) {
                continue;
            }
            $waarde = $order[$key];
            if ($waarde === '' || $waarde === null) {
                continue;
            }
            echo '<dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt>';
            echo '<dd>' . htmlspecialchars((string) $waarde, ENT_QUOTES, 'UTF-8') . '</dd>';
        }
        ?>
    </dl>
    <?php
    if (!empty($data['artikelen']) && is_array($data['artikelen'])) {
        echo '<h2 style="font-size:1rem;margin-top:1.25rem;">Artikelen</h2><ul>';
        foreach ($data['artikelen'] as $art) {
            if (!is_array($art)) {
                continue;
            }
            $naam = $art['productnaam'] ?? $art['titel'] ?? 'Artikel';
            $aantal = $art['aantal'] ?? 1;
            echo '<li>' . htmlspecialchars((string) $aantal, ENT_QUOTES, 'UTF-8') . '× '
                . htmlspecialchars((string) $naam, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
    ?>
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

$bestellingId = '';
$email = '';
$resultaat = null;
$fout = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $bestellingId = isset($_POST['bestelling_id']) ? trim((string) $_POST['bestelling_id']) : '';
    $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';

    if ($bestellingId === '' || $email === '') {
        $fout = 'Vul bestelnummer én e-mail in (zelfde als bij de chatbot-tool).';
    } else {
        $resultaat = bestellingToolDirectOpzoeken($conn, (int) $bestellingId, $email);
    }
}

toolsExpandingRenderHead([
    'titel' => 'Bestelling opzoeken',
    'subtitel' => 'Direct voerChatToolUit() — geen GPT, wel chat_tools.php + bestelling_lookup.php.',
    'type' => 'direct',
    'toon_terug' => true,
]);
?>

<div class="flow">
    <strong>Keten (zonder AI):</strong>
    formulier → <code>voerChatToolUit('zoek_bestelling', …)</code> →
    <code>zoekBestellingRuw()</code> → database
</div>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="bestelling_id">Bestelnummer</label>
    <input type="number" name="bestelling_id" id="bestelling_id" min="1" required
        value="<?= htmlspecialchars($bestellingId, ENT_QUOTES, 'UTF-8') ?>">

    <label for="email">E-mail (hoort bij bestelling)</label>
    <input type="email" name="email" id="email" required
        value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit" class="primary">Opzoeken (zonder GPT)</button>
</form>

<?php if ($isPost && $fout === '' && is_array($resultaat)): ?>
    <?php bestellingToolRenderResultaat($resultaat); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
