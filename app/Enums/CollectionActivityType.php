<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Append-only collection timeline events (M8) — reuses the lightweight activity
 * pattern from M5 (see App\Models\CollectionActivity). Never store PII in
 * `properties`.
 */
enum CollectionActivityType: string
{
    use HasLabel;

    case CaseOpened = 'case_opened';
    case CaseAssigned = 'case_assigned';
    case CaseStatusChanged = 'case_status_changed';
    case PriorityChanged = 'priority_changed';
    case InstallmentOverdue = 'installment_overdue';
    case FollowUpScheduled = 'follow_up_scheduled';
    case FollowUpCompleted = 'follow_up_completed';
    case PromiseCreated = 'promise_created';
    case PromiseKept = 'promise_kept';
    case PromiseBroken = 'promise_broken';
    case PromiseCancelled = 'promise_cancelled';
    case PaymentReceived = 'payment_received';
    case ChequeBounced = 'cheque_bounced';
    case ChequeCleared = 'cheque_cleared';
    case PenaltyAssessed = 'penalty_assessed';
    case PenaltyApproved = 'penalty_approved';
    case CaseResolved = 'case_resolved';
    case CaseReopened = 'case_reopened';

    public function label(): string
    {
        return match ($this) {
            self::CaseOpened => 'Collection case opened',
            self::CaseAssigned => 'Case assigned',
            self::CaseStatusChanged => 'Status changed',
            self::PriorityChanged => 'Priority changed',
            self::InstallmentOverdue => 'Installment became overdue',
            self::FollowUpScheduled => 'Follow-up scheduled',
            self::FollowUpCompleted => 'Follow-up completed',
            self::PromiseCreated => 'Promise to pay created',
            self::PromiseKept => 'Promise kept',
            self::PromiseBroken => 'Promise broken',
            self::PromiseCancelled => 'Promise cancelled',
            self::PaymentReceived => 'Payment received',
            self::ChequeBounced => 'Cheque bounced',
            self::ChequeCleared => 'Cheque cleared',
            self::PenaltyAssessed => 'Bounce penalty assessed',
            self::PenaltyApproved => 'Bounce penalty approved',
            self::CaseResolved => 'Case resolved',
            self::CaseReopened => 'Case reopened',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CaseOpened, self::CaseReopened => 'plus',
            self::CaseAssigned => 'user',
            self::CaseStatusChanged, self::PriorityChanged => 'inbox',
            self::InstallmentOverdue, self::PromiseBroken, self::ChequeBounced => 'clock',
            self::FollowUpScheduled, self::FollowUpCompleted => 'clock',
            self::PromiseCreated, self::PromiseKept, self::PromiseCancelled => 'inbox',
            self::PaymentReceived, self::ChequeCleared, self::CaseResolved => 'building',
            self::PenaltyAssessed, self::PenaltyApproved => 'inbox',
        };
    }
}
