<?php

declare(strict_types=1);

use App\Actions\Bookings\ConfirmBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Plot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Create + submit + confirm in one go. */
function confirmedBooking(array $s): Booking
{
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    app(SubmitBookingAction::class)->handle($booking, $s['actor']);

    return app(ConfirmBookingAction::class)->handle($booking->fresh(), $s['actor']);
}

it('marks the plot BOOKED when a booking is confirmed (13)', function () {
    $s = bookingScenario();

    $booking = confirmedBooking($s);

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($s['plot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($booking->confirmed_at)->not->toBeNull()
        ->and($booking->confirmed_by)->toBe($s['actor']->id);
});

it('clears any M4 hold metadata when confirming from HOLD', function () {
    $s = bookingScenario(['status' => PlotStatus::Hold->value, 'held_at' => now(), 'hold_reason' => 'walk-in']);

    confirmedBooking($s);

    $plot = $s['plot']->fresh();
    expect($plot->status)->toBe(PlotStatus::Booked)
        ->and($plot->held_at)->toBeNull()
        ->and($plot->hold_reason)->toBeNull();
});

it('allows only one live (pending/confirmed) booking per plot — DB guarantee (14)', function () {
    $s = bookingScenario();

    $first = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    app(SubmitBookingAction::class)->handle($first, $s['actor']);

    // Force a second row straight to pending, bypassing the action guard.
    expect(fn () => Booking::factory()->pending()->forPlot($s['plot'])->create())
        ->toThrow(QueryException::class);
});

it('rejects the losing confirmation once the plot is taken (15 — concurrency)', function () {
    $s = bookingScenario();

    // Two draft bookings for the same plot (drafts do not reserve).
    $a = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    $b = app(CreateBookingAction::class)->handle(
        bookingPayload($s, ['buyers' => [['buyer_id' => $s['buyerB']->id, 'ownership_percentage' => '100', 'is_primary' => true]]]),
        $s['actor']
    );

    // A wins the race to PENDING then CONFIRMED.
    app(SubmitBookingAction::class)->handle($a, $s['actor']);
    app(ConfirmBookingAction::class)->handle($a->fresh(), $s['actor']);

    // B cannot even reach PENDING now …
    expect(fn () => app(SubmitBookingAction::class)->handle($b->fresh(), $s['actor']))
        ->toThrow(DomainException::class);

    // … and a direct confirm attempt is a controlled business error, not a crash.
    expect(fn () => app(ConfirmBookingAction::class)->handle($b->fresh(), $s['actor']))
        ->toThrow(DomainException::class);

    expect($b->fresh()->status)->toBe(BookingStatus::Draft)
        ->and($s['plot']->fresh()->status)->toBe(PlotStatus::Booked);
});

it('SELECT ... FOR UPDATE locks the plot row during confirmation (16)', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    app(SubmitBookingAction::class)->handle($booking, $s['actor']);

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));

    app(ConfirmBookingAction::class)->handle($booking->fresh(), $s['actor']);

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'for update')))
        ->toBeTrue('ConfirmBookingAction must lock the plot (and booking) row FOR UPDATE');
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

it('serialises competing FOR UPDATE locks on the same plot row (17 — mysql)', function () {
    foreach (['bk_lock_a', 'bk_lock_b'] as $name) {
        config()->set("database.connections.{$name}", [
            'driver' => 'mysql', 'host' => 'mysql', 'port' => 3306,
            'database' => 'king_builders', 'username' => 'king_builders', 'password' => 'secret',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'options' => [], 'strict' => true,
        ]);
    }

    $a = DB::connection('bk_lock_a');
    $b = DB::connection('bk_lock_b');

    try {
        $a->getPdo();
        $b->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('MySQL king_builders is not reachable: '.$e->getMessage());
    }

    $suffix = uniqid();
    $projectId = $a->table('projects')->insertGetId([
        'name' => "BK CONC {$suffix}", 'code' => 'BK'.substr($suffix, -8),
        'slug' => "bk-conc-{$suffix}", 'status' => 'planning', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $blockId = $a->table('blocks')->insertGetId([
        'project_id' => $projectId, 'name' => 'B', 'code' => 'B', 'sort_order' => 0,
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $plotId = $a->table('plots')->insertGetId([
        'project_id' => $projectId, 'block_id' => $blockId, 'plot_number' => '1',
        'area' => 1000, 'area_unit' => 'sq_ft', 'status' => 'available', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        $a->beginTransaction();
        $a->table('plots')->where('id', $plotId)->lockForUpdate()->first();

        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $b->transaction(function () use ($b, $plotId): void {
                $b->table('plots')->where('id', $plotId)->lockForUpdate()->first();
            });
        } catch (QueryException $e) {
            $blocked = str_contains(strtolower($e->getMessage()), 'lock wait timeout');
        }

        $a->rollBack();
        expect($blocked)->toBeTrue('the second MySQL session must block on the first session\'s row lock');
    } finally {
        try {
            $a->rollBack();
        } catch (Throwable) {
        }
        $a->table('plots')->where('id', $plotId)->delete();
        $a->table('blocks')->where('id', $blockId)->delete();
        $a->table('projects')->where('id', $projectId)->delete();
        $a->disconnect();
        $b->disconnect();
    }
});

it('rolls back the whole confirmation if any step fails (18)', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s, ['buyers' => [
        ['buyer_id' => $s['buyerA']->id, 'ownership_percentage' => '60', 'is_primary' => true],
        ['buyer_id' => $s['buyerB']->id, 'ownership_percentage' => '40', 'is_primary' => false],
    ]]), $s['actor']);
    app(SubmitBookingAction::class)->handle($booking, $s['actor']);

    // Archive a buyer so confirmation's re-validation throws mid-flight.
    Buyer::whereKey($s['buyerB']->id)->update(['status' => 'archived']);

    expect(fn () => app(ConfirmBookingAction::class)->handle($booking->fresh(), $s['actor']))
        ->toThrow(DomainException::class);

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe(BookingStatus::Pending)
        ->and($fresh->confirmed_at)->toBeNull()
        ->and($fresh->pricing_snapshot)->toBeNull()
        ->and($s['plot']->fresh()->status)->toBe(PlotStatus::Available);
});

it('confirmation is idempotent', function () {
    $s = bookingScenario();
    $booking = confirmedBooking($s);

    $again = app(ConfirmBookingAction::class)->handle($booking->fresh(), $s['actor']);

    expect($again->status)->toBe(BookingStatus::Confirmed)
        ->and($s['plot']->fresh()->status)->toBe(PlotStatus::Booked);
});
