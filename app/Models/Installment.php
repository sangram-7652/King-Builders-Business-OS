<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentStatus;
use Database\Factories\InstallmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property InstallmentStatus $status
 */
class Installment extends Model
{
    /** @use HasFactory<InstallmentFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_plan_id', 'installment_number', 'name', 'due_date', 'amount', 'status',
        'waived_at', 'waived_by', 'waiver_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_plan_id' => 'integer',
            'installment_number' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'status' => InstallmentStatus::class,
            'waived_at' => 'datetime',
            'waived_by' => 'integer',
        ];
    }

    /** @return BelongsTo<PaymentPlan, $this> */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** Allocations that currently count — i.e. from SUCCESS payments only. @return HasMany<PaymentAllocation, $this> */
    public function effectiveAllocations(): HasMany
    {
        return $this->allocations()->whereHas('payment', fn ($q) => $q->where('status', 'success'));
    }

    public function isWaived(): bool
    {
        return $this->status === InstallmentStatus::Waived;
    }

    public function label(): string
    {
        return $this->name ?: "Installment {$this->installment_number}";
    }
}
