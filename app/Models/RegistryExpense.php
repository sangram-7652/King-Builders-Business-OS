<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RegistryExpenseType;
use Database\Factories\RegistryExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registry expense tracking (M9). DECIMAL only. Not an accounting ledger; never
 * posted to the M6/M7 booking financials.
 *
 * @property RegistryExpenseType $expense_type
 */
class RegistryExpense extends Model
{
    /** @use HasFactory<RegistryExpenseFactory> */
    use HasFactory;

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'registry_case_id', 'booking_id', 'expense_type', 'amount', 'status',
        'paid_by', 'paid_at', 'reference', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'registry_case_id' => 'integer',
            'booking_id' => 'integer',
            'expense_type' => RegistryExpenseType::class,
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RegistryCase, $this> */
    public function registryCase(): BelongsTo
    {
        return $this->belongsTo(RegistryCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
