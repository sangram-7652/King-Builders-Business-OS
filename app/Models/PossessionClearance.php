<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClearanceCategory;
use App\Enums\ClearanceStatus;
use Database\Factories\PossessionClearanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One possession clearance category (M10). PENDING → CLEARED / REJECTED /
 * WAIVED. Never waived automatically.
 *
 * @property ClearanceCategory $category
 * @property ClearanceStatus $status
 */
class PossessionClearance extends Model
{
    /** @use HasFactory<PossessionClearanceFactory> */
    use HasFactory;

    protected $fillable = [
        'possession_case_id', 'category', 'status', 'required',
        'remarks', 'waiver_reason', 'snapshot', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'possession_case_id' => 'integer',
            'category' => ClearanceCategory::class,
            'status' => ClearanceStatus::class,
            'required' => 'boolean',
            'snapshot' => 'array',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PossessionCase, $this> */
    public function possessionCase(): BelongsTo
    {
        return $this->belongsTo(PossessionCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isSatisfied(): bool
    {
        return $this->status->isSatisfied();
    }
}
