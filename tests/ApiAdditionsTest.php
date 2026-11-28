<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use SteadFast\SteadFastCourierLaravelPackage\Enums\ParcelState;
use SteadFast\SteadFastCourierLaravelPackage\Exceptions\InvalidRequestException;
use SteadFast\SteadFastCourierLaravelPackage\Facades\SteadfastCourier;

// The 2.1 additions. Kept apart from SteadfastCourierTest.php so that file stays
// exactly as it was in 2.0.0: it passing unchanged is the evidence that
// upgrading does not break existing calls.

function additionsUrl(string $path): string
{
    return 'https://portal.packzy.com/api/v1'.$path;
}

function additionsOrder(): array
{
    return [
        'invoice' => 'INV-1',
        'recipient_name' => 'Badrul Hasan Sajib',
        'recipient_phone' => '01309055966',
        'recipient_address' => 'Dhanmondi, Zigatola',
        'cod_amount' => 0,
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
});

// =====================================================================
// New endpoints
// =====================================================================

it('reads every new GET endpoint at its documented path', function (string $method, array $args, string $path) {
    Http::fake(['*' => Http::response(['status' => 200])]);

    SteadfastCourier::$method(...$args);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === additionsUrl($path)
        && $request->hasHeader('Api-Key', 'test-api-key'));
})->with([
    'return status' => ['checkDeliveryStatusWithReturnStatus', [256621148], '/status_with_return_status_by_cid/256621148'],
    'parcel states' => ['getParcelStates', [], '/parcel_states'],
    'parcels by state string' => ['getParcelsByState', ['in_review'], '/parcel_states/in_review'],
    'parcels by state enum' => ['getParcelsByState', [ParcelState::CodProcessing], '/parcel_states/cod_processing'],
    'stats, default range' => ['getStats', [], '/stats'],
    'fraud score' => ['fraudScore', ['01309055966'], '/fraud_check/score/01309055966'],
    'return requests page' => ['getReturnRequestsPage', [2], '/get_return_requests?page=2'],
    'payments page' => ['getPaymentsPage', ['3'], '/payments?page=3'],
]);

it('sends page and page size for a parcel state', function () {
    Http::fake(['*' => Http::response(['status' => 200, 'data' => []])]);

    SteadfastCourier::getParcelsByState(ParcelState::InReview, 2, 5);

    Http::assertSent(fn (Request $request) => $request->url() === additionsUrl('/parcel_states/in_review?page=2&per_page=5'));
});

it('leaves an unknown parcel state for the API to reject', function () {
    Http::fake(['*' => Http::response([
        'status' => 404,
        'message' => 'Unknown state. Use one of: in_review, delivery_processing, cod_processing, return_requests.',
    ], 404)]);

    expect(SteadfastCourier::getParcelsByState('teleported'))
        ->toMatchArray(['status' => 404]);
});

it('sends a stats date range from strings or dates', function ($from, $to) {
    Http::fake(['*' => Http::response(['status' => 200])]);

    SteadfastCourier::getStats($from, $to);

    Http::assertSent(fn (Request $request) => $request->url() === additionsUrl('/stats?from=2026-01-01&to=2026-11-17'));
})->with([
    'strings' => ['2026-01-01', '2026-11-17'],
    'dates' => [new Carbon('2026-01-01 15:00'), new DateTimeImmutable('2026-11-17')],
]);

it('sends only the stats date that was given', function () {
    Http::fake(['*' => Http::response(['status' => 200])]);

    SteadfastCourier::getStats(to: '2026-11-17');

    Http::assertSent(fn (Request $request) => $request->url() === additionsUrl('/stats?to=2026-11-17'));
});

it('books bulk orders through the extended endpoint with the same body', function () {
    Http::fake(['*' => Http::response(['status' => 200, 'data' => []])]);

    $orders = [additionsOrder(), additionsOrder()];

    SteadfastCourier::bulkCreateOrdersExtended($orders);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === additionsUrl('/create_order/bulk-order/extended')
        && $request->data() === $orders);
});

it('validates extended bulk orders like the plain bulk call', function ($orders) {
    Http::fake();

    expect(fn () => SteadfastCourier::bulkCreateOrdersExtended($orders))
        ->toThrow(InvalidRequestException::class);

    Http::assertNothingSent();
})->with([
    'empty' => [[]],
    'single order' => [fn () => additionsOrder()],
    'too many' => [fn () => array_fill(0, 501, additionsOrder())],
]);

it('pings without sending any credentials', function () {
    Http::fake(['*' => Http::response(['status' => 200, 'message' => 'pong'])]);

    expect(SteadfastCourier::ping())->toMatchArray(['message' => 'pong']);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === additionsUrl('/ping')
        && ! $request->hasHeader('Api-Key')
        && ! $request->hasHeader('Secret-Key'));
});

it('pings the base url given to withConfig', function () {
    Http::fake(['*' => Http::response(['message' => 'pong'])]);

    SteadfastCourier::withConfig('k', 's', 'https://staging.example.test/api/v1/')->ping();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://staging.example.test/api/v1/ping');
});

// =====================================================================
// Local validation of the new arguments
// =====================================================================

it('refuses a page that cannot exist without calling the api', function (callable $call) {
    Http::fake();

    expect($call)->toThrow(InvalidRequestException::class, 'whole number of 1 or more');

    Http::assertNothingSent();
})->with([
    'zero' => [fn () => SteadfastCourier::getPaymentsPage(0)],
    'negative' => [fn () => SteadfastCourier::getReturnRequestsPage(-1)],
    'fraction' => [fn () => SteadfastCourier::getPaymentsPage('1.5')],
    'word' => [fn () => SteadfastCourier::getPaymentsPage('next')],
    'null' => [fn () => SteadfastCourier::getReturnRequestsPage(null)],
    'bool' => [fn () => SteadfastCourier::getReturnRequestsPage(true)],
    'zero page size' => [fn () => SteadfastCourier::getParcelsByState('in_review', 1, 0)],
]);

it('refuses an empty parcel state, consignment id or phone', function (callable $call) {
    Http::fake();

    expect($call)->toThrow(InvalidRequestException::class);

    Http::assertNothingSent();
})->with([
    'state' => [fn () => SteadfastCourier::getParcelsByState('')],
    'consignment id' => [fn () => SteadfastCourier::checkDeliveryStatusWithReturnStatus(' ')],
    'phone' => [fn () => SteadfastCourier::fraudScore('')],
]);

it('refuses a stats date that is neither a date nor a string', function ($from) {
    Http::fake();

    expect(fn () => SteadfastCourier::getStats($from))
        ->toThrow(InvalidRequestException::class, '"from" date');

    Http::assertNothingSent();
})->with([
    'empty string' => [''],
    'integer' => [20260101],
    'array' => [['2026-01-01']],
]);

it('percent-encodes the new path segments', function () {
    Http::fake(['*' => Http::response(['status' => 200])]);

    SteadfastCourier::fraudScore('+880 1309');

    Http::assertSent(fn (Request $request) => $request->url() === additionsUrl('/fraud_check/score/%2B880%201309'));
});

// =====================================================================
// fraudCheck() deprecation
// =====================================================================

function deprecationLog(): TestHandler
{
    config([
        'logging.deprecations' => ['channel' => 'steadfast-test', 'trace' => false],
        'logging.channels.steadfast-test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
    ]);

    return Log::channel('steadfast-test')->getLogger()->getHandlers()[0];
}

it('keeps fraudCheck on its old path and response while logging a deprecation', function () {
    Http::fake(['*' => Http::response(['total_parcels' => 0, 'total_delivered' => 0])]);
    $log = deprecationLog();

    $response = SteadfastCourier::fraudCheck('01309055966');

    expect($response)->toBe(['total_parcels' => 0, 'total_delivered' => 0])
        ->and($log->hasWarningThatContains('fraudScore()'))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === additionsUrl('/fraud_check/01309055966'));
});

it('logs nothing when the app has no deprecations channel, as by default', function ($channel) {
    Http::fake(['*' => Http::response(['total_parcels' => 0])]);
    config(['logging.deprecations' => $channel]);
    Log::spy();

    SteadfastCourier::fraudCheck('01309055966');

    Log::shouldNotHaveReceived('channel');
})->with([
    'null channel' => [['channel' => 'null']],
    'unset' => [null],
    'string null' => ['null'],
]);

// Regression: an E_USER_DEPRECATED notice, even silenced with @, reaches an
// application's error handler, and one that throws on everything turned a
// still-working fraudCheck() into an exception. No PHP error is raised now.
it('raises no PHP error at all, so no error handler can turn it into an exception', function () {
    Http::fake(['*' => Http::response(['total_parcels' => 0])]);
    deprecationLog();

    set_error_handler(function (int $level, string $message) {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $response = SteadfastCourier::fraudCheck('01309055966');
    } finally {
        restore_error_handler();
    }

    expect($response)->toBe(['total_parcels' => 0]);
});

it('still answers when the deprecations channel itself is broken', function () {
    Http::fake(['*' => Http::response(['total_parcels' => 0])]);
    config(['logging.deprecations' => ['channel' => 'does-not-exist-driver']]);
    config(['logging.channels.does-not-exist-driver' => ['driver' => 'nope']]);

    expect(SteadfastCourier::fraudCheck('01309055966'))->toBe(['total_parcels' => 0]);
});

// =====================================================================
// Retries
// =====================================================================

// Steadfast counts every failed authentication: ten in five minutes locks the
// keys out for an hour, so retrying a 401 only gets there faster.
it('does not retry a read that failed for a reason a retry cannot fix', function (int $status) {
    $attempts = 0;

    Http::fake(function () use (&$attempts, $status) {
        $attempts++;

        return Http::response(['status' => $status, 'message' => 'no'], $status);
    });

    expect(SteadfastCourier::getCurrentBalance())->toMatchArray(['status' => $status]);

    expect($attempts)->toBe(1);
})->with([401, 403, 404, 422, 429]);

it('still retries a read that failed on the server side', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        return $attempts === 1
            ? Http::response(['message' => 'Server Error'], 503)
            : Http::response(['status' => 200, 'current_balance' => 10]);
    });

    expect(SteadfastCourier::getCurrentBalance())->toBe(['status' => 200, 'current_balance' => 10]);

    expect($attempts)->toBe(2);
});

it('returns the last server error body once retries run out', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::response(['status' => 500, 'message' => 'Server Error'], 500);
    });

    expect(SteadfastCourier::getCurrentBalance())->toBe(['status' => 500, 'message' => 'Server Error']);

    expect($attempts)->toBe(2);
});

it('retries the new reads on a dropped connection too', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Connection timed out');
    });

    expect(fn () => SteadfastCourier::getParcelStates())->toThrow(ConnectionException::class);

    expect($attempts)->toBe(2);
});

it('never retries the extended bulk write', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('Connection timed out');
    });

    expect(fn () => SteadfastCourier::bulkCreateOrdersExtended([additionsOrder()]))
        ->toThrow(ConnectionException::class);

    expect($attempts)->toBe(1);
});
