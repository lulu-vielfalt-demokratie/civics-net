# Drupal Multisite Best Practices
## Lessons Learned: HTML → Drupal Theme (gerechtgehtanders.com)

---

## 1. Planung vor Implementierung

### Was wir falsch gemacht haben
- Direkt mit dem Coding angefangen, ohne vollständige Datenstruktur zu planen
- Paragraph-Types, Fields und Display Modes in mehreren Iterationen angelegt statt einmalig vollständig
- Content-Types ohne zugehörige Field-Konfigurationen importiert
- Theme mit `base theme: olivero` gestartet, obwohl wir ein eigenständiges Theme wollten

### Was besser gewesen wäre
- **Erst vollständige Datenstruktur planen** (Content-Types, Fields, Paragraph-Types, Display Modes) — alles auf Papier/Whiteboard, dann erst implementieren
- **Feature-Parität mit dem HTML-Prototype** sicherstellen: welche Inhalte sind dynamisch, welche statisch?
- **`base theme: false`** von Anfang an setzen bei eigenständigem Theme
- **HTML-Prototype vollständig abnehmen lassen** bevor Drupal-Struktur aufgebaut wird

---

## 2. Drupal Multisite: Vollständige Provisioning-Reihenfolge

Bei einer neuen Multisite **immer** in dieser Reihenfolge importieren:

```
1. node.type.*.yml                    # Content-Type-Definition (Name, Label)
2. paragraphs.paragraphs_type.*.yml   # Paragraph-Types
3. taxonomy.vocabulary.*.yml          # Taxonomien
4. field.storage.*.yml                # Field Storages (ERST Storages...)
5. field.field.*.yml                  # Field Configs (...DANN Instances)
6. core.entity_view_display.*.yml     # View Displays
7. core.entity_form_display.*.yml     # Form Displays (Backend-Formulare!)
```

**Wichtig:** Config-Import in zwei Schritten (erst Storages, dann Fields), weil Drupal's `--partial`-Import alphabetisch sortiert, nicht nach Abhängigkeiten.

### Was passiert wenn man Schritte vergisst

| Vergessener Schritt | Symptom |
|---------------------|---------|
| `node.type.*.yml` | Nodes können erstellt werden, aber Label fehlt in UI, Views filtern falsch |
| `field.storage.*.yml` | Field-Instances können nicht erstellt werden ("field storage does not exist") |
| `core.entity_form_display.*.yml` | Redakteure sehen im Backend nur den Titel, keine weiteren Felder |
| `core.entity_view_display.*.yml` | Felder werden im Frontend nicht gerendert (`content.field_xyz` ist leer) |

---

## 3. HTML → Drupal Twig: Richtiger Workflow

### Was wir falsch gemacht haben
- Inline-Styles in Twig-Templates (schwer wartbar, keine Media-Queries möglich)
- `page.html.twig` mit vollständigem `<html><head><body>` gebaut → doppeltes HTML-Dokument
- Falsche Twig-Funktionen verwendet (`item|view`, `drupal_view()` ohne Extension)
- Zu viele Iterationen durch fehlende Kenntnis der richtigen Drupal-Twig-Variablen

### Richtiger Workflow

```
1. HTML-Prototype bauen und abnehmen lassen
2. Sections → Paragraph-Types mappen (Hero, Icon-Grid, CTA etc.)
3. Dynamische Inhalte identifizieren → Fields definieren
4. CSS komplett in style.css auslagern, Klassen definieren
5. Templates schlank mit Klassen bauen, KEIN Inline-CSS
6. html.html.twig für <html><head><body>
7. page.html.twig NUR für Header/Main/Footer (KEIN <html>-Tag!)
8. Paragraph-Templates nur für Paragraphen-spezifisches Markup
```

### Korrekte Twig-Variablen

```twig
{# Feld-Wert direkt (kein Drupal-Wrapper): #}
{{ paragraph.field_title.value }}

{# Feld gerendert mit Display-Mode (hat Drupal-Wrapper-Divs): #}
{{ content.field_title }}

{# Feld-Items iterieren: #}
{% for item in paragraph.field_items %}
  {{ item.value }}
{% endfor %}

{# Link-Feld: #}
{{ paragraph.field_cta.uri }}
{{ paragraph.field_cta.title }}

{# Dynamische Daten via Preprocess Hook (statt drupal_view()): #}
{# → gga2_theme.theme: hook_preprocess_paragraph__hero() #}
{% for event in naechste_termine_events %}
  {{ event.title }}
{% endfor %}
```

### Wichtige Template-Suggestions

```
html.html.twig              → <html>, <head>, <body>, Meta-Tags, OG-Tags
page.html.twig              → Header, Main, Footer (KEIN <html>!)
node--[type].html.twig      → Node-spezifisches Markup
paragraph--[type].html.twig → Paragraph-spezifisches Markup
field--[name].html.twig     → Feld-Wrapper entfernen
block--[id].html.twig       → Block-Wrapper entfernen
menu--[name].html.twig      → Menü-Markup
region--[name].html.twig    → Region-Wrapper (Vorsicht: bricht Grid-Layouts!)
```

---

## 4. Display Modes: Kritisch für Frontend-Rendering

**Das häufigste Problem:** `content.field_xyz` ist leer im Twig-Template.

**Ursache:** Der Entity View Display für das Feld wurde nicht konfiguriert.

**Lösung:**
```php
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$display = EntityViewDisplay::load("paragraph.hero.default");
if (!$display) {
  $display = EntityViewDisplay::create([
    "targetEntityType" => "paragraph",
    "bundle" => "hero",
    "mode" => "default",
    "status" => TRUE,
  ]);
}
$display->setComponent("field_title", [
  "type" => "string",
  "weight" => 0,
  "label" => "hidden",
]);
$display->save();
```

**Und Form Display für Backend-Formulare nicht vergessen!**

---

## 5. Theme-Architektur

### `theme.info.yml`
```yaml
name: 'Theme Name'
type: theme
base theme: false          # Eigenständig, kein Olivero-Erbe!
core_version_requirement: '>=11'

regions:
  content: Content
  primary_menu: Primary Menu
  footer: Footer
  # Nur Regionen die wirklich genutzt werden!
```

### Ein Theme für alle CivicOS-Tenants
Statt pro Tenant ein eigenes Theme, nutzen wir **CSS Custom Properties**:

```css
/* Basis-Theme (für alle Tenants gleich) */
:root {
  --primary: #FF6A2B;      /* Wird pro Tenant überschrieben */
  --secondary: #7344B2;    /* Wird pro Tenant überschrieben */
  --black: #241F2B;
  --beige: #F7F2EA;
}
```

```css
/* /sites/tenant.civicos.de/files/css/tenant-vars.css */
/* Automatisch generiert beim Provisioning */
:root {
  --primary: #E63946;      /* Tenant-spezifische Farbe */
  --secondary: #457B9D;    /* Tenant-spezifische Farbe */
}
```

---

## 6. Admin-Toolbar Kompatibilität

```css
body.toolbar-fixed .site-header { top: 39px; }
body.toolbar-tray-open.toolbar-fixed .site-header { top: 79px; }
.contextual { display: none !important; }
```

---

## 7. Menü-System: Immer Drupal-Bordmittel nutzen

**Nicht:** Navigationlinks hartcodieren in Templates

**Sondern:**
- Hauptnavigation → `system_menu_block:main` → Region `primary_menu`
- Footer-Mitmachen → `system_menu_block:footer-mitmachen` → Region `footer`
- Footer-Rechtliches → `system_menu_block:footer-rechtliches` → Region `footer`

Redakteure pflegen alle Links über `/admin/structure/menu` — kein Theme-Eingriff nötig.

---

## 8. Views: Häufige Fallstricke

- `drupal_view()` ist keine standard Twig-Funktion bei `base theme: false`
- **Lösung:** Preprocess Hook in `theme.theme`
- DateTime-Felder in Views brauchen vollständige `settings`-Konfiguration im YAML
- View-Blöcke NICHT der `content`-Region zuweisen → werden doppelt gerendert!
- Für komplexe Darstellungen: Entity Query im Preprocess Hook > Views-Field-Renderer

```php
function mytheme_preprocess_paragraph__hero(&$variables) {
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'event')
    ->condition('status', 1)
    ->sort('field_event_date', 'ASC')
    ->range(0, 3)
    ->accessCheck(TRUE);
  
  $nodes = \Drupal\node\Entity\Node::loadMultiple($query->execute());
  $events = [];
  foreach ($nodes as $node) {
    $date = new \DateTime($node->field_event_date->value ?? 'now');
    $events[] = [
      'title' => $node->label(),
      'day' => $date->format('d'),
      'month' => $date->format('M'),
      'time' => $date->format('H:i') . ' Uhr',
      'location' => $node->field_location->value ?? NULL,
    ];
  }
  $variables['naechste_termine_events'] = $events;
}
```

---

## 9. SSL & DNS Checkliste

```
☐ Domain registriert → Verifizierungs-E-Mail SOFORT bestätigen!
☐ A-Record @ → Server-IP
☐ A-Record www → Server-IP
☐ DNS propagiert prüfen: nslookup domain.com 8.8.8.8
☐ Nginx server_block mit korrektem PHP-Socket anlegen
☐   → PHP-Socket: unix:/run/php/php8.3-fpm-platformsync.sock
☐ sites/sites.php: Domain → Multisite-Ordner mappen
☐ settings.php: trusted_host_patterns erweitern
☐ certbot --nginx -d domain.com -d www.domain.com
☐ certbot renew --dry-run (Autorenew testen)
☐ Subdomain-Weiterleitungen (z.B. mitmachen.*) ebenfalls mit Certbot absichern
```

**Wildcard-Zertifikate:** Automatische Erneuerung funktioniert nicht mit `--nginx` — DNS-Challenge muss manuell erneuert werden alle 60 Tage.

---

## 10. Barrierefreiheit, Mobile First, SEO Checkliste

### Barrierefreiheit
```
☐ Skip-Link ("Direkt zum Inhalt")
☐ Semantisches HTML5 (header, main, footer, nav, article)
☐ ARIA-Labels auf Navigation
☐ Mindest-Touch-Targets (44px)
☐ Focus-Styles (focus-visible)
☐ prefers-reduced-motion Media Query
☐ Kontrastverhältnis 4.5:1 (WCAG AA)
☐ Alt-Texte für alle Bilder
☐ Klare Heading-Hierarchie (h1 → h2 → h3)
☐ Barrierefreiheitserklärung nach EU-Richtlinie 2016/2102
```

### Mobile First
```
☐ Hamburger-Menü für < 768px
☐ font-size: 16px auf Inputs (verhindert iOS-Zoom)
☐ clamp() für responsive Schriftgrößen
☐ Responsive Grid mit @media Queries
☐ Touch-freundliche Button-Größen
☐ Termin-Kachel auf Mobile testen
```

### SEO
```
☐ Meta-Description pro Seite
☐ Open Graph Tags (og:title, og:description, og:type, og:url)
☐ Twitter Card
☐ Canonical-Tags (Drupal automatisch)
☐ HTTPS aktiv
☐ Sitemap (simple_sitemap Modul) → /sitemap.xml
☐ robots.txt: noindex auf Staging (slug.civicos.de)
☐ Schema.org für Events (JSON-LD)
☐ Google Search Console: Sitemap anmelden
```

---

## 11. Staging + Produktion: Professioneller Workflow

### Konzept
```
slug.civicos.de        → Staging  (Entwicklung/Test, noindex)
finale-domain.de       → Produktion (nach Freigabe)
```

### Warum zwei Umgebungen?
- Änderungen immer erst auf Staging testen
- Kein Risiko für Live-Site während Entwicklung
- Kunde kann auf Staging abnehmen bevor es live geht
- Bei Fehler: Live-Site läuft weiter ungestört

### Workflow
```
Entwicklung auf slug.civicos.de
       ↓
Abnahme durch Kunde/Initiative
       ↓
Config-Export auf Staging:
drush config:export --uri=slug.civicos.de

       ↓
Config-Import auf Produktion:
drush config:import --uri=finale-domain.de

       ↓
DNS umschalten auf finale Domain
       ↓
slug.civicos.de bleibt als Staging erhalten
       ↓
Änderungen immer erst auf Staging → dann Produktion
```

### Was beim Provisioning automatisch passiert
```
POST /api/v1/sites
{
  "slug": "gga2",
  "domain": "gerechtgehtanders.com",
  "plan": "free"
}

→ Drupal installieren
→ Basis-Config-Paket einspielen
→ Staging-URL: gga2.civicos.de (noindex automatisch)
→ Produktions-URL: nach DNS-Umschaltung
→ Admin-Credentials zurückgeben
```

---

## 12. CivicOS: Was ist generisch, was ist parametrierbar?

### Feste Bestandteile (jeder Tenant bekommt das automatisch)

**Content-Struktur:**
- `landing_page` + `field_paragraphs`
- `event` + Datum/Ort/Beschreibung
- `blog_post` + Text/Bild
- `local_group` + Kontaktfelder
- `page` — für Impressum, Datenschutz, Barrierefreiheit
- Paragraph-Types: `hero`, `icon_grid`, `text_image`, `cta`
- View "Nächste Termine"

**Module:**
- Paragraphs + Entity Reference Revisions
- Simple Sitemap
- Path / Path Alias

**Rechtliche Vorlagen:**
- Datenschutzerklärung (Platzhalter für Name/Adresse)
- Barrierefreiheitserklärung
- Impressum (leer, muss befüllt werden)

**Theme:**
- Hamburger-Menü
- Admin-Toolbar-Kompatibilität
- Mobile-First Responsive
- WCAG 2.1 AA Basis

### Parametrierbar (pro Tenant aus API-Request)

| Parameter | Beispiel | Wo gesetzt |
|-----------|---------|------------|
| `site_name` | "Gerecht geht anders" | `system.site.name` |
| `contact_email` | "kontakt@domain.de" | `system.site.mail` |
| `primary_color` | `#FF6A2B` | CSS Custom Property `--primary` |
| `secondary_color` | `#7344B2` | CSS Custom Property `--secondary` |
| `domain` | `gerechtgehtanders.com` | Nginx + sites.php + trusted_host_patterns |
| `logo_url` | `/files/logo.svg` | `system.theme.global` |
| `footer_description` | "Dialoge für..." | Block-Text |
| `analytics_id` | `12843b15-...` | Umami-Tracking-Script |

### Tenant-spezifisch (komplett individuell)

- Custom Paragraph-Types (z.B. `petition_counter`)
- Spezifische Views und Dashboards
- Custom Modules (z.B. `lagebild` für wmgr.civicos.de)
- Externe Integrationen (Signal, Mailchimp etc.)
- Eigene Content-Types über den Standard hinaus

### Ein Theme für alle — individuelle Farben per CSS

```css
/* Wird beim Provisioning automatisch generiert: */
/* /sites/tenant.civicos.de/files/css/tenant-vars.css */
:root {
  --primary: #FF6A2B;
  --secondary: #7344B2;
  --black: #241F2B;
  --beige: #F7F2EA;
}
```

Das bedeutet: **Ein zentrales Theme für alle Tenants**, individuell per CSS-Override — kein separates Theme-Deployment pro Tenant.

---

## 13. Gesamtfazit: Idealer Workflow HTML → Drupal

```
Phase 1: Design & Planung (1 Tag)
├── HTML-Prototype mit echtem Content bauen
├── Prototype abnehmen lassen
├── Sections → Paragraph-Types mappen
├── Dynamische Felder identifizieren
└── CSS-Klassen-System definieren

Phase 2: Drupal-Struktur (2-3 Stunden)
├── Content-Types vollständig anlegen (inkl. Labels!)
├── Paragraph-Types anlegen
├── Field Storages importieren (Schritt 1)
├── Field Configs importieren (Schritt 2)
├── View Displays konfigurieren
└── Form Displays konfigurieren

Phase 3: Theme (1-2 Stunden)
├── html.html.twig (Meta, OG-Tags)
├── page.html.twig (Header/Footer, kein <html>!)
├── style.css mit allen Klassen aus dem Prototype
├── Paragraph-Templates mit Klassen (KEIN Inline-CSS)
├── Menü-System über Drupal-Bordmittel
└── Hamburger-Menü + Admin-Toolbar-Kompatibilität

Phase 4: Content & Testing (1 Tag)
├── Beispiel-Content eintragen
├── Views für dynamische Inhalte (Preprocess Hook)
├── Footer parametrierbar machen (Menüs + Blöcke)
├── SSL & DNS konfigurieren
├── Sitemap konfigurieren
├── Staging noindex setzen
├── Mobile-Test
└── Barrierefreiheits-Check

Gesamtzeit mit diesem Wissen: ~2 Tage statt ~2 Wochen
```
