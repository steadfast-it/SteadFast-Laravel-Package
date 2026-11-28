<?php

use SteadFast\SteadFastCourierLaravelPackage\Enums\DeliveryStatus;
use SteadFast\SteadFastCourierLaravelPackage\Enums\ParcelState;
use SteadFast\SteadFastCourierLaravelPackage\Enums\ReturnStatus;

// =====================================================================
// DeliveryStatus::Exceptional
// =====================================================================

it('recognises an exceptional parcel instead of returning null', function () {
    expect(DeliveryStatus::fromResponse(['status' => 200, 'delivery_status' => 'exceptional']))
        ->toBe(DeliveryStatus::Exceptional);
});

it('treats an exceptional parcel as unsettled and needing support', function () {
    expect(DeliveryStatus::Exceptional->isFinal())->toBeFalse()
        ->and(DeliveryStatus::Exceptional->isAwaitingApproval())->toBeFalse()
        ->and(DeliveryStatus::Exceptional->isInProgress())->toBeTrue()
        ->and(DeliveryStatus::Exceptional->needsSupport())->toBeTrue();
});

// needsSupport() is relied on to page someone; widening it to existing cases
// would change behaviour on upgrade, so only the new case is added.
it('leaves needsSupport unchanged for every status that existed in 2.0', function () {
    $needsSupport = array_values(array_map(
        fn (DeliveryStatus $s) => $s->value,
        array_filter(DeliveryStatus::cases(), fn (DeliveryStatus $s) => $s->needsSupport())
    ));

    expect($needsSupport)->toBe(['unknown_approval_pending', 'unknown', 'exceptional']);
});

// =====================================================================
// ReturnStatus
// =====================================================================

it('covers every return status the API documents, spelled as the API spells them', function () {
    expect(array_map(fn (ReturnStatus $s) => $s->value, ReturnStatus::cases()))->toBe([
        'partial_delivered_return_proccessing',
        'partial_delivered_return_rider_assigned',
        'partial_delivered_return_received',
        'cancelled_return_proccessing',
        'cancelled_return_rider_assigned',
        'cancelled_return_received',
    ]);
});

// Captured from the live API: the return stage arrives in delivery_status.
it('reads a return status out of a status-with-return-status response', function () {
    expect(ReturnStatus::fromResponse(['status' => 200, 'delivery_status' => 'cancelled_return_proccessing']))
        ->toBe(ReturnStatus::CancelledReturnProcessing);
});

it('returns null for a parcel that is not on its way back', function (array $response) {
    expect(ReturnStatus::fromResponse($response))->toBeNull();
})->with([
    'normal status' => [['delivery_status' => 'pending']],
    'empty' => [[]],
    'not a string' => [['delivery_status' => 7]],
]);

it('puts every return status in exactly one stage', function () {
    foreach (ReturnStatus::cases() as $status) {
        $stages = array_filter([$status->isProcessing(), $status->isRiderAssigned(), $status->isReceived()]);

        expect($stages)->toHaveCount(1, "{$status->value} is in more or fewer than one stage");
    }
});

it('maps a return back to the delivery outcome that started it', function (ReturnStatus $status, DeliveryStatus $outcome, bool $partial) {
    expect($status->deliveryStatus())->toBe($outcome)
        ->and($status->wasPartialDelivery())->toBe($partial);
})->with([
    [ReturnStatus::PartialDeliveredReturnReceived, DeliveryStatus::PartialDelivered, true],
    [ReturnStatus::CancelledReturnRiderAssigned, DeliveryStatus::Cancelled, false],
]);

it('gives every return status a label', function () {
    foreach (ReturnStatus::cases() as $status) {
        expect($status->label())->toBeString()->not->toBeEmpty();
    }
});

// =====================================================================
// ParcelState
// =====================================================================

it('matches the keys getParcelStates reports', function () {
    // The "states" keys of a live GET /parcel_states response.
    $live = ['in_review', 'delivery_processing', 'cod_processing', 'return_requests'];

    expect(array_map(fn (ParcelState $s) => $s->value, ParcelState::cases()))->toBe($live);

    foreach (ParcelState::cases() as $state) {
        expect($state->label())->toBeString()->not->toBeEmpty();
    }
});
