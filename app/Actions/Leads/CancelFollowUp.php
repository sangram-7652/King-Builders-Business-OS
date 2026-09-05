<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\FollowUpStatus;
use App\Enums\LeadActivityType;
use App\Exceptions\DomainException;
use App\Models\LeadFollowUp;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class CancelFollowUp
{
    use RunsInTransaction;

    public function handle(LeadFollowUp $followUp, User $actor, ?string $reason = null): LeadFollowUp
    {
        return $this->transaction(function () use ($followUp, $actor, $reason): LeadFollowUp {
            /** @var LeadFollowUp $locked */
            $locked = LeadFollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [FollowUpStatus::Pending, FollowUpStatus::Missed], true)) {
                throw new DomainException("A {$locked->status->label()} follow-up cannot be cancelled.");
            }

            $locked->forceFill([
                'status' => FollowUpStatus::Cancelled,
                'cancelled_at' => now(),
                'note' => $reason ? trim(($locked->note ? $locked->note.' — ' : '').'Cancelled: '.$reason) : $locked->note,
            ])->save();

            $lead = $locked->lead;
            $lead->recordActivity(
                LeadActivityType::FollowUpCancelled,
                trim($locked->type->label().' follow-up cancelled'.($reason ? " — {$reason}" : '')),
                ['follow_up_id' => $locked->id],
                $actor,
            );
            $lead->syncNextAction();

            Log::info('lead.follow_up_cancelled', [
                'lead_id' => $lead->id, 'follow_up_id' => $locked->id, 'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
