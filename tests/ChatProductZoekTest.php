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

    public function testLinkSlugWordtKorterVoorDatabaseMatch(): void
    {
        $varianten = maakLinkSlugVarianten('Minecraft_Story_Mode_-_The_Complete_Adventure');

        $this->assertContains('Minecraft_Story_Mode_-_The_Complete_Adventure', $varianten);
        $this->assertContains('Minecraft_Story_Mode', $varianten);
    }

    public function testMeerdereShopLinksZonderDuplicaten(): void
    {
        $tekst = 'Eerst https://www.marioswitch.nl/Super_Mario_Odyssey en nog eens https://www.marioswitch.nl/Super_Mario_Odyssey';
        $slugs = haalProductLinksUitTekst($tekst);

        $this->assertSame(['Super_Mario_Odyssey'], $slugs);
    }

    public function testGeenShopLinksInTekst(): void
    {
        $this->assertSame([], haalProductLinksUitTekst('Hallo, geen productlink hier.'));
    }

    public function testVoorraadUitGesprekZonderLinks(): void
    {
        $conn = new PDO('sqlite::memory:');
        $result = controleerVoorraadUitGesprek($conn, [
            ['role' => 'assistant', 'content' => 'Hallo, hier zijn avonturenspellen maar zonder shop-link.'],
        ]);

        $this->assertSame('geen_producten_in_gesprek', $result['status']);
        $this->assertSame([], $result['resultaat']);
    }
}
