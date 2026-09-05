<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * A buyer's access to the self-service customer portal (M15).
 *
 *   NONE     — never invited
 *   INVITED  — an activation token is outstanding; cannot sign in yet
 *   ACTIVE   — has set a password and can sign in
 *   SUSPENDED — access revoked by staff; historical data untouched
 *
 * Only ACTIVE may authenticate. A customer is a different model on a different
 * auth guard from a staff user — it can never hold a staff role or permission.
 */
enum CustomerPortalStatus: string
{
    use HasLabel;

    case None = 'none';
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not invited',
            self::Invited => 'Invited',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'muted',
            self::Invited => 'warning',
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
