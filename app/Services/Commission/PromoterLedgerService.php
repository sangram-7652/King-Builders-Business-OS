<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Enums\PromoterLedgerEntryType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\CommissionPayout;
use App\Models\Partner;
use App\Models\PromoterLedgerEntry;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;

/**
 * The promoter's financial ledger — advance given, commission earned, the
 * automatic advance adjustment, and (informational) payout mirrors. This is
 * the single source of truth for a promoter's advance balance: it is always
 * DERIVED by summing the ledger, never read from a cached column.
 *
 * Every public method here locks the promoter row first, so two concurrent
 * writers for the SAME promoter (e.g. two bookings confirmed at once) always
 * serialise instead of racing on the advance balance.
 */
class PromoterLedgerService
{
    use RunsInTransaction;

    /** The promoter's current outstanding advance, derived purely from the ledger. */
    public function advanceBalance(Partner $partner): Money
    {
        $balance = Money::zero();

        PromoterLedgerEntry::query()
            ->where('partner_id', $partner->id)
            ->orderBy('id')
            ->get(['type', 'advance_amount', 'adjustment_amount'])
            ->each(function (PromoterLedgerEntry $row) use (&$balance): void {
                $balance = match ($row->type) {
                    PromoterLedgerEntryType::AdvanceGiven, PromoterLedgerEntryType::AdvanceAdjustmentReversed
                        => $balance->plus(Money::of((string) ($row->advance_amount ?? '0'))),
                    PromoterLedgerEntryType::AdvanceRefunded
                        => $balance->minus(Money::of((string) ($row->advance_amount ?? '0'))),
                    PromoterLedgerEntryType::Commission
                        => $balance->minus(Money::of((string) ($row->adjustment_amount ?? '0'))),
                    default => $balance,
                };
            });

        return $balance;
    }

    /** §1 / §11 — advance given at promoter creation or via "Add Advance". */
    public function giveAdvance(Partner $partner, Money $amount, string $description, User $actor): PromoterLedgerEntry
    {
        if (! $amount->isPositive()) {
            throw new DomainException('The advance amount must be greater than zero.');
        }

        return $this->transaction(function () use ($partner, $amount, $description, $actor): PromoterLedgerEntry {
            $locked = $this->lockPartner($partner);

            return $this->append($locked, PromoterLedgerEntryType::AdvanceGiven, [
                'advance_amount' => $amount->store(),
                'description' => $description !== '' ? $description : 'Advance given.',
            ], $actor);
        });
    }

    /** §12 — a simple, controlled manual refund / reduction. Never allowed to push the balance negative. */
    public function refundAdvance(Partner $partner, Money $amount, string $reason, User $actor): PromoterLedgerEntry
    {
        if (! $amount->isPositive()) {
            throw new DomainException('The refund/adjustment amount must be greater than zero.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A refund/adjustment needs a reason.');
        }

        return $this->transaction(function () use ($partner, $amount, $reason, $actor): PromoterLedgerEntry {
            $locked = $this->lockPartner($partner);
            $balance = $this->advanceBalance($locked);

            if ($amount->greaterThan($balance)) {
                throw new DomainException("That exceeds the current advance balance of ₹{$balance->store()}.");
            }

            return $this->append($locked, PromoterLedgerEntryType::AdvanceRefunded, [
                'advance_amount' => $amount->store(),
                'description' => $reason,
            ], $actor);
        });
    }

    /**
     * §3 / §4 / §8 — apply a freshly (re)computed gross commission against the
     * promoter's outstanding advance and write the single `commission` ledger
     * row (gross + adjusted + payable together).
     *
     * Idempotent under recalculation: if this case already has an active
     * (unreversed) commission row from an earlier calculation, it is
     * compensated first via {@see reverseCaseAdjustment()} before the fresh
     * figure is applied — so the ledger always reflects only the LATEST
     * calculation, never double-consumes the advance.
     */
    public function applyCommission(CommissionCase $case, Partner $partner, Booking $booking, Money $gross, User $actor): PromoterLedgerEntry
    {
        return $this->transaction(function () use ($case, $partner, $booking, $gross, $actor): PromoterLedgerEntry {
            $locked = $this->lockPartner($partner);

            $this->reverseActiveAdjustment($case, $locked, $actor, 'Superseded by a recalculated commission.');

            $balance = $this->advanceBalance($locked);
            $adjusted = Money::min($balance, $gross);
            $payable = $gross->minus($adjusted);

            return $this->append($locked, PromoterLedgerEntryType::Commission, [
                'booking_id' => $booking->id,
                'commission_case_id' => $case->id,
                'gross_commission_amount' => $gross->store(),
                'adjustment_amount' => $adjusted->store(),
                'payable_amount' => $payable->store(),
                'reference' => $booking->booking_number,
                'description' => "Commission earned on {$booking->booking_number}.",
            ], $actor);
        });
    }

    /**
     * §10 — compensate a case's currently-active advance adjustment, if any
     * (a no-op when the case never consumed advance, or already had its
     * adjustment reversed). Used when a case is cancelled, reversed, or
     * superseded by a recalculation.
     */
    public function reverseCaseAdjustment(CommissionCase $case, ?User $actor, string $note): void
    {
        $this->transaction(function () use ($case, $actor, $note): void {
            $locked = Partner::query()->whereKey($case->partner_id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $this->reverseActiveAdjustment($case, $locked, $actor, $note);
        });
    }

    private function reverseActiveAdjustment(CommissionCase $case, Partner $lockedPartner, ?User $actor, string $note): void
    {
        /** @var PromoterLedgerEntry|null $active */
        $active = PromoterLedgerEntry::query()
            ->where('commission_case_id', $case->id)
            ->where('type', PromoterLedgerEntryType::Commission->value)
            ->whereNull('reversed_at')
            ->lockForUpdate()
            ->first();

        if ($active === null) {
            return;
        }

        if (bccomp((string) $active->adjustment_amount, '0', 2) <= 0) {
            // Nothing was actually consumed — just mark it so it is not
            // reconsidered "active" again, no compensating row needed.
            $active->forceFill(['reversed_at' => now()])->save();

            return;
        }

        $reversal = $this->append($lockedPartner, PromoterLedgerEntryType::AdvanceAdjustmentReversed, [
            'booking_id' => $active->booking_id,
            'commission_case_id' => $case->id,
            'advance_amount' => $active->adjustment_amount,
            'reference' => $case->case_number,
            'description' => $note,
        ], $actor);

        $active->forceFill(['reversed_at' => now(), 'reversal_entry_id' => $reversal->id])->save();
    }

    /** §2E — informational mirror of a recorded payout. Never moves the advance balance. */
    public function mirrorPayoutRecorded(CommissionCase $case, CommissionPayout $payout, User $actor): void
    {
        $this->transaction(function () use ($case, $payout, $actor): void {
            $locked = Partner::query()->whereKey($case->partner_id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $this->append($locked, PromoterLedgerEntryType::CommissionPayoutRecorded, [
                'booking_id' => $case->booking_id,
                'commission_case_id' => $case->id,
                'commission_payout_id' => $payout->id,
                'payout_amount' => $payout->amount,
                'reference' => $case->case_number,
                'description' => "Payout recorded ({$payout->method->label()}).",
            ], $actor);
        });
    }

    /** §2E — informational mirror of a voided payout. Never moves the advance balance. */
    public function mirrorPayoutVoided(CommissionCase $case, CommissionPayout $payout, User $actor): void
    {
        $this->transaction(function () use ($case, $payout, $actor): void {
            $locked = Partner::query()->whereKey($case->partner_id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $this->append($locked, PromoterLedgerEntryType::CommissionPayoutVoided, [
                'booking_id' => $case->booking_id,
                'commission_case_id' => $case->id,
                'commission_payout_id' => $payout->id,
                'payout_amount' => $payout->amount,
                'reference' => $case->case_number,
                'description' => 'Payout voided.',
            ], $actor);
        });
    }

    private function lockPartner(Partner $partner): Partner
    {
        return Partner::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function append(Partner $lockedPartner, PromoterLedgerEntryType $type, array $attrs, ?User $actor): PromoterLedgerEntry
    {
        $before = $this->advanceBalance($lockedPartner);

        $delta = match (true) {
            in_array($type, [PromoterLedgerEntryType::AdvanceGiven, PromoterLedgerEntryType::AdvanceAdjustmentReversed], true)
                => Money::of((string) ($attrs['advance_amount'] ?? '0')),
            $type === PromoterLedgerEntryType::AdvanceRefunded
                => Money::zero()->minus(Money::of((string) ($attrs['advance_amount'] ?? '0'))),
            $type === PromoterLedgerEntryType::Commission
                => Money::zero()->minus(Money::of((string) ($attrs['adjustment_amount'] ?? '0'))),
            default => Money::zero(),
        };

        return PromoterLedgerEntry::create($attrs + [
            'partner_id' => $lockedPartner->id,
            'type' => $type->value,
            'balance_after' => $before->plus($delta)->store(),
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}
