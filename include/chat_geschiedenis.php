<?php

// Dit bestand slaat chatberichten op in de tabel chatHistory (HTML + JSON).
// Het wordt gebruikt door:
// - api/chat/send.php (bericht van de klant)
// - api/chat/worker.php (antwoord van Mr M)
//
// Doel: het gesprek blijft zichtbaar na verversen, net als bij de oude ChatGptMrM-flow.
// JSON-formaat is hetzelfde als in ChatGptMrM.php: [{"user":"..."},{"assistant":"..."}, ...]

// Zet het chat-type om naar de JSON-sleutel (user / assistant).
function jsonSleutelVoorChatType(string $type): ?string
{
    if ($type === 'user') {
        return 'user';
    }
    if ($type === 'bot') {
        return 'assistant';
    }

    return null;
}

// Voegt 1 bericht toe aan de JSON-array (zelfde structuur als de oude chat).
function voegToeAanConversationJsonArray(array $bestaand, string $type, string $tekst): array
{
    $sleutel = jsonSleutelVoorChatType($type);
    if ($sleutel === null) {
        return $bestaand;
    }

    $tekst = trim($tekst);
    if ($tekst === '') {
        return $bestaand;
    }

    $bestaand[] = [$sleutel => $tekst];

    return $bestaand;
}

// Leest conversationJSON uit de database en maakt er een PHP-array van.
function laadConversationJsonArray(?string $jsonTekst): array
{
    if (!is_string($jsonTekst) || trim($jsonTekst) === '') {
        return [];
    }

    $data = json_decode($jsonTekst, true);
    if (!is_array($data)) {
        return [];
    }

    return $data;
}

// Zet URL’s in bot-tekst om naar klikbare links (zelfde idee als in ChatBotMrM.js).
function zetUrlsOmNaarLinksInHtml(string $tekst): string
{
    $tekst = trim($tekst);
    if ($tekst === '') {
        return '';
    }

    $pattern = '#(https?://[^\s<]+|www\.[^\s<]+)#i';
    if (preg_match_all($pattern, $tekst, $matches, PREG_OFFSET_CAPTURE) < 1 || empty($matches[0])) {
        return htmlspecialchars($tekst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $html = '';
    $offset = 0;

    foreach ($matches[0] as $match) {
        $raw = (string) $match[0];
        $start = (int) $match[1];
        if ($start > $offset) {
            $html .= htmlspecialchars(substr($tekst, $offset, $start - $offset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $urlText = $raw;
        $trailing = '';
        while ($urlText !== '' && preg_match('/[)\].,!?;:]$/', $urlText) === 1) {
            $trailing = substr($urlText, -1) . $trailing;
            $urlText = substr($urlText, 0, -1);
        }

        if ($urlText !== '') {
            $href = preg_match('/^https?:\/\//i', $urlText) === 1 ? $urlText : ('https://' . $urlText);
            $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">'
                . htmlspecialchars($urlText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        } else {
            $html .= htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($trailing !== '') {
            $html .= htmlspecialchars($trailing, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $offset = $start + strlen($raw);
    }

    if ($offset < strlen($tekst)) {
        $html .= htmlspecialchars(substr($tekst, $offset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    return $html;
}

// Maakt 1 chatregel als HTML (tijd staat naast de tekst, niet eroverheen).
function maakChatBerichtHtml(string $type, string $tekst): string
{
    $type = in_array($type, ['user', 'bot', 'system'], true) ? $type : 'system';
    $tekst = trim($tekst);
    if ($tekst === '') {
        return '';
    }

    if ($type === 'bot') {
        $inhoud = zetUrlsOmNaarLinksInHtml($tekst);
    } else {
        $inhoud = htmlspecialchars($tekst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $tijd = date('H:i');

    return "<div class='chat-message {$type}'><p>{$inhoud}</p><span class='message-time'>{$tijd}</span></div>";
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
        $stmt = $conn->prepare('SELECT conversationJSON, conversationHTML FROM chatHistory WHERE cookie = ? LIMIT 1');
        $stmt->execute([$cookie]);
        $rij = $stmt->fetch();

        if ($rij) {
            $jsonArray = laadConversationJsonArray($rij['conversationJSON'] ?? '');
            $jsonArray = voegToeAanConversationJsonArray($jsonArray, $type, $tekst);
            $jsonTekst = json_encode($jsonArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($jsonTekst)) {
                $jsonTekst = '[]';
            }

            $nieuwHtml = (string) ($rij['conversationHTML'] ?? '') . $html;
            $update = $conn->prepare(
                'UPDATE chatHistory SET conversationJSON = ?, conversationHTML = ? WHERE cookie = ?'
            );
            $update->execute([$jsonTekst, $nieuwHtml, $cookie]);
            return;
        }

        $jsonArray = voegToeAanConversationJsonArray([], $type, $tekst);
        $jsonTekst = json_encode($jsonArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($jsonTekst)) {
            $jsonTekst = '[]';
        }

        $insert = $conn->prepare(
            'INSERT INTO chatHistory (cookie, conversationJSON, conversationHTML, page_info) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$cookie, $jsonTekst, $html, 'ChatBotMrM queue']);
    } catch (Throwable $e) {
        // Geen crash: chat moet doorlopen als geschiedenis even niet kan.
    }
}
