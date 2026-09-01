<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Enums\DocumentActivityType;
use App\Enums\RegistryCaseStatus;
use App\Exceptions\DomainException;
use App\Models\RegistryAppointment;
use App\Models\RegistryCase;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Schedules (or reschedules) the registry appointment (M9). Rescheduling
 * supersedes the current appointment and inserts a new row — the full history
 * is preserved. `registry.schedule`.
 */
class ScheduleRegistryAppointmentAction
{
    use RunsInTransaction;

    /**
     * @param  array{scheduled_at: string, registry_office: string, appointment_reference?: string|null, responsible_user_id?: int|null, notes?: string|null, reason?: string|null}  $data
     */
    public function handle(RegistryCase $case, array $data, User $actor, bool $reschedule = false): RegistryCase
    {
        if (! $actor->can('registry.schedule')) {
            throw new DomainException('You are not authorised to schedule registry appointments.');
        }

        if (trim((string) ($data['registry_office'] ?? '')) === '') {
            throw new DomainException('The registry office is required.');
        }

        return $this->transaction(function () use ($case, $data, $actor, $reschedule): RegistryCase {
            /** @var RegistryCase $locked */
            $locked = RegistryCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('liveAppointment');

            if (! $reschedule && $locked->status !== RegistryCaseStatus::Ready) {
                throw new DomainException("Only a READY registry case can be scheduled (this one is {$locked->status->label()}).");
            }

            if ($reschedule && ! in_array($locked->status, [RegistryCaseStatus::Scheduled, RegistryCaseStatus::InProcess], true)) {
                throw new DomainException("A {$locked->status->label()} registry case cannot be rescheduled.");
            }

            if ($reschedule && $locked->liveAppointment !== null) {
                $locked->liveAppointment->forceFill([
                    'superseded_at' => now(),
                    'reschedule_reason' => $data['reason'] ?? null,
                ])->save();
            }

            $appointment = RegistryAppointment::create([
                'registry_case_id' => $locked->id,
                'scheduled_at' => $data['scheduled_at'],
                'registry_office' => trim($data['registry_office']),
                'appointment_reference' => $data['appointment_reference'] ?? null,
                'responsible_user_id' => $data['responsible_user_id'] ?? $locked->appointment_owner_id,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $locked->forceFill([
                'status' => RegistryCaseStatus::Scheduled,
                'scheduled_at' => $appointment->scheduled_at,
                'registry_office' => $appointment->registry_office,
                'appointment_reference' => $appointment->appointment_reference,
                'appointment_owner_id' => $appointment->responsible_user_id,
            ])->save();

            DocumentTimeline::record(
                $reschedule ? DocumentActivityType::RegistryRescheduled : DocumentActivityType::RegistryScheduled,
                "Registry {$locked->case_number} ".($reschedule ? 'rescheduled' : 'scheduled')." for {$appointment->scheduled_at->format('d M Y H:i')} at {$appointment->registry_office}.",
                $locked->booking()->first(), null,
                ['registry_case_id' => $locked->id, 'appointment_id' => $appointment->id],
                $actor,
            );

            Log::info('registry_case.scheduled', ['registry_case_id' => $locked->id, 'reschedule' => $reschedule, 'by' => $actor->id]);

            return $locked->load('liveAppointment', 'appointments');
        });
    }
}
