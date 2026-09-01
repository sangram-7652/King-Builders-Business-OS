<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\FollowUpOutcome;
use App\Enums\LeadActivityType;
use App\Exceptions\DomainException;
use App\Models\LeadFollowUp;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class CompleteFollowUp
{
    use RunsInTransaction;

    /**
     * @param  array{outcome: string, note?: string|null}  $data  already-validated
     */
    public function handle(LeadFollowUp $followUp, array $data, User $actor): LeadFollowUp
    {
        return $this->transaction(function () use ($followUp, $data, $actor): LeadFollowUp {
            if ($followUp->isCompleted()) {
                throw new DomainException('This follow-up is already completed.');
            }

            $followUp->fill([
                'outcome' => FollowUpOutcome::from($data['outcome']),
                'completed_at' => now(),
                'note' => $data['note'] ?? $followUp->note,
            ])->save();

            $lead = $followUp->lead;
            $lead->recordActivity(
                LeadActivityType::FollowUpCompleted,
                'Follow-up completed — '.$followUp->outcome->label(),
                ['follow_up_id' => $followUp->id, 'outcome' => $followUp->outcome->value],
                $actor,
            );

            $lead->recomputeFollowUpAt();

            Log::info('lead.follow_up_completed', ['lead_id' => $lead->id, 'by' => $actor->id]);

            return $followUp;
        });
    }
}
