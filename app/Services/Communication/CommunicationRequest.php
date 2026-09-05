<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\CommunicationCategory;
use App\Enums\CommunicationChannel;
use App\Models\Buyer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A fully-resolved instruction to send one communication (M16). The rendered
 * `body` is already final — {@see Communicator}
 * only persists and queues it, it does not render templates.
 */
final class CommunicationRequest
{
    /**
     * @param  array<string, mixed>|null  $context  rendered variable snapshot for audit
     */
    public function __construct(
        public readonly CommunicationChannel $channel,
        public readonly CommunicationCategory $category,
        public readonly string $to,
        public readonly string $body,
        public readonly ?string $subject = null,
        public readonly ?string $eventKey = null,
        public readonly ?Model $subjectModel = null,
        public readonly ?Buyer $buyer = null,
        public readonly ?Lead $lead = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?array $context = null,
        public readonly ?User $actor = null,
    ) {}
}
