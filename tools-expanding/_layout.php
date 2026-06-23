<?php
/**
 * Gedeelde HTML-layout voor tools-expanding pagina's.
 *
 * @param array{
 *   titel: string,
 *   subtitel?: string,
 *   type?: string,
 *   toon_terug?: bool
 * } $pagina
 */
function toolsExpandingRenderHead(array $pagina): void
{
    $titel = $pagina['titel'] ?? 'Tools';
    $type = $pagina['type'] ?? '';
    $badge = $type === 'gpt' ? 'Met GPT' : ($type === 'direct' ? 'Zonder GPT' : '');
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') ?> — tools-expanding</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; max-width: 800px; margin: 0 auto; padding: 1.5rem 1rem 3rem; line-height: 1.5; background: #f6f7f9; color: #111; }
        a { color: #0b57d0; }
        h1 { font-size: 1.35rem; margin: 0 0 0.25rem; }
        .sub { color: #555; font-size: 0.95rem; margin-bottom: 1rem; }
        .badge { display: inline-block; font-size: 0.75rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 4px; margin-left: 0.35rem; vertical-align: middle; }
        .badge-gpt { background: #e8f0fe; color: #174ea6; }
        .badge-direct { background: #e6f4ea; color: #137333; }
        .terug { display: inline-block; margin-bottom: 1rem; font-size: 0.9rem; }
        label { display: block; font-weight: 600; margin-top: 1rem; }
        input, textarea, button { font: inherit; }
        input[type="password"], input[type="text"], input[type="email"], input[type="number"], textarea {
            width: 100%; padding: 0.5rem; margin-top: 0.25rem; border: 1px solid #ccc; border-radius: 4px;
        }
        textarea { min-height: 6rem; }
        button, .knop { display: inline-block; margin-top: 1rem; padding: 0.5rem 1rem; cursor: pointer; border: 1px solid #888; border-radius: 4px; background: #fff; text-decoration: none; color: inherit; }
        button.primary { background: #0b57d0; color: #fff; border-color: #0b57d0; }
        .hint { color: #555; font-size: 0.9rem; }
        .fout { background: #fee; border: 1px solid #c99; padding: 0.75rem; margin-top: 1rem; border-radius: 4px; }
        .ok { background: #eef8ee; border: 1px solid #9c9; padding: 1rem; margin-top: 1rem; border-radius: 4px; }
        .meta { background: #fff; border: 1px solid #ddd; padding: 0.75rem; margin-top: 1rem; font-size: 0.9rem; border-radius: 4px; }
        .flow { background: #fff; border: 1px solid #ccd; padding: 0.75rem; margin: 1rem 0; font-size: 0.85rem; border-radius: 4px; }
        .flow code, .meta code { font-size: 0.8rem; }
        .kaarten { display: grid; gap: 1rem; margin-top: 1.5rem; }
        @media (min-width: 560px) { .kaarten { grid-template-columns: 1fr 1fr; } }
        .kaart { display: block; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 1rem 1.1rem; text-decoration: none; color: inherit; transition: box-shadow 0.15s; }
        .kaart:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: #0b57d0; }
        .kaart h2 { font-size: 1.05rem; margin: 0 0 0.35rem; }
        .kaart p { margin: 0; font-size: 0.9rem; color: #444; }
        .kaart .fn { margin-top: 0.5rem; font-size: 0.8rem; color: #666; font-family: ui-monospace, monospace; }
        dl.resultaat { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 0.75rem 1rem; margin-top: 1rem; }
        dl.resultaat dt { font-weight: 600; margin-top: 0.5rem; }
        dl.resultaat dd { margin: 0.15rem 0 0 0; }
        pre.json { background: #1e1e1e; color: #d4d4d4; padding: 1rem; overflow: auto; font-size: 0.8rem; border-radius: 4px; }
        .login-box { max-width: 360px; margin: 2rem auto; background: #fff; padding: 1.5rem; border-radius: 8px; border: 1px solid #ddd; }
    </style>
</head>
<body>
    <?php if (!empty($pagina['toon_terug'])): ?>
        <a class="terug" href="<?= htmlspecialchars(toolsExpandingHubUrl(), ENT_QUOTES, 'UTF-8') ?>">← Terug naar tools-overzicht</a>
    <?php endif; ?>
    <h1>
        <?= htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') ?>
        <?php if ($badge !== ''): ?>
            <span class="badge badge-<?= $type === 'gpt' ? 'gpt' : 'direct' ?>"><?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </h1>
    <?php if (!empty($pagina['subtitel'])): ?>
        <p class="sub"><?= htmlspecialchars((string) $pagina['subtitel'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
<?php
}

function toolsExpandingRenderFoot(): void
{
    ?>
</body>
</html>
<?php
}
