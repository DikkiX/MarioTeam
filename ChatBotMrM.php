<?php error_reporting(-1); //show errors
//overzicht
//in create-page.php staat: include "/include/iframeChatbot.php";
///include/iframeChatbot.php maakt de iframe met ChatBotMrM.php erin
//ChatBotMrM.php voor form. Verstuurt bericht naar de server (ChatGPTMrM.php) via AJAX
//ChatGPTMrM.php stuurt de berichten door naar de functie CHATGPT ();
?><!DOCTYPE html>
<html lang="nl">
<head>
    <link href="https://fonts.googleapis.com/css?family=Josefin+Sans" rel="stylesheet">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatbot Mr M</title>
    <style>
        /* Algemene styling */
        body {
            margin: 0;
            font-family: "Josefin Sans", sans-serif;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden; /* Voorkom scrollen van het hele lichaam */
        }

        /* Kop met afbeelding en titel */
        .chat-header {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            background-color: #ffc901;
            border-bottom: 1px solid #0093e1;
            position: relative;
        }

        .chat-header img {
            width: 50px;
            height: 50px;
            margin-right: 15px;
            border-radius: 50%;
            transform: scale(1.4);
            z-index: 1000; /* Zorg dat het plaatje boven andere elementen komt */
        }

        .chat-header h1 {
            font-size: 20px;
            margin: 0;
        }

        .chat-header p {
            font-size: 12px;
            color: #888;
            margin: 0;
        }

        /* Berichtenvenster */
        .chat-messages {
            flex: 1 1 auto;
    		min-height: 0; /* Zorg dat het berichtenvenster goed werkt met flexbox */
            padding: 20px;
            overflow-y: auto;
            background-color: #fafafa;
        }

        .chat-message.user {
            text-align: right;
        }

        .chat-message.bot {
            text-align: left;
        }
        
        .chat-message.system {
            text-align: center;
        }

        .chat-message p {
            margin: 4px 0 4px 0;
            border: 0;
            padding: 0;
		}

		.message-time {
    		position: absolute;
    		bottom: 5px;
    		right: 10px;
   		 	font-size: 12px;
   		 	color: #888;
		}
/*
        .chat-message.user p {
            background-color: #00d501;
            color: #000;
            border: 1px solid #0093e1;
        }

        .chat-message.bot p {
            background-color: #ffc901;
            color: #000;
            border: 1px solid #0093e1;
        }
      
        .chat-message.system p {
            background-color: #ff84c5;
            color: #000;
            border: 1px solid #0093e1;
            
        }  */

/* Algemene container voor alle berichten */
.chat-messages {
    display: flex;
    flex-direction: column; 
    gap: 10px; 
    overflow-y: auto;
    padding: 10px;
}

/* Gebruikersberichten: Rechts uitlijnen */
.chat-message.user {
    display: flex;
    justify-content: flex-end; 
    align-items: flex-start;
    background-color: #00d501;
    color: #000; 
    border: 1px solid #0093e1;
    padding: 10px 15px 20px 15px;
    border-radius: 10px;
    max-width: 70%; 
    align-self: flex-end;
    position: relative;
}

/* Botberichten: Links uitlijnen */
.chat-message.bot {
    display: flex;
    justify-content: flex-start;
    align-items: flex-start;
    flex-direction: column;
    background-color: #ffc901;
    color: #000;
    border: 1px solid #0093e1;
    padding: 10px 15px 20px 15px;
    border-radius: 10px;
    max-width: 70%;
    align-self: flex-start;
    position: relative;
}
.chat-message.system {
    display: flex;
    justify-content: center; 
    align-items: center; 
    background-color: #ff84c5; 
    color: #000; 
    border: 1px solid #0093e1; 
    padding: 10px 15px 20px 15px;
    border-radius: 10px; 
    max-width: 70%; 
    margin: 10px auto; 
    text-align: center; 
    position: relative;
}


        /* Invoerveld onderaan */
        .chat-input {
            display: flex;
            padding: 10px;
            background-color: #f1f1f1;
            border-top: 1px solid #0093e1;
            flex-shrink: 0;
        }

        .chat-input textarea {
            flex: 1;
            resize: none;
            border: 1px solid #0093e1;
            border-radius: 5px;
            padding: 10px;
            font-size: 14px;
            font-family: sans-serif;
        }

        .chat-input button {
            margin-left: 10px;
            padding: 10px 20px;
            background-color: #00d501;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            font-family: "Josefin Sans", sans-serif;
            border: 1px solid #0093e1; /* Blauw randje */
    		color: white;
        }

        .chat-input button:hover {
            background-color: #ff84c5;
        }
        

    #clear-chat:hover,
    #toggle-chat:hover {
        background-color: #f1f1f1; /* Zachte hover-kleur */
        color: #000; /* Donkere tekst bij hover */
    }   
        
  
    #menu-container {
        position: relative;
        margin-left: auto;
        cursor: pointer;
    }


    #menu-options {
        display: none;
        position: absolute;
        width: 100px;
        right: 0;
        top: 30px;
        background: #fff;
        border: 1px solid #ccc;
        border-radius: 5px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        z-index: 1000;
    }

        
        #menu-dots {
    font-size: 24px;
    line-height: 24px;
    color: #555;
    cursor: pointer;
    user-select: none;
}

#menu-dots:hover {
    color: #000;
}

#menu-options button {
    padding: 10px;
    width: 100%;
    text-align: left;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 14px;
    color: #555;
}

#menu-options button:hover {
    background-color: #f1f1f1;
    color: #000;
    border-radius: 5px;
}

/* Aan het typen ... */
.typing-indicator {
    font-style: italic;
    color: gray;
    margin: 10px 0;
}
.typing-indicator .dot {
    animation: blink 1.5s infinite;
}
.typing-indicator .dot:nth-child(2) {
    animation-delay: 0.2s;
}
.typing-indicator .dot:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes blink {
    0%, 20% {
        opacity: 0;
    }
    50% {
        opacity: 1;
    }
}

    </style>
</head>
<body>

<!-- Kop -->
<div class="chat-header">
    <img src="https://www.marioswitch.nl/Plaatjes/Mr-M/Mr-M-Klantenservice-ChatBot.png" alt="Mr M">
    <div>
        <h1>Chatbot Mr M</h1>
        <p>Powered by ChatGPT.<br>Kan ook fouten maken: Net echt!</p>
    </div>
    <div id="menu-container">
        <div id="menu-dots">&#x22EE;</div>
        <div id="menu-options">
            <button id="toggle-chat" onclick="toggleParentIframe()">Verberg chat</button>
            <button id="clear-chat">Chat wissen</button>
        </div>
    </div>
</div>


<!-- Berichtenvenster -->
<div class="chat-messages" id="chatMessages">
<?php
include_once $_SERVER['DOCUMENT_ROOT']."/include/db.inc"; error_reporting(-1); //show errors
if (isset($_COOKIE['chatbot_session']))  
{
   /////////////////////////////////////////////////
   //SELECT berichten	
   $cookie_value = $_COOKIE["chatbot_session"];    // Haal bestaande cookie-waarde op
   $sql = "SELECT * FROM chatHistory WHERE cookie = ?";
   $stmt = $conn->prepare($sql);
   $stmt->execute(array($cookie_value));
   $chatHistoryResult = $stmt->fetch();
   
   $conversationHTML = $chatHistoryResult['conversationHTML'];
   echo $conversationHTML;
}
else
	    echo  "<div class='chat-message system'>
            <p>Nieuw: Aankoophulp, kletsen over games en antwoord op je vragen! <span class='message-time'>".date("H:i")."</span></p>
        </div>";
?>
</div>

<!-- Invoerveld -->
<div class="chat-input">
    <form action="ChatGptMrM.php"  id="chat-form" method="POST" style="display: flex; width: 100%;">
        <textarea id = "user-input" name="user" placeholder="Typ hier je bericht..." rows="1" required style="flex: 1;"></textarea>
        <input type = 'hidden' name = 'page' id = 'page' value = '<?php echo $_SERVER['REQUEST_URI']; ?>'>
        <button type="submit">Verstuur</button>
    </form>
</div>

<script>

function scrollToBottom() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTo({
            top: chatMessages.scrollHeight,
            behavior: 'smooth' // Zorgt voor vloeiend scrollen
        });
    }
}


document.addEventListener('DOMContentLoaded', function () {

    // Algemene functie om berichten te verwerken
    async function processMessage(message) {
        const chatMessages = document.getElementById('chatMessages');
        
        if (message !== 'wacht op 2de bericht')
        {
        // Voeg het gebruikersbericht toe aan de chatgeschiedenis
        const now = new Date();
        const hours = now.getHours().toString().padStart(2, '0');
        const minutes = now.getMinutes().toString().padStart(2, '0');

        chatMessages.innerHTML += `
            <div class='chat-message user'>
                <p>${message}<span class='message-time'>${hours}:${minutes}</span></p>
            </div>
        `;

        // Scroll naar beneden
        scrollToBottom();
		}

    // Start de timer voor de "aan het typen"-indicator
    let typingIndicator;
    let typingIndicatorTimeout = setTimeout(() => {
        // Voeg de "aan het typen"-indicator toe
        typingIndicator = document.createElement('div');
        typingIndicator.className = 'chat-message bot typing-indicator';
        typingIndicator.innerHTML = `
            <p>Mr M: <span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></p>
        `;
        chatMessages.appendChild(typingIndicator);
        scrollToBottom();
    }, 250); // Vertraging van 250 ms

    // Verstuur bericht naar de server via AJAX
    const pageValue = document.getElementById('page').value;
try {
    const response = await fetch('ChatGptMrM.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ user: message, page: pageValue })
    });

        // AJAX-response ontvangen, dus clear de timer voor de typing-indicator
        clearTimeout(typingIndicatorTimeout);

        // Als de typing-indicator al is toegevoegd, verwijder deze
        if (typingIndicator) {
            chatMessages.removeChild(typingIndicator);
        }

        // Ontvang het antwoord van de server
        const serverReply = await response.text();

            // Voeg het antwoord van de chatbot toe aan de chatgeschiedenis
            chatMessages.innerHTML += serverReply;
            scrollToBottom();
            
            
        // Controleer of het antwoord specifieke berichten bevat
        const specificMessages = [
            'Ik heb antwoord op al mijn vragen.',
            'Ik ga nu opzoek naar de perfecte games.'
        ];
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = serverReply;
        const botMessage = tempDiv.textContent || tempDiv.innerText;
        const shouldSendAdditionalMessage = specificMessages.some(specificMessage => botMessage.includes(specificMessage));
        if (shouldSendAdditionalMessage && message !== 'wacht op 2de bericht') {
            await processMessage('wacht op 2de bericht');
        }
            
         //einde controlle   
        } catch (error) {
            console.error('Fout bij het verwerken van het bericht:', error);
            chatMessages.innerHTML += `
                <div class='chat-message system'>
                    <p>Er ging iets mis bij het verwerken van het bericht. Probeer het later opnieuw.</p>
                </div>
            `;
            scrollToBottom();
        }
    }


    // Luister naar berichten van het hoofdvenster
    window.addEventListener('message', function (event) {
        if (event.data.type === "question") {
            processMessage(event.data.text); // Verwerk de ontvangen vraag
        }
    });

    // Verwerk berichten uit het invoerveld
    document.getElementById('chat-form').addEventListener('submit', function (e) {
        e.preventDefault(); // Voorkom pagina herladen

        const userMessage = document.getElementById('user-input').value.trim();
        if (userMessage !== '') {
            processMessage(userMessage); // Verwerk de ingevoerde vraag
            document.getElementById('user-input').value = ''; // Wis het tekstvak
        }
});



    //////////////////////////////////////////////
    // Verwijder de chatgeschiedenis van de frontend
    const menuDots = document.getElementById('menu-dots');
    const menuOptions = document.getElementById('menu-options');
    const clearChatButton = document.getElementById('clear-chat');

    // Toggle menu bij klikken op de drie puntjes
    menuDots.addEventListener('click', function () {
        if (menuOptions.style.display === 'none' || menuOptions.style.display === '') {
            menuOptions.style.display = 'block';
        } else {
            menuOptions.style.display = 'none';
        }
    });

    // Verberg menu als je ergens anders klikt
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#menu-container')) {
            menuOptions.style.display = 'none';
        }
    });

    // Wissen van de chat
    clearChatButton.addEventListener('click', async function () {
        const chatMessages = document.getElementById('chatMessages');

        try {
            const response = await fetch('ChatGptMrM.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'clear' })
            });

            const serverReply = await response.text();
            chatMessages.innerHTML = serverReply;
            scrollToBottom();
        } catch (error) {
            console.error('Fout bij het wissen van de chat:', error);
        }
    });
    
        // Scroll naar beneden zodra de pagina is geladen
    setTimeout(scrollToBottom, 3000); // Vertraging van 2000 ms
});

    function toggleParentIframe() {
        // Stuur een bericht naar de ouderpagina om de iframe te toggelen
        window.parent.postMessage({ type: 'toggleChatbot' }, '*');
    }
</script>

</body>
</html>
