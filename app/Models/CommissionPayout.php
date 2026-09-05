<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionPayoutMethod;
use App\Enums\CommissionPayoutStatus;
use Database\Factories\CommissionPayoutFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded commission payout (M14.5) — operational tracking only.
 *
 * @property CommissionPayoutMethod $method
 * @property CommissionPayoutStatus $status
 */
class CommissionPayout extends Model
{
    /** @use HasFactory<CommissionPayoutFactory> */
    use HasFactory;

    protected $fillable = [
        'commission_case_id', 'amount', 'method', 'paid_on', 'reference', 'notes', 'status',
        'recorded_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'commission_case_id' => 'integer',
            'amount' => 'decimal:2',
            'method' => CommissionPayoutMethod::class,
            'paid_on' => 'date',
            'status' => CommissionPayoutStatus::class,
            'recorded_by' => 'integer',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }

    /** @return BelongsTo<CommissionCase, $this> */
    public function commissionCase(): BelongsTo
    {
        return $this->belongsTo(CommissionCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @param  Builder<CommissionPayout>  $query */
    public function scopeRecorded(Builder $query): void
    {
        $query->where('status', CommissionPayoutStatus::Recorded->value);
    }

    public function isVoided(): bool
    {
        return $this->status === CommissionPayoutStatus::Voided;
    }
}
