<?php

namespace HiEvents\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\FulfillmentStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\ManualOrderAttendeeException;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\CreateManualOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\ManualOrderAttendeeDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\ManualOrderTaxAndFeeDTO;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Domain\Tax\TaxAndFeeRollupService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Throwable;

class CreateManualOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ProductQuantityUpdateService $productQuantityUpdateService,
        private readonly TaxAndFeeRollupService $taxAndFeeRollupService,
        private readonly OrderManagementService $orderManagementService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ManualOrderAttendeeException
     * @throws Throwable
     */
    public function handle(CreateManualOrderDTO $orderDTO): OrderDomainObject
    {
        return $this->databaseManager->transaction(function () use ($orderDTO) {
            $event = $this->eventRepository->findById($orderDTO->event_id);
            $products = $this->getTicketProducts($orderDTO);

            $lines = $orderDTO->attendees->values()->map(
                fn (ManualOrderAttendeeDTO $attendee, int $index) => $this->resolveLine($attendee, $index, $products)
            );

            $this->assertCapacityAvailable($lines);

            $order = $this->createOrder($orderDTO, $event);

            $orderItems = $lines->map(fn (array $line) => $this->createOrderItem($order, $line));

            $lines->each(fn (array $line) => $this->createAttendee($order, $orderDTO, $line));

            $order = $this->orderManagementService->updateOrderTotals($order, $orderItems);

            $lines
                ->countBy(fn (array $line) => $line['product_price_id'])
                ->each(fn (int $count, int $priceId) => $this->productQuantityUpdateService->increaseQuantitySold(
                    priceId: $priceId,
                    adjustment: $count,
                ));

            event(new OrderStatusChangedEvent(
                order: $order,
                sendEmails: $orderDTO->send_confirmation_email,
            ));

            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(DomainEventType::ORDER_CREATED, $order->getId())
            );

            return $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->loadRelation(AttendeeDomainObject::class)
                ->findById($order->getId());
        });
    }

    /**
     * @return Collection<int, ProductDomainObject>
     */
    private function getTicketProducts(CreateManualOrderDTO $orderDTO): Collection
    {
        $productIds = $orderDTO->attendees
            ->map(fn (ManualOrderAttendeeDTO $attendee) => $attendee->product_id)
            ->unique()
            ->values()
            ->toArray();

        return $this->productRepository
            ->loadRelation(ProductPriceDomainObject::class)
            ->loadRelation(TaxAndFeesDomainObject::class)
            ->findWhereIn(ProductDomainObjectAbstract::ID, $productIds, [
                ProductDomainObjectAbstract::EVENT_ID => $orderDTO->event_id,
                ProductDomainObjectAbstract::PRODUCT_TYPE => ProductType::TICKET->name,
            ])
            ->keyBy(fn (ProductDomainObject $product) => $product->getId());
    }

    /**
     * @throws ManualOrderAttendeeException
     */
    private function resolveLine(ManualOrderAttendeeDTO $attendee, int $index, Collection $products): array
    {
        /** @var ProductDomainObject|null $product */
        $product = $products->get($attendee->product_id);

        if ($product === null) {
            throw new ManualOrderAttendeeException(__('This ticket is invalid'), $index, 'product_id');
        }

        return [
            'index' => $index,
            'attendee' => $attendee,
            'product' => $product,
            'product_price_id' => $this->resolveProductPriceId($attendee, $product, $index),
            'taxes_and_fees' => $this->resolveTaxesAndFees($attendee, $product, $index),
        ];
    }

    /**
     * @throws ManualOrderAttendeeException
     */
    private function resolveProductPriceId(ManualOrderAttendeeDTO $attendee, ProductDomainObject $product, int $index): int
    {
        $prices = $product->getProductPrices() ?? collect();

        if ($attendee->product_price_id !== null) {
            $price = $prices->first(fn (ProductPriceDomainObject $price) => $price->getId() === $attendee->product_price_id);
        } else {
            $price = $prices->first();
        }

        if ($price === null) {
            throw new ManualOrderAttendeeException(__('The product price ID is invalid.'), $index, 'product_price_id');
        }

        return $price->getId();
    }

    /**
     * @return Collection<int, array{tax_or_fee: TaxAndFeesDomainObject, amount: float}>
     *
     * @throws ManualOrderAttendeeException
     */
    private function resolveTaxesAndFees(ManualOrderAttendeeDTO $attendee, ProductDomainObject $product, int $index): Collection
    {
        $productTaxesAndFees = $product->getTaxAndFees() ?? collect();

        return ($attendee->taxes_and_fees ?? collect())->map(
            function (ManualOrderTaxAndFeeDTO $taxAndFee) use ($productTaxesAndFees, $index) {
                $taxOrFee = $productTaxesAndFees->first(
                    fn (TaxAndFeesDomainObject $productTaxOrFee) => $productTaxOrFee->getId() === $taxAndFee->tax_or_fee_id
                );

                if ($taxOrFee === null) {
                    throw new ManualOrderAttendeeException(
                        __('This tax or fee does not apply to the selected ticket'),
                        $index,
                        'taxes_and_fees',
                    );
                }

                return [
                    'tax_or_fee' => $taxOrFee,
                    'amount' => $taxAndFee->amount,
                ];
            }
        );
    }

    /**
     * @throws ManualOrderAttendeeException
     */
    private function assertCapacityAvailable(Collection $lines): void
    {
        $lines
            ->groupBy(fn (array $line) => $line['product_price_id'])
            ->each(function (Collection $linesForPrice) {
                $firstLine = $linesForPrice->first();

                $available = $this->productRepository->getQuantityRemainingForProductPrice(
                    $firstLine['product']->getId(),
                    $firstLine['product_price_id'],
                );

                if ($available < $linesForPrice->count()) {
                    throw new ManualOrderAttendeeException(
                        __('Only :count ticket(s) of this type are available. Adjust the product\'s available quantity to add more.', [
                            'count' => max($available, 0),
                        ]),
                        $firstLine['index'],
                        'product_id',
                    );
                }
            });
    }

    private function createOrder(CreateManualOrderDTO $orderDTO, EventDomainObject $event): OrderDomainObject
    {
        $totalPaid = $orderDTO->attendees->sum(fn (ManualOrderAttendeeDTO $attendee) => $attendee->amount_paid
            + ($attendee->taxes_and_fees?->sum(fn (ManualOrderTaxAndFeeDTO $taxAndFee) => $taxAndFee->amount) ?? 0));

        return $this->orderRepository->create([
            OrderDomainObjectAbstract::EVENT_ID => $orderDTO->event_id,
            OrderDomainObjectAbstract::FIRST_NAME => $orderDTO->first_name,
            OrderDomainObjectAbstract::LAST_NAME => $orderDTO->last_name,
            OrderDomainObjectAbstract::EMAIL => $orderDTO->email,
            OrderDomainObjectAbstract::ADDRESS => $orderDTO->address?->toArray(),
            OrderDomainObjectAbstract::TOTAL_GROSS => $totalPaid,
            OrderDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            OrderDomainObjectAbstract::PUBLIC_ID => IdHelper::publicId(IdHelper::ORDER_PREFIX),
            OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
            OrderDomainObjectAbstract::PAYMENT_STATUS => $totalPaid > 0
                ? OrderPaymentStatus::PAYMENT_RECEIVED->name
                : OrderPaymentStatus::NO_PAYMENT_REQUIRED->name,
            OrderDomainObjectAbstract::CURRENCY => $event->getCurrency(),
            OrderDomainObjectAbstract::IS_MANUALLY_CREATED => true,
            OrderDomainObjectAbstract::LOCALE => $orderDTO->locale,
        ]);
    }

    private function createOrderItem(OrderDomainObject $order, array $line): OrderItemDomainObject
    {
        /** @var ManualOrderAttendeeDTO $attendee */
        $attendee = $line['attendee'];
        /** @var ProductDomainObject $product */
        $product = $line['product'];

        $this->taxAndFeeRollupService->resetRollUp();
        $line['taxes_and_fees']->each(fn (array $taxAndFee) => $this->taxAndFeeRollupService->addToRollUp(
            $taxAndFee['tax_or_fee'],
            $taxAndFee['amount'],
        ));

        return $this->orderRepository->addOrderItem([
            OrderItemDomainObjectAbstract::ORDER_ID => $order->getId(),
            OrderItemDomainObjectAbstract::PRODUCT_ID => $product->getId(),
            OrderItemDomainObjectAbstract::PRODUCT_PRICE_ID => $line['product_price_id'],
            OrderItemDomainObjectAbstract::ITEM_NAME => $product->getTitle(),
            OrderItemDomainObjectAbstract::QUANTITY => 1,
            OrderItemDomainObjectAbstract::PRICE => $attendee->amount_paid,
            OrderItemDomainObjectAbstract::TOTAL_BEFORE_ADDITIONS => $attendee->amount_paid,
            OrderItemDomainObjectAbstract::TOTAL_TAX => $this->taxAndFeeRollupService->getTotalTaxes(),
            OrderItemDomainObjectAbstract::TOTAL_SERVICE_FEE => $this->taxAndFeeRollupService->getTotalFees(),
            OrderItemDomainObjectAbstract::TOTAL_GROSS => $attendee->amount_paid + $this->taxAndFeeRollupService->getTotalTaxesAndFees(),
            OrderItemDomainObjectAbstract::TAXES_AND_FEES_ROLLUP => $this->taxAndFeeRollupService->getRollUp(),
        ]);
    }

    private function createAttendee(OrderDomainObject $order, CreateManualOrderDTO $orderDTO, array $line): AttendeeDomainObject
    {
        /** @var ManualOrderAttendeeDTO $attendee */
        $attendee = $line['attendee'];
        /** @var ProductDomainObject $product */
        $product = $line['product'];

        return $this->attendeeRepository->create([
            AttendeeDomainObjectAbstract::EVENT_ID => $order->getEventId(),
            AttendeeDomainObjectAbstract::ORDER_ID => $order->getId(),
            AttendeeDomainObjectAbstract::PRODUCT_ID => $product->getId(),
            AttendeeDomainObjectAbstract::PRODUCT_PRICE_ID => $line['product_price_id'],
            AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
            AttendeeDomainObjectAbstract::FIRST_NAME => $attendee->first_name,
            AttendeeDomainObjectAbstract::LAST_NAME => $attendee->last_name ?? '',
            AttendeeDomainObjectAbstract::EMAIL => $attendee->email ?: $orderDTO->email,
            AttendeeDomainObjectAbstract::PUBLIC_ID => IdHelper::publicId(IdHelper::ATTENDEE_PREFIX),
            AttendeeDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            AttendeeDomainObjectAbstract::LOCALE => $orderDTO->locale,
            AttendeeDomainObjectAbstract::FULFILLMENT_STATUS => $product->getIsHardTicket()
                ? FulfillmentStatus::PENDING->name
                : null,
        ]);
    }
}
