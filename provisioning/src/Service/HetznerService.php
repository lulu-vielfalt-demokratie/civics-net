<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class HetznerService {
    public function __construct(
        private readonly string $apiToken,
        private readonly string $serverType,
        private readonly string $location,
        private readonly string $image,
        private readonly Client $client,
        private readonly LoggerInterface $logger,
    ) {}

    public function getDnsZoneId(string $domain): string {
        throw new \RuntimeException("Hetzner DNS not used – using Netcup");
    }

    public function createDnsRecord(string $zoneId, string $type, string $name, string $value, int $ttl = 300): array {
        return [];
    }

    public function deleteDnsRecord(string $zoneId, string $name): void {}
}
