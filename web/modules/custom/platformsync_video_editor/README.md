# PlatformSync Video-Editor

Drupal 11 Modul · CivicOS / PlatformSync

## Features
- Video trimmen (In/Out-Punkte per Timeline)
- Gesichter automatisch verpixeln (face-api.js, lokal im Browser)
- Bereiche manuell verpixeln (Nummernschilder, Schilder)
- Metadaten entfernen (GPS, Gerät, Datum)
- Tonspuren hinzufügen (eigene Dateien + Syndikal-Bibliothek)
- Export als WebM / MP4 (via ffmpeg.wasm)
- Alle Verarbeitungen lokal im Browser – kein Upload auf externe Server

## Installation

```bash
# Modul nach /modules/custom/ kopieren
cp -r platformsync_video_editor /var/www/platformsync/web/modules/custom/

# Via Drush aktivieren
drush en platformsync_video_editor --uri=https://platformsync.de
drush cr
```

## Konfiguration

1. **Block platzieren**: Admin → Struktur → Blöcke → „PlatformSync Video-Editor" auf gewünschter Seite platzieren
2. **Permissions**: Admin → Benutzer → Berechtigungen → „Video-Editor verwenden" den gewünschten Rollen zuweisen
3. **Syndikal-Tracks anlegen**: Inhalt hinzufügen → Syndikal Track → MP3 hochladen

## Syndikal-Bibliothek API

Der Endpunkt `/api/syndikal/tracks` liefert alle veröffentlichten Syndikal-Track-Nodes als JSON.
Felder die nicht befüllt sind werden als `null` zurückgegeben – der Editor zeigt Fallbacks an.

## Weiterentwicklung

- `js/video-editor.js` → TODO-Kommentare für echte face-api.js und ffmpeg.wasm Integration
- `src/Controller/TracksApiController.php` → Erweiterbar um Tenant-Filter per CivicOS-Kontext
