<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of a document (M9).
 *
 *   PENDING ─▶ UPLOADED ─▶ UNDER_REVIEW ─▶ VERIFIED
 *                             └──────────▶ REJECTED ─▶ (re-upload) ─▶ UPLOADED
 *   any of UPLOADED / UNDER_REVIEW / VERIFIED ─▶ EXPIRED  (expires_at passed)
 *
 * A REJECTED document always carries a `rejection_reason`. Versioning means a
 * verified/signed file is never destructively replaced — a re-upload adds a new
 * version and moves the record back to UPLOADED.
 */
enum DocumentStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Uploaded => 'Uploaded',
            self::UnderReview => 'Under review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'muted',
            self::Uploaded => 'info',
            self::UnderReview => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
            self::Expired => 'danger',
        };
    }

    /** Counts towards "received" on a checklist (a file exists). */
    public function hasFile(): bool
    {
        return in_array($this, [self::Uploaded, self::UnderReview, self::Verified, self::Rejected, self::Expired], true);
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    public function isOutstanding(): bool
    {
        return in_array($this, [self::Pending, self::Rejected, self::Expired], true);
    }
}
