<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Platform\Msisdn;
use PDO;
use Psr\Log\LoggerInterface;

final class DailyChargeJob
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ChargeService $charges,
        private readonly LoggerInterface $log,
    ) {
    }

    /** @return array<string, int|string> */
    public function run(Clock $clock): array
    {
        $billingDay = $clock->today();
        $summary = [
            'billingDay' => $billingDay,
            'processed' => 0,
            'charged' => 0,
            'alreadyCharged' => 0,
            'transient' => 0,
            'suspended' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $stmt = $this->pdo->query("SELECT id FROM subscribers WHERE status = 'ACTIVE' ORDER BY id");
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            $subscriberId = (string) $id;
            $summary['processed']++;

            try {
                $result = $this->charges->chargeSubscriber($subscriberId, $billingDay, $clock);
            } catch (\Throwable $e) {
                // One bad subscriber must never abort the rest of the job.
                $this->log->error('charge failed for subscriber', [
                    'subscriber' => Msisdn::mask($subscriberId),
                    'error' => $e->getMessage(),
                ]);
                $summary['errors']++;
                continue;
            }

            switch ($result['outcome'] ?? null) {
                case 'charged':
                    $summary['charged']++;
                    break;
                case 'already_charged':
                    $summary['alreadyCharged']++;
                    break;
                case 'transient':
                case 'unknown_code':
                    $summary['transient']++;
                    break;
                case 'suspended':
                    $summary['suspended']++;
                    break;
                case 'error':
                    $summary['errors']++;
                    break;
                default:
                    // skipped / race_lost
                    $summary['skipped']++;
                    break;
            }
        }

        return $summary;
    }
}
