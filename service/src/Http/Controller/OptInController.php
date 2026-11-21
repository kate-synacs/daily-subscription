<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Exception\BadRequestException;
use App\Exception\PlatformException;
use App\Http\Json;
use App\Http\Request;
use App\Http\Response;
use App\Platform\Msisdn;
use App\Service\OptInService;

final class OptInController
{
    public function __construct(private readonly OptInService $service)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            $body = Json::decode($request->body);
            $subscriberId = $body['subscriberId'] ?? null;
            if (!is_string($subscriberId) || !Msisdn::validate($subscriberId)) {
                throw new BadRequestException('Invalid subscriberId');
            }

            $result = $this->service->optIn($subscriberId);

            return Response::json(200, array_merge(['ok' => true], $result));
        } catch (BadRequestException $e) {
            return $this->error(400, 'BAD_REQUEST', $e->getMessage());
        } catch (PlatformException $e) {
            return $this->error(502, 'PLATFORM_ERROR', $e->getMessage());
        }
    }

    private function error(int $status, string $code, string $message): Response
    {
        return Response::json($status, [
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
