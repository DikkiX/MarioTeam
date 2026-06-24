<?php

// Tests voor include/chat_functie_keuze.php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../include/chat_functie_keuze.php';

final class ChatFunctieKeuzeTest extends TestCase
{
    private function functieNaamUitKeuze($keuze): ?string
    {
        if (!is_array($keuze) || !isset($keuze['function']['name'])) {
            return null;
        }

        return (string) $keuze['function']['name'];
    }

    public function testVergelijkbareSpellenForceertAanraders(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze('Hebben jullie spellen die lijken op Xenoblade?');
        $this->assertSame('zoek_productaanraders', $this->functieNaamUitKeuze($keuze));
    }

    public function testDanspellenForceertAanraders(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze('danspellen?');
        $this->assertSame('zoek_productaanraders', $this->functieNaamUitKeuze($keuze));
    }

    public function testJustDanceVoorraadForceertProductLookup(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze('Hebben jullie dus geen Just Dance?');
        $this->assertSame('zoek_productvoorraad', $this->functieNaamUitKeuze($keuze));
    }

    public function testPakketVolgenMetBestellingForceertTraceer(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze(
            'Waar is mijn pakket? Bestelling 4711, email klant@mail.nl'
        );
        $this->assertSame('zoek_traceer', $this->functieNaamUitKeuze($keuze));
    }

    public function testTraceerNummerForceertTraceer(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze('Kun je status geven van traceer 3SYZAB123456789?');
        $this->assertSame('zoek_traceer', $this->functieNaamUitKeuze($keuze));
    }

    public function testAdresWijzigingForceertAdresTool(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze(
            'Ik wil het adres aanpassen voor bestelling 19235'
        );
        $this->assertSame('wijzig_bestelling_adres', $this->functieNaamUitKeuze($keuze));
    }

    public function testAlgemeneGroetBlijftAuto(): void
    {
        $this->assertSame('auto', bepaalGeforceerdeFunctieKeuze('Hoi, hoe gaat het?'));
    }

    public function testProductFinderBlokkeertNietMeteenDatabaseZoeken(): void
    {
        $this->assertSame('auto', bepaalGeforceerdeFunctieKeuze('hebben jullie dans spellen?', 'ProductFinder'));
    }

    public function testNaVijfVragenWordtDatabaseGezocht(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze('Ik heb antwoord op al mijn vragen.', 'ProductFinder');
        $this->assertSame('zoek_productaanraders', $this->functieNaamUitKeuze($keuze));
    }

    public function testVoorraadFollowUpWordtHerkend(): void
    {
        $this->assertTrue(isVoorraadFollowUpVraag('zijn ze allemaal op voorraad'));
        $this->assertTrue(isVoorraadFollowUpVraag('hebben jullie ze nog op voorraad'));
        $this->assertFalse(isVoorraadFollowUpVraag('hebben jullie danspellen?'));
    }

    public function testProductFinderMetVergelijkingForceertAanraders(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze('Hebben jullie spellen die lijken op Xenoblade?', 'ProductFinder');
        $this->assertSame('zoek_productaanraders', $this->functieNaamUitKeuze($keuze));
    }

    public function testProductFinderMetAlleenSuggestiesForceertAanraders(): void
    {
        $keuze = bepaalGeforceerdeFunctieKeuze('Ik wil alleen suggesties, geen andere vragen', 'ProductFinder');
        $this->assertSame('zoek_productaanraders', $this->functieNaamUitKeuze($keuze));
    }
}
