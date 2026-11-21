<?php

declare(strict_types=1);

namespace App\Platform;

use App\Config\Config;
use App\Exception\PlatformException;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/** The single place where the service talks to the Carrier Platform. */
final class PlatformClient
{
    private readonly string $baseUrl;
    private readonly string $applicationId;
    private readonly string $password;
    private readonly string $shortcode;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
        private readonly LoggerInterface $log,
    ) {
        $this->baseUrl = rtrim($config->getString('PLATFORM_BASE_URL'), '/');
        $this->applicationId = $config->getString('PLATFORM_APP_ID');
        $this->password = $config->getString('PLATFORM_PASSWORD');
        $this->shortcode = $config->getString('SHORTCODE');
    }

    /**
     * §A — opt-in ("1") / opt-out ("0").
     *
     * @return array<string, mixed>
     */
    public function sendSubscription(string $subscriberId, string $action): array
    {
        $response = $this->post('/subscription/send', [
            'applicationId' => $this->applicationId,
            'password' => $this->password,
            'version' => '1.0',
            'action' => $action,
            'subscriberId' => $subscriberId,
        ]);

        $this->log->info('platform subscription/send', $this->safeContext($response, $subscriberId));
        $this->assertSuccess($response);

        return $response;
    }

    /**
     * §C — MT SMS.
     *
     * @return array<string, mixed>
     */
    public function sendSms(string $subscriberId, string $message): array
    {
        $response = $this->post('/sms/send', [
            'applicationId' => $this->applicationId,
            'password' => $this->password,
            'message' => $message,
            'destinationAddresses' => [$subscriberId],
            'version' => '1.0',
            'sourceAddress' => $this->shortcode,
            'encoding' => '0',
        ]);

        $this->log->info('platform sms/send', $this->safeContext($response, $subscriberId));
        $this->assertSuccess($response);

        return $response;
    }

    /**
     * §D — deliberately left unimplemented until the charge job exists.
     * The signature is fixed now so callers can be typed later without churn.
     *
     * @return array<string, mixed>
     */
    public function directDebit(
        string $externalTrxId,
        string $subscriberId,
        string $amount,
        string $currency,
        string $paymentInstrumentName = 'Mobile Account',
    ): array {
        throw new \LogicException('directDebit not implemented until Iteration 6');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = $this->http->request('POST', $this->baseUrl . $path, [
            'json' => $payload,
            'timeout' => 5,
            'connect_timeout' => 2,
            'http_errors' => false,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $response */
    private function assertSuccess(array $response): void
    {
        $code = $response['statusCode'] ?? null;
        if ($code === 'S1000') {
            return;
        }

        $detail = $response['statusDetail'] ?? null;
        throw new PlatformException(
            is_string($code) ? $code : 'UNKNOWN',
            is_string($detail) ? $detail : 'Platform error',
        );
    }

    /**
     * Only requestId / statusCode / externalTrxId / referenceId and the masked
     * subscriber are ever logged. Credentials are never included.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function safeContext(array $response, string $subscriberId): array
    {
        $context = [
            'requestId' => $response['requestId'] ?? null,
            'statusCode' => $response['statusCode'] ?? null,
            'subscriber' => Msisdn::mask($subscriberId),
        ];
        foreach (['externalTrxId', 'referenceId'] as $key) {
            if (isset($response[$key])) {
                $context[$key] = $response[$key];
            }
        }

        return $context;
    }
}
