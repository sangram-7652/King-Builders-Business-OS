<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\GuardsAgainstDestructiveDelete;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property BookingStatus $status
 * @property bool $price_overridden
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use GuardsAgainstDestructiveDelete, HasFactory, SoftDeletes;

    public const SEQUENCE_KEY = 'booking';

    protected $fillable = [
        'booking_number',
        'project_id', 'block_id', 'plot_id',
        'booking_date', 'status',
        'base_area', 'base_rate', 'base_amount',
        'plc_amount', 'charge_amount', 'subtotal',
        'discount_amount', 'tax_amount', 'final_amount', 'vikray_muly_amount',
        'pricing_snapshot', 'notes',
        'created_by', 'confirmed_at', 'confirmed_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'price_overridden', 'price_override_by', 'price_override_at', 'price_override_reason',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'block_id' => 'integer',
            'plot_id' => 'integer',
            'booking_date' => 'date',
            'status' => BookingStatus::class,
            'base_area' => 'decimal:4',
            'base_rate' => 'decimal:4',
            'base_amount' => 'decimal:2',
            'plc_amount' => 'decimal:2',
            'charge_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'vikray_muly_amount' => 'decimal:2',
            'pricing_snapshot' => 'array',
            'created_by' => 'integer',
            'confirmed_at' => 'datetime',
            'confirmed_by' => 'integer',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
            'price_overridden' => 'boolean',
            'price_override_by' => 'integer',
            'price_override_at' => 'datetime',
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

    /** @return BelongsTo<Plot, $this> */
    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** @return BelongsTo<User, $this> */
    public function priceOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_override_by');
    }

    /** @return HasMany<BookingBuyer, $this> */
    public function bookingBuyers(): HasMany
    {
        return $this->hasMany(BookingBuyer::class);
    }

    /** @return BelongsToMany<Buyer, $this> */
    public function buyers(): BelongsToMany
    {
        return $this->belongsToMany(Buyer::class, 'booking_buyers')
            ->withPivot(['ownership_percentage', 'is_primary'])
            ->withTimestamps();
    }

    /** @return HasOne<BookingBuyer, $this> */
    public function primaryBookingBuyer(): HasOne
    {
        return $this->hasOne(BookingBuyer::class)->where('is_primary', true);
    }

    /** @return HasMany<BookingPriceLine, $this> */
    public function priceLines(): HasMany
    {
        return $this->hasMany(BookingPriceLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The booking's current active promoter attribution row, if any (one promoter maximum per booking). @return HasMany<BookingPartnerAttribution, $this> */
    public function partnerAttributions(): HasMany
    {
        return $this->hasMany(BookingPartnerAttribution::class)->where('status', 'active')->orderBy('id');
    }

    /** Same as {@see partnerAttributions()} but as a single row — the natural accessor now that a booking has at most one promoter. @return HasOne<BookingPartnerAttribution, $this> */
    public function promoterAttribution(): HasOne
    {
        return $this->hasOne(BookingPartnerAttribution::class)->where('status', 'active');
    }

    /** Every attribution row ever written for this booking (promoter history), newest first. @return HasMany<BookingPartnerAttribution, $this> */
    public function partnerAttributionHistory(): HasMany
    {
        return $this->hasMany(BookingPartnerAttribution::class)->orderByDesc('revision')->orderBy('id');
    }

    /** Commission cases for this booking (M14.4). @return HasMany<\App\Models\CommissionCase, $this> */
    public function commissionCases(): HasMany
    {
        return $this->hasMany(CommissionCase::class)->latest('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Witness 1 / Witness 2 for the Plot KYC Receipt (registry KYC). @return HasMany<BookingWitness, $this> */
    public function witnesses(): HasMany
    {
        return $this->hasMany(BookingWitness::class)->orderBy('witness_number');
    }

    /** Booking-level documents (M9). @return \Illuminate\Database\Eloquent\Relations\MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->latest('id');
    }

    /** The current agreement (M9). @return HasOne<Agreement, $this> */
    public function agreement(): HasOne
    {
        return $this->hasOne(Agreement::class)->latestOfMany();
    }

    /** @return HasMany<Agreement, $this> */
    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class)->latest('id');
    }

    /** @return HasOne<RegistryCase, $this> */
    public function registryCase(): HasOne
    {
        return $this->hasOne(RegistryCase::class);
    }

    /** @return HasOne<DocumentHandover, $this> */
    public function documentHandover(): HasOne
    {
        return $this->hasOne(DocumentHandover::class);
    }

    /** Possession case (M10). @return HasOne<\App\Models\PossessionCase, $this> */
    public function possessionCase(): HasOne
    {
        return $this->hasOne(PossessionCase::class);
    }

    /** @return HasMany<TransferRequest, $this> */
    public function transferRequests(): HasMany
    {
        return $this->hasMany(TransferRequest::class)->latest('id');
    }

    /** Ownership ledger for this booking's plot (M10). @return HasMany<\App\Models\PlotOwnershipHistory, $this> */
    public function ownershipHistory(): HasMany
    {
        return $this->hasMany(PlotOwnershipHistory::class)->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>>
     */
    protected function businessDependents(): array
    {
        return ['payments' => $this->payments()];
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Booking>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term !== '') {
            $query->where('booking_number', 'like', "%{$term}%");
        }
    }

    /** @param  Builder<Booking>  $query */
    public function scopeStatus(Builder $query, BookingStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof BookingStatus ? $status->value : $status);
        }
    }

    // --- Helpers ---------------------------------------------------

    public static function formatCode(int $number): string
    {
        return 'BK-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public function canTransitionTo(BookingStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function isDraft(): bool
    {
        return $this->status === BookingStatus::Draft;
    }

    public function isPending(): bool
    {
        return $this->status === BookingStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    public function isCancelled(): bool
    {
        return $this->status === BookingStatus::Cancelled;
    }

    /** Editable (pricing + buyers) only before confirmation. */
    public function isEditable(): bool
    {
        return in_array($this->status, [BookingStatus::Draft, BookingStatus::Pending], true);
    }
}
