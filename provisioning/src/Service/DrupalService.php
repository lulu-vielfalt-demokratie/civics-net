<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use Psr\Log\LoggerInterface;

class DrupalService {

    private string $drush;

    public function __construct(
        private readonly string $drupalRoot,
        private readonly string $baseDomain,
        private readonly LoggerInterface $logger,
    ) {
        $this->drush = '/usr/local/bin/drush';
    }

    public function provision(string $slug, string $domain, array $db, string $siteName, string $adminEmail, string $profile = 'standard'): array {
        $this->logger->info("Drupal provisioning: {$domain}");

        // 1. Site-Verzeichnis anlegen
        $siteDir = "{$this->drupalRoot}/web/sites/{$domain}";
        if (!is_dir($siteDir)) {
            mkdir($siteDir, 0755, true);
        }
        mkdir("{$siteDir}/files", 0755, true);

        // 2. DB-Passwort in MariaDB setzen
        $this->setDbPassword($db);

        // 3. settings.php generieren
        $this->writeSettings($domain, $db, $siteDir);

        // 4. sites.php eintragen
        $this->registerSite($domain);

        // 5. Drush site:install
        $adminPass = $this->siteInstall($domain, $siteName, $adminEmail, $profile);

        // 6. Berechtigungen setzen
        $this->setPermissions($siteDir);

        $this->logger->info("Drupal provisioned: {$domain}");

        return [
            'admin_user'     => 'admin',
            'admin_password' => $adminPass,
        ];
    }

    private function setDbPassword(array $db): void {
        $pdo = new \PDO(
            "mysql:host=127.0.0.1;port=3306",
            'root',
            $_ENV['DB_ROOT_PASSWORD'] ?? '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $user = addslashes($db['db_user']);
        $pass = addslashes($db['db_password']);
        $pdo->exec("ALTER USER '{$user}'@'%' IDENTIFIED BY '{$pass}'; FLUSH PRIVILEGES;");
    }

    private function writeSettings(string $domain, array $db, string $siteDir): void {
        $hash = bin2hex(random_bytes(32));
        $slug = explode('.', $domain)[0];

        $settings = <<<PHP
<?php
\$databases['default']['default'] = [
  'driver'    => 'mysql',
  'host'      => '127.0.0.1',
  'port'      => '3306',
  'database'  => '{$db['db_name']}',
  'username'  => '{$db['db_user']}',
  'password'  => '{$db['db_password']}',
  'prefix'    => '',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'collation' => 'utf8mb4_general_ci',
];

\$settings['hash_salt'] = '{$hash}';
\$settings['trusted_host_patterns'] = [
  '^' . str_replace('-', '\\-', '{$domain}') . '\$',
];
\$settings['file_public_path']  = 'sites/{$domain}/files';
\$settings['file_private_path'] = '/var/www/platformsync-private/{$domain}';
\$settings['config_sync_directory'] = '/var/www/platformsync/config/sync/{$slug}';
\$settings['civicos_slug'] = '{$slug}';
PHP;

        file_put_contents("{$siteDir}/settings.php", $settings);
        chmod("{$siteDir}/settings.php", 0640);
    }

    private function registerSite(string $domain): void {
        $sitesPhp = "{$this->drupalRoot}/web/sites/sites.php";
        $entry    = "\$sites['{$domain}'] = '{$domain}';\n";

        if (!str_contains(file_get_contents($sitesPhp), $entry)) {
            file_put_contents($sitesPhp, $entry, FILE_APPEND);
            $this->logger->info("sites.php: {$domain} eingetragen");
        }
    }

    private function siteInstall(string $domain, string $siteName, string $adminEmail, string $profile = 'standard'): string {
        $adminPass = bin2hex(random_bytes(8));

        $cmd = sprintf(
            '%s --root=%s --uri=%s site:install %s --site-name=%s --account-mail=%s --account-name=admin --account-pass=%s --locale=de -y 2>&1',
            escapeshellarg($this->drush),
            escapeshellarg("{$this->drupalRoot}/web"),
            escapeshellarg("https://{$domain}"),
            escapeshellarg($profile),
            escapeshellarg($siteName),
            escapeshellarg($adminEmail),
            escapeshellarg($adminPass),
        );

        $this->logger->info("Drush: {$cmd}");
        $output = shell_exec($cmd);
        $this->logger->info("Drush output: {$output}");

        if (!str_contains($output, 'Installation complete')) {
            throw new \RuntimeException("Drush site:install fehlgeschlagen: {$output}");
        }

        return $adminPass;
    }

    private function setPermissions(string $siteDir): void {
        shell_exec("chown -R www-data:www-data {$siteDir}");
        shell_exec("chmod -R 755 {$siteDir}/files");
    }

    public function deprovision(string $domain): void {
        $siteDir  = "{$this->drupalRoot}/web/sites/{$domain}";
        $sitesPhp = "{$this->drupalRoot}/web/sites/sites.php";

        // Aus sites.php entfernen
        if (file_exists($sitesPhp)) {
            $content = file_get_contents($sitesPhp);
            $content = str_replace("\$sites['{$domain}'] = '{$domain}';\n", '', $content);
            file_put_contents($sitesPhp, $content);
        }

        // Archivieren
        $archive = "/var/civicos-archive/{$domain}-" . date('Ymd-His') . ".tar.gz";
        @mkdir('/var/civicos-archive', 0755, true);
        shell_exec("tar czf {$archive} -C {$this->drupalRoot}/web/sites {$domain} 2>&1");

        // Löschen
        shell_exec("rm -rf " . escapeshellarg($siteDir));
        $this->logger->info("Deprovisioned: {$domain} → {$archive}");
    }
}
