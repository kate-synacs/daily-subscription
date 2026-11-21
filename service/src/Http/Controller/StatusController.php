<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Exception\BadRequestException;
use App\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\Platform\Msisdn;
use App\Service\StatusService;

final class StatusController
{
    public function __construct(private readonly StatusService $service)
    {
    }

    public function handle(Request $request): Response
    {
        $subscriberId = $request->params['subscriberId'] ?? '';
        if (!is_string($subscriberId) || !Msisdn::validate($subscriberId)) {
            throw new BadRequestException('Invalid subscriberId');
        }

        try {
            $state = $this->service->get($subscriberId);
        } catch (NotFoundException $e) {
            return Response::json(404, [
                'ok' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => $e->getMessage()],
            ]);
        }

        return Response::json(200, array_merge(['ok' => true], $state));
    }
}
