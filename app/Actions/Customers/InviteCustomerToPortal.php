<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\BuyerStatus;
use App\Enums\CustomerActivityType;
use App\Enums\CustomerPortalStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use App\Models\CustomerInvitation;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Customers\CustomerTokenService;
use Illuminate\Support\Facades\Log;

/**
 * Invites a buyer to the self-service portal (M15). Generates a single-use,
 * expiring activation token and returns the link for staff to share (no mail).
 * Re-inviting is allowed — it supersedes any outstanding token.
 *
 * @phpstan-type Result array{buyer: Buyer, link: string}
 */
class InviteCustomerToPortal
{
    use RunsInTransaction;

    public function __construct(private readonly CustomerTokenService $tokens) {}

    /**
     * @return array{buyer: Buyer, link: string}
     */
    public function handle(Buyer $buyer, User $actor): array
    {
        if (($buyer->email ?? '') === '') {
            throw new DomainException('Add an email address to the buyer before inviting them to the portal.');
        }

        if ($buyer->status !== BuyerStatus::Active) {
            throw new DomainException('Only an active customer record can be invited to the portal.');
        }

        if ($buyer->portal_status === CustomerPortalStatus::Active) {
            throw new DomainException('This customer already has active portal access — use "reset password" instead.');
        }

        // F-M5-1: an invitation must never create an ambiguous portal identity —
        // the buyer's email has to be unique among live customers before they
        // can sign in with it.
        if (Buyer::query()
            ->where('email', Buyer::normalizeEmail($buyer->email))
            ->whereKeyNot($buyer->getKey())
            ->exists()
        ) {
            throw new DomainException('Another customer already uses that email address — resolve the duplicate before inviting.');
        }

        return $this->transaction(function () use ($buyer, $actor): array {
            /** @var Buyer $locked */
            $locked = Buyer::query()->whereKey($buyer->getKey())->lockForUpdate()->firstOrFail();

            ['token' => $token] = $this->tokens->issue($locked, CustomerInvitation::PURPOSE_INVITE, $actor);

            $locked->forceFill([
                'portal_status' => CustomerPortalStatus::Invited,
                'portal_invited_at' => now(),
            ])->save();

            $locked->recordPortalActivity(CustomerActivityType::Invited, 'Portal invitation issued.');

            Log::info('customer.portal_invited', ['buyer_id' => $locked->id, 'by' => $actor->id]);

            return ['buyer' => $locked, 'link' => $this->tokens->link($token, CustomerInvitation::PURPOSE_INVITE)];
        });
    }
}
