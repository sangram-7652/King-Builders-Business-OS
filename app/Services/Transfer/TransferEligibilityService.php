<?php

declare(strict_types=1);

namespace App\Services\Transfer;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Enums\TransferRequestStatus;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Models\TransferRequest;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use App\Support\Registry\EligibilityResult;

/**
 * Decides whether a transfer request may be APPROVED (M10). Consumes M6/M7/M8/M9
 * state; never recomputes a balance and never mutates anything.
 *
 *   - booking is CONFIRMED
 *   - there is no OTHER active transfer on the same booking
 *   - financial clearance: outstanding / overdue within config, unless the
 *     reviewer recorded an explicit `financial_waiver_reason`
 *   - required transfer documents are VERIFIED (for ownership-moving types)
 */
class TransferEligibilityService
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    public function evaluate(TransferRequest $transfer): EligibilityResult
    {
        $cfg = config('transfer');
        $checks = [];
        $booking = $transfer->relationLoaded('booking') ? $transfer->booking : $transfer->booking()->first();

        $confirmed = $booking !== null && $booking->status === BookingStatus::Confirmed;
        $checks[] = $this->check('booking_confirmed', 'Booking confirmed', $confirmed,
            $confirmed ? null : 'Booking is not confirmed.');

        if ($transfer->transfer_type->movesOwnership()) {
            $newBuyerOk = $transfer->new_buyer_id !== null && $transfer->new_buyer_id !== $transfer->current_buyer_id;
            $checks[] = $this->check('new_buyer', 'A distinct incoming buyer is set', $newBuyerOk,
                $newBuyerOk ? null : 'The incoming buyer is missing or the same as the current owner.');
        }

        $conflict = TransferRequest::query()
            ->where('booking_id', $transfer->booking_id)
            ->whereKeyNot($transfer->getKey())
            ->whereIn('status', [
                TransferRequestStatus::Submitted->value,
                TransferRequestStatus::UnderReview->value,
                TransferRequestStatus::DocumentsPending->value,
                TransferRequestStatus::Approved->value,
            ])
            ->exists();
        $checks[] = $this->check('no_conflicting_transfer', 'No other active transfer on this booking', ! $conflict,
            $conflict ? 'Another transfer request on this booking is still open.' : null);

        // --- Financial ------------------------------------------------
        if ($booking !== null) {
            $waived = trim((string) $transfer->financial_waiver_reason) !== '';
            $outstanding = $this->ledger->bookingOutstanding($booking);
            $overdue = $this->ledger->bookingOverdue($booking);
            $max = Money::of($cfg['financial']['max_outstanding']);

            $financialOk = $waived || (
                (! $cfg['financial']['block_on_outstanding'] || ! $outstanding->greaterThan($max))
                && (! $cfg['financial']['block_on_overdue'] || ! $overdue->isPositive())
            );
            $checks[] = $this->check('financial_clearance', 'Financial clearance', $financialOk,
                $financialOk ? null : "Outstanding ₹{$outstanding->store()} / overdue ₹{$overdue->store()} (no waiver).");
        }

        // --- Documents (ownership-moving transfers only) --------------
        if ($transfer->transfer_type->movesOwnership() && $cfg['required_document_codes'] !== []) {
            $typeIds = DocumentType::query()->whereIn('code', $cfg['required_document_codes'])->pluck('id', 'code');
            $verified = Document::query()
                ->where('documentable_type', (new Booking)->getMorphClass())
                ->where('documentable_id', $transfer->booking_id)
                ->whereIn('document_type_id', $typeIds->values())
                ->where('status', DocumentStatus::Verified->value)
                ->count();
            $docsOk = $verified >= count($cfg['required_document_codes']);
            $checks[] = $this->check('documents_verified', 'Transfer documents verified', $docsOk,
                $docsOk ? null : "{$verified}/".count($cfg['required_document_codes']).' transfer documents verified.');
        }

        $eligible = ! in_array(false, array_column($checks, 'passed'), true);

        return new EligibilityResult(eligible: $eligible, checks: $checks);
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string|null}
     */
    private function check(string $key, string $label, bool $passed, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'passed' => $passed, 'detail' => $detail];
    }
}
