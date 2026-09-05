<?php

declare(strict_types=1);

namespace App\Communication\Contracts;

use App\Communication\CommunicationManager;
use App\Communication\OutboundMessage;
use App\Communication\ProviderResult;
use App\Enums\CommunicationChannel;

/**
 * A pluggable transport for one communication channel (M16). Domain code never
 * references a concrete provider — it asks the {@see CommunicationManager}
 * for the provider bound to a channel in config.
 *
 * Implementations MUST NOT throw for an expected delivery failure — return a
 * {@see ProviderResult::temporaryFailure()} / `permanentFailure()` instead.
 * A thrown exception is treated as a transient transport error and retried.
 */
interface CommunicationProvider
{
    public function channel(): CommunicationChannel;

    /** Short stable identifier stored on the communication record (e.g. "log", "smtp"). */
    public function name(): string;

    public function send(OutboundMessage $message): ProviderResult;
}
