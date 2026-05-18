<?php

// Dit bestand zoekt producten in de tabel Winkel (voor de chatbot).
// Het wordt gebruikt door:
// - api/chat/worker.php → functie zoek_productaanraders
//
// Doel: de AI mag begrijpen wat de klant bedoelt, maar mag alleen spellen noemen die echt in de shop staan.

// Haalt zoekwoorden uit de tool-call (veld zoektermen en/of zoekterm).
function haalZoektermenUitToolArgs(array $arguments): array
{
    $termen = [];

    if (isset($arguments['zoektermen']) && is_array($arguments['zoektermen'])) {
        foreach ($arguments['zoektermen'] as $t) {
            $t = trim((string) $t);
            if ($t !== '') {
                $termen[] = $t;
            }
        }
    }

    $enkel = isset($arguments['zoekterm']) ? trim((string) $arguments['zoekterm']) : '';
    if ($enkel !== '') {
        $termen[] = $enkel;
    }

    return $termen;
}

// Maakt de zoeklijst iets breder (zonder per game een lijstje bij te houden).
// Voorbeeld: danspellen → ook dans en dance (titels staan vaak in het Engels).
function breidZoektermenUitVoorDatabase(array $termen): array
{
    $uit = [];

    // Alleen algemene woorden, geen productnamen.
    $taalBrug = [
        'dans' => ['dance', 'dancer', 'dancing', 'party'],
        'race' => ['racing', 'kart', 'mario kart'],
        'racen' => ['racing', 'kart'],
        'voetbal' => ['fifa', 'football'],
        'sport' => ['fifa', 'sports'],
        'schiet' => ['shooter', 'shoot'],
        'horror' => ['horror'],
        'puzzel' => ['puzzle'],
        'avontuur' => ['adventure'],
        'actie' => ['action'],
    ];

    foreach ($termen as $term) {
        $term = trim((string) $term);
        if ($term === '') {
            continue;
        }

        $uit[] = $term;
        $klein = function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term);

        // danspellen eindigt op “spellen”; soms wordt de stam “dan”, maar bedoeld is “dans”.
        if (preg_match('/^(.+)(spellen|games)$/iu', $klein, $match) === 1) {
            $stam = trim((string) $match[1]);
            if ($stam !== '') {
                $uit[] = $stam;
                $stamMetS = $stam . 's';
                if (str_starts_with($klein, $stamMetS)) {
                    $uit[] = $stamMetS;
                    $klein = $stamMetS;
                } else {
                    $klein = $stam;
                }
            }
        }

        if (isset($taalBrug[$klein])) {
            foreach ($taalBrug[$klein] as $extra) {
                $uit[] = $extra;
            }
        }
    }

    $uniek = [];
    foreach ($uit as $t) {
        $t = trim((string) $t);
        if ($t === '') {
            continue;
        }
        $sleutel = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        $uniek[$sleutel] = $t;
    }

    return array_values($uniek);
}

// Zoekt producten die nu op voorraad zijn (aantal > 0 in Winkel).
function zoekAanradersInDatabase(PDO $conn, array $zoektermen, int $maxPerTerm = 5, int $maxTotaal = 10): array
{
    if ($maxPerTerm < 1) {
        $maxPerTerm = 1;
    }
    if ($maxTotaal < 1) {
        $maxTotaal = 1;
    }
    if ($maxTotaal > 10) {
        $maxTotaal = 10;
    }

    $gebruikteTermen = breidZoektermenUitVoorDatabase($zoektermen);
    if (empty($gebruikteTermen)) {
        return [
            'rijen' => [],
            'gebruikte_zoektermen' => [],
        ];
    }

    $perLink = [];
    $stmt = $conn->prepare("
        SELECT w.nr, w.titel, w.link, w.prijs, w.sentence
        FROM Winkel w
        WHERE w.aantal > 0
          AND (w.titel LIKE :t_titel OR w.link LIKE :t_link OR w.sentence LIKE :t_sentence)
        ORDER BY w.aantal DESC, w.prijs ASC
        LIMIT " . (int) $maxPerTerm . "
    ");

    foreach ($gebruikteTermen as $term) {
        $like = '%' . $term . '%';
        $stmt->bindValue(':t_titel', $like, PDO::PARAM_STR);
        $stmt->bindValue(':t_link', $like, PDO::PARAM_STR);
        $stmt->bindValue(':t_sentence', $like, PDO::PARAM_STR);
        $stmt->execute();
        $batch = $stmt->fetchAll();
        if (!is_array($batch)) {
            continue;
        }

        foreach ($batch as $row) {
            $link = isset($row['link']) ? trim((string) $row['link']) : '';
            $sleutel = $link !== '' ? $link : (string) ($row['nr'] ?? '');
            if ($sleutel === '' || isset($perLink[$sleutel])) {
                continue;
            }
            $perLink[$sleutel] = $row;
            if (count($perLink) >= $maxTotaal) {
                break 2;
            }
        }
    }

    return [
        'rijen' => array_values($perLink),
        'gebruikte_zoektermen' => $gebruikteTermen,
    ];
}

// Geen zoekwoord meegegeven: pak een paar populaire producten die op voorraad zijn.
function haalAlgemeneAanradersUitDatabase(PDO $conn, int $max): array
{
    if ($max < 1) {
        $max = 1;
    }
    if ($max > 10) {
        $max = 10;
    }

    $stmt = $conn->prepare("
        SELECT w.nr, w.titel, w.link, w.prijs, w.sentence
        FROM Winkel w
        WHERE w.aantal > 0
        ORDER BY w.aantal DESC, w.prijs ASC
        LIMIT " . (int) $max . "
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

// Zet shop-links om naar volledige https-URL’s voor in het antwoord.
function voegProductUrlsToeAanRijen(array $rows, string $basisUrl): array
{
    if ($basisUrl === '' || empty($rows)) {
        return $rows;
    }

    foreach ($rows as $idx => $row) {
        $link = isset($row['link']) ? trim((string) $row['link']) : '';
        if ($link === '') {
            continue;
        }

        if (preg_match('/^https?:\/\//i', $link) === 1) {
            $rows[$idx]['product_url'] = $link;
            continue;
        }

        if (preg_match('/^www\./i', $link) === 1) {
            $rows[$idx]['product_url'] = 'https://' . $link;
            continue;
        }

        $rows[$idx]['product_url'] = $basisUrl . '/' . ltrim($link, '/');
    }

    return $rows;
}
