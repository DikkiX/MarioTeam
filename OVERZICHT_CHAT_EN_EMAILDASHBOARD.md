# Overzicht: Chatbot + E-maildashboard (MarioSwitch1NL)

Dit document legt simpel uit waar de belangrijkste onderdelen staan en wat ze doen.

## 1) Chatbot (huidige versie: queue/worker)

### Frontend (UI)
- Bestand: `/ChatBotMrM.php`
- Doet:
  - Toont chat UI.
  - Stuurt gebruikersbericht naar API: `POST /api/chat/send`
  - Pollt status/antwoord: `GET /api/chat/status`

### API endpoints
- Bestand: `/api/chat/send.php`
  - Ontvangt `{cookie, user_message}`.
  - Schrijft een nieuw record in `chat_queue` met status `pending`.
  - Start de worker (fire-and-forget).

- Bestand: `/api/chat/status.php`
  - Geeft status + antwoord terug voor een bericht id + cookie.
  - Frontend blijft poll’en tot `completed`.

- Bestand: `/api/chat/worker.php`
  - Pakt het oudste `pending` bericht, zet `processing`, maakt antwoord en zet `completed`.
  - Gebruikt dezelfde “oude volgorde” als de eerdere chatbot:
    1) system0 (onderwerp/platform bepalen)
    2) system1 opbouwen met FAQ/contact/today
    3) definitief antwoord maken
  - Kan ook interne tools gebruiken:
    - `zoek_productvoorraad` (voorraad/prijs/product info)
    - `zoek_bestelling` (order-status)
  - Regels:
    - Bij vragen over “op voorraad/prijs/beschikbaar” wordt tool-gebruik afgedwongen zodat de bot niet gaat gokken.

### Kennis / content (FAQ & tone of voice)
- Tone-of-voice:
  - `/include/ChatGPT/mrM.php`
- Onderwerp-classificatie (system0):
  - `/include/ChatGPT/system0.php`
- FAQ content per onderwerp (vult `$FAQ[...]` arrays):
  - `/include/ChatGPT/aankoop.php`
  - `/include/ChatGPT/zending.php`
  - `/include/ChatGPT/service.php`
  - `/include/ChatGPT/inkoop.php`
  - `/include/ChatGPT/loyaliteit.php`
  - (er bestaat ook `/include/ChatGPT/FAQ.php` als bundel/extra content)
- Contact gegevens (wordt als blok toegevoegd):
  - `/include/contact.inc`
- Openingstijden/“vandaag” helper:
  - `/include/time4.inc`

### Logs
- Worker log:
  - Pad staat in worker configuratie (meestal `storage/logs/chat_worker.log` of vergelijkbaar).
  - Handig om te checken of tools worden aangeroepen:
    - “Functie aangeroepen: zoek_productvoorraad”
    - “Functie aangeroepen: zoek_bestelling”

## 2) Chatbot (oude versie: direct POST naar ChatGptMrM.php)

- Bestand: `/ChatGptMrM.php`
- Doet:
  - Directe POST flow (zonder queue).
  - Slaat chatHistory op in DB (`chatHistory` tabel).
  - Gebruikt `ChatFunction.php` om OpenAI aan te roepen.
- Let op:
  - De huidige chat UI gebruikt normaal de queue/worker route.
  - Dit bestand is vooral relevant als je bewust de oude route gebruikt.

## 3) OpenAI aanroepen (modellen / instellingen)

### Env keys
- Bestand dat `.env` leest:
  - `/include/env.php` (functie `getProjectEnvValue($key)`)
- Verwachte keys:
  - `OPENAI_API_KEY`
  - `CHAT_MODEL_MODE` (model keuze mode)

### Model-keuze (zelfde logica als ChatFunction)
- In ChatFunction:
  - `/include/ChatFunction.php` (functie `CHATGPT(...)`)
  - Default model parameter is `gpt-5-mini`.
  - Mode mapping:
    - `1` → `gpt-5.2`
    - `2` → `gpt-5-mini`
    - `3` → `gpt-4.1-mini`
- In worker:
  - `/api/chat/worker.php`
  - Leest `CHAT_MODEL_MODE` en gebruikt dezelfde mapping.
  - Default is mode `2` (dus standaard `gpt-5-mini`), zodat het gelijk loopt met ChatFunction.

## 4) E-maildashboard (AI concepten)

- Bestand: `/EmailDashboard.php`
- Doet:
  - Login + CSRF.
  - Gmail token lezen/refreshen.
  - Mails ophalen + AI-concepten aanmaken + concepten beheren.

### OpenAI call voor e-mailconcepten
- Functie:
  - `roepOpenAiAanVoorEmailConcept(...)` in `/EmailDashboard.php`
- Waarom deze eigen cURL call bestaat (en niet CHATGPT()):
  - Nette foutafhandeling met HTTP status + OpenAI error message.
  - Timeout zodat dashboard niet blijft hangen.
  - Return-structuur met `['ok'=>true/false, 'content/error'=>...]`.

## 5) Database (belangrijkste tabellen)

- `chat_queue`:
  - Queue voor worker chat.
  - Bevat o.a. `cookie`, `user_message`, `ai_response`, `status`.
- `chatHistory`:
  - Oude chatbot opslag (HTML + JSON history).
- `email_concepten`:
  - Concepten voor e-maildashboard.
- `dashboard_settings`:
  - Dashboard instellingen zoals `tone_of_voice`.

## 6) “Waar pas ik iets aan?”

- Bot zegt verkeerde bedrijfsinfo (retour/verzenden/openingstijden):
  - check de inhoud in `include/ChatGPT/*.php` + `include/contact.inc` + `include/time4.inc`
  - check of system0 goed labelt in `include/ChatGPT/system0.php`
- Bot gokt over voorraad/prijs:
  - check worker log of `zoek_productvoorraad` wordt aangeroepen
  - check tool forcing in `/api/chat/worker.php`
- Model/kwaliteit aanpassen:
  - zet `CHAT_MODEL_MODE=1` in `.env` (5.2) of `2` (5-mini) of `3` (4.1-mini)

