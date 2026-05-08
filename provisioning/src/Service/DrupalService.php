<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use Psr\Log\LoggerInterface;

class DrupalService {
    public function __construct(
        private readonly string $drupalRoot,
        private readonly string $baseDomain,
        private readonly LoggerInterface $logger,
    ) {}

    public function provision(string $slug, string $domain, array $db, string $siteName, string $adminEmail): array {
        $this->logger->info("Drupal provisioning stub: {$domain}");
        return ['admin_user' => 'admin', 'admin_password' => bin2hex(random_bytes(12))];
    }

    public function deprovision(string $domain): void {
        $this->logger->info("Drupal deprovision stub: {$domain}");
    }
}
