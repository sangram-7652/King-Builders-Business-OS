<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only lead attribution span (M14.2). `ended_at IS NULL` = current;
 * `partner_id IS NULL` = an explicit "direct / no partner" decision.
 */
class LeadPartnerAttribution extends Model
{
    protected $fillable = [
        'lead_id', 'partner_id', 'attributed_by', 'attributed_at', 'ended_at', 'source', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'partner_id' => 'integer',
            'attributed_by' => 'integer',
            'attributed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attributedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attributed_by');
    }

    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }
}
