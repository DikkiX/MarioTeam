<?php

use PHPUnit\Framework\TestCase;

if (!defined('EMAIL_DASHBOARD_LIB_ONLY')) {
    define('EMAIL_DASHBOARD_LIB_ONLY', true);
}

require_once __DIR__ . '/../EmailDashboard.php';
require_once __DIR__ . '/../include/bestelling_lookup.php';

// Fake DB laag voor unit tests:
// We testen hier de business-logica in bestelling_lookup.php zonder echte database.
final class FakeStmt
{
    private mixed $row;
    private FakeConn $conn;

    public function __construct(FakeConn $conn, mixed $row)
    {
        $this->conn = $conn;
        $this->row = $row;
    }

    public function execute(array $params = []): void
    {
        $this->conn->lastExecuteParams = $params;
    }

    public function fetch(): mixed
    {
        return $this->row;
    }
}

final class FakeConn
{
    public array $lastExecuteParams = [];
    private mixed $row;

    public function __construct(mixed $row)
    {
        $this->row = $row;
    }

    public function prepare(string $sql): FakeStmt
    {
        return new FakeStmt($this, $this->row);
    }
}

final class FakeConnThrows
{
    public function prepare(string $sql): void
    {
        throw new RuntimeException('boom');
    }
}

final class BestellingLookupTest extends TestCase
{
    // Items parsing: basic "Nx product" regels.
    public function testParseBestellingItemsTekstParsesLines(): void
    {
        $items = "1x Mario Kart 8 Deluxe\n2x Zelda BOTW";

        $parsed = parseBestellingItemsTekst($items);

        $this->assertIsArray($parsed);
        $this->assertCount(2, $parsed);
        $this->assertSame('Mario Kart 8 Deluxe', $parsed[0]['productnaam']);
        $this->assertSame(1, $parsed[0]['aantal']);
        $this->assertSame('Zelda BOTW', $parsed[1]['productnaam']);
        $this->assertSame(2, $parsed[1]['aantal']);
    }

    // Items parsing: meta-regels (korting/verzendkosten/totaal) worden genegeerd + HTML wordt gedecodeerd.
    public function testParseBestellingItemsTekstIgnoresMetaLinesAndDecodesHtml(): void
    {
        $items = "Korting: -5 euro<br>\n1x Mario &amp; Luigi<br>\nVerzendkosten: 4,95 euro<br>\nTotaal: 9,95 euro";
        $parsed = parseBestellingItemsTekst($items);

        $this->assertCount(1, $parsed);
        $this->assertSame('Mario & Luigi', $parsed[0]['productnaam']);
        $this->assertSame(1, $parsed[0]['aantal']);
    }

    // Items parsing: prijs "-> 10,50 euro" wordt herkend en dubbele regels worden samengevoegd.
    public function testParseBestellingItemsTekstParsesPrijsAndMergesDuplicates(): void
    {
        $items = "1x Game A -> 10,50 euro\n2x Game A -> 10.50 euro\n1x Game B";
        $parsed = parseBestellingItemsTekst($items);

        $this->assertCount(2, $parsed);
        $this->assertSame('Game A', $parsed[0]['productnaam']);
        $this->assertSame(3, $parsed[0]['aantal']);
        $this->assertEquals(10.50, $parsed[0]['prijs_euro'], 0.0001);
        $this->assertSame('Game B', $parsed[1]['productnaam']);
    }

    // Track&Trace parsing: simpele "provider|code" string.
    public function testHaalTrackCodeUitTracktraceTakesLastSegment(): void
    {
        $trace = "PostNL|3SYZAB123456789";
        $this->assertSame('3SYZAB123456789', haalTrackCodeUitTracktrace($trace));
    }

    // Track&Trace parsing: sommige waarden hebben lege segmenten/extra pipes (zoals in productie gezien).
    public function testHaalTrackCodeUitTracktraceSkipsEmptySegments(): void
    {
        $trace = "|||9|3SGDWQ838080473";
        $this->assertSame('3SGDWQ838080473', haalTrackCodeUitTracktrace($trace));
    }

    // Track&Trace parsing: leeg blijft leeg.
    public function testHaalTrackCodeUitTracktraceReturnsEmptyOnEmpty(): void
    {
        $this->assertSame('', haalTrackCodeUitTracktrace(''));
    }

    // Order lookup: input validatie (privacy) -> zonder id+email doen we geen query.
    public function testZoekBestellingRuwRequiresIdAndEmail(): void
    {
        $conn = new FakeConn(false);
        $result = zoekBestellingRuw($conn, 0, '');
        $this->assertSame('zoek_bestelling', $result['functie']);
        $this->assertFalse($result['gevonden']);
        $this->assertSame('Voor orderdata zijn zowel bestelling_id als email verplicht.', $result['message']);
    }

    // Order lookup: geen rij terug uit DB -> "niet gevonden" melding.
    public function testZoekBestellingRuwReturnsNotFoundIfNoRow(): void
    {
        $conn = new FakeConn(false);
        $result = zoekBestellingRuw($conn, 123, 'a@b.nl');
        $this->assertSame('zoek_bestelling', $result['functie']);
        $this->assertFalse($result['gevonden']);
        $this->assertSame('De combinatie van bestelling_id en email klopt niet.', $result['message']);
        $this->assertSame([':id' => 123, ':email' => 'a@b.nl'], $conn->lastExecuteParams);
    }

    // Order lookup: track code aanwezig -> status "verzonden" + track_code gevuld.
    public function testZoekBestellingRuwSetsVerzendStatusVerzondenWhenTrackCodePresent(): void
    {
        $row = [
            'id' => 123,
            'status' => '0',
            'verzending' => '',
            'tracktrace' => 'PostNL|3SYZAB123456789',
            'items' => "2x Game A\n",
            'inpakdatum' => 0,
        ];
        $conn = new FakeConn($row);
        $result = zoekBestellingRuw($conn, 123, 'a@b.nl');

        $this->assertTrue($result['gevonden']);
        $this->assertSame('verzonden', $result['resultaat']['verzend_status']);
        $this->assertSame('3SYZAB123456789', $result['resultaat']['track_code']);
        $this->assertTrue($result['artikelen_gevonden']);
        $this->assertSame('Bestellingen.items', $result['artikelen_bron']);
        $this->assertSame(2, $result['artikelen'][0]['aantal']);
    }

    // Order lookup: geen track code maar wel inpakdatum -> status "niet_verzonden".
    public function testZoekBestellingRuwSetsVerzendStatusNietVerzondenWhenInpakdatumSet(): void
    {
        $row = [
            'id' => 123,
            'status' => '0',
            'verzending' => '',
            'tracktrace' => '',
            'items' => "1x Game A\n",
            'inpakdatum' => 1712345678,
        ];
        $conn = new FakeConn($row);
        $result = zoekBestellingRuw($conn, 123, 'a@b.nl');

        $this->assertTrue($result['gevonden']);
        $this->assertSame('niet_verzonden', $result['resultaat']['verzend_status']);
        $this->assertSame('', $result['resultaat']['track_code']);
    }

    // Order lookup: DB error/exception -> nette fallback tekst (geen details lekken).
    public function testZoekBestellingRuwHandlesException(): void
    {
        $conn = new FakeConnThrows();
        $result = zoekBestellingRuw($conn, 123, 'a@b.nl');
        $this->assertSame('zoek_bestelling', $result['functie']);
        $this->assertFalse($result['gevonden']);
        $this->assertSame('Order lookup is nu niet beschikbaar.', $result['message']);
    }
}

final class EmailDashboardHelpersTest extends TestCase
{
    public function testFormatteerBestandsgrootte(): void
    {
        $this->assertSame('', formatteerBestandsgrootte(0));
        $this->assertSame('500 B', formatteerBestandsgrootte(500));
        $this->assertSame('1,0 KB', formatteerBestandsgrootte(1024));
        $this->assertSame('1,0 MB', formatteerBestandsgrootte(1024 * 1024));
    }

    public function testNormaliseerContentId(): void
    {
        $this->assertSame('', normaliseerContentId(''));
        $this->assertSame('abc', normaliseerContentId('<abc>'));
        $this->assertSame('abc', normaliseerContentId('  abc  '));
        $this->assertSame('a@b', normaliseerContentId("<a@b>\n"));
    }

    public function testBase64UrlDecode(): void
    {
        $raw = 'hello world!';
        $b64 = base64_encode($raw);
        $url = rtrim(strtr($b64, '+/', '-_'), '=');

        $this->assertSame($raw, base64UrlDecode($url));
        $this->assertSame('', base64UrlDecode('@@@'));
    }

    public function testHaalBijlagesUitPayloadFindsAttachmentsAndInlineParts(): void
    {
        $payload = [
            'mimeType' => 'multipart/mixed',
            'parts' => [
                [
                    'mimeType' => 'text/plain',
                    'filename' => '',
                    'body' => ['size' => 10],
                ],
                [
                    'mimeType' => 'image/jpeg',
                    'filename' => '',
                    'headers' => [
                        ['name' => 'Content-ID', 'value' => '<img1>'],
                        ['name' => 'Content-Disposition', 'value' => 'inline'],
                    ],
                    'body' => [
                        'size' => 123,
                        'data' => 'aGVsbG8',
                    ],
                ],
                [
                    'mimeType' => 'application/pdf',
                    'filename' => 'factuur.pdf',
                    'headers' => [
                        ['name' => 'Content-Disposition', 'value' => 'attachment'],
                    ],
                    'body' => [
                        'attachmentId' => 'att-123',
                        'size' => 999,
                    ],
                ],
            ],
        ];

        $bijlages = haalBijlagesUitPayload($payload);
        $this->assertCount(2, $bijlages);

        $this->assertSame('image/jpeg', $bijlages[0]['mimeType']);
        $this->assertSame('img1', $bijlages[0]['contentId']);
        $this->assertSame('1', $bijlages[0]['partPath']);
        $this->assertTrue($bijlages[0]['hasInlineData']);

        $this->assertSame('factuur.pdf', $bijlages[1]['filename']);
        $this->assertSame('att-123', $bijlages[1]['attachmentId']);
        $this->assertSame('2', $bijlages[1]['partPath']);
    }

    public function testVindBijlagePartOpPad(): void
    {
        $payload = [
            'mimeType' => 'multipart/mixed',
            'parts' => [
                ['mimeType' => 'text/plain'],
                [
                    'mimeType' => 'multipart/related',
                    'parts' => [
                        ['mimeType' => 'image/png', 'body' => ['data' => 'aGVsbG8']],
                    ],
                ],
            ],
        ];

        $part = vindBijlagePartOpPad($payload, '1.0');
        $this->assertIsArray($part);
        $this->assertSame('image/png', $part['mimeType']);
        $this->assertNull(vindBijlagePartOpPad($payload, ''));
        $this->assertNull(vindBijlagePartOpPad($payload, 'abc'));
        $this->assertNull(vindBijlagePartOpPad($payload, '9'));
    }

    public function testVervangCidSrcInHtml(): void
    {
        $html = '<p>Hi</p><img src="cid:img1">';
        $out = vervangCidSrcInHtml($html, ['img1' => '/EmailDashboard.php?attachment=1&message_id=x&attachment_id=y&inline=1']);
        $this->assertStringContainsString('src="/EmailDashboard.php?attachment=1&amp;message_id=x&amp;attachment_id=y&amp;inline=1"', $out);

        $out2 = vervangCidSrcInHtml('<img src="cid:unknown">', ['img1' => '/EmailDashboard.php?attachment=1']);
        $this->assertSame('<img src="">', $out2);
    }

    public function testSanitizeEmailHtmlVoorDashboardRemovesScriptsAndExternalImages(): void
    {
        $html = '<div onclick="alert(1)"><script>alert(1)</script><a href="javascript:alert(1)">x</a>'
            . '<a href="https://example.com">ok</a>'
            . '<img src="https://evil.com/x.png" alt="x">'
            . '<img src="/EmailDashboard.php?attachment=1&amp;message_id=1&amp;attachment_id=2&amp;inline=1" alt="test">'
            . '</div>';

        $out = sanitizeEmailHtmlVoorDashboard($html);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('onclick=', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('evil.com', $out);
        $this->assertStringContainsString('href="https://example.com"', $out);
        $this->assertStringContainsString('<img src="/EmailDashboard.php?attachment=1&amp;message_id=1&amp;attachment_id=2&amp;inline=1"', $out);
    }

    public function testExtracteerBestelEnEmailUitTekst(): void
    {
        $t = "Hoi, mijn bestelnummer is 12345 en mijn email is Test@Example.com";
        $r = extracteerBestelEnEmailUitTekst($t);
        $this->assertSame(12345, $r['bestelling_id']);
        $this->assertSame('test@example.com', $r['email']);

        $t2 = "Order: 999\ngeen email erbij";
        $r2 = extracteerBestelEnEmailUitTekst($t2);
        $this->assertSame(999, $r2['bestelling_id']);
        $this->assertSame('', $r2['email']);
    }
}
