<?php
/**
 * Tool-test MET GPT: CHATGPT() + zoek_traceer.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_gpt_tool_run.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

$modus = 'bestelling';
$traceernummer = '';
$bestellingId = '';
$email = '';
$antwoord = null;
$fout = '';
$toolChoice = '';
$duurMs = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $modus = isset($_POST['modus']) ? (string) $_POST['modus'] : 'bestelling';
    $traceernummer = isset($_POST['traceernummer']) ? trim((string) $_POST['traceernummer']) : '';
    $bestellingId = isset($_POST['bestelling_id']) ? trim((string) $_POST['bestelling_id']) : '';
    $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';

    if ($modus === 'traceernummer') {
        if ($traceernummer === '') {
            $fout = 'Vul een traceernummer in.';
        } else {
            $bericht = 'Wat is de bezorgstatus van mijn pakket met traceernummer ' . $traceernummer . '?';
        }
    } else {
        if ($bestellingId === '' || $email === '') {
            $fout = 'Vul bestelnummer én e-mail in.';
        } else {
            $bericht = 'Waar is mijn pakket? Bestelling ' . $bestellingId . ', mijn e-mail is ' . $email . '.';
        }
    }

    if ($fout === '') {
        $resultaat = toolsExpandingGptUitvoeren($conn, $bericht, 'zoek_traceer');
        $fout = $resultaat['fout'];
        $antwoord = $resultaat['antwoord'];
        $toolChoice = $resultaat['tool_choice'];
        $duurMs = $resultaat['duur_ms'];
    }
}

toolsExpandingRenderHead([
    'titel' => 'Zending / track & trace',
    'subtitel' => 'CHATGPT() + zoek_traceer — GPT mag het traceernummer nooit aan de klant geven.',
    'type' => 'gpt',
    'toon_terug' => true,
]);

toolsExpandingGptRenderFlow('zoek_traceer');
?>

<p class="hint">Live PostNL/GLS vereist API-keys in <code>.env</code>.</p>

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

        <label for="email">E-mail</label>
        <input type="email" name="email" id="email"
            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div id="velden-traceernummer" style="display:none;">
        <label for="traceernummer">Traceernummer</label>
        <input type="text" name="traceernummer" id="traceernummer"
            value="<?= htmlspecialchars($traceernummer, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <button type="submit" class="primary">Verstuur → CHATGPT() + zoek_traceer</button>
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

<?php if ($isPost && $fout === '' && is_string($antwoord)): ?>
    <?php toolsExpandingGptRenderMetaEnAntwoord($toolChoice, (int) $duurMs, $antwoord); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
