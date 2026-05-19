<?php

// Tests voor include/chat_geschiedenis.php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/chat_geschiedenis.php';

final class ChatGeschiedenisTest extends TestCase
{
    public function testUserEnBotWordenZelfdeJsonFormaatAlsOudeChat(): void
    {
        $json = [];
        $json = voegToeAanConversationJsonArray($json, 'user', 'danspellen?');
        $json = voegToeAanConversationJsonArray($json, 'bot', 'We hebben Just Dance 2018!');

        $this->assertSame([
            ['user' => 'danspellen?'],
            ['assistant' => 'We hebben Just Dance 2018!'],
        ], $json);
    }

    public function testSystemBerichtGaatNietInJson(): void
    {
        $json = voegToeAanConversationJsonArray([], 'system', 'Email verzonden');

        $this->assertSame([], $json);
    }

    public function testLegeJsonWordtLegeArray(): void
    {
        $this->assertSame([], laadConversationJsonArray(''));
        $this->assertSame([], laadConversationJsonArray(null));
    }

    public function testBotUrlsWordenLinksInOpgeslagenHtml(): void
    {
        $html = maakChatBerichtHtml('bot', 'Kijk https://www.marioswitch.nl/Just_Dance_2018 voor info.');

        $this->assertStringContainsString('<a href="https://www.marioswitch.nl/Just_Dance_2018"', $html);
        $this->assertStringContainsString('Just_Dance_2018</a>', $html);
    }

    public function testUrlMetPuntNaLinkHoortBuitenDeLink(): void
    {
        $html = zetUrlsOmNaarLinksInHtml('Bekijk https://www.marioswitch.nl/Mario_Kart. Leuk spel!');

        $this->assertStringContainsString('Mario_Kart</a>.', $html);
        $this->assertStringNotContainsString('Mario_Kart.</a>', $html);
    }

    public function testUserBerichtWordtGeescapedZonderLinks(): void
    {
        $html = maakChatBerichtHtml('user', '<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<a href', $html);
    }

    public function testKapotteJsonWordtLegeArray(): void
    {
        $this->assertSame([], laadConversationJsonArray('{kapot'));
    }
}
