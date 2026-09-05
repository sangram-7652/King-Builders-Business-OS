<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\ChequeStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Masters\PaymentMode;
use App\Models\Payment;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Records a payment against a CONFIRMED booking as PENDING — it does not touch
 * balances until it is verified SUCCESS.
 *
 * `payment_number` (PAY-000001) comes from the concurrency-safe sequence. An
 * optional `idempotency_key` makes a retried request return the original
 * payment instead of creating a duplicate.
 */
class RecordPaymentAction
{
    use RunsInTransaction;

    public function __construct(private readonly SequenceGenerator $sequences) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): Payment
    {
        $booking = Booking::query()->findOrFail($data['booking_id']);

        if (! $booking->isConfirmed()) {
            throw new DomainException('Payments can only be recorded against a confirmed booking.');
        }

        $amount = Money::of($data['amount'] ?? null);

        if (! $amount->isPositive()) {
            throw new DomainException('A payment amount must be greater than zero.');
        }

        // A payment is recorded when it is received — never in the future, and
        // not implausibly far in the past (guards data-entry typos that would
        // silently skew every date-bucketed report).
        if (isset($data['payment_date'])) {
            $date = Carbon::parse((string) $data['payment_date'])->startOfDay();

            if ($date->isFuture()) {
                throw new DomainException('A payment date cannot be in the future.');
            }
            if ($date->lt(now()->subYears(10))) {
                throw new DomainException('That payment date is too far in the past.');
            }
        }

        /** @var PaymentMode $mode */
        $mode = PaymentMode::query()->findOrFail($data['payment_mode_id']);

        if (! $mode->is_active) {
            throw new DomainException('That payment mode is inactive.');
        }

        $key = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key']
            : null;

        if ($key !== null && ($existing = Payment::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }

        $isCheque = (bool) $mode->is_cheque;

        if ($isCheque && (empty($data['cheque_number']) || empty($data['cheque_date']))) {
            throw new DomainException('Cheque payments need a cheque number and cheque date.');
        }

        try {
            return $this->transaction(function () use ($booking, $mode, $amount, $data, $actor, $key, $isCheque): Payment {
                $payment = Payment::create([
                    'payment_number' => Payment::formatCode($this->sequences->next(Payment::SEQUENCE_KEY)),
                    'idempotency_key' => $key,
                    'booking_id' => $booking->id,
                    'payment_mode_id' => $mode->id,
                    'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                    'amount' => $amount->store(),
                    'status' => PaymentStatus::Pending,
                    'reference_number' => $data['reference_number'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'cheque_number' => $isCheque ? $data['cheque_number'] : null,
                    'cheque_bank_name' => $isCheque ? ($data['cheque_bank_name'] ?? null) : null,
                    'cheque_date' => $isCheque ? $data['cheque_date'] : null,
                    'cheque_status' => $isCheque ? ChequeStatus::Pending : null,
                    'received_by' => $actor->id,
                ]);

                Log::info('payment.recorded', [
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'booking_id' => $booking->id,
                    'amount' => $payment->amount,
                    'mode' => $mode->code,
                    'by' => $actor->id,
                ]);

                return $payment;
            });
        } catch (QueryException $e) {
            // Idempotency race: another request created the payment first.
            if ($key !== null && ($existing = Payment::query()->where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
