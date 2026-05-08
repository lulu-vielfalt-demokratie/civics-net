<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class NotificationService {
    private const POSTSTACK_API = 'https://api.poststack.dev/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly Client $client,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendWelcome(string $email, string $name, string $domain, string $apiKey, string $adminUser, string $adminPass): void {
        if (empty($this->apiKey)) {
            $this->logger->info("Poststack nicht konfiguriert – Mail an {$email} übersprungen");
            return;
        }
        try {
            $this->client->post(self::POSTSTACK_API . '/messages', [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'from'    => ['email' => $this->fromEmail, 'name' => $this->fromName],
                    'to'      => [['email' => $email]],
                    'subject' => "Dein CivicOS-Zugang: {$domain}",
                    'html'    => "<h2>Willkommen, {$name}!</h2><p>URL: <a href='https://{$domain}'>https://{$domain}</a></p><p>Admin: {$adminUser} / {$adminPass}</p><p>API-Key: {$apiKey}</p>",
                ],
            ]);
            $this->logger->info("Welcome-Mail gesendet an {$email}");
        } catch (\Throwable $e) {
            $this->logger->error("Welcome-Mail fehlgeschlagen: {$e->getMessage()}");
        }
    }
}
