<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CollectionReminderType;
use Database\Factories\CollectionReminderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal, in-app collection reminder (M8). No external channels.
 *
 * @property CollectionReminderType $type
 */
class CollectionReminder extends Model
{
    /** @use HasFactory<CollectionReminderFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SEEN = 'seen';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'booking_id', 'collection_case_id', 'type', 'reference_type', 'reference_id',
        'remind_on', 'message', 'status', 'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'collection_case_id' => 'integer',
            'reference_id' => 'integer',
            'type' => CollectionReminderType::class,
            'remind_on' => 'date',
            'seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<CollectionCase, $this> */
    public function collectionCase(): BelongsTo
    {
        return $this->belongsTo(CollectionCase::class);
    }

    /** @param  Builder<CollectionReminder>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /** @param  Builder<CollectionReminder>  $query */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)->whereDate('remind_on', '<=', now());
    }
}
