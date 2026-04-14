<?php

/////////////////////////////////
//Langskomen
$bodymain3 .= '<h2 class = "solidLineTop"><img src="/Plaatjes/Opmaak/Langskomen-80x54.png" width = "40" alt="Vragen"> Langskomen?</h2>';

$bodymain3 .= "<font color = brown><b>Geen fysieke winkel<br>Afgeven aangemelde inruil. Afhalen klaargelegde bestelling.</b></font><br><br>";

include_once $_SERVER['DOCUMENT_ROOT']."/include/openmelding.inc";
$bodymain3 .= $openmelding."<br><br>"; //bv Vandaag zijn wij aanwezig vanaf 10.00u.

include_once $_SERVER['DOCUMENT_ROOT']."/include/inkoopmelding.inc";	
//verkopen
$bodymain3 .= '<b>Inruil afgeven:</b><br>Je '.$univ_one.'-inruil mag worden afgegeven nadat je ons <a href="inkoop1.php">inkoop formulier</a> hebt ingevuld.';
if ($inkoopmelding != '') 
 $bodymain3 .= '<br>'.$inkoopmelding.'<br><br>';
else 
 $bodymain3 .= ' De inruil wordt binnen 48 uur verwerkt.<br><br>';
//kopen
$bodymain3 .= '<b>Bestelling afhalen:</b><br>Zodra je '.$univ_one.' bestelling klaar ligt om op te halen ontvang je een email. Op werkdagen en zondagen ontvang je deze mail meestal binnen een uur. Als je haast hebt mag je ons altijd bellen!<br><br>';


/////////////////////////////////
//Contact


$bodymain3 .= '<h2  class = "solidLineTop"><img src="/Plaatjes/Opmaak/Veelgestelde-vragen-80x70.png" width = "40" alt="Onze gegevens:"> Contact Klantenservice</h2>';

if (date("d-m-Y") != "08-02-2021")
$bodymain3 .= 'We vinden het leuk om je te helpen. Zo kun je ons bijvoorbeeld WhatsAppen voor product foto\'s.<br /><br />';

if (date("d-m-Y") == "03-03-2024")
 $bodymain3 .= "<b><font color = green>Vandaag geen telefonische klantenservice</b><br>
Morgen vanaf 10.00u zijn we weer telefonisch bereikbaar. Mail en WhatsApp wordt wel beantwoord vandaag. Excuus voor het ongemak.</b></font><br /><br />";

$bodymain3 .= '
<div class = \'containermain\'>
<div class = \'contact_top\'>

<table cellspacing = 2 class = norm>
<tr valign = top>
<td>

<img src = \'Plaatjes/Fun/email.png\' alt = \'@\' height = \'25\' width = \'25\'><td>Email
<td><table><tr><td>'.
"$mailadres</table>";


$bodymain3 .= '<tr valign = top><td>
<img src=\'Plaatjes/Fun/telefoon.png\' alt=\'Telefoon\' height = \'25\' width = \'25\'><td>Telefoon<td><table><tr><td>';
if ((date("d-m-Y") == "23-11-2021") && (date("H") < 12))
 $bodymain3 .= "<a href='tel:$mijntelb'>".$mijntelefoon."</a> (vanaf 12.00u)</table>";
else
 $bodymain3 .= "<a href='tel:$mijntelb'>".$mijntelefoon."</a></table>";
$bodymain3 .= "<tr valign = top><td><img src='Plaatjes/Fun/whatsapp.png' alt='WhatssApp' height = '25' width = '25'><td>Whatsapp";
//if ((date("N") == 6)||((date("N") == 5)&&(date("H") > 17)))
// $bodymain3 .= '<font color = "orange" size = "-2"><br>Wordt pas zondag<br>beantwoord.</font>';
$bodymain3 .= "<td><table><tr><td><a href='https://wa.me/$mijnmobielb' target = '_blank'>$mijnmobiel</a></table><tr valign = 'top'><td>";

$bodymain3 .= '<img src="Plaatjes/Fun/openingstijden.png" alt="Openingstijden"  height = "25" width = "25"><td>Bereikbaar<td>';
$bodymain3 .= $cantacttijden1;
$bodymain3 .= '<br></table>';

$bodymain3 .= '</table></div><div class = \'contact_right\'>';
//$bodymain3 .= '</table>';
/*
if ( ((date("d") > 5) && (date("m") == 12)) || ((date("d") == 1) && (date("m") == 1)))
  $bodymain3 .= '<img src="https://www.mariowii.nl/Plaatjes/Opmaak/mailkerst.jpg" class = "teamfoto" alt="Mario Team met kerst">';
else */
  $bodymain3 .= '<img src="https://www.mariowii.nl/SpecifiekePlaatjes/Team-foto-groep.jpg" class = "contact_teamfoto" alt="Mario Team groepsfoto">';
 
$bodymain3 .= '</div></div>';
	
$bodymain3 .= '
<h2  class = "solidLineTop"><img src="/Plaatjes/Opmaak/Bedrijfsgegevens-80x66.png" height = "40" alt="Onze gegevens:"> Bedrijfsgegevens</h2>';

$bodymain3 .= "<font color = brown><b>Geen fysieke winkel<br>Afgeven aangemelde inruil. Afhalen klaargelegde bestelling.</b></font><br><br>";
$bodymain3 .= '</font><table><tr valign = "top"><td colspan="2"><b>Adres</b><td  colspan="2">';
$bodymain3 .= $univ_mar.'</br>';

$bodymain3 .= $mijnadresbr;


$bodymain3 .= '<tr valign = top><td colspan="2"><br><b>Onderdeel van</b><td colspan="2"><br>Mario Team bv<tr valign = top><td colspan="2">
<b>Rekeningnummer</b><td  colspan="2">';
$bodymain3 .= $mijnbanknr;
$bodymain3 .= '<tr valign = top><td colspan="2"><b>KvK nummer</b><td>'.
$kvk
.'<tr valign = top><td colspan="2"><b>btw-id</b><td>'.$btwnr;

$bodymain3 .= '</table></div></div>';
?>