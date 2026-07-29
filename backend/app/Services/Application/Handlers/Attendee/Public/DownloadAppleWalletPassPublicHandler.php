<?php

namespace HiEvents\Services\Application\Handlers\Attendee\Public;

use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;
use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use HiEvents\Services\Domain\Wallet\DTO\AppleWalletPassResponseDTO;
use HiEvents\Services\Domain\Wallet\GenerateAppleWalletPassService;

readonly class DownloadAppleWalletPassPublicHandler
{
    public function __construct(
        private GenerateAppleWalletPassService $generateAppleWalletPassService,
    ) {}

    /**
     * @throws WalletNotConfiguredException
     * @throws WalletPassGenerationException
     */
    public function handle(int $eventId, string $attendeeShortId): AppleWalletPassResponseDTO
    {
        return $this->generateAppleWalletPassService->generate($eventId, $attendeeShortId);
    }
}
