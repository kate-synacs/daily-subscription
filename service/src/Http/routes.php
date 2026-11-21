<?php

declare(strict_types=1);

use FastRoute\RouteCollector;

return static function (RouteCollector $r): void {
    $r->addRoute('GET', '/health', static function (): array {
        return ['ok' => true, 'service' => 'service'];
    });
};
