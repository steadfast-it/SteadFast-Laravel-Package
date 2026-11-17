<?php

namespace SteadFast\SteadFastCourierLaravelPackage\Enums;

/**
 * The four states at the top of the merchant dashboard, as accepted by
 * getParcelsByState() and reported as keys of getParcelStates()["states"].
 */
enum ParcelState: string
{
    case InReview = 'in_review';
    case DeliveryProcessing = 'delivery_processing';
    case CodProcessing = 'cod_processing';
    case ReturnRequests = 'return_requests';

    public function label(): string
    {
        return match ($this) {
            self::InReview => 'In review',
            self::DeliveryProcessing => 'Delivery processing',
            self::CodProcessing => 'COD processing',
            self::ReturnRequests => 'Return requests',
        };
    }
}
