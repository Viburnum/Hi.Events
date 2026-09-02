<?php

namespace HiEvents\Services\Domain\Order;

use Carbon\Carbon;
use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\Enums\TaxCalculationType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\Status\FulfillmentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Helper\Currency;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Tax\TaxAndFeeOrderRollupService;
use Illuminate\Support\Collection;

class OrderManagementService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly TaxAndFeeOrderRollupService $taxAndFeeOrderRollupService,
    ) {}

    public function deleteExistingOrders(int $eventId, string $sessionId): void
    {
        $this->orderRepository->deleteWhere([
            OrderDomainObjectAbstract::SESSION_ID => $sessionId,
            OrderDomainObjectAbstract::STATUS => OrderStatus::RESERVED->name,
            OrderDomainObjectAbstract::EVENT_ID => $eventId,
        ]);
    }

    public function createNewOrder(
        int $eventId,
        EventDomainObject $event,
        int $timeOutMinutes,
        string $locale,
        ?PromoCodeDomainObject $promoCode,
        ?AffiliateDomainObject $affiliate = null,
        ?string $sessionId = null,
    ): OrderDomainObject {
        $reservedUntil = Carbon::now()->addMinutes($timeOutMinutes);

        return $this->orderRepository->create([
            'event_id' => $eventId,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'reserved_until' => $reservedUntil->toString(),
            'status' => OrderStatus::RESERVED->name,
            'session_id' => $sessionId,
            'currency' => $event->getCurrency(),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
            'promo_code_id' => $promoCode?->getId(),
            'promo_code' => $promoCode?->getCode(),
            'affiliate_id' => $affiliate?->getId(),
            'locale' => $locale,
        ]);
    }

    /**
     * Update order totals by summing up all order items.
     * Platform fee and its tax are included at the item level.
     * If any product in the order is a hard ticket, the highest hard_ticket_fee
     * among them is added once to the order total as an order-level fee.
     *
     * @param  Collection<OrderItemDomainObject>  $orderItems
     */
    public function updateOrderTotals(OrderDomainObject $order, Collection $orderItems): OrderDomainObject
    {
        $totalBeforeAdditions = 0;
        $totalTax = 0;
        $totalFee = 0;
        $totalGross = 0;

        foreach ($orderItems as $item) {
            $totalBeforeAdditions += $item->getTotalBeforeAdditions();
            $totalTax += $item->getTotalTax();
            $totalFee += $item->getTotalServiceFee();
            $totalGross += $item->getTotalGross();
        }

        $rollup = $this->taxAndFeeOrderRollupService->rollup($orderItems);

        // Check if any product in the order is a hard ticket and apply the fee
        $hasHardTicket = $this->orderHasHardTicketProduct($orderItems);
        $hardTicketFee = $hasHardTicket ? $this->calculateHardTicketFee($orderItems) : 0.0;
        $fulfillmentStatus = null;

        if ($hasHardTicket) {
            $fulfillmentStatus = FulfillmentStatus::PENDING->name;
        }

        if ($hardTicketFee > 0) {
            $totalFee = Currency::round($totalFee + $hardTicketFee);
            $totalGross = Currency::round($totalGross + $hardTicketFee);
            $rollup = $this->addHardTicketFeeToRollup($rollup, $hardTicketFee);
        }

        $updateData = [
            'total_before_additions' => $totalBeforeAdditions,
            'total_tax' => $totalTax,
            'total_fee' => $totalFee,
            'total_gross' => $totalGross,
            'taxes_and_fees_rollup' => $rollup,
        ];

        if ($fulfillmentStatus !== null) {
            $updateData['fulfillment_status'] = $fulfillmentStatus;
        }

        $this->orderRepository->updateFromArray($order->getId(), $updateData);

        return $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->findById($order->getId());
    }

    private function getHardTicketProducts(Collection $orderItems): Collection
    {
        $productIds = $orderItems->map(fn (OrderItemDomainObject $item) => $item->getProductId())
            ->unique()
            ->values()
            ->toArray();

        if (empty($productIds)) {
            return collect();
        }

        return $this->productRepository->findWhereIn(
            field: ProductDomainObjectAbstract::ID,
            values: $productIds,
        )->filter(fn (ProductDomainObject $product) => $product->getIsHardTicket());
    }

    private function orderHasHardTicketProduct(Collection $orderItems): bool
    {
        return $this->getHardTicketProducts($orderItems)->isNotEmpty();
    }

    /**
     * Calculate the hard ticket fee for an order. Returns the maximum hard_ticket_fee
     * among all hard ticket products in the order (charged once per order).
     */
    private function calculateHardTicketFee(Collection $orderItems): float
    {
        $maxFee = $this->getHardTicketProducts($orderItems)
            ->max(fn (ProductDomainObject $product) => $product->getHardTicketFee() ?? 0.0);

        return $maxFee ?? 0.0;
    }

    private function addHardTicketFeeToRollup(array $rollup, float $fee): array
    {
        $rollup['fees'] ??= [];
        $rollup['fees'][] = [
            'name' => __('Hard Ticket Fee'),
            'rate' => $fee,
            'type' => TaxCalculationType::FIXED->name,
            'value' => $fee,
        ];

        return $rollup;
    }
}
