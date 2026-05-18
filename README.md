# Mario Team — Codebase

Dit is de codebase van Mario Team (o.a. de chat op de website + het e-maildashboard).

## Snel starten (lees dit eerst)

- Overzicht/uitleg: [Uitleg overzicht codebase](Uitleg%20overzicht%20codebase.md)
- Chat flow (queue/worker):
  - UI: [ChatBotMrM.php](ChatBotMrM.php)
  - API: [api/chat/send.php](api/chat/send.php) → [api/chat/status.php](api/chat/status.php)
  - Worker: [api/chat/worker.php](api/chat/worker.php)
- E-maildashboard: [EmailDashboard.php](EmailDashboard.php)

## Mappen (kort)

- `api/` = echte endpoints (URL’s) die je aanroept vanuit de browser of via HTTP.
- `include/` = gedeelde helpers/includes die je in meerdere scripts hergebruikt (geen endpoints).
- Gedeelde order lookup (chat + e-mail): [include/bestelling_lookup.php](include/bestelling_lookup.php)
- Chat: welke functie verplicht is bij productvragen: [include/chat_functie_keuze.php](include/chat_functie_keuze.php)

## Belangrijkste instellingen

Deze keys staan in `.env` (niet committen):

- `OPENAI_API_KEY`
- `CHAT_MODEL_MODE` (model-keuze)
- `CHAT_WORKER_SECRET` (beveiliging voor het worker endpoint)
- `EMAIL_WORKER_SECRET` (beveiliging voor de e-mail worker in EmailDashboard)
- `EMAIL_DASHBOARD_USER` en `EMAIL_DASHBOARD_PASS` (inlog voor het dashboard)
