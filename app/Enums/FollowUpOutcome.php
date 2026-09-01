<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum FollowUpOutcome: string
{
    use HasLabel;

    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case WrongNumber = 'wrong_number';
    case NotInterested = 'not_interested';
    case CallbackRequested = 'callback_requested';
    case MeetingScheduled = 'meeting_scheduled';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NoAnswer => 'No answer',
            self::Busy => 'Busy',
            self::WrongNumber => 'Wrong number',
            self::NotInterested => 'Not interested',
            self::CallbackRequested => 'Callback requested',
            self::MeetingScheduled => 'Meeting scheduled',
            self::Other => 'Other',
        };
    }
}
