<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Masters\DocumentType;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A managed document (M9) — polymorphic over Buyer (KYC) or Booking (booking
 * form, agreement, registered deed, handover ack, …). Holds the workflow state;
 * the files are immutable {@see DocumentVersion} rows.
 *
 * @property DocumentStatus $status
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'documentable_type', 'documentable_id', 'document_type_id', 'title', 'status',
        'current_version_id', 'verified_by', 'verified_at', 'rejected_by', 'rejected_at',
        'rejection_reason', 'expires_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'document_type_id' => 'integer',
            'status' => DocumentStatus::class,
            'current_version_id' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'rejected_by' => 'integer',
            'rejected_at' => 'datetime',
            'expires_at' => 'date',
            'created_by' => 'integer',
        ];
    }

    // --- Relationships -------------------------------------------------

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Document>  $query */
    public function scopeStatus(Builder $query, DocumentStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof DocumentStatus ? $status->value : $status);
        }
    }

    /** Documents attached to a Booking (the agreement + handover files live here too). */
    public function scopeForBooking(Builder $query, int $bookingId): void
    {
        $query->where('documentable_type', (new Booking)->getMorphClass())->where('documentable_id', $bookingId);
    }

    // --- Helpers ---------------------------------------------------

    public function isVerified(): bool
    {
        return $this->status === DocumentStatus::Verified;
    }

    public function hasFile(): bool
    {
        return $this->current_version_id !== null;
    }

    /** A file that must be protected from destructive delete. */
    public function isProtected(): bool
    {
        return $this->status === DocumentStatus::Verified;
    }
}
