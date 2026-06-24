<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';
require_once toolsExpandingProjectRoot() . '/include/gls_webhook_handler.php';

toolsExpandingRequireLogin();
require_once dirname(__DIR__) . '/_layout.php';

$conn = toolsExpandingRequireDatabase();

// Zoek op pakketnummer
$zoek = trim((string) ($_GET['parcel_no'] ?? ''));
$rij  = null;
$alle = [];

if ($zoek !== '') {
    try {
        $stmt = $conn->prepare('SELECT * FROM gls_pakket_status WHERE parcel_no = :pno LIMIT 1');
        $stmt->execute([':pno' => $zoek]);
        $rij = $stmt->fetch() ?: null;
    } catch (Throwable) {}
}

try {
    $stmt2 = $conn->query('SELECT parcel_no, state, beschrijving, datum_event, bijgewerkt FROM gls_pakket_status ORDER BY bijgewerkt DESC LIMIT 50');
    $alle = $stmt2 ? $stmt2->fetchAll() : [];
} catch (Throwable) {}

toolsExpandingRenderHead([
    'titel'      => 'GLS webhook-status',
    'subtitel'   => 'Alle ontvangen GLS-pakketten uit gls_pakket_status.',
    'type'       => 'direct',
    'toon_terug' => true,
]);
?>

<form method="get" style="display:flex;gap:.5rem;margin-bottom:1rem">
    <input type="text" name="parcel_no" value="<?= htmlspecialchars($zoek, ENT_QUOTES, 'UTF-8') ?>" placeholder="Pakketnummer zoeken…" style="max-width:260px">
    <button class="primary" type="submit">Zoeken</button>
    <?php if ($zoek !== ''): ?><a class="knop" href="status.php">Wis</a><?php endif; ?>
</form>

<?php if ($zoek !== ''): ?>
    <?php if ($rij): ?>
        <div class="ok">
            <strong>Gevonden: <?= htmlspecialchars((string)$rij['parcel_no'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            State: <code><?= htmlspecialchars((string)$rij['state'], ENT_QUOTES, 'UTF-8') ?></code><br>
            Beschrijving: <?= htmlspecialchars((string)$rij['beschrijving'], ENT_QUOTES, 'UTF-8') ?><br>
            Datum event: <?= htmlspecialchars((string)$rij['datum_event'], ENT_QUOTES, 'UTF-8') ?><br>
            Bijgewerkt: <?= htmlspecialchars((string)$rij['bijgewerkt'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <details style="margin-top:.75rem">
            <summary style="cursor:pointer;font-size:.9rem">Raw JSON</summary>
            <pre class="json"><?= htmlspecialchars(
                (string) json_encode(json_decode((string)$rij['raw_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ENT_QUOTES, 'UTF-8'
            ) ?></pre>
        </details>
    <?php else: ?>
        <div class="fout">Geen pakket gevonden met nummer <strong><?= htmlspecialchars($zoek, ENT_QUOTES, 'UTF-8') ?></strong>.</div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($alle !== []): ?>
    <h2 style="font-size:1rem;margin-top:1.5rem">Recente pakketten (max 50)</h2>
    <table style="width:100%;border-collapse:collapse;font-size:.9rem;background:#fff;border:1px solid #ddd;border-radius:4px;overflow:hidden">
        <thead style="background:#f0f3f7">
            <tr>
                <th style="text-align:left;padding:.5rem .7rem">Pakketnummer</th>
                <th style="text-align:left;padding:.5rem .7rem">State</th>
                <th style="text-align:left;padding:.5rem .7rem">Beschrijving</th>
                <th style="text-align:left;padding:.5rem .7rem">Datum event</th>
                <th style="text-align:left;padding:.5rem .7rem">Bijgewerkt</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($alle as $r): ?>
            <tr style="border-top:1px solid #eee">
                <td style="padding:.45rem .7rem">
                    <a href="?parcel_no=<?= urlencode((string)$r['parcel_no']) ?>">
                        <?= htmlspecialchars((string)$r['parcel_no'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </td>
                <td style="padding:.45rem .7rem"><code><?= htmlspecialchars((string)$r['state'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td style="padding:.45rem .7rem"><?= htmlspecialchars((string)$r['beschrijving'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:.45rem .7rem"><?= htmlspecialchars((string)$r['datum_event'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:.45rem .7rem"><?= htmlspecialchars((string)$r['bijgewerkt'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p class="hint">Nog geen GLS-pakketten ontvangen. Zodra GLS een webhook stuurt, verschijnen ze hier.</p>
<?php endif; ?>

<?php toolsExpandingRenderFoot(); ?>
