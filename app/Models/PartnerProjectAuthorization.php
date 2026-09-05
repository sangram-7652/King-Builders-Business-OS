<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Project-wise partner authorisation (M14). One row per (partner, project);
 * the row is toggled between `active` and `revoked` rather than deleted so the
 * grant / revoke history survives.
 */
class PartnerProjectAuthorization extends Model
{
    protected $fillable = [
        'partner_id', 'project_id', 'status',
        'authorized_at', 'authorized_by',
        'revoked_at', 'revoked_by', 'revoke_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'partner_id' => 'integer',
            'project_id' => 'integer',
            'authorized_by' => 'integer',
            'revoked_by' => 'integer',
            'authorized_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
