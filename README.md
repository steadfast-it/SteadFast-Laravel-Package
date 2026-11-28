<p align="center">
  <img src="https://raw.githubusercontent.com/steadfast-it/SteadFast-Laravel-Package/main/logo.png" width="30%" alt="SteadFast">
</p>

<h1 align="center">SteadFast Courier for Laravel</h1>

<p align="center">
  A complete Laravel client for the <a href="https://www.steadfast.com.bd/">SteadFast Courier Limited</a> API.
</p>

<p align="center">
  <a href="https://packagist.org/packages/steadfast-courier/steadfast-laravel-package"><img src="https://img.shields.io/packagist/v/steadfast-courier/steadfast-laravel-package.svg?style=flat-square" alt="Latest version"></a>
  <a href="https://packagist.org/packages/steadfast-courier/steadfast-laravel-package"><img src="https://img.shields.io/packagist/dt/steadfast-courier/steadfast-laravel-package.svg?style=flat-square" alt="Downloads"></a>
  <a href="https://github.com/steadfast-it/SteadFast-Laravel-Package/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/steadfast-it/SteadFast-Laravel-Package/run-tests.yml?branch=main&label=tests&style=flat-square" alt="Tests"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/packagist/l/steadfast-courier/steadfast-laravel-package.svg?style=flat-square" alt="License"></a>
</p>

Book parcels, track deliveries, handle returns and pickups, and reconcile payments from your
Laravel application. Every endpoint in the
[official SteadFast API documentation](https://documenter.getpostman.com/view/26211192/2sAYJ1mhpk)
is covered, and every method returns the API's JSON decoded into a plain PHP array.

Works the same in an API-only backend, a queue worker, or a Blade application.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick start](#quick-start)
- [Endpoint coverage](#endpoint-coverage)
- [Orders](#orders)
- [Delivery status and tracking](#delivery-status-and-tracking)
- [Returns and pickups](#returns-and-pickups)
- [Parcel states and stats](#parcel-states-and-stats)
- [Account and reference data](#account-and-reference-data)
- [Service check](#service-check)
- [Multiple Steadfast accounts](#multiple-steadfast-accounts)
- [Error handling](#error-handling)
- [What the API does to your data](#what-the-api-does-to-your-data)
- [Webhooks](#webhooks)
- [Upgrading to 2.1](#upgrading-to-21)
- [Upgrading from 1.x](#upgrading-from-1x)
- [Contributing](#contributing)
- [Credits and license](#credits-and-license)

## Requirements

| | Supported |
| --- | --- |
| PHP | 8.2 – 8.5 |
| Laravel | 12.x, 13.x |

Laravel 11 and earlier are not supported. Laravel 11 reached end of security support on
12 March 2026, and Composer's `block-insecure` audit default refuses to install any 11.x
release. Projects still on Laravel 11 or older should stay on `^1.1` of this package.

## Installation

```bash
composer require steadfast-courier/steadfast-laravel-package
```

Publish the config file:

```bash
php artisan vendor:publish --tag="steadfast-config"
```

## Configuration

Add your credentials to `.env`. You can find them in your Steadfast merchant dashboard, or
request them from Steadfast support.

```env
STEADFAST_API_KEY="your-api-key"
STEADFAST_SECRET_KEY="your-secret-key"
```

Everything else is optional. The defaults are shown here, so you only need to add the lines
you actually want to change:

```env
STEADFAST_BASE_URL="https://portal.packzy.com/api/v1"

# Webhooks, see the Webhooks section below
STEADFAST_BEARER_TOKEN=

# Network behaviour
STEADFAST_TIMEOUT=30
STEADFAST_CONNECT_TIMEOUT=10
STEADFAST_RETRY_TIMES=2
STEADFAST_RETRY_SLEEP=200
```

The published `config/steadfast.php` documents each option inline. A trailing slash on
`STEADFAST_BASE_URL` is fine, and leaving it unset raises a clear `InvalidRequestException`
rather than a confusing HTTP client error.

## Quick start

```php
use SteadFast\SteadFastCourierLaravelPackage\Facades\SteadfastCourier;

// Book a parcel
$response = SteadfastCourier::placeOrder([
    'invoice' => 'INV-1001',
    'recipient_name' => 'Badrul Hasan Sajib',
    'recipient_phone' => '01309055966',
    'recipient_address' => 'Dhanmondi, Zigatola, Dhaka',
    'cod_amount' => 3010,
]);

$consignmentId = $response['consignment']['consignment_id'];

// Check on it later
$status = SteadfastCourier::checkDeliveryStatusByConsignmentId($consignmentId);

echo $status['delivery_status']; // "in_review"
```

If you prefer the registered alias, `\SteadFastCourier::placeOrder(...)` works identically.

## Endpoint coverage

| Method | Endpoint | Package method | Description |
| --- | --- | --- | --- |
| `POST` | `/create_order` | [`placeOrder()`](#place-an-order) | Book a single parcel. |
| `POST` | `/create_order/bulk-order` | [`bulkCreateOrders()`](#create-orders-in-bulk) | Book up to 500 parcels in one call. |
| `POST` | `/create_order/bulk-order/extended` | [`bulkCreateOrdersExtended()`](#readable-bulk-errors) | As above, with readable validation messages per order. |
| `GET` | `/status_by_cid/{id}` | [`checkDeliveryStatusByConsignmentId()`](#check-a-delivery-status) | Current delivery status, by consignment ID. |
| `GET` | `/status_with_return_status_by_cid/{id}` | [`checkDeliveryStatusWithReturnStatus()`](#follow-a-parcel-coming-back) | As above, but says how far a returning parcel has got. |
| `GET` | `/status_by_invoice/{invoice}` <sup>1</sup> | [`checkDeliveryStatusByInvoiceId()`](#check-a-delivery-status) | Current delivery status, by your own invoice number. |
| `GET` | `/status_by_trackingcode/{code}` <sup>1</sup> | [`checkDeliveryStatusByTrackingCode()`](#check-a-delivery-status) | Current delivery status, by tracking code. |
| `GET` | `/trackings_by_invoice/{invoice}` | [`getTrackingsByInvoice()`](#read-the-full-tracking-history) | Full event history for a consignment, not just its current status. |
| `POST` | `/create_return_request` | [`createReturnRequest()`](#create-a-return-request) | Ask for a parcel to be returned to you. |
| `GET` | `/get_return_requests` | [`getReturnRequests()`](#list-and-read-return-requests), [`getReturnRequestsPage()`](#list-and-read-return-requests) | Your return requests, ten to a page. |
| `GET` | `/get_return_request/{id}` | [`getReturnRequest()`](#list-and-read-return-requests) | Read one return request. |
| `POST` | `/create_pickup_request` | [`createPickupRequest()`](#request-a-pickup) | Ask Steadfast to collect parcels from your address. |
| `GET` | `/get_balance` | [`getCurrentBalance()`](#current-balance) | Your current merchant account balance. |
| `GET` | `/payments` | [`getPayments()`](#payments), [`getPaymentsPage()`](#payments) | The payouts Steadfast has made to you, ten to a page. |
| `GET` | `/payments/{payment_id}` | [`getPayment()`](#payments) | Read one payment. |
| `GET` | `/parcel_states` | [`getParcelStates()`](#dashboard-states) | How many parcels are in each dashboard state, and their COD. |
| `GET` | `/parcel_states/{state}` | [`getParcelsByState()`](#dashboard-states) | The parcels in one dashboard state, a page at a time. |
| `GET` | `/stats` | [`getStats()`](#stats) | Counts and COD per outcome between two dates. |
| `GET` | `/police_stations` | [`getPoliceStations()`](#police-stations) | Districts and their police stations, for pickup requests. |
| `GET` | `/fraud_check/score/{phone}` | [`fraudScore()`](#fraud-check) | A customer's delivery record and merchant reports. |
| `GET` | `/fraud_check/{phone}` | [`fraudCheck()`](#fraud-check) <sup>2</sup> | Retired by Steadfast: every count is now 0. |
| `GET` | `/ping` | [`ping()`](#service-check) | Is the API reachable? Needs no credentials. |

<sup>1</sup> Not listed in the current Postman collection, but still served by the API and
supported here since 1.x.

<sup>2</sup> Deprecated since 2.1. Steadfast retired this endpoint on 27 September 2026. It
still answers, but with 0 for every count, so every customer looks clean. Use `fraudScore()`.

Status answers are cached by Steadfast for 60 seconds, so polling a status faster than that
spends requests and tells you nothing new. Use [webhooks](#webhooks) where you can. The API
allows 1,000 requests a minute, and 6,000 a minute for booking.

Each example below assumes this import:

```php
use SteadFast\SteadFastCourierLaravelPackage\Facades\SteadfastCourier;
```

## Orders

### Place an order

```php
$response = SteadfastCourier::placeOrder([
    // Required
    'invoice' => 'INV-1001',                         // your own reference, must be unique
    'recipient_name' => 'Badrul Hasan Sajib',
    'recipient_phone' => '01309055966',
    'recipient_address' => 'Dhanmondi, Zigatola, Dhaka',
    'cod_amount' => 3010,                            // 0 when nothing is collected

    // Optional
    'recipient_email' => 'badrul.sajib@gmail.com',
    'alternative_phone' => '01722743076',
    'item_description' => 'iPhone 16 Pro Max 512GB',
    'note' => 'Call before delivery.',
]);
```

Unrecognised keys are passed through untouched, so a field Steadfast adds later works without
waiting for a package release.

```json
{
    "status": 200,
    "message": "Consignment has been created successfully.",
    "consignment": {
        "consignment_id": 131822154,
        "invoice": "INV-1001",
        "tracking_code": "7DB724A65D4",
        "recipient_name": "Badrul Hasan Sajib",
        "recipient_phone": "01309055966",
        "recipient_address": "Dhanmondi, Zigatola, Dhaka",
        "cod_amount": 3010,
        "status": "in_review",
        "note": "Call before delivery.",
        "created_at": "2026-03-21T07:05:31.000000Z",
        "updated_at": "2026-03-21T07:05:31.000000Z"
    }
}
```

### Create orders in bulk

Up to **500 orders** per call.

```php
$response = SteadfastCourier::bulkCreateOrders([
    [
        'invoice' => 'INV-1001',
        'recipient_name' => 'Badrul Hasan Sajib',
        'recipient_phone' => '01309055966',
        'recipient_address' => 'Dhanmondi, Zigatola, Dhaka',
        'cod_amount' => 3010,
    ],
    [
        'invoice' => 'INV-1002',
        'recipient_name' => 'Jane Smith',
        'recipient_phone' => '01711111111',
        'recipient_address' => 'House 44, Road 2/A, Dhanmondi, Dhaka 1209',
        'cod_amount' => 1500,
    ],
]);

foreach ($response['data'] as $consignment) {
    if ($consignment['status'] === 'error') {
        // This one order was rejected; the rest may have succeeded.
        continue;
    }

    // $consignment['consignment_id'], $consignment['tracking_code']
}
```

```json
{
    "status": 200,
    "message": "We have a response for you.",
    "data": [
        {
            "invoice": "INV-1001",
            "recipient_name": "Badrul Hasan Sajib",
            "recipient_phone": "01309055966",
            "recipient_address": "Dhanmondi, Zigatola, Dhaka",
            "cod_amount": "3010",
            "consignment_id": 11543968,
            "tracking_code": "B025A038",
            "status": "success"
        }
    ]
}
```

A bulk call succeeds as a whole even when individual orders fail, so check each entry's
`status` rather than assuming the batch went through.

These mistakes are caught before any request is sent, and raise
[`InvalidRequestException`](#error-handling):

| Call | Problem |
| --- | --- |
| `bulkCreateOrders([])` | nothing to send |
| `bulkCreateOrders(['invoice' => '...'])` | a single order, where a list of orders is expected |
| more than 500 orders | over the API limit, send them in chunks |

Chunking is straightforward:

```php
use SteadFast\SteadFastCourierLaravelPackage\SteadfastCourier as Client;

foreach (array_chunk($orders, Client::MAX_BULK_ORDERS) as $chunk) {
    SteadfastCourier::bulkCreateOrders($chunk);
}
```

### Readable bulk errors

`bulkCreateOrdersExtended()` takes exactly the same orders and books them the same way. The
difference is the `error` on a rejected entry: `bulkCreateOrders()` gives a JSON-encoded string
of codes, the extended call gives a list of messages you can show to a person.

```php
$response = SteadfastCourier::bulkCreateOrdersExtended($orders);

foreach ($response['data'] as $entry) {
    if ($entry['status'] === 'error') {
        // ["The recipient phone field is required.", ...]
        $messages = $entry['error'];
    }
}
```

```text
bulkCreateOrders()          "error": "[\"RECEIVER_PHONE_IS_REQUIRED\"]"
bulkCreateOrdersExtended()  "error": ["The recipient phone field is required."]
```

## Delivery status and tracking

### Check a delivery status

Three interchangeable lookups, all returning the same shape:

```php
SteadfastCourier::checkDeliveryStatusByConsignmentId(131822154);
SteadfastCourier::checkDeliveryStatusByInvoiceId('INV-1001');
SteadfastCourier::checkDeliveryStatusByTrackingCode('7DB724A65D4');
```

```json
{
    "status": 200,
    "delivery_status": "partial_delivered"
}
```

### Work with statuses using the enum

Comparing raw strings is easy to get wrong: a typo becomes a branch that silently never runs.
Use the `DeliveryStatus` enum instead.

```php
use SteadFast\SteadFastCourierLaravelPackage\Enums\DeliveryStatus;

$status = DeliveryStatus::fromResponse(
    SteadfastCourier::checkDeliveryStatusByConsignmentId($consignmentId)
);

if ($status?->isFinal()) {
    // Settled and the balance is updated. Stop polling.
}

if ($status?->isAwaitingApproval()) {
    // Outcome recorded, waiting on a Steadfast admin. The balance has not moved.
}

if ($status?->needsSupport()) {
    // Steadfast cannot classify this parcel. A human needs to look at it.
}

echo $status?->label(); // "Partially delivered and balance updated"
```

`fromResponse()` reads any of the three shapes Steadfast uses: `delivery_status` from a status
check, `consignment.status` from [`placeOrder()`](#place-an-order), and `status` from a
[webhook](#webhooks) payload. It returns `null` when the payload carries no status this package
recognises, so a status Steadfast adds in future degrades gracefully rather than throwing in
production.

```php
DeliveryStatus::fromResponse(SteadfastCourier::placeOrder($order));              // InReview
DeliveryStatus::fromResponse(SteadfastCourier::checkDeliveryStatusByInvoiceId($i)); // Delivered
DeliveryStatus::fromResponse($webhookPayload);                                   // Cancelled
```

| API value | Enum case | Meaning | Bucket |
| --- | --- | --- | --- |
| `in_review` | `InReview` | Order placed, under review | in progress |
| `pending` | `Pending` | Not yet delivered or cancelled | in progress |
| `hold` | `Hold` | On hold | in progress |
| `delivered_approval_pending` | `DeliveredApprovalPending` | Delivered, awaiting admin approval | awaiting approval |
| `partial_delivered_approval_pending` | `PartialDeliveredApprovalPending` | Partially delivered, awaiting approval | awaiting approval |
| `cancelled_approval_pending` | `CancelledApprovalPending` | Cancelled, awaiting approval | awaiting approval |
| `unknown_approval_pending` | `UnknownApprovalPending` | Unknown state, needs support | awaiting approval |
| `delivered` | `Delivered` | Delivered and balance updated | final |
| `partial_delivered` | `PartialDelivered` | Partially delivered and balance updated | final |
| `cancelled` | `Cancelled` | Cancelled and balance updated | final |
| `unknown` | `Unknown` | Unknown status, contact support | in progress |
| `exceptional` | `Exceptional` | Lost, damaged or off the normal path, contact support | in progress |

Every status falls into exactly one bucket: `isFinal()`, `isAwaitingApproval()` or
`isInProgress()`. `needsSupport()` is true for `Unknown`, `UnknownApprovalPending` and
`Exceptional`.

Anything ending `_approval_pending` is the rider's word, not Steadfast's yet. Do not settle
your books on it: wait for `isFinal()`.

### Follow a parcel coming back

For a cancelled or partly delivered parcel, `checkDeliveryStatusWithReturnStatus()` reports
how far its return has got. It uses the same `delivery_status` field, so read it with the
`ReturnStatus` enum and fall back to `DeliveryStatus` for anything else:

```php
use SteadFast\SteadFastCourierLaravelPackage\Enums\DeliveryStatus;
use SteadFast\SteadFastCourierLaravelPackage\Enums\ReturnStatus;

$response = SteadfastCourier::checkDeliveryStatusWithReturnStatus($consignmentId);

if ($return = ReturnStatus::fromResponse($response)) {
    $return->isProcessing();       // being prepared for return
    $return->isRiderAssigned();    // a rider is bringing it back
    $return->isReceived();         // you have it back
    $return->wasPartialDelivery(); // only part of the order is coming back
    $return->deliveryStatus();     // DeliveryStatus::Cancelled or ::PartialDelivered
} else {
    $status = DeliveryStatus::fromResponse($response);
}
```

| API value | Enum case |
| --- | --- |
| `partial_delivered_return_proccessing` | `PartialDeliveredReturnProcessing` |
| `partial_delivered_return_rider_assigned` | `PartialDeliveredReturnRiderAssigned` |
| `partial_delivered_return_received` | `PartialDeliveredReturnReceived` |
| `cancelled_return_proccessing` | `CancelledReturnProcessing` |
| `cancelled_return_rider_assigned` | `CancelledReturnRiderAssigned` |
| `cancelled_return_received` | `CancelledReturnReceived` |

"proccessing" is spelled as the API spells it.

### Read the full tracking history

Where a status check returns the current state, this returns the whole event trail:

```php
$response = SteadfastCourier::getTrackingsByInvoice('INV-1001');

foreach ($response['tracking'] as $event) {
    echo $event['text']; // "Consignment created by Sender(API)."
}
```

## Returns and pickups

### Create a return request

Identify the consignment by **one** of `consignment_id`, `invoice` or `tracking_code`.
Omitting all three raises `InvalidRequestException` before any request is sent.

```php
$response = SteadfastCourier::createReturnRequest([
    'consignment_id' => 160757328,
    'reason' => 'Received a damaged or broken item',
]);
```

### List and read return requests

```php
$first = SteadfastCourier::getReturnRequests();        // newest first, ten to a page
$third = SteadfastCourier::getReturnRequestsPage(3);
$one = SteadfastCourier::getReturnRequest(1);
```

To read every page:

```php
$page = 1;

do {
    $response = SteadfastCourier::getReturnRequestsPage($page);

    foreach ($response['data'] as $request) {
        // ...
    }
} while ($page++ < $response['meta']['last_page']);
```

### Request a pickup

Ask Steadfast to collect parcels from one of your saved addresses. Use
[`getPoliceStations()`](#police-stations) to look up `police_station_id`.

```php
$response = SteadfastCourier::createPickupRequest([
    'address_id' => 5,
    'police_station_id' => 92,
    'address' => 'Dhanmondi, Zigatola 2/a',
    'contact_number' => '01722743076',
    'note' => 'Ten small parcels, ready after 3pm.',
    'estim_qty' => 12,
]);
```

## Parcel states and stats

### Dashboard states

The four figures at the top of your Steadfast dashboard, read live rather than cached:

```php
$response = SteadfastCourier::getParcelStates();

$response['states']['in_review'];       // ["count" => 9574, "cod_amount" => 73785061]
$response['states']['return_requests']; // ["count" => 0]
```

And the parcels behind one of them, latest first, 20 to a page unless you say otherwise:

```php
use SteadFast\SteadFastCourierLaravelPackage\Enums\ParcelState;

$response = SteadfastCourier::getParcelsByState(ParcelState::DeliveryProcessing);
$response = SteadfastCourier::getParcelsByState('in_review', page: 2, perPage: 50);

foreach ($response['data'] as $parcel) {
    // consignment_id, invoice, tracking_code, status, cod_amount, ...
}

$response['meta']['last_page'];
```

The states are `in_review`, `delivery_processing`, `cod_processing` and `return_requests`.
Any other state is passed to Steadfast, which answers 404 with the list of valid ones.

### Stats

How the parcels you booked between two dates turned out, as on your Stats page. Dates are
inclusive, and may be `Y-m-d` strings or any `DateTimeInterface`. Leave them out for the last
30 days.

```php
$response = SteadfastCourier::getStats('2026-01-01', now());

$response['success_rate'];          // 38
$response['delivered']['count'];    // 6
$response['cancelled']['cod_amount'];
```

`to` before `from` comes back as a 422 with an `errors` field.

## Account and reference data

### Current balance

```php
$response = SteadfastCourier::getCurrentBalance();
```

```json
{
    "status": 200,
    "current_balance": 4820
}
```

### Payments

```php
$first = SteadfastCourier::getPayments();       // ten to a page
$second = SteadfastCourier::getPaymentsPage(2); // an empty "payments" list past the end
$one = SteadfastCourier::getPayment('SFC-23618228');
```

```json
{
    "status": 1,
    "alertClass": "success",
    "message": "Fetched successfully!",
    "payment": {
        "payment_id": "SFC-23618228",
        "amount": 1,
        "method": "Cash",
        "due_bills": 0,
        "paid_bills": 0,
        "charges": 0,
        "total": 1,
        "status_label": "ready",
        "created_at": "2026-02-27 16:08:24",
        "ready_at": "2026-02-27 16:27:20",
        "paid_at": null
    }
}
```

### Police stations

Districts with their police stations, needed for `police_station_id` on a pickup request:

```php
$response = SteadfastCourier::getPoliceStations();

foreach ($response['data'] as $district) {
    echo $district['name']; // "Bagerhat"

    foreach ($district['policestations'] as $station) {
        echo "{$station['id']}: {$station['name']}";
    }
}
```

### Fraud check

A customer's delivery record across Steadfast: how much of it arrived, how much came back, and
what other merchants have reported. Worth calling before accepting a high-value COD order:

```php
$response = SteadfastCourier::fraudScore('01309055966');
```

```json
{
    "status": 200,
    "phone": "01309055966",
    "score": null,
    "level": null,
    "reasons": [],
    "scoring_disabled": true,
    "doubtful_reports": false,
    "total_reports": 0,
    "delivery_ratio": 50,
    "cancellation_ratio": 50,
    "return_ratio": 50,
    "volume_band": "low",
    "volume_range": "2",
    "delivered_count": null,
    "cancelled_count": null,
    "fraud_categories": []
}
```

Fraud lookups have their own quota, separate from the 1,000-a-minute request limit. On the
account this package was tested with it was 18 searches, shared by `fraudScore()` and
`fraudCheck()`. Past it, both answer 429:

```json
{"error": "Rate limit exceeded. You have reached your maximum allowed searches.", "limit": 18, "current": 18}
```

Check for `error` before reading the score, and cache results per phone number rather than
looking the same customer up on every order.

> [!WARNING]
> **`fraudCheck()` is deprecated and no longer tells you anything.** Steadfast retired
> `/fraud_check/{phone}` on 27 September 2026. It still answers, but every count is now `0`,
> so every customer looks clean. If you call `fraudCheck()`, switch to `fraudScore()`. The
> response is shaped differently: there is no `total_parcels`, and counts are replaced by
> ratios and a volume band. `fraudCheck()` keeps working unchanged until 3.0.

## Service check

`ping()` is the one call that needs no credentials, and it sends none. If it answers, the base
URL is right and your server can reach Steadfast, so anything that fails after it is the keys
or the request, not the connection.

```php
SteadfastCourier::ping(); // ["status" => 200, "message" => "pong", ...]
```

Because it sends no keys, it never counts towards the lockout for failed authentication (see
[Error handling](#error-handling)), which makes it safe for a health check that runs often.

## Multiple Steadfast accounts

If you are not using `.env`, or you run a SaaS where each tenant has their own Steadfast
account, pass credentials per call with `withConfig()`:

```php
$response = SteadfastCourier::withConfig(
    $tenant->steadfast_api_key,
    $tenant->steadfast_secret_key,
    'https://portal.packzy.com/api/v1', // optional, falls back to config
)->getCurrentBalance();
```

`withConfig()` returns a **new** client and never modifies the shared one, so credentials
cannot leak between tenants later in the same request, queue worker or Octane worker:

```php
$tenantA = SteadfastCourier::withConfig($a->api_key, $a->secret_key);
$tenantB = SteadfastCourier::withConfig($b->api_key, $b->secret_key);

$tenantA->placeOrder($order); // always tenant A
$tenantB->placeOrder($order); // always tenant B
SteadfastCourier::getCurrentBalance(); // still the credentials from config
```

## Error handling

Steadfast reports its own validation failures as JSON, and that body carries the message you
need, so it is returned to you unchanged rather than raised:

```php
$response = SteadfastCourier::placeOrder($order);

if ($response['status'] !== 200) {
    Log::warning('Steadfast rejected the order', ['message' => $response['message']]);
}
```

Three kinds of problem throw instead:

| Exception | When |
| --- | --- |
| `InvalidRequestException` | Your input cannot succeed. Raised locally, before any network call. |
| `SteadfastCourierException` | Steadfast answered, but not with JSON: an HTML gateway error, an empty body. |
| `Illuminate\Http\Client\ConnectionException` | Steadfast could not be reached within the timeout. |

`InvalidRequestException` extends `SteadfastCourierException`, so one `catch` covers both.

The HTTP status codes Steadfast uses, all returned to you as the decoded body:

| Code | Meaning | What to do |
| --- | --- | --- |
| 200 | Done, or answered. | Bulk calls report per-order failures inside a 200, so read each entry. |
| 400 | The request was not understood. | Usually a malformed bulk payload. |
| 401 | Wrong or revoked keys, or a parcel that belongs to another profile. | Fix the keys. Do not retry: see below. |
| 403 | Authenticated, but not allowed right now. | An inactive account, or KYC details to resubmit. |
| 422 | A field was rejected. | Read `errors` for the field names. |
| 429 | Too many requests, or too many failures. | Back off. |
| 500 | Steadfast's fault. | Safe to retry with the same invoice, see below. |

> [!IMPORTANT]
> Steadfast counts failed authentication per address and key. **Ten 401s in five minutes lock
> you out with a 429 for 60 minutes, and that includes your correct keys.** Never retry a 401
> in a loop. This package does not retry 4xx answers for you.

```php
use Illuminate\Http\Client\ConnectionException;
use SteadFast\SteadFastCourierLaravelPackage\Exceptions\InvalidRequestException;
use SteadFast\SteadFastCourierLaravelPackage\Exceptions\SteadfastCourierException;

try {
    $response = SteadfastCourier::getCurrentBalance();
} catch (InvalidRequestException $e) {
    // A bug in the calling code: bad identifier, oversized batch, missing reference.
    report($e);
} catch (SteadfastCourierException $e) {
    Log::error('Unreadable response from Steadfast', [
        'status' => $e->status(),
        'body' => $e->response()?->body(),
    ]);
} catch (ConnectionException $e) {
    Log::error('Steadfast unreachable', ['message' => $e->getMessage()]);
}
```

Earlier versions returned `null` for a non-JSON reply, which callers could not tell apart from
an empty result. That case now throws, with the status code and a snippet of the body in the
message.

### Timeouts and retries

Requests give up after `STEADFAST_TIMEOUT` seconds (default 30) and stop waiting to connect
after `STEADFAST_CONNECT_TIMEOUT` seconds (default 10). Without these, an unresponsive
endpoint would block your web request or queue worker indefinitely.

Reads (status lookups, tracking, balance, payments, states, stats and reference data) are
tried up to `STEADFAST_RETRY_TIMES` times in total (default 2) when the connection fails or
Steadfast answers with a 5xx. A 4xx answer is never retried: it would get the same answer, and
retrying a 401 would only reach the lockout faster.

**Writes are never retried automatically.** If you retry one yourself, reuse the same invoice:
Steadfast refuses a duplicate invoice, so a retry cannot book the same parcel twice. The
duplicate refusal does not hand back the original consignment, though, so after a
`ConnectionException` or a 500 look the parcel up by invoice to find out what happened:

```php
try {
    SteadfastCourier::placeOrder($order);
} catch (ConnectionException $e) {
    // It may have been booked before the connection dropped.
    $existing = SteadfastCourier::checkDeliveryStatusByInvoiceId($order['invoice']);
}
```

## What the API does to your data

Two things Steadfast does that can surprise you:

**It strips some characters.** Before storing an address, a name or a note, Steadfast replaces
each of `{` `}` `;` `<` `>` `$` with a space. The text you send is not always the text you get
back.

**It truncates rather than refuses.** Over-long values are cut to fit, without an error:

| Field | Kept |
| --- | --- |
| `invoice` | 100 characters |
| `recipient_name` | 100 characters |
| `recipient_phone` | 40 characters |
| `recipient_address` | 490 characters |
| `note` | 480 characters |

If the exact value matters to you (an invoice you match on later, say), check the length
yourself before booking.

## Webhooks

Steadfast pushes parcel status changes to a callback URL you register with them, along with a
bearer token of your choosing for authentication.

Set the same token in your `.env`:

```env
STEADFAST_BEARER_TOKEN="your-generated-bearer-token"
```

Register a route. Webhooks come from Steadfast's servers, not a browser, so keep it out of any
CSRF-protected or session-authenticated group:

```php
Route::post('/steadfast-webhook', [SteadFastWebhookController::class, 'handle']);
```

```php
use Illuminate\Http\Request;

class SteadFastWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $expected = config('steadfast.webhook_bearer_token');

        // Never authenticate against an unset token. If $expected were empty, the
        // comparison below would collapse to 'Bearer ', which any caller can send.
        if (blank($expected)) {
            return response()->json(['error' => 'Webhook token is not configured'], 500);
        }

        $token = $request->header('Authorization');

        // hash_equals compares in constant time, so the token cannot be recovered
        // by measuring how long the comparison takes.
        if (! is_string($token) || ! hash_equals('Bearer '.$expected, $token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->validate([
            'consignment_id' => ['required'],
            'invoice' => ['required'],
            'status' => ['required', 'string'],
            'cod_amount' => ['required'],
            'updated_at' => ['required'],
        ]);

        // Hand off to your own job so the HTTP response is not blocked.
        ProcessSteadfastWebhook::dispatch($payload);

        // Answer immediately so Steadfast does not time out and resend.
        return response()->json(['status' => 'success']);
    }
}
```

The `status` field carries the same values as a status check, so the
[`DeliveryStatus`](#work-with-statuses-using-the-enum) enum applies here too:

```php
$status = DeliveryStatus::tryFrom($payload['status']);

if ($status?->isFinal()) {
    $order->markSettled($status);
}
```

Steadfast may deliver the same event more than once, so make your handler idempotent: key it
on `consignment_id` and ignore an update whose `updated_at` is not newer than what you stored.

Besides parcel status changes, Steadfast also calls your webhook when a return list is
accepted and when a payout is made. The example above validates the parcel-status payload
only, so a return or payout event would fail its `validate()` call with a 422. If you enable
those events, log the raw request first and branch on its shape before validating.

## Upgrading to 2.1

2.1 only adds to 2.0. Every existing method keeps its name, signature, URL and response, so
`composer update` needs no code changes. Three things are worth knowing:

**Switch from `fraudCheck()` to `fraudScore()`.** This is the one that matters. Steadfast has
retired the endpoint behind `fraudCheck()`, which now returns `0` for every count, so a check
such as `$response['total_cancelled'] > 5` never fires. `fraudCheck()` writes a warning to
your deprecations log channel (`LOG_DEPRECATIONS_CHANNEL`) if you have one set. It raises no
PHP error, so it cannot reach an error handler, an error page or your test results. See
[Fraud check](#fraud-check).

**`DeliveryStatus` has a new case, `Exceptional`.** An exceptional parcel used to come back from
`fromResponse()` as `null`; it now comes back as `DeliveryStatus::Exceptional`. Code that
handles `null` as "no status" now sees a real case instead. A `match` on `DeliveryStatus` with
no `default` arm and without `Exceptional` throws `UnhandledMatchError` when it meets one. Add
the arm, or a `default`.

**Reads no longer retry a 4xx.** A read that failed with a 401, 403, 404, 422 or 429 used to be
tried twice. It is now tried once, and the same response body is returned. Connection failures
and 5xx answers are still retried.

## Upgrading from 1.x

Version 2.0 drops end-of-life framework versions and corrects two behaviours that could lose
data or leak credentials. Expect a short checklist rather than a rewrite.

**Requirements.** PHP 8.2+ and Laravel 12+. See [Requirements](#requirements).

**The package has been renamed** from `steadfast-courier/steadfast-courier-laravel-package`
to `steadfast-courier/steadfast-laravel-package`, following the repository rename. The old
package is abandoned and will receive no further releases. Swap it in one command:

```bash
composer remove steadfast-courier/steadfast-courier-laravel-package
composer require steadfast-courier/steadfast-laravel-package
```

**The config file and its keys have been renamed** from `steadfast-courier` to `steadfast`.
Re-publish the config and rename any keys you read directly:

```bash
php artisan vendor:publish --tag="steadfast-config"
```

```diff
- config('steadfast-courier.api_key')
+ config('steadfast.api_key')
```

Your published `config/steadfast-courier.php` is no longer read: move any customisations into
`config/steadfast.php` and delete the old file. The `STEADFAST_*` environment variable names
are unchanged, so `.env` needs no edits.

**`bulkCreateOrders()` posts a different body.** It now sends the order list as the request
body, matching the current API documentation; it previously sent
`{"data": "<json string>"}`. The response is wrapped, so read `$response['data']` instead of
iterating the response directly:

```php
// 1.x
foreach (SteadfastCourier::bulkCreateOrders($orders) as $consignment) { }

// 2.0
foreach (SteadfastCourier::bulkCreateOrders($orders)['data'] as $consignment) { }
```

**Non-JSON replies throw.** A reply that is not JSON used to come back as `null`. It now
raises `SteadfastCourierException`. See [Error handling](#error-handling).

**Invalid input throws early.** An empty identifier, a batch over 500 orders, a single order
passed where a list belongs, or a return request with no consignment reference now raise
`InvalidRequestException` instead of producing a malformed request.

**`withConfig()` no longer mutates the shared client.** Chained calls such as
`SteadfastCourier::withConfig(...)->placeOrder(...)` are unaffected. Previously the credentials
you passed leaked into every later call in the same process, which in a multi-tenant
application meant booking parcels on the wrong account.

**`webhook_bearer_token` now exists in the config file.** If you copied the webhook controller
from an older README, re-read [Webhooks](#webhooks): the previous example authenticated any
request sending a bare `Bearer ` header whenever the token was unset. Re-publish the config,
or add the key by hand:

```bash
php artisan vendor:publish --tag="steadfast-config" --force
```

## Contributing

Contributions are welcome. Working on the package itself needs PHP 8.3 or newer, since Pest
requires it.

```bash
composer install
composer test      # Pest
composer analyse   # PHPStan
composer format    # Pint
```

The test suite never touches the network: it fakes the HTTP client and fails on any
unexpected request. Please add a test with your change, and make sure it fails without it.

Bug reports and feature requests belong in
[GitHub issues](https://github.com/steadfast-it/SteadFast-Laravel-Package/issues).

## Credits and license

Built and maintained with thanks to [AmadulHaque](https://github.com/AmadulHaque) and
[all contributors](https://github.com/steadfast-it/SteadFast-Laravel-Package/graphs/contributors).

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
