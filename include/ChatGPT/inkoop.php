<?php
//Inkoop


$FAQ['inkoop'][1]['tonen'] = 1; //1 -> wel zichtbaar op website, 0 -> alleen voor bot
$FAQ['inkoop'][1]['site'] = 'All'; // 'All', Switch', Wii U', '3DS', 'Wii', 'DS', 'GC', 'GBA', 'N64', 'SNES'
$FAQ['inkoop'][1]['text'] = '<br /><br /><h2><img src="Plaatjes/Fun/logo-117x120.png" height = "59" widht = "60"> 

<A name="Inkoopsysteem"></a>Inkoopsysteem</h2>

<b><A name="Verkopen"></a>Hoe verkopen?</b><br />

Verkoop jouw gebruikte Nintendo-producten aan ons en ontvang geld. We werken met een inruilsysteem, je ziet direct hoeveel geld je inruil waard is bij ons:

<ul>
<li>SNES via <a href="https://www.mariosnes.nl/inkoop1.php">Mario SNES</a></li>
<li>N64 via <a href="https://www.mario64.nl/inkoop1.php">Mario 64</a></li>
<li>GBA via <a href="https://www.marioGBA.nl/inkoop1.php">Mario GBA</a></li>
<li>Gamecube via <a href="https://www.mariocube.nl/inkoop1.php">MarioCube</a></li>
<li>DS via <a href="https://www.mariods.nl/inkoop1.php">Mario DS</a></li>
<li>Wii via <a href="https://www.mariowii.nl/inkoop1.php">Mario Wii</a></li>
<li>3DS via <a href="https://www.mario3ds.nl/inkoop1.php">Mario 3DS</a></li>
<li>Wii U via <a href="https://www.mariowii-u.nl/inkoop1.php">Mario Wii U</a></li>
<li>Switch via <a href="https://www.marioswitch.nl/inkoop1.php">Mario Switch</a></li></ul>
<p>

Meer uitleg over de verwachte staat van de producten lees je op de inruilpagina\'s zelf.<br><br>

<b><a name="InruilOpsturen"></a>Hoe werkt het als je een inruil opstuurt?</b><br>
Geef eerst op via ons inruilsysteem welke producten je aan ons wilt verkopen. Je ziet direct hoeveel geld wij ervoor kunnen geven. Als je akkoord gaat met ons aanbod, kun je je rekeningnummer en persoonlijke gegevens opgeven.<br><br>

Je ontvangt een e-mail met verzendinstructies. Je pakt alles in en verstuurt het naar het aangegeven adres. De verzendkosten zijn voor eigen rekening. Via track & trace kun je zien of het pakketje bij ons is aangekomen.<br><br>

 Bij opsturen (of zonder afspraak afgeven op kantoor) verwerken we je inruil met 1 tot 3 werkdagen.<br><br>

Wanneer we het pakket gecontroleerd hebben, ontvang je een e-mail met onze bevindingen. Als er echt iets geks aan de hand is, nemen we telefonisch contact op. Producten waarvoor we een bedrag hebben ingehouden, kun je teruggestuurd krijgen (hier kunnen verzendkosten voor in rekening worden gebracht). De volgende werkdag staat het geld op je rekening.<br><br>

Ons doel is om een nieuwe eigenaar blij te maken met jouw producten. Als het product niet compleet is of beschadigd, zullen we de nieuwe eigenaar blij maken met een korting en een redelijk bedrag van je inruil afhalen.<br><br>



<b><A name="InruilBrengen"></a>Hoe werkt het als je een inruil langsbrengt?</b><br />
We werken met een inruilsysteem. Zo weet jij van te voren wat je krijgt voor je spullen en zijn we snel klaar als je langs komt. Na het aanmaken van je inruil kun je ons bellen voor een afspraak. Wanneer je op de afgesproken dag langs komt staan we klaar en controleren wij je inruil terwijl je wacht. We bespreken de onregelmatigheden. Het geld kunnen wij dan contant meegeven of overmaken.<br><br>



<b><A name="Tegoed"></a>Tegoed of geld voor inruil?</b><br>

Voor jouw inruil kun je alleen geld krijgen. Dit geld wordt overgemaakt naar jouw rekening, zodra wij de inruil hebben gecontroleerd. Wanneer je hier langkomt kun je er ook voor kiezen om jouw geld contant te ontvangen.<br><br>Het is helaas niet mogelijk om inruilen en bestellingen met elkaar te verrekenen. Natuurlijk ben je wel van harte welkom om het ontvangen geld van jouw inruil in een van onze webshops te besteden.<br><br>

<b><a name="Administratiekosten"></a>Administratiekosten</b><br>
Wanneer je een inruil op afspraak komt brengen, rekenen wij 2,75 euro administratiekosten, maar we verwerken de inruil meteen! Kies je ervoor om de inruil via de post naar ons op te sturen, dan betaal je geen administratiekosten.

<b><a name="Hoe noteer ik als ik meerdere games wil inruilen (Bijv. 2, 3 of 4)?"></a>Hoe noteer ik als ik meerdere games wil inruilen (Bijv. 2, 3 of 4)?</b><br>
Als je meerdere games wilt inruilen, kunt je dit aangeven door bijvoorbeeld x2 of x4 bij de game te noteren.';


$FAQ['zending'][2]['tonen'] = 0; //1 -> wel zichtbaar op website, 0 -> alleen voor bot
$FAQ['zending'][2]['site'] = 'All'; // 'All', Switch', Wii U', '3DS', 'Wii', 'DS', 'GC', 'GBA', 'N64', 'SNES'
$FAQ['zending'][2]['text'] = '<b>Kopen jullie deze producten in?</b><br>
Ja, wij kopen producten in zolang ze van Nintendo zijn. Je kunt een inkoopaanvraag indienen via onze inkooppagina, die je linksboven op de website vindt.';

$FAQ['zending'][3]['tonen'] = 0; //1 -> wel zichtbaar op website, 0 -> alleen voor bot
$FAQ['zending'][3]['site'] = 'All'; // 'All', Switch', Wii U', '3DS', 'Wii', 'DS', 'GC', 'GBA', 'N64', 'SNES'
$FAQ['zending'][3]['text'] = '<b>Ik heb iets heel speciaals, moois en duurs. Wat geven jullie er voor?</b><br>
Je kunt alles aan ons verkopen via ons inkoop systeem. Maar als je toch wilt dat we er persoonlijk even naar kijken ben je heel welkom om foto\'s naar onze WhatsApp sturen. We zullen dan de inkoopwaarde voor je controleren.';

include_once $_SERVER['DOCUMENT_ROOT']."/specific/skyscraperinkoopvragen.inc"; //$skyscraperinkoopvragenstring
$FAQ['zending'][4]['tonen'] = 0; //1 -> wel zichtbaar op website, 0 -> alleen voor bot
$FAQ['zending'][4]['site'] = $univ_one; // 'All', Switch', Wii U', '3DS', 'Wii', 'DS', 'GC', 'GBA', 'N64', 'SNES'
$FAQ['zending'][4]['text'] = $skyscraperinkoopvragenstring;
?>
