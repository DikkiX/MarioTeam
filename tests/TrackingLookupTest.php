<?php

// Tests voor include/tracking_lookup.php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/tracking_lookup.php';

final class TrackingLookupTest extends TestCase
{
    public function testKiesBesteTraceerApiPrefersPostNlMetMeerData(): void
    {
        $postnl = [
            'live' => true,
            'huidige_status' => 'Het pakket is bezorgd',
            'geschiedenis' => [
                ['omschrijving' => 'Onderweg'],
                ['omschrijving' => 'Bezorgd'],
            ],
        ];
        $gls = [
            'live' => true,
            'huidige_status' => 'Bezorgd',
            'geschiedenis' => [],
        ];

        $best = kiesBesteTraceerApiResultaat($postnl, $gls);

        $this->assertSame('postnl', $best['vervoerder']);
        $this->assertSame('Het pakket is bezorgd', $best['huidige_status']);
    }

    public function testKiesBesteTraceerApiKiestGlsAlsAlleenGlsLive(): void
    {
        $postnl = ['live' => false, 'status' => 'geen_data', 'message' => 'Geen data'];
        $gls = [
            'live' => true,
            'huidige_status' => 'Onderweg',
            'geschiedenis' => [['omschrijving' => 'Scan']],
        ];

        $best = kiesBesteTraceerApiResultaat($postnl, $gls);

        $this->assertSame('gls', $best['vervoerder']);
    }

    public function testKiesBesteTraceerApiGeenLiveData(): void
    {
        $best = kiesBesteTraceerApiResultaat(
            ['live' => false, 'message' => 'PostNL niks'],
            ['live' => false, 'message' => 'GLS niks']
        );

        $this->assertFalse($best['live']);
        $this->assertSame('geen_live_data', $best['status']);
    }

    public function testVerwijderTraceerGevoeligeVelden(): void
    {
        $schoon = verwijderTraceerGevoeligeVelden([
            'track_code' => '3SYZAB123',
            'traceernummer' => 'SECRET',
            'huidige_status' => 'Onderweg',
            'nested' => [
                'barcode' => 'X',
                'omschrijving' => 'Bezorgd',
            ],
        ]);

        $this->assertArrayNotHasKey('track_code', $schoon);
        $this->assertArrayNotHasKey('traceernummer', $schoon);
        $this->assertSame('Onderweg', $schoon['huidige_status']);
        $this->assertArrayNotHasKey('barcode', $schoon['nested']);
        $this->assertSame('Bezorgd', $schoon['nested']['omschrijving']);
    }

    public function testZoekTraceerRuwVereistInput(): void
    {
        $conn = new PDO('sqlite::memory:');
        $result = zoekTraceerRuw($conn, []);

        $this->assertFalse($result['gevonden']);
        $this->assertArrayNotHasKey('track_code', $result);
        $this->assertArrayNotHasKey('traceernummer', $result);
    }

    public function testNormaliseerPostNlTraceerResponse(): void
    {
        $json = [
            'CurrentStatus' => [
                'Status' => [
                    'StatusDescription' => 'Het pakket is bezorgd',
                    'TimeStamp' => '2026-06-10T14:00:00',
                ],
            ],
        ];

        $result = normaliseerPostNlTraceerResponse($json);

        $this->assertTrue($result['live']);
        $this->assertSame('Het pakket is bezorgd', $result['huidige_status']);
        $this->assertSame('postnl', $result['vervoerder']);
    }

    public function testHaalVervoerderLabelUitVerzending(): void
    {
        $this->assertSame('postnl', haalVervoerderLabelUitVerzending('Verzonden via PostNL briefpost'));
        $this->assertSame('gls', haalVervoerderLabelUitVerzending('GLS pakket'));
        $this->assertNull(haalVervoerderLabelUitVerzending(''));
    }
}
