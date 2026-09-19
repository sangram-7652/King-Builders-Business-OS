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
use Illuminate\Support\Facades\Log;

/**
 * Sets the booking's promoter (M14.2) — one promoter maximum per booking.
 *
 * Rather than editing the row, the current active attribution (if any) is
 * SUPERSEDED (status → superseded, `ended_at` stamped) and a fresh row is
 * written with the next `revision`, so historical attribution — and any
 * commission snapshot taken from it — is never mutated.
 *
 *   - `partnerId === null` clears the promoter, marking the booking a direct sale
 *   - the promoter must be ACTIVE and (by default) authorised for the project
 *   - a CANCELLED booking is rejected; the M6 lifecycle is respected
 */
class SetBookingPartnerAttribution
{
    use RunsInTransaction;

    public function handle(Booking $booking, ?int $partnerId, User $actor, ?string $reason = null): Booking
    {
        if ($booking->isCancelled()) {
            throw new DomainException('A cancelled booking cannot be attributed to a promoter.');
        }

        if ($booking->isConfirmed() && ! config('partners.attribution.allow_edit_after_confirmation')) {
            throw new DomainException('Promoter attribution is locked once the booking is confirmed.');
        }

        return $this->transaction(function () use ($booking, $partnerId, $actor, $reason): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            // A committed commission (approved / paid) locks the attribution —
            // that promoter's money is already an obligation. A pending case is
            // fine; GenerateCommissionCases re-derives it from the new promoter.
            $committed = CommissionCase::query()
                ->where('booking_id', $locked->id)
                ->whereIn('status', [
                    CommissionCaseStatus::Approved->value,
                    CommissionCaseStatus::PartiallyPaid->value,
                    CommissionCaseStatus::Paid->value,
                ])
                ->exists();

            if ($committed) {
                throw new DomainException('This booking has an approved commission — its promoter can no longer be changed.');
            }

            $partner = $partnerId !== null ? $this->resolvePartner($locked, $partnerId) : null;

            $previous = BookingPartnerAttribution::query()
                ->where('booking_id', $locked->id)
                ->where('status', 'active')
                ->first();

            // Clearing an already-direct booking, or re-setting the same promoter, is a no-op.
            if ($partner === null && $previous === null) {
                return $locked->load('partnerAttributions.partner');
            }
            if ($partner !== null && $previous !== null && $previous->partner_id === $partner->id) {
                return $locked->load('partnerAttributions.partner');
            }

            if ($previous !== null) {
                $previous->forceFill(['status' => 'superseded', 'ended_at' => now()])->save();
            }

            $revision = ((int) BookingPartnerAttribution::query()
                ->where('booking_id', $locked->id)->max('revision')) + 1;

            if ($partner !== null) {
                BookingPartnerAttribution::create([
                    'booking_id' => $locked->id,
                    'partner_id' => $partner->id,
                    'status' => 'active',
                    'revision' => $revision,
                    'attributed_by' => $actor->id,
                    'attributed_at' => now(),
                    'reason' => $reason,
                ]);
            }

            $this->recordActivities($locked, $partner, $previous?->partner_id, $actor);

            Log::info('booking.promoter_attribution_set', [
                'booking_id' => $locked->id,
                'revision' => $revision,
                'partner_id' => $partner?->id,
                'by' => $actor->id,
            ]);

            return $locked->load('partnerAttributions.partner');
        });
    }

    private function resolvePartner(Booking $booking, int $partnerId): Partner
    {
        $partner = Partner::find($partnerId);

        if ($partner === null) {
            throw new DomainException('The selected promoter no longer exists.');
        }
        if (! $partner->canReceiveAttribution()) {
            throw new DomainException("{$partner->displayName()} is {$partner->status->label()} and cannot be attributed.");
        }
        if (config('partners.attribution.require_project_authorization') && ! $this->isAuthorised($partner, $booking)) {
            throw new DomainException("{$partner->displayName()} is not authorised for this project.");
        }

        return $partner;
    }

    private function isAuthorised(Partner $partner, Booking $booking): bool
    {
        return PartnerProjectAuthorization::query()
            ->where('partner_id', $partner->id)
            ->where('project_id', $booking->project_id)
            ->where('status', 'active')
            ->exists();
    }

    private function recordActivities(Booking $booking, ?Partner $partner, ?int $previousPartnerId, User $actor): void
    {
        if ($partner !== null) {
            $type = $previousPartnerId !== null
                ? PartnerActivityType::BookingAttributionUpdated
                : PartnerActivityType::BookingAttributed;

            $partner->recordActivity($type, "Booking {$booking->booking_number} attributed.", [
                'booking_id' => $booking->id,
            ], $actor);
        }

        if ($previousPartnerId !== null && $previousPartnerId !== $partner?->id) {
            Partner::find($previousPartnerId)?->recordActivity(
                PartnerActivityType::BookingAttributionRemoved,
                "Booking {$booking->booking_number} attribution removed.",
                ['booking_id' => $booking->id],
                $actor,
            );
        }
    }
}
