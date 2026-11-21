<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Json;
use App\Http\Request;
use App\Http\Response;
use App\Service\WebhookService;
use Psr\Log\LoggerInterface;

final class SubscriptionWebhookController
{
    public function __construct(
        private readonly WebhookService $service,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * Always HTTP 200 with S1000: a 5xx makes the Platform retry forever.
     * Internal errors are logged, never echoed back.
     */
    public function handle(Request $request): Response
    {
        try {
            $result = $this->service->handleSubscription(Json::decode($request->body));
        } catch (\Throwable $e) {
            $this->log->error('subscription webhook failed', ['error' => $e->getMessage()]);
            $result = ['statusCode' => 'S1000', 'statusDetail' => 'Success'];
        }

        return Response::json(200, $result);
    }
}
