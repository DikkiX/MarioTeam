<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/bestelling_lookup.php';

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

    public function testParseBestellingItemsTekstIgnoresMetaLinesAndDecodesHtml(): void
    {
        $items = "Korting: -5 euro<br>\n1x Mario &amp; Luigi<br>\nVerzendkosten: 4,95 euro<br>\nTotaal: 9,95 euro";
        $parsed = parseBestellingItemsTekst($items);

        $this->assertCount(1, $parsed);
        $this->assertSame('Mario & Luigi', $parsed[0]['productnaam']);
        $this->assertSame(1, $parsed[0]['aantal']);
    }

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

    public function testHaalTrackCodeUitTracktraceTakesLastSegment(): void
    {
        $trace = "PostNL|3SYZAB123456789";
        $this->assertSame('3SYZAB123456789', haalTrackCodeUitTracktrace($trace));
    }

    public function testHaalTrackCodeUitTracktraceSkipsEmptySegments(): void
    {
        $trace = "|||9|3SGDWQ838080473";
        $this->assertSame('3SGDWQ838080473', haalTrackCodeUitTracktrace($trace));
    }

    public function testHaalTrackCodeUitTracktraceReturnsEmptyOnEmpty(): void
    {
        $this->assertSame('', haalTrackCodeUitTracktrace(''));
    }

    public function testZoekBestellingRuwRequiresIdAndEmail(): void
    {
        $conn = new FakeConn(false);
        $result = zoekBestellingRuw($conn, 0, '');
        $this->assertSame('zoek_bestelling', $result['functie']);
        $this->assertFalse($result['gevonden']);
        $this->assertSame('Voor orderdata zijn zowel bestelling_id als email verplicht.', $result['message']);
    }

    public function testZoekBestellingRuwReturnsNotFoundIfNoRow(): void
    {
        $conn = new FakeConn(false);
        $result = zoekBestellingRuw($conn, 123, 'a@b.nl');
        $this->assertSame('zoek_bestelling', $result['functie']);
        $this->assertFalse($result['gevonden']);
        $this->assertSame('De combinatie van bestelling_id en email klopt niet.', $result['message']);
        $this->assertSame([':id' => 123, ':email' => 'a@b.nl'], $conn->lastExecuteParams);
    }

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

    public function testZoekBestellingRuwHandlesException(): void
    {
        $conn = new FakeConnThrows();
        $result = zoekBestellingRuw($conn, 123, 'a@b.nl');
        $this->assertSame('zoek_bestelling', $result['functie']);
        $this->assertFalse($result['gevonden']);
        $this->assertSame('Order lookup is nu niet beschikbaar.', $result['message']);
    }
}
