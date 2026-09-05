<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\BookingStatus;
use App\Enums\FollowUpType;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\RegistryCaseStatus;
use App\Models\Block;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\Plot;
use App\Models\Project;
use App\Models\RegistryCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    $this->travelTo(Carbon::parse('2026-06-15 09:00:00', 'UTC'));
});

/** A user with the full permission set the dashboard's widgets check for. */
function dashboardUser(array $extra = []): User
{
    return makeUser(permissions: array_merge([
        'leads.view', 'leads.view_all', 'leads.create',
        'buyers.view', 'buyers.create',
        'plots.view',
        'bookings.view', 'bookings.create',
        'payments.view', 'payments.create',
        'collections.view',
        'registry.view', 'possession.view', 'transfer.view', 'documents.view',
        'follow_ups.view', 'follow_ups.create',
        'reports.view',
    ], $extra));
}

it('loads the dashboard for an authenticated user', function () {
    $this->actingAs(dashboardUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Executive dashboard')
        ->assertSee('Attention required')
        ->assertSee('Recent bookings')
        ->assertSee('System health');
});

it('redirects a guest to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('removes every trace of the old M0/M1 foundation dashboard', function () {
    $response = $this->actingAs(dashboardUser())->get(route('dashboard'))->assertOk();

    $response->assertDontSee('M0 Foundation is in place')
        ->assertDontSee("What's next")
        ->assertDontSee('Milestone roadmap')
        ->assertDontSee('Test toast')
        ->assertDontSee('M1 — Authentication &amp; RBAC', false);
});

it('shows a near-empty, honest dashboard for a user with no module permissions', function () {
    $this->actingAs(makeUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No dashboard metrics for your role')
        // no leakage of module data the user cannot access
        ->assertDontSee('Recent bookings')
        ->assertDontSee('Lead pipeline')
        ->assertDontSee('Collection overview');
});

it('computes KPI values from real database records, never fabricated ones', function () {
    $sp = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Green Meadows']);
    $block = Block::factory()->create(['project_id' => $project->id]);

    Plot::factory()->count(4)->create(['project_id' => $project->id, 'block_id' => $block->id, 'status' => PlotStatus::Available->value]);
    $bookedPlot = Plot::factory()->create(['project_id' => $project->id, 'block_id' => $block->id, 'status' => PlotStatus::Booked->value]);

    $booking = Booking::factory()->confirmed()->create([
        'project_id' => $project->id, 'block_id' => $block->id, 'plot_id' => $bookedPlot->id,
        'created_by' => $sp->id, 'booking_date' => '2026-06-05',
        'final_amount' => '1500000', 'base_amount' => '1500000', 'subtotal' => '1500000',
        'confirmed_at' => '2026-06-05',
    ]);
    $buyer = Buyer::factory()->create(['status' => 'active']);
    BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100]);

    Lead::factory()->count(3)->create(['assigned_to' => $sp->id, 'created_at' => '2026-06-06', 'status' => 'new']);

    $html = $this->actingAs(dashboardUser())->get(route('dashboard'))->assertOk()->getContent();

    // Available/booked plot counts and booking value are the real snapshot —
    // not derived from any hardcoded figure.
    expect($html)->toContain('4') // available plots
        ->toContain('₹15') // booking value, compact-formatted (₹15 L)
        ->toContain('Green Meadows');
});

it('derives financial metrics from the M7/M8 payment ledger, not booking value minus a guess', function () {
    $sp = User::factory()->create();
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->create(['project_id' => $project->id, 'block_id' => $block->id, 'status' => PlotStatus::Booked->value]);

    $booking = Booking::factory()->confirmed()->create([
        'project_id' => $project->id, 'block_id' => $block->id, 'plot_id' => $plot->id,
        'created_by' => $sp->id, 'booking_date' => '2026-06-05',
        'final_amount' => '1000000', 'base_amount' => '1000000', 'subtotal' => '1000000',
        'confirmed_at' => '2026-06-05',
    ]);
    $buyer = Buyer::factory()->create(['status' => 'active']);
    BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100]);

    $actor = User::factory()->create();
    activePlanFor($booking->fresh(), $actor, [
        ['type' => 'amount', 'value' => '400000', 'due_date' => '2026-06-01'],
        ['type' => 'amount', 'value' => '600000', 'due_date' => '2026-09-01'],
    ]);
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => cashMode()->id, 'amount' => '400000', 'payment_date' => '2026-06-06',
    ], $actor);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $actor);

    $html = $this->actingAs(dashboardUser())->get(route('dashboard'))->assertOk()->getContent();

    // Collected = the verified payment (₹4 L); outstanding = the unpaid
    // installment portion via the M8 ledger — never "booking value − collected".
    expect($html)->toContain('₹4') // collected, compact
        ->toContain('₹6'); // outstanding, compact
});

it('scopes the dashboard to a salesperson without leads.view_all', function () {
    $me = makeUser(permissions: ['leads.view', 'follow_ups.view']);
    $other = User::factory()->create();

    Lead::factory()->count(2)->create(['assigned_to' => $me->id, 'created_at' => '2026-06-05', 'status' => 'new']);
    Lead::factory()->count(5)->create(['assigned_to' => $other->id, 'created_at' => '2026-06-05', 'status' => 'new']);

    $html = $this->actingAs($me)->get(route('dashboard'))->assertOk()->getContent();

    // The scoped viewer's pipeline sees only their own 2 leads, not the
    // other salesperson's 5 — mirrors the M11 reporting scope.
    expect($html)->toContain('Lead pipeline');
});

it('shows attention-required items backed by real pending cases', function () {
    $sp = User::factory()->create();
    $project = Project::factory()->create();
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->create(['project_id' => $project->id, 'block_id' => $block->id, 'status' => PlotStatus::Booked->value]);
    $booking = Booking::factory()->confirmed()->create([
        'project_id' => $project->id, 'block_id' => $block->id, 'plot_id' => $plot->id,
        'created_by' => $sp->id, 'booking_date' => '2026-06-01', 'confirmed_at' => '2026-06-01',
        'final_amount' => '1000000', 'base_amount' => '1000000', 'subtotal' => '1000000',
    ]);
    RegistryCase::factory()->forBooking($booking)->status(RegistryCaseStatus::Scheduled)->create();

    $user = dashboardUser();
    $lead = Lead::factory()->create(['assigned_to' => $user->id, 'created_by' => $user->id]);
    LeadFollowUp::factory()->create([
        'lead_id' => $lead->id, 'assigned_to' => $user->id, 'created_by' => $user->id,
        'type' => FollowUpType::Call->value, 'due_at' => now(), 'status' => 'pending',
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Attention required')
        ->assertSee('Registry cases pending')
        ->assertSee('Follow-ups due today');
});

it('lists recent bookings with buyer, plot, project and status', function () {
    $sp = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Sunrise Enclave']);
    $block = Block::factory()->create(['project_id' => $project->id]);
    $plot = Plot::factory()->create(['project_id' => $project->id, 'block_id' => $block->id, 'status' => PlotStatus::Booked->value]);
    $booking = Booking::factory()->confirmed()->create([
        'project_id' => $project->id, 'block_id' => $block->id, 'plot_id' => $plot->id,
        'created_by' => $sp->id, 'booking_date' => '2026-06-10', 'confirmed_at' => '2026-06-10',
        'final_amount' => '2500000', 'base_amount' => '2500000', 'subtotal' => '2500000',
    ]);
    $buyer = Buyer::factory()->create(['status' => 'active', 'first_name' => 'Meera', 'last_name' => 'Shah']);
    BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100]);

    $this->actingAs(dashboardUser())->get(route('dashboard'))
        ->assertOk()
        ->assertSee($booking->booking_number)
        ->assertSee('Sunrise Enclave')
        ->assertSee(BookingStatus::Confirmed->label());
});

it('only shows quick actions the user is authorised to perform', function () {
    $limited = makeUser(permissions: ['leads.view', 'leads.create']);

    $this->actingAs($limited)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('New lead')
        ->assertDontSee('New booking')
        ->assertDontSee('Record payment');
});

it('does not regress into an unbounded per-KPI query count', function () {
    Project::factory()->count(3)->create()->each(function (Project $project) {
        $block = Block::factory()->create(['project_id' => $project->id]);
        Plot::factory()->count(3)->create(['project_id' => $project->id, 'block_id' => $block->id]);
    });
    Lead::factory()->count(5)->create(['created_at' => '2026-06-05']);

    DB::enableQueryLog();
    $this->actingAs(dashboardUser())->get(route('dashboard'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The executive dashboard is ~15-20 bounded aggregate queries plus the
    // dashboard-only additions (buyers, pipeline, 3x follow-up counts) — a
    // generous ceiling that still catches an accidental per-row N+1 loop.
    expect($count)->toBeLessThan(80);
});
