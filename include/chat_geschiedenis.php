<?php

// Dit bestand slaat chatberichten op in de tabel chatHistory (HTML voor het scherm).
// Het wordt gebruikt door:
// - api/chat/send.php (bericht van de klant)
// - api/chat/worker.php (antwoord van Mr M)
//
// Doel: het gesprek blijft zichtbaar na verversen, net als bij de oude ChatGptMrM-flow.

// Maakt 1 chatregel als HTML (tijd staat naast de tekst, niet eroverheen).
function maakChatBerichtHtml(string $type, string $tekst): string
{
    $type = in_array($type, ['user', 'bot', 'system'], true) ? $type : 'system';
    $tekst = trim($tekst);
    if ($tekst === '') {
        return '';
    }

    $veilig = htmlspecialchars($tekst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $tijd = date('H:i');

    return "<div class='chat-message {$type}'><p>{$veilig}</p><span class='message-time'>{$tijd}</span></div>";
}

// Voegt 1 bericht toe aan chatHistory voor deze cookie (maakt rij aan als die nog niet bestaat).
function voegBerichtToeAanChatGeschiedenis(PDO $conn, string $cookie, string $type, string $tekst): void
{
    $cookie = trim($cookie);
    if ($cookie === '') {
        return;
    }

    $html = maakChatBerichtHtml($type, $tekst);
    if ($html === '') {
        return;
    }

    try {
        $stmt = $conn->prepare('SELECT conversationHTML FROM chatHistory WHERE cookie = ? LIMIT 1');
        $stmt->execute([$cookie]);
        $rij = $stmt->fetch();

        if ($rij && isset($rij['conversationHTML'])) {
            $nieuw = (string) $rij['conversationHTML'] . $html;
            $update = $conn->prepare('UPDATE chatHistory SET conversationHTML = ? WHERE cookie = ?');
            $update->execute([$nieuw, $cookie]);
            return;
        }

        $insert = $conn->prepare(
            'INSERT INTO chatHistory (cookie, conversationJSON, conversationHTML, page_info) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$cookie, '', $html, 'ChatBotMrM queue']);
    } catch (Throwable $e) {
        // Geen crash: chat moet doorlopen als geschiedenis even niet kan.
    }
}
