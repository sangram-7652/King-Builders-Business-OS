<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Enums\BookingStatus;
use App\Enums\ChequeStatus;
use App\Enums\CollectionReminderType;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PromiseStatus;
use App\Models\Booking;
use App\Models\CollectionReminder;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Services\Payments\PaymentLedger;
use Illuminate\Support\Carbon;

/**
 * Regenerates internal collection reminders (M8) — in-app only, no external
 * channels. Idempotent: the `collection_reminders_dedupe` unique key means the
 * same reminder is never inserted twice for the same day.
 */
class GenerateCollectionRemindersAction
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    public function handle(?Carbon $asOf = null): int
    {
        $today = ($asOf ?? Carbon::today())->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $created = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->with(['activePaymentPlan.installments', 'collectionCase'])
            ->chunkById(200, function ($bookings) use (&$created, $today, $tomorrow): void {
                foreach ($bookings as $booking) {
                    $plan = $booking->activePaymentPlan;

                    if ($plan !== null) {
                        foreach ($plan->installments as $installment) {
                            if ($installment->status === InstallmentStatus::Waived
                                || ! $this->ledger->installmentOutstanding($installment)->isPositive()) {
                                continue;
                            }

                            $due = $installment->due_date->copy()->startOfDay();

                            $type = match (true) {
                                $due->equalTo($today) => CollectionReminderType::DueToday,
                                $due->equalTo($tomorrow) => CollectionReminderType::DueTomorrow,
                                $due->lt($today) => CollectionReminderType::Overdue,
                                default => null,
                            };

                            if ($type === null) {
                                continue;
                            }

                            $created += $this->upsert($booking, $type, 'installment', $installment->id, $today,
                                "{$type->label()}: {$installment->label()} on {$booking->booking_number}.");
                        }
                    }

                    foreach (PaymentPromise::query()->where('booking_id', $booking->id)->where('status', PromiseStatus::Open->value)->get() as $promise) {
                        $pd = $promise->promise_date->copy()->startOfDay();
                        $type = $pd->lt($today) ? CollectionReminderType::PromiseBroken
                            : ($pd->lte($tomorrow) ? CollectionReminderType::PromiseDue : null);

                        if ($type !== null) {
                            $created += $this->upsert($booking, $type, 'promise', $promise->id, $today,
                                "{$type->label()}: ₹{$promise->promised_amount} on {$booking->booking_number}.");
                        }
                    }

                    foreach (Payment::query()->where('booking_id', $booking->id)
                        ->where('status', PaymentStatus::Pending->value)
                        ->where('cheque_status', ChequeStatus::Pending->value)->get() as $chequePayment) {
                        $created += $this->upsert($booking, CollectionReminderType::ChequePending, 'payment', $chequePayment->id, $today,
                            "Cheque {$chequePayment->cheque_number} pending on {$booking->booking_number}.");
                    }
                }
            });

        return $created;
    }

    private function upsert(Booking $booking, CollectionReminderType $type, string $refType, int $refId, Carbon $on, string $message): int
    {
        $existing = CollectionReminder::query()
            ->where('booking_id', $booking->id)
            ->where('type', $type->value)
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->whereDate('remind_on', $on->toDateString())
            ->exists();

        if ($existing) {
            return 0;
        }

        CollectionReminder::create([
            'booking_id' => $booking->id,
            'collection_case_id' => $booking->collectionCase?->id,
            'type' => $type,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'remind_on' => $on->toDateString(),
            'message' => $message,
            'status' => CollectionReminder::STATUS_PENDING,
        ]);

        return 1;
    }
}
