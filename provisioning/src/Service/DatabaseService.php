<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

class DatabaseService {
    private \PDO $pdo;

    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $rootUser,
        private readonly string $rootPass,
    ) {
        $this->pdo = new \PDO(
            "mysql:host={$host};port={$port}",
            $rootUser,
            $rootPass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    public function createForSite(string $slug): array {
        $dbName = $this->dbName($slug);
        $dbUser = $this->dbUser($slug);
        $dbPass = $this->generatePassword();

        $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'%' IDENTIFIED BY '{$dbPass}'");
        $this->pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'%'");
        $this->pdo->exec("FLUSH PRIVILEGES");

        return [
            'db_name'     => $dbName,
            'db_user'     => $dbUser,
            'db_password' => $dbPass,
            'db_host'     => $this->host,
        ];
    }

    public function removeForSite(string $slug): void {
        $dbName = $this->dbName($slug);
        $dbUser = $this->dbUser($slug);
        $this->pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        $this->pdo->exec("DROP USER IF EXISTS '{$dbUser}'@'%'");
        $this->pdo->exec("FLUSH PRIVILEGES");
    }

    public function dbName(string $slug): string {
        return 'civicos_' . preg_replace('/[^a-z0-9_]/', '_', $slug);
    }

    public function dbUser(string $slug): string {
        return substr('civ_' . preg_replace('/[^a-z0-9_]/', '_', $slug), 0, 32);
    }

    private function generatePassword(): string {
        return bin2hex(random_bytes(20));
    }
}
