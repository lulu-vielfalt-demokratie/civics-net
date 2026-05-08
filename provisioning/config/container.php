<?php
declare(strict_types=1);

use CivicOS\Provisioning\Service\DatabaseService;
use CivicOS\Provisioning\Service\DrupalService;
use CivicOS\Provisioning\Service\DnsService;
use CivicOS\Provisioning\Service\TraefikService;
use CivicOS\Provisioning\Service\HetznerService;
use CivicOS\Provisioning\Service\JwtService;
use CivicOS\Provisioning\Service\NotificationService;
use CivicOS\Provisioning\Service\SiteRegistry;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use function DI\create;
use function DI\get;
use function DI\value;

return [

    LoggerInterface::class => function () {
        $logger = new Logger('civicos-provisioning');
        $logger->pushHandler(new StreamHandler(
            $_ENV['LOG_PATH'] ?? 'php://stdout',
            $_ENV['LOG_LEVEL'] ?? 'info',
        ));
        return $logger;
    },

    \GuzzleHttp\Client::class => create(\GuzzleHttp\Client::class)
        ->constructor(['timeout' => 30.0]),

    JwtService::class => create(JwtService::class)
        ->constructor(
            secret: value($_ENV['JWT_SECRET'] ?? ''),
            expiry: value(3600),
        ),

    DatabaseService::class => create(DatabaseService::class)
        ->constructor(
            host:     value($_ENV['DB_HOST'] ?? '172.17.0.1'),
            port:     value((int)($_ENV['DB_PORT'] ?? 3306)),
            rootUser: value($_ENV['DB_ROOT_USER'] ?? 'root'),
            rootPass: value($_ENV['DB_ROOT_PASSWORD'] ?? ''),
        ),

    DrupalService::class => create(DrupalService::class)
        ->constructor(
            drupalRoot: value($_ENV['DRUPAL_ROOT'] ?? '/var/www/civicos-drupal'),
            baseDomain: value($_ENV['CIVICOS_BASE_DOMAIN'] ?? 'civicos.de'),
            logger:     get(LoggerInterface::class),
        ),

    HetznerService::class => create(HetznerService::class)
        ->constructor(
            apiToken:   value($_ENV['HETZNER_API_TOKEN'] ?? ''),
            serverType: value('cx22'),
            location:   value('nbg1'),
            image:      value('debian-12'),
            client:     get(\GuzzleHttp\Client::class),
            logger:     get(LoggerInterface::class),
        ),

    DnsService::class => create(DnsService::class)
        ->constructor(
            hetzner:    get(HetznerService::class),
            baseDomain: value($_ENV['CIVICOS_BASE_DOMAIN'] ?? 'civicos.de'),
            logger:     get(LoggerInterface::class),
        ),

    TraefikService::class => create(TraefikService::class)
        ->constructor(
            configDir: value($_ENV['TRAEFIK_CONFIG_DIR'] ?? '/opt/civicos/traefik/conf.d'),
            network:   value('civicos-net'),
            logger:    get(LoggerInterface::class),
        ),

    SiteRegistry::class => create(SiteRegistry::class)
        ->constructor(
            dbPath: value($_ENV['REGISTRY_DB_PATH'] ?? '/opt/civicos/data/registry.sqlite'),
            logger: get(LoggerInterface::class),
        ),

    NotificationService::class => create(NotificationService::class)
        ->constructor(
            apiKey:    value($_ENV['POSTSTACK_API_KEY'] ?? ''),
            fromEmail: value($_ENV['POSTSTACK_FROM_EMAIL'] ?? ''),
            fromName:  value($_ENV['POSTSTACK_FROM_NAME'] ?? 'CivicOS'),
            client:    get(\GuzzleHttp\Client::class),
            logger:    get(LoggerInterface::class),
        ),
];
