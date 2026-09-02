<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\Masters\AreaUnit;
use App\Enums\PlotFacing;
use App\Enums\PlotStatus;
use App\Models\Concerns\GuardsAgainstDestructiveDelete;
use App\Models\Masters\PlotCategory;
use App\Models\Masters\PlotDimension;
use App\Models\Masters\PlotSize;
use Database\Factories\PlotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property PlotStatus $status
 * @property bool $is_active
 * @property Carbon|null $hold_expires_at
 */
class Plot extends Model
{
    /** @use HasFactory<PlotFactory> */
    use GuardsAgainstDestructiveDelete, HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id', 'block_id', 'plot_number',
        'plot_category_id', 'plot_size_id', 'plot_dimension_id',
        'area', 'area_unit', 'facing',
        'status', 'is_active',
        'held_at', 'hold_expires_at', 'hold_reason', 'held_by',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'block_id' => 'integer',
            'plot_category_id' => 'integer',
            'plot_size_id' => 'integer',
            'plot_dimension_id' => 'integer',
            'area' => 'decimal:2',
            'area_unit' => AreaUnit::class,
            'facing' => PlotFacing::class,
            'status' => PlotStatus::class,
            'is_active' => 'boolean',
            'held_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'held_by' => 'integer',
        ];
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Block, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    /** @return BelongsTo<PlotCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PlotCategory::class, 'plot_category_id');
    }

    /** @return BelongsTo<PlotSize, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(PlotSize::class, 'plot_size_id');
    }

    /** @return BelongsTo<PlotDimension, $this> */
    public function dimension(): BelongsTo
    {
        return $this->belongsTo(PlotDimension::class, 'plot_dimension_id');
    }

    /** @return BelongsTo<User, $this> */
    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    /** All bookings ever raised against this plot (M6). @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * The single live booking reserving this plot, if any (PENDING / CONFIRMED).
     *
     * @return HasOne<Booking, $this>
     */
    public function activeBooking(): HasOne
    {
        return $this->hasOne(Booking::class)
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->latestOfMany();
    }

    /** Possession case for this plot's booking (M10). @return HasOne<\App\Models\PossessionCase, $this> */
    public function possessionCase(): HasOne
    {
        return $this->hasOne(PossessionCase::class);
    }

    /** @return HasMany<TransferRequest, $this> */
    public function transferRequests(): HasMany
    {
        return $this->hasMany(TransferRequest::class)->latest('id');
    }

    /** Full ownership ledger, newest first (M10). @return HasMany<\App\Models\PlotOwnershipHistory, $this> */
    public function ownershipHistory(): HasMany
    {
        return $this->hasMany(PlotOwnershipHistory::class)->orderByDesc('started_at')->orderByDesc('id');
    }

    /** The current owner period(s) — `ended_at` NULL. @return HasMany<\App\Models\PlotOwnershipHistory, $this> */
    public function currentOwnerships(): HasMany
    {
        return $this->hasMany(PlotOwnershipHistory::class)->whereNull('ended_at');
    }

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>>
     */
    protected function businessDependents(): array
    {
        return ['bookings' => $this->bookings()];
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Plot>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term !== '') {
            $query->where('plot_number', 'like', "%{$term}%");
        }
    }

    /** @param  Builder<Plot>  $query */
    public function scopeStatus(Builder $query, PlotStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof PlotStatus ? $status->value : $status);
        }
    }

    /** Plots whose hold has lapsed and should return to AVAILABLE. */
    public function scopeHoldExpired(Builder $query): void
    {
        $query->where('status', PlotStatus::Hold->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now());
    }

    // --- Helpers ---------------------------------------------------

    public function canTransitionTo(PlotStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function isOnHold(): bool
    {
        return $this->status === PlotStatus::Hold;
    }

    public function isHoldExpired(): bool
    {
        return $this->isOnHold()
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isPast();
    }

    public function areaLabel(): string
    {
        $unit = $this->area_unit instanceof AreaUnit ? $this->area_unit->abbreviation() : (string) $this->area_unit;

        return rtrim(rtrim(number_format((float) $this->area, 2), '0'), '.').' '.$unit;
    }

    /**
     * Hold fields to clear when a plot leaves the HOLD state.
     *
     * @return array<string, null>
     */
    public static function clearedHoldAttributes(): array
    {
        return [
            'held_at' => null,
            'hold_expires_at' => null,
            'hold_reason' => null,
            'held_by' => null,
        ];
    }
}
