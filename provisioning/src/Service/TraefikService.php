<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use Psr\Log\LoggerInterface;

class TraefikService {
    public function __construct(
        private readonly string $configDir,
        private readonly string $network,
        private readonly LoggerInterface $logger,
    ) {}

    public function addSite(string $slug, string $domain): void {
        $configFile = "{$this->configDir}/civicos-{$slug}.yml";
        file_put_contents($configFile, "# CivicOS tenant: {$slug}\n# Domain: {$domain}\n");
        $this->logger->info("Traefik config created for {$domain}");
    }

    public function removeSite(string $slug): void {
        $configFile = "{$this->configDir}/civicos-{$slug}.yml";
        if (file_exists($configFile)) {
            unlink($configFile);
        }
    }
}
