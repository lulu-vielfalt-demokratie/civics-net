# platformsync_quiz

Interaktives Präsenz-Quiz für demokratiepädagogische Veranstaltungen.
Entwickelt für Lulu.Vielfalt.Demokratie / PlatformSync.

## Konzept

- **Host-Screen** (Beamer): zeigt Fragen, live Antwort-Balken, Auflösung
- **Player** (Smartphone): Teilnehmende öffnen eine URL / scannen QR-Code
- **Echtzeit** via Server-Sent Events (SSE) — kein WebSocket, kein Node.js

## Installation

1. Modul in `web/modules/custom/platformsync_quiz/` kopieren
2. `drush en platformsync_quiz`
3. `drush updb` (Datenbanktabellen anlegen)
4. Berechtigung `host platformsync quiz` an gewünschte Rolle vergeben

## Fragen verwalten

Fragen werden als Drupal-Nodes vom Typ `quiz` verwaltet.
Jeder Node enthält ein Mehrwert-Feld `field_quiz_questions` mit folgender Struktur
(als JSON-kodiertes Paragraph- oder Computed-Feld, je nach Implementierung):

```json
{
  "category": "Demokratietheorie",
  "question": "Was unterscheidet ...",
  "answers": ["Antwort A", "Antwort B", "Antwort C"],
  "correct": 1,
  "explanation": "Hintergrundtext ..."
}
```

## Session starten

1. Host loggt sich in Drupal ein
2. `POST /api/quiz/session` mit `{ "quiz_id": 42 }`
3. Response enthält `session_id`, `host_url`, `player_url`
4. Host öffnet `host_url` am Beamer
5. Teilnehmende öffnen `player_url` auf dem Smartphone (oder scannen QR)

## Nginx-Konfiguration

SSE braucht deaktiviertes Proxy-Buffering:

```nginx
location /api/quiz/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 3600s;
    chunked_transfer_encoding on;
}
```

## Runden-Konzept (geplant)

- **Runde 1** — Wissen: Multiple Choice, klare richtige Antwort
- **Runde 2** — Abwägen: Dilemma-Fragen, keine eindeutige Antwort, Diskussion
- **Runde 3** — Handeln: Szenario-Entscheidungen im Team

## Lizenz

Proprietär — Teil der PlatformSync CivicOS-Plattform.
