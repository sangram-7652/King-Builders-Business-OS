<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AgreementType: string
{
    use HasLabel;

    case BookingAgreement = 'booking_agreement';
    case SaleAgreement = 'sale_agreement';
    case AllotmentLetter = 'allotment_letter';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BookingAgreement => 'Booking agreement',
            self::SaleAgreement => 'Sale agreement',
            self::AllotmentLetter => 'Allotment letter',
            self::Other => 'Other',
        };
    }
}
