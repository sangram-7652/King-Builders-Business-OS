<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PossessionAppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One possession appointment (M10). Rescheduling supersedes the row and inserts
 * a new one; the live appointment has `superseded_at` NULL.
 */
class PossessionAppointment extends Model
{
    /** @use HasFactory<PossessionAppointmentFactory> */
    use HasFactory;

    protected $fillable = [
        'possession_case_id', 'scheduled_at', 'site_location', 'assigned_to',
        'notes', 'superseded_at', 'reschedule_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'possession_case_id' => 'integer',
            'scheduled_at' => 'datetime',
            'assigned_to' => 'integer',
            'superseded_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<PossessionCase, $this> */
    public function possessionCase(): BelongsTo
    {
        return $this->belongsTo(PossessionCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isLive(): bool
    {
        return $this->superseded_at === null;
    }
}
