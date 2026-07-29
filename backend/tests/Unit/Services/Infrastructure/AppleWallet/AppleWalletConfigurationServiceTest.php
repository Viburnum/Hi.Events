<?php

namespace Tests\Unit\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletConfigurationService;
use Tests\TestCase;

class AppleWalletConfigurationServiceTest extends TestCase
{
    private AppleWalletConfigurationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AppleWalletConfigurationService;
    }

    public function test_is_configured_returns_false_when_credentials_missing(): void
    {
        config([
            'services.apple_wallet.pass_type_identifier' => null,
            'services.apple_wallet.team_identifier' => null,
            'services.apple_wallet.certificate_path' => null,
            'services.apple_wallet.wwdr_certificate_path' => null,
        ]);

        $this->assertFalse($this->service->isConfigured());
    }

    public function test_is_configured_returns_true_when_all_credentials_present(): void
    {
        config([
            'services.apple_wallet.pass_type_identifier' => 'pass.com.example',
            'services.apple_wallet.team_identifier' => 'TEAM123',
            'services.apple_wallet.certificate_path' => '/certs/pass.p12',
            'services.apple_wallet.wwdr_certificate_path' => '/certs/wwdr.pem',
        ]);

        $this->assertTrue($this->service->isConfigured());
    }

    public function test_getters_throw_when_required_value_missing(): void
    {
        config(['services.apple_wallet.pass_type_identifier' => null]);

        $this->expectException(WalletNotConfiguredException::class);

        $this->service->getPassTypeIdentifier();
    }

    public function test_organization_name_falls_back_to_app_name(): void
    {
        config([
            'services.apple_wallet.organization_name' => null,
            'app.name' => 'Hi.Events',
        ]);

        $this->assertSame('Hi.Events', $this->service->getOrganizationName());
    }
}
