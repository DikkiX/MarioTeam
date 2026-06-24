<?php
/**
 * Tool-test ZONDER GPT: direct voerChatToolUit(zoek_traceer).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

include_once toolsExpandingProjectRoot() . '/include/chat_tools.php';

function traceerToolRenderResultaat(array $data): void
{
    if (empty($data['gevonden'])) {
        echo '<div class="fout">' . htmlspecialchars((string) ($data['message'] ?? 'Geen status.'), ENT_QUOTES, 'UTF-8') . '</div>';
        return;
    }

    ?>
    <div class="ok">Traceerinfo gevonden (zonder traceernummer in output).</div>
    <dl class="resultaat">
        <?php
        $velden = [
            'status' => 'Status',
            'vervoerder' => 'Vervoerder',
            'live_tracking' => 'Live tracking',
            'huidige_status' => 'Huidige bezorgstatus',
            'laatste_update' => 'Laatste update',
            'verzend_status_database' => 'Status in database',
            'bestelling_id' => 'Bestelnummer',
            'message' => 'Toelichting',
        ];
        foreach ($velden as $key => $label) {
            if (!array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
                continue;
            }
            $waarde = is_bool($data[$key]) ? ($data[$key] ? 'ja' : 'nee') : (string) $data[$key];
            echo '<dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt>';
            echo '<dd>' . htmlspecialchars($waarde, ENT_QUOTES, 'UTF-8') . '</dd>';
        }
        ?>
    </dl>
    <?php
    if (!empty($data['geschiedenis']) && is_array($data['geschiedenis'])) {
        echo '<h2 style="font-size:1rem;margin-top:1.25rem;">Geschiedenis</h2><ul>';
        foreach ($data['geschiedenis'] as $stap) {
            if (!is_array($stap)) {
                continue;
            }
            $tijd = (string) ($stap['tijd'] ?? '');
            $oms = (string) ($stap['omschrijving'] ?? '');
            echo '<li>' . htmlspecialchars(trim($tijd . ' — ' . $oms, ' —'), ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
    ?>
    <details style="margin-top:1rem;">
        <summary class="hint">Ruwe JSON (geen traceernummer)</summary>
        <pre class="json"><?= htmlspecialchars(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        ) ?></pre>
    </details>
    <?php
}

$modus = 'bestelling';
$traceernummer = '';
$bestellingId = '';
$email = '';
$resultaat = null;
$fout = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $modus = isset($_POST['modus']) ? (string) $_POST['modus'] : 'bestelling';
    $traceernummer = isset($_POST['traceernummer']) ? trim((string) $_POST['traceernummer']) : '';
    $bestellingId = isset($_POST['bestelling_id']) ? trim((string) $_POST['bestelling_id']) : '';
    $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';

    $args = [];
    if ($modus === 'traceernummer') {
        if ($traceernummer === '') {
            $fout = 'Vul een traceernummer in.';
        } else {
            $args['traceernummer'] = $traceernummer;
        }
    } else {
        if ($bestellingId === '' || $email === '') {
            $fout = 'Vul bestelnummer én e-mail in.';
        } else {
            $args['bestelling_id'] = (int) $bestellingId;
            $args['email'] = $email;
        }
    }

    if ($fout === '') {
        $resultaat = voerChatToolUit($conn, 'zoek_traceer', $args);
    }
}

toolsExpandingRenderHead([
    'titel' => 'Zending / track & trace',
    'subtitel' => 'Direct voerChatToolUit(zoek_traceer) — PostNL/GLS status, nooit traceernummer terug.',
    'type' => 'direct',
    'toon_terug' => true,
]);
?>

<div class="flow">
    <strong>Keten (zonder AI):</strong>
    traceernummer of bestelling → <code>tracking_lookup.php</code> → PostNL + GLS API (beste antwoord) → status (zonder code)
</div>

<p class="hint">Live tracking vereist credentials in <code>.env</code> (POSTNL_* / GLS_API_*).</p>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="modus">Zoeken via</label>
    <select name="modus" id="modus">
        <option value="bestelling" <?= $modus === 'bestelling' ? 'selected' : '' ?>>Bestelnummer + e-mail</option>
        <option value="traceernummer" <?= $modus === 'traceernummer' ? 'selected' : '' ?>>Traceernummer</option>
    </select>

    <div id="velden-bestelling">
        <label for="bestelling_id">Bestelnummer</label>
        <input type="number" name="bestelling_id" id="bestelling_id" min="1"
            value="<?= htmlspecialchars($bestellingId, ENT_QUOTES, 'UTF-8') ?>">

        <label for="email">E-mail (hoort bij bestelling)</label>
        <input type="email" name="email" id="email"
            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div id="velden-traceernummer" style="display:none;">
        <label for="traceernummer">Traceernummer</label>
        <input type="text" name="traceernummer" id="traceernummer"
            value="<?= htmlspecialchars($traceernummer, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <button type="submit" class="primary">Opzoeken (zonder GPT)</button>
</form>

<script>
(function () {
    var modus = document.getElementById('modus');
    var blokBestelling = document.getElementById('velden-bestelling');
    var blokTraceer = document.getElementById('velden-traceernummer');
    function sync() {
        var isTraceer = modus.value === 'traceernummer';
        blokBestelling.style.display = isTraceer ? 'none' : 'block';
        blokTraceer.style.display = isTraceer ? 'block' : 'none';
    }
    modus.addEventListener('change', sync);
    sync();
})();
</script>

<?php if ($isPost && $fout === '' && is_array($resultaat)): ?>
    <?php traceerToolRenderResultaat($resultaat); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
