<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use CivicOS\Provisioning\Model\Site;
use Psr\Log\LoggerInterface;

class SiteRegistry {
    private \PDO $pdo;

    public function __construct(
        private readonly string $dbPath,
        private readonly LoggerInterface $logger,
    ) {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->pdo = new \PDO("sqlite:{$this->dbPath}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    private function migrate(): void {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sites (
                slug              TEXT PRIMARY KEY,
                name              TEXT NOT NULL,
                contact_email     TEXT NOT NULL,
                plan              TEXT NOT NULL DEFAULT 'free',
                status            TEXT NOT NULL DEFAULT 'provisioning',
                domain            TEXT NOT NULL,
                db_name           TEXT NOT NULL,
                api_key           TEXT NOT NULL,
                created_at        INTEGER NOT NULL,
                provisioned_at    INTEGER,
                error_message     TEXT,
                hetzner_server_id INTEGER
            );
            CREATE TABLE IF NOT EXISTS metrics (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                slug        TEXT NOT NULL,
                event       TEXT NOT NULL,
                payload     TEXT NOT NULL,
                received_at INTEGER NOT NULL,
                FOREIGN KEY (slug) REFERENCES sites(slug)
            );
        SQL);
    }

    public function register(Site $site): void {
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO sites
                (slug, name, contact_email, plan, status, domain, db_name, api_key, created_at)
            VALUES
                (:slug, :name, :contact_email, :plan, :status, :domain, :db_name, :api_key, :created_at)
        SQL);
        $stmt->execute([
            ':slug'          => $site->slug,
            ':name'          => $site->name,
            ':contact_email' => $site->contactEmail,
            ':plan'          => $site->plan,
            ':status'        => $site->status,
            ':domain'        => $site->domain,
            ':db_name'       => $site->dbName,
            ':api_key'       => $site->apiKey,
            ':created_at'    => $site->createdAt,
        ]);
        $this->logger->info("Site registered: {$site->slug}");
    }

    public function markActive(string $slug): void {
        $this->pdo->prepare(
            'UPDATE sites SET status = ?, provisioned_at = ? WHERE slug = ?'
        )->execute([Site::STATUS_ACTIVE, time(), $slug]);
    }

    public function markError(string $slug, string $message): void {
        $this->pdo->prepare(
            'UPDATE sites SET status = ?, error_message = ? WHERE slug = ?'
        )->execute([Site::STATUS_ERROR, $message, $slug]);
        $this->logger->error("Provisioning error for {$slug}: {$message}");
    }

    public function setStatus(string $slug, string $status): void {
        $this->pdo->prepare(
            'UPDATE sites SET status = ? WHERE slug = ?'
        )->execute([$status, $slug]);
    }

    public function find(string $slug): ?Site {
        $stmt = $this->pdo->prepare('SELECT * FROM sites WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? Site::fromRow($row) : null;
    }

    public function findAll(?string $status = null): array {
        if ($status) {
            $stmt = $this->pdo->prepare('SELECT * FROM sites WHERE status = ? ORDER BY created_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $this->pdo->query('SELECT * FROM sites ORDER BY created_at DESC');
        }
        return array_map(
            fn(array $row) => Site::fromRow($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function recordMetric(string $slug, string $event, array $payload): void {
        $this->pdo->prepare(
            'INSERT INTO metrics (slug, event, payload, received_at) VALUES (?, ?, ?, ?)'
        )->execute([$slug, $event, json_encode($payload), time()]);
    }

    public function slugExists(string $slug): bool {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sites WHERE slug = ?');
        $stmt->execute([$slug]);
        return (bool) $stmt->fetch();
    }
}
