<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Enums\PriceCalculationType;
use App\Models\BookingPriceLine;
use Database\Factories\Masters\ChargeTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Other charges" master (M6) — development, maintenance, legal, … The booking
 * pricing engine reads `calculation_type` + `value` to resolve an amount.
 */
class ChargeType extends MasterModel
{
    /** @use HasFactory<ChargeTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'calculation_type', 'value', 'description', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'calculation_type' => PriceCalculationType::class,
            'value' => 'decimal:4',
        ]);
    }

    /** @return HasMany<BookingPriceLine, $this> */
    public function priceLines(): HasMany
    {
        return $this->hasMany(BookingPriceLine::class);
    }

    /** @return list<string> */
    public function referencingRelations(): array
    {
        return ['priceLines'];
    }
}
