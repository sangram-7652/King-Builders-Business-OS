<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\CollectionActivityType;
use App\Enums\CollectionReminderType;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\ChequeBounce;
use App\Models\Payment;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Records a cheque bounce (M8) and drives the M7 consequence.
 *
 *  - bounce while PENDING  → M7 VerifyPaymentAction(FAILED)  (payment kept)
 *  - bounce after CLEARED  → M7 ReversePaymentAction (system-initiated; the
 *    caller has `cheques.bounce`) — payment kept as REVERSED, receipt voided
 *  - never deletes the payment, never silently nulls a SUCCESS payment
 *  - opens / links a collection case, records the timeline event and a reminder
 *  - idempotent: one bounce record per payment
 */
class RecordChequeBounceAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    public function __construct(
        private readonly EnsureCollectionCaseAction $ensureCase,
        private readonly VerifyPaymentAction $verify,
        private readonly ReversePaymentAction $reverse,
    ) {}

    /**
     * @param  array{bounce_date: string, bounce_reason: string, bank_charges?: mixed, notes?: string|null}  $data
     */
    public function handle(Payment $payment, array $data, User $actor): ChequeBounce
    {
        if (! $actor->can('cheques.bounce')) {
            throw new DomainException('You are not authorised to record a cheque bounce.');
        }

        $payment->loadMissing(['paymentMode', 'chequeBounce', 'booking']);

        if (! (bool) $payment->paymentMode?->is_cheque && $payment->cheque_status === null) {
            throw new DomainException('That payment is not a cheque payment.');
        }

        if ($payment->chequeBounce !== null) {
            return $payment->chequeBounce;
        }

        if (trim((string) ($data['bounce_reason'] ?? '')) === '') {
            throw new DomainException('A bounce reason is required.');
        }

        $case = $this->ensureCase->handle($payment->booking, $actor);

        return $this->transaction(function () use ($payment, $data, $actor, $case): ChequeBounce {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (($existing = ChequeBounce::query()->where('payment_id', $locked->id)->first()) !== null) {
                return $existing;
            }

            $wasCleared = $locked->status === PaymentStatus::Success;

            if ($wasCleared) {
                $this->reverse->handle($locked, 'Cheque bounced: '.$data['bounce_reason'], $actor, systemInitiated: true);
            } elseif ($locked->status === PaymentStatus::Pending) {
                $this->verify->handle($locked, PaymentStatus::Failed, $actor);
            }

            $bounce = ChequeBounce::create([
                'payment_id' => $locked->id,
                'booking_id' => $locked->booking_id,
                'collection_case_id' => $case->id,
                'bounce_date' => $data['bounce_date'],
                'bounce_reason' => $data['bounce_reason'],
                'bank_charges' => $data['bank_charges'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'payment_was_cleared' => $wasCleared,
                'handled_by' => $actor->id,
            ]);

            $case->loadMissing('booking');
            $case->recordActivity(
                CollectionActivityType::ChequeBounced,
                "Cheque {$locked->cheque_number} bounced — {$data['bounce_reason']}.",
                ['payment_id' => $locked->id, 'was_cleared' => $wasCleared],
                $actor,
            );

            $case->reminders()->firstOrCreate(
                [
                    'type' => CollectionReminderType::ChequeBounced->value,
                    'reference_type' => 'payment',
                    'reference_id' => $locked->id,
                    'remind_on' => now()->toDateString(),
                ],
                [
                    'booking_id' => $case->booking_id,
                    'message' => "Cheque {$locked->cheque_number} on booking {$case->booking->booking_number} bounced.",
                    'status' => 'pending',
                ],
            );

            Log::info('cheque.bounced', [
                'payment_id' => $locked->id,
                'booking_id' => $locked->booking_id,
                'was_cleared' => $wasCleared,
                'by' => $actor->id,
            ]);

            $this->syncCase($case);

            return $bounce;
        });
    }
}
