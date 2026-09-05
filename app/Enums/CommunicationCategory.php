<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Regulatory classification of a communication (M16). Transactional messages
 * (a receipt, a registry date) are always allowed to the party they concern;
 * marketing messages require an explicit opt-in (consent — M16.5).
 */
enum CommunicationCategory: string
{
    use HasLabel;

    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Transactional => 'Transactional',
            self::Marketing => 'Marketing',
        };
    }

    public function requiresConsent(): bool
    {
        return $this === self::Marketing;
    }
}
