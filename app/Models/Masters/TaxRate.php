<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\BookingPriceLine;
use Database\Factories\Masters\TaxRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tax rate master (M6) — a named percentage applied to the taxable amount
 * (subtotal − discount). Calculation foundation only; no filing / returns.
 */
class TaxRate extends MasterModel
{
    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'percentage', 'description', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'percentage' => 'decimal:4',
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
