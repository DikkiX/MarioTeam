<?php

// Dit bestand kiest welke “functie” de chatbot verplicht moet gebruiken.
// Het wordt gebruikt door:
// - api/chat/worker.php (vlak vóór de OpenAI-call)
//
// Doel: de bot niet laten gokken over voorraad of spellen.
// Maar bij ProductFinder eerst wél de oude stappen (5 vragen), en pas daarna de database.

// Kijkt naar het bericht van de klant + het label uit system0.
// Geeft terug: 'auto' (AI mag zelf kiezen) of 1 vaste functienaam.
function bepaalGeforceerdeFunctieKeuze(string $berichtTekst, string $assistant0 = '')
{
    $t = trim($berichtTekst);
    if ($t === '') {
        return 'auto';
    }

    // system0 kan “ProductFinder” teruggeven = klant zoekt nog een game (orienterend).
    $isProductFinder = preg_match('/ProductFinder/i', $assistant0) === 1;
    // Na de 5 vragen zegt de klant dit. Dan mag er gezocht worden in Winkel.
    $klaarMetVragen = preg_match('/ik heb antwoord op al mijn vragen/i', $t) === 1;

    if ($klaarMetVragen) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_productaanraders',
            ],
        ];
    }

    // Tijdens ProductFinder: geen database forceren. Eerst Mr M + verkoopvragen (VerkoopAdvies3).
    if ($isProductFinder) {
        return 'auto';
    }

    // Bestelling: bestelnummer + e-mail in het bericht → zoek_bestelling.
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

    // Vergelijkbare spellen (bijv. “lijken op Xenoblade”).
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

    // Eén bekende titel (bijv. “hebben jullie Just Dance?”) → zoek_productvoorraad.
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

    // Genre-vragen (bijv. “danspellen?” — schrijf: danspellen, niet dansspellen).
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

    if ($heeftGenreWoord && $isVraag) {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'zoek_productaanraders',
            ],
        ];
    }

    return 'auto';
}

// Vervolgvraag: klant vraagt voorraad van spellen die net in het gesprek stonden.
function isVoorraadFollowUpVraag(string $berichtTekst): bool
{
    $t = trim($berichtTekst);
    if ($t === '') {
        return false;
    }

    $heeftVoorraadWoord = preg_match(
        '/\b(op\s+voorraad|voorraad|beschikbaar|in\s+stock|nog\s+te\s+koop|hebben\s+jullie\s+(die|ze|hem|haar|het))\b/i',
        $t
    ) === 1;

    $verwijstNaarEerder = preg_match(
        '/\b(ze|die|deze|allemaal|alle\s+drie|beide|genoemde|die\s+games|die\s+spellen|dat\s+spel|die\s+titels)\b/i',
        $t
    ) === 1;

    return $heeftVoorraadWoord && $verwijstNaarEerder;
}
