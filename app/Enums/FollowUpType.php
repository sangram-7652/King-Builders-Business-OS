<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * What kind of interaction a follow-up represents (M13.1).
 *
 * Purely descriptive — recording a WHATSAPP / EMAIL follow-up does NOT send
 * anything (external messaging is explicitly out of scope for M13). The
 * communication log (M13.2) is where sent/received messages are recorded.
 */
enum FollowUpType: string
{
    use HasLabel;

    case Call = 'call';
    case Meeting = 'meeting';
    case SiteVisit = 'site_visit';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Note = 'note';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Meeting => 'Meeting',
            self::SiteVisit => 'Site visit',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Note => 'Note',
            self::Other => 'Other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Call => 'phone',
            self::Meeting => 'user',
            self::SiteVisit => 'building',
            self::WhatsApp, self::Email => 'inbox',
            self::Note => 'inbox',
            self::Other => 'clock',
        };
    }
}
