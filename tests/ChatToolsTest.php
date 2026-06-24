<?php

// Tests voor include/chat_tools.php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/chat_tools.php';

final class ChatToolsTest extends TestCase
{
    public function testBouwChatToolsBevatDrieHoofdtools(): void
    {
        $tools = bouwChatTools();

        $this->assertCount(5, $tools);

        $namen = array_map(static function (array $tool): string {
            return (string) ($tool['function']['name'] ?? '');
        }, $tools);

        $this->assertSame(
            ['zoek_bestelling', 'zoek_productvoorraad', 'zoek_productaanraders', 'wijzig_bestelling_adres', 'zoek_traceer'],
            $namen
        );
    }

    public function testVoerChatToolUitOnbekendeFunctie(): void
    {
        $conn = new PDO('sqlite::memory:');
        $result = voerChatToolUit($conn, 'bestaat_niet', []);

        $this->assertFalse($result['gevonden']);
        $this->assertSame('bestaat_niet', $result['functie']);
    }
}
