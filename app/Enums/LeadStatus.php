<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle state of a Lead.
 *
 * The transition map is the single source of truth. CONVERTED is a *controlled*
 * state: it is never a valid target of the generic ChangeLeadStatus action and
 * can only be reached through ConvertLeadToBuyer.
 *
 * M13.1 extends the M5 set with the full sales pipeline
 * (site-visit → negotiation → booking-pending) and three extra negative
 * outcomes (NOT_INTERESTED / INVALID / DUPLICATE). The original M5 states
 * (INTERESTED, FOLLOW_UP) are kept as flexible intermediate states — extending,
 * not replacing.
 */
enum LeadStatus: string
{
    use HasLabel;

    // --- Positive pipeline ------------------------------------------------
    case New = 'new';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case FollowUp = 'follow_up';
    case Qualified = 'qualified';
    case SiteVisitPlanned = 'site_visit_planned';
    case SiteVisitDone = 'site_visit_done';
    case Negotiation = 'negotiation';
    case BookingPending = 'booking_pending';
    case Converted = 'converted';

    // --- Negative outcomes ---------------------------------------------
    case Lost = 'lost';
    case NotInterested = 'not_interested';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::FollowUp => 'Follow-up',
            self::Qualified => 'Qualified',
            self::SiteVisitPlanned => 'Site visit planned',
            self::SiteVisitDone => 'Site visit done',
            self::Negotiation => 'Negotiation',
            self::BookingPending => 'Booking pending',
            self::Converted => 'Converted',
            self::Lost => 'Lost',
            self::NotInterested => 'Not interested',
            self::Invalid => 'Invalid',
            self::Duplicate => 'Duplicate',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Contacted, self::Interested => 'brand',
            self::FollowUp => 'warning',
            self::Qualified, self::SiteVisitPlanned, self::SiteVisitDone,
            self::Negotiation, self::BookingPending => 'success',
            self::Converted => 'success',
            self::Lost, self::NotInterested, self::Invalid, self::Duplicate => 'danger',
        };
    }

    /**
     * The four ways a lead can leave the pipeline without converting.
     *
     * @return list<self>
     */
    public static function negativeOutcomes(): array
    {
        return [self::Lost, self::NotInterested, self::Invalid, self::Duplicate];
    }

    /**
     * Generic transitions available from the lead detail screen. Every open
     * state can also drop to any negative outcome (added below), and CONVERTED
     * is deliberately never a target here.
     *
     * @return array<value-of<self>, list<self>>
     */
    public static function transitionMap(): array
    {
        $forward = [
            self::New->value => [self::Contacted],
            self::Contacted->value => [self::Interested, self::FollowUp, self::Qualified],
            self::Interested->value => [self::FollowUp, self::Qualified, self::Contacted],
            self::FollowUp->value => [self::Contacted, self::Interested, self::Qualified],
            self::Qualified->value => [self::FollowUp, self::SiteVisitPlanned, self::Negotiation, self::BookingPending],
            self::SiteVisitPlanned->value => [self::SiteVisitDone, self::Qualified, self::FollowUp],
            self::SiteVisitDone->value => [self::Negotiation, self::BookingPending, self::Qualified, self::FollowUp],
            self::Negotiation->value => [self::BookingPending, self::SiteVisitDone, self::Qualified, self::FollowUp],
            self::BookingPending->value => [self::Negotiation, self::Qualified],
            self::Converted->value => [],
            self::Lost->value => [self::New, self::Contacted],
            self::NotInterested->value => [self::New, self::Contacted],
            self::Invalid->value => [self::New],
            self::Duplicate->value => [self::New],
        ];

        // Every non-terminal positive state may drop to any negative outcome.
        foreach ($forward as $from => $targets) {
            $status = self::from($from);
            if ($status !== self::Converted && ! in_array($status, self::negativeOutcomes(), true)) {
                $forward[$from] = array_values(array_unique([...$targets, ...self::negativeOutcomes()], SORT_REGULAR));
            }
        }

        return $forward;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return self::transitionMap()[$this->value];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isConverted(): bool
    {
        return $this === self::Converted;
    }

    public function isNegative(): bool
    {
        return in_array($this, self::negativeOutcomes(), true);
    }

    /**
     * Conversion is allowed once a lead has reached QUALIFIED or the later
     * BOOKING_PENDING state.
     */
    public function canBeConverted(): bool
    {
        return in_array($this, [self::Qualified, self::BookingPending], true);
    }

    public function isOpen(): bool
    {
        return $this !== self::Converted && ! $this->isNegative();
    }
}
