<?php

declare(strict_types=1);

use App\Actions\Bookings\CalculateBookingPriceAction;
use App\Actions\Bookings\ConfirmBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\OverrideBookingPriceAction;
use App\Actions\Bookings\UpdateBookingAction;
use App\Actions\Transfer\ExecutePlotTransferAction;
use App\Enums\BookingStatus;
use App\Enums\Masters\AreaUnit;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Livewire\Bookings\BookingForm;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\Project;
use App\Models\User;
use App\Support\Payments\ReceiptPdfData;
use App\Support\Pricing\PricingArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * Project with Block A (A1 = 50 sq yd, A2 = 900 sq ft), Block B (B1) and a
 * direct plot D1 (1200 sq ft).
 *
 * @return array<string, mixed>
 */
function pricingWorld(): array
{
    $project = Project::factory()->create();
    $blockA = Block::factory()->create(['project_id' => $project->id, 'name' => 'Block A']);
    $blockB = Block::factory()->create(['project_id' => $project->id, 'name' => 'Block B']);
    $avail = ['status' => PlotStatus::Available->value];

    return [
        'project' => $project, 'blockA' => $blockA, 'blockB' => $blockB,
        'a1' => Plot::factory()->forBlock($blockA)->create(['plot_number' => 'A1', 'area' => '50', 'area_unit' => 'sq_yd'] + $avail),
        'a2' => Plot::factory()->forBlock($blockA)->create(['plot_number' => 'A2', 'area' => '900', 'area_unit' => 'sq_ft'] + $avail),
        'b1' => Plot::factory()->forBlock($blockB)->create(['plot_number' => 'B1', 'area' => '600', 'area_unit' => 'sq_ft'] + $avail),
        'd1' => Plot::factory()->direct()->create(['project_id' => $project->id, 'plot_number' => 'D1', 'area' => '1200', 'area_unit' => 'sq_ft'] + $avail),
        'buyer' => Buyer::factory()->create(['status' => 'active']),
        'actor' => User::factory()->create(),
    ];
}

/** @return array<string, mixed> */
function pricingPayload(Plot $plot, Buyer $buyer, array $pricing = [], string $status = 'pending'): array
{
    return [
        'project_id' => $plot->project_id,
        'block_id' => $plot->block_id,
        'plot_id' => $plot->id,
        'booking_date' => now()->toDateString(),
        'status' => $status,
        'pricing' => $pricing + ['base_area' => '1', 'base_rate' => '2000', 'components' => []],
        'buyers' => [['buyer_id' => $buyer->id, 'ownership_percentage' => '100', 'is_primary' => true]],
    ];
}

/*
| ---------------------------------------------------------------------------
| 1-6. The form keeps the pricing area in sync with the selected plot.
| ---------------------------------------------------------------------------
*/

it('selecting a plot loads its area (converted to sq ft) and shows its own unit (1)', function () {
    $w = pricingWorld();

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['a1']->id)
        ->assertSet('base_area', '450.0000')
        ->assertSee('50 sq yd')
        ->assertSee('= 450 sq ft');
});

it('changing Plot A → Plot B replaces the pricing area, never keeps the old one (2)', function () {
    $w = pricingWorld();

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['a1']->id)
        ->assertSet('base_area', '450.0000')
        ->set('plot_id', (string) $w['a2']->id)
        ->assertSet('base_area', '900.0000');
});

it('changing the Block resets Plot and pricing area (3)', function () {
    $w = pricingWorld();

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['a2']->id)
        ->set('block_id', (string) $w['blockB']->id)
        ->assertSet('plot_id', '')
        ->assertSet('base_area', '')
        ->assertSee('Select a plot');
});

it('changing the Project resets Block, Plot and pricing area (4)', function () {
    $w = pricingWorld();

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $w['a2']->id)
        ->set('project_id', (string) Project::factory()->create()->id)
        ->assertSet('block_id', '')
        ->assertSet('plot_id', '')
        ->assertSet('base_area', '');
});

it('books a direct plot and a block plot with the plot-derived pricing area (5, 6)', function () {
    $w = pricingWorld();

    foreach ([[BookingForm::DIRECT_BLOCK, $w['d1'], '1200.0000'], [(string) $w['blockA']->id, $w['a1'], '450.0000']] as [$block, $plot, $area]) {
        Livewire::actingAs(bookingManager())->test(BookingForm::class)
            ->set('project_id', (string) $w['project']->id)
            ->set('block_id', $block)
            ->set('plot_id', (string) $plot->id)
            ->set('base_rate', '2000')
            ->set('buyers.0.buyer_id', (string) $w['buyer']->id)
            ->call('save')
            ->assertHasNoErrors();

        $booking = Booking::where('plot_id', $plot->id)->firstOrFail();
        expect((string) $booking->base_area)->toBe($area)
            ->and($booking->block_id)->toBe($plot->block_id);
    }
});

/*
| ---------------------------------------------------------------------------
| 7-12. Unit normalisation — exact factors, bcmath only.
| ---------------------------------------------------------------------------
*/

it('converts every supported unit to sq ft with the exact factors (7-11)', function (string $area, AreaUnit $unit, string $sqft) {
    expect(PricingArea::toSquareFeet($area, $unit))->toBe($sqft);
})->with([
    'sq ft unchanged' => ['450', AreaUnit::SquareFeet, '450.0000'],
    'sq ft decimals' => ['499.83', AreaUnit::SquareFeet, '499.8300'],
    'sq yd ×9' => ['50', AreaUnit::SquareYards, '450.0000'],
    'sq m ×10.7639104167' => ['100', AreaUnit::SquareMetres, '1076.3910'],
    'sq m rounds to 4 dp' => ['41.8064', AreaUnit::SquareMetres, '450.0003'],
    'acre ×43560' => ['1', AreaUnit::Acre, '43560.0000'],
    'acre fraction' => ['0.25', AreaUnit::Acre, '10890.0000'],
    'hectare ×107639.104167' => ['0.5', AreaUnit::Hectare, '53819.5521'],
]);

it('50 sq yd at ₹2,000 / sq ft prices at ₹9,00,000 — the plot itself is untouched (8)', function () {
    $w = pricingWorld();

    $booking = app(CreateBookingAction::class)->handle(pricingPayload($w['a1'], $w['buyer']), $w['actor']);

    expect((string) $booking->base_area)->toBe('450.0000')
        ->and($booking->final_amount)->toBe('900000.00')
        ->and($w['a1']->fresh()->area)->toBe('50.00')
        ->and($w['a1']->fresh()->area_unit)->toBe(AreaUnit::SquareYards);
});

it('uses no float arithmetic for area conversion (12)', function () {
    // Token-level scan (comments ignored): no (float) casts and no calls to
    // PHP's float-returning helpers — only bcmath.
    $floatCalls = [];
    $bcCalls = 0;

    foreach (['Support/Pricing/PricingArea.php', 'Enums/Masters/AreaUnit.php'] as $file) {
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents(app_path($file))),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_DOUBLE_CAST) {
                $floatCalls[] = "{$file}: (float)";
            }

            $prev = $tokens[$i - 1] ?? null;
            $isGlobalCall = is_array($t) && $t[0] === T_STRING && ($tokens[$i + 1] ?? null) === '('
                && ! (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_DOUBLE_COLON, T_OBJECT_OPERATOR], true));

            if ($isGlobalCall && in_array(strtolower($t[1]), ['floatval', 'round', 'number_format', 'floor', 'ceil', 'intdiv'], true)) {
                $floatCalls[] = "{$file}: {$t[1]}()";
            }

            if ($isGlobalCall && str_starts_with($t[1], 'bc')) {
                $bcCalls++;
            }
        }
    }

    expect($floatCalls)->toBe([])
        ->and($bcCalls)->toBeGreaterThan(0)
        ->and(AreaUnit::SquareMetres->squareFeetFactor())->toBeString();
});

/*
| ---------------------------------------------------------------------------
| Server-side: pricing area and hierarchy are never trusted from the client.
| ---------------------------------------------------------------------------
*/

it('ignores a manipulated base_area — the stored area always comes from the plot', function () {
    $w = pricingWorld();

    $booking = app(CreateBookingAction::class)->handle(pricingPayload($w['a2'], $w['buyer'], ['base_area' => '1']), $w['actor']);
    expect((string) $booking->base_area)->toBe('900.0000')
        ->and($booking->final_amount)->toBe('1800000.00');

    $updated = app(UpdateBookingAction::class)->handle($booking, [
        'pricing' => ['base_area' => '5', 'base_rate' => '2000', 'components' => []],
    ], $w['actor']);
    expect((string) $updated->base_area)->toBe('900.0000');
});

it('rejects a cross-project plot, a wrong-block plot and a block plot submitted as direct (17, 18, 19)', function () {
    $w = pricingWorld();
    $foreign = Plot::factory()->create(['status' => PlotStatus::Available->value]);

    $cases = [
        'cross-project' => ['project_id' => $w['project']->id, 'block_id' => $w['blockA']->id, 'plot_id' => $foreign->id],
        'wrong block' => ['project_id' => $w['project']->id, 'block_id' => $w['blockA']->id, 'plot_id' => $w['b1']->id],
        'direct with block plot' => ['project_id' => $w['project']->id, 'block_id' => null, 'plot_id' => $w['a2']->id],
    ];

    foreach ($cases as $overrides) {
        expect(fn () => app(CreateBookingAction::class)->handle($overrides + pricingPayload($w['a2'], $w['buyer']), $w['actor']))
            ->toThrow(DomainException::class);
    }

    expect(Booking::count())->toBe(0);
});

it('refuses to confirm when the plot area changed after pricing, instead of silently re-pricing', function () {
    $w = pricingWorld();
    $booking = app(CreateBookingAction::class)->handle(pricingPayload($w['a2'], $w['buyer']), $w['actor']);

    $w['a2']->forceFill(['area' => '1000'])->save();

    expect(fn () => app(ConfirmBookingAction::class)->handle($booking, $w['actor']))
        ->toThrow(DomainException::class, 'Edit and save the booking to re-price it');
    expect($booking->fresh()->status)->toBe(BookingStatus::Pending);

    // Re-saving re-prices from the plot; then confirmation succeeds.
    app(UpdateBookingAction::class)->handle($booking->fresh(), [], $w['actor']);
    $confirmed = app(ConfirmBookingAction::class)->handle($booking->fresh(), $w['actor']);
    expect((string) $confirmed->base_area)->toBe('1000.0000')
        ->and($confirmed->final_amount)->toBe('2000000.00');
});

/*
| ---------------------------------------------------------------------------
| 13-14. Price override survives unrelated saves; removal clears it fully.
| ---------------------------------------------------------------------------
*/

function overriddenBooking(array $w): Booking
{
    $booking = app(CreateBookingAction::class)->handle(pricingPayload($w['a2'], $w['buyer'], [
        'components' => [['type' => 'tax', 'name' => 'GST', 'calculation_type' => 'percentage', 'rate' => '5']],
    ]), $w['actor']);
    expect($booking->final_amount)->toBe('1890000.00');

    return app(OverrideBookingPriceAction::class)->handle($booking, '1800000', 'negotiated', bookingManager());
}

it('an override survives saving unrelated fields through the booking form (13)', function () {
    $w = pricingWorld();
    $booking = overriddenBooking($w);
    $before = $booking->fresh();

    Livewire::actingAs(bookingManager())->test(BookingForm::class, ['booking' => $before])
        ->assertSee('Manual price override active')
        ->assertCount('discountLines', 0) // the override is not an editable line
        ->assertCount('chargeLines', 0)
        ->set('notes', 'unrelated note')
        ->call('save')
        ->assertHasNoErrors();

    $after = $booking->fresh();
    expect($after->final_amount)->toBe('1800000.00')
        ->and($after->price_overridden)->toBeTrue()
        ->and($after->price_override_reason)->toBe('negotiated')
        ->and($after->price_override_by)->toBe($before->price_override_by)
        ->and($after->price_override_at->equalTo($before->price_override_at))->toBeTrue()
        ->and($after->priceLines->filter(fn ($l) => data_get($l->metadata, 'override'))->count())->toBe(1)
        ->and($after->notes)->toBe('unrelated note');
});

it('an override also survives an update with no pricing payload, and cannot be forged by a client row', function () {
    $w = pricingWorld();
    $booking = overriddenBooking($w);

    $after = app(UpdateBookingAction::class)->handle($booking->fresh(), [
        'notes' => 'x',
        'pricing' => ['base_rate' => '2000', 'components' => [
            ['type' => 'tax', 'name' => 'GST', 'calculation_type' => 'percentage', 'rate' => '5'],
            ['type' => 'discount', 'name' => 'Manual price override', 'calculation_type' => 'fixed', 'rate' => '999999',
                'metadata' => ['override' => true, 'reason' => 'forged', 'target_final' => '1']],
        ]],
    ], $w['actor']);

    expect($after->final_amount)->toBe('1800000.00')
        ->and($after->price_override_reason)->toBe('negotiated');
});

it('removing an override clears the line and ALL its metadata (14)', function () {
    $w = pricingWorld();
    $booking = overriddenBooking($w);

    $after = app(OverrideBookingPriceAction::class)->remove($booking->fresh(), bookingManager());

    expect($after->final_amount)->toBe('1890000.00')
        ->and($after->price_overridden)->toBeFalse()
        ->and($after->price_override_reason)->toBeNull()
        ->and($after->price_override_by)->toBeNull()
        ->and($after->price_override_at)->toBeNull()
        ->and($after->priceLines->filter(fn ($l) => data_get($l->metadata, 'override'))->count())->toBe(0);
});

it('overriding to exactly the calculated price also clears all override metadata', function () {
    $w = pricingWorld();
    $booking = overriddenBooking($w);

    $after = app(OverrideBookingPriceAction::class)->handle($booking->fresh(), '1890000', 'back to list', bookingManager());

    expect($after->price_overridden)->toBeFalse()
        ->and($after->price_override_reason)->toBeNull()
        ->and($after->price_override_by)->toBeNull();
});

/*
| ---------------------------------------------------------------------------
| 15-16. Precision: preview = stored = confirmed.
| ---------------------------------------------------------------------------
*/

it('rejects a rate with more than 4 decimals everywhere instead of rounding it silently (15)', function () {
    $w = pricingWorld();

    expect(fn () => app(CalculateBookingPriceAction::class)->handle(['base_area' => '450', 'base_rate' => '1234.56789']))
        ->toThrow(DomainException::class, 'at most 4 decimal places');
    expect(fn () => app(CreateBookingAction::class)->handle(pricingPayload($w['a2'], $w['buyer'], ['base_rate' => '1234.56789']), $w['actor']))
        ->toThrow(DomainException::class, 'at most 4 decimal places');
    expect(fn () => app(CalculateBookingPriceAction::class)->handle(['base_area' => '450', 'base_rate' => '1', 'components' => [
        ['type' => 'plc', 'name' => 'Corner', 'calculation_type' => 'per_sqft', 'rate' => '10.12345'],
    ]]))->toThrow(DomainException::class, 'Corner rate may have at most 4 decimal places');

    Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('base_rate', '1234.56789')
        ->call('save')
        ->assertHasErrors(['base_rate' => 'regex']);

    // Trailing zeros are not "extra precision".
    expect(app(CalculateBookingPriceAction::class)->handle(['base_area' => '450', 'base_rate' => '2000.000000'])->finalAmount->store())
        ->toBe('900000.00');
});

it('the form preview, the stored price and the confirmed price are identical (16)', function () {
    $w = pricingWorld();
    $plot = Plot::factory()->forBlock($w['blockA'])->create(['area' => '499.83', 'area_unit' => 'sq_ft', 'status' => PlotStatus::Available->value]);

    $form = Livewire::actingAs(bookingManager())->test(BookingForm::class)
        ->set('project_id', (string) $w['project']->id)
        ->set('block_id', (string) $w['blockA']->id)
        ->set('plot_id', (string) $plot->id)
        ->set('base_rate', '1234.5678')
        ->call('addTax')
        ->set('taxLines.0.name', 'GST')
        ->set('taxLines.0.rate', '18');
    $previewFinal = $form->get('preview')['final_amount'];

    $form->set('buyers.0.buyer_id', (string) $w['buyer']->id)->call('saveAndSubmit')->assertHasNoErrors();

    $booking = Booking::where('plot_id', $plot->id)->firstOrFail();
    $confirmed = app(ConfirmBookingAction::class)->handle($booking, $w['actor']);

    expect($booking->final_amount)->toBe($previewFinal)
        ->and($confirmed->final_amount)->toBe($previewFinal)
        ->and(data_get($confirmed->pricing_snapshot, 'totals.final'))->toBe($previewFinal);
});

/*
| ---------------------------------------------------------------------------
| 20-22. Frozen price: confirmation, Plot Transfer, receipts.
| ---------------------------------------------------------------------------
*/

it('a confirmed booking stays price-frozen when the plot is later edited (20)', function () {
    $w = pricingWorld();
    $booking = app(CreateBookingAction::class)->handle(pricingPayload($w['a2'], $w['buyer']), $w['actor']);
    $confirmed = app(ConfirmBookingAction::class)->handle($booking, $w['actor']);

    $w['a2']->forceFill(['area' => '1500'])->save();

    expect($confirmed->fresh()->final_amount)->toBe('1800000.00')
        ->and((string) $confirmed->fresh()->base_area)->toBe('900.0000');
    expect(fn () => app(UpdateBookingAction::class)->handle($confirmed->fresh(), [], $w['actor']))
        ->toThrow(DomainException::class);
});

it('Plot Transfer never recalculates the booking price, and the receipt keeps the frozen area (21, 22)', function () {
    $w = pricingWorld();
    $booking = app(CreateBookingAction::class)->handle(pricingPayload($w['a2'], $w['buyer']), $w['actor']);
    $booking = app(ConfirmBookingAction::class)->handle($booking, $w['actor']);
    $payment = payIn($booking, $w['actor'], '100000', now()->toDateString());

    app(ExecutePlotTransferAction::class)->handle($booking->fresh(), $w['a1']->id, null, possessionOfficer()); // 50 sq yd

    $after = $booking->fresh();
    expect($after->plot_id)->toBe($w['a1']->id)
        ->and((string) $after->base_area)->toBe('900.0000')
        ->and($after->final_amount)->toBe('1800000.00')
        ->and($after->pricing_snapshot)->toBe($booking->pricing_snapshot);

    $extra = ReceiptPdfData::build($payment->receipt->fresh());
    expect($extra['plotArea'])->toBe('900 sq ft')
        ->and($extra['rate'])->toBe('2000');
});
