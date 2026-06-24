<?php

// Verwerkt inkomende GLS webhook-posts en slaat de laatste pakketstatus op.
// Tabel: gls_pakket_status (aanmaken via database/setup_gls_pakket_status.php)
//
// GLS stuurt voor elk pakketevent een HTTP POST met JSON.
// Wij slaan de laatste status op zodat zoek_traceer dit uit de DB kan lezen.

/**
 * Haalt de laatste event-beschrijving en -datum op uit de events-array.
 *
 * @param array<int, array<string, mixed>> $events
 * @return array{beschrijving: string, datum: string}
 */
function glsLaatsteEventUitArray(array $events): array
{
    $laatste = ['beschrijving' => '', 'datum' => ''];

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $tekst = trim((string) ($event['DescriptionNL'] ?? $event['DescriptionEN'] ?? ''));
        $datum = trim((string) ($event['Date'] ?? ''));
        if ($tekst !== '') {
            $laatste = ['beschrijving' => $tekst, 'datum' => $datum];
        }
    }

    return $laatste;
}

/**
 * Verwerkt de ruwe GLS webhook-payload en slaat de status op in de DB.
 *
 * @param PDO    $conn
 * @param string $rawJson  Ruwe POST-body van GLS
 * @return array{ok: bool, message: string, parcel_no?: string}
 */
function verwerkGlsWebhookPayload(PDO $conn, string $rawJson): array
{
    $data = json_decode($rawJson, true);

    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Ongeldige JSON.'];
    }

    $parcelNo = trim((string) ($data['ParcelNo'] ?? $data['parcelNo'] ?? ''));
    if ($parcelNo === '') {
        return ['ok' => false, 'message' => 'ParcelNo ontbreekt in payload.'];
    }

    $state       = trim((string) ($data['State'] ?? $data['state'] ?? ''));
    $events      = isset($data['Events']) && is_array($data['Events']) ? $data['Events'] : [];
    $laatste     = glsLaatsteEventUitArray($events);
    $beschrijving = $laatste['beschrijving'];
    $datumEvent   = $laatste['datum'] !== '' ? glsIso8601NaarMysql($laatste['datum']) : null;

    if ($beschrijving === '' && $state !== '') {
        $beschrijving = $state;
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO gls_pakket_status (parcel_no, state, beschrijving, datum_event, raw_json)
            VALUES (:parcel_no, :state, :beschrijving, :datum_event, :raw_json)
            ON DUPLICATE KEY UPDATE
                state         = VALUES(state),
                beschrijving  = VALUES(beschrijving),
                datum_event   = VALUES(datum_event),
                raw_json      = VALUES(raw_json),
                bijgewerkt    = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':parcel_no'    => $parcelNo,
            ':state'        => $state,
            ':beschrijving' => $beschrijving,
            ':datum_event'  => $datumEvent,
            ':raw_json'     => $rawJson,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'DB-opslag mislukt: ' . $e->getMessage()];
    }

    return ['ok' => true, 'message' => 'Opgeslagen.', 'parcel_no' => $parcelNo];
}

/**
 * Haalt de opgeslagen GLS-status op voor een pakketnummer.
 *
 * @return array{live: bool, status: string, vervoerder: string, huidige_status: string, laatste_update: string, geschiedenis: array<int, array<string, mixed>>, message: string}
 */
function haalGlsStatusUitDb(PDO $conn, string $parcelNo): array
{
    try {
        $stmt = $conn->prepare(
            'SELECT state, beschrijving, datum_event, raw_json, bijgewerkt FROM gls_pakket_status WHERE parcel_no = :pno LIMIT 1'
        );
        $stmt->execute([':pno' => $parcelNo]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        return [
            'live'          => false,
            'status'        => 'fout',
            'vervoerder'    => 'gls',
            'huidige_status' => '',
            'laatste_update' => '',
            'geschiedenis'  => [],
            'message'       => 'GLS-status opzoeken is nu niet beschikbaar.',
        ];
    }

    if ($row === false || $row === null) {
        return [
            'live'          => false,
            'status'        => 'geen_data',
            'vervoerder'    => 'gls',
            'huidige_status' => '',
            'laatste_update' => '',
            'geschiedenis'  => [],
            'message'       => 'Geen GLS-status gevonden voor dit pakket.',
        ];
    }

    $rawDecoded = json_decode((string) ($row['raw_json'] ?? ''), true);
    $geschiedenis = [];

    if (is_array($rawDecoded)) {
        $events = $rawDecoded['Events'] ?? [];
        if (is_array($events)) {
            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $tekst = trim((string) ($event['DescriptionNL'] ?? $event['DescriptionEN'] ?? ''));
                $tijd  = trim((string) ($event['Date'] ?? ''));
                if ($tekst !== '') {
                    $geschiedenis[] = ['tijd' => $tijd, 'omschrijving' => $tekst];
                }
            }
        }
    }

    $huidig       = trim((string) ($row['beschrijving'] ?? ''));
    $huidigTijd   = trim((string) ($row['datum_event'] ?? ''));

    return [
        'live'           => $huidig !== '',
        'status'         => $huidig !== '' ? 'gevonden' : 'geen_data',
        'vervoerder'     => 'gls',
        'huidige_status' => $huidig,
        'laatste_update' => $huidigTijd,
        'geschiedenis'   => $geschiedenis,
        'message'        => $huidig !== '' ? '' : 'Geen GLS-status gevonden.',
    ];
}

function glsIso8601NaarMysql(string $iso): ?string
{
    $dt = DateTime::createFromFormat(DateTime::ATOM, $iso);
    if ($dt === false) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $iso);
    }
    if ($dt === false) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $iso);
    }

    return $dt !== false ? $dt->format('Y-m-d H:i:s') : null;
}
