<?php

namespace Tests\Unit\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use HiEvents\Services\Infrastructure\AppleWallet\ApplePassSigner;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletConfigurationService;
use Tests\TestCase;
use ZipArchive;

class ApplePassSignerTest extends TestCase
{
    private string $certificatePath;

    private string $wwdrPath;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(['commonName' => 'Hi.Events Test Pass'], $key);
        $x509 = openssl_csr_sign($csr, null, $key, 365);

        openssl_pkcs12_export($x509, $p12, $key, 'test-password');
        openssl_x509_export($x509, $certPem);

        $this->certificatePath = tempnam(sys_get_temp_dir(), 'p12');
        $this->wwdrPath = tempnam(sys_get_temp_dir(), 'wwdr');

        file_put_contents($this->certificatePath, $p12);
        file_put_contents($this->wwdrPath, $certPem);

        config([
            'services.apple_wallet.certificate_path' => $this->certificatePath,
            'services.apple_wallet.certificate_password' => 'test-password',
            'services.apple_wallet.wwdr_certificate_path' => $this->wwdrPath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->certificatePath);
        @unlink($this->wwdrPath);
        parent::tearDown();
    }

    public function test_sign_produces_valid_pkpass_archive(): void
    {
        $signer = new ApplePassSigner(new AppleWalletConfigurationService);

        $passData = ['formatVersion' => 1, 'serialNumber' => 'A-ABC123'];
        $pkpass = $signer->sign($passData, ['icon.png' => 'fake-icon-bytes']);

        $archivePath = tempnam(sys_get_temp_dir(), 'result');
        file_put_contents($archivePath, $pkpass);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath) === true);

        $this->assertNotFalse($zip->locateName('pass.json'));
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertNotFalse($zip->locateName('signature'));
        $this->assertNotFalse($zip->locateName('icon.png'));

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame(sha1('fake-icon-bytes'), $manifest['icon.png']);
        $this->assertSame(sha1($zip->getFromName('pass.json')), $manifest['pass.json']);
        $this->assertNotEmpty($zip->getFromName('signature'));

        $zip->close();
        @unlink($archivePath);
    }

    public function test_sign_throws_without_icon(): void
    {
        $signer = new ApplePassSigner(new AppleWalletConfigurationService);

        $this->expectException(WalletPassGenerationException::class);

        $signer->sign(['formatVersion' => 1], []);
    }
}
