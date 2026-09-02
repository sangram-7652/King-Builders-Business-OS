<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\TransferRequestStatus;
use App\Models\TransferRequest;
use App\Models\User;

/**
 * SUPER ADMIN bypasses via Gate::before. Every ability checks the module
 * permission AND that the user may view the request's booking (IDOR guard).
 * A normal sales user never receives transfer completion rights automatically.
 */
class TransferRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('transfer.view');
    }

    public function view(User $user, TransferRequest $transfer): bool
    {
        return $user->can('transfer.view') && $this->canReach($user, $transfer);
    }

    public function create(User $user): bool
    {
        return $user->can('transfer.create');
    }

    public function update(User $user, TransferRequest $transfer): bool
    {
        return $user->can('transfer.create')
            && ! $transfer->status->isTerminal() && $this->canReach($user, $transfer);
    }

    public function review(User $user, TransferRequest $transfer): bool
    {
        return $user->can('transfer.review')
            && ! $transfer->status->isTerminal() && $this->canReach($user, $transfer);
    }

    public function approve(User $user, TransferRequest $transfer): bool
    {
        return $user->can('transfer.approve')
            && $transfer->status === TransferRequestStatus::UnderReview
            && $this->canReach($user, $transfer);
    }

    public function complete(User $user, TransferRequest $transfer): bool
    {
        return $user->can('transfer.complete')
            && $transfer->status === TransferRequestStatus::Approved
            && $this->canReach($user, $transfer);
    }

    private function canReach(User $user, TransferRequest $transfer): bool
    {
        $transfer->loadMissing('booking');

        return $transfer->booking !== null && $user->can('view', $transfer->booking);
    }
}
