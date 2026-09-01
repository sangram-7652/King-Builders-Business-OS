<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceCalculationType;
use App\Enums\PriceComponentType;
use App\Models\Masters\ChargeType;
use App\Models\Masters\PlcType;
use App\Models\Masters\TaxRate;
use Database\Factories\BookingPriceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a booking's price breakdown (M6). `amount` is the figure the
 * pricing engine resolved — a snapshot, independent of the master it came from.
 */
class BookingPriceLine extends Model
{
    /** @use HasFactory<BookingPriceLineFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id', 'type', 'name', 'calculation_type',
        'quantity', 'rate', 'amount',
        'plc_type_id', 'charge_type_id', 'tax_rate_id',
        'sort_order', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'type' => PriceComponentType::class,
            'calculation_type' => PriceCalculationType::class,
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'plc_type_id' => 'integer',
            'charge_type_id' => 'integer',
            'tax_rate_id' => 'integer',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<PlcType, $this> */
    public function plcType(): BelongsTo
    {
        return $this->belongsTo(PlcType::class);
    }

    /** @return BelongsTo<ChargeType, $this> */
    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** Signed amount — discounts count against the total. */
    public function signedAmount(): string
    {
        return $this->type->isDeduction() ? '-'.$this->amount : (string) $this->amount;
    }
}
