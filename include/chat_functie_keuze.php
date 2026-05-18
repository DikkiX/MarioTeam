<?php

// Dit bestand bepaalt welke "interne functie" de chatbot MOET gebruiken.
// Doel: de bot niet laten gokken bij vragen over spellen/voorraad, maar altijd eerst de database checken.
//
// Wordt gebruikt door:
// - api/chat/worker.php (vóór de OpenAI-call)
//
// Teruggeeft:
// - 'auto' = OpenAI mag zelf kiezen
// - of een array met 1 vaste functienaam (zoek_productaanraders, zoek_productvoorraad, zoek_bestelling)

// Kijkt naar de tekst van de klant en beslist: welke functie moet verplicht worden?
function bepaalGeforceerdeFunctieKeuze(string $berichtTekst)
{
    $t = trim($berichtTekst);
    if ($t === '') {
        return 'auto';
    }

    // 1) Bestelling: als er bestelnummer + e-mail in de tekst staat → zoek_bestelling.
    $heeftBestelWoord = preg_match('/bestelling|bestelnummer|order|status|inhoud|artikelen|orderregels|wat heb ik besteld|wat zit er/i', $t) === 1;
    $heeftEmail = preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $t) === 1;
    $heeftBestelnummer = preg_match('/\b\d+\b/', $t) === 1;

    if ($heeftBestelWoord && $heeftEmail && $heeftBestelnummer) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_bestelling',
            ],
        ];
    }

    // 2) Vergelijkbare spellen / aanraders (bijv. "lijken op Xenoblade", "soortgelijke games").
    $vraagtOmAanraders = preg_match(
        '/\b(aanraden|aanrader|suggest|suggestie|alternatief|soortgelijk|gelijke|vergelijkbaar|vergelijkbare|andere\s+games|andere\s+spellen)\b/i',
        $t
    ) === 1;
    $vraagtOmVergelijking = preg_match(
        '/\b(lijken\s+op|zoals|net\s+als|in\s+de\s+trant\s+van|vergelijkbaar\s+met|soort\s+als|op\s+.+\s+lijkt)\b/i',
        $t
    ) === 1;

    if ($vraagtOmAanraders || $vraagtOmVergelijking) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_productaanraders',
            ],
        ];
    }

    // 3) Eén concrete titel (bijv. "hebben jullie geen Just Dance?").
    // Dit moet VÓÓR genre-detectie, anders pakt "dance" in Just Dance het verkeerde pad.
    $vraagtOmAssortiment = preg_match(
        '/\b(heb\s+je|hebben\s+jullie|verkopen\s+jullie|hebben\s+jullie\s+ook|is\s+er|staat\s+.+\s+op\s+voorraad)\b/i',
        $t
    ) === 1;
    $noemtBekendeTitel = preg_match(
        '/\b(just\s*dance|xenoblade|zelda|mario|pokemon|fifa|sonic|kirby|animal\s*crossing|splatoon|smash|kart|pikmin|metroid|fire\s*emblem|bayonetta|ring\s*fit)\b/i',
        $t
    ) === 1;

    if ($vraagtOmAssortiment && $noemtBekendeTitel && !$vraagtOmVergelijking) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_productvoorraad',
            ],
        ];
    }

    // 4) Genre-vragen (bijv. "danspellen?" — één woord, één s: dans+pellen, niet "dansspellen").
    // Let op: \bdans\b matcht NIET in "danspellen", daarom ook losse woorden als danspellen|racespellen.
    $heeftGenreWoord = preg_match(
        '/(?:race|racing|autos?|kart|mario\s*kart|dans|dance|just\s*dance|danspellen|racespellen|partyspellen|rpg|jrpg|avontuur|actie|shooter|schiet|puzzel|party|multiplayer|co-?op|sport|voetbal|basketbal|horror|strategy|strategie|xenoblade|zelda|mario|pokemon|sonic|kirby|fifa)/i',
        $t
    ) === 1;
    $vraagtOmSpellen = preg_match('/\b(spel|spellen|game|games)\b|\w+spellen\b|\w+games\b/i', $t) === 1;
    $isVraag = preg_match('/\?|\b(heb\s+je|hebben\s+jullie|zijn\s+er|verkoop|verkopen\s+jullie|aanraden)\b/i', $t) === 1;

    if ($heeftGenreWoord && $isVraag && ($vraagtOmSpellen || preg_match('/spellen|games/i', $t) === 1)) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_productaanraders',
            ],
        ];
    }

    // Alleen een genrewoord + vraagteken (bijv. "danspellen?") zonder apart woord "spellen".
    if ($heeftGenreWoord && $isVraag) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_productaanraders',
            ],
        ];
    }

    // Geen duidelijke product/order-vraag → OpenAI mag zelf kiezen.
    return 'auto';
}
