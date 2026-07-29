<?php

namespace HiEvents\Services\Infrastructure\AppleWallet;

use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use Imagick;

class ApplePassImageService
{
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /**
     * Normalise arbitrary image bytes to PNG, which is the only format Apple
     * Wallet accepts for pass assets.
     *
     * @throws WalletPassGenerationException
     */
    public function toPng(string $bytes): string
    {
        if ($this->isImagickAvailable()) {
            try {
                $imagick = new Imagick;
                $imagick->readImageBlob($bytes);
                $imagick->setImageFormat('png');
                $png = $imagick->getImageBlob();
                $imagick->clear();

                return $png;
            } catch (\Throwable $exception) {
                throw new WalletPassGenerationException(
                    __('Unable to process the event logo for the Apple Wallet pass.'),
                    previous: $exception,
                );
            }
        }

        if (str_starts_with($bytes, self::PNG_MAGIC)) {
            return $bytes;
        }

        throw new WalletPassGenerationException(
            __('The event logo must be a PNG image to generate an Apple Wallet pass.')
        );
    }

    private function isImagickAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }
}
