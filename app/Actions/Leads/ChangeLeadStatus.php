<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Exceptions\DomainException;
use App\Models\Lead;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Generic, map-enforced lead status change. CONVERTED is never a valid target
 * here — conversion goes through ConvertLeadToBuyer.
 */
class ChangeLeadStatus
{
    use RunsInTransaction;

    public function handle(Lead $lead, LeadStatus $target, User $actor): Lead
    {
        return $this->transaction(function () use ($lead, $target, $actor): Lead {
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if ($current === $target) {
                return $locked;
            }

            if ($target === LeadStatus::Converted) {
                throw new DomainException('Use the conversion workflow to convert a lead.');
            }

            if (! $current->canTransitionTo($target)) {
                throw new DomainException("A {$current->label()} lead cannot move to {$target->label()}.");
            }

            $locked->status = $target;
            $locked->save();

            $locked->recordActivity(LeadActivityType::StatusChanged, "Status: {$current->label()} → {$target->label()}", [
                'from' => $current->value,
                'to' => $target->value,
            ], $actor);

            Log::info('lead.status_changed', [
                'lead_id' => $locked->id,
                'from' => $current->value,
                'to' => $target->value,
                'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
