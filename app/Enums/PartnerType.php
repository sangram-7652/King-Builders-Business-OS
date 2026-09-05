<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Kind of channel partner / broker (M14).
 *
 * Purely a classification for filtering, reporting and (later) default
 * commission-scheme selection — it never changes how commission is calculated.
 */
enum PartnerType: string
{
    use HasLabel;

    case Individual = 'individual';
    case Firm = 'firm';
    case ChannelPartner = 'channel_partner';
    case Referral = 'referral';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual Broker',
            self::Firm => 'Brokerage Firm',
            self::ChannelPartner => 'Channel Partner',
            self::Referral => 'Referral Associate',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Individual => 'info',
            self::Firm => 'brand',
            self::ChannelPartner => 'success',
            self::Referral => 'muted',
        };
    }

    /** A firm / channel partner carries a company identity; the others are people. */
    public function isOrganisation(): bool
    {
        return in_array($this, [self::Firm, self::ChannelPartner], true);
    }
}
