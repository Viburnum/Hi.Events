<?php

namespace HiEvents\Services\Infrastructure\GoogleWallet;

use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;

class GoogleWalletConfigurationService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.google_wallet.issuer_id'))
            && ! empty(config('services.google_wallet.service_account_json'));
    }

    /**
     * @throws WalletNotConfiguredException
     */
    public function getIssuerId(): string
    {
        $issuerId = config('services.google_wallet.issuer_id');

        if (empty($issuerId)) {
            throw new WalletNotConfiguredException(__('Google Wallet issuer ID is not configured.'));
        }

        return (string) $issuerId;
    }

    public function getClassSuffix(): string
    {
        return (string) (config('services.google_wallet.class_suffix') ?: 'hievents_event_ticket');
    }

    public function getOrigin(): ?string
    {
        return config('services.google_wallet.origin') ?: config('app.frontend_url');
    }

    /**
     * Returns the parsed service account credentials (client_email, private_key, ...).
     *
     * @return array<string, mixed>
     *
     * @throws WalletNotConfiguredException
     */
    public function getServiceAccount(): array
    {
        $value = config('services.google_wallet.service_account_json');

        if (empty($value)) {
            throw new WalletNotConfiguredException(__('Google Wallet service account is not configured.'));
        }

        // The value may be inline JSON or a path to a JSON file.
        if (is_file($value)) {
            $value = (string) file_get_contents($value);
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new WalletNotConfiguredException(
                __('Google Wallet service account JSON is invalid or missing client_email/private_key.')
            );
        }

        return $decoded;
    }
}
