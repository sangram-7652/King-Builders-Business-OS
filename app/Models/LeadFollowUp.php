<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FollowUpOutcome;
use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use Database\Factories\LeadFollowUpFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A follow-up task on a lead (M5, promoted to a real task in M13.1).
 *
 * `status` is authoritative — `completed_at` / `cancelled_at` are the
 * timestamps that back it. A PENDING follow-up only becomes MISSED via the
 * MarkMissedFollowUpsJob sweep, never implicitly on read
 * ({@see self::isOverdue()} is the "should be missed" predicate the sweep uses).
 *
 * @property Carbon $due_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property FollowUpType $type
 * @property FollowUpPriority $priority
 * @property FollowUpStatus $status
 */
class LeadFollowUp extends Model
{
    /** @use HasFactory<LeadFollowUpFactory> */
    use HasFactory;

    protected $fillable = [
        'lead_id', 'title', 'type', 'priority', 'status', 'assigned_to',
        'rescheduled_from_id', 'due_at', 'note', 'outcome',
        'completed_at', 'completed_by', 'cancelled_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'assigned_to' => 'integer',
            'rescheduled_from_id' => 'integer',
            'created_by' => 'integer',
            'completed_by' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'type' => FollowUpType::class,
            'priority' => FollowUpPriority::class,
            'status' => FollowUpStatus::class,
            'outcome' => FollowUpOutcome::class,
        ];
    }

    // --- Relationships -------------------------------------------------

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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return BelongsTo<LeadFollowUp, $this> */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(LeadFollowUp::class, 'rescheduled_from_id');
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<LeadFollowUp>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', FollowUpStatus::Pending->value);
    }

    /** Pending and past its due time. @param  Builder<LeadFollowUp>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->pending()->where('due_at', '<', now());
    }

    /** Pending and due today. @param  Builder<LeadFollowUp>  $query */
    public function scopeDueToday(Builder $query): void
    {
        $query->pending()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    /** Pending and due after today. @param  Builder<LeadFollowUp>  $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->pending()->where('due_at', '>', now()->endOfDay());
    }

    /** @param  Builder<LeadFollowUp>  $query */
    public function scopeMissed(Builder $query): void
    {
        $query->where('status', FollowUpStatus::Missed->value);
    }

    /**
     * The follow-ups a user may see: theirs (assigned or created) unless they
     * hold `leads.view_all`.
     *
     * @param  Builder<LeadFollowUp>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('leads.view_all')) {
            return;
        }

        $query->where(function (Builder $query) use ($user): void {
            $query->where('assigned_to', $user->id)
                ->orWhere('created_by', $user->id)
                ->orWhereHas('lead', fn (Builder $q) => $q->visibleTo($user));
        });
    }

    // --- Helpers ---------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status === FollowUpStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === FollowUpStatus::Completed;
    }

    /** PENDING and past due — the condition the missed-sweep acts on. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at->isPast();
    }
}
