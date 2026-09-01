<?php

declare(strict_types=1);

use App\Actions\Collections\AssignCollectionCaseAction;
use App\Actions\Collections\CreatePaymentPromiseAction;
use App\Exceptions\DomainException;
use App\Models\BouncePenalty;
use App\Models\CollectionCase;
use App\Models\Payment;
use App\Models\PaymentPromise;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('rejects unauthorised collection access (37)', function () {
    $this->actingAs(makeUser())->get(route('collections.queue'))->assertForbidden();
    $this->actingAs(collectionExecutive())->get(route('collections.queue'))->assertOk();

    // reports need their own permission
    $this->actingAs(collectionExecutive())->get(route('collections.reports'))->assertForbidden();
    $this->actingAs(collectionManager())->get(route('collections.reports'))->assertOk();
});

it('rejects unauthorised case assignment (38)', function () {
    $s = overdueCaseScenario();

    expect(fn () => app(AssignCollectionCaseAction::class)->handle($s['case'], collectionExecutive(), collectionExecutive()))
        ->toThrow(DomainException::class, 'not authorised');

    expect(collectionExecutive()->can('assign', $s['case']->fresh()))->toBeFalse()
        ->and(collectionManager()->can('assign', $s['case']->fresh()))->toBeTrue();
});

it('rejects unauthorised promise update (39)', function () {
    $s = overdueCaseScenario();
    $mine = collectionExecutive();
    $other = collectionExecutive();
    app(AssignCollectionCaseAction::class)->handle($s['case'], $mine, collectionManager());

    $promise = app(CreatePaymentPromiseAction::class)->handle([
        'booking_id' => $s['booking']->id, 'promised_amount' => '100000', 'promise_date' => now()->addDay()->toDateString(),
    ], $mine);

    expect($other->can('update', $promise->fresh()))->toBeFalse()
        ->and($mine->can('update', $promise->fresh()))->toBeTrue()
        ->and(collectionManager()->can('update', $promise->fresh()))->toBeTrue();

    // a plain user cannot create promises
    expect(makeUser(permissions: ['collections.view'])->can('create', PaymentPromise::class))->toBeFalse();
});

it('rejects unauthorised penalty approval (40)', function () {
    $penalty = BouncePenalty::factory()->create();

    expect(collectionExecutive()->can('approve', $penalty))->toBeFalse()
        ->and(collectionManager()->can('approve', $penalty))->toBeTrue();
});

it('a collection user cannot fake a payment — no path to M7 SUCCESS', function () {
    $exec = collectionExecutive();

    expect($exec->can('create', Payment::class))->toBeFalse()
        ->and($exec->can('verify', Payment::factory()->create()))->toBeFalse();
});

it('gates the collection queue list to visible cases', function () {
    $s = overdueCaseScenario();
    $exec = collectionExecutive();

    // not assigned to exec → not visible
    expect(CollectionCase::query()->visibleTo($exec)->count())->toBe(0);

    app(AssignCollectionCaseAction::class)->handle($s['case'], $exec, collectionManager());
    expect(CollectionCase::query()->visibleTo($exec->fresh())->count())->toBe(1);
});
