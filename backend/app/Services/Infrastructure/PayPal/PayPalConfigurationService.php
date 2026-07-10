<?php

namespace HiEvents\Services\Infrastructure\PayPal;

use HiEvents\Exceptions\PayPal\PayPalClientConfigurationException;

class PayPalConfigurationService
{
    public const MODE_SANDBOX = 'sandbox';

    public const MODE_LIVE = 'live';

    /**
     * @throws PayPalClientConfigurationException
     */
    public function getClientId(): string
    {
        $clientId = config('services.paypal.client_id');

        if (empty($clientId)) {
            throw new PayPalClientConfigurationException(
                __('PayPal client ID is not configured.')
            );
        }

        return $clientId;
    }

    /**
     * @throws PayPalClientConfigurationException
     */
    public function getClientSecret(): string
    {
        $clientSecret = config('services.paypal.client_secret');

        if (empty($clientSecret)) {
            throw new PayPalClientConfigurationException(
                __('PayPal client secret is not configured.')
            );
        }

        return $clientSecret;
    }

    public function getMode(): string
    {
        return config('services.paypal.mode', self::MODE_SANDBOX);
    }

    public function getWebhookId(): ?string
    {
        return config('services.paypal.webhook_id');
    }

    public function getBaseUrl(): string
    {
        return $this->getMode() === self::MODE_LIVE
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
