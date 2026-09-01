<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FollowUpOutcome;
use Database\Factories\LeadFollowUpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $due_at
 * @property Carbon|null $completed_at
 */
class LeadFollowUp extends Model
{
    /** @use HasFactory<LeadFollowUpFactory> */
    use HasFactory;

    protected $fillable = ['lead_id', 'due_at', 'note', 'outcome', 'completed_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'created_by' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'outcome' => FollowUpOutcome::class,
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isCompleted() && $this->due_at->isPast();
    }
}
