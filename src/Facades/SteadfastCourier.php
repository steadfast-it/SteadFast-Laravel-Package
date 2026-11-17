<?php

namespace SteadFast\SteadFastCourierLaravelPackage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Orders
 *
 * @method static mixed placeOrder(array $data)
 * @method static mixed bulkCreateOrders(array $data)
 * @method static mixed bulkCreateOrdersExtended(array $data)
 *
 * Delivery status and tracking
 * @method static mixed checkDeliveryStatusByConsignmentId(int|string $id)
 * @method static mixed checkDeliveryStatusWithReturnStatus(int|string $consignmentId)
 * @method static mixed checkDeliveryStatusByInvoiceId(int|string $id)
 * @method static mixed checkDeliveryStatusByTrackingCode(int|string $id)
 * @method static mixed getTrackingsByInvoice(int|string $invoice)
 *
 * Return requests
 * @method static mixed createReturnRequest(array $data)
 * @method static mixed getReturnRequests()
 * @method static mixed getReturnRequestsPage(int|string $page)
 * @method static mixed getReturnRequest(int|string $id)
 *
 * Pickup requests
 * @method static mixed createPickupRequest(array $data)
 *
 * Account
 * @method static mixed getCurrentBalance()
 * @method static mixed getPayments()
 * @method static mixed getPaymentsPage(int|string $page)
 * @method static mixed getPayment(int|string $paymentId)
 *
 * Parcel states and stats
 * @method static mixed getParcelStates()
 * @method static mixed getParcelsByState(\SteadFast\SteadFastCourierLaravelPackage\Enums\ParcelState|string $state, int|string|null $page = null, int|string|null $perPage = null)
 * @method static mixed getStats(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null)
 *
 * Reference data
 * @method static mixed getPoliceStations()
 * @method static mixed fraudCheck(int|string $phone) Deprecated: always returns 0 counts since 27 September 2026. Use fraudScore().
 * @method static mixed fraudScore(int|string $phone)
 *
 * Service check
 * @method static mixed ping()
 *
 * Multi-tenant credentials
 * @method static \SteadFast\SteadFastCourierLaravelPackage\SteadfastCourier withConfig(string $apiKey, string $secretKey, ?string $baseUrl = null)
 *
 * @see \SteadFast\SteadFastCourierLaravelPackage\SteadfastCourier
 */
class SteadfastCourier extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \SteadFast\SteadFastCourierLaravelPackage\SteadfastCourier::class;
    }
}
