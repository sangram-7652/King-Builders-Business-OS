<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\FollowUpStatus;
use App\Enums\LeadActivityType;
use App\Models\LeadFollowUp;

/**
 * Flip PENDING follow-ups that are past their due time to MISSED (M13.1).
 *
 * Run by MarkMissedFollowUpsJob (hourly). Idempotent — a
 * follow-up already MISSED is skipped, so re-running never double-records.
 * `$grace` (minutes) keeps "due 2 minutes ago" from flashing as missed.
 */
class MarkMissedFollowUps
{
    public function handle(int $graceMinutes = 30): int
    {
        $cutoff = now()->subMinutes($graceMinutes);
        $count = 0;

        LeadFollowUp::query()
            ->where('status', FollowUpStatus::Pending->value)
            ->where('due_at', '<', $cutoff)
            ->with('lead:id')
            ->chunkById(200, function ($followUps) use (&$count): void {
                foreach ($followUps as $followUp) {
                    $followUp->forceFill(['status' => FollowUpStatus::Missed])->save();

                    $followUp->lead?->recordActivity(
                        LeadActivityType::FollowUpMissed,
                        trim($followUp->type->label().' follow-up missed (was due '.$followUp->due_at->format('d M Y H:i').')'),
                        ['follow_up_id' => $followUp->id],
                        null,
                    );

                    $count++;
                }
            });

        return $count;
    }
}
