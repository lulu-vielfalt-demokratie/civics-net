<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Controller;

use CivicOS\Provisioning\Service\JwtService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController {
    public function __construct(
        private readonly JwtService $jwt,
    ) {}

    public function token(Request $request, Response $response): Response {
        $data   = (array) $request->getParsedBody();
        $apiKey = $data['api_key'] ?? '';

        if (!hash_equals($_ENV['ADMIN_API_KEY'] ?? '', $apiKey)) {
            $response->getBody()->write(json_encode(['error' => 'unauthorized']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $token = $this->jwt->generate('admin');
        $response->getBody()->write(json_encode(['token' => $token, 'expires_in' => 3600]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
