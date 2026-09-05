<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Enums\CommissionCaseStatus;
use App\Enums\PartnerActivityType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\BookingPartnerAttribution;
use App\Models\CommissionCase;
use App\Models\Partner;
use App\Models\PartnerProjectAuthorization;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Partners\AttributionSplit;
use Illuminate\Support\Facades\Log;

/**
 * Sets the channel-partner split for a booking (M14.2).
 *
 * The passed split replaces the current active set. Rather than editing rows,
 * the whole active set is SUPERSEDED (status → superseded, `ended_at` stamped)
 * and a fresh set is written with the next `revision`, so historical attribution
 * — and any commission snapshot taken from it — is never mutated.
 *
 *   - shares must total exactly 100.00 with exactly one primary (or be empty)
 *   - an empty split marks the booking a direct sale
 *   - every partner must be ACTIVE and (by default) authorised for the project
 *   - a CANCELLED booking is rejected; the M6 lifecycle is respected
 *
 * @phpstan-import-type RawShare from AttributionSplit
 */
class SetBookingPartnerAttribution
{
    use RunsInTransaction;

    /**
     * @param  iterable<RawShare>  $shares
     */
    public function handle(Booking $booking, iterable $shares, User $actor, ?string $reason = null): Booking
    {
        if ($booking->isCancelled()) {
            throw new DomainException('A cancelled booking cannot be attributed to a partner.');
        }

        if ($booking->isConfirmed() && ! config('partners.attribution.allow_edit_after_confirmation')) {
            throw new DomainException('Partner attribution is locked once the booking is confirmed.');
        }

        $split = AttributionSplit::fromRows($shares);

        return $this->transaction(function () use ($booking, $split, $actor, $reason): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            // A committed commission (approved / paid) locks the split — that
            // partner's money is already an obligation. Pending cases are fine;
            // GenerateCommissionCases re-derives them from the new split.
            $committed = CommissionCase::query()
                ->where('booking_id', $locked->id)
                ->whereIn('status', [
                    CommissionCaseStatus::Approved->value,
                    CommissionCaseStatus::PartiallyPaid->value,
                    CommissionCaseStatus::Paid->value,
                ])
                ->exists();

            if ($committed) {
                throw new DomainException('This booking has an approved commission — its partner split can no longer be changed.');
            }

            $partners = $this->resolvePartners($locked, $split);

            $previousRows = BookingPartnerAttribution::query()
                ->where('booking_id', $locked->id)
                ->where('status', 'active')
                ->get();
            $previousPartnerIds = $previousRows->pluck('partner_id')->all();

            // Clearing an already-direct booking is a no-op.
            if ($split->isEmpty() && $previousRows->isEmpty()) {
                return $locked->load('partnerAttributions.partner');
            }

            if ($previousRows->isNotEmpty()) {
                BookingPartnerAttribution::query()
                    ->whereKey($previousRows->modelKeys())
                    ->update(['status' => 'superseded', 'ended_at' => now()]);
            }

            $revision = ((int) BookingPartnerAttribution::query()
                ->where('booking_id', $locked->id)->max('revision')) + 1;

            foreach ($split->shares as $share) {
                BookingPartnerAttribution::create([
                    'booking_id' => $locked->id,
                    'partner_id' => $share['partner_id'],
                    'share_percentage' => $share['share_percentage'],
                    'role' => $share['role'],
                    'status' => 'active',
                    'revision' => $revision,
                    'attributed_by' => $actor->id,
                    'attributed_at' => now(),
                    'reason' => $reason,
                ]);
            }

            $this->recordActivities($locked, $partners, $split, $previousPartnerIds, $previousRows->isNotEmpty(), $actor);

            Log::info('booking.partner_attribution_set', [
                'booking_id' => $locked->id,
                'revision' => $revision,
                'partner_ids' => $split->partnerIds(),
                'by' => $actor->id,
            ]);

            return $locked->load('partnerAttributions.partner');
        });
    }

    /**
     * @return array<int, Partner> keyed by partner id
     */
    private function resolvePartners(Booking $booking, AttributionSplit $split): array
    {
        if ($split->isEmpty()) {
            return [];
        }

        /** @var array<int, Partner> $partners */
        $partners = Partner::query()->whereKey($split->partnerIds())->get()->keyBy('id')->all();

        foreach ($split->partnerIds() as $partnerId) {
            $partner = $partners[$partnerId] ?? null;
            if ($partner === null) {
                throw new DomainException('One of the selected partners no longer exists.');
            }
            if (! $partner->canReceiveAttribution()) {
                throw new DomainException("{$partner->displayName()} is {$partner->status->label()} and cannot be attributed.");
            }
            if (config('partners.attribution.require_project_authorization') && ! $this->isAuthorised($partner, $booking)) {
                throw new DomainException("{$partner->displayName()} is not authorised for this project.");
            }
        }

        return $partners;
    }

    private function isAuthorised(Partner $partner, Booking $booking): bool
    {
        return PartnerProjectAuthorization::query()
            ->where('partner_id', $partner->id)
            ->where('project_id', $booking->project_id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @param  array<int, Partner>  $partners
     * @param  list<int>  $previousPartnerIds
     */
    private function recordActivities(Booking $booking, array $partners, AttributionSplit $split, array $previousPartnerIds, bool $hadPrevious, User $actor): void
    {
        $newIds = $split->partnerIds();

        foreach ($split->shares as $share) {
            $partner = $partners[$share['partner_id']];
            $type = in_array($partner->id, $previousPartnerIds, true)
                ? PartnerActivityType::BookingAttributionUpdated
                : PartnerActivityType::BookingAttributed;

            $partner->recordActivity($type, "Booking {$booking->booking_number}: {$share['share_percentage']}% ({$share['role']->label()}).", [
                'booking_id' => $booking->id,
                'share_percentage' => $share['share_percentage'],
                'role' => $share['role']->value,
            ], $actor);
        }

        foreach (array_diff($previousPartnerIds, $newIds) as $droppedId) {
            Partner::find($droppedId)?->recordActivity(
                PartnerActivityType::BookingAttributionRemoved,
                "Booking {$booking->booking_number} attribution removed.",
                ['booking_id' => $booking->id],
                $actor,
            );
        }
    }
}
