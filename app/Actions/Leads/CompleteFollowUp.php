<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\FollowUpOutcome;
use App\Enums\FollowUpStatus;
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
     * @param  array{outcome?: string|null, note?: string|null}  $data  already-validated
     */
    public function handle(LeadFollowUp $followUp, array $data, User $actor): LeadFollowUp
    {
        return $this->transaction(function () use ($followUp, $data, $actor): LeadFollowUp {
            /** @var LeadFollowUp $locked */
            $locked = LeadFollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new DomainException("This follow-up is {$locked->status->label()} and cannot be completed.");
            }

            $locked->forceFill([
                'status' => FollowUpStatus::Completed,
                'outcome' => isset($data['outcome']) && $data['outcome'] !== '' ? FollowUpOutcome::from($data['outcome']) : $locked->outcome,
                'completed_at' => now(),
                'completed_by' => $actor->id,
                'note' => $data['note'] ?? $locked->note,
            ])->save();

            $lead = $locked->lead;
            $lead->markFirstContact();
            $lead->recordActivity(
                LeadActivityType::FollowUpCompleted,
                trim($locked->type->label().' completed'.($locked->outcome ? ' — '.$locked->outcome->label() : '')),
                ['follow_up_id' => $locked->id, 'outcome' => $locked->outcome?->value],
                $actor,
            );
            $lead->syncNextAction();

            Log::info('lead.follow_up_completed', [
                'lead_id' => $lead->id, 'follow_up_id' => $locked->id, 'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
