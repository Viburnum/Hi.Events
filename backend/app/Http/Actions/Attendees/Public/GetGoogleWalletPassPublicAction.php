<?php

namespace HiEvents\Http\Actions\Attendees\Public;

use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;
use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Attendee\Public\GetGoogleWalletPassUrlPublicHandler;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetGoogleWalletPassPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetGoogleWalletPassUrlPublicHandler $handler,
        private readonly GoogleWalletConfigurationService $configuration,
    ) {}

    public function __invoke(int $eventId, string $attendeeShortId): JsonResponse|Response
    {
        if (! $this->configuration->isConfigured()) {
            return $this->notFoundResponse();
        }

        try {
            $saveUrl = $this->handler->handle($eventId, $attendeeShortId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (WalletNotConfiguredException) {
            return $this->notFoundResponse();
        } catch (WalletPassGenerationException $exception) {
            return $this->errorResponse($exception->getMessage());
        }

        return $this->jsonResponse(['save_url' => $saveUrl]);
    }
}
