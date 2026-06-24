# Chat-tools en helpers

Alle GPT-tools worden gedefinieerd en aangeroepen via `include/chat_tools.php` (`bouwChatTools`, `voerChatToolUit`).

## Tools

| Tool | Helper(s) | Wat het doet |
|------|-----------|--------------|
| `zoek_bestelling` | `include/bestelling_lookup.php` | Bestelling opzoeken op bestelnummer + e-mail: status, artikelen, verzendinfo |
| `zoek_productvoorraad` | `include/chat_tools.php`, `include/chat_product_zoek.php` | Eén product op voorraad zoeken op titel/link |
| `zoek_productaanraders` | `include/chat_product_zoek.php` | Meerdere producten op voorraad zoeken op zoektermen |
| `wijzig_bestelling_adres` | `include/bestelling_adres.php` | Bezorgadres ophalen of wijzigen op alleen bestelnummer (`naam`, `adres`, `telefoon`) |
| `zoek_traceer` | `include/tracking_lookup.php`, `include/bestelling_lookup.php` | Bezorgstatus ophalen via traceernummer of bestelnummer + e-mail |

## Helpers

| Bestand | Gebruikt door | Wat het doet |
|---------|---------------|--------------|
| `include/bestelling_lookup.php` | `zoek_bestelling`, `zoek_traceer` | Bestelling uit DB, artikelen parsen, trackcode uit `tracktrace` |
| `include/bestelling_adres.php` | `wijzig_bestelling_adres` | Adres lezen/schrijven in `Bestellingen` |
| `include/chat_product_zoek.php` | `zoek_productvoorraad`, `zoek_productaanraders` | Zoektermen en productqueries op `Winkel` / `info` |
| `include/tracking_lookup.php` | `zoek_traceer` | PostNL/GLS live-status en traceerantwoord voor de chat |
| `include/chat_functie_keuze.php` | chat (toolkeuze) | Bepaalt welke tool bij bepaalde gebruikersvragen hoort |
