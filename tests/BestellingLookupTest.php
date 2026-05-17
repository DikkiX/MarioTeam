<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/bestelling_lookup.php';

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

    public function testHaalTrackCodeUitTracktraceTakesLastSegment(): void
    {
        $trace = "PostNL|3SYZAB123456789";
        $this->assertSame('3SYZAB123456789', haalTrackCodeUitTracktrace($trace));
    }

    public function testHaalTrackCodeUitTracktraceReturnsEmptyOnEmpty(): void
    {
        $this->assertSame('', haalTrackCodeUitTracktrace(''));
    }
}
