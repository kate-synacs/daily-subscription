<?php

declare(strict_types=1);

use FastRoute\RouteCollector;

/**
 * @param array<string, callable(App\Http\Request): App\Http\Response> $handlers
 */
return static function (RouteCollector $r, array $handlers): void {
    $r->addRoute('GET', '/health', $handlers['health']);
    $r->addRoute('POST', '/subscribers/opt-in', $handlers['optIn']);
    $r->addRoute('GET', '/subscribers/{subscriberId}', $handlers['status']);
    $r->addRoute('POST', '/webhooks/subscription', $handlers['webhook']);
    $r->addRoute('POST', '/webhooks/mo-sms', $handlers['moSms']);
    $r->addRoute('POST', '/jobs/daily-charge', $handlers['dailyCharge']);
};
