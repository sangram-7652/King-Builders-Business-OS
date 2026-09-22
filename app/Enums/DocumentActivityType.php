<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Append-only documentation / agreement / registry timeline events (M9) —
 * reuses the M5/M8 lightweight activity pattern (see App\Models\DocumentActivity).
 * Never store PII in `properties`.
 */
enum DocumentActivityType: string
{
    use HasLabel;

    case DocumentUploaded = 'document_uploaded';
    case DocumentResubmitted = 'document_resubmitted';
    case DocumentSubmittedForReview = 'document_submitted_for_review';
    case DocumentVerified = 'document_verified';
    case DocumentRejected = 'document_rejected';
    case DocumentExpired = 'document_expired';
    case DocumentDeleted = 'document_deleted';

    case AgreementCreated = 'agreement_created';
    case AgreementPrepared = 'agreement_prepared';
    case AgreementSent = 'agreement_sent';
    case AgreementSigned = 'agreement_signed';
    case AgreementApproved = 'agreement_approved';
    case AgreementCancelled = 'agreement_cancelled';

    case PlotKycReceiptGenerated = 'plot_kyc_receipt_generated';

    case RegistryInitiated = 'registry_initiated';
    case RegistryEligibilityChanged = 'registry_eligibility_changed';
    case RegistryScheduled = 'registry_scheduled';
    case RegistryRescheduled = 'registry_rescheduled';
    case RegistryInProcess = 'registry_in_process';
    case RegistryCompleted = 'registry_completed';
    case RegistryOnHold = 'registry_on_hold';
    case RegistryResumed = 'registry_resumed';
    case RegistryCancelled = 'registry_cancelled';
    case RegistryExpenseRecorded = 'registry_expense_recorded';
    case RegistryExpenseApproved = 'registry_expense_approved';
    case RegistryStatusChanged = 'registry_status_changed';

    case HandoverDocumentsReady = 'handover_documents_ready';
    case HandoverScheduled = 'handover_scheduled';
    case HandoverCompleted = 'handover_completed';
    case HandoverReversed = 'handover_reversed';

    public function label(): string
    {
        return match ($this) {
            self::DocumentUploaded => 'Document uploaded',
            self::DocumentResubmitted => 'Document re-uploaded',
            self::DocumentSubmittedForReview => 'Document submitted for review',
            self::DocumentVerified => 'Document verified',
            self::DocumentRejected => 'Document rejected',
            self::DocumentExpired => 'Document expired',
            self::DocumentDeleted => 'Document removed',
            self::AgreementCreated => 'Agreement created',
            self::AgreementPrepared => 'Agreement prepared',
            self::AgreementSent => 'Agreement sent',
            self::AgreementSigned => 'Agreement signed',
            self::AgreementApproved => 'Agreement approved',
            self::AgreementCancelled => 'Agreement cancelled',
            self::PlotKycReceiptGenerated => 'Plot KYC receipt generated',
            self::RegistryInitiated => 'Registry case initiated',
            self::RegistryEligibilityChanged => 'Registry eligibility changed',
            self::RegistryScheduled => 'Registry appointment scheduled',
            self::RegistryRescheduled => 'Registry appointment rescheduled',
            self::RegistryInProcess => 'Registry in process',
            self::RegistryCompleted => 'Registry completed',
            self::RegistryOnHold => 'Registry put on hold',
            self::RegistryResumed => 'Registry resumed',
            self::RegistryCancelled => 'Registry cancelled',
            self::RegistryExpenseRecorded => 'Registry expense recorded',
            self::RegistryExpenseApproved => 'Registry expense approved',
            self::RegistryStatusChanged => 'Registry status changed',
            self::HandoverDocumentsReady => 'Handover documents ready',
            self::HandoverScheduled => 'Handover scheduled',
            self::HandoverCompleted => 'Documents handed over',
            self::HandoverReversed => 'Handover reversed',
        };
    }

    public function icon(): string
    {
        return match (true) {
            str_starts_with($this->value, 'document_') => 'inbox',
            str_starts_with($this->value, 'agreement_') => 'inbox',
            $this === self::PlotKycReceiptGenerated => 'inbox',
            str_starts_with($this->value, 'registry_') => 'building',
            default => 'clock',
        };
    }
}
