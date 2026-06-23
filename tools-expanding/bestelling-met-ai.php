<?php
/**
 * Tool-test MET GPT: CHATGPT() + zoek_bestelling.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_gpt_tool_run.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

$bestellingId = '';
$email = '';
$antwoord = null;
$fout = '';
$toolChoice = '';
$duurMs = null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    $bestellingId = isset($_POST['bestelling_id']) ? trim((string) $_POST['bestelling_id']) : '';
    $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';

    if ($bestellingId === '' || $email === '') {
        $fout = 'Vul bestelnummer én e-mail in.';
    } else {
        $bericht = 'Waar is mijn bestelling ' . $bestellingId . '? Mijn e-mailadres is ' . $email . '.';
        $resultaat = toolsExpandingGptUitvoeren($conn, $bericht, 'zoek_bestelling');
        $fout = $resultaat['fout'];
        $antwoord = $resultaat['antwoord'];
        $toolChoice = $resultaat['tool_choice'];
        $duurMs = $resultaat['duur_ms'];
    }
}

toolsExpandingRenderHead([
    'titel' => 'Bestelling opzoeken',
    'subtitel' => 'CHATGPT() met geforceerde tool zoek_bestelling — zelfde keten als sync chat.',
    'type' => 'gpt',
    'toon_terug' => true,
]);

toolsExpandingGptRenderFlow('zoek_bestelling');
?>

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

    <button type="submit" class="primary">Verstuur → CHATGPT() + zoek_bestelling</button>
</form>

<?php if ($isPost && $fout === '' && is_string($antwoord)): ?>
    <?php toolsExpandingGptRenderMetaEnAntwoord($toolChoice, (int) $duurMs, $antwoord); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
