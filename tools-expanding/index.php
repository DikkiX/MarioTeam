<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

$fout = '';

if (isset($_GET['uitloggen'])) {
    toolsExpandingUitloggen();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wachtwoord'])) {
    if (toolsExpandingProbeerInloggen((string) $_POST['wachtwoord'])) {
        header('Location: index.php');
        exit;
    }
    $fout = 'Ongeldig wachtwoord.';
}

toolsExpandingRenderHead([
    'titel' => 'Tools-expanding',
    'subtitel' => 'Kleine bibliotheek om chat-tools te testen — met of zonder GPT.',
]);

if (!toolsExpandingIsIngelogd()) {
    ?>
    <div class="login-box">
        <p class="hint">Alleen test/staging. Log in om tools te openen.</p>
        <?php if ($fout !== ''): ?>
            <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post">
            <label for="wachtwoord">Wachtwoord</label>
            <input type="password" name="wachtwoord" id="wachtwoord" autocomplete="off" required>
            <button type="submit" class="primary">Inloggen</button>
        </form>
    </div>
    <?php
    toolsExpandingRenderFoot();
    exit;
}

?>
<p class="hint">
    <strong>Met GPT</strong> = OpenAI + tools &nbsp;|&nbsp;
    <strong>Zonder GPT</strong> = direct <code>voerChatToolUit()</code> (admin-stijl)
</p>
<p><a href="index.php?uitloggen=1">Uitloggen</a></p>

<div class="kaarten">
    <?php foreach (toolsExpandingCatalogus() as $tool): ?>
        <a class="kaart" href="<?= htmlspecialchars($tool['url'], ENT_QUOTES, 'UTF-8') ?>">
            <h2>
                <?= htmlspecialchars($tool['titel'], ENT_QUOTES, 'UTF-8') ?>
                <span class="badge badge-<?= $tool['type'] === 'gpt' ? 'gpt' : 'direct' ?>">
                    <?= $tool['type'] === 'gpt' ? 'Met GPT' : 'Zonder GPT' ?>
                </span>
            </h2>
            <p><?= htmlspecialchars($tool['beschrijving'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="fn"><?= htmlspecialchars($tool['functie'], ENT_QUOTES, 'UTF-8') ?></div>
        </a>
    <?php endforeach; ?>
</div>

<p class="hint" style="margin-top: 2rem;">
    Nieuwe tool toevoegen: pagina in <code>tools-expanding/</code> + regel in <code>toolsExpandingCatalogus()</code> in <code>_bootstrap.php</code>.
</p>
<?php
toolsExpandingRenderFoot();
