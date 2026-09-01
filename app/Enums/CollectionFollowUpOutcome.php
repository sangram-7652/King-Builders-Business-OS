<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Centralised collection follow-up outcomes (M8). Distinct from the M5 sales
 * lead follow-up outcomes — this is about chasing money, not qualifying a lead.
 */
enum CollectionFollowUpOutcome: string
{
    use HasLabel;

    case Contacted = 'contacted';
    case PromisedPayment = 'promised_payment';
    case PaymentReceived = 'payment_received';
    case CallBack = 'call_back';
    case CustomerUnreachable = 'customer_unreachable';
    case Dispute = 'dispute';
    case ChequePending = 'cheque_pending';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contacted => 'Contacted',
            self::PromisedPayment => 'Promised payment',
            self::PaymentReceived => 'Payment received',
            self::CallBack => 'Call back',
            self::CustomerUnreachable => 'Customer unreachable',
            self::Dispute => 'Dispute',
            self::ChequePending => 'Cheque pending',
            self::Other => 'Other',
        };
    }
}
