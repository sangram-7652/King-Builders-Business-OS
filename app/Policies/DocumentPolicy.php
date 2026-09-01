<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\User;

/**
 * SUPER ADMIN bypasses via Gate::before.
 *
 * Every ability first checks the module permission AND that the user may view
 * the document's underlying Buyer / Booking — this is the isolation guard that
 * makes guessing a document id (IDOR) useless: a user who cannot see the buyer
 * cannot touch or download that buyer's documents.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::DocumentsView->value);
    }

    public function view(User $user, Document $document): bool
    {
        return $user->can(Permission::DocumentsView->value) && $this->canReachDocumentable($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $user->can(Permission::DocumentsDownload->value) && $this->canReachDocumentable($user, $document);
    }

    public function upload(User $user, Document $document): bool
    {
        return $user->can(Permission::DocumentsUpload->value) && $this->canReachDocumentable($user, $document);
    }

    public function verify(User $user, Document $document): bool
    {
        return $user->can(Permission::DocumentsVerify->value) && $this->canReachDocumentable($user, $document);
    }

    public function reject(User $user, Document $document): bool
    {
        return $user->can(Permission::DocumentsReject->value) && $this->canReachDocumentable($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->can(Permission::DocumentsDelete->value)
            && ! $document->isProtected()
            && $this->canReachDocumentable($user, $document);
    }

    /**
     * Resolve the document to the Buyer / Booking it ultimately belongs to and
     * check the user may view that entity.
     */
    private function canReachDocumentable(User $user, Document $document): bool
    {
        $document->loadMissing('documentable');
        $d = $document->documentable;

        return match (true) {
            $d instanceof Buyer, $d instanceof Booking => $user->can('view', $d),
            default => false,
        };
    }
}
