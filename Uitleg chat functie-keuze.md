# Cheat sheet: wanneer welke chat-functie (helper)

Kort overzicht voor Mario Team / overdracht.  
Logica staat in `include/chat_functie_keuze.php`. De worker (`api/chat/worker.php`) voert dit uit vóór de OpenAI-call.

Er zijn **3 database-functies** (tools):

| Functie | Doet |
|---------|------|
| `zoek_bestelling` | Order opzoeken (bestelnummer + e-mail) |
| `zoek_productvoorraad` | Eén product/titel → voorraad ja/nee |
| `zoek_productaanraders` | Meerdere zoektermen → aanraders op voorraad |

Als PHP **`auto`** teruggeeft, mag OpenAI zelf kiezen. Anders is één functie **verplicht**.

---

## Volgorde van beslissing (eerste match wint)

1. "Ik heb antwoord op al mijn vragen" → **zoek_productaanraders**
2. ProductFinder + vergelijk/direct → **zoek_productaanraders** of **auto** (zie hieronder)
3. Bestelling + e-mail + getal → **zoek_bestelling**
4. Aanraders / vergelijk-zinnen → **zoek_productaanraders**
5. Hebben jullie + bekende titel → **zoek_productvoorraad**
6. Genre + vraag → **zoek_productaanraders**
7. Anders → **auto** (AI kiest)

---

## 1. zoek_productaanraders

### Altijd deze functie

**Zin (exact):**
- `ik heb antwoord op al mijn vragen`

**Aanraders / suggesties:**
- aanraden, aanrader, aanbevel, aanbevelen, aanbeveling
- suggest, suggestie, suggesties
- alternatief, soortgelijk, gelijke
- vergelijkbaar, vergelijkbare
- andere games, andere spellen

**Vergelijking:**
- lijken op, zoals, net als
- in de trant van, vergelijkbaar met, soort als
- op … lijkt

**ProductFinder (system0) + uitzondering:**
- Normaal: **geen** geforceerde tool → eerst verkoopvragen (VerkoopAdvies).
- Wél aanraders als klant zegt bv.:
  - vergelijk-zinnen (zoals hierboven), of
  - alleen suggesties, alleen namen
  - direct spellen / titels / antwoord
  - geen vragen, niet meer vragen, stop met vragen, geen intake, overslaan

**Genre + vraag (beide nodig, of genre + vraagteken/heb je/…):**

Genre-woorden (voorbeelden):  
race, racing, auto, kart, mario kart, dans, dance, just dance, danspellen, racespellen, partyspellen, rpg, jrpg, avontuur, actie, shooter, schiet, puzzel, party, multiplayer, co-op, sport, voetbal, basketbal, horror, strategy, strategie, xenoblade, zelda, mario, pokemon, sonic, kirby, fifa

Plus vraag-indicatie: `?` of heb je, hebben jullie, zijn er, verkoop, verkopen jullie, aanraden  
En vaak: spel, spellen, game, games (of woorden als *danspellen*, *racespellen*)

**Voorbeelden:** "danspellen?", "spellen zoals Xenoblade", "hebben jullie RPG's?"

---

## 2. zoek_bestelling

**Alle drie tegelijk in hetzelfde bericht:**

| Deel | Woorden |
|------|---------|
| Bestel | bestelling, bestelnummer, order, status, inhoud, artikelen, orderregels, wat heb ik besteld, wat zit er |
| E-mail | geldig e-mailadres (x@y.nl) |
| Nummer | minstens één cijfer in het bericht |

**Voorbeeld:** "Mijn bestelling 4711, email klant@mail.nl"

**Niet genoeg:** alleen ordernummer zonder mail → **auto** (geen force).

---

## 3. zoek_productvoorraad

**Alle voorwaarden:**

| Deel | Woorden |
|------|---------|
| Assortiment-vraag | heb je, hebben jullie, verkopen jullie, hebben jullie ook, is er, staat … op voorraad |
| Bekende titel | just dance, xenoblade, zelda, mario, pokemon, fifa, sonic, kirby, animal crossing, splatoon, smash, kart, pikmin, metroid, fire emblem, bayonetta, ring fit |
| Geen vergelijk-zin | geen "lijken op" / "zoals …" (dan → aanraders) |

**Voorbeeld:** "Hebben jullie Just Dance?"

**Niet:** "Spellen zoals Xenoblade" → **aanraders**, niet voorraad.

---

## 4. auto — OpenAI kiest zelf

Geen match hierboven, bijvoorbeeld:

- Algemene service-vragen (verzending, contact, FAQ)
- ProductFinder-intake ("ik zoek iets voor mijn dochter") → eerst vragen, geen DB
- Bestelling zonder complete gegevens

---

## 5. Extra regels in de worker (geen functie-keuze)

### A. Voorraad follow-up (PHP zoekt zelf, geen tool)

Als **voorraad** én **verwijzing naar eerder** in hetzelfde bericht:

**Voorraad:** op voorraad, voorraad, beschikbaar, in stock, nog te koop, hebben jullie die/ze/hem/haar/het  

**Verwijzing:** ze, die, deze, allemaal, beide, genoemde, die games, die spellen, dat spel, die titels  

**Voorbeeld:** "Zijn ze allemaal op voorraad?" na net genoemde spellen.

### B. Tool verplicht, AI kiest welke (`required`)

Bij `auto` + woorden: **op voorraad, voorraad, beschikbaar, in stock, prijs**  
→ OpenAI moet een tool gebruiken, maar mag kiezen welke.

---

## Snelle voorbeelden

| Klant zegt | Resultaat |
|------------|-----------|
| "Hebben jullie Just Dance?" | zoek_productvoorraad |
| "Danspellen?" | zoek_productaanraders |
| "Spellen zoals Zelda" | zoek_productaanraders |
| "Order 123, mail a@b.nl" | zoek_bestelling |
| "Ik zoek een game voor mijn zoon" (ProductFinder) | auto → eerst verkoopvragen |
| "Ik heb antwoord op al mijn vragen" | zoek_productaanraders |
| "Zijn ze op voorraad?" (na eerdere titels) | geen tool, PHP checkt links uit gesprek |

---

## Aanpassen

- Triggers wijzigen: `include/chat_functie_keuze.php`
- Tool-beschrijvingen voor OpenAI: `bouwToolsVoorOpenAi()` in `api/chat/worker.php`
- Instructies aan AI: `basisPrompt` in `api/chat/worker.php`
- Tests: `tests/ChatFunctieKeuzeTest.php`

*Versie: afstudeerproject Context-Aware Service & Support Agent, testomgeving marioswitch1.nl.*
