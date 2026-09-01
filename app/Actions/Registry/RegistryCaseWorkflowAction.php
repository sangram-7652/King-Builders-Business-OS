<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Actions\Documents\UploadDocumentAction;
use App\Enums\DocumentActivityType;
use App\Enums\HandoverStatus;
use App\Enums\RegistryCaseStatus;
use App\Exceptions\DomainException;
use App\Models\DocumentHandover;
use App\Models\Masters\DocumentType;
use App\Models\RegistryCase;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * The remaining registry-case transitions (M9): mark in-process, complete
 * (records the registered document + opens the handover), put on hold / resume,
 * and cancel. Each is idempotent for its target state.
 */
class RegistryCaseWorkflowAction
{
    use RunsInTransaction;

    public function __construct(private readonly UploadDocumentAction $upload) {}

    public function markInProcess(RegistryCase $case, User $actor): RegistryCase
    {
        return $this->transaction(function () use ($case, $actor): RegistryCase {
            $locked = $this->lock($case);

            if ($locked->status === RegistryCaseStatus::InProcess) {
                return $locked;
            }

            if ($locked->status !== RegistryCaseStatus::Scheduled) {
                throw new DomainException('Only a SCHEDULED registry case can move to in-process.');
            }

            $locked->forceFill(['status' => RegistryCaseStatus::InProcess])->save();
            $this->log($locked, DocumentActivityType::RegistryInProcess, "Registry {$locked->case_number} is in process.", $actor);

            return $locked;
        });
    }

    /**
     * @param  array{registered_document_number: string, registration_date: string, registry_office?: string|null, notes?: string|null}  $data
     */
    public function complete(RegistryCase $case, array $data, User $actor, ?UploadedFile $registeredDeed = null): RegistryCase
    {
        if (! $actor->can('registry.complete')) {
            throw new DomainException('You are not authorised to complete a registry case.');
        }

        if (trim((string) ($data['registered_document_number'] ?? '')) === '') {
            throw new DomainException('The registered document number is required.');
        }

        return $this->transaction(function () use ($case, $data, $actor, $registeredDeed): RegistryCase {
            $locked = $this->lock($case);

            if ($locked->status === RegistryCaseStatus::Completed) {
                return $locked->load('handover');
            }

            if (! in_array($locked->status, [RegistryCaseStatus::Scheduled, RegistryCaseStatus::InProcess], true)) {
                throw new DomainException("A {$locked->status->label()} registry case cannot be completed.");
            }

            $locked->forceFill([
                'status' => RegistryCaseStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->id,
                'registered_document_number' => trim($data['registered_document_number']),
                'registration_date' => $data['registration_date'],
                'registry_office' => $data['registry_office'] ?? $locked->registry_office,
                'notes' => $data['notes'] ?? $locked->notes,
            ])->save();

            if ($registeredDeed !== null) {
                $type = DocumentType::query()->where('code', 'REGISTERED_DEED')->firstOrFail();
                $this->upload->handle($locked->booking, $type, $registeredDeed, $actor, [
                    'title' => "Registered deed {$data['registered_document_number']}",
                ]);
            }

            // Open the handover (idempotent — unique booking_id).
            DocumentHandover::firstOrCreate(
                ['booking_id' => $locked->booking_id],
                [
                    'registry_case_id' => $locked->id,
                    'status' => HandoverStatus::RegistryCompleted,
                    'created_by' => $actor->id,
                ],
            );

            $this->log($locked, DocumentActivityType::RegistryCompleted,
                "Registry {$locked->case_number} completed — document {$locked->registered_document_number}.", $actor);

            Log::info('registry_case.completed', ['registry_case_id' => $locked->id, 'by' => $actor->id]);

            return $locked->load('handover', 'booking');
        });
    }

    public function putOnHold(RegistryCase $case, string $reason, User $actor): RegistryCase
    {
        if (trim($reason) === '') {
            throw new DomainException('A hold reason is required.');
        }

        return $this->transaction(function () use ($case, $reason, $actor): RegistryCase {
            $locked = $this->lock($case);

            if ($locked->status === RegistryCaseStatus::OnHold) {
                return $locked;
            }

            if (! $locked->status->isActive()) {
                throw new DomainException("A {$locked->status->label()} registry case cannot be put on hold.");
            }

            $locked->forceFill([
                'status' => RegistryCaseStatus::OnHold,
                'status_before_hold' => $locked->status->value,
                'hold_reason' => trim($reason),
            ])->save();

            $this->log($locked, DocumentActivityType::RegistryOnHold, "Registry {$locked->case_number} put on hold: ".trim($reason), $actor);

            return $locked;
        });
    }

    public function resume(RegistryCase $case, User $actor): RegistryCase
    {
        return $this->transaction(function () use ($case, $actor): RegistryCase {
            $locked = $this->lock($case);

            if ($locked->status !== RegistryCaseStatus::OnHold) {
                return $locked;
            }

            $back = RegistryCaseStatus::tryFrom((string) $locked->status_before_hold) ?? RegistryCaseStatus::EligibilityPending;
            $locked->forceFill([
                'status' => $back,
                'status_before_hold' => null,
                'hold_reason' => null,
            ])->save();

            $this->log($locked, DocumentActivityType::RegistryResumed, "Registry {$locked->case_number} resumed ({$back->label()}).", $actor);

            return $locked;
        });
    }

    public function cancel(RegistryCase $case, string $reason, User $actor): RegistryCase
    {
        return $this->transaction(function () use ($case, $reason, $actor): RegistryCase {
            $locked = $this->lock($case);

            if ($locked->status === RegistryCaseStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status === RegistryCaseStatus::Completed) {
                throw new DomainException('A completed registry case cannot be cancelled.');
            }

            $locked->forceFill([
                'status' => RegistryCaseStatus::Cancelled,
                'cancellation_reason' => $reason ?: null,
            ])->save();

            $this->log($locked, DocumentActivityType::RegistryCancelled, "Registry {$locked->case_number} cancelled.", $actor);

            return $locked;
        });
    }

    private function lock(RegistryCase $case): RegistryCase
    {
        /** @var RegistryCase $locked */
        $locked = RegistryCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
        $locked->load('booking');

        return $locked;
    }

    private function log(RegistryCase $case, DocumentActivityType $type, string $description, User $actor): void
    {
        DocumentTimeline::record($type, $description, $case->booking, null, ['registry_case_id' => $case->id], $actor);
    }
}
