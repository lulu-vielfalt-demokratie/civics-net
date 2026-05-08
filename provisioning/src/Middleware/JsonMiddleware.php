<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class JsonMiddleware implements MiddlewareInterface {
    public function process(Request $request, Handler $handler): Response {
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            if ($body) {
                $parsed = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request = $request->withParsedBody($parsed);
                }
            }
        }
        return $handler->handle($request);
    }
}
