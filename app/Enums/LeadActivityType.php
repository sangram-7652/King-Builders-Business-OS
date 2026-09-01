<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The lightweight lead activity/history foundation (M5). Not the full
 * enterprise audit system — just enough to render a timeline.
 */
enum LeadActivityType: string
{
    use HasLabel;

    case Created = 'created';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case StatusChanged = 'status_changed';
    case FollowUpScheduled = 'follow_up_scheduled';
    case FollowUpCompleted = 'follow_up_completed';
    case Converted = 'converted';
    case NoteAdded = 'note_added';
    case DuplicateReviewed = 'duplicate_reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Lead created',
            self::Assigned => 'Lead assigned',
            self::Unassigned => 'Lead unassigned',
            self::StatusChanged => 'Status changed',
            self::FollowUpScheduled => 'Follow-up scheduled',
            self::FollowUpCompleted => 'Follow-up completed',
            self::Converted => 'Lead converted',
            self::NoteAdded => 'Note added',
            self::DuplicateReviewed => 'Duplicate reviewed',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Created => 'plus',
            self::Assigned, self::Unassigned => 'user',
            self::StatusChanged => 'inbox',
            self::FollowUpScheduled, self::FollowUpCompleted => 'clock',
            self::Converted => 'building',
            self::NoteAdded => 'inbox',
            self::DuplicateReviewed => 'search',
        };
    }
}
