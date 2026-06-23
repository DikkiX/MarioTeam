<?php
/**
 * Tool-test MET GPT: CHATGPT() + zoek_productvoorraad.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_gpt_tool_run.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

$zoekterm = '';
$antwoord = null;
$fout = '';
$toolChoice = '';
$duurMs = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $zoekterm = isset($_POST['zoekterm']) ? trim((string) $_POST['zoekterm']) : '';

    if ($zoekterm === '') {
        $fout = 'Vul een zoekterm in.';
    } else {
        $bericht = 'Hebben jullie ' . $zoekterm . ' op voorraad? Wat kost het?';
        $resultaat = toolsExpandingGptUitvoeren($conn, $bericht, 'zoek_productvoorraad');
        $fout = $resultaat['fout'];
        $antwoord = $resultaat['antwoord'];
        $toolChoice = $resultaat['tool_choice'];
        $duurMs = $resultaat['duur_ms'];
    }
}

toolsExpandingRenderHead([
    'titel' => 'Productvoorraad opzoeken',
    'subtitel' => 'CHATGPT() met geforceerde tool zoek_productvoorraad.',
    'type' => 'gpt',
    'toon_terug' => true,
]);

toolsExpandingGptRenderFlow('zoek_productvoorraad');
?>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="zoekterm">Zoekterm (titel of deel van titel)</label>
    <input type="text" name="zoekterm" id="zoekterm" required
        placeholder="bijv. Just Dance"
        value="<?= htmlspecialchars($zoekterm, ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit" class="primary">Verstuur → CHATGPT() + zoek_productvoorraad</button>
</form>

<?php if ($isPost && $fout === '' && is_string($antwoord)): ?>
    <?php toolsExpandingGptRenderMetaEnAntwoord($toolChoice, (int) $duurMs, $antwoord); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
