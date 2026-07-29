<?php

namespace HiEvents\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use ZipArchive;

/**
 * Builds and signs an Apple Wallet .pkpass bundle using the PHP openssl and zip
 * extensions. A .pkpass is a ZIP archive containing pass.json, the referenced
 * image assets, a manifest.json of SHA1 hashes and a detached PKCS#7 signature
 * of that manifest.
 */
class ApplePassSigner
{
    public function __construct(
        private readonly AppleWalletConfigurationService $configuration,
    ) {}

    /**
     * @param  array<string, mixed>  $passData  The decoded pass.json structure.
     * @param  array<string, string>  $assets  Map of file name => raw file contents (must include icon.png).
     *
     * @throws WalletPassGenerationException
     */
    public function sign(array $passData, array $assets): string
    {
        if (! isset($assets['icon.png'])) {
            throw new WalletPassGenerationException(
                __('An icon.png asset is required to build an Apple Wallet pass.')
            );
        }

        $files = $assets;
        $files['pass.json'] = json_encode($passData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $manifest = [];
        foreach ($files as $name => $contents) {
            $manifest[$name] = sha1($contents);
        }
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES);

        $files['manifest.json'] = $manifestJson;
        $files['signature'] = $this->signManifest($manifestJson);

        return $this->buildZip($files);
    }

    /**
     * @throws WalletPassGenerationException
     */
    private function signManifest(string $manifestJson): string
    {
        $certificateContents = @file_get_contents($this->configuration->getCertificatePath());

        if ($certificateContents === false) {
            throw new WalletPassGenerationException(
                __('Unable to read the Apple Wallet signing certificate.')
            );
        }

        $certificates = [];
        if (! openssl_pkcs12_read($certificateContents, $certificates, $this->configuration->getCertificatePassword())) {
            throw new WalletPassGenerationException(
                __('Unable to decode the Apple Wallet signing certificate. Check the certificate password.')
            );
        }

        $manifestPath = $this->createTempFile($manifestJson);
        $signaturePath = $this->createTempFile('');

        try {
            $signed = openssl_pkcs7_sign(
                $manifestPath,
                $signaturePath,
                $certificates['cert'],
                $certificates['pkey'],
                [],
                PKCS7_BINARY | PKCS7_DETACHED,
                $this->configuration->getWwdrCertificatePath(),
            );

            if (! $signed) {
                throw new WalletPassGenerationException(
                    __('Failed to sign the Apple Wallet pass manifest.')
                );
            }

            return $this->convertPkcs7PemToDer((string) file_get_contents($signaturePath));
        } finally {
            @unlink($manifestPath);
            @unlink($signaturePath);
        }
    }

    private function convertPkcs7PemToDer(string $signature): string
    {
        $begin = 'filename="smime.p7s"';
        $end = '------';

        $signature = substr($signature, strpos($signature, $begin) + strlen($begin));
        $signature = substr($signature, 0, strpos($signature, $end));

        return base64_decode(trim($signature));
    }

    /**
     * @param  array<string, string>  $files
     *
     * @throws WalletPassGenerationException
     */
    private function buildZip(array $files): string
    {
        $zipPath = $this->createTempFile('');
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new WalletPassGenerationException(__('Failed to create the Apple Wallet pass archive.'));
        }

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        try {
            return (string) file_get_contents($zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pkpass');

        if ($contents !== '') {
            file_put_contents($path, $contents);
        }

        return $path;
    }
}
