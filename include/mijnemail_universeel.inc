<?php
$headers = 'FROM: '.$univ_mar.' <info@'.$univ_web.'>'."\r\n".'Reply-To: '.$univ_mar.' <info@'.$univ_web.'>'."\r\n"."Date: ".date("r (T)")." \r\n".'X-Mailer: PHP/' . phpversion();
$mailadres = 'info@'.$univ_web;

if (isset ($naamafzender) == 0)
 $naamafzender = 'Wibert, Alex, Tim, Jelle, Ramon, Dorus, Roemer, Morris, Eline, Hind, Ilias, Jade, Dennis en Fiene'; //wordt ook gebruikt in function_whoisin.inc

include_once $_SERVER['DOCUMENT_ROOT'].'/'."include/links.inc";
 
//$arraywebsites = array ("MarioWiiU.nl", "Mario3DS.nl", "MarioWii.nl", "MarioDS.nl", "MarioCube.nl", "MarioGBA.nl", "Mario64.nl");
$onzewebsites = '';
foreach ($LinksIncWeb as $key => $value)
{
 if ($value != $univ_web_text)
 {
  if ($onzewebsites != '')
	  $onzewebsites .= ' | ';
	$onzewebsites .= 'www.'.$value; 
}}

$afsluitingemail = "Groet!\n$naamafzender\nwww.$univ_web_text\n$onzewebsites\n\n";
$Ternamevan = "Mario Team bv";     //gebruikt op bb.php 6-1-2015
$Ternamevankort = "Mario Team bv"; //deze verwijderen in de toekomst 6-1-2015

//BCC: voor inzendingen beoordelen
$BCCmail = 'whuberts@mariowii.nl';

//Universeel:
//$myip = "static.kpn.net"; //volmertstr 14
//$myip2 = "j237058.upc-j.chello.nl"; //thuis
//$myip = "g191084.upc-g.chello.nl"; //Claudia
//$myip3 = 'ip565ddeab.direct-adsl.nl'; //
$myrem = '92.108.85.32'; //kantoor Nijverheidslaan Ziggo1
$myrem2 = '2001:1c00:bd0e:7700'; //kantoor Nijverheidslaan Ziggo1
//$myrem2 = '80.60.38.187'; //kantoor Pampuslaan sept
$mijnrekeningnummer = "NL68 INGB 0004493097 (ING)"; // niet meer gebruiken
$mijnbanknr = " NL68 INGB 0004493097 "; //niet meer gebruiken
$mijntelefoon = "0203680529";
$mijntelb = "+31203680529";
//$mijnmobiel = '0687768987';
$mijnmobiel = '0642384387';
//$mijnmobielb = '+31687768987';
$mijnmobielb = '+31642384387';
$mijnadresvol = "Nijverheidslaan 6V, 1382 LH Weesp, Nederland";
$mijnadres = "Nijverheidslaan 6V, 1382 LH Weesp";
$mijnadresgnl = "Nijverheidslaan 6V, 1382 LH Weesp";
$mijnadreszin = "Nijverheidslaan 6V in Weesp";
$mijnadresbr = "Nijverheidslaan 6V<br>1382 LH Weesp";
$mijnpostcode = '1382LH'; //voor marktplaats

$iban = "NL68 INGB 0004493097";
$bic = "INGBNL2A";

$kvk = '86241214';
$btwnr = 'NL863906771B01';

$euro10 = "12,50 euro"; //waarde van 1000 muntjes

//wanneer bellen
//voor contact:
$cantacttijden1 = "<table class = 'norm' border = '0'><tr><td>ma:<td>10.30u - 18.00u<tr><td>di:<td>10.30u - 18.00u<tr><td>wo:<td>10.30u - 18.00u<tr><td>do:<td>10.30u - 18.00u<tr><td>vr:<td>10.30u - 18.00u<tr><td>za:<td><center>&nbsp;- gesloten -&nbsp;</center><tr><td>zo:<td>&nbsp;12.00u - 18.00u</table>";
$cantacttijden2 = "ma t/m vr 10.00 - 18.00u, za gesloten en zo 12.00 - 18.00u";

$date52 = date("d-m-Y");

//zomer
if (time() < 1724241600)
{
	$cantacttijden1 = "<table class = 'norm' border = '0'><tr><td>ma:<td>10.30u - 18.00u<tr><td>di:<td>12.00u - 18.00u<tr><td>wo:<td>10.00u - 18.00u<tr><td>do:<td>10.00u - 18.00u<tr><td>vr:<td>10.00u - 18.00u<tr><td>za:<td>&nbsp;Gesloten<tr><td>zo:<td>&nbsp;12.00u - 18.00u</table>";
$cantacttijden2 = "ma t/m vr 10.30 - 18.00u, za gesloten en zo 12.00 - 18.00u";
}

//Kerst
if ((date("d-m-Y") == "27-12-2025")||(date("d-m-Y") == "28-12-2025")||(date("d-m-Y") == "22-12-2025")||(date("d-m-Y") == "23-12-2025")
	||(date("d-m-Y") == "24-12-2025")||(date("d-m-Y") == "25-12-2025")||(date("d-m-Y") == "26-12-2025"))
{ 
$cantacttijden1 = "<table class = 'norm' border = '0'><tr><td>ma:<td>&nbsp;10.00u - 18.00u<tr><td>di:<td>&nbsp;10.00u - 18.00u<tr><td>wo:<td>&nbsp;10.00u - 16.00u<tr><td>1e kerst:<td><center>&nbsp;- gesloten -&nbsp;</center><tr><td>2e kerst:<td><center>&nbsp;- gesloten -&nbsp;</center><tr><td>za:<td>&nbsp;12.00u - 17.00u<tr><td>zo:<td>&nbsp;12.00u - 18.00u</table>";
$cantacttijden2 = "ma t/m vr 10.00 - 18.00u, kerst gesloten, za & zo 12.00 - 17.00u";
}

//oud en nieuw
if ((date("d-m-Y") == "27-12-2025")||(date("d-m-Y") == "28-12-2025")||(date("d-m-Y") == "29-12-2025")||(date("d-m-Y") == "30-12-2025")
	||(date("d-m-Y") == "31-12-2025")
    ||(date("d-m-Y") == "01-01-2026")||(date("d-m-Y") == "02-01-2026")) //LET OP JAAR!
{ 
$cantacttijden1 = "<table class = 'norm' border = '0'><tr><td>ma:<td>&nbsp;10.30u - 18.00u<tr><td>di:<td>&nbsp;10.30u - 18.00u<tr><td>wo:<td>&nbsp;10.30u - 16.00u<tr><td>1 jan:<td>&nbsp;- gesloten -&nbsp;<tr><td>vr:<td>&nbsp;10.30u - 18.00u<tr><td>za:<td>&nbsp;- gesloten -&nbsp;<tr><td>zo:<td>&nbsp;12.00u - 18.00u</table>";
$cantacttijden2 = "ma t/m vr 10.00 - 18.00u, 1 januari gesloten, za gesloten en zo 12.00 - 18.00u";
}

/*


//Pasen
if ((date("d-m-Y") == "29-03-2024")||(date("d-m-Y") == "30-03-2024")||(date("d-m-Y") == "31-03-2024")||(date("d-m-Y") == "01-04-2024"))
{ 
 $cantacttijden1 = "<table class = 'norm' border = '0'><tr><td>ma:<td>&nbsp; 2e Paasdag Gesloten<tr><td>di:<td>10.00u - 18.00u<tr><td>wo:<td>10.00u - 18.00u<tr><td>do:<td>10.00u - 18.00u<tr><td>vr:<td>10.00u - 18.00u<tr><td>za:<td>&nbsp;Gesloten<tr><td>zo:<td>&nbsp; 1e Paasdag Gesloten</table>";
 $cantacttijden2 = "2e Paasdag gesloten, di t/m vr 10.00 - 18.00u, za en zo gesloten";
}

//Koningsdag
if ((date("d-m-Y") == "28-04-2023") || (date("d-m-Y") == "25-04-2023") || (date("d-m-Y") == "26-04-2023") || (date("d-m-Y") == "27-04-2023"))
{ 
 $cantacttijden1 = "<table class = 'norm' border = '0'><tr><td>ma:<td>10.00u - 18.00u<tr><td>di:<td>10.00u - 18.00u<tr><td>wo:<td>10.00u - 18.00u<tr><td>do:<td>&nbsp;Koningsdag gesloten<tr><td>vr:<td>12.00u - 18.00u<tr><td>za:<td>&nbsp;Gesloten<tr><td>zo:<td>&nbsp;14.00u - 18.00u</table>";
 $cantacttijden2 = "Koningsdag gesloten, ma t/m wo 10.00 - 18.00u, vr 12.00 - 18.00, za gesloten en zo 14.00 - 18.00u";
}

//Hemelvaartsdag
if ((date("d-m-Y") == "06-05-2024") || (date("d-m-Y") == "07-05-2024") || (date("d-m-Y") == "08-05-2024") || (date("d-m-Y") == "09-05-2024"))
{ 
 $cantacttijden1 = "<table class = 'norm' border = '0'><tr><td>ma:<td>10.00u - 18.00u<tr><td>di:<td>10.00u - 18.00u<tr><td>wo:<td>10.00u - 18.00u<tr><td>Hemelvaart:<td>10.30u - 16.00u<tr><td>vr:<td>10.00u - 18.00u<tr><td>za:<td>&nbsp;Gesloten<tr><td>zo:<td>&nbsp;12.00u - 18.00u</table>";
 $cantacttijden2 = "Hemelvaartsdag 10.30 - 16.00u, ma t/m vr 10.00 - 18.00u, za gesloten en zo 12.00 - 18.00u";
}

//Pinksteren
if ((date("d-m-Y") == "16-05-2024")||(date("d-m-Y") == "17-05-2024") || (date("d-m-Y") == "18-05-2024") || (date("d-m-Y") == "19-05-2024") || (date("d-m-Y") == "20-05-2024"))
{ 
 $cantacttijden1 = "<table class = 'norm' border = '0'><tr><td>Pinksteren:<td>12.00u - 16.00u<tr><td>di:<td>10.00u - 18.00u<tr><td>wo:<td>10.00u - 18.00u<tr><td>do:<td>10.00u - 18.00u<tr><td>vr:<td>10.00u - 18.00u<tr><td>za:<td>&nbsp;Gesloten<tr><td>Pinksteren:<td>12.00u - 17.00u</table>";
 $cantacttijden2 = "1e Pinksterdag 12.00 - 17.00u, 2e Pinksterdag 10.00 - 16.00u, di t/m vr 10.00 - 18.00u";
}
*/
//hieronder niet aanpassen.
$ip = gethostbyaddr($_SERVER['REMOTE_ADDR']);
?>