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

// Haalt product-slugs uit shop-URL’s in een tekst (bijv. …/Minecraft_Story_Mode).
function haalProductLinksUitTekst(string $tekst): array
{
    $slugs = [];
    if (preg_match_all('#(?:https?://)?(?:www\.)?marioswitch(?:1)?\.nl/([A-Za-z0-9_\-]+)#iu', $tekst, $matches) < 1) {
        return [];
    }

    foreach ($matches[1] as $slug) {
        $slug = trim((string) $slug);
        if ($slug === '' || isset($slugs[$slug])) {
            continue;
        }
        $slugs[$slug] = $slug;
    }

    return array_values($slugs);
}

// Maakt varianten van een zoekterm (bijv. dubbele punt weg) zodat LIKE beter matcht.
function maakProductZoektermVarianten(string $zoekterm): array
{
    $zoekterm = trim($zoekterm);
    if ($zoekterm === '') {
        return [];
    }

    $varianten = [$zoekterm];
    $zonderInterpunctie = preg_replace('/[:\-–—]+/u', ' ', $zoekterm);
    $zonderInterpunctie = preg_replace('/\s+/u', ' ', trim((string) $zonderInterpunctie));
    if ($zonderInterpunctie !== '' && $zonderInterpunctie !== $zoekterm) {
        $varianten[] = $zonderInterpunctie;
    }

    $uniek = [];
    foreach ($varianten as $v) {
        $sleutel = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
        $uniek[$sleutel] = $v;
    }

    return array_values($uniek);
}

// Zoekt 1 product op titel of link (meerdere term-varianten).
function zoekProductOpZoekterm(PDO $conn, string $zoekterm): ?array
{
    $varianten = maakProductZoektermVarianten($zoekterm);
    if (empty($varianten)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            w.nr,
            w.titel,
            w.link,
            w.prijs,
            w.sentence,
            CASE WHEN w.aantal > 0 THEN 'ja' ELSE 'nee' END AS op_voorraad
        FROM Winkel w
        WHERE w.titel LIKE :zoekterm_titel OR w.link LIKE :zoekterm_link
        ORDER BY w.aantal DESC, w.prijs ASC
        LIMIT 1
    ");

    foreach ($varianten as $term) {
        $like = '%' . $term . '%';
        $stmt->execute([
            ':zoekterm_titel' => $like,
            ':zoekterm_link' => $like,
        ]);
        $rij = $stmt->fetch();
        if (is_array($rij) && !empty($rij['titel'])) {
            return $rij;
        }
    }

    return null;
}

// Maakt kortere slug-varianten (shop-URL is soms langer dan w.link in de database).
function maakLinkSlugVarianten(string $slug): array
{
    $slug = trim($slug);
    if ($slug === '') {
        return [];
    }

    $varianten = [$slug];

    // Bijv. Minecraft_Story_Mode_-_The_Complete_Adventure → ook Minecraft_Story_Mode proberen.
    if (preg_match('/^(.+?)_-_.+$/u', $slug, $match) === 1) {
        $korter = trim((string) $match[1]);
        if ($korter !== '') {
            $varianten[] = $korter;
        }
    }

    $delen = explode('_', $slug);
    while (count($delen) > 3) {
        array_pop($delen);
        $varianten[] = implode('_', $delen);
    }

    $uniek = [];
    foreach ($varianten as $v) {
        $v = trim($v);
        if ($v === '') {
            continue;
        }
        $uniek[$v] = $v;
    }

    return array_values($uniek);
}

// Zoekt 1 product op de slug uit de shop-link (betrouwbaarst na een aanbeveling).
function zoekProductOpLinkSlug(PDO $conn, string $slug): ?array
{
    $stmt = $conn->prepare("
        SELECT
            w.nr,
            w.titel,
            w.link,
            w.prijs,
            w.sentence,
            CASE WHEN w.aantal > 0 THEN 'ja' ELSE 'nee' END AS op_voorraad
        FROM Winkel w
        WHERE w.link LIKE :zoekterm_link
        ORDER BY w.aantal DESC, w.prijs ASC
        LIMIT 1
    ");

    foreach (maakLinkSlugVarianten($slug) as $variant) {
        $stmt->execute([':zoekterm_link' => '%' . $variant . '%']);
        $rij = $stmt->fetch();
        if (is_array($rij) && !empty($rij['titel'])) {
            return $rij;
        }
    }

    return null;
}

// Leest de laatste bot-antwoorden en controleert voorraad per genoemde shop-link.
function controleerVoorraadUitGesprek(PDO $conn, array $contextMessages, string $basisUrl = ''): array
{
    $teksten = [];
    foreach ($contextMessages as $bericht) {
        if (!is_array($bericht) || ($bericht['role'] ?? '') !== 'assistant') {
            continue;
        }
        $inhoud = trim((string) ($bericht['content'] ?? ''));
        if ($inhoud !== '') {
            $teksten[] = $inhoud;
        }
    }

    // Nieuwste bot-berichten eerst (meest recente aanbeveling).
    $teksten = array_reverse($teksten);
    $gezien = [];
    $producten = [];

    foreach ($teksten as $tekst) {
        $slugs = haalProductLinksUitTekst($tekst);
        foreach ($slugs as $slug) {
            if (isset($gezien[$slug])) {
                continue;
            }
            $gezien[$slug] = true;
            $rij = zoekProductOpLinkSlug($conn, $slug);
            if ($rij === null) {
                continue;
            }
            if ($basisUrl !== '') {
                $metUrl = voegProductUrlsToeAanRijen([$rij], $basisUrl);
                $rij = $metUrl[0] ?? $rij;
            }
            $producten[] = $rij;
        }
    }

    return [
        'functie' => 'controleer_voorraad_lijst',
        'gevonden' => !empty($producten),
        'status' => empty($producten) ? 'geen_producten_in_gesprek' : 'gecontroleerd',
        'message' => empty($producten)
            ? 'Geen shop-links gevonden in eerdere antwoorden. Vraag de klant welke titels bedoeld worden.'
            : 'Voorraad gecontroleerd op basis van producten die eerder in dit gesprek zijn genoemd.',
        'resultaat' => $producten,
    ];
}
