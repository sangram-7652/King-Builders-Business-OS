<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Model;

/**
 * Which entity a document / requirement applies to. Buyer documents (KYC),
 * booking documents (agreement, registry, …) and — from M14 — channel-partner
 * KYC documents are each managed separately. All three flow through the same
 * private-disk, IDOR-safe M9 document pipeline.
 */
enum DocumentScope: string
{
    use HasLabel;

    case Buyer = 'buyer';
    case Booking = 'booking';
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Booking => 'Booking',
            self::Partner => 'Channel Partner',
        };
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Buyer => Buyer::class,
            self::Booking => Booking::class,
            self::Partner => Partner::class,
        };
    }
}
