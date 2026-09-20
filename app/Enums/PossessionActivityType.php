<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Append-only possession / transfer / ownership timeline events (M10) — reuses
 * the M5/M8/M9 lightweight activity pattern (see App\Models\PossessionActivity).
 * Never store PII in `properties`.
 */
enum PossessionActivityType: string
{
    use HasLabel;

    case PossessionInitiated = 'possession_initiated';
    case PossessionEligibilityChanged = 'possession_eligibility_changed';
    case ClearanceChanged = 'clearance_changed';
    case PossessionScheduled = 'possession_scheduled';
    case PossessionRescheduled = 'possession_rescheduled';
    case InspectionRecorded = 'inspection_recorded';
    case PossessionReadyForHandover = 'possession_ready_for_handover';
    case PossessionHandoverStarted = 'possession_handover_started';
    case PossessionAcknowledged = 'possession_acknowledged';
    case PossessionCompleted = 'possession_completed';
    case CertificateGenerated = 'certificate_generated';
    case PossessionOnHold = 'possession_on_hold';
    case PossessionResumed = 'possession_resumed';
    case PossessionCancelled = 'possession_cancelled';

    case TransferRequested = 'transfer_requested';
    case TransferSubmitted = 'transfer_submitted';
    case TransferUnderReview = 'transfer_under_review';
    case TransferDocumentsPending = 'transfer_documents_pending';
    case TransferDocumentsVerified = 'transfer_documents_verified';
    case TransferApproved = 'transfer_approved';
    case TransferRejected = 'transfer_rejected';
    case TransferCompleted = 'transfer_completed';
    case TransferCancelled = 'transfer_cancelled';
    case OwnershipChanged = 'ownership_changed';
    case PlotChanged = 'plot_changed';
    case NomineeRecorded = 'nominee_recorded';

    public function label(): string
    {
        return match ($this) {
            self::PossessionInitiated => 'Possession initiated',
            self::PossessionEligibilityChanged => 'Possession eligibility changed',
            self::ClearanceChanged => 'Clearance changed',
            self::PossessionScheduled => 'Possession scheduled',
            self::PossessionRescheduled => 'Possession rescheduled',
            self::InspectionRecorded => 'Inspection recorded',
            self::PossessionReadyForHandover => 'Ready for handover',
            self::PossessionHandoverStarted => 'Handover started',
            self::PossessionAcknowledged => 'Possession acknowledged',
            self::PossessionCompleted => 'Possession completed',
            self::CertificateGenerated => 'Certificate generated',
            self::PossessionOnHold => 'Possession put on hold',
            self::PossessionResumed => 'Possession resumed',
            self::PossessionCancelled => 'Possession cancelled',
            self::TransferRequested => 'Transfer requested',
            self::TransferSubmitted => 'Transfer submitted',
            self::TransferUnderReview => 'Transfer under review',
            self::TransferDocumentsPending => 'Transfer documents pending',
            self::TransferDocumentsVerified => 'Transfer documents verified',
            self::TransferApproved => 'Transfer approved',
            self::TransferRejected => 'Transfer rejected',
            self::TransferCompleted => 'Transfer completed',
            self::TransferCancelled => 'Transfer cancelled',
            self::OwnershipChanged => 'Ownership changed',
            self::PlotChanged => 'Plot changed',
            self::NomineeRecorded => 'Nominee recorded',
        };
    }

    public function icon(): string
    {
        return match (true) {
            str_starts_with($this->value, 'transfer') => 'inbox',
            str_starts_with($this->value, 'ownership') => 'user',
            str_starts_with($this->value, 'nominee') => 'user',
            default => 'building',
        };
    }
}
