# Codebase Uitleg (MarioSwitch1NL)

## 1) Doel
Deze uitleg beschrijft alleen de huidige chatbot‑codebase: wat waar gebeurt en hoe de chatflow loopt.

## 2) Architectuur in 1 zin
Een PHP‑frontend (chat UI) stuurt berichten naar een PHP‑backend die een AI‑prompt opbouwt (tone‑of‑voice + FAQ + data), OpenAI aanroept, en het antwoord terugstuurt.

## 3) Belangrijkste bestanden (met links)
- Frontend chat UI: [ChatBotMrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/ChatBotMrM.php#L1-L509)
- Backend chat flow: [ChatGptMrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/ChatGptMrM.php#L1-L337)
- OpenAI call: [ChatFunction.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatFunction.php#L6-L111)
- Classificatieprompt (onderwerp + platform): [system0.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/system0.php#L1-L71)
- Tone of voice Mr M.: [mrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/mrM.php#L1-L95)
- Verkoopadvies‑vragen: [VerkoopAdvies3.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/VerkoopAdvies3.php#L1-L67)
- Productlijst (voorraad + labels): [ProductList.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/ProductList.php#L1-L212)
- FAQ per onderwerp:
  - [aankoop.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/aankoop.php#L1-L200)
  - [zending.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/zending.php#L1-L111)
  - [service.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/service.php#L1-L159)
  - [inkoop.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/inkoop.php#L1-L72)
  - [loyaliteit.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/loyaliteit.php#L1-L62)
- Site‑config en DB‑connectie: [univ.inc](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/specific/univ.inc#L1-L107) en [db.inc](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/db.inc#L1-L26)

## 4) Chatflow uitgelegd (stap voor stap)
1. **User typt bericht in frontend**
   - UI en JS in [ChatBotMrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/ChatBotMrM.php#L349-L421).
2. **Frontend stuurt POST naar backend**
   - `fetch('ChatGptMrM.php', { user, page })`.
3. **Backend slaat bericht op**
   - Chatcookie + insert/update in `chatHistory`: [ChatGptMrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/ChatGptMrM.php#L39-L83).
4. **Classificatie (onderwerp + platform)**
   - `system0` prompt: [system0.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/system0.php#L1-L71)
   - Output bepaalt welke FAQ en context geladen wordt.
5. **Opbouwen van systeem‑prompt (system1)**
   - Tone of voice (Mr M.)
   - FAQ‑modules (Aankoop/Zending/Service/etc)
   - Contactinformatie + openingstijden
   - Logica in [ChatGptMrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/ChatGptMrM.php#L124-L276)
6. **AI‑antwoord ophalen**
   - `CHATGPT()` in [ChatFunction.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatFunction.php#L6-L111)
7. **Links normaliseren**
   - `perfectLink()` in [perfectLink.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/perfectLink.php#L1-L83)
8. **Antwoord terug naar frontend + opslaan**
   - HTML wordt toegevoegd aan chatgeschiedenis: [ChatGptMrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/ChatGptMrM.php#L281-L304)

## 5) Wat de “multi‑agent” nu feitelijk doet
De code werkt met een **twee‑staps agent flow**:
- **Agent 1 (classificatie)**: bepaalt onderwerp/platform via `system0`.
- **Agent 2 (antwoordgenerator)**: combineert tone‑of‑voice + FAQ + data in `system1`, en genereert het uiteindelijke antwoord.

## 6) Data en configuratie
- `chatHistory`: chatlogs en HTML (frontend weergave en context)
- `info` / `info2`: productmetadata (labels zoals leeftijd, hidden gem, doelgroep)
- `Winkel`: voorraad en prijzen
- `univ.inc`: site‑specifieke variabelen (platform, urls, categorieën)
- `db.inc`: DB‑connectie en timezone

## 7) Handige startvolgorde om te leren
1. Chatflow begrijpen: [ChatBotMrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/ChatBotMrM.php#L1-L509) → [ChatGptMrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/ChatGptMrM.php#L1-L337)
2. Prompting & agent‑logica: [system0.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/system0.php#L1-L71) + [mrM.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/mrM.php#L1-L95)
3. Data‑koppeling: [ProductList.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/ProductList.php#L1-L212)
4. FAQ‑module structuur: [service.php](file:///Users/obedado/Documents/MarioWii%20Afstudeerstage/MarioSwitch1NL/include/ChatGPT/service.php#L1-L159)
