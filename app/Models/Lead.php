<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Masters\LeadSource;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property LeadStatus $status
 * @property Carbon|null $follow_up_at
 * @property Carbon|null $converted_at
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email',
        'lead_source_id', 'assigned_to',
        'status', 'notes', 'follow_up_at',
        'converted_at', 'converted_by', 'buyer_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'lead_source_id' => 'integer',
            'assigned_to' => 'integer',
            'buyer_id' => 'integer',
            'converted_by' => 'integer',
            'created_by' => 'integer',
            'follow_up_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return HasMany<LeadFollowUp, $this> */
    public function followUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class)->latest('due_at');
    }

    /** @return HasMany<LeadFollowUp, $this> */
    public function pendingFollowUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class)->whereNull('completed_at')->orderBy('due_at');
    }

    /** @return HasMany<LeadActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('id');
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Lead>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /**
     * Restrict a query to the leads a user is allowed to see. `leads.view_all`
     * lifts the restriction; otherwise a user only sees leads they own or are
     * assigned to.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('leads.view_all')) {
            return;
        }

        $query->where(function (Builder $query) use ($user): void {
            $query->where('assigned_to', $user->id)->orWhere('created_by', $user->id);
        });
    }

    // --- Helpers ---------------------------------------------------

    public function canTransitionTo(LeadStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function isConverted(): bool
    {
        return $this->status === LeadStatus::Converted;
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->can('leads.view_all')
            || $this->assigned_to === $user->id
            || $this->created_by === $user->id;
    }

    /**
     * Append one row to the activity timeline.
     *
     * @param  array<string, scalar|null>  $properties  never PII
     */
    public function recordActivity(LeadActivityType $type, string $description, array $properties = [], ?User $causer = null): LeadActivity
    {
        return $this->activities()->create([
            'type' => $type,
            'description' => $description,
            'properties' => $properties ?: null,
            'causer_id' => ($causer ?? auth()->user())?->id,
            'created_at' => now(),
        ]);
    }

    public function recomputeFollowUpAt(): void
    {
        $this->forceFill([
            'follow_up_at' => $this->pendingFollowUps()->min('due_at'),
        ])->save();
    }
}
