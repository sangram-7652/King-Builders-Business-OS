<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only lead assignment history (M13.1).
 *
 * One row per ownership span. `ended_at IS NULL` marks the current span. Rows
 * are never mutated after creation except to stamp `ended_at` when the span
 * closes — the reassignment action does that in a transaction with the new
 * span's insert. There is intentionally no factory: rows come only from
 * App\Actions\Leads\AssignLead.
 *
 * @property Carbon $assigned_at
 * @property Carbon|null $ended_at
 */
class LeadAssignment extends Model
{
    protected $fillable = ['lead_id', 'assigned_to', 'assigned_by', 'assigned_at', 'ended_at', 'reason'];

    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'assigned_to' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }
}
