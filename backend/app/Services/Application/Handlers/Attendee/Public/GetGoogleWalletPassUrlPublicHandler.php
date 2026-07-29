<?php

namespace HiEvents\Services\Application\Handlers\Attendee\Public;

use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;
use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use HiEvents\Services\Domain\Wallet\GenerateGoogleWalletPassService;

readonly class GetGoogleWalletPassUrlPublicHandler
{
    public function __construct(
        private GenerateGoogleWalletPassService $generateGoogleWalletPassService,
    ) {}

    /**
     * @throws WalletNotConfiguredException
     * @throws WalletPassGenerationException
     */
    public function handle(int $eventId, string $attendeeShortId): string
    {
        return $this->generateGoogleWalletPassService->generate($eventId, $attendeeShortId);
    }
}
