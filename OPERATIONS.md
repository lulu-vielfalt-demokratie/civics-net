
## Admin Dashboard

URL: https://admin.civicos.de
Login: Admin API Key aus /opt/civicos/.env (ADMIN_API_KEY)

Funktionen:
- Übersicht mit Metriken
- Tenant-Liste mit Sperren/Aktivieren
- Neuen Tenant anlegen (vollautomatisch, ~26 Sekunden)

## Live Tenants

| Domain | Organisation |
|--------|-------------|
| lulu-vielfalt.civicos.de | Lulu Vielfalt Demokratie |
| verfassungsreform-mv.civicos.de | Verfassungsreform MV |

## Nginx Vhosts civicos.de

| Domain | Backend |
|--------|---------|
| admin.civicos.de | /var/www/civicos-admin (statisch) |
| api.civicos.de | 127.0.0.1:8080 (Provisioning Service) |
| *.civicos.de | PHP-FPM Drupal Multisite |
