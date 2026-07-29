<?php

namespace HiEvents\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;

class AppleWalletConfigurationService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.apple_wallet.pass_type_identifier'))
            && ! empty(config('services.apple_wallet.team_identifier'))
            && ! empty(config('services.apple_wallet.certificate_path'))
            && ! empty(config('services.apple_wallet.wwdr_certificate_path'));
    }

    /**
     * @throws WalletNotConfiguredException
     */
    public function getPassTypeIdentifier(): string
    {
        return $this->getRequired('pass_type_identifier');
    }

    /**
     * @throws WalletNotConfiguredException
     */
    public function getTeamIdentifier(): string
    {
        return $this->getRequired('team_identifier');
    }

    public function getOrganizationName(): string
    {
        return config('services.apple_wallet.organization_name') ?: config('app.name', 'Hi.Events');
    }

    /**
     * @throws WalletNotConfiguredException
     */
    public function getCertificatePath(): string
    {
        return $this->getRequired('certificate_path');
    }

    public function getCertificatePassword(): string
    {
        return (string) config('services.apple_wallet.certificate_password', '');
    }

    /**
     * @throws WalletNotConfiguredException
     */
    public function getWwdrCertificatePath(): string
    {
        return $this->getRequired('wwdr_certificate_path');
    }

    /**
     * @throws WalletNotConfiguredException
     */
    private function getRequired(string $key): string
    {
        $value = config('services.apple_wallet.'.$key);

        if (empty($value)) {
            throw new WalletNotConfiguredException(
                __('Apple Wallet is not configured. Missing :key.', ['key' => $key])
            );
        }

        return $value;
    }
}
