<?php

namespace HiEvents\Services\Infrastructure\PayPal;

use HiEvents\Exceptions\PayPal\PayPalApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PayPalClient
{
    private const ACCESS_TOKEN_CACHE_KEY = 'paypal_access_token';

    private const TOKEN_EXPIRY_BUFFER_SECONDS = 60;

    public function __construct(
        private readonly PayPalConfigurationService $configurationService
    ) {}

    /**
     * @throws PayPalApiException
     */
    public function createOrder(array $payload): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->post($this->configurationService->getBaseUrl().'/v2/checkout/orders', $payload);

        return $this->decodeResponse($response, __('Failed to create PayPal order.'));
    }

    /**
     * @throws PayPalApiException
     */
    public function captureOrder(string $paypalOrderId): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->post($this->configurationService->getBaseUrl().'/v2/checkout/orders/'.$paypalOrderId.'/capture');

        return $this->decodeResponse($response, __('Failed to capture PayPal order.'));
    }

    /**
     * @throws PayPalApiException
     */
    public function verifyWebhookSignature(array $headers, string $rawBody, string $webhookId): bool
    {
        $normalizedHeaders = $this->normalizeHeaders($headers);

        $payload = [
            'auth_algo' => $normalizedHeaders['paypal-auth-algo'] ?? null,
            'cert_url' => $normalizedHeaders['paypal-cert-url'] ?? null,
            'transmission_id' => $normalizedHeaders['paypal-transmission-id'] ?? null,
            'transmission_sig' => $normalizedHeaders['paypal-transmission-sig'] ?? null,
            'transmission_time' => $normalizedHeaders['paypal-transmission-time'] ?? null,
            'webhook_id' => $webhookId,
            'webhook_event' => json_decode($rawBody, true),
        ];

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->post(
                $this->configurationService->getBaseUrl().'/v1/notifications/verify-webhook-signature',
                $payload
            );

        $data = $this->decodeResponse($response, __('Failed to verify PayPal webhook signature.'));

        return ($data['verification_status'] ?? null) === 'SUCCESS';
    }

    /**
     * @throws PayPalApiException
     */
    private function accessToken(): string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth(
                $this->configurationService->getClientId(),
                $this->configurationService->getClientSecret()
            )
            ->post(
                $this->configurationService->getBaseUrl().'/v1/oauth2/token',
                ['grant_type' => 'client_credentials']
            );

        $data = $this->decodeResponse($response, __('Failed to obtain PayPal access token.'));

        $accessToken = $data['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new PayPalApiException(__('PayPal did not return an access token.'));
        }

        $expiresIn = (int) ($data['expires_in'] ?? 0);
        $ttl = max(1, $expiresIn - self::TOKEN_EXPIRY_BUFFER_SECONDS);

        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $accessToken, $ttl);

        return $accessToken;
    }

    /**
     * @throws PayPalApiException
     */
    private function decodeResponse(Response $response, string $errorMessage): array
    {
        if ($response->failed()) {
            throw new PayPalApiException(
                $errorMessage.' '.__('HTTP status: :status', ['status' => $response->status()]).' '.$response->body()
            );
        }

        return $response->json() ?? [];
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = is_array($value) ? ($value[0] ?? null) : $value;
        }

        return $normalized;
    }
}
