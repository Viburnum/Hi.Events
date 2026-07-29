<?php

namespace HiEvents\Http\Actions\Attendees\Public;

use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;
use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Attendee\Public\DownloadAppleWalletPassPublicHandler;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class DownloadAppleWalletPassPublicAction extends BaseAction
{
    public function __construct(
        private readonly DownloadAppleWalletPassPublicHandler $handler,
        private readonly AppleWalletConfigurationService $configuration,
    ) {}

    public function __invoke(int $eventId, string $attendeeShortId): Response|JsonResponse
    {
        if (! $this->configuration->isConfigured()) {
            return $this->notFoundResponse();
        }

        try {
            $pass = $this->handler->handle($eventId, $attendeeShortId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        } catch (WalletNotConfiguredException) {
            return $this->notFoundResponse();
        } catch (WalletPassGenerationException $exception) {
            return $this->errorResponse($exception->getMessage());
        }

        return response()->make($pass->contents, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="'.$pass->filename.'"',
        ]);
    }
}
