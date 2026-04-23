<?php error_reporting(-1); //show errors
//overzicht
//in create-page.php staat: include "/include/iframeChatbot.php";
///include/iframeChatbot.php maakt de iframe met ChatBotMrM.php erin
//ChatBotMrM.php voor form. Verstuurt bericht naar de server (ChatGPTMrM.php) via AJAX
//ChatGPTMrM.php stuurt de berichten door naar de functie CHATGPT ();
?>
<!DOCTYPE html>
<html lang="nl">

<head>
    <link href="https://fonts.googleapis.com/css?family=Josefin+Sans" rel="stylesheet">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatbot Mr M</title>
    <style>
        /* Algemene styling */
        html {
            height: 100%;
        }

        body {
            margin: 0;
            font-family: "Josefin Sans", sans-serif;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            overflow: hidden;
            box-sizing: border-box;
            padding-top: env(safe-area-inset-top, 0);
            padding-bottom: env(safe-area-inset-bottom, 0);
            /* Voorkom scrollen van het hele lichaam */
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
            z-index: 1000;
            /* Zorg dat het plaatje boven andere elementen komt */
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
            min-height: 0;
            /* Zorg dat het berichtenvenster goed werkt met flexbox */
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
            position: sticky;
            bottom: 0;
            z-index: 10;
        }

        .chat-input form {
            display: flex;
            width: 100%;
            gap: 10px;
            align-items: flex-end;
        }

        .chat-input textarea {
            flex: 1;
            resize: none;
            border: 1px solid #0093e1;
            border-radius: 5px;
            padding: 10px;
            font-size: 14px;
            font-family: sans-serif;
            min-height: 42px;
            box-sizing: border-box;
        }

        .chat-input button {
            padding: 10px 20px;
            background-color: #00d501;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            font-family: "Josefin Sans", sans-serif;
            border: 1px solid #0093e1;
            /* Blauw randje */
            color: white;
        }

        .chat-input button:hover {
            background-color: #ff84c5;
        }


        #clear-chat:hover,
        #toggle-chat:hover {
            background-color: #f1f1f1;
            /* Zachte hover-kleur */
            color: #000;
            /* Donkere tekst bij hover */
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

            0%,
            20% {
                opacity: 0;
            }

            50% {
                opacity: 1;
            }
        }

        /* Op mobiel geven we de berichten meer breedte en houden we de invoerbalk zichtbaar. */
        @media (max-width: 768px) {
            .chat-header {
                padding: 10px 12px;
            }

            .chat-header img {
                width: 42px;
                height: 42px;
                margin-right: 10px;
                transform: none;
            }

            .chat-header h1 {
                font-size: 18px;
            }

            .chat-header p {
                font-size: 11px;
            }

            .chat-messages {
                padding: 10px;
            }

            .chat-message.user,
            .chat-message.bot,
            .chat-message.system {
                max-width: 88%;
            }

            .chat-input {
                padding: 8px;
                padding-bottom: calc(8px + env(safe-area-inset-bottom, 0));
            }

            .chat-input form {
                gap: 8px;
            }

            .chat-input textarea {
                font-size: 16px;
            }

            .chat-input button {
                padding: 10px 14px;
                font-size: 14px;
                white-space: nowrap;
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
        include_once $_SERVER['DOCUMENT_ROOT'] . "/include/db.inc";
        error_reporting(-1); //show errors
        if (isset($_COOKIE['chatbot_session'])) {
            /////////////////////////////////////////////////
            //SELECT berichten	
            $cookie_value = $_COOKIE["chatbot_session"];    // Haal bestaande cookie-waarde op
            $sql = "SELECT * FROM chatHistory WHERE cookie = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute(array($cookie_value));
            $chatHistoryResult = $stmt->fetch();

            $conversationHTML = $chatHistoryResult['conversationHTML'];
            echo $conversationHTML;
        } else
            echo  "<div class='chat-message system'>
            <p>Nieuw: Aankoophulp, kletsen over games en antwoord op je vragen! <span class='message-time'>" . date("H:i") . "</span></p>
        </div>";
        ?>
    </div>

    <!-- Invoerveld -->
    <div class="chat-input">
        <form action="ChatGptMrM.php" id="chat-form" method="POST">
            <textarea id="user-input" name="user" placeholder="Typ hier je bericht..." rows="1" required style="flex: 1;"></textarea>
            <input type='hidden' name='page' id='page' value='<?php echo $_SERVER['REQUEST_URI']; ?>'>
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

        // Hiermee lezen we een bestaande cookie uit de browser.
        function getCookieValue(name) {
            const cookieParts = document.cookie.split('; ');

            for (const cookiePart of cookieParts) {
                const [cookieName, cookieValue] = cookiePart.split('=');
                if (cookieName === name) {
                    return decodeURIComponent(cookieValue || '');
                }
            }

            return '';
        }

        // Elke bezoeker heeft 1 vaste chat-cookie nodig.
        // Zo weten frontend en backend over welk gesprek het gaat.
        function ensureChatSessionCookie() {
            let cookieValue = getCookieValue('chatbot_session');

            if (cookieValue !== '') {
                return cookieValue;
            }

            // We maken zelf een cookie aan zodat de queue en frontend dezelfde bezoeker herkennen.
            cookieValue = 'chat-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
            document.cookie = `chatbot_session=${encodeURIComponent(cookieValue)}; path=/; max-age=31536000; SameSite=Lax`;
            return cookieValue;
        }

        // Deze helper maakt een normaal chatbericht in het scherm.
        function createMessageElement(type, text) {
            const wrapper = document.createElement('div');
            wrapper.className = `chat-message ${type}`;

            const paragraph = document.createElement('p');
            paragraph.textContent = text;

            const time = document.createElement('span');
            time.className = 'message-time';
            const now = new Date();
            time.textContent = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');

            paragraph.appendChild(time);
            wrapper.appendChild(paragraph);

            return wrapper;
        }

        // Dit is het simpele laadblokje met de drie puntjes.
        function createTypingIndicator() {
            const typingIndicator = document.createElement('div');
            typingIndicator.className = 'chat-message bot typing-indicator';
            typingIndicator.innerHTML = `
        <p>Mr M: <span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></p>
    `;

            return typingIndicator;
        }


        document.addEventListener('DOMContentLoaded', function() {

            // Deze functie vraagt elke paar seconden:
            // "Is het antwoord al klaar?"
            function startPolling(berichtId, typingIndicator) {
                const chatMessages = document.getElementById('chatMessages');
                const sessionCookie = ensureChatSessionCookie();
                let pollingTimer = null;
                let aantalPogingen = 0;

                // Hiermee stoppen we de polling zodra we een eindstatus hebben.
                const stopPolling = () => {
                    if (pollingTimer !== null) {
                        clearInterval(pollingTimer);
                        pollingTimer = null;
                    }
                };

                const haalStatusOp = async () => {
                    aantalPogingen += 1;

                    try {
                        // We vragen de nieuwste status van dit bericht op.
                        const response = await fetch(`/api/chat/status?bericht_id=${encodeURIComponent(berichtId)}&cookie=${encodeURIComponent(sessionCookie)}`, {
                            method: 'GET',
                            credentials: 'same-origin'
                        });
                        const data = await response.json();

                        if (!response.ok || data.status !== 'succes') {
                            throw new Error('Status ophalen mislukt.');
                        }

                        const queueStatus = data.bericht.queue_status;
                        const aiResponse = data.bericht.ai_response || '';

                        // Zolang de worker nog bezig is, doen we nog niets.
                        // De laad-animatie blijft dan gewoon zichtbaar.
                        if (queueStatus === 'pending' || queueStatus === 'processing') {
                            return;
                        }

                        stopPolling();

                        // Nu halen we het laadblokje weg, want er is een eindstatus.
                        if (typingIndicator && typingIndicator.parentNode) {
                            typingIndicator.parentNode.removeChild(typingIndicator);
                        }

                        // Bij completed tonen we het echte AI-antwoord.
                        // Bij error tonen we een veilige algemene foutmelding.
                        if (queueStatus === 'completed') {
                            chatMessages.appendChild(createMessageElement('bot', aiResponse));
                        } else if (queueStatus === 'error') {
                            chatMessages.appendChild(createMessageElement('system', 'Er ging iets mis bij het ophalen van het antwoord. Probeer het later opnieuw.'));
                        } else {
                            chatMessages.appendChild(createMessageElement('system', 'Onbekende berichtstatus ontvangen.'));
                        }

                        scrollToBottom();
                    } catch (error) {
                        // Als status ophalen meerdere keren mislukt, stoppen we netjes.
                        if (aantalPogingen < 3) {
                            return;
                        }

                        stopPolling();

                        if (typingIndicator && typingIndicator.parentNode) {
                            typingIndicator.parentNode.removeChild(typingIndicator);
                        }

                        chatMessages.appendChild(createMessageElement('system', 'Er ging iets mis bij het ophalen van het antwoord. Probeer het later opnieuw.'));
                        scrollToBottom();
                    }
                };

                // Meteen 1 keer proberen en daarna elke 2 seconden opnieuw.
                haalStatusOp();
                pollingTimer = setInterval(haalStatusOp, 2000);
            }

            // Deze functie verwerkt 1 nieuw bericht van de gebruiker.
            async function processMessage(message) {
                const chatMessages = document.getElementById('chatMessages');
                const sessionCookie = ensureChatSessionCookie();

                if (message !== 'wacht op 2de bericht') {
                    chatMessages.appendChild(createMessageElement('user', message));
                    // Scroll naar beneden
                    scrollToBottom();
                }

                // Tijdens het wachten laten we de drie puntjes zien.
                let typingIndicator = null;
                const typingIndicatorTimeout = setTimeout(() => {
                    typingIndicator = createTypingIndicator();
                    chatMessages.appendChild(typingIndicator);
                    scrollToBottom();
                }, 250);

                try {
                    // Hier slaan we het bericht eerst op in de queue op.
                    const response = await fetch('/api/chat/send', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            cookie: sessionCookie,
                            user_message: message
                        })
                    });
                    const data = await response.json();

                    clearTimeout(typingIndicatorTimeout);

                    if (!response.ok || data.status !== 'succes') {
                        throw new Error('Bericht opslaan mislukt.');
                    }

                    if (!typingIndicator) {
                        typingIndicator = createTypingIndicator();
                        chatMessages.appendChild(typingIndicator);
                    }

                    // Vanaf hier wachten we niet op het antwoord.
                    // In plaats daarvan gaan we pollen op de status.
                    startPolling(data.bericht_id, typingIndicator);
                    scrollToBottom();
                } catch (error) {
                    clearTimeout(typingIndicatorTimeout);

                    if (typingIndicator && typingIndicator.parentNode) {
                        typingIndicator.parentNode.removeChild(typingIndicator);
                    }

                    chatMessages.appendChild(createMessageElement('system', 'Er ging iets mis bij het versturen van het bericht. Probeer het later opnieuw.'));
                    scrollToBottom();
                }
            }


            // Hiermee kan ook de pagina buiten de iframe een vraag doorsturen.
            window.addEventListener('message', function(event) {
                if (event.data.type === "question") {
                    processMessage(event.data.text); // Verwerk de ontvangen vraag
                }
            });

            // Dit verstuurt het bericht uit het tekstvak.
            document.getElementById('chat-form').addEventListener('submit', function(e) {
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
            menuDots.addEventListener('click', function() {
                if (menuOptions.style.display === 'none' || menuOptions.style.display === '') {
                    menuOptions.style.display = 'block';
                } else {
                    menuOptions.style.display = 'none';
                }
            });

            // Verberg menu als je ergens anders klikt
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#menu-container')) {
                    menuOptions.style.display = 'none';
                }
            });

            // Wissen van de chat
            clearChatButton.addEventListener('click', async function() {
                const chatMessages = document.getElementById('chatMessages');

                try {
                    const response = await fetch('ChatGptMrM.php', {
                        method: 'POST',
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            action: 'clear'
                        })
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
            window.parent.postMessage({
                type: 'toggleChatbot'
            }, '*');
        }
    </script>

</body>

</html>