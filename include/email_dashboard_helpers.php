<?php

function base64UrlDecode($data)
{
    // Gmail gebruikt base64url encoding voor inhoud.
    $data = strtr((string) $data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($data, true);
    return $decoded === false ? '' : $decoded;
}

function formatteerBestandsgrootte($bytes)
{
    // Maak bytes leesbaar voor het dashboard (KB/MB).
    $bytes = (int) $bytes;
    if ($bytes <= 0) {
        return '';
    }
    if ($bytes < 1024) {
        return (string) $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
}

function normaliseerContentId($cid)
{
    // In mails staat Content-ID soms met < en > eromheen. Die halen we weg.
    $cid = trim((string) $cid);
    if ($cid === '') {
        return '';
    }
    return trim($cid, "<> \t\r\n");
}

function normaliseerTekst($text)
{
    // Maakt tekst voorspelbaar: overal \n en geen grote lege blokken.
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim((string) $text);
}

function haalHeaderOp($headers, $naam)
{
    // Gmail geeft headers als lijst met naam + waarde. Deze functie zoekt één header op.
    if (!is_array($headers)) {
        return null;
    }
    foreach ($headers as $h) {
        if (!is_array($h)) {
            continue;
        }
        $n = isset($h['name']) ? (string) $h['name'] : '';
        if (strcasecmp($n, (string) $naam) === 0) {
            return isset($h['value']) ? (string) $h['value'] : '';
        }
    }
    return null;
}

function verzamelBijlagePartsUitPayload($payload, &$result, $path = '')
{
    // Gmail bouwt een mail op uit stukjes (parts). Bijlages zitten meestal als part met een bijlage-id.
    if (!is_array($payload)) {
        return;
    }

    $mimeType = isset($payload['mimeType']) ? strtolower(trim((string) $payload['mimeType'])) : '';
    $filename = isset($payload['filename']) ? trim((string) $payload['filename']) : '';
    $headers = (isset($payload['headers']) && is_array($payload['headers'])) ? $payload['headers'] : [];

    $contentId = normaliseerContentId((string) (haalHeaderOp($headers, 'Content-ID') ?? (haalHeaderOp($headers, 'Content-Id') ?? '')));
    $disp = strtolower((string) (haalHeaderOp($headers, 'Content-Disposition') ?? ''));

    $body = (isset($payload['body']) && is_array($payload['body'])) ? $payload['body'] : [];
    $attachmentId = isset($body['attachmentId']) ? trim((string) $body['attachmentId']) : '';
    $size = isset($body['size']) ? (int) $body['size'] : 0;
    $inlineData = isset($body['data']) && is_string($body['data']) && $body['data'] !== '' ? (string) $body['data'] : '';

    $isContainer = ($mimeType === 'multipart/alternative' || $mimeType === 'multipart/mixed' || $mimeType === 'multipart/related');

    // We nemen alleen echte bijlages/plaatjes mee, niet de "container" parts.
    $isAttachmentLike = !$isContainer && ($attachmentId !== '' || $inlineData !== '' || $filename !== '' || $contentId !== '' || ($disp !== '' && strpos($disp, 'attachment') !== false));
    if ($isAttachmentLike) {
        $result[] = [
            'filename' => $filename,
            'mimeType' => $mimeType,
            'size' => $size,
            'attachmentId' => $attachmentId,
            'contentId' => $contentId,
            'partPath' => (string) $path,
            'hasInlineData' => $inlineData !== '',
        ];
    }

    if (isset($payload['parts']) && is_array($payload['parts'])) {
        foreach ($payload['parts'] as $i => $part) {
            $childPath = $path === '' ? (string) $i : ($path . '.' . (string) $i);
            verzamelBijlagePartsUitPayload($part, $result, $childPath);
        }
    }
}

function haalBijlagesUitPayload($payload)
{
    // Geef een platte lijst terug van alle gevonden bijlages in 1 mail.
    $result = [];
    verzamelBijlagePartsUitPayload($payload, $result, '');
    return $result;
}

function vindBijlagePartOpAttachmentId($payload, $attachmentId)
{
    // Nodig om type + bestandsnaam bij een bijlage-id te vinden.
    if (!is_array($payload)) {
        return null;
    }
    $body = (isset($payload['body']) && is_array($payload['body'])) ? $payload['body'] : [];
    $partAtt = isset($body['attachmentId']) ? trim((string) $body['attachmentId']) : '';
    if ($partAtt !== '' && hash_equals($partAtt, (string) $attachmentId)) {
        return $payload;
    }
    if (isset($payload['parts']) && is_array($payload['parts'])) {
        foreach ($payload['parts'] as $p) {
            $found = vindBijlagePartOpAttachmentId($p, $attachmentId);
            if (is_array($found)) {
                return $found;
            }
        }
    }
    return null;
}

function vindBijlagePartOpPad($payload, $partPath)
{
    // Sommige inline plaatjes hebben geen bijlage-id, maar wel body.data in een part.
    // Hiervoor gebruiken we een "pad" zoals 0.1.2 om het juiste part terug te vinden.
    if (!is_array($payload)) {
        return null;
    }
    $partPath = trim((string) $partPath);
    if ($partPath === '') {
        return null;
    }
    if (preg_match('/^\d+(?:\.\d+)*$/', $partPath) !== 1) {
        return null;
    }

    $current = $payload;
    $bits = explode('.', $partPath);
    foreach ($bits as $b) {
        $idx = (int) $b;
        if (!isset($current['parts']) || !is_array($current['parts']) || !array_key_exists($idx, $current['parts'])) {
            return null;
        }
        $current = $current['parts'][$idx];
        if (!is_array($current)) {
            return null;
        }
    }
    return $current;
}

function emailDashboardAttachmentUrl($messageId, $attachmentId, $filename = '', $inline = false)
{
    // Deze link gebruiken we om een bijlage te downloaden of een plaatje te tonen.
    $u = '/EmailDashboard.php?attachment=1&message_id=' . urlencode((string) $messageId) . '&attachment_id=' . urlencode((string) $attachmentId);
    if ($filename !== '') {
        $u .= '&filename=' . urlencode((string) $filename);
    }
    $u .= $inline ? '&inline=1' : '&download=1';
    return $u;
}

function emailDashboardInlinePartUrl($messageId, $partPath, $filename = '', $inline = true)
{
    // Zelfde als emailDashboardAttachmentUrl, maar dan voor inline parts (body.data).
    $u = '/EmailDashboard.php?attachment=1&message_id=' . urlencode((string) $messageId) . '&part_path=' . urlencode((string) $partPath);
    if ($filename !== '') {
        $u .= '&filename=' . urlencode((string) $filename);
    }
    $u .= $inline ? '&inline=1' : '&download=1';
    return $u;
}

function vervangCidSrcInHtml($html, $cidToUrl)
{
    // Sommige mails gebruiken <img src="cid:...">. Dat is een interne verwijzing naar een bijlage.
    // We vervangen dat door een link in het dashboard die het plaatje ophaalt.
    if (!is_string($html) || trim($html) === '' || !is_array($cidToUrl) || empty($cidToUrl)) {
        return (string) $html;
    }

    return preg_replace_callback('/src\s*=\s*(["\'])\s*cid:([^"\'>\s]+)\s*\1/i', function ($m) use ($cidToUrl) {
        $cid = normaliseerContentId((string) ($m[2] ?? ''));
        if ($cid === '' || !isset($cidToUrl[$cid])) {
            return 'src=""';
        }
        return 'src="' . htmlspecialchars((string) $cidToUrl[$cid], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }, (string) $html);
}

function zoekTekstPlainInPayload($payload)
{
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['mimeType']) && (string) $payload['mimeType'] === 'text/plain') {
        $data = $payload['body']['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = base64UrlDecode($data);
            return $decoded !== '' ? $decoded : null;
        }
    }

    if (isset($payload['parts']) && is_array($payload['parts'])) {
        foreach ($payload['parts'] as $part) {
            $found = zoekTekstPlainInPayload($part);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }
    }

    return null;
}

function zoekTekstHtmlInPayload($payload)
{
    // Sommige mails hebben alleen HTML (geen text/plain).
    // Deze functie zet HTML om naar normale tekst, zodat we het in het dashboard kunnen tonen.
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['mimeType']) && (string) $payload['mimeType'] === 'text/html') {
        $data = $payload['body']['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = base64UrlDecode($data);
            if ($decoded === '') {
                return null;
            }

            $html = str_replace(["\r\n", "\r"], "\n", $decoded);
            $html = preg_replace('/<\s*head\b[^>]*>[\s\S]*?<\s*\/\s*head\s*>/i', '', (string) $html);
            $html = preg_replace('/<\s*style\b[^>]*>[\s\S]*?<\s*\/\s*style\s*>/i', '', (string) $html);
            $html = preg_replace('/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/i', '', (string) $html);
            $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
            $html = preg_replace('/<\/\s*p\s*>/i', "\n\n", $html);
            $html = preg_replace('/<\/\s*div\s*>/i', "\n", $html);
            $text = strip_tags($html);
            $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
            $text = trim((string) $text);
            return $text !== '' ? $text : null;
        }
    }

    if (isset($payload['parts']) && is_array($payload['parts'])) {
        foreach ($payload['parts'] as $part) {
            $found = zoekTekstHtmlInPayload($part);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }
    }

    return null;
}

function haalHtmlUitPayload($payload)
{
    // Sommige mails hebben alleen HTML, dit haalt de ruwe HTML string uit de payload.
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['mimeType']) && (string) $payload['mimeType'] === 'text/html') {
        $data = $payload['body']['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = base64UrlDecode($data);
            return $decoded !== '' ? $decoded : null;
        }
    }

    if (isset($payload['parts']) && is_array($payload['parts'])) {
        foreach ($payload['parts'] as $part) {
            $found = haalHtmlUitPayload($part);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }
    }

    return null;
}

function sanitizeEmailHtmlVoorDashboard($html)
{
    // We willen opmaak tonen, maar geen scripts/styling/rare attributen uitvoeren.
    // US24: we laten ook <img> toe, maar alleen als src naar ons eigen dashboard wijst (geen externe plaatjes).
    $html = str_replace(["\r\n", "\r"], "\n", (string) $html);
    $html = preg_replace('/<\s*head\b[^>]*>[\s\S]*?<\s*\/\s*head\s*>/i', '', (string) $html);
    $html = preg_replace('/<\s*style\b[^>]*>[\s\S]*?<\s*\/\s*style\s*>/i', '', (string) $html);
    $html = preg_replace('/<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/i', '', (string) $html);
    $html = preg_replace('/<\s*meta\b[^>]*>/i', '', (string) $html);
    $html = preg_replace('/<\s*link\b[^>]*>/i', '', (string) $html);

    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><a><img><table><thead><tbody><tr><td><th><div><span><hr>';
    $html = strip_tags((string) $html, $allowed);

    $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', (string) $html);
    $html = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', (string) $html);
    $html = preg_replace('/\son\w+\s*=\s*[^\s>]+/i', '', (string) $html);

    $html = preg_replace('/\sstyle\s*=\s*"[^"]*"/i', '', (string) $html);
    $html = preg_replace("/\sstyle\s*=\s*'[^']*'/i", '', (string) $html);
    $html = preg_replace('/\sclass\s*=\s*"[^"]*"/i', '', (string) $html);
    $html = preg_replace("/\sclass\s*=\s*'[^']*'/i", '', (string) $html);

    $html = preg_replace_callback('/<\s*a\b([^>]*)>/i', function ($m) {
        $attrs = (string) ($m[1] ?? '');
        $href = '';
        if (preg_match('/href\s*=\s*"([^"]*)"/i', $attrs, $hm) === 1) {
            $href = (string) ($hm[1] ?? '');
        } elseif (preg_match("/href\s*=\s*'([^']*)'/i", $attrs, $hm) === 1) {
            $href = (string) ($hm[1] ?? '');
        }

        $href = html_entity_decode((string) $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $href = trim((string) $href);
        if ($href !== '' && preg_match('/^\s*javascript:/i', $href) === 1) {
            $href = '';
        }

        if ($href === '') {
            return '<a>';
        }

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">';
    }, (string) $html);

    $html = preg_replace_callback('/<\s*img\b([^>]*)>/i', function ($m) {
        // We laten alleen afbeeldingen toe die we zelf serveren (geen plaatjes van andere websites).
        $attrs = (string) ($m[1] ?? '');
        $src = '';
        $alt = '';
        if (preg_match('/src\s*=\s*"([^"]*)"/i', $attrs, $sm) === 1) {
            $src = (string) ($sm[1] ?? '');
        } elseif (preg_match("/src\s*=\s*'([^']*)'/i", $attrs, $sm) === 1) {
            $src = (string) ($sm[1] ?? '');
        }
        if (preg_match('/alt\s*=\s*"([^"]*)"/i', $attrs, $am) === 1) {
            $alt = (string) ($am[1] ?? '');
        } elseif (preg_match("/alt\s*=\s*'([^']*)'/i", $attrs, $am) === 1) {
            $alt = (string) ($am[1] ?? '');
        }

        $src = html_entity_decode((string) $src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alt = html_entity_decode((string) $alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $src = trim((string) $src);
        $alt = trim((string) $alt);

        if ($src === '' || preg_match('/^\s*\/EmailDashboard\.php\?attachment=1/i', $src) !== 1) {
            return '';
        }

        $safeAlt = $alt !== '' ? ' alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' . $safeAlt . ' style="max-width:100%; height:auto; display:block;">';
    }, (string) $html);

    $html = preg_replace("/\n{3,}/", "\n\n", (string) $html);
    $html = trim((string) $html);
    return $html;
}

function parseerEmailAdresUitFromHeader($fromHeader)
{
    // We willen alleen het e-mailadres uit de afzender-regel (From) halen.
    $fromHeader = trim((string) $fromHeader);
    if ($fromHeader === '') {
        return '';
    }

    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fromHeader, $m) === 1) {
        $candidate = trim((string) ($m[0] ?? ''));
        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }

    return '';
}

function extracteerBestelEnEmailUitTekst($text)
{
    // Deze functie probeert uit vrije tekst 2 dingen te halen:
    // 1) E-mailadres (voor privacy-check in de DB)
    // 2) Bestelnummer (de order id in onze database)
    //
    // We zijn bewust “simpel”: we zoeken alleen een nummer als er ook woorden zoals
    // "bestelnummer/bestelling/order" in de tekst staan. Dan pakken we het eerste nummer.
    $t = (string) $text;
    $email = '';
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $t, $m) === 1) {
        $candidate = strtolower(trim((string) ($m[0] ?? '')));
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $email = $candidate;
        }
    }

    $bestellingId = 0;
    if (preg_match('/\b(bestel(?:nummer|nr)?|bestelling|order)\b[^\d]{0,20}(\d+)/i', $t, $m) === 1) {
        $bestellingId = (int) ($m[2] ?? 0);
    } elseif (preg_match('/\b(bestel(?:nummer|nr)?|bestelling|order)\b/i', $t) === 1 && preg_match('/\b\d+\b/', $t, $m) === 1) {
        $bestellingId = (int) ($m[0] ?? 0);
    }

    return [
        'bestelling_id' => $bestellingId,
        'email' => $email,
    ];
}

function parseerEmailAdressenUitHeaderTekst($headerTekst)
{
    // Haal 1 of meerdere e-mailadressen uit een header-string (To/Cc/Delivered-To/etc).
    $t = trim((string) $headerTekst);
    if ($t === '') {
        return [];
    }

    $result = [];
    if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $t, $matches) > 0) {
        $found = $matches[0] ?? [];
        if (is_array($found)) {
            foreach ($found as $m) {
                $candidate = strtolower(trim((string) $m));
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $result[] = $candidate;
                }
            }
        }
    } else {
        $single = parseerEmailAdresUitFromHeader($t);
        if ($single !== '') {
            $result[] = strtolower($single);
        }
    }

    return array_values(array_unique($result));
}

function stripQuotedEnHandtekeningTekst($text)
{
    // Neemt "oude replies" en handtekeningen weg, zodat we alleen de echte vraag overhouden.
    $t = normaliseerTekst($text);
    if ($t === '') {
        return '';
    }

    $cutPatterns = [
        "/\nOn[^\n]{0,200}wrote:\n/i",
        "/\nOp[^\n]{0,200}schreef[^\n]{0,200}:\n/i",
        "/\nVan:\s*[^\n]+\nVerzonden:\s*[^\n]+\nAan:\s*[^\n]+\nOnderwerp:\s*[^\n]+\n/i",
        "/\nFrom:\s*[^\n]+\nSent:\s*[^\n]+\nTo:\s*[^\n]+\nSubject:\s*[^\n]+\n/i",
    ];
    foreach ($cutPatterns as $re) {
        if (preg_match($re, $t, $m, PREG_OFFSET_CAPTURE) === 1) {
            $pos = (int) ($m[0][1] ?? -1);
            if ($pos > 0) {
                $t = substr($t, 0, $pos);
            }
            break;
        }
    }

    $lines = preg_split("/\n/", $t);
    if (is_array($lines)) {
        $keep = [];
        foreach ($lines as $line) {
            $l = (string) $line;
            if (preg_match('/^\s*>+/', $l) === 1) {
                continue;
            }
            $keep[] = $l;
        }
        $t = implode("\n", $keep);
    }

    $sigMarkers = [
        "\n-- \n",
        "\n--\n",
        "\nMet vriendelijke groet",
        "\nVriendelijke groet",
        "\nGroeten,",
        "\nKind regards",
        "\nBest regards",
        "\nSent from my",
    ];
    foreach ($sigMarkers as $m) {
        $p = stripos($t, $m);
        if ($p !== false && $p > 0) {
            $t = substr($t, 0, (int) $p);
            break;
        }
    }

    $t = preg_replace("/\n{3,}/", "\n\n", (string) $t);
    return trim((string) $t);
}

function bouwRfc2822Bericht($toEmail, $subject, $bodyText, $inReplyTo = null, $references = null, $fromHeader = null)
{
    // Gmail API verwacht het bericht als RFC2822 in base64url (raw).
    $headers = [];
    if (is_string($fromHeader) && trim((string) $fromHeader) !== '') {
        $headers[] = 'From: ' . trim((string) $fromHeader);
    }
    $headers[] = 'To: ' . (string) $toEmail;
    $headers[] = 'Subject: ' . (string) $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset="UTF-8"';
    $headers[] = 'Content-Transfer-Encoding: 7bit';
    if (is_string($inReplyTo) && $inReplyTo !== '') {
        $headers[] = 'In-Reply-To: ' . $inReplyTo;
    }
    if (is_string($references) && $references !== '') {
        $headers[] = 'References: ' . $references;
    }

    $raw = implode("\r\n", $headers) . "\r\n\r\n" . (string) $bodyText;
    $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return $b64;
}

function verwerkEmailRulesVoorMail($rules, $fromHeader, $subject)
{
    // Dit controleert of de mail voldoet aan regels, en geeft acties terug.
    $fromHeader = strtolower((string) $fromHeader);
    $subject = strtolower((string) $subject);

    $ignore = false;
    $extra = [];

    foreach ($rules as $r) {
        if (!is_array($r)) {
            continue;
        }
        $condType = isset($r['condition_type']) ? (string) $r['condition_type'] : '';
        $condValue = isset($r['condition_value']) ? trim((string) $r['condition_value']) : '';
        $actionType = isset($r['action_type']) ? (string) $r['action_type'] : '';
        $actionValue = isset($r['action_value']) ? trim((string) $r['action_value']) : '';

        if ($condValue === '' || $condType === '' || $actionType === '') {
            continue;
        }

        $needle = strtolower($condValue);
        $match = false;
        if ($condType === 'from_contains') {
            $match = (strpos($fromHeader, $needle) !== false);
        } elseif ($condType === 'subject_contains') {
            $match = (strpos($subject, $needle) !== false);
        }

        if (!$match) {
            continue;
        }

        if ($actionType === 'ignore') {
            $ignore = true;
            break;
        }
        if ($actionType === 'add_prompt' && $actionValue !== '') {
            $extra[] = $actionValue;
        }
    }

    return [
        'ignore' => $ignore,
        'extra_instructies' => implode("\n\n", $extra),
    ];
}

