<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Livewire\Portal\Dashboard;
use App\Models\Booking;
use App\Models\Buyer;
use App\Services\Portal\CustomerPortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/** An active portal customer who is the primary buyer on a confirmed ₹10L booking, ₹300000 paid directly. */
function portalCustomerWithBooking(): array
{
    $s = confirmedBookingScenario('1000000');
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '300000', 'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    // Promote the booking's primary buyer to an active portal customer.
    $customer = $s['booking']->bookingBuyers()->where('is_primary', true)->first()->buyer;
    $customer->forceFill([
        'status' => 'active', 'email' => "portal-cust-{$customer->id}@example.com",
        'portal_status' => 'active', 'password' => bcrypt('pw'),
        'portal_activated_at' => now(),
    ])->save();

    return ['customer' => $customer->fresh(), 'booking' => $s['booking']->fresh()];
}

it('aggregates the dashboard from M7 ledger truth', function () {
    ['customer' => $customer] = portalCustomerWithBooking();

    $summary = app(CustomerPortfolioService::class)->summary($customer);

    expect($summary['bookings'])->toBe(1)
        ->and($summary['plots'])->toBe(1)
        ->and((float) $summary['paid'])->toBe(300000.0)
        ->and((float) $summary['outstanding'])->toBe(700000.0);
});

it('shows only the customer’s own bookings', function () {
    ['customer' => $customer] = portalCustomerWithBooking();
    portalCustomerWithBooking(); // a second, unrelated customer + booking

    $summary = app(CustomerPortfolioService::class)->summary($customer);

    expect($summary['bookings'])->toBe(1);
});

it('renders the dashboard for a signed-in customer with a bounded query count', function () {
    ['customer' => $customer] = portalCustomerWithBooking();

    DB::enableQueryLog();
    Livewire::actingAs($customer, 'customer')
        ->test(Dashboard::class)
        ->assertOk()
        ->assertSee('Outstanding');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(40);
});

it('handles a customer with no bookings gracefully', function () {
    $customer = activePortalBuyer();

    $summary = app(CustomerPortfolioService::class)->summary($customer);

    expect($summary['bookings'])->toBe(0)
        ->and((float) $summary['outstanding'])->toBe(0.0);

    Livewire::actingAs($customer, 'customer')->test(Dashboard::class)->assertOk();
});
