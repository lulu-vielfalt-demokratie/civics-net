<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use Psr\Log\LoggerInterface;

class DnsService {
    public function __construct(
        private readonly HetznerService $hetzner,
        private readonly string $baseDomain,
        private readonly LoggerInterface $logger,
    ) {}

    public function createCname(string $slug): void {
        $this->logger->info("DNS: {$slug}.{$this->baseDomain} – Netcup TODO");
    }

    public function removeRecords(string $slug): void {
        $this->logger->info("DNS: remove {$slug}.{$this->baseDomain}");
    }
}
