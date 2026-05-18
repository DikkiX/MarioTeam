# Uitleg: tests + checks in de deployment

Dit document legt simpel uit wat er in GitHub Actions gebeurt voordat er bestanden geüpload worden.

## Wat er gebeurt in de workflow (in volgorde)

Workflow bestand: [.github/workflows/deploy.yml](.github/workflows/deploy.yml)

1) PHP lint (syntax check)
- Dit draait `php -l` over alle `.php` bestanden.
- Doel: voorkomen dat er een echte “syntax error” live gaat (bijv. een missende `)` of een kapotte `if`).
- Als dit faalt: de workflow stopt meteen en er wordt niets geüpload.

2) Composer install
- Dit downloadt PHPUnit (test tool) naar `vendor/`.
- Zonder deze stap kan PHPUnit niet draaien.

3) Unit tests + coverage (PHPUnit)
- Dit draait alle tests in de map `tests/`.
- Doel: checken of stukjes logica doen wat we verwachten.
- Als 1 test faalt: workflow stopt en je ziet precies welke test faalt.
- Coverage: extra output die laat zien hoeveel regels code tijdens tests “geraakt” zijn.

4) Upload via SFTP
- Alleen als de checks hierboven groen zijn, worden de bestanden geüpload.

5) Smoke check (na upload)
- Dit stuurt een paar simpele requests naar de site om te checken of de site “leeft”.
- Voorbeeld: `status.php` zonder params hoort `422` te geven. Dat is bewust: het laat zien dat het endpoint draait en input valideert.

## Wat unit tests zijn (simpel)

- Een unit test is een klein testje voor 1 functie of 1 stukje logica.
- Je doet: input erin → output eruit → controleren met asserts.
- Je test niet de hele website, maar alleen kleine onderdelen.

Voorbeeld (simpel idee):
- Input: `"2x Game A\n1x Game B"`
- Verwachte output: een lijst met 2 items en de juiste aantallen.

## Welke unit tests we nu hebben

Test bestand: [tests/BestellingLookupTest.php](tests/BestellingLookupTest.php)

1) Bestelling helpers (`include/bestelling_lookup.php`)
- Items tekst omzetten naar artikelen (`parseBestellingItemsTekst`)
- Track & trace code uit tekst halen (`haalTrackCodeUitTracktrace`)
- Order lookup logica testen met een fake DB (`zoekBestellingRuw` + FakeConn)

2) Chat functie-keuze (`include/chat_functie_keuze.php`)
- `bepaalGeforceerdeFunctieKeuze`: beslist welke interne functie verplicht is (aanraders, voorraad, bestelling).
- Tests in [ChatFunctieKeuzeTest.php](tests/ChatFunctieKeuzeTest.php) (o.a. “lijken op Xenoblade”, “danspellen?”, “geen Just Dance?”).

3) EmailDashboard helpers (`include/email_dashboard_helpers.php`)
- Helpers die je kunt testen zonder Gmail of login, zoals:
  - `formatteerBestandsgrootte`: zet bytes om naar tekst zoals `500 B`, `1,0 KB`, `1,5 MB`.
  - `normaliseerContentId`: haalt `<` en `>` weg uit een Content-ID, zodat `cid:` links matchen.
  - `base64UrlDecode`: decodeert Gmail base64url (Gmail gebruikt `-` en `_` i.p.v. `+` en `/`).
  - `haalBijlagesUitPayload`: loopt door Gmail “parts” heen en geeft een platte lijst met bijlages/inline parts terug.
  - `emailDashboardAttachmentUrl`: bouwt een dashboard-URL om een bijlage op te halen via `?attachment=1&attachment_id=...`.
  - `emailDashboardInlinePartUrl`: bouwt een dashboard-URL om een inline part op te halen via `?attachment=1&part_path=...`.
  - `vervangCidSrcInHtml`: vervangt `<img src="cid:...">` door een dashboard-URL, zodat plaatjes in het dashboard werken.
  - `zoekTekstPlainInPayload`: zoekt `text/plain` in een Gmail payload en geeft de gedecodeerde tekst terug.
  - `zoekTekstHtmlInPayload`: zoekt `text/html` en zet HTML om naar normale tekst (voor “plain text” weergave).
  - `haalHtmlUitPayload`: haalt de ruwe HTML uit `text/html` (dus zonder omzetten naar tekst).
  - `sanitizeEmailHtmlVoorDashboard`: maakt mail-HTML veilig (geen scripts, geen externe plaatjes, rare attributes weg).
  - `extracteerBestelEnEmailUitTekst`: zoekt een bestelnummer + e-mailadres uit vrije tekst (voor de order lookup).
  - `haalHeaderOp`: zoekt één headerwaarde op uit de Gmail headers-lijst (case-insensitive).
  - `parseerEmailAdresUitFromHeader`: haalt 1 e-mailadres uit een `From:` regel.
  - `parseerEmailAdressenUitHeaderTekst`: haalt meerdere e-mailadressen uit `To/Cc/...` headertekst.
  - `bouwRfc2822Bericht`: bouwt een mailbericht (headers + body) en encodeert dat naar base64url voor Gmail send.
  - `verwerkEmailRulesVoorMail`: past rules toe (bijv. “ignore” of extra instructies) op basis van afzender/onderwerp.

Welke tests er exact draaien is makkelijk te zien met:
- `vendor/bin/phpunit --list-tests`

## Wat coverage betekent

- Coverage is een percentage: hoeveel regels code zijn uitgevoerd tijdens het draaien van de tests.
- Het is een handig signaal, maar het betekent niet “alles is perfect”.
- Laag % betekent meestal:
  - te weinig tests, of
  - te weinig verschillende gevallen getest (happy flow wel, randgevallen niet).

Welke bestanden meetellen voor coverage staat in: [phpunit.xml](phpunit.xml)

Op dit moment tellen deze bestanden mee:
- `include/bestelling_lookup.php`
- `include/email_dashboard_helpers.php`

## Waarom we niet alles met echte DB/Gmail testen in unit tests

- In CI (GitHub Actions) hebben we geen veilige toegang tot een productie database of Gmail tokens.
- Unit tests moeten snel en stabiel zijn, zonder echte externe dependencies.
- Daarom testen we DB-gedrag vaak met fake data (FakeConn) en testen we Gmail payloads met voorbeeld-arrays.

## Hoe je fouten leest in GitHub Actions

- Fout bij lint: je ziet de bestandsnaam + regel + “Parse error …”.
- Fout bij unit tests: je ziet welke test faalt en wat het verschil was tussen “expected” en “actual”.
- Fout bij smoke check: je ziet welke URL een andere HTTP code teruggeeft dan verwacht.
