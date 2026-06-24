<?php

// Adres ophalen en wijzigen in tabel Bestellingen.
// Gebruikt door chat_tools.php → wijzig_bestelling_adres.
//
// Alleen bestelnummer nodig. Kolommen: naam, adres, telefoon, mail.
// `adres` = volledige adresregel in één veld (bv. "Straat 1, 1234AB Plaats, Nederland").

include_once __DIR__ . '/bestelling_lookup.php';

/** @return list<string> */
function bestellingAdresDbKolommen(): array
{
    return ['naam', 'adres', 'telefoon', 'mail'];
}

/** Velden die de klant/medewerker mag wijzigen (logische naam → DB-kolom). */
function bestellingAdresWijzigbareVelden(): array
{
    return [
        'naam' => 'naam',
        'adres' => 'adres',
        'telefoon' => 'telefoon',
    ];
}

function normaliseerBestellingAdresWaarde(string $veld, string $waarde): string
{
    return trim($waarde);
}

/**
 * @return array<string, string>
 */
function haalNieuweAdresVeldenUitArgs(array $args): array
{
    $velden = [];
    foreach (bestellingAdresWijzigbareVelden() as $logisch => $dbKolom) {
        if (!array_key_exists($logisch, $args)) {
            continue;
        }
        $waarde = normaliseerBestellingAdresWaarde($logisch, (string) $args[$logisch]);
        if ($waarde !== '') {
            $velden[$logisch] = $waarde;
        }
    }

    return $velden;
}

/**
 * @param array<string, mixed> $row
 * @return array{mag: bool, reden: string}
 */
function magBestellingAdresWijzigen(array $row): array
{
    $tracktrace = trim((string) ($row['tracktrace'] ?? ''));
    $trackCode = haalTrackCodeUitTracktrace($tracktrace);
    if ($trackCode === '' && preg_match('/^[A-Z0-9]{6,}$/i', $tracktrace) === 1) {
        $trackCode = strtoupper($tracktrace);
    }
    if ($trackCode !== '') {
        return [
            'mag' => false,
            'reden' => 'De bestelling is al verzonden (track & trace aanwezig).',
        ];
    }

    $statusTekst = trim((string) ($row['status'] ?? ''));
    if ($statusTekst === '3') {
        return [
            'mag' => false,
            'reden' => 'De bestelling heeft status verzonden.',
        ];
    }

    $verzendingTekst = trim((string) ($row['verzending'] ?? ''));
    if ($verzendingTekst !== '' && preg_match('/\bverzonden\b/i', $verzendingTekst) === 1) {
        return [
            'mag' => false,
            'reden' => 'De bestelling staat als verzonden in het systeem.',
        ];
    }

    return ['mag' => true, 'reden' => ''];
}

/**
 * @return array<string, string>
 */
function extraheerBestellingAdresUitRij(array $row): array
{
    $adres = [];
    foreach (bestellingAdresDbKolommen() as $dbKolom) {
        $adres[$dbKolom] = isset($row[$dbKolom]) ? trim((string) $row[$dbKolom]) : '';
    }

    return $adres;
}

/**
 * @return array<string, mixed>|false
 */
function haalBestellingMetAdresOp($conn, int $bestellingId)
{
    $bestellingId = (int) $bestellingId;
    if ($bestellingId <= 0) {
        return false;
    }

    $selectKolommen = array_unique(array_merge(
        bestellingAdresDbKolommen(),
        ['id', 'status', 'verzending', 'tracktrace', 'inpakdatum']
    ));

    $sql = 'SELECT ' . implode(', ', $selectKolommen)
        . ' FROM Bestellingen WHERE id = :id LIMIT 1';

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $bestellingId]);
        $row = $stmt->fetch();
        return $row ? $row : false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param array<string, string> $nieuweVelden logische veldnamen (naam, adres, telefoon)
 * @return array{ok: bool, message: string, adres?: array<string, string>}
 */
function slaBestellingAdresOp($conn, int $bestellingId, array $nieuweVelden): array
{
    $bestellingId = (int) $bestellingId;
    if ($bestellingId <= 0) {
        return ['ok' => false, 'message' => 'Voor adreswijziging is bestelling_id verplicht.'];
    }

    if ($nieuweVelden === []) {
        return ['ok' => false, 'message' => 'Geen nieuwe adresgegevens om op te slaan.'];
    }

    $row = haalBestellingMetAdresOp($conn, $bestellingId);
    if ($row === false) {
        return ['ok' => false, 'message' => 'Geen bestelling gevonden met dit bestelnummer.'];
    }

    $wijzigCheck = magBestellingAdresWijzigen($row);
    if (!$wijzigCheck['mag']) {
        return ['ok' => false, 'message' => $wijzigCheck['reden']];
    }

    $wijzigbaar = bestellingAdresWijzigbareVelden();
    $setParts = [];
    $params = [':id' => $bestellingId];

    foreach ($nieuweVelden as $logisch => $waarde) {
        if (!isset($wijzigbaar[$logisch])) {
            continue;
        }
        $dbKolom = $wijzigbaar[$logisch];
        $param = ':veld_' . $logisch;
        $setParts[] = $dbKolom . ' = ' . $param;
        $params[$param] = $waarde;
    }

    if ($setParts === []) {
        return ['ok' => false, 'message' => 'Geen geldige adresvelden om bij te werken.'];
    }

    $sql = 'UPDATE Bestellingen SET ' . implode(', ', $setParts)
        . ' WHERE id = :id LIMIT 1';

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable) {
        return ['ok' => false, 'message' => 'Adres opslaan is nu niet beschikbaar.'];
    }

    $vernieuwd = haalBestellingMetAdresOp($conn, $bestellingId);
    if ($vernieuwd === false) {
        return ['ok' => true, 'message' => 'Adres opgeslagen.'];
    }

    return [
        'ok' => true,
        'message' => 'Adres opgeslagen.',
        'adres' => extraheerBestellingAdresUitRij($vernieuwd),
    ];
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function bestellingAdresRuw($conn, array $args): array
{
    $bestellingId = isset($args['bestelling_id']) ? (int) $args['bestelling_id'] : 0;

    if ($bestellingId <= 0) {
        return [
            'functie' => 'wijzig_bestelling_adres',
            'gevonden' => false,
            'message' => 'Voor adresgegevens is bestelling_id verplicht.',
        ];
    }

    $nieuweVelden = haalNieuweAdresVeldenUitArgs($args);
    $isOpslaan = $nieuweVelden !== [];

    try {
        $row = haalBestellingMetAdresOp($conn, $bestellingId);
        if ($row === false) {
            return [
                'functie' => 'wijzig_bestelling_adres',
                'gevonden' => false,
                'message' => 'Geen bestelling gevonden met dit bestelnummer.',
            ];
        }

        $huidigAdres = extraheerBestellingAdresUitRij($row);
        $wijzigCheck = magBestellingAdresWijzigen($row);

        if ($isOpslaan) {
            if (!$wijzigCheck['mag']) {
                return [
                    'functie' => 'wijzig_bestelling_adres',
                    'gevonden' => true,
                    'actie' => 'opslaan',
                    'opgeslagen' => false,
                    'bestelling_id' => $bestellingId,
                    'adres' => $huidigAdres,
                    'mag_wijzigen' => false,
                    'message' => $wijzigCheck['reden'],
                ];
            }

            $opslaan = slaBestellingAdresOp($conn, $bestellingId, $nieuweVelden);
            $adresNaOpslaan = isset($opslaan['adres']) && is_array($opslaan['adres'])
                ? $opslaan['adres']
                : $huidigAdres;

            return [
                'functie' => 'wijzig_bestelling_adres',
                'gevonden' => true,
                'actie' => 'opslaan',
                'opgeslagen' => (bool) ($opslaan['ok'] ?? false),
                'bestelling_id' => $bestellingId,
                'adres' => $adresNaOpslaan,
                'mag_wijzigen' => true,
                'message' => (string) ($opslaan['message'] ?? ''),
            ];
        }

        return [
            'functie' => 'wijzig_bestelling_adres',
            'gevonden' => true,
            'actie' => 'ophalen',
            'bestelling_id' => $bestellingId,
            'adres' => $huidigAdres,
            'mag_wijzigen' => $wijzigCheck['mag'],
            'message' => $wijzigCheck['mag']
                ? 'Huidig adres opgehaald. Geef naam/adres/telefoon mee om op te slaan.'
                : $wijzigCheck['reden'],
        ];
    } catch (Throwable) {
        return [
            'functie' => 'wijzig_bestelling_adres',
            'gevonden' => false,
            'message' => 'Adres lookup is nu niet beschikbaar.',
        ];
    }
}
