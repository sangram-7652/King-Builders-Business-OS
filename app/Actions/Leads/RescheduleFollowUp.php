<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\FollowUpStatus;
use App\Enums\LeadActivityType;
use App\Exceptions\DomainException;
use App\Models\LeadFollowUp;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Move a pending (or missed) follow-up to a new time.
 *
 * History is preserved: the original row is marked RESCHEDULED and a fresh
 * PENDING row is created with `rescheduled_from_id` pointing back at it — the
 * chain is walkable and nothing is overwritten.
 */
class RescheduleFollowUp
{
    use RunsInTransaction;

    /**
     * @param  array{due_at: string, note?: string|null}  $data
     */
    public function handle(LeadFollowUp $followUp, array $data, User $actor): LeadFollowUp
    {
        return $this->transaction(function () use ($followUp, $data, $actor): LeadFollowUp {
            /** @var LeadFollowUp $locked */
            $locked = LeadFollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [FollowUpStatus::Pending, FollowUpStatus::Missed], true)) {
                throw new DomainException("A {$locked->status->label()} follow-up cannot be rescheduled.");
            }

            $newDue = Carbon::parse($data['due_at']);

            $locked->forceFill(['status' => FollowUpStatus::Rescheduled])->save();

            $replacement = $locked->lead->followUps()->create([
                'title' => $locked->title,
                'type' => $locked->type,
                'priority' => $locked->priority,
                'status' => FollowUpStatus::Pending,
                'assigned_to' => $locked->assigned_to,
                'rescheduled_from_id' => $locked->id,
                'due_at' => $newDue,
                'note' => $data['note'] ?? $locked->note,
                'created_by' => $actor->id,
            ]);

            $lead = $locked->lead;
            $lead->recordActivity(
                LeadActivityType::FollowUpRescheduled,
                'Follow-up moved from '.$locked->due_at->format('d M Y H:i').' to '.$newDue->format('d M Y H:i'),
                ['from_follow_up_id' => $locked->id, 'to_follow_up_id' => $replacement->id],
                $actor,
            );
            $lead->syncNextAction();

            Log::info('lead.follow_up_rescheduled', [
                'lead_id' => $lead->id, 'from' => $locked->id, 'to' => $replacement->id, 'by' => $actor->id,
            ]);

            return $replacement;
        });
    }
}
