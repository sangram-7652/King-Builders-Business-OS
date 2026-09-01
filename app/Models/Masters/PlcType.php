<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Enums\Masters\PlcCalculationType;
use App\Models\BookingPriceLine;
use Database\Factories\Masters\PlcTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlcType extends MasterModel
{
    /** @use HasFactory<PlcTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'calculation_type', 'value', 'description', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'calculation_type' => PlcCalculationType::class,
            'value' => 'decimal:2',
        ]);
    }

    /** Booking price lines that applied this PLC type (M6). @return HasMany<BookingPriceLine, $this> */
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
