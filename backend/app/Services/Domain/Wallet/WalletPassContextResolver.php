<?php

namespace HiEvents\Services\Domain\Wallet;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Wallet\DTO\WalletPassContextDTO;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class WalletPassContextResolver
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function resolve(int $eventId, string $attendeeShortId): WalletPassContextDTO
    {
        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(ProductDomainObject::class, nested: [
                new Relationship(ProductPriceDomainObject::class),
            ], name: 'product'))
            ->findFirstWhere([
                AttendeeDomainObjectAbstract::SHORT_ID => $attendeeShortId,
                AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
            ]);

        if (! $attendee) {
            throw new ResourceNotFoundException(__('Attendee not found'));
        }

        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->findById($eventId);

        if (! $event) {
            throw new ResourceNotFoundException(__('Event not found'));
        }

        return new WalletPassContextDTO(
            attendee: $attendee,
            event: $event,
        );
    }
}
