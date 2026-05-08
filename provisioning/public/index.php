<?php
declare(strict_types=1);

use CivicOS\Provisioning\Middleware\AuthMiddleware;
use CivicOS\Provisioning\Middleware\JsonMiddleware;
use CivicOS\Provisioning\Controller\ProvisioningController;
use CivicOS\Provisioning\Controller\MetricsController;
use CivicOS\Provisioning\Controller\AuthController;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addRoutingMiddleware();
$app->add(JsonMiddleware::class);
$app->addErrorMiddleware(
    displayErrorDetails: $_ENV['APP_ENV'] === 'development',
    logErrors: true,
    logErrorDetails: true,
);

$app->post('/api/v1/auth/token', [AuthController::class, 'token']);

$app->group('/api/v1/sites', function ($group) {
    $group->get('',           [ProvisioningController::class, 'list']);
    $group->post('',          [ProvisioningController::class, 'provision']);
    $group->get('/{slug}',    [ProvisioningController::class, 'detail']);
    $group->delete('/{slug}', [ProvisioningController::class, 'deprovision']);
    $group->post('/{slug}/suspend', [ProvisioningController::class, 'suspend']);
    $group->post('/{slug}/restore', [ProvisioningController::class, 'restore']);
})->add(AuthMiddleware::class);

$app->post('/api/v1/metrics', [MetricsController::class, 'ingest']);

$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'status'  => 'ok',
        'service' => 'civicos-provisioning',
        'version' => '1.0.0',
    ]));
    return $response;
});

$app->run();
