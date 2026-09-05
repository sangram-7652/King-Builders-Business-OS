<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Enums\CustomerActivityType;
use App\Enums\PaymentStatus;
use App\Livewire\Portal\Payments\Index;
use App\Livewire\Portal\Payments\Show;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('shows the payment schedule for the customer’s confirmed bookings', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();

    Livewire::actingAs($customer, 'customer')
        ->test(Index::class)
        ->assertOk()
        ->assertSee($booking->booking_number)
        ->assertViewHas('bookingSchedules', fn ($c) => $c->count() === 1);
});

it('lists only SUCCESS payments in the history tab', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();

    // a pending (unverified) payment must not appear
    app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '50000', 'payment_date' => now()->toDateString(),
    ], User::factory()->create());

    Livewire::actingAs($customer, 'customer')
        ->test(Index::class)
        ->set('tab', 'history')
        ->assertViewHas('payments', fn ($p) => $p->total() === 1);
});

it('shows a payment the customer owns and 404s one they do not', function () {
    ['customer' => $a] = portalBooking();
    ['customer' => $b, 'booking' => $bBooking] = portalBooking();

    $bPayment = Payment::query()->where('booking_id', $bBooking->id)->where('status', PaymentStatus::Success->value)->first();

    Livewire::actingAs($b, 'customer')
        ->test(Show::class, ['payment' => $bPayment])
        ->assertOk()
        ->assertSee($bPayment->payment_number);

    Livewire::actingAs($a, 'customer')
        ->test(Show::class, ['payment' => $bPayment])
        ->assertStatus(404);
});

it('streams a receipt PDF for the owner and records a download audit event', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();
    $receipt = Receipt::query()->where('booking_id', $booking->id)->firstOrFail();

    $this->actingAs($customer, 'customer')
        ->get(route('portal.receipts.pdf', $receipt->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($customer->fresh()->portalActivities()->where('type', CustomerActivityType::ReceiptDownloaded->value)->exists())->toBeTrue();
});

it('404s a receipt download for a customer who does not own it', function () {
    ['customer' => $a] = portalBooking();
    ['booking' => $bBooking] = portalBooking();
    $bReceipt = Receipt::query()->where('booking_id', $bBooking->id)->firstOrFail();

    $this->actingAs($a, 'customer')
        ->get(route('portal.receipts.pdf', $bReceipt->id))
        ->assertNotFound();
});

it('requires portal authentication for the receipt download', function () {
    ['booking' => $booking] = portalBooking();
    $receipt = Receipt::query()->where('booking_id', $booking->id)->firstOrFail();

    $this->get(route('portal.receipts.pdf', $receipt->id))->assertRedirect(route('portal.login'));
});
