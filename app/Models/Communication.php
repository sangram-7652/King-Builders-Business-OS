<?php

declare(strict_types=1);

namespace App\Models;

use App\Communication\OutboundMessage;
use App\Enums\CommunicationCategory;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use Database\Factories\CommunicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One outbound communication (M16). `body` / `context` are a frozen snapshot —
 * status changes update timestamps and `status`, never the content.
 *
 * @property CommunicationChannel $channel
 * @property CommunicationCategory $category
 * @property CommunicationStatus $status
 * @property array<string, mixed>|null $context
 */
class Communication extends Model
{
    /** @use HasFactory<CommunicationFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid', 'channel', 'category', 'status', 'to_address', 'subject', 'body', 'context',
        'event_key', 'subject_type', 'subject_id', 'buyer_id',
        'provider', 'provider_message_id', 'attempts', 'error',
        'queued_at', 'sending_at', 'sent_at', 'delivered_at', 'failed_at',
        'idempotency_key', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'category' => CommunicationCategory::class,
            'status' => CommunicationStatus::class,
            'context' => 'array',
            'subject_id' => 'integer',
            'buyer_id' => 'integer',
            'attempts' => 'integer',
            'created_by' => 'integer',
            'queued_at' => 'datetime',
            'sending_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Communication $c): void {
            $c->uuid ??= (string) Str::uuid();
        });
    }

    // --- Relationships -------------------------------------------------

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
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

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Communication>  $query */
    public function scopeStatus(Builder $query, CommunicationStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof CommunicationStatus ? $status->value : $status);
        }
    }

    /** @param  Builder<Communication>  $query */
    public function scopeChannel(Builder $query, CommunicationChannel|string|null $channel): void
    {
        if ($channel !== null && $channel !== '') {
            $query->where('channel', $channel instanceof CommunicationChannel ? $channel->value : $channel);
        }
    }

    /** @param  Builder<Communication>  $query */
    public function scopeForBuyer(Builder $query, int $buyerId): void
    {
        $query->where('buyer_id', $buyerId);
    }

    // --- Lifecycle ------------------------------------------------

    public function markQueued(): void
    {
        $this->transitionTo(CommunicationStatus::Queued, ['queued_at' => now()]);
    }

    public function markSending(): void
    {
        $this->forceFill(['status' => CommunicationStatus::Sending, 'sending_at' => now()])->save();
    }

    /**
     * Atomically take ownership of this communication for a delivery attempt
     * (F-M16-2). Only ONE worker can win — a single conditional UPDATE flips a
     * startable status (PENDING / QUEUED / FAILED) or a STALE `Sending` (its
     * lease expired because a previous worker died) to SENDING and bumps the
     * attempt counter. Returns false when another worker already holds it or it
     * has moved past sending; the caller must then do nothing. This is the
     * persistent idempotency guard — the queue's ShouldBeUnique lock is only a
     * secondary hint.
     */
    public function claimForSending(int $leaseSeconds = 300): bool
    {
        $startable = [
            CommunicationStatus::Pending->value,
            CommunicationStatus::Queued->value,
            CommunicationStatus::Failed->value,
        ];

        $affected = static::query()
            ->whereKey($this->getKey())
            ->where(function (Builder $q) use ($startable, $leaseSeconds): void {
                $q->whereIn('status', $startable)
                    ->orWhere(fn (Builder $inner) => $inner
                        ->where('status', CommunicationStatus::Sending->value)
                        ->where('sending_at', '<', now()->subSeconds($leaseSeconds)));
            })
            ->update([
                'status' => CommunicationStatus::Sending->value,
                'sending_at' => now(),
                'attempts' => $this->getConnection()->raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($affected === 1) {
            $this->refresh();

            return true;
        }

        return false;
    }

    public function markSent(string $provider, ?string $providerMessageId): void
    {
        $this->forceFill([
            'status' => CommunicationStatus::Sent,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
            'error' => null,
        ])->save();
    }

    /** Set ONLY from a genuine provider delivery report (webhook — M16.4). */
    public function markDelivered(): void
    {
        if ($this->status === CommunicationStatus::Delivered) {
            return;
        }

        $this->forceFill(['status' => CommunicationStatus::Delivered, 'delivered_at' => now()])->save();
    }

    public function markFailed(string $error): void
    {
        if ($this->status === CommunicationStatus::Delivered) {
            return;
        }

        $this->forceFill([
            'status' => CommunicationStatus::Failed,
            'failed_at' => now(),
            'error' => Str::limit($error, 250, ''),
        ])->save();
    }

    public function markCancelled(): void
    {
        $this->forceFill(['status' => CommunicationStatus::Cancelled])->save();
    }

    public function recordAttempt(): void
    {
        $this->forceFill(['attempts' => $this->attempts + 1])->save();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function canRetry(): bool
    {
        return $this->status === CommunicationStatus::Failed;
    }

    public function toOutboundMessage(): OutboundMessage
    {
        return new OutboundMessage(
            channel: $this->channel,
            category: $this->category,
            to: $this->to_address,
            subject: $this->subject,
            body: $this->body,
            reference: $this->uuid,
            metadata: ['event' => $this->event_key],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transitionTo(CommunicationStatus $target, array $extra = []): void
    {
        $this->forceFill(array_merge(['status' => $target], $extra))->save();
    }
}
