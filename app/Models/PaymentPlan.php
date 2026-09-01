<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentPlanStatus;
use App\Models\Concerns\GuardsAgainstDestructiveDelete;
use Database\Factories\PaymentPlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property PaymentPlanStatus $status
 */
class PaymentPlan extends Model
{
    /** @use HasFactory<PaymentPlanFactory> */
    use GuardsAgainstDestructiveDelete, HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_id', 'name', 'total_amount', 'status', 'allows_variance',
        'created_by', 'activated_at', 'approved_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'total_amount' => 'decimal:2',
            'status' => PaymentPlanStatus::class,
            'allows_variance' => 'boolean',
            'created_by' => 'integer',
            'activated_at' => 'datetime',
            'approved_by' => 'integer',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return HasMany<Installment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('installment_number');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @param  Builder<PaymentPlan>  $query */
    public function scopeStatus(Builder $query, PaymentPlanStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof PaymentPlanStatus ? $status->value : $status);
        }
    }

    public function isDraft(): bool
    {
        return $this->status === PaymentPlanStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === PaymentPlanStatus::Active;
    }

    public function canTransitionTo(PaymentPlanStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }
}
