<?php

declare(strict_types=1);

namespace App\Communication;

use Illuminate\Support\Str;

/**
 * The outcome of handing a message to a provider (M16).
 *
 *  - `accepted`         the provider took the message for delivery
 *  - `providerMessageId` the provider's own id, used to match webhooks later
 *  - `confirmsDelivery` whether this provider will later report DELIVERED via a
 *                       webhook; if false, SENT is the terminal success state
 *  - `permanentFailure` a 4xx-class rejection (bad number, blocked) — do NOT retry
 *  - `error`            a short, secret-free description of a failure
 */
final class ProviderResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerMessageId,
        public readonly bool $confirmsDelivery,
        public readonly bool $permanentFailure,
        public readonly ?string $error,
    ) {}

    public static function accepted(?string $providerMessageId, bool $confirmsDelivery = false): self
    {
        return new self(true, $providerMessageId, $confirmsDelivery, false, null);
    }

    public static function temporaryFailure(string $error): self
    {
        return new self(false, null, false, false, self::scrub($error));
    }

    public static function permanentFailure(string $error): self
    {
        return new self(false, null, false, true, self::scrub($error));
    }

    private static function scrub(string $error): string
    {
        return Str::limit(trim($error), 240);
    }
}
