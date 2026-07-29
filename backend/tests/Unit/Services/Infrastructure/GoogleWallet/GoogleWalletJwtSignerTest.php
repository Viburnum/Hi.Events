<?php

namespace Tests\Unit\Services\Infrastructure\GoogleWallet;

use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletConfigurationService;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletJwtSigner;
use Mockery;
use Tests\TestCase;

class GoogleWalletJwtSignerTest extends TestCase
{
    private string $privateKeyPem;

    private string $publicKeyPem;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $privateKeyPem = '';
        openssl_pkey_export($key, $privateKeyPem);

        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem = openssl_pkey_get_details($key)['key'];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_save_url_produces_verifiable_signed_jwt(): void
    {
        $configuration = Mockery::mock(GoogleWalletConfigurationService::class);
        $configuration->shouldReceive('getServiceAccount')->andReturn([
            'client_email' => 'issuer@example.iam.gserviceaccount.com',
            'private_key' => $this->privateKeyPem,
        ]);
        $configuration->shouldReceive('getOrigin')->andReturn('https://tickets.example.com');

        $signer = new GoogleWalletJwtSigner($configuration);

        $saveUrl = $signer->createSaveUrl(
            eventTicketClass: ['id' => 'issuer.class'],
            eventTicketObject: ['id' => 'issuer.A-ABC123', 'barcode' => ['value' => 'A-ABC123']],
        );

        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $saveUrl);

        $jwt = substr($saveUrl, strlen('https://pay.google.com/gp/v/save/'));
        [$headerB64, $payloadB64, $signatureB64] = explode('.', $jwt);

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('savetowallet', $payload['typ']);
        $this->assertSame('issuer@example.iam.gserviceaccount.com', $payload['iss']);
        $this->assertSame(['https://tickets.example.com'], $payload['origins']);
        $this->assertSame('A-ABC123', $payload['payload']['eventTicketObjects'][0]['barcode']['value']);

        $signingInput = $headerB64.'.'.$payloadB64;
        $verified = openssl_verify(
            $signingInput,
            $this->base64UrlDecode($signatureB64),
            $this->publicKeyPem,
            OPENSSL_ALGO_SHA256,
        );

        $this->assertSame(1, $verified);
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
