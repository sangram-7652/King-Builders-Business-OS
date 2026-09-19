<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Marketing-consent state for a contactable party (Buyer) — F-M16-3.
 *
 * Consent is ACTIVE only when it has been explicitly given and not withdrawn.
 * An opt-out always wins, even if consent is later re-recorded without clearing
 * it. The communication engine blocks any MARKETING-category message to a party
 * without active consent; transactional messages are never gated by this.
 *
 * Expects nullable `marketing_consent_at` / `marketing_opt_out_at` datetime
 * columns and both in the model's `casts()` as `datetime`.
 */
trait HasMarketingConsent
{
    public function hasMarketingConsent(): bool
    {
        return $this->marketing_consent_at !== null
            && $this->marketing_opt_out_at === null;
    }

    public function grantMarketingConsent(): void
    {
        $this->forceFill([
            'marketing_consent_at' => $this->marketing_consent_at ?? now(),
            'marketing_opt_out_at' => null,
        ])->save();
    }

    public function optOutOfMarketing(): void
    {
        $this->forceFill(['marketing_opt_out_at' => now()])->save();
    }
}
