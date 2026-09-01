<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadActivityType;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ScheduleFollowUp
{
    use RunsInTransaction;

    /**
     * @param  array{due_at: string, note?: string|null}  $data  already-validated
     */
    public function handle(Lead $lead, array $data, User $actor): LeadFollowUp
    {
        return $this->transaction(function () use ($lead, $data, $actor): LeadFollowUp {
            $followUp = $lead->followUps()->create([
                'due_at' => Carbon::parse($data['due_at']),
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $lead->recordActivity(
                LeadActivityType::FollowUpScheduled,
                'Follow-up scheduled for '.$followUp->due_at->format('d M Y H:i'),
                ['follow_up_id' => $followUp->id],
                $actor,
            );

            $lead->recomputeFollowUpAt();

            Log::info('lead.follow_up_scheduled', ['lead_id' => $lead->id, 'by' => $actor->id]);

            return $followUp;
        });
    }
}
