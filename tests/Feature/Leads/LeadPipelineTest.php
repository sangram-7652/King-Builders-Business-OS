<?php

declare(strict_types=1);

use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\ConvertLeadToBuyer;
use App\Enums\LeadStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-09-15 09:00:00', 'UTC'));
});

// ---------------------------------------------------------------------------
//  Expanded pipeline (M13.1)
// ---------------------------------------------------------------------------

it('walks the full positive pipeline new → booking_pending', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->status(LeadStatus::New)->create();

    foreach ([
        LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::SiteVisitPlanned,
        LeadStatus::SiteVisitDone, LeadStatus::Negotiation, LeadStatus::BookingPending,
    ] as $target) {
        app(ChangeLeadStatus::class)->handle($lead->fresh(), $target, $actor);
    }

    expect($lead->fresh()->status)->toBe(LeadStatus::BookingPending);
});

it('exposes the four negative outcomes and treats them as closed', function () {
    expect(LeadStatus::negativeOutcomes())->toBe([
        LeadStatus::Lost, LeadStatus::NotInterested, LeadStatus::Invalid, LeadStatus::Duplicate,
    ]);

    foreach (LeadStatus::negativeOutcomes() as $status) {
        expect($status->isOpen())->toBeFalse()
            ->and($status->isNegative())->toBeTrue();
    }
});

it('lets any open lead drop to any negative outcome', function (LeadStatus $from, LeadStatus $to) {
    $lead = Lead::factory()->status($from)->create();

    app(ChangeLeadStatus::class)->handle($lead, $to, User::factory()->create());

    expect($lead->fresh()->status)->toBe($to);
})->with([
    'qualified → not_interested' => [LeadStatus::Qualified, LeadStatus::NotInterested],
    'site_visit_planned → invalid' => [LeadStatus::SiteVisitPlanned, LeadStatus::Invalid],
    'negotiation → duplicate' => [LeadStatus::Negotiation, LeadStatus::Duplicate],
    'contacted → lost' => [LeadStatus::Contacted, LeadStatus::Lost],
]);

it('still refuses the disallowed jumps', function (LeadStatus $from, LeadStatus $to) {
    $lead = Lead::factory()->status($from)->create();

    expect(fn () => app(ChangeLeadStatus::class)->handle($lead, $to, User::factory()->create()))
        ->toThrow(DomainException::class);
})->with([
    'new → qualified' => [LeadStatus::New, LeadStatus::Qualified],
    'new → site_visit_planned' => [LeadStatus::New, LeadStatus::SiteVisitPlanned],
    'booking_pending → site_visit_planned' => [LeadStatus::BookingPending, LeadStatus::SiteVisitPlanned],
    'converted → anything' => [LeadStatus::Converted, LeadStatus::New],
]);

it('stamps first_contacted_at the first time a lead leaves NEW and never moves it', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->status(LeadStatus::New)->create(['first_contacted_at' => null]);

    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Contacted, $actor);
    $first = $lead->fresh()->first_contacted_at;
    expect($first)->not->toBeNull();

    $this->travel(3)->days();
    app(ChangeLeadStatus::class)->handle($lead->fresh(), LeadStatus::Qualified, $actor);

    expect($lead->fresh()->first_contacted_at->equalTo($first))->toBeTrue();
});

it('bumps last_activity_at on every activity', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->status(LeadStatus::New)->create();
    $before = $lead->last_activity_at;

    $this->travel(1)->hour();
    app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Contacted, $actor);

    expect($lead->fresh()->last_activity_at->gt($before ?? now()->subYear()))->toBeTrue();
});

// ---------------------------------------------------------------------------
//  Conversion now allowed from QUALIFIED or BOOKING_PENDING
// ---------------------------------------------------------------------------

it('converts a booking_pending lead to a buyer (M13.1 widened the gate)', function () {
    $actor = User::factory()->create();
    $lead = Lead::factory()->status(LeadStatus::BookingPending)->create();
    $buyer = Buyer::factory()->create(['status' => 'active']);

    app(ConvertLeadToBuyer::class)->handle($lead, $actor, $buyer->id);

    expect($lead->fresh()->status)->toBe(LeadStatus::Converted)
        ->and($lead->fresh()->buyer_id)->toBe($buyer->id);
});

it('refuses to convert a contacted lead', function () {
    $lead = Lead::factory()->status(LeadStatus::Contacted)->create();
    $buyer = Buyer::factory()->create(['status' => 'active']);

    expect(fn () => app(ConvertLeadToBuyer::class)->handle($lead, User::factory()->create(), $buyer->id))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
//  Scopes
// ---------------------------------------------------------------------------

it('scopes open leads to non-converted, non-negative', function () {
    Lead::factory()->status(LeadStatus::New)->create();
    Lead::factory()->status(LeadStatus::Negotiation)->create();
    Lead::factory()->status(LeadStatus::Converted)->create();
    Lead::factory()->status(LeadStatus::Lost)->create();
    Lead::factory()->status(LeadStatus::Duplicate)->create();

    expect(Lead::query()->open()->count())->toBe(2);
});

it('scopes stale leads by last_activity_at', function () {
    Lead::factory()->status(LeadStatus::New)->create(['last_activity_at' => now()->subDays(20)]);
    Lead::factory()->status(LeadStatus::New)->create(['last_activity_at' => now()->subDays(2)]);
    Lead::factory()->status(LeadStatus::New)->create(['last_activity_at' => null]);
    Lead::factory()->status(LeadStatus::Converted)->create(['last_activity_at' => now()->subYear()]);

    expect(Lead::query()->stale(14)->count())->toBe(2); // the 20-day one + the null one; converted excluded
});
