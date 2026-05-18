<?php

// Tests voor include/chat_product_zoek.php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/chat_product_zoek.php';

final class ChatProductZoekTest extends TestCase
{
    public function testDanspellenWordtUitgebreidMetDance(): void
    {
        $uit = breidZoektermenUitVoorDatabase(['danspellen']);
        $klein = array_map('strtolower', $uit);

        $this->assertContains('danspellen', $klein);
        $this->assertContains('dans', $klein);
        $this->assertContains('dance', $klein);
        $this->assertContains('dancer', $klein);
    }

    public function testHaaltZoektermenUitToolArgs(): void
    {
        $termen = haalZoektermenUitToolArgs([
            'zoekterm' => 'rpg',
            'zoektermen' => ['jrpg', 'xenoblade'],
        ]);

        $this->assertSame(['jrpg', 'xenoblade', 'rpg'], $termen);
    }

    public function testHaaltShopLinksUitBotTekst(): void
    {
        $tekst = 'Kijk https://www.marioswitch.nl/Minecraft_Story_Mode_-_The_Complete_Adventure en Mario.';
        $slugs = haalProductLinksUitTekst($tekst);

        $this->assertContains('Minecraft_Story_Mode_-_The_Complete_Adventure', $slugs);
    }

    public function testZoektermVariantVerwijdertDubbelePunt(): void
    {
        $varianten = maakProductZoektermVarianten('Minecraft: Story Mode');

        $this->assertContains('Minecraft: Story Mode', $varianten);
        $this->assertContains('Minecraft Story Mode', $varianten);
    }
}
