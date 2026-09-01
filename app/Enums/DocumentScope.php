<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;
use App\Models\Booking;
use App\Models\Buyer;
use Illuminate\Database\Eloquent\Model;

/**
 * Which entity a document / requirement applies to (M9). Buyer documents (KYC)
 * and booking documents (agreement, registry, …) are managed separately.
 */
enum DocumentScope: string
{
    use HasLabel;

    case Buyer = 'buyer';
    case Booking = 'booking';

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Booking => 'Booking',
        };
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Buyer => Buyer::class,
            self::Booking => Booking::class,
        };
    }
}
