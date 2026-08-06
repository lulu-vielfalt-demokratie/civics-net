<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Verwaltet Umami-Websites für CivicOS-Tenants.
 *
 * Bei jeder Provisionierung:
 * 1. Login → Token holen
 * 2. Neue Website anlegen
 * 3. Website-ID zurückgeben → wird in SiteRegistry gespeichert
 */
class UmamiService {

    private ?string $token = null;

    public function __construct(
        private readonly string $umamiUrl,
        private readonly string $adminUser,
        private readonly string $adminPassword,
        private readonly string $teamId,
        private readonly Client $client,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Legt eine neue Umami-Website für einen Tenant an.
     * Gibt die Website-ID zurück (= Tracking-ID).
     */
    public function createSite(string $slug, string $domain): string {
        $token = $this->getToken();

        $response = $this->client->post("{$this->umamiUrl}/api/websites", [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'name'   => $slug,
                'domain' => $domain,
                'teamId' => $this->teamId,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $siteId = $data['id'];

        $this->logger->info("Umami site created: {$domain} → {$siteId}");
        return $siteId;
    }

    /**
     * Löscht eine Umami-Website bei Deprovisionierung.
     */
    public function deleteSite(string $siteId): void {
        $token = $this->getToken();

        $this->client->delete("{$this->umamiUrl}/api/websites/{$siteId}", [
            'headers' => ['Authorization' => "Bearer {$token}"],
        ]);

        $this->logger->info("Umami site deleted: {$siteId}");
    }

    /**
     * Gibt den Umami-Tracking-Script-Tag zurück.
     */
    public function getTrackingScript(string $siteId): string {
        $publicUrl = str_replace('http://127.0.0.1:3001', 'https://analytics.civicos.de', $this->umamiUrl);
        return "<script defer src=\"{$publicUrl}/script.js\" data-website-id=\"{$siteId}\"></script>";
    }

    private function getToken(): string {
        if ($this->token) {
            return $this->token;
        }

        $response = $this->client->post("{$this->umamiUrl}/api/auth/login", [
            'json' => [
                'username' => $this->adminUser,
                'password' => $this->adminPassword,
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $this->token = $data['token'];
        return $this->token;
    }
}
