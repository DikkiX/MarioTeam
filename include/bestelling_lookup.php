<?php

// Dit bestand bevat gedeelde “order lookup” helpers.
// Het wordt gebruikt door:
// - Chatbot worker (api/chat/worker.php) via de tool `zoek_bestelling`
// - EmailDashboard (EmailDashboard.php) om orderdata alvast op te halen vóór de AI-call (US23)
//
// Doel: 1 plek voor order-queries + parsing, zodat je het niet dubbel onderhoudt.
// Dit hoort bewust in /include (gedeelde helper) en niet in /api (endpoint).

// Kleine helper: maak tekst lowercase (met UTF-8 support als mbstring aanwezig is).
function lowerTekst($text)
{
    $t = (string) $text;
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($t, 'UTF-8');
    }
    return strtolower($t);
}

// Zet de tekst uit Bestellingen.items om naar een lijst met artikelen.
// Output: array van items met { productnaam, aantal, prijs_euro? }.
function parseBestellingItemsTekst($itemsTekst)
{
    $t = trim((string) $itemsTekst);
    if ($t === '') {
        return [];
    }

    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $delen = preg_split('/\n+/', $t);
    if (!is_array($delen)) {
        $delen = [$t];
    }

    $samengevoegd = [];

    foreach ($delen as $deel) {
        $regel = trim((string) $deel);
        if ($regel === '') {
            continue;
        }

        if (preg_match('/^(korting|betaal|betaling)\s*[:=]/i', $regel) === 1) {
            continue;
        }
        if (preg_match('/^verzendkosten\s*[:=]/i', $regel) === 1) {
            continue;
        }
        if (preg_match('/^totaal\s*[:=]/i', $regel) === 1) {
            continue;
        }

        $aantal = 1;
        $naam = $regel;
        $prijsEuro = null;

        if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)\s*$/u', $regel, $m) === 1) {
            $aantal = (int) $m[1];
            $naam = trim((string) $m[2]);
        }

        if (preg_match('/^(.*?)\s*->\s*([\d.,]+)\s*euro\s*$/i', (string) $naam, $m) === 1) {
            $naam = trim((string) $m[1]);
            $rawPrijs = str_replace(',', '.', (string) $m[2]);
            if (is_numeric($rawPrijs)) {
                $prijsEuro = (float) $rawPrijs;
            }
        }

        if ($aantal <= 0) {
            $aantal = 1;
        }
        if ($naam === '') {
            continue;
        }

        $prijsKey = $prijsEuro === null ? '' : number_format($prijsEuro, 2, '.', '');
        $key = lowerTekst($naam) . '|' . $prijsKey;
        if (!isset($samengevoegd[$key])) {
            $samengevoegd[$key] = [
                'productnaam' => $naam,
                'aantal' => 0,
                'prijs_euro' => $prijsEuro,
            ];
        }
        $samengevoegd[$key]['aantal'] += $aantal;
    }

    $artikelen = array_values($samengevoegd);
    usort($artikelen, function ($a, $b) {
        return strcmp((string) ($a['productnaam'] ?? ''), (string) ($b['productnaam'] ?? ''));
    });

    return $artikelen;
}

// Probeert uit een “tracktrace” veld een echte Track&Trace code te halen.
// In de Bestellingen tabel lijkt dit een '|' gescheiden string te zijn (bijv. "|||9|3SGDWQ838080473").
// We pakken daarom de laatste “code-achtige” waarde.
function haalTrackCodeUitTracktrace($tracktrace)
{
    $tt = trim((string) $tracktrace);
    if ($tt === '') {
        return '';
    }

    $delen = preg_split('/\|+/', $tt);
    if (!is_array($delen) || empty($delen)) {
        return '';
    }

    for ($i = count($delen) - 1; $i >= 0; $i--) {
        $candidate = trim((string) $delen[$i]);
        if ($candidate === '') {
            continue;
        }
        if (preg_match('/^[A-Z0-9]{6,}$/i', $candidate) === 1) {
            return $candidate;
        }
    }

    return '';
}

// Haal 1 bestelling op uit de DB, op basis van (bestelling_id + email).
// Belangrijk: we doen hier expres een match op email om privacy te beschermen.
function haalBestellingOp($conn, $bestellingId, $email)
{
    $bestellingId = (int) $bestellingId;
    $email = trim((string) $email);
    if ($bestellingId <= 0 || $email === '') {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT
            id,
            betaling,
            verzendkosten,
            totaal,
            totaal_site,
            status,
            verzending,
            datum,
            PayStatus,
            tracktrace,
            items,
            inpakdatum
        FROM Bestellingen
        WHERE id = :id AND mail = :email
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $bestellingId,
        ':email' => $email,
    ]);
    $row = $stmt->fetch();
    return $row ? $row : false;
}

// Hoofdfunctie: haalt orderdata op en geeft ruwe data terug.
// - Geen “mooie klanttekst” hier: dat doet de AI.
// - Veilig: als id+email niet matchen, komt er niets terug.
// - Bij fout: returnt een nette melding zonder privédata.
function zoekBestellingRuw($conn, $bestellingId, $email)
{
    $bestellingId = (int) $bestellingId;
    $email = trim((string) $email);
    if ($bestellingId <= 0 || $email === '') {
        return [
            'functie' => 'zoek_bestelling',
            'gevonden' => false,
            'message' => 'Voor orderdata zijn zowel bestelling_id als email verplicht.',
        ];
    }

    try {
        $resultaat = haalBestellingOp($conn, $bestellingId, $email);
        if ($resultaat === false) {
            return [
                'functie' => 'zoek_bestelling',
                'gevonden' => false,
                'message' => 'De combinatie van bestelling_id en email klopt niet.',
            ];
        }

        $artikelenInfo = [
            'gevonden' => false,
            'artikelen' => [],
            'bron' => '',
            'message' => '',
        ];
        if ($resultaat !== false) {
            $itemsTekst = isset($resultaat['items']) ? trim((string) $resultaat['items']) : '';
            if ($itemsTekst !== '') {
                $fallback = parseBestellingItemsTekst($itemsTekst);
                if (!empty($fallback)) {
                    $artikelenInfo = [
                        'gevonden' => true,
                        'artikelen' => $fallback,
                        'bron' => 'Bestellingen.items',
                        'message' => '',
                    ];
                } else {
                    $artikelenInfo['message'] = 'Geen artikelregels gevonden in bestelling.';
                }
            } else {
                $artikelenInfo['message'] = 'Geen items gevonden in bestelling.';
            }
        }

        $verzendStatus = 'onbekend';
        $tracktrace = is_array($resultaat) && isset($resultaat['tracktrace']) ? trim((string) $resultaat['tracktrace']) : '';
        $statusTekst = is_array($resultaat) && isset($resultaat['status']) ? trim((string) $resultaat['status']) : '';
        $verzendingTekst = is_array($resultaat) && isset($resultaat['verzending']) ? trim((string) $resultaat['verzending']) : '';
        $trackCode = haalTrackCodeUitTracktrace($tracktrace);

        $heeftVerzendTekst = $verzendingTekst !== '' && preg_match('/\bverzonden\b/i', $verzendingTekst) === 1;
        $heeftInpakDatum = is_array($resultaat) && isset($resultaat['inpakdatum']) && (int) $resultaat['inpakdatum'] > 0;
        $statusIsVerzonden = $statusTekst === '3';

        if ($trackCode !== '') {
            $verzendStatus = 'verzonden';
        } elseif ($statusIsVerzonden) {
            $verzendStatus = 'verzonden';
        } elseif ($heeftVerzendTekst) {
            $verzendStatus = 'verzonden';
        } elseif ($heeftInpakDatum) {
            $verzendStatus = 'niet_verzonden';
        } else {
            $verzendStatus = 'niet_verzonden';
        }

        if (is_array($resultaat)) {
            $resultaat['verzend_status'] = $verzendStatus;
            $resultaat['track_code'] = $trackCode;
        }

        return [
            'functie' => 'zoek_bestelling',
            'gevonden' => $resultaat !== false,
            'resultaat' => $resultaat,
            'artikelen' => $artikelenInfo['artikelen'] ?? [],
            'artikelen_gevonden' => (bool) ($artikelenInfo['gevonden'] ?? false),
            'artikelen_bron' => (string) ($artikelenInfo['bron'] ?? ''),
            'artikelen_message' => (string) ($artikelenInfo['message'] ?? ''),
        ];
    } catch (Throwable) {
        return [
            'functie' => 'zoek_bestelling',
            'gevonden' => false,
            'message' => 'Order lookup is nu niet beschikbaar.',
        ];
    }
}
