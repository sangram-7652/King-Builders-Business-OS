<?php

declare(strict_types=1);

use App\Actions\Payments\AllocatePaymentAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Receipt;
use App\Services\Payments\PaymentLedger;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| Guarantee 1 — the in-transaction re-check (every driver)
| Once one allocation has consumed an installment's outstanding balance, a
| second allocation attempting to consume the same balance is rejected. No UI
| guard involved.
*/
it('two allocations cannot both consume the same installment balance', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->addMonth()->toDateString()],
        ['type' => 'amount', 'value' => '700000', 'due_date' => now()->addMonths(2)->toDateString()],
    ]);
    $i1 = $s['booking']->activePaymentPlan->installments->first();

    $mkPayment = function () use ($s) {
        $p = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '300000'], $s['actor']);
        $p = app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);
        $p->allocations()->delete();

        return $p->fresh();
    };

    $a = $mkPayment();
    $b = $mkPayment();

    app(AllocatePaymentAction::class)->handle($a, [['installment_id' => $i1->id, 'amount' => '300000']], financeManager());

    expect(fn () => app(AllocatePaymentAction::class)->handle($b, [['installment_id' => $i1->id, 'amount' => '300000']], financeManager()))
        ->toThrow(DomainException::class);

    expect(app(PaymentLedger::class)->installmentPaid($i1->fresh())->store())->toBe('300000.00');
});

it('auto-allocation locks the installment rows FOR UPDATE', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);

    $payment = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '250000'], $s['actor']);

    $sql = [];
    DB::listen(fn ($q) => $sql[] = strtolower($q->sql));

    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    expect(collect($sql)->contains(fn (string $q) => str_contains($q, 'for update')))->toBeTrue();
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no row-level FOR UPDATE');

/*
| Guarantee 2 — concurrency-safe code generation
*/
it('generates distinct payment numbers under repeated calls (3)', function () {
    $s = confirmedBookingScenario();

    $numbers = collect(range(1, 20))->map(fn () => app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '1',
    ], $s['actor'])->payment_number);

    expect($numbers->unique()->count())->toBe(20)
        ->and($numbers->first())->toBe('PAY-000001')
        ->and($numbers->last())->toBe('PAY-000020');
});

it('generates distinct receipt numbers under repeated verification (4)', function () {
    $s = confirmedBookingScenario('2000000');
    activePlanFor($s['booking'], $s['actor']);

    $receipts = collect(range(1, 10))->map(function () use ($s) {
        $p = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '10000'], $s['actor']);

        return app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor'])->receipt->receipt_number;
    });

    expect($receipts->unique()->count())->toBe(10)
        ->and(Receipt::count())->toBe(10);
});

/*
| Guarantee 3 — real row-level locking across two MySQL sessions
| Session A holds a FOR UPDATE lock on the code_sequences row; session B
| (1s lock-wait timeout) cannot acquire it. This is the database serialising
| PAY / RCPT number generation.
*/
it('serialises competing FOR UPDATE locks on the sequence row (mysql)', function () {
    foreach (['seq_lock_a', 'seq_lock_b'] as $name) {
        config()->set("database.connections.{$name}", [
            'driver' => 'mysql', 'host' => 'mysql', 'port' => 3306,
            'database' => 'king_builders', 'username' => 'king_builders', 'password' => 'secret',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'options' => [], 'strict' => true,
        ]);
    }

    $a = DB::connection('seq_lock_a');
    $b = DB::connection('seq_lock_b');

    try {
        $a->getPdo();
        $b->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('MySQL king_builders is not reachable: '.$e->getMessage());
    }

    $key = 'conc_test_'.uniqid();
    $a->table('code_sequences')->insert(['key' => $key, 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);

    try {
        $a->beginTransaction();
        $a->table('code_sequences')->where('key', $key)->lockForUpdate()->first();

        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $b->transaction(function () use ($b, $key): void {
                $b->table('code_sequences')->where('key', $key)->lockForUpdate()->first();
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
        $a->table('code_sequences')->where('key', $key)->delete();
        $a->disconnect();
        $b->disconnect();
    }
});

it('the SequenceGenerator itself hands out distinct values', function () {
    $seen = collect(range(1, 50))->map(fn () => DB::transaction(fn () => app(SequenceGenerator::class)->next('unit_test')));

    expect($seen->unique()->count())->toBe(50)
        ->and($seen->min())->toBe(1)
        ->and($seen->max())->toBe(50);
});
