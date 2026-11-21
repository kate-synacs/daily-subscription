<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Clock\SystemClock;
use App\Http\Request;
use App\Http\Response;
use App\Service\DailyChargeJob;

final class DailyChargeController
{
    public function __construct(private readonly DailyChargeJob $job)
    {
    }

    /**
     * Billing day comes only from the Clock. Any date in the request body is
     * ignored — use FrozenClock in tests for determinism.
     */
    public function handle(Request $request): Response
    {
        return Response::json(200, array_merge(
            ['ok' => true],
            $this->job->run(new SystemClock()),
        ));
    }
}
