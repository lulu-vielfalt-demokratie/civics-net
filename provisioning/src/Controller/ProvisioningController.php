<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Controller;

use CivicOS\Provisioning\Model\Site;
use CivicOS\Provisioning\Service\DatabaseService;
use CivicOS\Provisioning\Service\DnsService;
use CivicOS\Provisioning\Service\DrupalService;
use CivicOS\Provisioning\Service\NotificationService;
use CivicOS\Provisioning\Service\SiteRegistry;
use CivicOS\Provisioning\Service\TraefikService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class ProvisioningController {
    public function __construct(
        private readonly SiteRegistry       $registry,
        private readonly DatabaseService    $database,
        private readonly DrupalService      $drupal,
        private readonly DnsService         $dns,
        private readonly TraefikService     $traefik,
        private readonly NotificationService $notifications,
        private readonly LoggerInterface    $logger,
    ) {}

    public function list(Request $request, Response $response): Response {
        $status = $request->getQueryParams()['status'] ?? null;
        $sites  = $this->registry->findAll($status);
        return $this->json($response, [
            'data' => array_map(fn(Site $s) => $s->toArray(), $sites),
            'meta' => ['count' => count($sites)],
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response {
        $site = $this->registry->find($args['slug']);
        if (!$site) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }
        return $this->json($response, ['data' => $site->toArray()]);
    }

    public function provision(Request $request, Response $response): Response {
        $data   = (array) $request->getParsedBody();
        $errors = $this->validate($data);
        if ($errors) {
            return $this->json($response, ['error' => 'invalid_request', 'details' => $errors], 400);
        }

        $slug   = preg_replace('/[^a-z0-9-]/', '-', strtolower($data['slug']));
        $domain = "{$slug}.{$_ENV['CIVICOS_BASE_DOMAIN']}";

        if ($this->registry->slugExists($slug)) {
            return $this->json($response, ['error' => 'conflict', 'message' => "Slug '{$slug}' exists."], 409);
        }

        $rawApiKey = bin2hex(random_bytes(24));

        $site = new Site(
            slug:         $slug,
            name:         $data['name'],
            contactEmail: $data['contact_email'],
            plan:         $data['plan'] ?? Site::PLAN_FREE,
            status:       Site::STATUS_PROVISIONING,
            domain:       $domain,
            dbName:       $this->database->dbName($slug),
            apiKey:       password_hash($rawApiKey, PASSWORD_BCRYPT),
            createdAt:    time(),
        );

        $this->registry->register($site);

        try {
            $db = $this->database->createForSite($slug);
            $this->logger->info("[{$slug}] DB erstellt");

            $credentials = $this->drupal->provision($slug, $domain, $db, $data['name'], $data['contact_email']);
            $this->logger->info("[{$slug}] Drupal provisioniert");

            $this->dns->createCname($slug);
            $this->traefik->addSite($slug, $domain);

            $this->registry->markActive($slug);

            $this->notifications->sendWelcome(
                $data['contact_email'], $data['name'], $domain,
                $rawApiKey, $credentials['admin_user'], $credentials['admin_password'],
            );

        } catch (\Throwable $e) {
            $this->registry->markError($slug, $e->getMessage());
            $this->logger->error("[{$slug}] Fehler: {$e->getMessage()}");
            return $this->json($response, ['error' => 'provisioning_failed', 'message' => $e->getMessage()], 500);
        }

        return $this->json($response, [
            'data'    => $this->registry->find($slug)->toArray(),
            'api_key' => $rawApiKey,
            'warning' => 'API-Key nur einmalig angezeigt – sicher speichern.',
            'drupal'  => [
                'url'        => "https://{$domain}",
                'admin_user' => $credentials['admin_user'],
                'admin_pass' => $credentials['admin_password'],
            ],
        ], 201);
    }

    public function deprovision(Request $request, Response $response, array $args): Response {
        $site = $this->registry->find($args['slug']);
        if (!$site) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }
        if ($request->getHeaderLine('X-Confirm-Deprovision') !== $site->slug) {
            return $this->json($response, ['error' => 'confirmation_required', 'message' => "Header X-Confirm-Deprovision: {$site->slug} erforderlich."], 400);
        }
        try {
            $this->drupal->deprovision($site->domain);
            $this->database->removeForSite($site->slug);
            $this->dns->removeRecords($site->slug);
            $this->traefik->removeSite($site->slug);
            $this->registry->setStatus($site->slug, 'deprovisioned');
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => $e->getMessage()], 500);
        }
        return $this->json($response, ['message' => "Site {$site->slug} deprovisioned."]);
    }

    public function suspend(Request $request, Response $response, array $args): Response {
        return $this->setStatus($response, $args['slug'], Site::STATUS_SUSPENDED);
    }

    public function restore(Request $request, Response $response, array $args): Response {
        return $this->setStatus($response, $args['slug'], Site::STATUS_ACTIVE);
    }

    private function setStatus(Response $response, string $slug, string $status): Response {
        if (!$this->registry->find($slug)) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }
        $this->registry->setStatus($slug, $status);
        return $this->json($response, ['slug' => $slug, 'status' => $status]);
    }

    private function validate(array $data): array {
        $errors = [];
        if (empty($data['slug']))          $errors[] = 'slug erforderlich';
        if (empty($data['name']))          $errors[] = 'name erforderlich';
        if (empty($data['contact_email'])) $errors[] = 'contact_email erforderlich';
        if (!empty($data['contact_email']) && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'contact_email ungültig';
        }
        if (!empty($data['slug']) && !preg_match('/^[a-z0-9-]+$/', strtolower($data['slug']))) {
            $errors[] = 'slug: nur Kleinbuchstaben, Zahlen und Bindestriche';
        }
        return $errors;
    }

    private function json(Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
