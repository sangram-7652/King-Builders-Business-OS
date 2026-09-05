<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Concerns\HasMarketingConsent;
use App\Models\Masters\LeadSource;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property LeadStatus $status
 * @property Carbon|null $follow_up_at
 * @property Carbon|null $next_action_at
 * @property Carbon|null $first_contacted_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $converted_at
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, HasMarketingConsent, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email',
        'lead_source_id', 'assigned_to', 'partner_id',
        'status', 'notes', 'follow_up_at',
        'first_contacted_at', 'last_activity_at', 'next_action', 'next_action_at',
        'converted_at', 'converted_by', 'buyer_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'lead_source_id' => 'integer',
            'assigned_to' => 'integer',
            'partner_id' => 'integer',
            'buyer_id' => 'integer',
            'converted_by' => 'integer',
            'created_by' => 'integer',
            'follow_up_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'next_action_at' => 'datetime',
            'converted_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'marketing_opt_out_at' => 'datetime',
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

    /** The currently attributed channel partner (M14.2), if any. @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return HasMany<LeadPartnerAttribution, $this> */
    public function partnerAttributions(): HasMany
    {
        return $this->hasMany(LeadPartnerAttribution::class)->latest('attributed_at');
    }

    /** The open attribution span (may have a null partner = "direct"). @return HasOne<LeadPartnerAttribution, $this> */
    public function currentPartnerAttribution(): HasOne
    {
        return $this->hasOne(LeadPartnerAttribution::class)->whereNull('ended_at')->latestOfMany('attributed_at');
    }

    /** @return HasMany<LeadFollowUp, $this> */
    public function followUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class)->latest('due_at');
    }

    /** @return HasMany<LeadFollowUp, $this> */
    public function pendingFollowUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class)->pending()->orderBy('due_at');
    }

    /** @return HasMany<LeadAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class)->latest('assigned_at');
    }

    /** The open assignment span (null when the lead is unassigned). @return HasOne<LeadAssignment, $this> */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(LeadAssignment::class)->whereNull('ended_at')->latestOfMany('assigned_at');
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

    /** @param  Builder<Lead>  $query */
    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('assigned_to');
    }

    /** Neither converted nor a negative outcome. @param  Builder<Lead>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [
            LeadStatus::Converted->value,
            ...array_map(fn ($s) => $s->value, LeadStatus::negativeOutcomes()),
        ]);
    }

    /** Open leads with no activity for `$days` days. @param  Builder<Lead>  $query */
    public function scopeStale(Builder $query, int $days): void
    {
        $query->open()->where(function (Builder $q) use ($days): void {
            $q->whereNull('last_activity_at')
                ->orWhere('last_activity_at', '<', now()->subDays($days));
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
     * Append one row to the activity timeline and bump `last_activity_at`.
     *
     * @param  array<string, scalar|null>  $properties  never PII
     */
    public function recordActivity(LeadActivityType $type, string $description, array $properties = [], ?User $causer = null): LeadActivity
    {
        $activity = $this->activities()->create([
            'type' => $type,
            'description' => $description,
            'properties' => $properties ?: null,
            'causer_id' => ($causer ?? auth()->user())?->id,
            'created_at' => now(),
        ]);

        $this->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $activity;
    }

    /**
     * Stamp `first_contacted_at` the first time the lead is actually worked
     * (leaves NEW, a follow-up is completed, or a communication is logged).
     * The anchor for the M13.5 response-time report — set once, never moved.
     */
    public function markFirstContact(?Carbon $when = null): void
    {
        if ($this->first_contacted_at === null) {
            $this->forceFill(['first_contacted_at' => $when ?? now()])->save();
        }
    }

    /**
     * Denormalise the next action from the earliest PENDING follow-up so lists
     * can sort / filter without a join. Keeps the M5 `follow_up_at` column in
     * step for backward compatibility.
     */
    public function syncNextAction(): void
    {
        /** @var LeadFollowUp|null $next */
        $next = $this->pendingFollowUps()->first();

        $this->forceFill([
            'follow_up_at' => $next?->due_at,
            'next_action_at' => $next?->due_at,
            'next_action' => $next === null
                ? null
                : trim($next->type->label().($next->title ? ' — '.$next->title : '')),
        ])->save();
    }
}
