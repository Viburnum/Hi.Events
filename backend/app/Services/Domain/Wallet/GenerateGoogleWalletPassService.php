<?php

namespace HiEvents\Services\Domain\Wallet;

use Carbon\Carbon;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\Wallet\WalletNotConfiguredException;
use HiEvents\Exceptions\Wallet\WalletPassGenerationException;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletConfigurationService;
use HiEvents\Services\Infrastructure\GoogleWallet\GoogleWalletJwtSigner;

class GenerateGoogleWalletPassService
{
    private const LANGUAGE = 'en-US';

    public function __construct(
        private readonly WalletPassContextResolver $contextResolver,
        private readonly GoogleWalletConfigurationService $configuration,
        private readonly GoogleWalletJwtSigner $signer,
    ) {}

    /**
     * @throws WalletNotConfiguredException
     * @throws WalletPassGenerationException
     */
    public function generate(int $eventId, string $attendeeShortId): string
    {
        $context = $this->contextResolver->resolve($eventId, $attendeeShortId);

        $issuerId = $this->configuration->getIssuerId();
        $classId = $issuerId.'.'.$this->configuration->getClassSuffix();
        $objectId = $issuerId.'.'.$this->sanitizeId($context->attendee->getPublicId());

        return $this->signer->createSaveUrl(
            eventTicketClass: $this->buildClass($classId, $context->event),
            eventTicketObject: $this->buildObject($objectId, $classId, $context->attendee, $context->event),
        );
    }

    private function buildClass(string $classId, EventDomainObject $event): array
    {
        $class = [
            'id' => $classId,
            'issuerName' => $event->getOrganizer()?->getName() ?: config('app.name', 'Hi.Events'),
            'reviewStatus' => 'UNDER_REVIEW',
            'eventName' => $this->localizedString($event->getTitle()),
        ];

        if ($event->getStartDate()) {
            $class['dateTime'] = [
                'start' => Carbon::parse($event->getStartDate())->toIso8601String(),
            ];

            if ($event->getEndDate()) {
                $class['dateTime']['end'] = Carbon::parse($event->getEndDate())->toIso8601String();
            }
        }

        $venueName = $event->getEventSettings()?->getAddress()->venue_name;
        if ($venueName) {
            $class['venue'] = [
                'name' => $this->localizedString($venueName),
                'address' => $this->localizedString($event->getEventSettings()->getAddressString()),
            ];
        }

        return $class;
    }

    private function buildObject(
        string $objectId,
        string $classId,
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
    ): array {
        $object = [
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'ticketHolderName' => $attendee->getFullName(),
            'ticketNumber' => $attendee->getPublicId(),
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $attendee->getPublicId(),
                'alternateText' => $attendee->getPublicId(),
            ],
        ];

        if ($ticketType = $attendee->getProduct()?->getTitle()) {
            $object['ticketType'] = $this->localizedString($ticketType);
        }

        $accentColor = WalletDesignHelper::accentHex($event);
        if ($accentColor) {
            $object['hexBackgroundColor'] = $accentColor;
        }

        return $object;
    }

    private function localizedString(string $value): array
    {
        return [
            'defaultValue' => [
                'language' => self::LANGUAGE,
                'value' => $value,
            ],
        ];
    }

    private function sanitizeId(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
    }
}
