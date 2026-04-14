<?php
//overzicht
//in create-page.php staat: include "/include/iframeChatbot.php";
///include/iframeChatbot.php maakt de iframe met ChatBotMrM.php erin
//ChatBotMrM.php voor form. Verstuurt bericht naar de server (ChatGPTMrM.php) via AJAX
//ChatGPTMrM.php stuurt de berichten door naar de functie CHATGPT ();

error_reporting(-1); //show errors
include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatFunction.php";
include_once $_SERVER['DOCUMENT_ROOT']."/include/db.inc";
$conn = new PDO('mysql:host='.$dbhost.';dbname='.$dbname.';charset=utf8mb4', $dbuser, $dbpass);
error_reporting(-1); //show errors
$temp = 0.6; $jsonData = ''; $user = ''; $system1 = '';

//////////////////////////////////////////////////
//POST
if ( ($_SERVER["REQUEST_METHOD"] == "POST") && (isset($_POST['user'])) )
{
 // Haal de waarden uit de text area's op
 $user = $_POST['user']; //bericht
 if (isset($_POST['page']))
 	$page = $_POST['page']; //locatie van de chatbot
 else
 	$page = 'via a href';
 
 if ($user != 'wacht op 2de bericht')
     $userHTML = "<div class='chat-message user'><p>$user<span class='message-time'>".date("H:i")."</span></p></div>";
 else
     $userHTML = ''; 

 /////////////////////////////////////////////////
 //functie
 include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/perfectLink.php";
 
 	 
 //////////////////////////////////////////////////
 //Cookie & database
 $cookie_name = "chatbot_session";
 //Prepare Update
 $sql = "UPDATE chatHistory SET conversationHTML = ? WHERE cookie = ?";
 $stmtUpdateHTML = $conn->prepare($sql);

 if (!isset($_COOKIE[$cookie_name])) // Controleer of het cookie al bestaat
 {
   /////////////////////////////////////////////////
   //eerste bericht!
    
    $cookie_value = bin2hex(random_bytes(16)); // Unieke 32-karakter string
    setcookie($cookie_name, $cookie_value, time() + 3600, "/"); // 1 jaar geldig	
    
    $conversationHTML = $userHTML;

    $sql = "INSERT INTO chatHistory (cookie, conversationJSON, conversationHTML, page_info) 
            VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array($cookie_value, '', $conversationHTML, $page));
 } 
 else 
 {
   /////////////////////////////////////////////////
   //SELECT berichten
 	
   $cookie_value = $_COOKIE[$cookie_name];    // Haal bestaande cookie-waarde op
   setcookie($cookie_name, $cookie_value, time() + 3600, "/"); //cookie verversen
    
   $sql = "SELECT * FROM chatHistory WHERE cookie = ?";
   $stmt = $conn->prepare($sql);
   $stmt->execute(array($cookie_value));
   $chatHistoryResult = $stmt->fetch();
   
   if (isset($chatHistoryResult['conversationHTML']))
   {
     $conversationHTML = $chatHistoryResult['conversationHTML'].$userHTML;
     $conversationHistoryArray = json_decode($chatHistoryResult['conversationJSON'], true);
   }
   else
   {
   	    echo "<div class='chat-message system'>
        <p>Hier gaat iets mis. Kan chat geschiedenis niet ophalen. <span class='message-time'>".date("H:i")."</span></p></div>";
   	 
   }
   $stmtUpdateHTML->execute(array($conversationHTML, $cookie_value)); 
 }

// echo $userHTML;

   if ($user != 'wacht op 2de bericht')
   {
    ///////////////////////////////////////////
    //$system0 schrijven om $system1 samen te stellen. $system1 gaat de vraag beantwoorden
    include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/system0.php";
    if (isset($conversationHistoryArray)) $input0 = $conversationHistoryArray;
    $input0[]['user'] = $user;
    $stmtUpdateHTML->execute(array($conversationHTML, $cookie_value));
    $assistant0 = CHATGPT(json_encode($input0), $system0, 0.2, 3);
    
    if (preg_match('/^2001:1c00:bd0e:7700:/', $_SERVER['REMOTE_ADDR']))
    	   	echo "<div class='chat-message system'>
        <p>$assistant0<span class='message-time'>".date("H:i")."</span></p></div>";
   }
   else $assistant0 = '';
	
    ///////////////////////////////////////////////
    //Email sturen naar klantenservice
    if (preg_match("/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i", $user, $matches))
    {
        $emailadresGebruiker = $matches[1];
        include_once $_SERVER['DOCUMENT_ROOT']."/include/mijnemail_universeel.inc";
        $to = $mailadres;
        $subject = "Doorgestuurd door MrM ($emailadresGebruiker)";
        $msg = print_r ($conversationHistoryArray,1);
        if (mail($to, $subject, $msg, $headers))
        	$systemMsg = "<div class='chat-message system'>
        <p>Email naar klantenservice verzonden!<span class='message-time'>".date("H:i")."</span></p></div>";
        else
        	$systemMsg = "<div class='chat-message system'>
        <p>Error: Email verzenden mislukt.<span class='message-time'>".date("H:i")."</span></p></div>";
        
        $conversationHTML .= $systemMsg;
        $stmtUpdateHTML->execute(array($conversationHTML, $cookie_value));
        echo $systemMsg;
    }
    
    ////////////////////////
    //$system1 opbouwen voor daadwerkelijke antwoord
    //tone of voice
    include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/mrM.php"; //$systemMrmPersoonlijk & $systemMrM
    $system1 = $systemMrM;
    
    //achtergrond info van MrM
    if (preg_match ("/Persoonlijk/i", $assistant0))
      $system1 .= $systemMrmPersoonlijk;
    
    
    //platform?
    $platformArray = ["Switch", "Wii U", "3DS", "Wii", "DS", "GC", "GBA", "N64", "SNES"];
    $platform = '';

	foreach ($platformArray as $value) 
	{
    	if (preg_match("/$value/i", $assistant0)) 
    	{
        	$platform = $value;
            break; // Stop de loop zodra er een match is gevonden
	}	}
	if ($platform == '')
		$platform = $univ_one;
    
    //Verkoop advies
    if (preg_match ("/ProductFinder/i", $assistant0)){
    

      include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/VerkoopAdvies3.php";	
      $system1 .= $systemAdviesVragen;}
    
    if (preg_match ("/ProductList/i", $assistant0))
    {
      if (preg_match ("/Switch/i", $assistant0))
      {
      	include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/ProductList.php";
      	$system1 .= $systemList;
	  }	
    }
    
    //FAQ ophalen
    if (preg_match ("/Aankoop/i", $assistant0) == 1)
    {
    	include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/aankoop.php";
    	include_once $_SERVER['DOCUMENT_ROOT']."/include/time4.inc";
        $system1 .= '
<b>B. Zo werken wij</b>
Wij staan voor Fantastisch Tweedehands. Zo mooi dat je het zonder problemen cadeau kunt geven. Goed voor het milieu, lage prijzen en erg veel keuze. Wat niet meer nieuw te koop is hebben wij wel nog op voorraad!

Je kunt het gesprek eindigen met een call to action. Voor klanten binnen Nederland: '.$wanneera.'
';
	}
    if (preg_match ("/Zending/i", $assistant0) == 1)
    {
    	include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/zending.php";
	}
    if (preg_match ("/Inkoop/i", $assistant0) == 1)
    {
    	include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/inkoop.php";
    	    $system1 .= '
<b>B. Zo werken wij</b>
Zo werken wij: Jij geeft op wat je wilt verkopen in ons inkoopsysteem ('.$univ_web.'/inkoop1.php). Wij laten zien wat we er voor mogen geven.
Direct verwerken op afspraak, afgeven zonder afspraak of opsturen kan pas nadat stap 3 is doorlopen in het inkoopsysteem.
';    
	}
    if (preg_match ("/Loyaliteit/i", $assistant0) == 1)
    {
    	include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/loyaliteit.php";
$system1 .= '
<b>B. Zo doe je het goed</b>
Wanneer een gebruiker geintresseerd is in het Helden programma (informatie toevoegen aan product pagina\'s), probeer je de gebruiker te laten starten. Je kunt hem of haar doorverwijzen naar de zoekresultaten van een titel die de gebruiker heeft gespeeld. De zoekresultaten vind je hier: '.$univ_web.'/'.$univ_zoeken.'?search=
Bijvoorbeeld: Misschien wil je een beoordeling toevoegen voor <a href = "https://www.'.$univ_web.'/'.$univ_zoeken.'?search=Mario+Kart">Mario Kart</a>?
';
	}
    if (preg_match ("/Service/i", $assistant0) == 1)
    {
    	include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/service.php";
    	    $system1 .= '
<b>B. Zo werken wij</b>

Als een klant aangeeft dat een product niet werkt of wilt terug sturen proberen we eerst te achterhalen wat de reden is. Als de reden is dat het product niet goed werkt proberen we er achter te komen of de klant alles goed heeft gedaan. Door de juiste vragen te stellen en advies te bieden kunnen we het probleem vaak op afstand oplossen en zo het aantal onnodige retouren verminderen. In de FAQ hieronder vind je mogelijke oplossingen.

Wanneer de reden van de klant een van de volgende is: dubbel besteld, verkeerd besteld, niet meer nodig, niet leuk, persoonlijk, etc. Geef dan ons adres aan de klant en vraag of ze het op eigen kosten terug willen sturen. Je kunt erbij zeggen dat ze dan hun volledige aankoopbedrag terug krijgen. Zorg dat de klant niet vergeet zijn gegevens bij de retour zending te vermelden.

Wanneer de klant aangeeft dat het probleem niet is opgelost kun je het volgende doen om de klant te helpen. Je vraagt of je een Fantastische collega mag mailen zodat die een gratis retour zending kan aanmaken. Als de klant dat goed vindt moet hij of zij wel even zijn eigen email adres aan jou vertellen.

Wanneer een gebruiker zijn email adres geeft in een bericht wordt er direct automatisch een mail gestuurd naar onze klantenservice. Die zal dan de vervolg stappen zetten om er bijvoorbeeld voor te zorgen dat de bestelling gratis retour kan worden gestuurd.

Voorbeeld 1 van een goed gesprek:
Gebruiker: Hoi, ik wil iets terug sturen, naar welk adres moet dat?
Assistant: Fantastisch dat je even contact met me opneemt! Hebben wij iets verkeerd gedaan of is er iets stuk? Of heb je misschien per ongeluk de verkeerde game besteld? Ik help je graag verder!
Gebruiker: De game wil niet opstarten
Assistant: Oepsie, dat is zeker niet de bedoeling. Excuus voor het ongemak. Wat is de titel van de game? Is het voor de '.$univ_one.'? Dan kan ik even kijken wat de oplossing is.<br>Misschien kunnen we het probleem samen oplossen!
Gebruiker: Het is voor de Mario Kart voor de N64.
Assistant: Fantastisch, laten we eens even kijken wat we kunnen doen! Zou je dit willen proberen?<br><br>[oplossingen]<br><br>Als dat niet werkt, kunnen we verder kijken naar een andere oplossing. Laat me weten of dit helpt!
Gebruiker: Bedankt voor het wachten, het werkt helaas niet.
Assistant: Oepsie, dat is jammer om te horen. Geen zorgen, we gaan ervoor zorgen dat je geholpen wordt! Mag ik een Fantastische collega mailen zodat we Mario Kart snel en gratis kunnen omruilen? Ik heb hiervoor wel even het emailadres nodig dat je gebruikt hebt bij het plaatsen van de bestelling. Laat maar weten of je dat wilt!
Gebruiker: Ja graag, olyvers@gmail.com
Assistant: Fantastisch, ik heb de email gestuurd! Mijn collega zal je snel een mail sturen om de gratis retourzending in orde te maken. Bedankt voor je geduld en als je nog meer vragen hebt, sta ik altijd klaar om te helpen!

Voorbeeld 2 van een goed gesprek:
Gebruiker: Ik wil een game terug sturen. Hoe werkt dat?
Assistant: Fantastich dat je even contact met me opneemt. Hebben wij iets verkeerd gedaan? Is er iets stuk?
Gebruiker: Nee, jullie zijn fantastisch! Ik heb per ongeluk het verkeerde besteld.
Assistant: Oepsie, maar geen enkel probleem natuurlijk. Zou je het willen opsturen naar '.$univ_mar.', Pampuslaan 180, 1382 JS Weesp. Vergeet niet duidelijk de afzender te vermelden en aan te geven dat het niet stuk is, anders maken wij ons zorgen dat er wat mis mee is als het bij ons binnen komt. Heb ik je zo goed geholpen?

Voorbeeld 3 van een goed gesprek:
Gebruiker: Wat is jullie adres?
Assistant: Fantastisch dat je even contact met me opneemt!<br>Wil je iets terug sturen? Wil je langs komen om iets op te halen?

Onderstaande FAQ kan je helpen om mogelijke oplossingen te bieden voor wanneer een product niet lijkt te werken.
';
	} 
    ///////////////////////////////////////////////
    //alle FAQ combineren     //$FAQ['aankoop'][7]['text']
    $system1 .= '
    <b>C. FAQ op onze website</b>';
    if (isset($FAQ) && is_array($FAQ)) 
    {
      foreach ($FAQ as $keyOnderwerp => $valueArray)
      {
    	foreach ($valueArray as $key => $tonenSiteTextArr)
    	{
    		if (($tonenSiteTextArr['site'] == $platform)||($tonenSiteTextArr['site'] == 'All'))
    			$system1 .= $tonenSiteTextArr['text'];
	} }	}

    //////////////////////////
    //contact pagina
    include_once $_SERVER['DOCUMENT_ROOT']."/include/mijnemail_universeel.inc";
    $bodymain3 = '';
    include_once $_SERVER['DOCUMENT_ROOT']."/include/contact.inc"; //$bodymain3

    $system1 .= '
<b>D. Contact gegevens op onze website</b>
Nooit meteen ons adres geven: De informatie bij langskomen moet eerst uitgelegd worden. Betreft een retour zending? Dan reden van retour vragen. 
Nooit direct ons telefoonnummer of email adres geven. Eerst vragen waar het over gaat.

Hieronder onze contactgegevens voor als je deze echt moet geven:

'.$bodymain3.'
';

$dateN = date("N"); 
$maandN = date("n"); 

$dag = [1 => 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];
$maand = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

$system1 .=  '
<b>E. Vandaag</b>
Het is nu ' . $dag[$dateN] . ' ' . date("j") . ' ' . $maand[$maandN] . ' ' . date("Y G:i") . '. Tijdens onze openingstijden wordt de mail en whatsapp meestal beantwoord binnen 2 uur.
';


    ///////////////////////////////////////////
    //vraag ChatGPT
    $sql = "UPDATE chatHistory SET conversationJSON = ?, conversationHTML = ? WHERE cookie = ?";
    $stmtUpdateBoth = $conn->prepare($sql);
    if (($user != '')&&($user != 'wacht op 2de bericht'))
    {
       //function CHATGPT($input, $systemContent, $temperature = 0.6, $model = "gpt-4o")
       if (isset($conversationHistoryArray))
	     $assistant = CHATGPT($user, $system1, $temp, 3, $conversationHistoryArray, 1);
       else
       	  $assistant = CHATGPT($user, $system1, $temp, 3);
       
       $assistant = perfectLink($assistant);
       
	   $conversationHistoryArray[]['user'] = $user;
       $conversationHistoryArray[]['assistant'] = $assistant;

        $assistant = str_replace('<p>', '', $assistant);// Vervang de opening <p> tags door een lege string
    	$assistant = str_replace('</p>', '<br><br>', $assistant);// Vervang de sluitende </p> tags door <br><br> voor dubbele regelafstand
   		$assistant = preg_replace('/(<br><br>)+$/', '', $assistant);// Verwijder eventuele extra <br><br> aan het einde

       $assistantMsg = "<div class='chat-message bot'><p>$assistant<span class='message-time'>".date("H:i")."</span></p></div>";
       $conversationHTML .= $assistantMsg;
       $stmtUpdateBoth->execute(array(json_encode($conversationHistoryArray), $conversationHTML, $cookie_value)); 
       echo $assistantMsg;
	}
	elseif ($user == 'wacht op 2de bericht')
	{
       	//verkoopadvies stap 2
       	include_once $_SERVER['DOCUMENT_ROOT']."/include/ChatGPT/ProductList.php";
       	$system2 = $systemMrM.$systemList;
       	$assistant = CHATGPT('Welke games raad je me aan om te kopen op basis van de antwoorden die ik heb gegeven? Games die ik leuk vind heb ik al gekocht.', 
       	                      $system2, $temp, 3, $conversationHistoryArray, 1);
        
        $assistant = perfectLink($assistant);
        
        $conversationHistoryArray[]['assistant'] = $assistant;
        $assistantMsg2 = "<div class='chat-message bot'><p>$assistant<span class='message-time'>".date("H:i")."</span></p></div>";
        $conversationHTML .= $assistantMsg2;
        $stmtUpdateBoth->execute(array(json_encode($conversationHistoryArray), $conversationHTML, $cookie_value)); 
        echo $assistantMsg2;
	}
    else
	   echo 'ERROR: user input was leeg.';   
    }


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear') {
    // Genereer een nieuwe cookie-waarde
    $cookie_value = bin2hex(random_bytes(16)); // Nieuwe unieke waarde
    setcookie("chatbot_session", $cookie_value, time() -1, "/"); // Cookie verwijderen
    
    echo  "<div class='chat-message system'>
            <p>De chat is gewist. Je kunt opnieuw beginnen!<span class='message-time'>".date("H:i")."</span></p>
        </div>";
}

?>