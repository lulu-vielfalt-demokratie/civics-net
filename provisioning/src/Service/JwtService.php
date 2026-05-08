<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService {
    public function __construct(
        private readonly string $secret,
        private readonly int    $expiry = 3600,
    ) {}

    public function generate(string $role = 'admin'): string {
        $now = time();
        return JWT::encode([
            'iss'  => 'civicos-provisioning',
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + $this->expiry,
        ], $this->secret, 'HS256');
    }

    public function validate(string $token): ?array {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            return (array) $decoded;
        } catch (\Throwable) {
            return null;
        }
    }
}
