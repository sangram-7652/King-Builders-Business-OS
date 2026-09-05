<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerActivityType;
use App\Enums\CustomerPortalStatus;
use App\Models\Buyer;
use App\Models\CustomerInvitation;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Customers\CustomerTokenService;
use Illuminate\Support\Facades\Log;

/**
 * Issues a portal password-reset token (M15).
 *
 *  - staff-initiated (from Customer 360): returns the link to share
 *  - customer-initiated ("forgot password"): the token is created and a
 *    `reset_requested` activity is logged for staff to action — the customer
 *    is shown a generic message and never told whether the email exists
 *    (no mail is sent in M15)
 */
class RequestCustomerPasswordReset
{
    use RunsInTransaction;

    public function __construct(private readonly CustomerTokenService $tokens) {}

    /**
     * Staff path — the buyer is known.
     *
     * @return array{link: string}
     */
    public function forBuyer(Buyer $buyer, User $actor): array
    {
        return $this->transaction(function () use ($buyer, $actor): array {
            /** @var Buyer $locked */
            $locked = Buyer::query()->whereKey($buyer->getKey())->lockForUpdate()->firstOrFail();

            ['token' => $token] = $this->tokens->issue($locked, CustomerInvitation::PURPOSE_RESET, $actor);
            $locked->recordPortalActivity(CustomerActivityType::ResetRequested, 'Password reset link issued by staff.');

            Log::info('customer.portal_reset_issued', ['buyer_id' => $locked->id, 'by' => $actor->id]);

            return ['link' => $this->tokens->link($token, CustomerInvitation::PURPOSE_RESET)];
        });
    }

    /**
     * Customer "forgot password" path — enumeration-safe, no mail.
     */
    public function forEmail(string $email): void
    {
        $buyer = Buyer::query()
            ->where('email', Buyer::normalizeEmail($email))
            ->where('portal_status', CustomerPortalStatus::Active->value)
            ->orderBy('id')
            ->first();

        if ($buyer === null) {
            return; // silent — never reveal whether the address exists
        }

        $this->transaction(function () use ($buyer): void {
            $this->tokens->issue($buyer, CustomerInvitation::PURPOSE_RESET, null);
            $buyer->recordPortalActivity(CustomerActivityType::ResetRequested, 'Customer requested a password reset.');
            Log::info('customer.portal_reset_requested', ['buyer_id' => $buyer->id]);
        });
    }
}
