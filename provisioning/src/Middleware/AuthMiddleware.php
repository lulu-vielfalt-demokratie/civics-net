<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Middleware;

use CivicOS\Provisioning\Service\JwtService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface {
    public function __construct(
        private readonly JwtService $jwt,
    ) {}

    public function process(Request $request, Handler $handler): Response {
        $auth = $request->getHeaderLine('Authorization');
        if (!str_starts_with($auth, 'Bearer ')) {
            return $this->unauthorized('Bearer token erforderlich.');
        }
        $token  = substr($auth, 7);
        $claims = $this->jwt->validate($token);
        if (!$claims) {
            return $this->unauthorized('Token ungültig oder abgelaufen.');
        }
        return $handler->handle($request->withAttribute('jwt_claims', $claims));
    }

    private function unauthorized(string $message): Response {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['error' => 'unauthorized', 'message' => $message]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
