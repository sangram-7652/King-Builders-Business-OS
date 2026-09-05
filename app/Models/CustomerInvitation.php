<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, expiring portal token (M15) — activation (`invite`) or password
 * reset (`reset`). Only the SHA-256 hash is stored; the plaintext appears once,
 * in the link handed to staff.
 */
class CustomerInvitation extends Model
{
    public const PURPOSE_INVITE = 'invite';

    public const PURPOSE_RESET = 'reset';

    protected $fillable = [
        'buyer_id', 'purpose', 'token_hash', 'expires_at', 'used_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'buyer_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<CustomerInvitation>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
