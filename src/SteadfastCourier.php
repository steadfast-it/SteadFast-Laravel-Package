<?php

namespace SteadFast\SteadFastCourierLaravelPackage;

use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SteadFast\SteadFastCourierLaravelPackage\Enums\ParcelState;
use SteadFast\SteadFastCourierLaravelPackage\Exceptions\InvalidRequestException;
use SteadFast\SteadFastCourierLaravelPackage\Exceptions\SteadfastCourierException;
use Throwable;

class SteadfastCourier
{
    /**
     * Steadfast accepts at most this many orders in one bulk request.
     */
    public const MAX_BULK_ORDERS = 500;

    protected $baseUrl;

    protected $apiKey;

    protected $secretKey;

    protected $contentType;

    protected $timeout;

    protected $connectTimeout;

    protected $retryTimes;

    protected $retrySleep;

    public function __construct()
    {
        $this->baseUrl = config('steadfast.base_url');
        $this->apiKey = config('steadfast.api_key');
        $this->secretKey = config('steadfast.secret_key');
        $this->contentType = config('steadfast.content_type', 'application/json');

        // env() yields strings, and an empty STEADFAST_TIMEOUT= would otherwise
        // become a timeout of 0, which Guzzle reads as "wait forever".
        $this->timeout = $this->positiveInt(config('steadfast.timeout'), 30);
        $this->connectTimeout = $this->positiveInt(config('steadfast.connect_timeout'), 10);

        // 0 and 1 both legitimately mean "do not retry", so these may be zero.
        $this->retryTimes = $this->wholeNumber(config('steadfast.retry.times'), 2);
        $this->retrySleep = $this->wholeNumber(config('steadfast.retry.sleep'), 200);
    }

    /**
     * Return a copy of this client that uses the given credentials.
     *
     * This intentionally does NOT mutate the current instance. The facade caches
     * the instance it resolves, so mutating $this would leak these credentials
     * into every later call in the same process (request, queue worker, Octane
     * worker) and bill the wrong merchant account.
     */
    public function withConfig(string $apiKey, string $secretKey, ?string $baseUrl = null): self
    {
        $clone = clone $this;

        $clone->apiKey = $apiKey;
        $clone->secretKey = $secretKey;
        $clone->baseUrl = $baseUrl ?? $this->baseUrl;

        return $clone;
    }

    // ---------------------------------------------------------------------
    // Orders
    // ---------------------------------------------------------------------

    /**
     * POST /create_order
     *
     * Recognised fields: invoice, recipient_name, recipient_phone,
     * recipient_address, recipient_email, alternative_phone, item_description,
     * cod_amount, note. Unknown fields are passed through untouched, so new API
     * fields work without a package release.
     */
    public function placeOrder($data)
    {
        return $this->post('/create_order', $data);
    }

    /**
     * POST /create_order/bulk-order
     *
     * The body is the list of orders itself. Up to MAX_BULK_ORDERS per call.
     */
    public function bulkCreateOrders($data)
    {
        $orders = $this->validateOrderList($data);

        return $this->post('/create_order/bulk-order', $orders);
    }

    /**
     * POST /create_order/bulk-order/extended
     *
     * The same request as bulkCreateOrders(). The difference is in the answer:
     * each failed entry's "error" is a list of readable validation messages
     * ("The recipient phone field is required.") rather than a JSON-encoded
     * string of codes ("RECEIVER_PHONE_IS_REQUIRED").
     */
    public function bulkCreateOrdersExtended($data)
    {
        $orders = $this->validateOrderList($data);

        return $this->post('/create_order/bulk-order/extended', $orders);
    }

    // ---------------------------------------------------------------------
    // Delivery status and tracking
    // ---------------------------------------------------------------------

    /**
     * GET /status_by_cid/{id}
     */
    public function checkDeliveryStatusByConsignmentId($id)
    {
        return $this->get('/status_by_cid/'.$this->requiredSegment($id, 'consignment id'));
    }

    /**
     * GET /status_with_return_status_by_cid/{id}
     *
     * As checkDeliveryStatusByConsignmentId(), except that for a parcel coming
     * back to you "delivery_status" holds the return stage instead, such as
     * "cancelled_return_proccessing". Read it with ReturnStatus::fromResponse(),
     * and fall back to DeliveryStatus::fromResponse() for any other parcel.
     */
    public function checkDeliveryStatusWithReturnStatus($consignmentId)
    {
        return $this->get('/status_with_return_status_by_cid/'.$this->requiredSegment($consignmentId, 'consignment id'));
    }

    /**
     * GET /status_by_invoice/{invoice}
     *
     * Not listed in the current Postman collection but still served, and
     * documented by this package since 1.x.
     */
    public function checkDeliveryStatusByInvoiceId($id)
    {
        return $this->get('/status_by_invoice/'.$this->requiredSegment($id, 'invoice'));
    }

    /**
     * GET /status_by_trackingcode/{code}
     *
     * Not listed in the current Postman collection but still served, and
     * documented by this package since 1.x.
     */
    public function checkDeliveryStatusByTrackingCode($id)
    {
        return $this->get('/status_by_trackingcode/'.$this->requiredSegment($id, 'tracking code'));
    }

    /**
     * GET /trackings_by_invoice/{invoice}
     *
     * The full tracking history for a consignment, rather than just its current
     * delivery status.
     */
    public function getTrackingsByInvoice($invoice)
    {
        return $this->get('/trackings_by_invoice/'.$this->requiredSegment($invoice, 'invoice'));
    }

    // ---------------------------------------------------------------------
    // Return requests
    // ---------------------------------------------------------------------

    /**
     * POST /create_return_request
     *
     * Identify the consignment by one of consignment_id, invoice or
     * tracking_code, and give a reason.
     */
    public function createReturnRequest(array $data)
    {
        $hasReference = array_filter(
            ['consignment_id', 'invoice', 'tracking_code'],
            fn (string $key) => isset($data[$key]) && $data[$key] !== ''
        );

        if ($hasReference === []) {
            throw InvalidRequestException::missingConsignmentReference();
        }

        return $this->post('/create_return_request', $data);
    }

    /**
     * GET /get_return_requests
     */
    public function getReturnRequests()
    {
        return $this->get('/get_return_requests');
    }

    /**
     * GET /get_return_requests?page={page}
     *
     * One page of return requests, ten to a page, newest first. The response's
     * "meta.last_page" says how many pages there are.
     *
     * A separate method rather than an optional argument on getReturnRequests(),
     * so that an application extending this class and overriding that method
     * keeps a compatible signature.
     */
    public function getReturnRequestsPage($page)
    {
        return $this->send('GET', '/get_return_requests', [
            'query' => ['page' => $this->pageNumber($page, 'page')],
        ]);
    }

    /**
     * GET /get_return_request/{id}
     */
    public function getReturnRequest($id)
    {
        return $this->get('/get_return_request/'.$this->requiredSegment($id, 'return request id'));
    }

    // ---------------------------------------------------------------------
    // Pickup requests
    // ---------------------------------------------------------------------

    /**
     * POST /create_pickup_request
     *
     * Recognised fields: address_id, police_station_id, address,
     * contact_number, note, estim_qty.
     */
    public function createPickupRequest(array $data)
    {
        return $this->post('/create_pickup_request', $data);
    }

    // ---------------------------------------------------------------------
    // Account
    // ---------------------------------------------------------------------

    /**
     * GET /get_balance
     */
    public function getCurrentBalance()
    {
        return $this->get('/get_balance');
    }

    /**
     * GET /payments
     */
    public function getPayments()
    {
        return $this->get('/payments');
    }

    /**
     * GET /payments?page={page}
     *
     * One page of payouts, ten to a page. A page past the end comes back with
     * an empty "payments" list.
     */
    public function getPaymentsPage($page)
    {
        return $this->send('GET', '/payments', [
            'query' => ['page' => $this->pageNumber($page, 'page')],
        ]);
    }

    // ---------------------------------------------------------------------
    // Parcel states and stats
    // ---------------------------------------------------------------------

    /**
     * GET /parcel_states
     *
     * The four figures at the top of the merchant dashboard: how many parcels
     * are in each state, and the cash on delivery riding on them. Read live,
     * not cached.
     */
    public function getParcelStates()
    {
        return $this->get('/parcel_states');
    }

    /**
     * GET /parcel_states/{state}
     *
     * The parcels in one dashboard state, latest first. The API pages 20 at a
     * time by default; pass $perPage to change that. An unknown state is left
     * for the API to reject, so a state Steadfast adds later works without a
     * package release.
     *
     * @param  ParcelState|string  $state
     */
    public function getParcelsByState($state, $page = null, $perPage = null)
    {
        $state = $state instanceof ParcelState ? $state->value : $state;

        $query = array_filter([
            'page' => $page === null ? null : $this->pageNumber($page, 'page'),
            'per_page' => $perPage === null ? null : $this->pageNumber($perPage, 'per page'),
        ], fn ($value) => $value !== null);

        return $this->send(
            'GET',
            '/parcel_states/'.$this->requiredSegment($state, 'parcel state'),
            $query === [] ? [] : ['query' => $query]
        );
    }

    /**
     * GET /stats
     *
     * Counts and cash on delivery per outcome for the parcels booked between
     * two dates, inclusive. With no dates, the API reports the last 30 days.
     *
     * @param  DateTimeInterface|string|null  $from
     * @param  DateTimeInterface|string|null  $to
     */
    public function getStats($from = null, $to = null)
    {
        $query = array_filter([
            'from' => $this->dateParameter($from, 'from'),
            'to' => $this->dateParameter($to, 'to'),
        ], fn ($value) => $value !== null);

        return $this->send('GET', '/stats', $query === [] ? [] : ['query' => $query]);
    }

    /**
     * GET /payments/{payment_id}
     */
    public function getPayment($paymentId)
    {
        return $this->get('/payments/'.$this->requiredSegment($paymentId, 'payment id'));
    }

    // ---------------------------------------------------------------------
    // Reference data
    // ---------------------------------------------------------------------

    /**
     * GET /police_stations
     *
     * Districts with their police stations, for the police_station_id a pickup
     * request needs.
     */
    public function getPoliceStations()
    {
        return $this->get('/police_stations');
    }

    /**
     * GET /fraud_check/{phone}
     *
     * @deprecated Steadfast retired this endpoint on 27 September 2026. It still
     *             answers, but every count in the response is now 0, so every
     *             customer looks clean. Use fraudScore() instead. This method
     *             keeps its old path and response so existing code does not
     *             break, and will be removed in 3.0.
     */
    public function fraudCheck($phone)
    {
        $this->reportDeprecation(
            'SteadfastCourier::fraudCheck() is deprecated: Steadfast retired /fraud_check/{phone} on '
            .'27 September 2026 and it now returns 0 for every count. Use fraudScore() instead.'
        );

        return $this->get('/fraud_check/'.$this->requiredSegment($phone, 'phone number'));
    }

    /**
     * GET /fraud_check/score/{phone}
     *
     * A customer's delivery record across Steadfast: delivery and cancellation
     * ratios, a volume band, and what other merchants have reported. The
     * response shape differs from fraudCheck(): there is no "total_parcels".
     */
    public function fraudScore($phone)
    {
        return $this->get('/fraud_check/score/'.$this->requiredSegment($phone, 'phone number'));
    }

    // ---------------------------------------------------------------------
    // Service check
    // ---------------------------------------------------------------------

    /**
     * GET /ping
     *
     * Needs no credentials and sends none, so it cannot count towards the
     * lockout for repeated authentication failures. If this answers, the base
     * URL is right and the network can reach Steadfast; anything failing after
     * that is the keys or the request.
     */
    public function ping()
    {
        $url = $this->baseUrl().'/ping';

        $client = Http::withHeaders(['Accept' => 'application/json'])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        return $this->decode('GET', $url, $client->get($url));
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Reject bulk input that cannot succeed, before spending a network call.
     *
     * @return array<int, mixed>
     */
    protected function validateOrderList($data): array
    {
        if (! is_array($data) || $data === []) {
            throw InvalidRequestException::emptyOrderList();
        }

        if (! array_is_list($data)) {
            throw InvalidRequestException::notAListOfOrders();
        }

        foreach ($data as $order) {
            if (! is_array($order)) {
                throw InvalidRequestException::notAListOfOrders();
            }
        }

        if (count($data) > self::MAX_BULK_ORDERS) {
            throw InvalidRequestException::tooManyOrders(count($data), self::MAX_BULK_ORDERS);
        }

        return $data;
    }

    /**
     * Percent-encode a required value that is interpolated into the URL path.
     *
     * Merchant invoice numbers and payment ids are caller-supplied. Unencoded,
     * an invoice such as "INV/2024/01" adds path segments and reaches a
     * different endpoint, and a "#" truncates the path into a URL fragment.
     */
    protected function requiredSegment($value, string $name): string
    {
        // A bool casts to "" or "1", and an array casts to "Array" with a
        // warning. Both would quietly build a URL pointing somewhere else.
        if (! is_scalar($value) || is_bool($value)) {
            throw InvalidRequestException::emptyIdentifier($name);
        }

        $segment = trim((string) $value);

        if ($segment === '') {
            throw InvalidRequestException::emptyIdentifier($name);
        }

        return rawurlencode($segment);
    }

    /**
     * Write a deprecation to the application's Laravel deprecations log channel
     * (logging.deprecations, LOG_DEPRECATIONS_CHANNEL), and nowhere else.
     *
     * Deliberately not trigger_error(E_USER_DEPRECATED): an application whose
     * error handler throws on every error, ignoring error_reporting(), would
     * turn a still-working call into an exception on upgrade. Writing to the
     * log directly cannot throw, print, or fail a test suite. With no channel
     * configured (Laravel's default is "null") nothing is written.
     */
    protected function reportDeprecation(string $message): void
    {
        try {
            $config = config('logging.deprecations');
            $channel = is_array($config) ? ($config['channel'] ?? null) : $config;

            if (! is_string($channel) || $channel === '' || $channel === 'null') {
                return;
            }

            Log::channel($channel)->warning($message);
        } catch (Throwable) {
            // Reporting a deprecation must never break the call it is about.
        }
    }

    /**
     * A page number or page size: a positive whole number, as an int or a
     * numeric string. Anything else cannot be a page and is refused locally.
     */
    protected function pageNumber($value, string $name): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            $number = (int) (is_string($value) ? trim($value) : $value);

            if ($number >= 1) {
                return $number;
            }
        }

        throw InvalidRequestException::invalidPageNumber($name, $value);
    }

    /**
     * A date for a query string, as Y-m-d. Strings are passed through for the
     * API to validate, so the API's own message reaches the caller.
     */
    protected function dateParameter($value, string $name): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        throw InvalidRequestException::invalidDate($name);
    }

    protected function get(string $uri)
    {
        return $this->send('GET', $uri);
    }

    protected function post(string $uri, $data)
    {
        return $this->send('POST', $uri, ['json' => $data]);
    }

    protected function send(string $method, string $uri, array $options = [])
    {
        $url = $this->baseUrl().$uri;

        return $this->decode($method, $url, $this->client($method)->send($method, $url, $options));
    }

    /**
     * The configured base URL, without a trailing slash.
     *
     * A trailing slash is an easy thing to leave in STEADFAST_BASE_URL, and
     * concatenating it would produce "/api/v1//get_balance". An unset base URL
     * would otherwise surface as a confusing "URI must include a scheme" error
     * from the HTTP client.
     */
    protected function baseUrl(): string
    {
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';

        if ($baseUrl === '') {
            throw InvalidRequestException::missingBaseUrl();
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * Read a config value that must be a positive whole number, falling back to
     * the default when it is missing, empty or nonsense.
     */
    protected function positiveInt($value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * As positiveInt(), but zero is a meaningful value rather than "unset".
     */
    protected function wholeNumber($value, int $default): int
    {
        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    protected function client(string $method): PendingRequest
    {
        $client = Http::withHeaders([
            'Api-Key' => $this->apiKey,
            'Secret-Key' => $this->secretKey,
            'Content-Type' => $this->contentType,
            'Accept' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        // Only idempotent reads are retried. Retrying a create_order call that
        // timed out could book the same parcel twice.
        //
        // And only failures that a second attempt can fix: a dropped connection
        // or a 5xx. A 401 is the same answer every time, and Steadfast counts
        // each one: ten in five minutes locks the keys out for an hour, so
        // retrying it would only get there faster. A 429 means back off, not
        // try again 200ms later.
        if ($method === 'GET' && $this->retryTimes > 1) {
            $client = $client->retry(
                $this->retryTimes,
                $this->retrySleep,
                fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false
            );
        }

        return $client;
    }

    /**
     * Steadfast reports its own validation failures as a JSON body, so those are
     * returned to the caller untouched. A body that is not JSON at all is a
     * transport or gateway failure and is raised instead of returned as null.
     */
    protected function decode(string $method, string $url, Response $response)
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw SteadfastCourierException::unexpectedResponse($method, $url, $response);
        }

        return $decoded;
    }
}
