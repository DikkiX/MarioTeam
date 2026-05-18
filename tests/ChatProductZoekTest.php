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
}
