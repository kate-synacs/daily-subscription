<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Msisdn;
use App\Repository\ProcessedWebhookEventRepository;
use App\Repository\SubscriberRepository;
use Psr\Log\LoggerInterface;

final class WebhookService
{
    public function __construct(
        private readonly LoggerInterface $log,
        private readonly ProcessedWebhookEventRepository $events,
        private readonly SubscriberRepository $subscribers,
    ) {
    }

    /**
     * §B subscription notification. Never calls /caas/direct/debit — charging
     * belongs exclusively to the daily job, not to webhook delivery.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function handleSubscription(array $payload): array
    {
        foreach (['applicationId', 'subscriberId', 'status', 'version', 'timeStamp'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                $this->log->warning('subscription webhook missing field', ['field' => $field]);

                return $this->accepted();
            }
        }

        /** @var string $subscriberId */
        $subscriberId = $payload['subscriberId'];
        $eventKey = hash('sha256', implode('|', [
            $payload['applicationId'],
            $subscriberId,
            $payload['status'],
            $payload['timeStamp'],
            $payload['version'],
        ]));

        if (!$this->events->tryInsert($eventKey, $payload)) {
            $this->log->info('subscription webhook duplicate', [
                'subscriber' => Msisdn::mask($subscriberId),
            ]);

            return $this->accepted();
        }

        $status = $payload['status'];
        switch ($status) {
            case 'REGISTERED':
                $this->subscribers->upsertRegistered($subscriberId);
                break;
            case 'UNREGISTERED':
                // No Platform call and no SMS: UPDATE is a no-op when absent.
                $this->subscribers->setStatus($subscriberId, 'INACTIVE');
                break;
            case 'PENDING':
            case 'CHARGE':
                $this->log->info('subscription webhook informational', [
                    'status' => $status,
                    'subscriber' => Msisdn::mask($subscriberId),
                ]);
                break;
            default:
                $this->log->warning('subscription webhook unknown status', [
                    'status' => $status,
                    'subscriber' => Msisdn::mask($subscriberId),
                ]);
        }

        return $this->accepted();
    }

    /** @return array<string, string> */
    private function accepted(): array
    {
        return ['statusCode' => 'S1000', 'statusDetail' => 'Success'];
    }
}
