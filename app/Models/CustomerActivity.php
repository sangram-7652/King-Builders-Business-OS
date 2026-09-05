<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerActivityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (M15). `updated_at` disabled; rows written only via
 * Buyer::recordPortalActivity(). A customer sees only the curated subset
 * flagged {@see CustomerActivityType::isCustomerVisible()}.
 *
 * @property CustomerActivityType $type
 */
class CustomerActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['buyer_id', 'type', 'description', 'properties', 'ip_address', 'created_at'];

    protected function casts(): array
    {
        return [
            'buyer_id' => 'integer',
            'type' => CustomerActivityType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** Only the events a customer is allowed to see on their own timeline. @param  Builder<CustomerActivity>  $query */
    public function scopeCustomerVisible(Builder $query): void
    {
        $query->whereIn('type', array_map(
            fn (CustomerActivityType $t) => $t->value,
            array_filter(CustomerActivityType::cases(), fn ($t) => $t->isCustomerVisible()),
        ));
    }
}
