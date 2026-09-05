<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Append-only customer portal audit trail (M15). Reuses the M5/M8/M9 activity
 * pattern. Distinct from staff audit — a customer only ever sees a curated
 * subset of these on their own timeline (M15.4).
 */
enum CustomerActivityType: string
{
    use HasLabel;

    case Invited = 'invited';
    case Activated = 'activated';
    case ResetRequested = 'reset_requested';
    case PasswordReset = 'password_reset';
    case Suspended = 'suspended';
    case Restored = 'restored';
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case ProfileUpdated = 'profile_updated';
    case DocumentViewed = 'document_viewed';
    case DocumentDownloaded = 'document_downloaded';
    case ReceiptViewed = 'receipt_viewed';
    case ReceiptDownloaded = 'receipt_downloaded';
    case SupportRequestCreated = 'support_request_created';
    case SupportRequestUpdated = 'support_request_updated';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Portal invitation sent',
            self::Activated => 'Portal access activated',
            self::ResetRequested => 'Password reset requested',
            self::PasswordReset => 'Password reset',
            self::Suspended => 'Portal access suspended',
            self::Restored => 'Portal access restored',
            self::Login => 'Signed in',
            self::LoginFailed => 'Failed sign-in attempt',
            self::Logout => 'Signed out',
            self::ProfileUpdated => 'Profile updated',
            self::DocumentViewed => 'Document viewed',
            self::DocumentDownloaded => 'Document downloaded',
            self::ReceiptViewed => 'Receipt viewed',
            self::ReceiptDownloaded => 'Receipt downloaded',
            self::SupportRequestCreated => 'Support request created',
            self::SupportRequestUpdated => 'Support request updated',
        };
    }

    /** Events a customer may see on their own timeline (M15.4). */
    public function isCustomerVisible(): bool
    {
        return in_array($this, [
            self::Activated, self::PasswordReset, self::Login, self::Logout,
            self::ProfileUpdated, self::DocumentDownloaded, self::ReceiptDownloaded,
            self::SupportRequestCreated, self::SupportRequestUpdated,
        ], true);
    }
}
