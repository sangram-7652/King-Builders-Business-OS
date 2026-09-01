<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Sequences\SequenceGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Issues the receipt for a verified (SUCCESS) payment. One receipt per payment
 * — calling it again returns the existing one (idempotent). `receipt_number`
 * (RCPT-000001) is concurrency-safe.
 *
 * The receipt snapshots the buyer name and payment-mode label so a later
 * master edit never rewrites an issued receipt.
 */
class GenerateReceiptAction
{
    use RunsInTransaction;

    public function __construct(private readonly SequenceGenerator $sequences) {}

    public function handle(Payment $payment, User $actor): Receipt
    {
        $payment->loadMissing(['receipt', 'paymentMode', 'booking.primaryBookingBuyer.buyer']);

        if ($payment->receipt !== null) {
            return $payment->receipt;
        }

        if ($payment->status !== PaymentStatus::Success) {
            throw new DomainException('A receipt can only be issued for a successful payment.');
        }

        return $this->transaction(function () use ($payment, $actor): Receipt {
            // Re-check inside the transaction / lock against a concurrent issue.
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (($existing = Receipt::query()->where('payment_id', $locked->id)->first()) !== null) {
                return $existing;
            }

            $buyer = $payment->booking->primaryBookingBuyer?->buyer;

            $receipt = Receipt::create([
                'receipt_number' => Receipt::formatCode($this->sequences->next(Receipt::SEQUENCE_KEY)),
                'payment_id' => $locked->id,
                'booking_id' => $locked->booking_id,
                'buyer_id' => $buyer?->id,
                'amount' => $locked->amount,
                'payment_date' => $locked->payment_date,
                'payment_mode_label' => $payment->paymentMode?->name ?? 'Payment',
                'reference_number' => $locked->reference_number,
                'buyer_name_snapshot' => $buyer?->fullName() ?? '—',
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            Log::info('receipt.issued', [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'payment_id' => $locked->id,
                'amount' => $receipt->amount,
                'by' => $actor->id,
            ]);

            return $receipt;
        });
    }
}
