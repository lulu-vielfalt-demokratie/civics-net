<?php
declare(strict_types=1);

namespace CivicOS\Provisioning\Model;

final class Site {
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_SUSPENDED    = 'suspended';
    public const STATUS_ERROR        = 'error';
    public const PLAN_FREE    = 'free';
    public const PLAN_STARTER = 'starter';
    public const PLAN_PRO     = 'pro';

    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $contactEmail,
        public readonly string $plan,
        public string          $status,
        public readonly string $domain,
        public readonly string $dbName,
        public readonly string $apiKey,
        public readonly int    $createdAt,
        public ?int            $provisionedAt = null,
        public ?string         $errorMessage  = null,
        public ?int            $hetznerServerId = null,
    ) {}

    public static function fromRow(array $row): self {
        return new self(
            slug:            $row['slug'],
            name:            $row['name'],
            contactEmail:    $row['contact_email'],
            plan:            $row['plan'],
            status:          $row['status'],
            domain:          $row['domain'],
            dbName:          $row['db_name'],
            apiKey:          $row['api_key'],
            createdAt:       (int) $row['created_at'],
            provisionedAt:   isset($row['provisioned_at']) ? (int) $row['provisioned_at'] : null,
            errorMessage:    $row['error_message'] ?? null,
            hetznerServerId: isset($row['hetzner_server_id']) ? (int) $row['hetzner_server_id'] : null,
        );
    }

    public function toArray(): array {
        return [
            'slug'             => $this->slug,
            'name'             => $this->name,
            'contact_email'    => $this->contactEmail,
            'plan'             => $this->plan,
            'status'           => $this->status,
            'domain'           => $this->domain,
            'url'              => "https://{$this->domain}",
            'created_at'       => $this->createdAt,
            'provisioned_at'   => $this->provisionedAt,
            'error_message'    => $this->errorMessage,
            'dedicated_server' => $this->hetznerServerId !== null,
        ];
    }

    public function isActive(): bool {
        return $this->status === self::STATUS_ACTIVE;
    }
}
