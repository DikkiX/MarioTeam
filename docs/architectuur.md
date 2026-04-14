# Project Context & Architectuur

Je bent vanaf nu mijn Senior Developer assistent voor mijn HBO afstudeerproject: "Context-Aware Service & Support Agent" voor de webshop Mario Wii.nl. Mijn naam is Obed en jij gaat mij helpen met de code.

## 1. Het Doel
We ontwikkelen een geïntegreerde Service Agent die de huidige chatbot vervangt. De nieuwe AI moet via Function Calling zelfstandig live order-informatie uit de database kunnen halen. Daarnaast bouwen we een e-mail module die automatisch concept-antwoorden genereert voor de klantenservice.

## 2. Server & Restricties (Cruciaal!)
De webshop draait op een One.com shared server met een harde limiet van maximaal 24 gelijktijdige PHP-processen.
* Regel 1: We mogen nóóit synchroon wachten op de OpenAI API, anders crasht de server bij drukte.
* Regel 2: Alles moet gebouwd worden met veilige PDO-connecties (geen SQL injecties) en API-sleutels worden uitsluitend via een .env bestand ingeladen.
* Regel 3: Geen zware frameworks. We schrijven alles in native/vanilla PHP en JavaScript om het snel en licht te houden.

## 3. De Architectuur (Asynchroon)
Om de serverlimieten te bewaken, bouwen we een asynchrone wachtrij:
1. De frontend stuurt een chatbericht naar een Middleware API (PHP).
2. De API slaat het bericht op in de MySQL tabel `chat_queue` met status 'pending' en sluit het script direct af (Fire-and-forget).
3. Een achtergrond-script (Worker) pakt pending berichten op, praat met OpenAI (inclusief Function Calling naar de database), en slaat het definitieve AI-antwoord op.
4. De frontend gebruikt polling om te checken of de status 'completed' is en toont dan pas het antwoord.

## 4. De Project Fases (Roadmap)
* Fase 1: Fundering & Deployment (Git, .env, GitHub Actions CI/CD).
* Fase 2: Database Architectuur (PDO connectie, tabellen `chat_queue` en `email_concepten`).
* Fase 3: Middleware API (Endpoint om inkomende chats in de wachtrij te zetten + Fire-and-forget trigger).
* Fase 4: De Worker (Achtergrondproces, OpenAI integratie, Function Calling voor actuele data, opslaan resultaat).
* Fase 5: Frontend & Polling (JavaScript AJAX polling in de bestaande chatwidget).
* Fase 6: E-mail Module (Gmail API uitlezen, OpenAI concepten laten schrijven, en een simpel PHP UI-dashboard bouwen voor de medewerkers).

## 5. User Stories
Wanneer ik aangeef dat we aan een specifieke User Story gaan werken, lees dan altijd eerst de exacte acceptatiecriteria in het bestand `docs/backlog.md` voordat je code genereert.