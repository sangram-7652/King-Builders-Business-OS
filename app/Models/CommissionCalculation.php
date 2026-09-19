<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable commission calculation snapshot (M14.4). Append-only — never
 * updated after it is written, so an approved / paid figure stays reproducible.
 *
 * `commission_amount` is the GROSS figure (booking final_amount × promoter
 * commission %); `advance_adjusted_amount` / `payable_amount` record how the
 * promoter's advance ledger split it at the moment this snapshot was taken.
 *
 * @property array<string, mixed> $snapshot
 */
class CommissionCalculation extends Model
{
    /** Append-only with its own `calculated_at`; no created_at / updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'commission_case_id', 'sequence', 'basis_amount',
        'commission_amount', 'advance_adjusted_amount', 'payable_amount',
        'snapshot', 'calculated_at', 'calculated_by',
    ];

    protected function casts(): array
    {
        return [
            'commission_case_id' => 'integer',
            'sequence' => 'integer',
            'basis_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'advance_adjusted_amount' => 'decimal:2',
            'payable_amount' => 'decimal:2',
            'snapshot' => 'array',
            'calculated_at' => 'datetime',
            'calculated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<CommissionCase, $this> */
    public function commissionCase(): BelongsTo
    {
        return $this->belongsTo(CommissionCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }
}
