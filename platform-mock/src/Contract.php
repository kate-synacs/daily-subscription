<?php

declare(strict_types=1);

namespace Mock;

/** Implements the Platform contract (§A, §C, §D of docs/ASSIGNMENT.md). */
final class Contract
{
    public function __construct(private readonly MockState $state)
    {
    }

    /**
     * §A — opt-in / opt-out. The Platform contract is always HTTP 200; invalid
     * input is reported via statusDetail instead of a 4xx.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function subscriptionSend(array $body): array
    {
        $action = $body['action'] ?? null;
        $subscriberId = $body['subscriberId'] ?? null;

        $valid = in_array($action, ['0', '1'], true)
            && is_string($subscriberId)
            && preg_match('/^tel:\d+$/', $subscriberId) === 1;

        $response = [
            'version' => '1.0',
            'requestId' => (string) (int) (microtime(true) * 1000),
            'statusCode' => 'S1000',
            'statusDetail' => $valid ? 'SUCCESS' : 'INVALID_REQUEST',
            'subscriptionStatus' => $valid && $action === '1' ? 'REGISTERED' : 'UNREGISTERED',
        ];

        $state = $this->state->load();
        $state['subscriptions'][] = [
            'action' => is_string($action) ? $action : '',
            'subscriberId' => is_string($subscriberId) ? $subscriberId : '',
            'statusCode' => $response['statusCode'],
            'at' => gmdate('c'),
        ];
        $this->state->save($state);

        return $response;
    }

    /**
     * §C — MT SMS. Appends to the observable SMS log.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function smsSend(array $body): array
    {
        $destinations = $body['destinationAddresses'] ?? [];
        $to = is_array($destinations) && isset($destinations[0]) && is_string($destinations[0])
            ? $destinations[0]
            : '';

        $state = $this->state->load();
        $state['sms'][] = [
            'direction' => 'MT',
            'to' => $to,
            'message' => is_string($body['message'] ?? null) ? $body['message'] : '',
            'at' => gmdate('c'),
        ];
        $this->state->save($state);

        return ['statusCode' => 'S1000', 'statusDetail' => 'Success'];
    }

    /**
     * §D — direct debit. Outcome comes from the admin-configured mode; every
     * call (including rejected ones) is recorded in the debit log.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function debit(array $body): array
    {
        $state = $this->state->load();
        $externalTrxId = $this->stringField($body, 'externalTrxId');
        $subscriberId = $this->stringField($body, 'subscriberId');
        $amount = $this->stringField($body, 'amount');
        $currency = $this->stringField($body, 'currency');
        $mode = is_string($state['mode'] ?? null) ? $state['mode'] : 'S1000';

        $referenceId = null;

        if (preg_match('/^\d+\.\d{2}$/', $amount) !== 1) {
            // The mock is allowed to reject malformed money as a permanent error.
            $code = 'E1308';
            $detail = 'Invalid amount';
        } elseif ($mode === 'auto-dup') {
            $seen = is_array($state['seenExternalTrxIds'] ?? null) ? $state['seenExternalTrxIds'] : [];
            if (in_array($externalTrxId, $seen, true)) {
                $code = 'E1337';
                $detail = 'Duplicate request';
            } else {
                $code = 'S1000';
                $detail = 'Request was successfully processed';
                $referenceId = $this->randomDigits(8);
            }
        } else {
            $code = $mode;
            $detail = match ($mode) {
                'S1000' => 'Request was successfully processed',
                'E1326' => 'Insufficient balance',
                'E1308' => 'Permanent error',
                'E1318' => 'Per-second limit exceeded',
                'E1337' => 'Duplicate request',
                default => 'Unknown mode',
            };
            if ($mode === 'S1000') {
                $referenceId = $this->randomDigits(8);
            }
        }

        // Every successful debit is remembered, in any mode, so switching the
        // mock to auto-dup later can reproduce E1337 for an earlier real charge.
        if ($code === 'S1000') {
            $state['seenExternalTrxIds'][] = $externalTrxId;
        }

        $entry = [
            'externalTrxId' => $externalTrxId,
            'subscriberId' => $subscriberId,
            'amount' => $amount,
            'currency' => $currency,
            'statusCode' => $code,
            'at' => gmdate('c'),
        ];
        if ($referenceId !== null) {
            $entry['referenceId'] = $referenceId;
        }
        $state['debits'][] = $entry;
        $this->state->save($state);

        $response = [
            'statusCode' => $code,
            'statusDetail' => $detail,
            'externalTrxId' => $externalTrxId,
        ];
        if ($code === 'S1000') {
            $response['internalTrxId'] = $this->randomDigits(15);
            $response['referenceId'] = $referenceId;
            $response['timeStamp'] = gmdate('c');
        }

        return $response;
    }

    /** @param array<string, mixed> $body */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function randomDigits(int $length): string
    {
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }
}
