<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment's money applied to one installment (M7). Append-only — never
 * deleted; a reversed payment's allocations simply stop counting.
 */
class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    protected $fillable = ['payment_id', 'installment_id', 'amount', 'allocated_by', 'is_auto'];

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'installment_id' => 'integer',
            'amount' => 'decimal:2',
            'allocated_by' => 'integer',
            'is_auto' => 'boolean',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Installment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
