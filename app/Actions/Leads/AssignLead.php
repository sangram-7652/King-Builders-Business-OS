<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadActivityType;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class AssignLead
{
    use RunsInTransaction;

    public function handle(Lead $lead, ?User $assignee, User $actor): Lead
    {
        return $this->transaction(function () use ($lead, $assignee, $actor): Lead {
            if ($assignee !== null && ! $assignee->isActive()) {
                throw new DomainException('Leads can only be assigned to an active user.');
            }

            $previous = $lead->assigned_to;
            $lead->assigned_to = $assignee?->id;
            $lead->save();

            if ($assignee !== null) {
                $lead->recordActivity(LeadActivityType::Assigned, "Assigned to {$assignee->name}", [
                    'from' => $previous,
                    'to' => $assignee->id,
                ], $actor);
            } else {
                $lead->recordActivity(LeadActivityType::Unassigned, 'Unassigned', ['from' => $previous], $actor);
            }

            Log::info('lead.assigned', [
                'lead_id' => $lead->id,
                'to' => $assignee?->id,
                'by' => $actor->id,
            ]);

            return $lead;
        });
    }
}
