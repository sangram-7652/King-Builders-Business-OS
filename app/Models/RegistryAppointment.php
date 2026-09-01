<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RegistryAppointmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One registry appointment (M9). Rescheduling supersedes the row and inserts a
 * new one; the live appointment has `superseded_at` NULL.
 */
class RegistryAppointment extends Model
{
    /** @use HasFactory<RegistryAppointmentFactory> */
    use HasFactory;

    protected $fillable = [
        'registry_case_id', 'scheduled_at', 'registry_office', 'appointment_reference',
        'responsible_user_id', 'notes', 'superseded_at', 'reschedule_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'registry_case_id' => 'integer',
            'scheduled_at' => 'datetime',
            'responsible_user_id' => 'integer',
            'superseded_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<RegistryCase, $this> */
    public function registryCase(): BelongsTo
    {
        return $this->belongsTo(RegistryCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function isLive(): bool
    {
        return $this->superseded_at === null;
    }
}
