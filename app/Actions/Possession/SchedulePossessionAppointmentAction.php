<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Enums\PossessionActivityType;
use App\Enums\PossessionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\PossessionAppointment;
use App\Models\PossessionCase;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Schedules (or reschedules) the possession appointment (M10). Rescheduling
 * supersedes the current appointment and inserts a new row — the full history
 * is preserved. `possession.schedule`.
 */
class SchedulePossessionAppointmentAction
{
    use RunsInTransaction;

    /**
     * @param  array{scheduled_at: string, site_location: string, assigned_to?: int|null, notes?: string|null, reason?: string|null}  $data
     */
    public function handle(PossessionCase $case, array $data, User $actor, bool $reschedule = false): PossessionCase
    {
        if (! $actor->can('possession.schedule')) {
            throw new DomainException('You are not authorised to schedule possession appointments.');
        }

        if (trim((string) ($data['site_location'] ?? '')) === '') {
            throw new DomainException('The site / location is required.');
        }

        return $this->transaction(function () use ($case, $data, $actor, $reschedule): PossessionCase {
            /** @var PossessionCase $locked */
            $locked = PossessionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('liveAppointment', 'booking');

            if (! $reschedule && $locked->status !== PossessionCaseStatus::Ready) {
                throw new DomainException("Only a READY possession case can be scheduled (this one is {$locked->status->label()}).");
            }

            if ($reschedule && ! in_array($locked->status, [PossessionCaseStatus::Scheduled, PossessionCaseStatus::Inspection], true)) {
                throw new DomainException("A {$locked->status->label()} possession case cannot be rescheduled.");
            }

            if ($reschedule && $locked->liveAppointment !== null) {
                $locked->liveAppointment->forceFill([
                    'superseded_at' => now(),
                    'reschedule_reason' => $data['reason'] ?? null,
                ])->save();
            }

            $appointment = PossessionAppointment::create([
                'possession_case_id' => $locked->id,
                'scheduled_at' => $data['scheduled_at'],
                'site_location' => trim($data['site_location']),
                'assigned_to' => $data['assigned_to'] ?? $locked->assigned_to,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $locked->forceFill([
                'status' => PossessionCaseStatus::Scheduled,
                'scheduled_at' => $appointment->scheduled_at,
                'site_location' => $appointment->site_location,
                'assigned_to' => $appointment->assigned_to,
            ])->save();

            PossessionTimeline::record(
                $reschedule ? PossessionActivityType::PossessionRescheduled : PossessionActivityType::PossessionScheduled,
                "Possession {$locked->case_number} ".($reschedule ? 'rescheduled' : 'scheduled')." for {$appointment->scheduled_at->format('d M Y H:i')} at {$appointment->site_location}.",
                $locked->booking, $locked->booking?->plot, null,
                ['possession_case_id' => $locked->id, 'appointment_id' => $appointment->id],
                $actor,
            );

            Log::info('possession_case.scheduled', ['possession_case_id' => $locked->id, 'reschedule' => $reschedule, 'by' => $actor->id]);

            return $locked->load('liveAppointment', 'appointments');
        });
    }
}
