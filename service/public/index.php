<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Clock\SystemClock;
use App\Config\Config;
use App\Exception\BadRequestException;
use App\Exception\DomainException;
use App\Exception\MethodNotAllowedException;
use App\Exception\NotFoundException;
use App\Exception\PlatformException;
use App\Http\Controller\DailyChargeController;
use App\Http\Controller\MoSmsController;
use App\Http\Controller\OptInController;
use App\Http\Controller\StatusController;
use App\Http\Controller\SubscriptionWebhookController;
use App\Http\Request;
use App\Http\Response;
use App\Platform\PlatformClient;
use App\Repository\ChargeAttemptRepository;
use App\Repository\PdoFactory;
use App\Repository\ProcessedWebhookEventRepository;
use App\Repository\SubscriberRepository;
use App\Service\ChargeCalculator;
use App\Service\ChargeService;
use App\Service\DailyChargeJob;
use App\Service\OptInService;
use App\Service\OptOutService;
use App\Service\StatusService;
use App\Service\WebhookService;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use GuzzleHttp\Client as GuzzleClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

// Bootstrap: Config's constructor fails fast on missing DB_* / platform credentials.
$config = new Config();
$pdo = PdoFactory::fromEnv();
$log = new Logger('daily-pass');
$log->pushHandler(new StreamHandler('php://stderr', Logger::INFO));
$clock = new SystemClock();
$platform = new PlatformClient($config, new GuzzleClient(), $log);
$subscribers = new SubscriberRepository($pdo);
$attempts = new ChargeAttemptRepository($pdo);
$calculator = new ChargeCalculator($config);
$charges = new ChargeService($pdo, $attempts, $subscribers, $calculator, $platform, $log);

$optInController = new OptInController(new OptInService($pdo, $subscribers, $platform));
$statusController = new StatusController(new StatusService($pdo, $clock));
$webhookController = new SubscriptionWebhookController(
    new WebhookService($log, new ProcessedWebhookEventRepository($pdo), $subscribers),
    $log,
);
$dailyChargeController = new DailyChargeController(new DailyChargeJob($pdo, $charges, $log));
$moSmsController = new MoSmsController(new OptOutService($pdo, $subscribers, $platform), $log);

$defineRoutes = require __DIR__ . '/../src/Http/routes.php';

$dispatcher = FastRoute\simpleDispatcher(
    static function (RouteCollector $r) use ($defineRoutes, $optInController, $statusController, $webhookController, $dailyChargeController, $moSmsController): void {
        $handlers = [
            'health' => static fn (Request $req): Response => Response::json(200, [
                'ok' => true,
                'service' => 'service',
            ]),
            'optIn' => static fn (Request $req): Response => $optInController->handle($req),
            'status' => static fn (Request $req): Response => $statusController->handle($req),
            'webhook' => static fn (Request $req): Response => $webhookController->handle($req),
            'dailyCharge' => static fn (Request $req): Response => $dailyChargeController->handle($req),
            'moSms' => static fn (Request $req): Response => $moSmsController->handle($req),
        ];
        $defineRoutes($r, $handlers);
    }
);

$request = Request::fromGlobals();
$routeInfo = $dispatcher->dispatch($request->method, $request->path);

try {
    switch ($routeInfo[0]) {
        case Dispatcher::NOT_FOUND:
            throw new NotFoundException('Not found');
        case Dispatcher::METHOD_NOT_ALLOWED:
            throw new MethodNotAllowedException($routeInfo[1] ?? []);
        case Dispatcher::FOUND:
            $handler = $routeInfo[1];
            $vars = $routeInfo[2] ?? [];
            $result = $handler($request->withParams($vars));
            if (!$result instanceof Response) {
                throw new \RuntimeException('Invalid handler result');
            }
            $result->emit();
            break;
        default:
            throw new \RuntimeException('Routing failure');
    }
} catch (MethodNotAllowedException $e) {
    if ($e->allowedMethods() !== []) {
        header('Allow: ' . implode(', ', $e->allowedMethods()));
    }
    Response::json(405, [
        'ok' => false,
        'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed'],
    ])->emit();
} catch (NotFoundException $e) {
    Response::json(404, [
        'ok' => false,
        'error' => ['code' => 'NOT_FOUND', 'message' => $e->getMessage()],
    ])->emit();
} catch (BadRequestException $e) {
    Response::json(400, [
        'ok' => false,
        'error' => ['code' => 'BAD_REQUEST', 'message' => $e->getMessage()],
    ])->emit();
} catch (PlatformException $e) {
    Response::json(502, [
        'ok' => false,
        'error' => ['code' => 'PLATFORM_ERROR', 'message' => $e->getMessage()],
    ])->emit();
} catch (DomainException $e) {
    Response::json(400, [
        'ok' => false,
        'error' => ['code' => 'BAD_REQUEST', 'message' => $e->getMessage()],
    ])->emit();
} catch (Throwable) {
    // Never leak stack traces or SQL to the client.
    Response::json(500, [
        'ok' => false,
        'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Internal server error'],
    ])->emit();
}
