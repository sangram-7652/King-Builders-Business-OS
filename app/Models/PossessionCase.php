<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PossessionCaseStatus;
use Database\Factories\PossessionCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Possession case (M10) — one per booking. POS-000001. Consumes M6/M7/M8/M9
 * state via the eligibility engine; never modifies booking pricing.
 *
 * @property PossessionCaseStatus $status
 */
class PossessionCase extends Model
{
    /** @use HasFactory<PossessionCaseFactory> */
    use HasFactory;

    public const SEQUENCE_KEY = 'possession_case';

    protected $fillable = [
        'case_number', 'booking_id', 'plot_id', 'status',
        'eligibility_snapshot', 'eligibility_checked_at', 'eligible_at',
        'initiated_at', 'initiated_by', 'scheduled_at', 'site_location', 'assigned_to',
        'inspection_at', 'completed_at', 'completed_by',
        'certificate_document_id', 'certificate_generated_at',
        'hold_reason', 'status_before_hold', 'cancellation_reason',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'plot_id' => 'integer',
            'status' => PossessionCaseStatus::class,
            'eligibility_snapshot' => 'array',
            'eligibility_checked_at' => 'datetime',
            'eligible_at' => 'datetime',
            'initiated_at' => 'datetime',
            'initiated_by' => 'integer',
            'scheduled_at' => 'datetime',
            'assigned_to' => 'integer',
            'inspection_at' => 'datetime',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
            'certificate_document_id' => 'integer',
            'certificate_generated_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public static function formatCode(int $number): string
    {
        return 'POS-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Plot, $this> */
    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Document, $this> */
    public function certificateDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'certificate_document_id');
    }

    /** @return HasMany<PossessionClearance, $this> */
    public function clearances(): HasMany
    {
        return $this->hasMany(PossessionClearance::class);
    }

    /** @return HasMany<PossessionAppointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(PossessionAppointment::class)->latest('id');
    }

    /** @return HasOne<PossessionAppointment, $this> */
    public function liveAppointment(): HasOne
    {
        return $this->hasOne(PossessionAppointment::class)->whereNull('superseded_at')->latestOfMany();
    }

    /** @return HasMany<PossessionInspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(PossessionInspection::class)->latest('id');
    }

    /** @return HasOne<PossessionInspection, $this> */
    public function latestInspection(): HasOne
    {
        return $this->hasOne(PossessionInspection::class)->latestOfMany();
    }

    /** @return HasOne<PossessionHandover, $this> */
    public function handover(): HasOne
    {
        return $this->hasOne(PossessionHandover::class);
    }

    // --- Scopes / helpers -----------------------------------------

    /** @param  Builder<PossessionCase>  $query */
    public function scopeStatus(Builder $query, PossessionCaseStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof PossessionCaseStatus ? $status->value : $status);
        }
    }

    public function isCompleted(): bool
    {
        return $this->status === PossessionCaseStatus::Completed;
    }

    public function isOnHold(): bool
    {
        return $this->status === PossessionCaseStatus::OnHold;
    }
}
