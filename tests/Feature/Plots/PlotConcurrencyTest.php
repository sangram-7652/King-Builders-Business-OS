<?php

declare(strict_types=1);

use App\Actions\Plots\HoldPlotAction;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Plot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| ---------------------------------------------------------------------------
| Guarantee 1 — the in-transaction re-check (every driver)
| ---------------------------------------------------------------------------
| HoldPlotAction re-reads the (locked) row's status INSIDE the transaction, so
| whichever request commits first wins and the other is rejected — with no help
| from any UI-level guarding.
*/
it('rejects the losing hold once the plot has been claimed', function () {
    $plot = Plot::factory()->available()->create();
    $winner = User::factory()->create();
    $loser = User::factory()->create();

    app(HoldPlotAction::class)->handle($plot->id, $winner->id);

    expect(fn () => app(HoldPlotAction::class)->handle($plot->id, $loser->id))
        ->toThrow(DomainException::class);

    $plot->refresh();
    expect($plot->status)->toBe(PlotStatus::Hold)
        ->and($plot->held_by)->toBe($winner->id);
});

it('SELECT ... FOR UPDATE locks the plot row inside the hold transaction', function () {
    $plot = Plot::factory()->available()->create();

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));

    app(HoldPlotAction::class)->handle($plot->id, null);

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'for update')))
        ->toBeTrue('HoldPlotAction must SELECT ... FOR UPDATE the plot row');
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

/*
| ---------------------------------------------------------------------------
| Guarantee 2 — real row-level locking across two MySQL sessions
| ---------------------------------------------------------------------------
| Session A holds a FOR UPDATE lock on the plot; session B (1s lock-wait
| timeout) cannot acquire it and times out. This is the database itself
| serialising access to the row, exactly what HoldPlotAction relies on.
*/
it('serialises competing FOR UPDATE locks on the same plot row (mysql)', function () {
    foreach (['plot_lock_a', 'plot_lock_b'] as $name) {
        config()->set("database.connections.{$name}", [
            'driver' => 'mysql', 'host' => 'mysql', 'port' => 3306,
            'database' => 'king_builders', 'username' => 'king_builders', 'password' => 'secret',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'options' => [], 'strict' => true,
        ]);
    }

    $a = DB::connection('plot_lock_a');
    $b = DB::connection('plot_lock_b');

    try {
        $a->getPdo();
        $b->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('MySQL king_builders is not reachable from the test: '.$e->getMessage());
    }

    $suffix = uniqid();
    $projectId = $a->table('projects')->insertGetId([
        'name' => "CONC {$suffix}", 'code' => 'C'.substr($suffix, -8),
        'slug' => "conc-{$suffix}", 'status' => 'planning', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $blockId = $a->table('blocks')->insertGetId([
        'project_id' => $projectId, 'name' => 'B', 'code' => 'B', 'sort_order' => 0,
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $plotId = $a->table('plots')->insertGetId([
        'project_id' => $projectId, 'block_id' => $blockId, 'plot_number' => '1',
        'area' => 500, 'area_unit' => 'sq_ft', 'status' => 'available', 'is_active' => 1,
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

        expect($blocked)->toBeTrue('the second MySQL session must be blocked by the first session\'s row lock');
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
