<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Json;
use App\Http\Request;
use App\Http\Response;
use App\Platform\Msisdn;
use App\Service\OptOutService;
use Psr\Log\LoggerInterface;

final class MoSmsController
{
    public function __construct(
        private readonly OptOutService $optOut,
        private readonly LoggerInterface $log,
    ) {
    }

    /** MO SMS per §C: the Platform always gets S1000, even for ignored commands. */
    public function handle(Request $request): Response
    {
        try {
            $body = Json::decode($request->body);
            $sourceAddress = $body['sourceAddress'] ?? null;
            $message = $body['message'] ?? null;

            if (is_string($sourceAddress) && is_string($message) && strcasecmp(trim($message), 'STOP') === 0) {
                if (Msisdn::validate($sourceAddress)) {
                    $this->optOut->optOut($sourceAddress);
                } else {
                    $this->log->warning('MO STOP with invalid sourceAddress');
                }
            } else {
                $this->log->info('MO SMS ignored (not STOP)', ['hasSource' => is_string($sourceAddress)]);
            }
        } catch (\Throwable $e) {
            // Never 5xx the Platform: it would retry the MO indefinitely.
            $this->log->error('MO SMS handling failed', ['error' => $e->getMessage()]);
        }

        return Response::json(200, [
            'statusCode' => 'S1000',
            'statusDetail' => 'Success',
        ]);
    }
}
