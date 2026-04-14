<?
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
//!!LET OP LEES MIJ!!
//!!
//!!$YYYY_MM_DDgoogle_optin is nodig voor Google_Customer_Reviews.inc	
//!!
//!!', ' wordt gebruikt voor alle bestemmingen buiten NL en BE
//!!'morgen' en 'overmorgen' zijn keywords die gebruikt worden in www.mariocube.nl/Admin/Dymo_Printen.php en include/form-gegevens-kassa3-univ.inc!
//!!deze keywords bepalen of iets een uitgestelde bestelling is!
//!!$wanneera is brievenbus
//!!$wanneerb is pakket!
//!!$wanneerc is pakket naar Belgie!
//!!
//!!Speciale dagen:
//!!include "include/openmelding.inc";
//!!mijnemail_universeel.inc
//!!
//!!Pas ook $VoorUurBesteldMorgenInHuis in veel gestelde vragen aan!!
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!

//wordt ook gebruikt in include/bb_statuschange_and_mail.inc & paynl-status-update.php
//bestel Bestel is belangrijk!!
//$msg .= "(".str_replace ('Bestel','Betaal',$wanneerb).")\n\n"; //$wanneerb = 'Bestel vandaag voor 11.00u, vanavond in huis.';
//, morgen en vanavond is belangrijk in include/bb_statuschange_and_mail.inc om te weten of het vandaag wordt verstuurd.
$today = getdate();

/*/A/B Test
//in R3/inc/winkel2
//in /kassa
include_once($_SERVER['DOCUMENT_ROOT'] . '/include/abtest.php');
$variantHL['A'] = "Niet";
$variantHL['B'] = "Wel";
$user_variantWinkelHighlight = voerABTestUit('WinkelHighlight', $variantHL, 0);
$boldABtestOpen = ''; $boldABtestClose = '';
if ($user_variantWinkelHighlight == "Wel")
{
	$boldABtestOpen = '<b>'; $boldABtestClose = '</b>';
}
*/
if ( ($today['hours'] < 18) && (($today['weekday'] == 'Monday')||($today['weekday'] == 'Tuesday')||($today['weekday'] == 'Wednesday')||($today['weekday'] == 'Thursday')) )
{ // voor 18.00u ma t/m do
  $wanneera = 'Bestel vandaag voor 18.00u, morgen in huis.';
  $wanneerb = 'Bestel vandaag voor 18.00u, morgen in huis.';
  if ((isset($language) == 1)&&($language == 'en'))
    $wanneera = 'Order today before 6 PM, delivered tomorrow.';
  $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+2, date("Y")));
}
elseif ( ($today['hours'] < 18) && ($today['weekday'] == 'Friday') )
{ // voor 18.00u vr
  $wanneera = 'Bestel vandaag voor 18.00u, morgen in huis.';
  $wanneerb = 'Bestel vandaag voor 18.00u, morgen in huis.';
  if ((isset($language) == 1)&&($language == 'en'))
    $wanneera = 'Order today before 6 PM, delivered tomorrow.';
  $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+4, date("Y")));
}
elseif ( ($today['hours'] < 18) && ($today['weekday'] == 'Sunday'))
{ // voor 18.00u ma t/m do
  $wanneera = 'Bestel zondag voor 18.00u, morgen in huis.';
  $wanneerb = 'Bestel zondag voor 18.00u, morgen in huis.';
  if ((isset($language) == 1)&&($language == 'en'))
    $wanneera = 'Order on Sunday before 6 PM, delivered tomorrow.';	
  $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+2, date("Y")));
}
elseif(($today['weekday'] == 'Sunday')||($today['weekday'] == 'Monday')||($today['weekday'] == 'Tuesday')||($today['weekday'] == 'Wednesday')||($today['weekday'] == 'Thursday'))
// na 18.00u ma t/m do
{ 
  if ($today['hours'] < 18)
  {
    $wanneera = 'Bestel vanmiddag, overmorgen in huis.';
    $wanneerb = 'Bestel vanmiddag, overmorgen in huis.'; 
    if ((isset($language) == 1)&&($language == 'en'))
      $wanneera = 'Order this afternoon, delivered the day after tomorrow.'; 	
  }
  else
  {
    $wanneera = 'Bestel vanavond, overmorgen in huis.';
    $wanneerb = 'Bestel vanavond, overmorgen in huis.';
    if ((isset($language) == 1)&&($language == 'en'))
     $wanneera = 'Order this evening, delivered the day after tomorrow.';
  }  
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+3, date("Y")));	
}
elseif ($today['weekday'] == 'Friday')
{ //na 18.30u vr of za
  $wanneera = 'Bestel vanavond, maandag in huis.';
  $wanneerb = 'Bestel vanavond, maandag in huis.';	
	 $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+4, date("Y")));
  if ((isset($language) == 1)&&($language == 'en')) 
    $wanneera = 'Order this evening, delivered on Monday.';	 	
}
elseif ($today['weekday'] == 'Saturday')
{ //na 18.30u vr of za
  $wanneera = 'Bestel vandaag, maandag in huis.';
  $wanneerb = 'Bestel vandaag, maandag in huis.';	
	 $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+3, date("Y")));
  if ((isset($language) == 1)&&($language == 'en'))
	 $wanneera = 'Order today, delivered on Monday.';	 	
}

///////////////////////////
//BELGIE 
//!!bestel wordt betaal in bb_statuschange_and_email.inc 
if ( ($today['hours'] < 18) && (($today['weekday'] == 'Monday')||($today['weekday'] == 'Tuesday')||($today['weekday'] == 'Wednesday')||($today['weekday'] == 'Thursday')) )
{ // voor 18.00u ma t/m do
  $wanneerc = 'Bestel voor 18.00u, morgen in huis (in België).';	
}
elseif ( ($today['hours'] < 18) && ($today['weekday'] == 'Friday') )
{ // voor 18.00u vr
  $wanneerc = 'Bestel voor 18.00u, maandag in huis (in België).';
}
elseif(($today['weekday'] == 'Monday')||($today['weekday'] == 'Tuesday')||($today['weekday'] == 'Wednesday'))
// na 18.00u ma t/m do
{ 
  if ($today['hours'] < 18)
    $wanneerc = 'Bestel vandaag, overmorgen in huis (in België).';
  else	
    $wanneerc = 'Bestel vanavond, overmorgen in huis (in België).';		
}
elseif($today['weekday'] == 'Thursday')
{ 
  if ($today['hours'] < 18)
    $wanneerc = 'Bestel vandaag, maandag in huis (in België).';
  else	
    $wanneerc = 'Bestel vanavond, maandag in huis (in België).';		
}
elseif($today['weekday'] == 'Sunday')
// zo
{ 
  if ($today['hours'] < 18)
    $wanneerc = 'Bestel vandaag, overmorgen in huis (in België).';
  else	
    $wanneerc = 'Bestel vanavond, overmorgen in huis (in België).';
}
elseif (($today['weekday'] == 'Friday')||($today['weekday'] == 'Saturday'))
{ //na 16u op vr of za
  if ($today['hours'] < 18)
    $wanneerc = 'Bestel vandaag, dinsdag in huis (in België).';
  else	
    $wanneerc = 'Bestel vanavond, dinsdag in huis (in België).';
}

///////////////////////////////////
//overkomstduur buitenland
//PL, PT, ES, IT 3 dagen
//DA, DE, FR 2 dagen

//hoeveel dagen onderweg?
if ($today['hours'] < 18)
{
  if (date("N") == 7)
	$time4_2days = 3;
  elseif ((date("N") + 2) > 5)
	$time4_2days = 4;
  else
	$time4_2days = 2;

  if (date("N") == 7)
	$time4_3days = 4;
  elseif ((date("N") + 3) > 5)
	$time4_3days = 5;
  else
	$time4_3days = 3;
}
else //post is al opgehaald vandaag
{
  if (date("N") == 7)
	$time4_2days = 3;
  elseif ((date("N") + 3) > 5)
	$time4_2days = 5;
  else
	$time4_2days = 3;

  if (date("N") == 7)
	$time4_3days = 4;
  elseif ((date("N") + 4) > 5)
	$time4_3days = 6;
  else
	$time4_3days = 4;
}



//N bepalen van de dag dat het aankomt
if ($time4_2days == 2) 
	$time4_N_2days = 0; //overmorgen
else 
{
	$time4_N_2days = date("N") + $time4_2days; 
	if ($time4_N_2days > 7) $time4_N_2days -= 7;
}
$time4_N_3days = date("N") + $time4_3days; 
if ($time4_N_3days > 7) $time4_N_3days -= 7;

$dagt4[1]['nl']='maandag'; 
$dagt4[2]['nl']='dinsdag'; 
$dagt4[3]['nl']='woensdag'; 
$dagt4[4]['nl']='donderdag'; 
$dagt4[5]['nl']='vrijdag'; 
$dagt4[6]['nl']='zaterdag'; 
$dagt4[7]['nl']='zondag'; 
$dagt4[0]['nl']='overmorgen';

//voor kassa alle overkomstduren
list ($besteldVandaag, $rubbish) = explode(', ',$wanneerb,2);
$time4_4_kassa['Nederland'] = $wanneerb;
if ((isset($language) == 1)&&($language == 'en'))
	$wanneerb = $wanneera;

$time4_4_kassa['Belgie'] = $besteldVandaag.', '.$dagt4[$time4_N_2days]['nl'].' al bezorgd in Belgie!';
$time4_4_kassa['Bulgarije'] = 'Verzonden vanuit Nederland naar Bulgarije met GLS. Je kunt jouw bestelling binnen 5-6 werkdagen verwachten.';
$time4_4_kassa['Denemarken'] = $besteldVandaag.', '.$dagt4[$time4_N_2days]['nl'].' al bezorgd in Denemarken!';
$time4_4_kassa['Duitsland'] = $besteldVandaag.', '.$dagt4[$time4_N_2days]['nl'].' al bezorgd in Duitsland!';
$time4_4_kassa['Estland'] = 'Verzonden vanuit Nederland naar Estland met GLS. Je kunt jouw bestelling binnen 5-6 werkdagen verwachten.';
$time4_4_kassa['Finland'] = 'Verzonden vanuit Nederland naar Finland met GLS. Je kunt jouw bestelling binnen 4-6 werkdagen verwachten.';
$time4_4_kassa['Frankrijk'] = $besteldVandaag.', '.$dagt4[$time4_N_2days]['nl'].' al bezorgd in Frankrijk!';
$time4_4_kassa['Griekenland'] = 'Verzonden vanuit Nederland naar Griekenland met GLS. Je kunt jouw bestelling binnen 5-8 werkdagen verwachten.';
$time4_4_kassa['Hongarije'] =$besteldVandaag.', '.$dagt4[$time4_N_3days]['nl'].' al bezorgd in Hongarije!';
$time4_4_kassa['Ierland'] = 'Verzonden vanuit Nederland naar Ierland met GLS. Je kunt jouw bestelling binnen 4 werkdagen verwachten.';
$time4_4_kassa['Italie'] = $besteldVandaag.', '.$dagt4[$time4_N_3days]['nl'].' al bezorgd in Italie!';
$time4_4_kassa['Kroatie'] = 'Verzonden vanuit Nederland naar Kroatië met GLS. Je kunt jouw bestelling binnen 5-7 werkdagen verwachten.';
$time4_4_kassa['Letland'] = 'Verzonden vanuit Nederland naar Letland met GLS. Je kunt jouw bestelling binnen 4 werkdagen verwachten.';
$time4_4_kassa['Litouwen'] = 'Verzonden vanuit Nederland naar Litouwen met GLS. Je kunt jouw bestelling binnen 4 werkdagen verwachten.';
$time4_4_kassa['Luxemburg'] = $besteldVandaag.', '.$dagt4[$time4_N_2days]['nl'].' al bezorgd in Luxemburg!';
$time4_4_kassa['Oostenrijk'] = $besteldVandaag.', '.$dagt4[$time4_N_3days]['nl'].' al bezorgd in Oostenrijk!';
$time4_4_kassa['Polen'] = $besteldVandaag.', '.$dagt4[$time4_N_3days]['nl'].' al bezorgd in Polen!';
$time4_4_kassa['Portugal'] = $besteldVandaag.', '.$dagt4[$time4_N_3days]['nl'].' al bezorgd in Portugal!';
$time4_4_kassa['Roemenie'] = 'Verzonden vanuit Nederland naar Roemenië met GLS. Je kunt jouw bestelling binnen 4 werkdagen verwachten.';
$time4_4_kassa['Slovenie'] = 'Verzonden vanuit Nederland naar Slovenië met GLS. Je kunt jouw bestelling binnen 4 werkdagen verwachten.';
$time4_4_kassa['Slowakije'] = $besteldVandaag.', '.$dagt4[$time4_N_3days]['nl'].' al bezorgd in Slowakije!';
$time4_4_kassa['Spanje'] = $besteldVandaag.', '.$dagt4[$time4_N_3days]['nl'].' al bezorgd in Spanje!';
$time4_4_kassa['Tsjechie'] = $besteldVandaag.', '.$dagt4[$time4_N_3days]['nl'].' al bezorgd in Tsjechie!';
$time4_4_kassa['Zweden'] = 'Verzonden vanuit Nederland naar Zweden. Je kunt jouw bestelling in 3-7 werkdagen verwachten.';
	
if ((isset($language)) &&($language != 'nl') &&($language != 'en')) //verzending naar buitenland
{
//vertalingen
$dagt4[1]['fr']='lundi'; $dagt4[2]['fr']='mardi'; $dagt4[3]['fr']='mercredi'; $dagt4[4]['fr']='jeudi'; $dagt4[5]['fr']='vendredi'; $dagt4[6]['fr']='samedi'; $dagt4[7]['fr']='dimanche';
$dagt4[1]['de']='Montag'; $dagt4[2]['de']='Dienstag'; $dagt4[3]['de']='Mittwoch'; $dagt4[4]['de']='Donnerstag'; $dagt4[5]['de']='Freitag'; $dagt4[6]['de']='Samstag'; $dagt4[7]['de']='Sonntag';
$dagt4[1]['it']='lunedì'; $dagt4[2]['it']='martedì'; $dagt4[3]['it']='mercoledì'; $dagt4[4]['it']='giovedì'; $dagt4[5]['it']='venerdì'; $dagt4[6]['it']='sabato'; $dagt4[7]['it']='domenica';
$dagt4[1]['es']='lunes'; $dagt4[2]['es']='martes'; $dagt4[3]['es']='miércoles'; $dagt4[4]['es']='jueves'; $dagt4[5]['es']='viernes'; $dagt4[6]['es']='sábado'; $dagt4[7]['es']='domingo';
$dagt4[1]['pt']='segunda-feira'; $dagt4[2]['pt']='terça-feira'; $dagt4[3]['pt']='quarta-feira'; $dagt4[4]['pt']='quinta-feira'; $dagt4[5]['pt']='sexta-feira'; $dagt4[6]['pt']='sábado'; $dagt4[7]['pt']='domingo';
$dagt4[1]['da']='mandag'; $dagt4[2]['da']='tirsdag'; $dagt4[3]['da']='onsdag'; $dagt4[4]['da']='torsdag'; $dagt4[5]['da']='fredag'; $dagt4[6]['da']='lørdag'; $dagt4[7]['da']='søndag';
$dagt4[1]['pl']='poniedziałek'; $dagt4[2]['pl']='wtorek'; $dagt4[3]['pl']='środa'; $dagt4[4]['pl']='czwartek'; $dagt4[5]['pl']='piątek'; $dagt4[6]['pl']='sobota'; $dagt4[7]['pl']='niedziela';
$dagt4[0]['fr']='après-demain'; $dagt4[0]['de']='übermorgen'; $dagt4[0]['it']='dopodomani'; $dagt4[0]['es']='pasado mañana'; $dagt4[0]['pt']='depois de amanhã'; $dagt4[0]['da']='i overmorgen'; $dagt4[0]['pl']='pojutrze';

/*//A/B Test
 if (isset($_GET['user_variantLanguageTime4']) == 1)
 	$user_variantLanguageTime4 = $_GET['user_variantLanguageTime4'];
 else
 {
  include_once($_SERVER['DOCUMENT_ROOT'] . '/include/abtest.php');
  $variantLanguageTime4['A'] = "met uit Nederland"; 
  $variantLanguageTime4['B'] = "zonder";
  $user_variantLanguageTime4 = voerABTestUit('LanguageTime4', $variantLanguageTime4, 0);
 }

 if ($user_variantLanguageTime4 == "met uit Nederland") {
    $OranjeMolen['fr'] = 'depuis les Pays-Bas';
    $OranjeMolen['de'] = 'aus den Niederlanden';
    $OranjeMolen['it'] = 'dai Paesi Bassi';
    $OranjeMolen['es'] = 'desde los Países Bajos';
    $OranjeMolen['pt'] = 'dos Países Baixos';
    $OranjeMolen['da'] = 'fra Holland';
    $OranjeMolen['pl'] = 'z Holandii';
 } else { */
$OranjeMolen['fr'] = '<a onmouseover="Tip(\'Expédié depuis les Pays-Bas\', BGCOLOR, \'#32dc64\')" onclick="Tip(\'Expédié depuis les Pays-Bas\', BGCOLOR, \'#32dc64\')" onmouseout="UnTip()"><img src="https://mariogba.nl/Plaatjes/Fun/LanguageMolen.png" alt="Expédié depuis les Pays-Bas" height="16"></a>';
$OranjeMolen['de'] = '<a onmouseover="Tip(\'Versandt aus den Niederlanden\', BGCOLOR, \'#32dc64\')" onclick="Tip(\'Versandt aus den Niederlanden\', BGCOLOR, \'#32dc64\')" onmouseout="UnTip()"><img src="https://mariogba.nl/Plaatjes/Fun/LanguageMolen.png" alt="Versandt aus den Niederlanden" height="16"></a>';
$OranjeMolen['it'] = '<a onmouseover="Tip(\'Spedito dai Paesi Bassi\', BGCOLOR, \'#32dc64\')" onclick="Tip(\'Spedito dai Paesi Bassi\', BGCOLOR, \'#32dc64\')" onmouseout="UnTip()"><img src="https://mariogba.nl/Plaatjes/Fun/LanguageMolen.png" alt="Spedito dai Paesi Bassi" height="16"></a>';
$OranjeMolen['es'] = '<a onmouseover="Tip(\'Enviado desde los Países Bajos\', BGCOLOR, \'#32dc64\')" onclick="Tip(\'Enviado desde los Países Bajos\', BGCOLOR, \'#32dc64\')" onmouseout="UnTip()"><img src="https://mariogba.nl/Plaatjes/Fun/LanguageMolen.png" alt="Enviado desde los Países Bajos" height="16"></a>';
$OranjeMolen['pt'] = '<a onmouseover="Tip(\'Enviado dos Países Baixos\', BGCOLOR, \'#32dc64\')" onclick="Tip(\'Enviado dos Países Baixos\', BGCOLOR, \'#32dc64\')" onmouseout="UnTip()"><img src="https://mariogba.nl/Plaatjes/Fun/LanguageMolen.png" alt="Enviado dos Países Baixos" height="16"></a>';
$OranjeMolen['da'] = '<a onmouseover="Tip(\'Afsendt fra Holland\', BGCOLOR, \'#32dc64\')" onclick="Tip(\'Afsendt fra Holland\', BGCOLOR, \'#32dc64\')" onmouseout="UnTip()"><img src="https://mariogba.nl/Plaatjes/Fun/LanguageMolen.png" alt="Afsendt fra Holland" height="16"></a>';
$OranjeMolen['pl'] = '<a onmouseover="Tip(\'Wysłano z Holandii\', BGCOLOR, \'#32dc64\')" onclick="Tip(\'Wysłano z Holandii\', BGCOLOR, \'#32dc64\')" onmouseout="UnTip()"><img src="https://mariogba.nl/Plaatjes/Fun/LanguageMolen.png" alt="Wysłano z Holandii" height="16"></a>';
// }

if (($today['hours'] > 17)||($today['weekday'] == 'Saturday'))
{
    // Als het na 18.00 uur
    $wanneerArr['fr'] = "Commandez aujourd'hui {$OranjeMolen[$language]}, {$dagt4[$time4_N_2days]['fr']} chez vous (en France) !";
    $wanneerArr['de'] = "Bestellen Sie heute {$OranjeMolen[$language]}, {$dagt4[$time4_N_2days]['de']} bei Ihnen (in Deutschland)!";
    $wanneerArr['it'] = "Ordina oggi {$OranjeMolen[$language]}, per {$dagt4[$time4_N_3days]['it']} a casa tua (in Italia)!";
    $wanneerArr['es'] = "Pide hoy {$OranjeMolen[$language]}, {$dagt4[$time4_N_3days]['es']} en tu casa (en España)!";
    $wanneerArr['pt'] = "Peça hoje {$OranjeMolen[$language]}, {$dagt4[$time4_N_3days]['pt']} em sua casa (em Portugal)!";
    $wanneerArr['da'] = "Bestil i dag {$OranjeMolen[$language]}, {$dagt4[$time4_N_2days]['da']} hos dig (i Danmark)!";
    $wanneerArr['pl'] = "Zamów dziś {$OranjeMolen[$language]}, na {$dagt4[$time4_N_3days]['pl']} u siebie (w Polsce)!";
} 
else
{
 $wanneerArr['fr'] = "Commandez avant 18h00 {$OranjeMolen[$language]}, {$dagt4[$time4_N_2days]['fr']} chez vous (en France) !";
 $wanneerArr['de'] = "Bestellen Sie vor 18:00 Uhr {$OranjeMolen[$language]}, {$dagt4[$time4_N_2days]['de']} bei Ihnen (in Deutschland)!";
 $wanneerArr['it'] = "Ordina entro le 18:00 {$OranjeMolen[$language]}, per {$dagt4[$time4_N_3days]['it']} a casa tua (in Italia)!";
 $wanneerArr['es'] = "Pide antes de las 18:00 {$OranjeMolen[$language]}, {$dagt4[$time4_N_3days]['es']} en tu casa (en España)!";
 $wanneerArr['pt'] = "Peça antes das 18:00 {$OranjeMolen[$language]}, {$dagt4[$time4_N_3days]['pt']} em sua casa (em Portugal)!";
 $wanneerArr['da'] = "Bestil før kl. 18:00 {$OranjeMolen[$language]}, {$dagt4[$time4_N_2days]['da']} hos dig (i Danmark)!";
 $wanneerArr['pl'] = "Zamów przed 18:00 {$OranjeMolen[$language]}, na {$dagt4[$time4_N_3days]['pl']} u siebie (w Polsce)!";
}
 $wanneera = $wanneerArr[$language];
 $wanneerb = $wanneera;
}





//}
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
//LEES EERST WAT BOVEN AAN DE PAGINA STAAT!!!
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
//if ((date("d-m-Y")=="14-11-2018")||(date("d-m-Y")=="15-11-2018")||(date("d-m-Y")=="16-11-2018"))
//  $wanneerb = 'Stakingen bpost, volgende week in huis.';
//LET OP:
////////////////////////////////////////////
//date_stylesheet_real.inc
//geupdate voor Pasen 2016.
///////////////////////////////////////////
/*
if (date("d-m-Y") == "24-12-2021")
{
  $wanneera = 'Bestel voor 14.00u, maandag of dinsdag in huis.';
  $wanneerb = 'Bestel voor 14.00u, maandag of dinsdag in huis.';	
	if ($today['weekday'] == 'Friday')
	 $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+4, date("Y")));
  else
	 $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+3, date("Y")));	
}*/
if (date("d-m-Y") == "07-01-2026")
{
  if ($today['hours'] < 18)
  {
    $wanneera = 'Bestel vandaag voor 18.00u, brievenbuspakketten worden vandaag nog verzonden.';
    $wanneerb = 'Bestel vandaag, GLS pakketten worden morgen weer verzonden.';
    $wanneerc = 'Bestel vandaag, overmorgen in huis.';
  }
}/*
if (date("d-m-Y") == "01-04-2024")
{
  $wanneera = 'Bestel vandaag, overmorgen in huis.';
  $wanneerb = 'Bestel vandaag, overmorgen in huis.';
  $wanneerc = 'Bestel vandaag, overmorgen in huis.';
  $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+4, date("Y")));	
}*/


//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
//LEES EERST WAT BOVEN AAN DE PAGINA STAAT!!!
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
/*

*/
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
//LEES EERST WAT BOVEN AAN DE PAGINA STAAT!!!
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!

//KERST
if ((date("d-m-Y") == "23-12-2025")&&($today['hours'] > 18))
{
  $wanneera = 'Bestel vanavond, zaterdag in huis.';
  $wanneerb = 'Bestel vanavond, zaterdag in huis.';
  $wanneerc = 'Bestel vanavond, maandag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+9, date("Y")));	
}
elseif ((date("d-m-Y") == "24-12-2025")&&($today['hours'] < 16)) //dag voor kerst
{
  $wanneera = 'Bestel vandaag voor 16.00u, zaterdag in huis.';
  $wanneerb = 'Bestel vandaag voor 16.00u, zaterdag in huis.';
  $wanneerc = 'Bestel vandaag voor 16.00u, maandag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+9, date("Y")));	
}
elseif ((date("d-m-Y") == "24-12-2025")||(date("d-m-Y") == "25-12-2025")) //dag voor kerst of eerste kerst dag
{
  $wanneera = 'Bestel vandaag, maandag in huis.';
  $wanneerb = 'Bestel vandaag, maandag in huis.';
  $wanneerc = 'Bestel vandaag, dinsdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif ((date("d-m-Y") == "26-12-2025")&&($today['hours'] < 18)) //2de kerstdag
{
  $wanneera = 'Bestel vandaag, maandag in huis.';
  $wanneerb = 'Bestel vandaag, maandag in huis.';
  $wanneerc = 'Bestel vandaag, dinsdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+5, date("Y")));	
}

//Nieuwjaar
if ((date("d-m-Y") == "30-12-2025")&&($today['hours'] > 18)) //oudjaar 
{
  $wanneera = 'Bestel vanavond, vrijdag in huis.';
  $wanneerb = 'Bestel vanavond, vrijdag in huis.';
  $wanneerc = 'Bestel vanavond, vrijdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif ((date("d-m-Y") == "31-12-2025")&&($today['hours'] < 16)) //oudjaar 
{
  $wanneera = 'Bestel vandaag voor 16.00u, vrijdag in huis.';
  $wanneerb = 'Bestel vandaag voor 16.00u, vrijdag in huis.';
  $wanneerc = 'Bestel vandaag voor 16.00u, vrijdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif (date("d-m-Y") == "31-12-2025")
{
  $wanneera = 'Bestel vandaag, zaterdag in huis.';
  $wanneerb = 'Bestel vandaag, zaterdag in huis.';
  $wanneerc = 'Bestel vandaag, zaterdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif ((date("d-m-Y") == "01-01-2026")&&($today['hours'] < 18)) 
{
  $wanneera = 'Bestel vandaag, overmorgen in huis.';
  $wanneerb = 'Bestel vandaag, overmorgen in huis.';
  $wanneerc = 'Bestel vandaag, overmorgen in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+5, date("Y")));	
}

/*
//NEDERLAND PASEN 2023
if (($today['mday'] == 15)&&($today['mon'] == 4)&&($today['hours'] > 17)) //vrijdag avond Pasen
{
  $wanneera = 'Bestel vanavond, woensdag in huis.';
  $wanneerb = 'Bestel vanavond, woensdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif ((($today['mday'] == 16)||($today['mday'] == 17))&&($today['mon'] == 4)) //zaterdag voor Pasen of zondag
{
  $wanneera = 'Bestel vandaag, woensdag in huis.';
  $wanneerb = 'Bestel vandaag, woensdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 18)&&($today['mon'] == 4)) //maandag 2de paasdag
{
  $wanneera = 'Bestel vandaag, overmorgen in huis.';
  $wanneerb = 'Bestel vandaag, overmorgen in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+6, date("Y")));	
}

//Koningsdag 2023 
elseif (($today['mday'] == 25)&&($today['mon'] == 4)&&($today['hours'] > 17)) //di avond
{
  $wanneera = 'Bestel vanavond, vrijdag in huis.';
  $wanneerb = 'Bestel vanavond, vrijdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 26)&&($today['mon'] == 4)&&($today['hours'] < 18)) //wo overdag
{
  $wanneera = 'Bestel vandaag, overmorgen in huis.';
  $wanneerb = 'Bestel vandaag, overmorgen in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 26)&&($today['mon'] == 4)&&($today['hours'] > 17)) //wo avond
{
  $wanneera = 'Bestel vanavond, zaterdag in huis.';
  $wanneerb = 'Bestel vanavond, zaterdag in huis.';
      $wanneerc = 'Bestel vanavond, maandag in huis (in Belgi&euml;).';	
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 27)&&($today['mon'] == 4)) //do koningsdag
{
  $wanneera = 'Bestel vandaag, overmorgen in huis.';
  $wanneerb = 'Bestel vandaag, overmorgen in huis.';
        $wanneerc = 'Bestel vandaag, maandag in huis (in Belgi&euml;).';		
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+6, date("Y")));	
}

//hemelvaart 2024
elseif (($today['mday'] == 7)&&($today['mon'] == 5)&&($today['hours'] > 17)) //dinsdag avond
{
  $wanneera = 'Bestel vanavond, vrijdag in huis.';
  $wanneerb = 'Bestel vanavond, vrijdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 8)&&($today['mon'] == 5)&&($today['hours'] < 18)) //woensdag overdag
{
  $wanneera = 'Bestel vandaag, overmorgen in huis.';
  $wanneerb = 'Bestel vandaag, overmorgen in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 8)&&($today['mon'] == 5)&&($today['hours'] > 17)) //woensdag avond
{
  $wanneera = 'Bestel vanavond, zaterdag in huis.';
  $wanneerb = 'Bestel vanavond, zaterdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 9)&&($today['mon'] == 5)) //donderdag hemelvaart
{
  $wanneera = 'Bestel vandaag, overmorgen in huis.';
  $wanneerb = 'Bestel vandaag, overmorgen in huis.';	
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+6, date("Y")));	
}

//pinksterdag 17 mei 2024
elseif (($today['mday'] == 17)&&($today['mon'] == 5)&&($today['hours'] > 17)) //vrijdag avond en zaterdag voor pinksteren 
{
  $wanneera = 'Bestel vanavond, dinsdag in huis.';
  $wanneerb = 'Bestel vanavond, dinsdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));
	  $wanneerc = 'Bestel vanavond, woensdag in huis (in Belgi&euml;).';		
}
elseif (($today['mday'] == 18)&&($today['mon'] == 5))
{
	  $wanneera = 'Bestel vandaag, dinsdag in huis.';
  $wanneerb = 'Bestel vandaag, dinsdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));
	  $wanneerc = 'Bestel vandaag, woensdag in huis (in Belgi&euml;).';	
}
elseif (($today['mday'] == 19)&&($today['mon'] == 5)) //pinksteren zondag
{
  $wanneera = 'Bestel vandaag, overmorgen in huis.';
  $wanneerb = 'Bestel vandaag, overmorgen in huis.';
  $wanneerc = 'Bestel vandaag, woensdag in huis (in Belgi&euml;).';	
  $YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+6, date("Y")));	
}
elseif (($today['mday'] == 20)&&($today['mon'] == 5)&&($today['hours'] < 18)) //maandag 2de pinksterdag
{
  $wanneera = 'Bestel vandaag voor 17.00u, morgen in huis.';
  $wanneerb = 'Bestel vandaag voor 17.00u, morgen in huis.';
        $wanneerc = 'Bestel vandaag, overmorgen in huis (in Belgi&euml;).';	
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+5, date("Y")));	
}

*/
//ECHO "(".$today['hours']." < 18) && (".$today['year']." == '2020')&& (".$today['month']." == '10')&& (".$today['day']." == '16')";
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
//LEES EERST WAT BOVEN AAN DE PAGINA STAAT!!!
//!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
/*
//BELGIE Pasen 2022
if (($today['mday'] == 14)&&($today['mon'] == 4)&&($today['hours'] > 15)) //donderdag avond pasen 
{
  $wanneerc = 'Bestel vandaag, dinsdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif (($today['mday'] == 15)&&($today['mon'] == 4)&&($today['hours'] < 16)) //vrijdag overdag pasen 
{
  $wanneerc = 'Bestel vandaag, dinsdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif (($today['mday'] == 16)&&($today['mon'] == 4)&&($today['hours'] > 15)) //vrijdag avond Pasen
{
  $wanneerc = 'Bestel vanavond, woensdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif ((($today['mday'] == 17)||($today['mday'] == 24))&&($today['mon'] == 4)) //zaterdag voor Pasen of zondag
{
  $wanneerc = 'Bestel vandaag, woensdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 18)&&($today['mon'] == 4)) //maandag 2de paasdag
{
  $wanneerc = 'Bestel vandaag, overmorgen in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+6, date("Y")));	
}
//Koningsdag 2022 BE 
elseif (($today['mday'] == 26)&&($today['mon'] == 4)&&($today['hours'] > 15)) //dinsdag avond
{
  $wanneerc = 'Bestel vanavond, vrijdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 27)&&($today['mon'] == 4)) //woensdag koningsdag
{
  $wanneerc = 'Bestel vandaag, overmorgen in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+6, date("Y")));	
}
//hemelvaart 2022
elseif (($today['mday'] == 24)&&($today['mon'] == 5)&&($today['hours'] > 15)) //dinsdag avond
{
  $wanneerc = 'Bestel vanavond, vrijdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 25)&&($today['mon'] == 5)&&($today['hours'] < 16)) //woensdag overdag
{
  $wanneerc = 'Bestel vandaag, overmorgen in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 25)&&($today['mon'] == 5)&&($today['hours'] > 15)) //woensdag avond
{
  $wanneerc = 'Bestel vanavond, maandag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 26)&&($today['mon'] == 5)) //donderdag hemelvaart
{
  $wanneerc = 'Bestel vandaag, maandag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+6, date("Y")));	
}

//pinksterdag 6 juni 2022
elseif (($today['mday'] == 2)&&($today['mon'] == 6)&&($today['hours'] > 15)) //donderdag avond pinksteren 
{
  $wanneerc = 'Bestel vandaag, dinsdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif (($today['mday'] == 3)&&($today['mon'] == 6)&&($today['hours'] < 16)) //vrijdag overdag pinksteren 
{
  $wanneerc = 'Bestel vandaag, dinsdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif (($today['mday'] == 3)&&($today['mon'] == 6)&&($today['hours'] > 15)) //vrijdag avond pinksteren 
{
  $wanneerc = 'Bestel vanavond, woensdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+8, date("Y")));	
}
elseif ((($today['mday'] == 4)||($today['mday'] == 5))&&($today['mon'] == 6)) //zaterdag voor pinksteren of zondag
{
  $wanneerc = 'Bestel vandaag, woensdag in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+7, date("Y")));	
}
elseif (($today['mday'] == 6)&&($today['mon'] == 6)) //maandag 2de pinksterdag
{
  $wanneerc = 'Bestel vandaag, overmorgen in huis.';
	$YYYY_MM_DDgoogle_optin = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d")+6, date("Y")));	
}
*/
?>