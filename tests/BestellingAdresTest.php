<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/bestelling_adres.php';

final class BestellingAdresFakeStmt
{
    private BestellingAdresFakeConn $conn;
    public string $lastSql = '';

    public function __construct(BestellingAdresFakeConn $conn, string $sql = '')
    {
        $this->conn = $conn;
        $this->lastSql = $sql;
    }

    public function execute(array $params = []): void
    {
        $this->conn->lastExecuteParams = $params;
        $this->conn->executeCount++;
        if (stripos($this->lastSql, 'UPDATE') !== false && is_array($this->conn->row)) {
            $map = bestellingAdresWijzigbareVelden();
            foreach ($params as $key => $waarde) {
                if (!is_string($key) || !str_starts_with($key, ':veld_')) {
                    continue;
                }
                $logisch = substr($key, 6);
                if ($logisch !== '' && isset($map[$logisch])) {
                    $this->conn->row[$map[$logisch]] = $waarde;
                }
            }
        }
    }

    public function fetch(): mixed
    {
        return $this->conn->row;
    }
}

final class BestellingAdresFakeConn
{
    public array $lastExecuteParams = [];
    public int $executeCount = 0;
    public mixed $row;

    public function __construct(mixed $row)
    {
        $this->row = $row;
    }

    public function prepare(string $sql): BestellingAdresFakeStmt
    {
        return new BestellingAdresFakeStmt($this, $sql);
    }
}

final class BestellingAdresFakeConnThrows
{
    public function prepare(string $sql): void
    {
        throw new RuntimeException('boom');
    }
}

final class BestellingAdresTest extends TestCase
{
    public function testOphalenVereistBestelnummer(): void
    {
        $conn = new BestellingAdresFakeConn(false);
        $result = bestellingAdresRuw($conn, ['bestelling_id' => 0]);
        $this->assertFalse($result['gevonden']);
        $this->assertSame('wijzig_bestelling_adres', $result['functie']);
    }

    public function testOphalenGeeftHuidigAdres(): void
    {
        $row = [
            'id' => 19235,
            'naam' => 'Rilana Nijholt-Tagage',
            'adres' => 'Katie Jansstraat 12, 5913RH Venlo, Nederland',
            'telefoon' => '0618433159',
            'mail' => 'rilanatagage@hotmail.com',
            'status' => '1',
            'verzending' => '',
            'tracktrace' => '',
            'inpakdatum' => 0,
        ];
        $conn = new BestellingAdresFakeConn($row);

        $result = bestellingAdresRuw($conn, ['bestelling_id' => 19235]);

        $this->assertTrue($result['gevonden']);
        $this->assertSame('ophalen', $result['actie']);
        $this->assertSame('Rilana Nijholt-Tagage', $result['adres']['naam']);
        $this->assertStringContainsString('Venlo', $result['adres']['adres']);
        $this->assertTrue($result['mag_wijzigen']);
    }

    public function testOpslaanWerktVoorNietVerzondenBestelling(): void
    {
        $row = [
            'id' => 19235,
            'naam' => 'Rilana Nijholt-Tagage',
            'adres' => 'Katie Jansstraat 12, 5913RH Venlo, Nederland',
            'telefoon' => '0618433159',
            'mail' => 'rilanatagage@hotmail.com',
            'status' => '1',
            'verzending' => '',
            'tracktrace' => '',
            'inpakdatum' => 0,
        ];
        $conn = new BestellingAdresFakeConn($row);

        $result = bestellingAdresRuw($conn, [
            'bestelling_id' => 19235,
            'adres' => 'Nieuwe Straat 9, 1382 JS Weesp, Nederland',
        ]);

        $this->assertTrue($result['gevonden']);
        $this->assertSame('opslaan', $result['actie']);
        $this->assertTrue($result['opgeslagen']);
        $this->assertSame('Nieuwe Straat 9, 1382 JS Weesp, Nederland', $result['adres']['adres']);
    }

    public function testOpslaanGeblokkeerdBijTracktrace(): void
    {
        $row = [
            'id' => 19235,
            'naam' => 'Rilana',
            'adres' => 'Oud adres',
            'telefoon' => '0612345678',
            'mail' => 'a@b.nl',
            'status' => '1',
            'verzending' => '',
            'tracktrace' => 'NL82629327',
            'inpakdatum' => 0,
        ];
        $conn = new BestellingAdresFakeConn($row);

        $result = bestellingAdresRuw($conn, [
            'bestelling_id' => 19235,
            'adres' => 'Nieuw adres',
        ]);

        $this->assertTrue($result['gevonden']);
        $this->assertFalse($result['opgeslagen']);
        $this->assertFalse($result['mag_wijzigen']);
    }

    public function testNietGevondenBijDatabaseFout(): void
    {
        $conn = new BestellingAdresFakeConnThrows();
        $result = bestellingAdresRuw($conn, ['bestelling_id' => 123]);
        $this->assertFalse($result['gevonden']);
        $this->assertSame('Geen bestelling gevonden met dit bestelnummer.', $result['message']);
    }
}
