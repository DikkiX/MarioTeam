<?php
include_once $_SERVER['DOCUMENT_ROOT']."/include/db.inc";
include_once $_SERVER['DOCUMENT_ROOT']."/include/function_verkoopprijs.inc";
error_reporting(-1); //show errors

//SELECT 
$sthSELECTinfo = $conn->prepare("SELECT * FROM info");
$sthSELECTinfo->execute(array());
$result = $sthSELECTinfo->fetchAll();
if ($sthSELECTinfo->rowCount() > 0) 
{
   foreach ($result as $ligne)
   { 
       $link = $ligne['link'];
       $info[$link] = '';
       if ($ligne['hardware'] == 'ja')
       	 $info[$link] .= "
'type' => 'hardware/accessoires',";
       elseif ($ligne['hardware'] == 'nee')
       	 $info[$link] .= "
'type' => '".$ligne['type']." game',";
       else	 
       	 $info[$link] .= "
'type' => '".$ligne['hardware']."',";
       if ($ligne['TotBeoord'] > 1)
       	$info[$link] .= "
'review cijfer' => '".$ligne['GemCijfer']."',";
}  }


//info2 ophalen
$sthSELECTinfo2 = $conn->prepare("SELECT * FROM info2");
$sthSELECTinfo2->execute();
$result = $sthSELECTinfo2->fetchAll();

if ($sthSELECTinfo2->rowCount() > 0) {
    foreach ($result as $lignei) { 
        $link = $lignei['link'];
        $info2[$link] = '';

        if (isset($lignei['allerKleinste']) && $lignei['allerKleinste'] == 1 || isset($lignei['AllerKleinste']) && $lignei['AllerKleinste'] == 1) {
            $info2[$link] .= "kleuters, ";
        }
        if (isset($lignei['PEGI']) && $lignei['PEGI'] == 1) {
            $info2[$link] .= "PEGI ".$lignei['PEGI']."+, ";
        }
        if (isset($lignei['pegi']) && $lignei['pegi'] == 1) {
            $info2[$link] .= "PEGI ".$lignei['pegi']."+, ";
        }
        if (isset($lignei['Pegi']) && $lignei['Pegi'] == 1) {
            $info2[$link] .= "PEGI ".$lignei['Pegi']."+, ";
        }
        if (isset($lignei['6tm9']) && $lignei['6tm9'] == 1) {
            $info2[$link] .= "6 t/m 9 jaar, ";
        }
        if (isset($lignei['10tm13']) && $lignei['10tm13'] == 1) {
            $info2[$link] .= "10 t/m 13 jaar, ";
        }
        if (isset($lignei['kids']) && $lignei['kids'] == 1) {
            $info2[$link] .= "kids, ";
        }
        if (isset($lignei['45plus']) && $lignei['45plus'] == 1) {
            $info2[$link] .= "senioren, ";
        }
        if (
            isset($lignei['HiddenGem']) && $lignei['HiddenGem'] == 1 ||
            isset($lignei['HiddenGems']) && $lignei['HiddenGems'] == 1 ||
            isset($lignei['gem']) && $lignei['gem'] == 1
        ) {
            $info2[$link] .= "hidden gem, ";
        }
        if (isset($lignei['Jongen']) && $lignei['Jongen'] == 1 || isset($lignei['jongens']) && $lignei['jongens'] == 1) {
            $info2[$link] .= "jongen, ";
        }
        if (isset($lignei['Meisje']) && $lignei['Meisje'] == 1 || isset($lignei['meisjes']) && $lignei['meisjes'] == 1) {
            $info2[$link] .= "meisje, ";
        }
        if (isset($lignei['Gamer']) && $lignei['Gamer'] == 1 || isset($lignei['gamers']) && $lignei['gamers'] == 1) {
            $info2[$link] .= "gamer, ";
        }
        if (isset($lignei['MotionControls']) && $lignei['MotionControls'] == 1) {
            $info2[$link] .= "motion controls, ";
        }
        if (isset($lignei['Multiplayer']) && $lignei['Multiplayer'] == 1) {
            $info2[$link] .= "multiplayer, ";
        }
        if (isset($lignei['Coop']) && $lignei['Coop'] == 1) {
            $info2[$link] .= "coop multiplayer, ";
        }
        if (isset($lignei['Fitness']) && $lignei['Fitness'] == 1) {
            $info2[$link] .= "fitness, ";
        }
        if (isset($lignei['DansZing']) && $lignei['DansZing'] == 1) {
            $info2[$link] .= "dans/zing, ";
        }
        if (
            isset($lignei['Nintendo']) && $lignei['Nintendo'] == 1 ||
            isset($lignei['nintendo']) && $lignei['nintendo'] == 1 ||
            isset($lignei['N']) && $lignei['N'] == 1
        ) {
            $info2[$link] .= "Uitgever Nintendo, ";
        }
        if (
            isset($lignei['SRare']) && $lignei['SRare'] == 1 ||
            isset($lignei['LRun']) && $lignei['LRun'] == 1 ||
            isset($lignei['SLimited']) && $lignei['SLimited'] == 1 ||
            isset($lignei['FLimited']) && $lignei['FLimited'] == 1 ||
            isset($lignei['OLimited']) && $lignei['OLimited'] == 1 ||
            isset($lignei['Square Enix']) && $lignei['Square Enix'] == 1 ||
            isset($lignei['SquareEnix']) && $lignei['SquareEnix'] == 1 ||
            isset($lignei['Square']) && $lignei['Square'] == 1 ||
            isset($lignei['007']) && $lignei['007'] == 1 ||
            isset($lignei['StarWars']) && $lignei['StarWars'] == 1 ||
            isset($lignei['starwars']) && $lignei['starwars'] == 1 ||
            isset($lignei['Rare']) && $lignei['Rare'] == 1 ||
            isset($lignei['CapCom']) && $lignei['CapCom'] == 1 ||
            isset($lignei['Capcom']) && $lignei['Capcom'] == 1 ||
            isset($lignei['konami']) && $lignei['konami'] == 1 ||
            isset($lignei['Altus']) && $lignei['Altus'] == 1 ||
            isset($lignei['atlus']) && $lignei['atlus'] == 1 ||
            isset($lignei['Atlus']) && $lignei['Atlus'] == 1 ||
            isset($lignei['NESClassics']) && $lignei['NESClassics'] == 1
        ) {
            $info2[$link] .= "verzamelaars, ";
        }

        // Verwijder laatste komma
        $info2[$link] = substr($info2[$link], 0, -2);
    }
}



if (($univ_one == 'SNES')||($univ_one == 'N64')||($univ_one == 'GBA'))
	$gamers = 'Gamers houden van top lijstjes en betalen liever niet te veel voor een nieuwe game. Vaak hebben we games in verschillende staten op voorraad: Beschadigd of verkleurd verkopen we met een leuke korting! We raden geen games compleet in doosje aan want die zijn snel te duur. Naast prijzen kun je gamers ook overtuigen dat een game perfect is voor hun door het gemiddelde cijfer op '. $univ_web.' te tonen of aan te geven dat het een hidden gem is.
De doelgroep Gamers vertel je dus dat het een top 3 beste games voor jou lijstje is. Je verteld het erbij als er een Fantastisch korting is omdat het beschadigd of verkleurd is.';
else
	$gamers = 'Gamers houden van top lijstjes en betalen liever niet te veel voor een nieuwe game. Vaak hebben we games in verschillende staten op voorraad: Los, buitenlands doosje, beschadigd of zonder handleiding verkopen we met een leuke korting! We raden geen special editions aan want die zijn snel te duur. Naast prijzen kun je gamers ook overtuigen dat een game perfect is voor hun door het gemiddelde cijfer op '. $univ_web.' te tonen of aan te geven dat het een hidden gem is.
De doelgroep Gamers vertel je dus dat het een top 3 beste games voor jou lijstje is. Je verteld het erbij als het een aanbiedingsprijs betreft.';

if (($univ_one == 'SNES')||($univ_one == 'N64')||($univ_one == 'GBA'))
	$verzamelaars = 'Verzamelaars raden we relatief dure en zeldzame producten aan, in de staat die verzameld wordt. Soms zijn games als nieuw (zeer mooi doosje en handleiding) beschikbaar wat een holy grail kan zijn voor een verzamelaar. We hebben speciaal voor verzamelaars een <a href=\'https://wa.me/$mijnmobielb\'>Whatsapp service</a> om foto\'s van producten te sturen zodat ze precies kunnen zien wat ze ontvangen. Verzamelaars zien hun aanschaf ook als een inverstering, het wordt steeds meer waard. 
De doelgroep verzamelaars attendeer je op als nieuw games. Vertel na je advies dat games een fantastische investering zijn (het word steeds meer waard).';
elseif (($univ_one == 'Switch'))
	$verzamelaars = 'Verzamelaars raden we relatief dure en zeldzame producten aan, in de staat die verzameld wordt. Er zijn veel special editions en gelimiteerde uitgaven op de Switch. We hebben speciaal voor verzamelaars een <a href=\'https://wa.me/$mijnmobielb\'>Whatsapp service</a> om foto\'s van producten te sturen zodat ze precies kunnen zien wat ze ontvangen. Verzamelaars zien hun aanschaf ook als een inverstering, het wordt steeds meer waard. 
De doelgroep verzamelaar vertel je dus ook na je advies dat ze een foto kunnen opvragen (producten met nummer hebben al fotos op de website). Vertel ook dat games een mooie investering zijn (het word steeds meer waard).';
else
	$verzamelaars = 'Verzamelaars raden we relatief dure en zeldzame producten aan, in de staat die verzameld wordt. Er zijn enkele games die in combinatie met een extra accessoire, steelbook of amiibo werden verkocht. Attendeer verzamelaars op deze holy grails wanneer deze op voorraad zijn . We hebben speciaal voor verzamelaars een <a href=\'https://wa.me/$mijnmobielb\'>Whatsapp service</a> om foto\'s van producten te sturen zodat ze precies kunnen zien wat ze ontvangen. Verzamelaars zien hun aanschaf ook als een inverstering, het wordt steeds meer waard. 
De doelgroep verzamelaar vertel je dus na je advies ook: Dat ze een foto kunnen opvragen (producten met nummer hebben al fotos op de website). Attendeer je op holy grails. Vertel ook dat games een mooie investering zijn (het wordt steeds meer waard).';

if ($univ_one == 'Switch')
	$tussentweehaakjes = '';
else
	$tussentweehaakjes = ' (voorbeeld is voor een Switch website)';

$systemList = '
<b>B. Verkoopadvies</b>
Onze klanten zijn in drie doelgroepen onder te verdelen: Verzamelaars, gamers en ouders. Onder ouders bedoelen we klanten die niet voor zichzelf kopen maar voor een kind (zoon, dochter, vriendje etc). De gebruiker heeft al wat vragen beantwoord zodat je een goed verkoopadvies kunt gegven. Noem 1 tot 3 games die in de lijst hieronder staan en perfect zouden zijn om te kopen voor de gebruiker. De informatie die je geeft pas je aan op de doelgroep. Hieronder de informatie waar je per doelgroep rekening mee houdt.

Verzamelaars:
'.$verzamelaars.'

Gamers:
'.$gamers.'

Ouders:
Let er op dat de game overeenkomt met de leeftijd. Het is de perfecte game als het ook nog in de interesses van het kind past. 
Ouders hebben vaak wat meer uitleg nodig. Voorbeelden van wat je moet uitleggen (nadat je advies hebt gegeven): Dat Just Dance voor de Switch niet op een Switch Lite gespeeld kan worden. Zo leg je uit dat voor Mario Party extra controllers erg leuk zijn omdat je dan met meerdere spelers kunt spelen. Dat Wii spellen ook op een Wii U gespeeld kunnen worden (maar niet andersom). Fantastisch Tweedehands is zo mooi dat je het gerust cadeau kunt geven: Goed voor het milieu! 
Ouders geef je dus extra uitleg na het advies.

LET OP: Je raad alleen producten aan die we daadwerkelijk op voorraad hebben volgens onderstaande lijst. Raad geen producten aan die de gebruiker al heeft. Raad geen producten aan die niet in de lijst staan.

Voorbeeld van advies voor ouders'.$tussentweehaakjes.':
Ik heb een paar Fantastische suggesties voor je dochter van 8 jaar die houdt van iets spannends:<br><br><b>1. Luigi’s Mansion 3</b> – Dit spel is spannend en grappig tegelijk! Luigi gaat op avontuur in een mysterieus hotel met spookachtige kamers. En het mooie is dat het ook samen gespeeld kan worden! <a href = "https://www.marioswitch.nl/Switch-spel-info.php?t=Luigis_Mansion_3" target = "_top">Bekijk in hoofdvenster</a>.<br><br><b>2. Kirby’s Return to Dream Land Deluxe</b> – Ga op reis door Dream Land met Kirby, een schattig maar dapper personage dat avonturen beleeft. Het is een kleurrijk en leuk spel vol actie! <a href = "https://www.marioswitch.nl/Switch-spel-info.php?t=Kirbys_Return_to_Dream_Land_Deluxe"  target = "_top">Bekijk in hoofdvenster</a>.<br><br><b>3. Super Mario 3D World + Bowser’s Fury</b> – Twee spellen in één! In Super Mario 3D World kan ze samen met vrienden of familie de wereld van Mario verkennen, en Bowser’s Fury biedt een open wereld avontuur. <a href = "https://www.marioswitch.nl/Switch-spel-info.php?t=Super_Mario_3D_World_Plus_Bowsers_Fury" target = "_top">Bekijk in hoofdvenster</a>.<br><br>Deze spellen zijn allemaal heel erg leuk en geschikt voor de leeftijd van je dochter.<br><br>Wil je weten welke controllers ik aanraad voor Luigi’s Mansion 3?

<b>C. Switch lijst met productvoorraad</b>
De volgende producten zijn op voorraad. Een titel zonder verdere extra aanduiding is in Fantastisch Tweedehands staat. Fantastisch Tweedehands betekend zo mooi dat je het cadeau kunt geven. Producten die beginnen met nr [123] zijn altijd foto\'s van beschikbaar op de website.
';


    
//SELECT 
$sthSELECTwinkel = $conn->prepare("SELECT * FROM Winkel WHERE aantal > 0 AND prijs > 0 ORDER BY link");
$sthSELECTwinkel->execute(array());
$result = $sthSELECTwinkel->fetchAll();
if ($sthSELECTwinkel->rowCount() > 0)  
{
   foreach ($result as $ligneDymon)
   { 
     $link = $ligneDymon['link'];
     list($prijsvk,$prijsvan) = verkoop_prijs($ligneDymon['link2']);
     $prijs4List = "'prijs' => '$prijsvk'";
     if ($prijsvan != '')
       $prijs4List = "'aanbiedingsprijs' => '$prijsvk', 
'oude prijs' => '$prijsvan'";	
     if (isset($info2[$link]) == 0) $info2[$link] = '';
     $systemList .= "
[
'titel' => '".$ligneDymon['titel']."',
'info' => '".$ligneDymon['sentence']."',
'link' => 'https://www.".$univ_web."/Switch-spel-info.php?t='".$ligneDymon['link']."',".$info[$link]."
'aanbevolen voor' => '".$info2[$link]."', 
$prijs4List
],
    ";
}  }

$systemList .= "

Kies 3 games uit bovenstaande lijst die je aanraad op basis van de gegeven antwoorden.";

?>