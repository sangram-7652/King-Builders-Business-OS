<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Enums\PartnerActivityType;
use App\Exceptions\DomainException;
use App\Models\Partner;
use App\Models\PartnerProjectAuthorization;
use App\Models\Project;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Authorises a partner to work a project (M14). One row per (partner, project)
 * — a previously revoked authorisation is re-activated in place, so history is
 * preserved. Idempotent: re-authorising an already-active pair is a no-op.
 */
class AuthorizePartnerForProjectAction
{
    use RunsInTransaction;

    public function handle(Partner $partner, Project $project, User $actor, ?string $notes = null): PartnerProjectAuthorization
    {
        if ($partner->status->isTerminal() || ! $partner->status->isOpen()) {
            throw new DomainException("A {$partner->status->label()} partner cannot be authorised for projects.");
        }

        return $this->transaction(function () use ($partner, $project, $actor, $notes): PartnerProjectAuthorization {
            $auth = $this->resolveRow($partner, $project);

            if ($auth->status === 'active') {
                return $auth;
            }

            $auth->forceFill([
                'status' => 'active',
                'authorized_at' => now(),
                'authorized_by' => $actor->id,
                'revoked_at' => null,
                'revoked_by' => null,
                'revoke_reason' => null,
                'notes' => $notes ?? $auth->notes,
            ])->save();

            $partner->recordActivity(PartnerActivityType::ProjectAuthorized, "Authorised for {$project->name}.", [
                'project_id' => $project->id,
            ], $actor);

            Log::info('partner.project_authorized', [
                'partner_id' => $partner->id, 'project_id' => $project->id, 'by' => $actor->id,
            ]);

            return $auth;
        });
    }

    private function resolveRow(Partner $partner, Project $project): PartnerProjectAuthorization
    {
        try {
            return PartnerProjectAuthorization::query()->firstOrCreate(
                ['partner_id' => $partner->getKey(), 'project_id' => $project->getKey()],
                ['status' => 'revoked'], // created as revoked, then activated below
            );
        } catch (QueryException) {
            return PartnerProjectAuthorization::query()
                ->where('partner_id', $partner->getKey())
                ->where('project_id', $project->getKey())
                ->firstOrFail();
        }
    }
}
