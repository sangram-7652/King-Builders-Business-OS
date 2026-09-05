<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadActivityType;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Assign, reassign or unassign a lead (M5, extended in M13.1).
 *
 * `leads.assigned_to` is the current owner; `lead_assignments` is the permanent,
 * append-only history — the open span is closed (`ended_at`) and a new one
 * opened in the same transaction, so no ownership period is ever lost.
 */
class AssignLead
{
    use RunsInTransaction;

    public function handle(Lead $lead, ?User $assignee, User $actor, ?string $reason = null): Lead
    {
        return $this->transaction(function () use ($lead, $assignee, $actor, $reason): Lead {
            /** @var Lead $locked */
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            if ($assignee !== null && ! $assignee->isActive()) {
                throw new DomainException('Leads can only be assigned to an active user.');
            }

            $previous = $locked->assigned_to;
            if ($previous === $assignee?->id) {
                return $locked;                       // no-op
            }

            // Close the current open span (if any).
            LeadAssignment::query()
                ->where('lead_id', $locked->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now(), 'updated_at' => now()]);

            // Open a new span when assigning to someone.
            if ($assignee !== null) {
                LeadAssignment::create([
                    'lead_id' => $locked->id,
                    'assigned_to' => $assignee->id,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                    'reason' => $reason,
                ]);
            }

            $locked->forceFill(['assigned_to' => $assignee?->id])->save();

            [$type, $description] = match (true) {
                $assignee === null => [LeadActivityType::Unassigned, 'Unassigned'.($reason ? " — {$reason}" : '')],
                $previous === null => [LeadActivityType::Assigned, "Assigned to {$assignee->name}"],
                default => [LeadActivityType::Reassigned, "Reassigned to {$assignee->name}".($reason ? " — {$reason}" : '')],
            };

            $locked->recordActivity($type, $description, [
                'from' => $previous,
                'to' => $assignee?->id,
            ], $actor);

            Log::info('lead.assigned', [
                'lead_id' => $locked->id,
                'from' => $previous,
                'to' => $assignee?->id,
                'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
