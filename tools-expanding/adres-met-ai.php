<?php
/**
 * Tool-test MET GPT: wijzig_bestelling_adres via CHATGPT().
 * Alleen bestelnummer nodig.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_gpt_tool_run.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

$bestellingId = '';
$nieuwAdres = '';
$bericht = '';
$fout = '';
$antwoord = null;
$toolChoice = '';
$duurMs = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bestellingId = isset($_POST['bestelling_id']) ? trim((string) $_POST['bestelling_id']) : '';
    $nieuwAdres = isset($_POST['nieuw_adres']) ? trim((string) $_POST['nieuw_adres']) : '';

    if ($bestellingId === '') {
        $fout = 'Vul een bestelnummer in.';
    } else {
        if ($nieuwAdres !== '') {
            $bericht = 'Wijzig het bezorgadres van bestelling ' . $bestellingId
                . ' naar: ' . $nieuwAdres . '.';
        } else {
            $bericht = 'Wat is het huidige bezorgadres van bestelling ' . $bestellingId . '?';
        }

        $gpt = toolsExpandingGptUitvoeren($conn, $bericht, 'wijzig_bestelling_adres');
        $fout = $gpt['fout'];
        $antwoord = $gpt['antwoord'];
        $toolChoice = $gpt['tool_choice'];
        $duurMs = $gpt['duur_ms'];
    }
}

toolsExpandingRenderHead([
    'titel' => 'Besteladres wijzigen',
    'subtitel' => 'Alleen bestelnummer — CHATGPT() + wijzig_bestelling_adres.',
    'type' => 'gpt',
    'toon_terug' => true,
]);

toolsExpandingGptRenderFlow('wijzig_bestelling_adres');
?>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="bestelling_id">Bestelnummer</label>
    <input type="number" name="bestelling_id" id="bestelling_id" min="1" required
        value="<?= htmlspecialchars($bestellingId, ENT_QUOTES, 'UTF-8') ?>">

    <label for="nieuw_adres">Nieuw adres (optioneel — leeg = alleen ophalen)</label>
    <textarea name="nieuw_adres" id="nieuw_adres" rows="3"
        placeholder="Bijv. Nieuwstraat 9, 1382 JS Weesp"><?= htmlspecialchars($nieuwAdres, ENT_QUOTES, 'UTF-8') ?></textarea>

    <button type="submit" class="primary">Test met GPT</button>
</form>

<?php if ($antwoord !== null && $duurMs !== null): ?>
    <?php toolsExpandingGptRenderMetaEnAntwoord($toolChoice, $duurMs, (string) $antwoord); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
