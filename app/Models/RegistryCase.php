<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RegistryCaseStatus;
use Database\Factories\RegistryCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Registry case (M9) — one per booking. REG-000001. Consumes M6/M7/M8 state via
 * the eligibility engine; never modifies booking pricing.
 *
 * @property RegistryCaseStatus $status
 */
class RegistryCase extends Model
{
    /** @use HasFactory<RegistryCaseFactory> */
    use HasFactory;

    public const SEQUENCE_KEY = 'registry_case';

    protected $fillable = [
        'case_number', 'booking_id', 'status', 'eligibility_snapshot', 'eligibility_checked_at',
        'initiated_at', 'initiated_by', 'scheduled_at', 'registry_office', 'appointment_reference',
        'appointment_owner_id', 'completed_at', 'completed_by', 'registered_document_number',
        'registration_date', 'hold_reason', 'status_before_hold', 'cancellation_reason',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'status' => RegistryCaseStatus::class,
            'eligibility_snapshot' => 'array',
            'eligibility_checked_at' => 'datetime',
            'initiated_at' => 'datetime',
            'initiated_by' => 'integer',
            'scheduled_at' => 'datetime',
            'appointment_owner_id' => 'integer',
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
            'registration_date' => 'date',
            'created_by' => 'integer',
        ];
    }

    public static function formatCode(int $number): string
    {
        return 'REG-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return HasMany<RegistryAppointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(RegistryAppointment::class)->latest('id');
    }

    /** @return HasOne<RegistryAppointment, $this> */
    public function liveAppointment(): HasOne
    {
        return $this->hasOne(RegistryAppointment::class)->whereNull('superseded_at')->latestOfMany();
    }

    /** @return HasMany<RegistryExpense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(RegistryExpense::class)->latest('id');
    }

    /** @return HasOne<DocumentHandover, $this> */
    public function handover(): HasOne
    {
        return $this->hasOne(DocumentHandover::class);
    }

    // --- Scopes / helpers -----------------------------------------

    /** @param  Builder<RegistryCase>  $query */
    public function scopeStatus(Builder $query, RegistryCaseStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof RegistryCaseStatus ? $status->value : $status);
        }
    }

    public function isCompleted(): bool
    {
        return $this->status === RegistryCaseStatus::Completed;
    }

    public function isOnHold(): bool
    {
        return $this->status === RegistryCaseStatus::OnHold;
    }
}
