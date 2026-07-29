<?php

namespace Tests\Unit\Services\Infrastructure\GoogleWallet;

use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletConfigurationService;
use Tests\TestCase;

class GoogleWalletConfigurationServiceTest extends TestCase
{
    private GoogleWalletConfigurationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GoogleWalletConfigurationService;
    }

    public function test_is_configured_reflects_credentials(): void
    {
        config([
            'services.google_wallet.issuer_id' => null,
            'services.google_wallet.service_account_json' => null,
        ]);
        $this->assertFalse($this->service->isConfigured());

        config([
            'services.google_wallet.issuer_id' => '1234567890',
            'services.google_wallet.service_account_json' => '{"client_email":"a@b.c","private_key":"x"}',
        ]);
        $this->assertTrue($this->service->isConfigured());
    }

    public function test_get_service_account_parses_inline_json(): void
    {
        config([
            'services.google_wallet.service_account_json' => json_encode([
                'client_email' => 'issuer@example.iam.gserviceaccount.com',
                'private_key' => '-----BEGIN PRIVATE KEY-----',
            ]),
        ]);

        $account = $this->service->getServiceAccount();

        $this->assertSame('issuer@example.iam.gserviceaccount.com', $account['client_email']);
    }

    public function test_get_service_account_throws_on_invalid_json(): void
    {
        config(['services.google_wallet.service_account_json' => '{"client_email":"a@b.c"}']);

        $this->expectException(WalletNotConfiguredException::class);

        $this->service->getServiceAccount();
    }

    public function test_class_suffix_defaults(): void
    {
        config(['services.google_wallet.class_suffix' => null]);

        $this->assertSame('hievents_event_ticket', $this->service->getClassSuffix());
    }
}
