<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Exception\DomainException;
use App\Exception\MethodNotAllowedException;
use App\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

$defineRoutes = require __DIR__ . '/../src/Http/routes.php';

$dispatcher = FastRoute\simpleDispatcher(static function (RouteCollector $r) use ($defineRoutes): void {
    $defineRoutes($r);
});

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
            $req = $request->withParams($vars);
            $result = $handler($req);
            if ($result instanceof Response) {
                $response = $result;
            } elseif (is_array($result)) {
                $response = Response::json(200, $result);
            } else {
                throw new \RuntimeException('Invalid handler result');
            }
            $response->emit();
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
        'error' => ['code' => 'method_not_allowed', 'message' => 'Method not allowed'],
    ])->emit();
} catch (NotFoundException $e) {
    Response::json(404, [
        'ok' => false,
        'error' => ['code' => 'not_found', 'message' => 'Not found'],
    ])->emit();
} catch (DomainException $e) {
    // More specific mapping will be added in later iterations.
    Response::json(400, [
        'ok' => false,
        'error' => ['code' => 'bad_request', 'message' => $e->getMessage()],
    ])->emit();
} catch (Throwable $e) {
    Response::json(500, [
        'ok' => false,
        'error' => ['code' => 'internal_error', 'message' => 'Internal server error'],
    ])->emit();
}
