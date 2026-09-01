<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CollectionActivityType;
use App\Enums\CollectionCaseStatus;
use App\Enums\CollectionPriority;
use Database\Factories\CollectionCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The collection unit of work for a booking (M8). Carries NO money — every
 * balance is read live from the M7 ledger.
 *
 * @property CollectionCaseStatus $status
 * @property CollectionPriority $priority
 */
class CollectionCase extends Model
{
    /** @use HasFactory<CollectionCaseFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id', 'assigned_to', 'status', 'priority',
        'opened_at', 'opened_by', 'last_follow_up_at', 'next_follow_up_at', 'resolved_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'assigned_to' => 'integer',
            'opened_by' => 'integer',
            'status' => CollectionCaseStatus::class,
            'priority' => CollectionPriority::class,
            'opened_at' => 'datetime',
            'last_follow_up_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return HasMany<CollectionFollowUp, $this> */
    public function followUps(): HasMany
    {
        return $this->hasMany(CollectionFollowUp::class)->latest('follow_up_at');
    }

    /** @return HasMany<CollectionFollowUp, $this> */
    public function pendingFollowUps(): HasMany
    {
        return $this->hasMany(CollectionFollowUp::class)->whereNull('completed_at')->orderBy('follow_up_at');
    }

    /** @return HasMany<PaymentPromise, $this> */
    public function promises(): HasMany
    {
        return $this->hasMany(PaymentPromise::class)->latest('id');
    }

    /** @return HasMany<CollectionActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(CollectionActivity::class)->latest('id');
    }

    /** @return HasMany<CollectionReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(CollectionReminder::class);
    }

    /** @return HasMany<ChequeBounce, $this> */
    public function chequeBounces(): HasMany
    {
        return $this->hasMany(ChequeBounce::class);
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<CollectionCase>  $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('collections.view_all')) {
            return;
        }

        $query->where('assigned_to', $user->id);
    }

    /** @param  Builder<CollectionCase>  $query */
    public function scopeStatus(Builder $query, CollectionCaseStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof CollectionCaseStatus ? $status->value : $status);
        }
    }

    /** @param  Builder<CollectionCase>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '!=', CollectionCaseStatus::Resolved->value);
    }

    // --- Helpers ---------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isResolved(): bool
    {
        return $this->status === CollectionCaseStatus::Resolved;
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('collections.view_all') || $this->assigned_to === $user->id;
    }

    /**
     * Append one row to the collection timeline.
     *
     * @param  array<string, scalar|null>  $properties  never PII
     */
    public function recordActivity(CollectionActivityType $type, string $description, array $properties = [], ?User $causer = null): CollectionActivity
    {
        return $this->activities()->create([
            'booking_id' => $this->booking_id,
            'type' => $type,
            'description' => $description,
            'properties' => $properties ?: null,
            'causer_id' => ($causer ?? auth()->user())?->id,
            'created_at' => now(),
        ]);
    }
}
