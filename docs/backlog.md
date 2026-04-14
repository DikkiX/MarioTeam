# Product Backlog: Context-Aware Service Agent

**US01: Als ontwikkelaar wil ik een beveiligde lokale projectomgeving met versiebeheer opzetten, zodat API-sleutels nooit per ongeluk online komen te staan.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * Project is lokaal geïnitialiseerd met Git.
  * Er is een `.env` bestand aanwezig voor gevoelige data zoals database-logins en OpenAI keys.
  * Het `.env` bestand staat genoteerd in de `.gitignore` zodat deze nooit naar GitHub wordt gepusht.
* Taken:
  * Git repo aanmaken.
  * `.gitignore` en `.env` aanmaken en configureren.
* Testen:
  * Doe een test-push naar de remote repository en check online of het `.env` bestand daar inderdaad ontbreekt.

**US02: Als ontwikkelaar wil ik een automatische deployment (CI/CD) opzetten via GitHub Actions, zodat nieuwe code automatisch naar de FTP test-server wordt gestuurd bij een update.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * Er is een deployment workflow bestand aanwezig in de repository.
  * De workflow triggert automatisch bij een push naar de main branch.
  * De FTP inloggegevens staan veilig opgeslagen als GitHub Secrets.
  * De code wordt automatisch overschreven op de test-server.
* Taken:
  * GitHub Secrets aanmaken voor FTP data.
  * Een YAML workflow bestand aanmaken met een standaard FTP deploy action.
* Testen:
  * Doe een kleine aanpassing, push de code en controleer via de browser of de wijziging live staat op de test-server.

**US03: Als ontwikkelaar wil ik een veilige database-connectie (PDO) en de chat_queue tabel opzetten, zodat de applicatie inkomende berichten kan opslaan.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * Er is een PHP-script dat een veilige connectie maakt via PDO.
  * Inloggegevens worden uit het `.env` bestand gehaald.
  * Tabel `chat_queue` is aangemaakt met kolommen: id, cookie, user_message, ai_response, status, created_at, updated_at.
  * Kolom status heeft standaard de waarde 'pending'.
* Taken:
  * SQL script schrijven en uitvoeren in database.
  * PDO connectie script bouwen inclusief try-catch foutafhandeling waarbij inloggegevens verborgen blijven.
* Testen:
  * Verander tijdelijk het wachtwoord in `.env` naar iets verkeerds, laad de pagina in de browser en check of de veilige foutmelding verschijnt zonder dat data lekt.

**US04: Als ontwikkelaar wil ik de email_concepten tabel in de database aanmaken, zodat door AI gegenereerde e-mail concepten veilig opgeslagen kunnen worden.**
* Prioriteit: High (Should Have)
* Acceptatiecriteria:
  * Tabel `email_concepten` is aangemaakt.
  * Kolommen: id, gmail_thread_id, klant_email, concept_tekst, status, created_at, updated_at.
  * Tabel staat los van chat-tabellen (geen foreign keys).
* Taken:
  * SQL script schrijven met de juiste datatypes.
  * Uitvoeren op de test-server.
* Testen:
  * Controleer in phpMyAdmin of de tabel en kolommen kloppen met het ontwerp.

**US05: Als ontwikkelaar wil ik het Middleware API endpoint (/api/chat/send) bouwen, zodat inkomende chatberichten direct in de wachtrij worden gezet.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * PHP-script luistert naar POST-verzoeken op `/api/chat/send`.
  * Accepteert JSON-data met `cookie` en `user_message`.
  * Slaat het bericht via PDO op in `chat_queue` met status 'pending'.
  * Retourneert direct een JSON-response (bijv. status: "succes").
* Taken:
  * POST afvangen en input valideren.
  * PDO INSERT query schrijven.
  * JSON HTTP-headers instellen en response geven.
* Testen:
  * Stuur een POST-verzoek via Postman, check of de JSON-response direct terugkomt en of het bericht succesvol in de database staat als 'pending'.

**US06: Als ontwikkelaar wil ik een fire-and-forget trigger inbouwen in de Middleware API, zodat het script het achtergrondproces start zonder de webshop te laten wachten.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * Endpoint `/api/chat/send` roept na de INSERT de Worker aan.
  * Signaal is non-blocking: PHP-script wacht niet op reactie van de Worker.
  * Frontend krijgt direct de JSON-response terug.
* Taken:
  * Non-blocking trigger schrijven (afhankelijk van One.com restricties).
  * Inbouwen en voorzien van foutafhandeling.
* Testen:
  * Stuur een testbericht, check de responstijd om zeker te weten dat deze niet blijft hangen op het AI-proces, en controleer of de Worker is geactiveerd.

**US07: Als ontwikkelaar wil ik een Worker-script bouwen dat de wachtrij uitleest, zodat klantberichten worden opgepakt voor AI-verwerking.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * Los PHP-script scant op 'pending' berichten in `chat_queue`.
  * Oudste bericht wordt als eerste geselecteerd.
  * Status van dit bericht verandert direct in 'processing'.
* Taken:
  * PDO SELECT query voor ophalen oudste rij.
  * PDO UPDATE query om status op 'processing' te zetten.
  * Script sluit netjes als er geen pending rijen zijn.
* Testen:
  * Plaats een testbericht op pending, draai het Worker-script handmatig en check of de status succesvol is veranderd in processing.

**US08: Als ontwikkelaar wil ik OpenAI en Function Calling integreren in de Worker, zodat de AI actuele database-informatie kan opvragen.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * Worker stuurt bericht veilig naar OpenAI.
  * Bevat de vereiste JSON-structuur voor Function Calling.
  * Bij een functie-aanroep voert Worker de bijbehorende PDO-query uit en stuurt de actuele data terug naar OpenAI.
* Taken:
  * OpenAI API-call logica programmeren.
  * Functies definiëren en if/else afhandeling bouwen voor het opvangen van functie-verzoeken.
  * PDO-queries schrijven voor de interne data-ophaling.
* Testen:
  * Zet een vraag in de database die specifieke orderdata vereist, voer de Worker uit en controleer via logs of de functie met succes is getriggerd.

**US09: Als ontwikkelaar wil ik dat de Worker het definitieve AI-antwoord opslaat in de database, zodat de chat klaarstaat voor de frontend.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * Worker vangt definitieve antwoord af van OpenAI.
  * Updatet de `ai_response` in de database.
  * Status verandert naar 'completed' of naar 'error' bij falen.
* Taken:
  * OpenAI tekst extraheren.
  * PDO UPDATE query schrijven voor het antwoord en de eindstatus.
  * Try-catch foutafhandeling rondom OpenAI-call.
* Testen:
  * Draai de Worker, controleer of de kolom ai_response gevuld is en status completed is. Verbreek daarna internet, draai opnieuw en controleer of de status op error springt.

**US10: Als ontwikkelaar wil ik een polling-mechanisme in het chatvenster inbouwen, zodat voltooide AI-antwoorden op het scherm verschijnen.**
* Prioriteit: Highest (Must Have)
* Acceptatiecriteria:
  * Nieuw API endpoint (GET `/api/chat/status`) checkt berichtstatus.
  * JavaScript blijft pollen totdat status wijzigt van pending/processing.
  * Bij status 'completed' toont frontend het antwoord en stopt polling.
  * Bij status 'error' toont frontend een foutmelding en stopt polling.
* Taken:
  * GET-route in PHP schrijven.
  * AJAX polling logica in de bestaande chatwidget integreren.
  * DOM-manipulatie voor inladen tekst of verwijderen laad-animatie.
* Testen:
  * Typ een vraag op de live pagina, controleer in de netwerk-tab of polling start en stop-moment klopt, en check of antwoord netjes inlaadt.

**US11: Als ontwikkelaar wil ik een koppeling maken met de Gmail API, zodat inkomende e-mails uitgelezen kunnen worden.**
* Prioriteit: High (Should Have)
* Acceptatiecriteria:
  * Veilige koppeling met Gmail API is gerealiseerd.
  * Tokens staan in `.env`.
  * Ongelezen e-mails, afzenders en thread-ID's kunnen worden opgehaald.
* Taken:
  * API instellen via Google Cloud.
  * PHP-script bouwen voor authenticatie en ophalen ongelezen mails.
* Testen:
  * Stuur een testmail en voer het script uit om te checken of de mail-inhoud correct in je terminal of browser wordt afgedrukt.

**US12: Als ontwikkelaar wil ik OpenAI koppelen aan de inkomende e-mails, zodat er automatisch concept-antwoorden worden opgeslagen.**
* Prioriteit: High (Should Have)
* Acceptatiecriteria:
  * Uitgelezen e-mail wordt doorgestuurd naar OpenAI.
  * Gegenereerde concept, thread-ID en afzender worden via PDO in `email_concepten` opgeslagen.
  * Status wordt standaard op 'draft' gezet.
* Taken:
  * OpenAI API-call scripten voor de mail-inhoud.
  * PDO INSERT query bouwen voor de email_concepten tabel.
* Testen:
  * Voer script uit met een testmail en controleer in de database of het concept daar netjes is opgeslagen met status draft.

**US13: Als medewerker wil ik een beheerdashboard (UI) hebben, zodat ik e-mail concepten kan inzien, aanpassen en versturen.**
* Prioriteit: High (Should Have)
* Acceptatiecriteria:
  * Webpagina toont lijst met openstaande 'draft' concepten.
  * Concepten kunnen bewerkt worden.
  * Knop verzendt de mail via Gmail API als antwoord in de originele thread.
  * Status verandert na verzenden naar 'sent'.
* Taken:
  * HTML/CSS interface bouwen.
  * PHP SELECT logica voor de weergave.
  * Gmail API verzend-functie integreren gekoppeld aan formulier.
  * PDO UPDATE query om status aan te passen.
* Testen:
  * Open het dashboard, pas een testconcept aan, verzend deze en controleer de daadwerkelijke inbox of de aangepaste tekst is gearriveerd en de database status is gewijzigd.