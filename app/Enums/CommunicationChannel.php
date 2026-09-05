<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * A delivery channel for an outbound communication (M16). Provider-agnostic —
 * which concrete provider serves a channel is a config choice, never hard-coded
 * into domain logic.
 */
enum CommunicationChannel: string
{
    use HasLabel;

    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Email => 'inbox',
            self::Sms => 'phone',
            self::WhatsApp => 'phone',
        };
    }

    /** Whether a subject line applies to this channel. */
    public function usesSubject(): bool
    {
        return $this === self::Email;
    }

    /** The recipient address kind — an email address or an E.164 phone number. */
    public function addressKind(): string
    {
        return $this === self::Email ? 'email' : 'phone';
    }
}
