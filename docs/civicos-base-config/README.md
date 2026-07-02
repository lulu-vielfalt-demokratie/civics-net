# CivicOS Base Config Package

Basis-Konfiguration für neue CivicOS-Tenants.

## Inhalt
- 4 Content-Types: landing_page, event, blog_post, local_group
- 4 Paragraph-Types: hero, icon_grid, text_image, cta
- Alle Field Storages + Field Configs
- View + Form Displays für alle Bundles
- 3 Menüs: main, footer-mitmachen, footer-rechtliches
- View: naechste_termine

## Installation

### Schritt 1: Types + Storages
```bash
mkdir /tmp/step1
cp node.type.*.yml /tmp/step1/
cp paragraphs.paragraphs_type.*.yml /tmp/step1/
cp field.storage.*.yml /tmp/step1/
cp system.menu.*.yml /tmp/step1/
drush config:import --partial --source=/tmp/step1 -y --uri=TENANT.civicos.de
```

### Schritt 2: Fields + Displays + Views
```bash
mkdir /tmp/step2
cp field.field.*.yml /tmp/step2/
cp core.entity_*.yml /tmp/step2/
cp views.view.*.yml /tmp/step2/
drush config:import --partial --source=/tmp/step2 -y --uri=TENANT.civicos.de
```

### Schritt 3: DB-Updates + Cache
```bash
drush updatedb -y --uri=TENANT.civicos.de
drush cache:rebuild --uri=TENANT.civicos.de
```
