<?php

declare(strict_types=1);

namespace App\Support\Customers;

use App\Models\Buyer;
use App\Models\CustomerInvitation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Issues and resolves the single-use, expiring portal tokens (M15). Only the
 * SHA-256 hash is persisted; the raw token is returned once so staff can build
 * the activation / reset link and share it out of band (no mail in M15).
 */
class CustomerTokenService
{
    /**
     * @return array{token: string, invitation: CustomerInvitation}
     */
    public function issue(Buyer $buyer, string $purpose, ?User $actor = null): array
    {
        // Any older unused token of the same purpose is spent immediately.
        CustomerInvitation::query()
            ->where('buyer_id', $buyer->getKey())
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $raw = Str::random(64);

        $ttlHours = $purpose === CustomerInvitation::PURPOSE_RESET
            ? (int) config('portal.reset_ttl_hours', 24)
            : (int) config('portal.invite_ttl_hours', 168);

        $invitation = CustomerInvitation::create([
            'buyer_id' => $buyer->getKey(),
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addHours($ttlHours),
            'created_by' => $actor?->getKey(),
        ]);

        return ['token' => $raw, 'invitation' => $invitation];
    }

    public function resolve(string $rawToken, string $purpose): ?CustomerInvitation
    {
        if (strlen($rawToken) < 32) {
            return null;
        }

        return CustomerInvitation::query()
            ->with('buyer')
            ->where('purpose', $purpose)
            ->where('token_hash', hash('sha256', $rawToken))
            ->usable()
            ->first();
    }

    public function link(string $rawToken, string $purpose): string
    {
        return $purpose === CustomerInvitation::PURPOSE_RESET
            ? route('portal.password.reset', ['token' => $rawToken])
            : route('portal.activate', ['token' => $rawToken]);
    }
}
