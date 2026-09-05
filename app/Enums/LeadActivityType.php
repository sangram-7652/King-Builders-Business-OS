<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The lightweight lead activity/history foundation (M5, extended in M13).
 * Not the full enterprise audit system — just enough to render a timeline.
 */
enum LeadActivityType: string
{
    use HasLabel;

    case Created = 'created';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case Unassigned = 'unassigned';
    case StatusChanged = 'status_changed';
    case FollowUpScheduled = 'follow_up_scheduled';
    case FollowUpCompleted = 'follow_up_completed';
    case FollowUpRescheduled = 'follow_up_rescheduled';
    case FollowUpCancelled = 'follow_up_cancelled';
    case FollowUpMissed = 'follow_up_missed';
    case SiteVisitScheduled = 'site_visit_scheduled';
    case SiteVisitCompleted = 'site_visit_completed';
    case SiteVisitCancelled = 'site_visit_cancelled';
    case CommunicationLogged = 'communication_logged';
    case RequirementUpdated = 'requirement_updated';
    case Scored = 'scored';
    case Merged = 'merged';
    case Reactivated = 'reactivated';
    case AutomationRan = 'automation_ran';
    case PartnerAttributed = 'partner_attributed';
    case PartnerAttributionRemoved = 'partner_attribution_removed';
    case Converted = 'converted';
    case NoteAdded = 'note_added';
    case DuplicateReviewed = 'duplicate_reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Lead created',
            self::Assigned => 'Lead assigned',
            self::Reassigned => 'Lead reassigned',
            self::Unassigned => 'Lead unassigned',
            self::StatusChanged => 'Status changed',
            self::FollowUpScheduled => 'Follow-up scheduled',
            self::FollowUpCompleted => 'Follow-up completed',
            self::FollowUpRescheduled => 'Follow-up rescheduled',
            self::FollowUpCancelled => 'Follow-up cancelled',
            self::FollowUpMissed => 'Follow-up missed',
            self::SiteVisitScheduled => 'Site visit scheduled',
            self::SiteVisitCompleted => 'Site visit completed',
            self::SiteVisitCancelled => 'Site visit cancelled',
            self::CommunicationLogged => 'Communication logged',
            self::RequirementUpdated => 'Requirement updated',
            self::Scored => 'Lead scored',
            self::Merged => 'Lead merged',
            self::Reactivated => 'Lead reactivated',
            self::AutomationRan => 'Automation executed',
            self::PartnerAttributed => 'Channel partner attributed',
            self::PartnerAttributionRemoved => 'Channel partner attribution removed',
            self::Converted => 'Lead converted',
            self::NoteAdded => 'Note added',
            self::DuplicateReviewed => 'Duplicate reviewed',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Created => 'plus',
            self::Assigned, self::Reassigned, self::Unassigned => 'user',
            self::StatusChanged => 'inbox',
            self::FollowUpScheduled, self::FollowUpCompleted, self::FollowUpRescheduled,
            self::FollowUpCancelled, self::FollowUpMissed => 'clock',
            self::SiteVisitScheduled, self::SiteVisitCompleted, self::SiteVisitCancelled => 'building',
            self::CommunicationLogged => 'phone',
            self::RequirementUpdated, self::Scored => 'inbox',
            self::Converted => 'building',
            self::Merged, self::Reactivated => 'inbox',
            self::AutomationRan => 'clock',
            self::PartnerAttributed, self::PartnerAttributionRemoved => 'user',
            self::NoteAdded => 'inbox',
            self::DuplicateReviewed => 'search',
        };
    }
}
