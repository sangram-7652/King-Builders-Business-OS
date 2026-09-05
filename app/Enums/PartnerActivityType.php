<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The lightweight append-only partner activity/history foundation (M14).
 * Reuses the M5 / M8 / M9 timeline pattern. Later phases add more cases
 * (attribution, commission generation) — this is the M14.1 foundation set.
 */
enum PartnerActivityType: string
{
    use HasLabel;

    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';
    case SubmittedForApproval = 'submitted_for_approval';
    case Approved = 'approved';
    case PutOnHold = 'put_on_hold';
    case Resumed = 'resumed';
    case Suspended = 'suspended';
    case Blacklisted = 'blacklisted';
    case Reactivated = 'reactivated';
    case Retired = 'retired';
    case KycUploaded = 'kyc_uploaded';
    case KycVerified = 'kyc_verified';
    case KycRejected = 'kyc_rejected';
    case ProjectAuthorized = 'project_authorized';
    case ProjectRevoked = 'project_revoked';
    case BankDetailsUpdated = 'bank_details_updated';
    case NoteAdded = 'note_added';
    case LeadAttributed = 'lead_attributed';
    case LeadAttributionRemoved = 'lead_attribution_removed';
    case BookingAttributed = 'booking_attributed';
    case BookingAttributionUpdated = 'booking_attribution_updated';
    case BookingAttributionRemoved = 'booking_attribution_removed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Partner created',
            self::Updated => 'Partner details updated',
            self::StatusChanged => 'Status changed',
            self::SubmittedForApproval => 'Submitted for approval',
            self::Approved => 'Partner approved',
            self::PutOnHold => 'Put on hold',
            self::Resumed => 'Resumed',
            self::Suspended => 'Suspended',
            self::Blacklisted => 'Blacklisted',
            self::Reactivated => 'Reactivated',
            self::Retired => 'Marked inactive',
            self::KycUploaded => 'KYC document uploaded',
            self::KycVerified => 'KYC document verified',
            self::KycRejected => 'KYC document rejected',
            self::ProjectAuthorized => 'Authorised for project',
            self::ProjectRevoked => 'Project authorisation revoked',
            self::BankDetailsUpdated => 'Payout bank details updated',
            self::NoteAdded => 'Note added',
            self::LeadAttributed => 'Lead attributed to partner',
            self::LeadAttributionRemoved => 'Lead attribution removed',
            self::BookingAttributed => 'Booking attributed to partner',
            self::BookingAttributionUpdated => 'Booking attribution updated',
            self::BookingAttributionRemoved => 'Booking attribution removed',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Created => 'plus',
            self::Updated, self::BankDetailsUpdated, self::NoteAdded => 'inbox',
            self::StatusChanged, self::SubmittedForApproval, self::Approved,
            self::PutOnHold, self::Resumed, self::Suspended, self::Blacklisted,
            self::Reactivated, self::Retired => 'user',
            self::KycUploaded, self::KycVerified, self::KycRejected => 'building',
            self::ProjectAuthorized, self::ProjectRevoked => 'building',
            self::LeadAttributed, self::LeadAttributionRemoved => 'inbox',
            self::BookingAttributed, self::BookingAttributionUpdated, self::BookingAttributionRemoved => 'inbox',
        };
    }
}
