<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
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
     * @param  array{due_at: string, type?: string|null, priority?: string|null, title?: string|null, note?: string|null, assigned_to?: int|null}  $data
     */
    public function handle(Lead $lead, array $data, User $actor): LeadFollowUp
    {
        return $this->transaction(function () use ($lead, $data, $actor): LeadFollowUp {
            $followUp = $lead->followUps()->create([
                'title' => isset($data['title']) && $data['title'] !== '' ? $data['title'] : null,
                'type' => isset($data['type']) ? FollowUpType::from($data['type']) : FollowUpType::Call,
                'priority' => isset($data['priority']) ? FollowUpPriority::from($data['priority']) : FollowUpPriority::Normal,
                'status' => FollowUpStatus::Pending,
                'assigned_to' => $data['assigned_to'] ?? $lead->assigned_to ?? $actor->id,
                'due_at' => Carbon::parse($data['due_at']),
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $lead->recordActivity(
                LeadActivityType::FollowUpScheduled,
                trim($followUp->type->label().' scheduled for '.$followUp->due_at->format('d M Y H:i')),
                ['follow_up_id' => $followUp->id, 'type' => $followUp->type->value],
                $actor,
            );

            $lead->syncNextAction();

            Log::info('lead.follow_up_scheduled', [
                'lead_id' => $lead->id, 'follow_up_id' => $followUp->id, 'by' => $actor->id,
            ]);

            return $followUp;
        });
    }
}
