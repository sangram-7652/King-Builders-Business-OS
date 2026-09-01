<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Enums\AgreementType;
use Database\Factories\AgreementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agreement (M9). AGR-000001. Files are versioned {@see Document}s attached to
 * the agreement — a signed agreement is never overwritten.
 *
 * @property AgreementStatus $status
 * @property AgreementType $type
 */
class Agreement extends Model
{
    /** @use HasFactory<AgreementFactory> */
    use HasFactory;

    public const SEQUENCE_KEY = 'agreement';

    protected $fillable = [
        'agreement_number', 'booking_id', 'type', 'status', 'document_id', 'terms_snapshot',
        'prepared_at', 'prepared_by', 'sent_at', 'signed_at', 'signed_by', 'signed_recorded_by',
        'approved_at', 'approved_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'document_id' => 'integer',
            'type' => AgreementType::class,
            'status' => AgreementStatus::class,
            'terms_snapshot' => 'array',
            'prepared_at' => 'datetime',
            'prepared_by' => 'integer',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'signed_recorded_by' => 'integer',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public static function formatCode(int $number): string
    {
        return 'AGR-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The versioned BOOKING_AGREEMENT document slot on the booking. @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @param  Builder<Agreement>  $query */
    public function scopeStatus(Builder $query, AgreementStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof AgreementStatus ? $status->value : $status);
        }
    }

    public function canTransitionTo(AgreementStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function isSigned(): bool
    {
        return $this->status->isSignedOrLater();
    }
}
