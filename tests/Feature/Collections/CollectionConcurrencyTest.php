<?php

declare(strict_types=1);

use App\Actions\Collections\CreatePaymentPromiseAction;
use App\Actions\Collections\EnsureCollectionCaseAction;
use App\Exceptions\DomainException;
use App\Models\CollectionCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('opens exactly one collection case per booking, even under a race', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);

    $a = app(EnsureCollectionCaseAction::class)->handle($s['booking']->fresh(), $s['actor']);
    $b = app(EnsureCollectionCaseAction::class)->handle($s['booking']->fresh(), $s['actor']);

    expect($b->id)->toBe($a->id)
        ->and(CollectionCase::where('booking_id', $s['booking']->id)->count())->toBe(1);

    // a raw second insert is refused by the unique key
    expect(fn () => CollectionCase::factory()->create(['booking_id' => $s['booking']->id]))
        ->toThrow(QueryException::class);
});

it('two promises cannot jointly over-commit the outstanding (case row locked)', function () {
    $s = overdueCaseScenario('1000000');

    app(CreatePaymentPromiseAction::class)->handle([
        'booking_id' => $s['booking']->id, 'promised_amount' => '700000', 'promise_date' => now()->addDay()->toDateString(),
    ], $s['actor']);

    // combined would be 1,400,000 > 1,000,000 outstanding
    expect(fn () => app(CreatePaymentPromiseAction::class)->handle([
        'booking_id' => $s['booking']->id, 'promised_amount' => '700000', 'promise_date' => now()->addDay()->toDateString(),
    ], $s['actor']))->toThrow(DomainException::class);

    expect($s['booking']->paymentPromises()->where('status', 'open')->count())->toBe(1);
});

it('locks the case row FOR UPDATE while a promise is created', function () {
    $s = overdueCaseScenario('1000000');

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));

    app(CreatePaymentPromiseAction::class)->handle([
        'booking_id' => $s['booking']->id, 'promised_amount' => '100000', 'promise_date' => now()->addDay()->toDateString(),
    ], $s['actor']);

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'collection_cases') && str_contains($q, 'for update')))
        ->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

it('serialises competing FOR UPDATE locks on the collection_cases row (mysql)', function () {
    foreach (['cc_lock_a', 'cc_lock_b'] as $name) {
        config()->set("database.connections.{$name}", [
            'driver' => 'mysql', 'host' => 'mysql', 'port' => 3306,
            'database' => 'king_builders', 'username' => 'king_builders', 'password' => 'secret',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'options' => [], 'strict' => true,
        ]);
    }

    $a = DB::connection('cc_lock_a');
    $b = DB::connection('cc_lock_b');

    try {
        $a->getPdo();
        $b->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('MySQL king_builders is not reachable: '.$e->getMessage());
    }

    $suffix = uniqid();
    $projectId = $a->table('projects')->insertGetId([
        'name' => "CC {$suffix}", 'code' => 'CC'.substr($suffix, -8), 'slug' => "cc-{$suffix}",
        'status' => 'planning', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $blockId = $a->table('blocks')->insertGetId([
        'project_id' => $projectId, 'name' => 'B', 'code' => 'B', 'sort_order' => 0, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $plotId = $a->table('plots')->insertGetId([
        'project_id' => $projectId, 'block_id' => $blockId, 'plot_number' => '1', 'area' => 1000,
        'area_unit' => 'sq_ft', 'status' => 'booked', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $bookingId = $a->table('bookings')->insertGetId([
        'booking_number' => 'BK-CC'.substr($suffix, -6), 'project_id' => $projectId, 'block_id' => $blockId,
        'plot_id' => $plotId, 'booking_date' => now()->toDateString(), 'status' => 'confirmed', 'final_amount' => 1000000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $caseId = $a->table('collection_cases')->insertGetId([
        'booking_id' => $bookingId, 'status' => 'open', 'priority' => 'low', 'opened_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        $a->beginTransaction();
        $a->table('collection_cases')->where('id', $caseId)->lockForUpdate()->first();

        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $b->transaction(function () use ($b, $caseId): void {
                $b->table('collection_cases')->where('id', $caseId)->lockForUpdate()->first();
            });
        } catch (QueryException $e) {
            $blocked = str_contains(strtolower($e->getMessage()), 'lock wait timeout');
        }

        $a->rollBack();
        expect($blocked)->toBeTrue();
    } finally {
        try {
            $a->rollBack();
        } catch (Throwable) {
        }
        $a->table('collection_cases')->where('id', $caseId)->delete();
        $a->table('bookings')->where('id', $bookingId)->delete();
        $a->table('plots')->where('id', $plotId)->delete();
        $a->table('blocks')->where('id', $blockId)->delete();
        $a->table('projects')->where('id', $projectId)->delete();
        $a->disconnect();
        $b->disconnect();
    }
});
