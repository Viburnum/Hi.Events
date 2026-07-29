<?php

namespace HiEvents\Services\Domain\Wallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;
use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use HiEvents\Helper\Url;
use HiEvents\Services\Domain\Wallet\DTO\AppleWalletPassResponseDTO;
use HiEvents\Services\Infrastructure\AppleWallet\ApplePassImageService;
use HiEvents\Services\Infrastructure\AppleWallet\ApplePassSigner;
use HiEvents\Services\Infrastructure\AppleWallet\AppleWalletConfigurationService;
use Illuminate\Support\Facades\Http;

class GenerateAppleWalletPassService
{
    public function __construct(
        private readonly WalletPassContextResolver $contextResolver,
        private readonly AppleWalletConfigurationService $configuration,
        private readonly ApplePassSigner $signer,
        private readonly ApplePassImageService $imageService,
    ) {}

    /**
     * @throws WalletNotConfiguredException
     * @throws WalletPassGenerationException
     */
    public function generate(int $eventId, string $attendeeShortId): AppleWalletPassResponseDTO
    {
        $context = $this->contextResolver->resolve($eventId, $attendeeShortId);

        $passData = $this->buildPassData($context->attendee, $context->event);
        $assets = $this->buildAssets($context->event);

        return new AppleWalletPassResponseDTO(
            contents: $this->signer->sign($passData, $assets),
            filename: $context->attendee->getPublicId().'.pkpass',
        );
    }

    private function buildPassData(AttendeeDomainObject $attendee, EventDomainObject $event): array
    {
        $barcode = [
            'format' => 'PKBarcodeFormatQR',
            'message' => $attendee->getPublicId(),
            'messageEncoding' => 'iso-8859-1',
            'altText' => $attendee->getPublicId(),
        ];

        $secondaryFields = [
            [
                'key' => 'attendee',
                'label' => __('Attendee'),
                'value' => $attendee->getFullName(),
            ],
        ];

        if ($ticketType = $attendee->getProduct()?->getTitle()) {
            $secondaryFields[] = [
                'key' => 'ticket',
                'label' => __('Ticket'),
                'value' => $ticketType,
            ];
        }

        $auxiliaryFields = [];
        if ($event->getStartDate()) {
            $auxiliaryFields[] = [
                'key' => 'date',
                'label' => __('Date'),
                'value' => Carbon::parse($event->getStartDate())->toIso8601String(),
                'dateStyle' => 'PKDateStyleMedium',
                'timeStyle' => 'PKDateStyleShort',
            ];
        }

        $venueName = $event->getEventSettings()?->getAddress()->venue_name;
        if ($venueName) {
            $auxiliaryFields[] = [
                'key' => 'venue',
                'label' => __('Location'),
                'value' => $venueName,
            ];
        }

        $passData = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $this->configuration->getPassTypeIdentifier(),
            'teamIdentifier' => $this->configuration->getTeamIdentifier(),
            'organizationName' => $this->configuration->getOrganizationName(),
            'serialNumber' => $attendee->getPublicId(),
            'description' => $event->getTitle(),
            'barcode' => $barcode,
            'barcodes' => [$barcode],
            'eventTicket' => [
                'primaryFields' => [
                    [
                        'key' => 'event',
                        'label' => __('Event'),
                        'value' => $event->getTitle(),
                    ],
                ],
                'secondaryFields' => $secondaryFields,
                'auxiliaryFields' => $auxiliaryFields,
                'backFields' => [
                    [
                        'key' => 'ticket-id',
                        'label' => __('Ticket ID'),
                        'value' => $attendee->getPublicId(),
                    ],
                    [
                        'key' => 'view-ticket',
                        'label' => __('View Ticket'),
                        'value' => sprintf('%s/product/%d/%s', config('app.frontend_url'), $event->getId(), $attendee->getShortId()),
                    ],
                ],
            ],
            'foregroundColor' => 'rgb(255, 255, 255)',
            'labelColor' => 'rgb(255, 255, 255)',
        ];

        if ($accentHex = WalletDesignHelper::accentHex($event)) {
            $passData['backgroundColor'] = WalletDesignHelper::hexToRgbString($accentHex);
        } else {
            $passData['backgroundColor'] = 'rgb(107, 70, 193)';
        }

        if ($event->getStartDate()) {
            $passData['relevantDate'] = Carbon::parse($event->getStartDate())->toIso8601String();
        }

        return $passData;
    }

    /**
     * @return array<string, string>
     *
     * @throws WalletPassGenerationException
     */
    private function buildAssets(EventDomainObject $event): array
    {
        $logoPng = $this->imageService->toPng($this->fetchLogoBytes($event));

        return [
            'icon.png' => $logoPng,
            'icon@2x.png' => $logoPng,
            'logo.png' => $logoPng,
            'logo@2x.png' => $logoPng,
        ];
    }

    /**
     * @throws WalletPassGenerationException
     */
    private function fetchLogoBytes(EventDomainObject $event): string
    {
        $image = $this->findEventImage($event, ImageType::TICKET_LOGO->name)
            ?? $this->findEventImage($event, ImageType::EVENT_COVER->name);

        if (! $image) {
            throw new WalletPassGenerationException(
                __('The event must have a ticket logo or cover image to generate an Apple Wallet pass.')
            );
        }

        $response = Http::get(Url::getCdnUrl($image->getPath()));

        if (! $response->successful()) {
            throw new WalletPassGenerationException(
                __('Unable to download the event logo for the Apple Wallet pass.')
            );
        }

        return $response->body();
    }

    private function findEventImage(EventDomainObject $event, string $type): ?ImageDomainObject
    {
        return $event->getImages()
            ?->first(fn (ImageDomainObject $image) => $image->getType() === $type);
    }
}
