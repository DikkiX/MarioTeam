<?php
/**
 * Tool-test ZONDER GPT: adres ophalen + wijzigen via wijzig_bestelling_adres.
 * Alleen bestelnummer. Adres = één DB-veld (volledige regel).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

toolsExpandingRequireLogin();
$conn = toolsExpandingRequireDatabase();

include_once toolsExpandingProjectRoot() . '/include/chat_tools.php';

/** @var list<array{key: string, label: string, readonly?: bool}> */
function adresToolVelden(): array
{
    return [
        ['key' => 'mail', 'label' => 'E-mail (alleen tonen)', 'readonly' => true],
        ['key' => 'naam', 'label' => 'Naam'],
        ['key' => 'adres', 'label' => 'Adres (volledige regel: straat, postcode, plaats, land)'],
        ['key' => 'telefoon', 'label' => 'Telefoon'],
    ];
}

function adresToolRenderResultaat(array $data): void
{
    if (empty($data['gevonden'])) {
        echo '<div class="fout">' . htmlspecialchars((string) ($data['message'] ?? 'Niet gevonden.'), ENT_QUOTES, 'UTF-8') . '</div>';
        return;
    }

    $actie = (string) ($data['actie'] ?? 'ophalen');
    if ($actie === 'opslaan' && !empty($data['opgeslagen'])) {
        echo '<div class="ok">Adres opgeslagen.</div>';
    } elseif ($actie === 'opslaan' && empty($data['opgeslagen'])) {
        echo '<div class="fout">' . htmlspecialchars((string) ($data['message'] ?? 'Opslaan mislukt.'), ENT_QUOTES, 'UTF-8') . '</div>';
    } else {
        echo '<div class="ok">Huidig adres opgehaald.</div>';
    }

    if (isset($data['mag_wijzigen'])) {
        $mag = (bool) $data['mag_wijzigen'];
        echo '<p class="hint">' . ($mag ? 'Wijzigen is nog toegestaan (niet verzonden).' : 'Wijzigen is geblokkeerd: ' . htmlspecialchars((string) ($data['message'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    $adres = isset($data['adres']) && is_array($data['adres']) ? $data['adres'] : [];
    if ($adres !== []) {
        echo '<dl class="resultaat">';
        foreach (adresToolVelden() as $veld) {
            $key = $veld['key'];
            if (!array_key_exists($key, $adres) || $adres[$key] === '') {
                continue;
            }
            echo '<dt>' . htmlspecialchars($veld['label'], ENT_QUOTES, 'UTF-8') . '</dt>';
            echo '<dd>' . htmlspecialchars((string) $adres[$key], ENT_QUOTES, 'UTF-8') . '</dd>';
        }
        echo '</dl>';
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
$veldWaarden = [];
foreach (adresToolVelden() as $veld) {
    $veldWaarden[$veld['key']] = '';
}
$resultaat = null;
$fout = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$actie = '';

if ($isPost) {
    $actie = isset($_POST['actie']) ? trim((string) $_POST['actie']) : '';
    $bestellingId = isset($_POST['bestelling_id']) ? trim((string) $_POST['bestelling_id']) : '';

    foreach (adresToolVelden() as $veld) {
        $key = $veld['key'];
        if (!empty($veld['readonly'])) {
            continue;
        }
        $veldWaarden[$key] = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    }

    if ($bestellingId === '') {
        $fout = 'Vul een bestelnummer in.';
    } else {
        $args = ['bestelling_id' => (int) $bestellingId];

        if ($actie === 'opslaan') {
            foreach (['naam', 'adres', 'telefoon'] as $key) {
                if (($veldWaarden[$key] ?? '') !== '') {
                    $args[$key] = $veldWaarden[$key];
                }
            }
            if (count($args) <= 1) {
                $fout = 'Vul minstens één veld in (naam, adres of telefoon).';
            }
        }

        if ($fout === '') {
            $resultaat = voerChatToolUit($conn, 'wijzig_bestelling_adres', $args);

            if ($actie === 'ophalen' && is_array($resultaat) && !empty($resultaat['adres']) && is_array($resultaat['adres'])) {
                foreach ($resultaat['adres'] as $key => $waarde) {
                    if (array_key_exists($key, $veldWaarden)) {
                        $veldWaarden[$key] = (string) $waarde;
                    }
                }
            }
        }
    }
}

toolsExpandingRenderHead([
    'titel' => 'Besteladres wijzigen',
    'subtitel' => 'Alleen bestelnummer — adres staat in DB als één veld (kolom adres).',
    'type' => 'direct',
    'toon_terug' => true,
]);
?>

<div class="flow">
    <strong>Database:</strong> Bestellingen.naam, Bestellingen.adres (volledige regel), Bestellingen.telefoon, Bestellingen.mail
</div>

<?php if ($fout !== ''): ?>
    <div class="fout"><?= htmlspecialchars($fout, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <label for="bestelling_id">Bestelnummer</label>
    <input type="number" name="bestelling_id" id="bestelling_id" min="1" required
        value="<?= htmlspecialchars($bestellingId, ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit" name="actie" value="ophalen" class="primary">Huidig adres ophalen</button>

    <h2 style="font-size:1rem;margin:1.5rem 0 0.75rem;">Adres aanpassen</h2>
    <p class="hint">Voorbeeld adresregel: Katie Jansstraat 12, 5913RH Venlo, Nederland</p>

    <?php foreach (adresToolVelden() as $veld): ?>
        <?php if (!empty($veld['readonly'])): ?>
            <?php if (($veldWaarden[$veld['key']] ?? '') !== ''): ?>
                <label><?= htmlspecialchars($veld['label'], ENT_QUOTES, 'UTF-8') ?></label>
                <input type="text" readonly
                    value="<?= htmlspecialchars($veldWaarden[$veld['key']], ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
        <?php else: ?>
            <label for="<?= htmlspecialchars($veld['key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($veld['label'], ENT_QUOTES, 'UTF-8') ?></label>
            <?php if ($veld['key'] === 'adres'): ?>
                <textarea name="adres" id="adres" rows="2"><?= htmlspecialchars($veldWaarden['adres'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            <?php else: ?>
                <input type="text" name="<?= htmlspecialchars($veld['key'], ENT_QUOTES, 'UTF-8') ?>"
                    id="<?= htmlspecialchars($veld['key'], ENT_QUOTES, 'UTF-8') ?>"
                    value="<?= htmlspecialchars($veldWaarden[$veld['key']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <button type="submit" name="actie" value="opslaan" class="primary" style="margin-top:0.75rem;">Adres opslaan</button>
</form>

<?php if ($isPost && $fout === '' && is_array($resultaat)): ?>
    <?php adresToolRenderResultaat($resultaat); ?>
<?php endif; ?>
<?php
toolsExpandingRenderFoot();
