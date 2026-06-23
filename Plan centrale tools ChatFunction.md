# Plan: centrale chat-tools (sync met chatbot)

**Status:** stap 1–4 geïmplementeerd op branch `feature/centrale-chat-tools`  
**Principe:** keep it simple

---

## 1. Doel

De chatbot (via `worker.php`) geeft OpenAI **tools** mee zodat het model live data uit de database kan ophalen (bestelling, voorraad, productaanraders). Dat werkt goed.

`CHATGPT()` in `ChatFunction.php` moet **dezelfde tools** kunnen gebruiken, zodat synchrone flows gelijk blijven met de chatbot.

**Bijdoel:** tool-PHP ook **direct** aanroepbaar voor klantenservice/admin, zonder AI.

| Wel | Niet |
|-----|------|
| Eén plek voor tool-definities + uitvoering | Dubbele logica in worker én ChatFunction |
| `CHATGPT()` optioneel met tools (sync) | Alles in één bestand proppen |
| Worker blijft async, gebruikt dezelfde tools | Worker ombouwen naar sync |
| Nieuwe tools later eenvoudig toevoegen | Groot framework of classes bouwen |
| Admin kan tools direct aanroepen (bonus) | Admin als eerste prioriteit vóór CHATGPT-tools |

---

## 2. Uitleg: hoe het nu werkt

Dit hoofdstuk is bedoeld om te **leren** en **vragen te beantwoorden** (bijv. in overleg).

### 2.1 Centrale plek: `chat_tools.php`

Alles draait om **één bestand**: `include/chat_tools.php`.

| Functie | Wat het doet |
|---------|----------------|
| **`bouwChatTools()`** | Het **menu** voor GPT: welke tools bestaan, met welke parameters |
| **`voerChatToolUit()`** | Het **echte werk**: database opzoeken en data teruggeven als array |

Zware logica staat in helpers (niet in `chat_tools.php` zelf):

- `bestelling_lookup.php` → orders
- `chat_product_zoek.php` → producten, voorraad, aanraders

**Nieuwe tool toevoegen** = twee blokken in `chat_tools.php` (definitie + uitvoering). Worker, `CHATGPT()` en admin pakken die dan automatisch mee.

---

### 2.2 Wie kan tools oproepen?

```text
┌─────────────────────────────────────────────────────────────────┐
│                     include/chat_tools.php                       │
│                                                                  │
│   bouwChatTools()          → menu voor GPT                       │
│   voerChatToolUit()        → database / echte actie              │
└────────────▲────────────────────────────▲────────────────────────┘
             │                            │
    ┌────────┴────────┐          ┌────────┴────────┐
    │  MET AI (GPT)   │          │  ZONDER AI      │
    └────────┬────────┘          └────────┬────────┘
             │                            │
   ┌─────────┴──────────┐               │
   │                      │               │
   ▼                      ▼               ▼
worker.php          CHATGPT()         admin (stap 5)
chatbot             sync              voerChatToolUit()
async               optioneel         direct
tools altijd aan    env-vlag
```

| Wie | Bestand | AI? | Tools aan? | Hoe |
|-----|---------|-----|------------|-----|
| **Klant chatbot** | `worker.php` | Ja | Altijd | Async queue → worker → `bouwChatTools` + `voerChatToolUit` |
| **Sync chat** | `ChatGptMrM.php` → `CHATGPT()` | Ja | Als `CHATGPT_GEBRUIK_TOOLS=1` | Sync request, zelfde tools |
| **E-maildashboard** | `EmailDashboard.php` | Ja | Nog **niet** | Order lookup nog handmatig in PHP vóór AI |
| **Admin (later)** | admin-pagina | **Nee** | Direct | Alleen `voerChatToolUit()` |

---

### 2.3 Route 1 — Chatbot (hoofdroute, async)

Dit is wat klanten **nu** gebruiken via `ChatBotMrM.php` → `/api/chat/send`.

```text
Klant typt in chat
       │
       ▼
ChatBotMrM.php  →  /api/chat/send  →  queue in database
       │
       ▼
worker.php pakt bericht op
       │
       ├── chat_functie_keuze.php
       │      → "welke tool nodig?" (auto / geforceerd / required)
       │
       ├── OpenAI + bouwChatTools()
       │
       ├── GPT vraagt tool aan (tool_call)
       │
       ├── voerChatToolUit()  →  bestelling_lookup / chat_product_zoek  →  database
       │
       └── GPT maakt klantantwoord in normale taal  →  polling haalt antwoord op
```

- **Async** (wachtrij + polling elke 2 sec)
- Tools **altijd aan** — onafhankelijk van env-vlag
- Log: `storage/logs/chat_worker.log` (`Functie aangeroepen: zoek_…`)

---

### 2.4 Route 2 — Sync `CHATGPT()` (optioneel)

Via `ChatGptMrM.php` — **niet** de normale chat-route (`/api/chat/send`).

```text
Bericht naar ChatGptMrM.php (sync, geen queue)
       │
       ├── .env: CHATGPT_GEBRUIK_TOOLS=1 ?
       │      nee → oude gedrag (alleen tekst, geen database)
       │      ja  → tools aan
       │
       ├── chatGptBepaalToolKeuze()  (zelfde regels als worker)
       │
       ├── CHATGPT(..., gebruikTools=true, $conn, $toolChoice)
       │        └── bouwChatTools() + voerChatToolUit()  (zelfde als worker)
       │
       └── Antwoord direct in één HTTP-response
```

- **Sync** (browser wacht tot alles klaar is)
- Zelfde tools als chatbot, **andere aanroeper**
- Log: `CHATGPT functie aangeroepen: …` in worker-log (als `schrijfWorkerLog` beschikbaar is)

---

### 2.5 Route 3 — Admin / klantenservice (later, zonder AI)

```text
Medewerker vult formulier in (bestelnummer, e-mail)
       │
       ▼
voerChatToolUit($conn, 'zoek_bestelling', [...])
       │
       ▼
database  →  resultaat op scherm (geen GPT, geen functie-keuze)
```

- Mens kiest zelf welke tool (= welk formulier/knop)
- Zelfde data als de bot zou ophalen
- **Stap 5** — UI nog niet gebouwd, functie bestaat al

---

### 2.6 Wanneer welke tool? (`chat_functie_keuze.php`)

Dit is **apart** van `chat_tools.php`. Het beslist **of** en **welke** tool GPT moet gebruiken.

| Resultaat | Betekenis |
|-----------|-----------|
| `'auto'` | GPT mag zelf kiezen: tool gebruiken of direct tekst antwoorden |
| `'required'` | GPT **moet** een tool gebruiken, mag zelf kiezen welke |
| Array met functienaam | GPT **moet precies die ene tool** gebruiken (bijv. `zoek_bestelling`) |

**Voorbeelden:**

- Bestelnummer + e-mail + “bestelling” → geforceerd `zoek_bestelling`
- “Spellen die lijken op Xenoblade” → geforceerd `zoek_productaanraders`
- “Hebben jullie Just Dance?” → geforceerd `zoek_productvoorraad`
- “Hoi!” → `auto` (geen tool nodig)

Gebruikt door: **worker** en **sync `CHATGPT()`** (via `chatGptBepaalToolKeuze()`).  
**Admin** slaat dit over — daar kiest de medewerker zelf.

---

### 2.7 GPT vs PHP — wie doet wat?

```text
                    PHP                          GPT
                    ───                          ───
Wanneer tool?       chat_functie_keuze           bij 'auto': wel of niet
                    (regex op bericht)           bij 'required': welke tool

Welke tool exact?   geforceerd: naam             bij 'auto': kiest zelf

Database query?     voerChatToolUit()            ─ (GPT doet dit NOOIT zelf)

Klantantwoord?      ─                            GPT schrijft tekst
```

GPT **belt niet zelf** de database. GPT vraagt: “voer `zoek_bestelling` uit met deze parameters”. PHP voert uit en geeft JSON terug. GPT maakt daar een leesbaar antwoord van.

---

### 2.8 Bestanden overzicht

```text
include/
  chat_tools.php           ← CENTRAAL: tools definiëren + uitvoeren
  chat_functie_keuze.php   ← wanneer welke tool (alleen bij AI)
  ChatFunction.php         ← CHATGPT() + tool-loop (sync)
  bestelling_lookup.php    ← order-queries
  chat_product_zoek.php    ← product/voorraad-queries

api/chat/
  worker.php               ← chatbot (async, AI + tools)

ChatBotMrM.php             ← chat UI → /api/chat/send (worker)
ChatGptMrM.php             ← sync chat (AI + tools via env-vlag)
test_chatgpt_tools.php     ← test sync CHATGPT()+tools visueel (geheim verplicht)

EmailDashboard.php         ← nog ZONDER tools op CHATGPT
                             (order lookup handmatig in PHP vóór AI)
```

---

### 2.9 Spiekbriefje — vragen aan Wibert

| Vraag | Antwoord |
|-------|----------|
| Waar staan de tools? | `include/chat_tools.php` |
| Gebruikt de chatbot dit al? | Ja, via `worker.php` |
| Kan `CHATGPT()` het ook? | Ja, met `$gebruikTools = true` of `CHATGPT_GEBRUIK_TOOLS=1` |
| Is de chatbot nu sync? | **Nee**, nog async via worker |
| Kan admin het zonder AI? | Ja, `voerChatToolUit()` direct — UI nog niet (stap 5) |
| Verandert de chatbot voor klanten? | Nee (zelfde gedrag, code netter) |
| Nieuwe tool waar toevoegen? | `bouwChatTools()` + `voerChatToolUit()` in `chat_tools.php` |
| Hoe testen? | phpunit + chatbot + worker-log (zie §9) |

---

## 3. Aanpak (stappen)

### Stap 1 — `include/chat_tools.php` ✅

- `bouwChatTools()` — OpenAI-definities
- `voerChatToolUit($conn, $naam, $args)` — router naar helpers
- Zware logica blijft in `bestelling_lookup.php`, `chat_product_zoek.php`, …

### Stap 2 — Worker vereenvoudigen ✅

```php
$tools = bouwChatTools();
$functieResultaat = voerChatToolUit($conn, $functieNaam, $arguments);
```

Gedrag chatbot ongewijzigd — minder dubbele code.

### Stap 3 — `CHATGPT()` uitbreiden ✅

```php
CHATGPT($input, $system, $temp, $model, $history, $test, $gebruikTools = false, $conn = null, $toolChoice = 'auto')
```

- `$gebruikTools === false` → exact zoals voorheen
- `$gebruikTools === true` → tool-loop via `chat_tools.php` (max. 3 rondes)
- `$toolChoice` → `'auto'`, `'required'`, of `bepaalGeforceerdeFunctieKeuze()` / `chatGptBepaalToolKeuze()`

### Stap 4 — Eerste consumer ✅

**ChatGptMrM.php** (sync): `CHATGPT_GEBRUIK_TOOLS=1` in `.env`.

### Stap 5 — Admin / tools-expanding (deels) ✅

**`tools-expanding/`** — hub met kaarten:

| Kaart | Type | Wat |
|-------|------|-----|
| CHATGPT + tools | Met GPT | sync test |
| Bestelling opzoeken | Zonder GPT | `voerChatToolUit(zoek_bestelling)` |

Nieuwe tool: pagina in map + regel in `toolsExpandingCatalogus()` in `_bootstrap.php`.

```php
// Voorbeeld direct (zonder GPT):
$result = voerChatToolUit($conn, 'zoek_bestelling', [
    'bestelling_id' => 12345,
    'email' => 'klant@voorbeeld.nl',
]);
```

---

## 4. Nieuwe tool toevoegen (toekomst)

Altijd **twee blokken** in `chat_tools.php`:

| # | Waar | Wat |
|---|------|-----|
| 1 | `bouwChatTools()` | OpenAI-definitie (name, description, parameters) |
| 2 | `voerChatToolUit()` | `if ($naam === 'nieuwe_tool')` → helper aanroepen |

Optioneel: grote logica in apart bestand (bijv. `tracking_lookup.php`).

**Voorbeeld toekomstige tool:** live bezorgstatus — inline tonen, geen doorlink. AfterPay-regels in PHP-tool.

---

## 5. Wat we bewust niet doen

- Geen apart registry-framework of classes per tool
- `CHATGPT()` niet volledig herschrijven
- Worker niet sync maken
- E-maildashboard niet als eerste ombouwen
- Tracking/admin niet vóór centrale tool-laag

---

## 6. Git

- Branch: **`feature/centrale-chat-tools`**
- `main` onaangeroerd tot merge na review/test

---

## 7. Open punten

- [x] Centrale `chat_tools.php`
- [x] `CHATGPT()` tools
- [x] Eerste sync consumer (`ChatGptMrM.php` + env-vlag)
- [x] Basis PHPUnit voor tools
- [ ] Admin-paneel met `voerChatToolUit()` direct
- [ ] E-maildashboard eventueel tools (nu: handmatige order lookup)
- [ ] Toekomst: bezorgstatus-tool — bezorger-API uitzoeken

---

## 8. Hoe testen

### A. Automatisch (lokaal / CI)

```bash
./vendor/bin/phpunit
```

- `ChatToolsTest`, `ChatFunctieKeuzeTest`, order lookup, product-zoek, …
- Test **geen** echte OpenAI-calls

### B. Chatbot (worker)

1. Deploy naar test
2. Testberichten in chat:
   - bestelling + e-mail → orderinfo
   - “Hebben jullie Just Dance?” → voorraad
   - “Spellen die lijken op Xenoblade?” → aanraders
3. Check `storage/logs/chat_worker.log`:
   - `Functie aangeroepen: zoek_…`
   - `Functie-resultaat ontvangen`

### C. Sync `CHATGPT()` (stap 4)

**Optie 1 — tools-expanding (aanbevolen):** `/tools-expanding/`

1. Open hub: `/tools-expanding/index.php` — wachtwoord **Obed** (eenmalig per sessie)
2. Kies kaart:
   - **Met GPT** → `gpt-sync-tools.php` (CHATGPT + tools)
   - **Zonder GPT** → `bestelling-zonder-ai.php` (direct `voerChatToolUit`)
3. Terug naar overzicht via link bovenaan

Oude URL `/test_chatgpt_tools.php` redirect naar de hub.

**Optie 2 — ChatGptMrM.php**

1. `.env`: `CHATGPT_GEBRUIK_TOOLS=1`
2. Via **ChatGptMrM.php** (niet `/api/chat/send`)
3. Zelfde berichten als B
4. Log: `CHATGPT functie aangeroepen: …`

### D. Admin (stap 5, later)

`voerChatToolUit()` direct aanroepen — geen OpenAI.

### E. Wat wanneer

| Wijziging | Test |
|-----------|------|
| `chat_tools.php` | phpunit + B |
| `CHATGPT()` tools | phpunit + C |
| Nieuwe tool | phpunit + B + C (+ D als admin klaar is) |

---

## 9. Notities / besluiten

```
(hier verder typen na implementatie of overleg)
```
