# Uitleg overzicht codebase Chatbot + E-maildashboard

Dit document legt simpel uit waar de belangrijkste onderdelen staan en wat ze doen.

Code-links in dit document zijn relatief (werken in GitHub én als je de repo lokaal opent).

## 1) Chatbot (huidige versie: queue/worker)

### Frontend (UI)
- Bestand: [ChatBotMrM.php](ChatBotMrM.php)
- Doet:
  - Toont chat UI.
  - Stuurt gebruikersbericht naar API: `POST /api/chat/send` ([ChatBotMrM.php:L679](ChatBotMrM.php#L679))
  - Pollt status/antwoord: `GET /api/chat/status` ([ChatBotMrM.php:L599](ChatBotMrM.php#L599))

### API endpoints
- Bestand: [send.php](api/chat/send.php)
  - Ontvangt `{cookie, user_message}`.
  - Schrijft een nieuw record in `chat_queue` met status `pending` ([send.php:L157](api/chat/send.php#L157)).
  - Start de worker (fire-and-forget) via [triggerWorkerOpAchtergrond](api/chat/send.php#L64).

- Bestand: [status.php](api/chat/status.php)
  - Geeft status + antwoord terug voor een bericht id + cookie.
  - Haalt status + antwoord uit `chat_queue` via SELECT ([status.php:L47](api/chat/status.php#L47)).
  - Frontend blijft poll’en tot `completed`.

- Bestand: [worker.php](api/chat/worker.php)
  - Pakt het oudste `pending` bericht, zet `processing`, maakt antwoord en zet `completed`.
  - Gebruikt dezelfde “oude volgorde” als de eerdere chatbot:
    1) system0 (onderwerp/platform bepalen)
    2) system1 opbouwen met FAQ/contact/today
    3) definitief antwoord maken
  - Kan ook interne tools gebruiken:
    - `zoek_productvoorraad` (voorraad/prijs/product info)
    - `zoek_bestelling` (order-status)
  - Order lookup helpers (gedeeld met EmailDashboard):
    - [bestelling_lookup.php](include/bestelling_lookup.php)
    - Worker gebruikt hiervoor de functie `zoekBestellingRuw(...)` uit dat bestand.
  - Regels:
    - Bij vragen over “op voorraad/prijs/beschikbaar” wordt tool-gebruik afgedwongen zodat de bot niet gaat gokken ([worker.php:L736](api/chat/worker.php#L736)).
  - Belangrijkste functies (handig om snel te vinden):
    - Berichten/prompt opbouw: [maakBerichtenVoorOpenAi](api/chat/worker.php#L608)
    - system0 label ophalen: [haalAssistant0VoorBericht](api/chat/worker.php#L497)
    - system1 bouwen via includes: [bouwSystem1MetIncludes](api/chat/worker.php#L550)
    - Tool forcing order lookup: [bepaalGeforceerdeToolChoice](api/chat/worker.php#L167)
    - OpenAI call (met tools): [roepOpenAiAan](api/chat/worker.php#L188)
    - OpenAI call (system0, zonder tone): [roepOpenAiAanZonderTone](api/chat/worker.php#L263)
    - Model mode mapping: [CHAT_MODEL_MODE handling](api/chat/worker.php#L211)

### Kennis / content (FAQ & tone of voice)
- Tone-of-voice:
  - [mrM.php](include/ChatGPT/mrM.php#L15)
- Onderwerp-classificatie (system0):
  - [system0.php](include/ChatGPT/system0.php#L4)
- FAQ content per onderwerp (vult `$FAQ[...]` arrays):
  - [aankoop.php](include/ChatGPT/aankoop.php)
  - [zending.php](include/ChatGPT/zending.php)
  - [service.php](include/ChatGPT/service.php)
  - [inkoop.php](include/ChatGPT/inkoop.php)
  - [loyaliteit.php](include/ChatGPT/loyaliteit.php)
  - (er bestaat ook [FAQ.php](include/ChatGPT/FAQ.php) als bundel/extra content)
- Contact gegevens (wordt als blok toegevoegd):
  - [contact.inc](include/contact.inc#L27)
- Openingstijden/“vandaag” helper:
  - [time4.inc](include/time4.inc#L25)

### Logs
- Worker log:
  - Pad staat in worker configuratie (meestal `storage/logs/chat_worker.log` of vergelijkbaar).
  - Handig om te checken of tools worden aangeroepen:
    - “Functie aangeroepen: zoek_productvoorraad”
    - “Functie aangeroepen: zoek_bestelling”

## 2) OpenAI aanroepen (modellen / instellingen)

### Env keys
- Bestand dat `.env` leest:
  - [env.php](include/env.php#L3) (functie `getProjectEnvValue($key)`)
- Verwachte keys:
  - `OPENAI_API_KEY`
  - `CHAT_MODEL_MODE` (model keuze mode)

### Model-keuze (zelfde logica als ChatFunction)
- In ChatFunction:
  - [ChatFunction.php](include/ChatFunction.php#L6) (functie `CHATGPT(...)`)
  - Default model parameter is `gpt-5-mini`.
  - Mode mapping:
    - `1` → `gpt-5.2`
    - `2` → `gpt-5-mini`
    - `3` → `gpt-4.1-mini`
- In worker:
  - [worker.php](api/chat/worker.php#L211)
  - Leest `CHAT_MODEL_MODE` en gebruikt dezelfde mapping.
  - Default is mode `2` (dus standaard `gpt-5-mini`), zodat het gelijk loopt met ChatFunction.

## 3) E-maildashboard (AI concepten)

- Bestand: [EmailDashboard.php](EmailDashboard.php)
- Doet:
  - Inloggen + basis beveiliging tegen nep-verzoeken.
  - Gmail token lezen/refreshen.
  - Mails ophalen + AI-concepten aanmaken + concepten beheren.

### Belangrijke begrippen (simpel uitgelegd)
- CSRF:
  - Dit is een aanval waarbij iemand jouw browser (terwijl je ingelogd bent) een actie laat doen.
  - Daarom zit er in elk formulier een verborgen token. Zonder dat token worden POST-acties geweigerd.
- “Worker”:
  - Dit is een stukje code dat mails ophaalt en concepten aanmaakt.
  - Op shared hosting kan dit niet echt 24/7 draaien; het draait alleen als er een request is.
- Gmail labels:
  - `UNREAD` + `INBOX` = de sync zoekt standaard alleen naar ongelezen inbox-mails.
  - `AI_CONCEPT` = label dat we op Gmail zetten zodra een mail is verwerkt, zodat we hem niet dubbel verwerken.

### Bijlagen & afbeeldingen in het dashboard (US24)
- Doel:
  - Bijlagen kunnen downloaden en afbeeldingen meteen zien, zonder Gmail te openen.
- Waar in de code:
  - Bijlages in een mail vinden: [haalBijlagesUitPayload](EmailDashboard.php#L651) (loopt door de mail “parts” heen).
  - `cid:` plaatjes in HTML fixen: [vervangCidSrcInHtml](EmailDashboard.php#L733)
  - HTML schoonmaken (veilig) en `<img>` toelaten: [sanitizeEmailHtmlVoorDashboard](EmailDashboard.php#L1198)
  - Bijlage ophalen/downloaden (aparte URL): [attachment endpoint](EmailDashboard.php#L2473)
  - Bijlagen tonen in “Gespreksgeschiedenis”: [thread render](EmailDashboard.php#L3453)
- Hoe het werkt (simpel):
  - Inline plaatjes (die in de mailtekst staan) worden in de tekst getoond, net als Gmail.
  - Losse bijlages blijven compact: je ziet een “documentje/knop” met naam + grootte, en je kunt downloaden.
  - De bijlage bytes worden pas opgehaald als je klikt (of als een inline plaatje laadt).

### OpenAI call voor e-mailconcepten
- Functie:
  - [roepOpenAiAanVoorEmailConcept](EmailDashboard.php#L1343) in [EmailDashboard.php](EmailDashboard.php)
- Model:
  - Gebruikt ook `CHAT_MODEL_MODE` uit `.env` (zelfde mapping als chat).
- Let op (verschil met chat):
  - De chat-worker kan tools/function-calling gebruiken (order/voorraad).
  - Het e-mailconcept gebruikt de centrale helper [ChatFunction.php](include/ChatFunction.php) (`CHATGPT(...)`) en maakt één antwoord per mail.
  - US23: voor ordervragen doet EmailDashboard eerst zelf een DB lookup (bestelnummer + email) en stuurt die feiten mee naar de AI:
    - Helpers: [bestelling_lookup.php](include/bestelling_lookup.php)
    - Extractie uit mail: [extracteerBestelEnEmailUitTekst](EmailDashboard.php#L1124)
    - AI call: [roepOpenAiAanVoorEmailConcept](EmailDashboard.php#L1343)

### System0 in e-mail (alleen als je het echt gebruikt)
- System0 is een extra AI-stap die een label teruggeeft zoals “Zending” of “Service”.
- Daarna kun je op basis van dat label alleen de juiste FAQ inladen (kortere prompt).
- System0 doet niet “automatisch” iets; je moet het label daarna zelf gebruiken in de code.

### Sync: waarom je soms niet “alle mails” ziet
- De sync zoekt standaard op `is:unread` en `labelIds=INBOX`.
- Als een mail al gelezen is gemaakt, of niet meer in INBOX zit, dan pakt de sync hem niet.
- De sync is nu gepagineerd (dus hij kan meer dan de nieuwste batch ophalen).

## 4) Database (belangrijkste tabellen)

- `chat_queue`:
  - Queue voor worker chat.
  - Bevat o.a. `cookie`, `user_message`, `ai_response`, `status`.
- `email_concepten`:
  - Concepten voor e-maildashboard.
- `dashboard_settings`:
  - Dashboard instellingen zoals `tone_of_voice`.

## 5) “Waar pas ik iets aan?”

- Bot zegt verkeerde bedrijfsinfo (retour/verzenden/openingstijden):
  - check de inhoud in [include/ChatGPT/](include/ChatGPT/) + [contact.inc](include/contact.inc#L27) + [time4.inc](include/time4.inc#L25)
  - check of system0 goed labelt in [system0.php](include/ChatGPT/system0.php#L4)
- Bot gokt over voorraad/prijs:
  - check worker log of `zoek_productvoorraad` wordt aangeroepen
  - check tool forcing in [worker.php:L736](api/chat/worker.php#L736)
- Model/kwaliteit aanpassen:
  - zet `CHAT_MODEL_MODE=1` in `.env` (5.2) of `2` (5-mini) of `3` (4.1-mini)

## 6) Deploy checks

- Workflow bestand:
  - [deploy.yml](.github/workflows/deploy.yml)
- Wat er gebeurt (in volgorde):
  - Stap 1: PHP lint (syntax check) over alle `.php` bestanden.
    - Doel: geen code uploaden die al kapot is door een syntax error.
    - Als dit faalt: je ziet een `php -l` error, en de deploy stopt.
  - Stap 2: Composer install (zet PHPUnit in `vendor/`).
  - Stap 3: Unit tests + coverage.
    - Doel unit tests: checken of onze helper-functies doen wat we verwachten.
    - Als tests falen: je ziet welke test faalt, en de deploy stopt.
    - Coverage: extra info hoeveel regels er tijdens tests geraakt zijn.
  - Stap 4: Upload via SFTP.
  - Stap 5: Smoke check (na upload).
    - Doel: snelle check of de belangrijkste pagina/endpoints reageren.
    - Voorbeeld: `status.php` zonder params hoort 422 te geven (dus endpoint leeft).
- Waar dit staat:
  - Composer deps: [composer.json](composer.json)
  - PHPUnit config (incl. coverage selectie): [phpunit.xml](phpunit.xml)
  - Tests: [BestellingLookupTest.php](tests/BestellingLookupTest.php)
- Coverage (simpel):
  - Coverage laat zien welke regels code door de tests worden uitgevoerd.
  - Alleen de bestanden die in `phpunit.xml` staan tellen mee.
  - Laag % = meestal te weinig tests of te weinig verschillende gevallen getest.
