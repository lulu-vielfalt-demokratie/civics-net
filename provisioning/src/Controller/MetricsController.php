<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Controller;

use CivicOS\Provisioning\Service\SiteRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MetricsController {
    public function __construct(
        private readonly SiteRegistry $registry,
    ) {}

    public function ingest(Request $request, Response $response): Response {
        $signature = $request->getHeaderLine('X-CivicOS-Signature');
        $body      = (string) $request->getBody();

        if (!$this->verifySignature($body, $signature)) {
            $response->getBody()->write(json_encode(['error' => 'invalid_signature']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $payload = json_decode($body, true);

        if (!$this->validatePayload($payload)) {
            $response->getBody()->write(json_encode(['error' => 'invalid_payload']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $site = $this->registry->find($payload['slug']);
        if (!$site || !$site->isActive()) {
            $response->getBody()->write(json_encode(['error' => 'unknown_site']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $this->registry->recordMetric($payload['slug'], $payload['event'], $payload['data'] ?? []);

        $response->getBody()->write(json_encode(['status' => 'accepted']));
        return $response->withStatus(202)->withHeader('Content-Type', 'application/json');
    }

    private function verifySignature(string $body, string $signature): bool {
        $secret   = $_ENV['METRICS_WEBHOOK_SECRET'] ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $signature);
    }

    private function validatePayload(?array $payload): bool {
        return $payload
            && isset($payload['slug'], $payload['event'], $payload['ts'])
            && is_string($payload['slug'])
            && is_string($payload['event'])
            && is_int($payload['ts'])
            && abs(time() - $payload['ts']) < 300;
    }
}
