<?php

declare(strict_types=1);

namespace App\Communication;

use App\Enums\CommunicationCategory;
use App\Enums\CommunicationChannel;

/**
 * An immutable, provider-agnostic description of one message to send (M16).
 * A provider adapter turns this into a concrete API call. `body` is already the
 * final rendered content — no template logic reaches the provider.
 */
final class OutboundMessage
{
    /**
     * @param  array<string, scalar|null>  $metadata  never secrets / PII beyond the address
     */
    public function __construct(
        public readonly CommunicationChannel $channel,
        public readonly CommunicationCategory $category,
        public readonly string $to,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly string $reference,
        public readonly array $metadata = [],
    ) {}
}
