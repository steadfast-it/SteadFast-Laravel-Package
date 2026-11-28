<?php

namespace SteadFast\SteadFastCourierLaravelPackage\Enums;

/**
 * How far a parcel that did not fully arrive has got on its way back to you.
 *
 * Only checkDeliveryStatusWithReturnStatus() reports these, and only for a
 * cancelled or partially delivered parcel. It reports them in the same
 * "delivery_status" field as a normal status, so for any other parcel
 * fromResponse() returns null and DeliveryStatus::fromResponse() applies.
 *
 * "proccessing" is spelled the way the API spells it.
 */
enum ReturnStatus: string
{
    case PartialDeliveredReturnProcessing = 'partial_delivered_return_proccessing';
    case PartialDeliveredReturnRiderAssigned = 'partial_delivered_return_rider_assigned';
    case PartialDeliveredReturnReceived = 'partial_delivered_return_received';
    case CancelledReturnProcessing = 'cancelled_return_proccessing';
    case CancelledReturnRiderAssigned = 'cancelled_return_rider_assigned';
    case CancelledReturnReceived = 'cancelled_return_received';

    /**
     * Read the return status out of a status-check response, or null when the
     * parcel is not on its way back.
     */
    public static function fromResponse(array $response): ?self
    {
        $candidate = $response['delivery_status'] ?? null;

        return is_string($candidate) ? self::tryFrom($candidate) : null;
    }

    /**
     * The delivery outcome that started the return.
     */
    public function deliveryStatus(): DeliveryStatus
    {
        return $this->wasPartialDelivery() ? DeliveryStatus::PartialDelivered : DeliveryStatus::Cancelled;
    }

    /**
     * Part of the order was delivered; the rest is what is coming back.
     */
    public function wasPartialDelivery(): bool
    {
        return match ($this) {
            self::PartialDeliveredReturnProcessing,
            self::PartialDeliveredReturnRiderAssigned,
            self::PartialDeliveredReturnReceived => true,
            default => false,
        };
    }

    /**
     * Being prepared for return; no rider yet.
     */
    public function isProcessing(): bool
    {
        return $this === self::PartialDeliveredReturnProcessing || $this === self::CancelledReturnProcessing;
    }

    /**
     * A rider is bringing it back.
     */
    public function isRiderAssigned(): bool
    {
        return $this === self::PartialDeliveredReturnRiderAssigned || $this === self::CancelledReturnRiderAssigned;
    }

    /**
     * You have it back. No further change is expected.
     */
    public function isReceived(): bool
    {
        return $this === self::PartialDeliveredReturnReceived || $this === self::CancelledReturnReceived;
    }

    public function label(): string
    {
        return match ($this) {
            self::PartialDeliveredReturnProcessing => 'Partly delivered, rest being prepared for return',
            self::PartialDeliveredReturnRiderAssigned => 'Partly delivered, a rider is bringing the rest back',
            self::PartialDeliveredReturnReceived => 'Partly delivered, rest returned to you',
            self::CancelledReturnProcessing => 'Cancelled, being prepared for return',
            self::CancelledReturnRiderAssigned => 'Cancelled, a rider is bringing it back',
            self::CancelledReturnReceived => 'Cancelled, returned to you',
        };
    }
}
