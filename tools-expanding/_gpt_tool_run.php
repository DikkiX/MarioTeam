<?php
/**
 * Gedeelde helpers voor tools-expanding pagina's mét GPT (CHATGPT + chat_tools).
 */
declare(strict_types=1);

function toolsExpandingGptSystemPrompt(): string
{
    return <<<'TXT'
Je bent een test-assistent voor Mario Team (games webshop).
Gebruik de beschikbare database-tools als de vraag daarom vraagt.
Verzin geen order- of voorraadgegevens — alleen wat uit een tool komt.
Antwoord in het Nederlands, kort en duidelijk (Mr M-stijl mag, geen emoji).
TXT;
}

/** Forceert één tool in de CHATGPT()-call (testpagina per tool). */
function toolsExpandingGptForceerTool(string $functieNaam): array
{
    return [
        'type' => 'function',
        'function' => ['name' => $functieNaam],
    ];
}

/**
 * @return array{fout: string, antwoord: string|null, tool_choice: string, duur_ms: int|null}
 */
function toolsExpandingGptUitvoeren(PDO $conn, string $bericht, string $functieNaam): array
{
    if ($bericht === '') {
        return ['fout' => 'Vul een testbericht in.', 'antwoord' => null, 'tool_choice' => '', 'duur_ms' => null];
    }

    include_once toolsExpandingProjectRoot() . '/include/ChatFunction.php';

    $toolChoice = toolsExpandingGptForceerTool($functieNaam);
    $toolChoiceWeergave = json_encode($toolChoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $mode = function_exists('getChatModelMode') ? getChatModelMode() : 2;
    $start = microtime(true);

    $antwoord = CHATGPT(
        $bericht,
        toolsExpandingGptSystemPrompt(),
        0.2,
        $mode,
        [],
        1,
        $conn,
        $toolChoice
    );

    return [
        'fout' => '',
        'antwoord' => $antwoord,
        'tool_choice' => is_string($toolChoiceWeergave) ? $toolChoiceWeergave : '',
        'duur_ms' => (int) round((microtime(true) - $start) * 1000),
    ];
}

function toolsExpandingGptRenderMetaEnAntwoord(string $toolChoice, int $duurMs, string $antwoord): void
{
    ?>
    <div class="meta">
        <strong>tool_choice:</strong> <?= htmlspecialchars($toolChoice, ENT_QUOTES, 'UTF-8') ?><br>
        <strong>Duur:</strong> <?= $duurMs ?> ms
    </div>
    <div class="ok"><?= nl2br(htmlspecialchars($antwoord, ENT_QUOTES, 'UTF-8')) ?></div>
    <?php
}

function toolsExpandingGptRenderFlow(string $functieNaam): void
{
    ?>
    <div class="flow">
        <strong>Keten (met GPT):</strong>
        testbericht → <code>CHATGPT()</code> →
        <code>chatGptMetTools()</code> →
        <code>voerChatToolUit(<?= htmlspecialchars($functieNaam, ENT_QUOTES, 'UTF-8') ?>)</code> →
        GPT antwoord
    </div>
    <?php
}
