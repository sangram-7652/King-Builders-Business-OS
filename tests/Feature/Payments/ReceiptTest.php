<?php

declare(strict_types=1);

use App\Actions\Payments\GenerateReceiptAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\Masters\AreaUnit;
use App\Enums\PaymentStatus;
use App\Enums\PlotFacing;
use App\Models\Block;
use App\Models\BookingBuyer;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Masters\City;
use App\Models\Masters\PlotDimension;
use App\Models\Payment;
use App\Models\Plot;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Branding;
use App\Support\Payments\ReceiptPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function verifiedPayment(array $s, string $amount = '250000'): Payment
{
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => $amount, 'reference_number' => 'REF-9',
    ], $s['actor']);

    return app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);
}

it('issues a receipt automatically on successful verification (29)', function () {
    $s = confirmedBookingScenario('1000000');
    $payment = verifiedPayment($s);
    $receipt = $payment->receipt;

    expect($receipt)->not->toBeNull()
        ->and($receipt->receipt_number)->toBe('RCPT-000001')
        ->and($receipt->buyer_id)->toBe($s['buyer']->id)
        ->and($receipt->buyer_name_snapshot)->toBe($s['buyer']->fullName())
        ->and($receipt->payment_mode_label)->toBe('Cash')
        ->and($receipt->reference_number)->toBe('REF-9')
        ->and($receipt->issued_by)->toBe($s['actor']->id);
});

it('generates unique receipt numbers and one receipt per payment (30)', function () {
    $s = confirmedBookingScenario('1000000');
    $a = verifiedPayment($s, '100000');
    $b = verifiedPayment($s, '100000');

    expect($a->receipt->receipt_number)->toBe('RCPT-000001')
        ->and($b->receipt->receipt_number)->toBe('RCPT-000002');

    // calling generate again returns the same receipt (idempotent)
    $again = app(GenerateReceiptAction::class)->handle($a->fresh(), $s['actor']);
    expect($again->id)->toBe($a->receipt->id);

    expect(fn () => Receipt::factory()->create(['receipt_number' => 'RCPT-000001']))
        ->toThrow(QueryException::class);
});

it('records the correct amount and payment reference on the receipt (31)', function () {
    $s = confirmedBookingScenario('1000000');
    $payment = verifiedPayment($s, '333333.33');

    expect($payment->receipt->amount)->toBe('333333.33')
        ->and($payment->receipt->payment_date->toDateString())->toBe($payment->payment_date->toDateString());
});

it('renders the receipt as a tenant-branded PDF (32)', function () {
    $s = confirmedBookingScenario('1000000');
    $payment = verifiedPayment($s);

    $receipt = $payment->receipt->load(['payment.paymentMode', 'booking.project', 'issuedBy']);

    $bytes = Pdf::loadView('receipts.pdf', [
        'receipt' => $receipt,
        'brand' => Branding::fromConfig(),
        'extra' => ReceiptPdfData::build($receipt),
    ])->output();

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(strlen($bytes))->toBeGreaterThan(1000);

    // the HTTP route also works for an authorised user
    seedRbac();
    $this->actingAs(financeManager())
        ->get(route('receipts.pdf', $receipt))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

/**
 * F-RCPT-1 — the receipt template must show real booking/plot/buyer/payment
 * ledger data, never a hardcoded or fabricated figure. Renders the Blade
 * directly to HTML (the exact markup dompdf turns into the PDF) so every
 * field can be asserted precisely, with no PDF-parsing dependency needed.
 */
it('renders every receipt field from real booking, plot, buyer and ledger data — no hardcoded figures', function () {
    seedRbac();

    $city = City::factory()->create(['name' => 'Lucknow']);
    $project = Project::factory()->create(['name' => 'Madhav Kunj', 'city_id' => $city->id]);
    $block = Block::factory()->create(['project_id' => $project->id, 'name' => 'Phase 2']);
    $dimension = PlotDimension::factory()->create(['width' => 30, 'length' => 40]);
    $plot = Plot::factory()->create([
        'project_id' => $project->id, 'block_id' => $block->id,
        'plot_number' => '33', 'area' => '1130.22', 'area_unit' => AreaUnit::SquareFeet->value,
        'facing' => PlotFacing::West->value, 'plot_dimension_id' => $dimension->id,
        'status' => 'sold',
    ]);

    $booking = Booking::factory()->confirmed()->forPlot($plot)->create([
        'final_amount' => '791154', 'base_amount' => '791154', 'subtotal' => '791154',
        'base_rate' => '800', 'charge_amount' => '5000',
    ]);

    $buyerCity = City::factory()->create(['name' => 'Mau']);
    $buyer = Buyer::factory()->create([
        'status' => 'active', 'first_name' => 'Nandini', 'middle_name' => null, 'last_name' => 'Singh Chauhan',
        'phone' => '7318262249', 'city_id' => $buyerCity->id,
    ]);
    BookingBuyer::factory()->create([
        'booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100,
    ]);

    $actor = User::factory()->create();

    // An earlier payment already on the ledger, so Total Paid / Balance are
    // genuinely cumulative — not just equal to this one payment.
    $earlier = app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => cashMode()->id, 'amount' => '775000',
    ], $actor);
    app(VerifyPaymentAction::class)->handle($earlier, PaymentStatus::Success, $actor);

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $booking->id, 'payment_mode_id' => chequeMode()->id, 'amount' => '3000',
        'reference_number' => '308806333387', 'cheque_number' => '000212',
        'cheque_bank_name' => 'Kotak Mahindra Bank', 'cheque_date' => now()->toDateString(),
        'notes' => '100/- sq.ft. discount',
    ], $actor);
    $payment = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $actor);
    $receipt = $payment->receipt;

    $html = view('receipts.pdf', [
        'receipt' => $receipt,
        'brand' => Branding::fromConfig(),
        'extra' => ReceiptPdfData::build($receipt),
    ])->render();

    expect($html)
        // Receipt No. / Date
        ->toContain($receipt->receipt_number)
        ->toContain($receipt->payment_date->format('d/m/Y'))
        // Customer
        ->toContain($buyer->customer_code)
        ->toContain('NANDINI SINGH CHAUHAN')
        ->toContain('7318262249')
        ->toContain('Mau')
        // Project / Booked Branch
        ->toContain('Madhav Kunj')
        ->toContain('Lucknow')
        // Plot
        ->toContain('33')
        ->toContain('1130.22')
        ->toContain('sq ft')
        ->toContain('30 X 40')
        ->toContain('West')
        ->toContain('Phase 2')
        ->toContain('Sold')
        // Rate / Total plot amount
        ->toContain('800.00')
        ->toContain(number_format(791154, 2))
        // Current payment
        ->toContain(number_format(3000, 2))
        ->toContain('Three Thousand Rupees Only')
        // Total paid (775,000 + 3,000) / Balance (791,154 − 778,000)
        ->toContain(number_format(778000, 2))
        ->toContain(number_format(13154, 2))
        // Payment mode / reference / date / bank / remark
        ->toContain('Cheque')
        ->toContain('308806333387')
        ->toContain('Kotak Mahindra Bank')
        ->toContain('100/- sq.ft. discount')
        // No Payment Plan / Allocation dependency
        ->not->toContain('Payment Plan')
        ->not->toContain('Installment')
        ->not->toContain('Unallocated');

    // The same data renders a real PDF through the HTTP route.
    $this->actingAs(financeManager())
        ->get(route('receipts.pdf', $receipt))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('omits the official-bank-account note when no bank account is configured', function () {
    config()->set('branding.bank.account_number', null);
    $s = confirmedBookingScenario('1000000');
    $payment = verifiedPayment($s);
    $receipt = $payment->receipt;

    $html = view('receipts.pdf', [
        'receipt' => $receipt,
        'brand' => Branding::fromConfig(),
        'extra' => ReceiptPdfData::build($receipt),
    ])->render();

    expect($html)->not->toContain('official bank account');
});

it('shows the official-bank-account note once a bank account is configured', function () {
    config()->set('branding.bank.account_number', '8467843191');
    config()->set('branding.bank.ifsc', 'KKBK0005225');
    config()->set('branding.bank.name', 'Kotak Mahindra Bank');

    $s = confirmedBookingScenario('1000000');
    $payment = verifiedPayment($s);
    $receipt = $payment->receipt;

    $html = view('receipts.pdf', [
        'receipt' => $receipt,
        'brand' => Branding::fromConfig(),
        'extra' => ReceiptPdfData::build($receipt),
    ])->render();

    expect($html)->toContain('official bank account')
        ->toContain('8467843191')
        ->toContain('KKBK0005225');
});

it('embeds the configured logo and payment QR code as real images, not text placeholders', function () {
    config()->set('branding.logo_path', 'branding/logo.png');
    config()->set('branding.qr_path', 'branding/qr.jpeg');

    $s = confirmedBookingScenario('1000000');
    $payment = verifiedPayment($s);
    $receipt = $payment->receipt;

    $html = view('receipts.pdf', [
        'receipt' => $receipt,
        'brand' => Branding::fromConfig(),
        'extra' => ReceiptPdfData::build($receipt),
    ])->render();

    expect($html)->toContain('class="logo"')
        ->toContain(public_path('branding/logo.png'))
        ->toContain('class="qr"')
        ->toContain(public_path('branding/qr.jpeg'))
        ->toContain('KINDLY MAKE THE PLOT PAYMENT BY SCANNING THIS QR CODE');

    expect(file_exists(public_path('branding/logo.png')))->toBeTrue()
        ->and(file_exists(public_path('branding/qr.jpeg')))->toBeTrue();
});

it('omits the logo and QR images gracefully when not configured, without broken tags', function () {
    config()->set('branding.logo_path', null);
    config()->set('branding.qr_path', null);

    $s = confirmedBookingScenario('1000000');
    $payment = verifiedPayment($s);
    $receipt = $payment->receipt;

    $html = view('receipts.pdf', [
        'receipt' => $receipt,
        'brand' => Branding::fromConfig(),
        'extra' => ReceiptPdfData::build($receipt),
    ])->render();

    expect($html)->not->toContain('class="logo"')
        ->not->toContain('class="qr"')
        ->not->toContain('KINDLY MAKE THE PLOT PAYMENT BY SCANNING THIS QR CODE');
});
