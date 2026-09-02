<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Actions\Documents\UploadDocumentAction;
use App\Enums\InspectionStatus;
use App\Enums\PossessionActivityType;
use App\Enums\PossessionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\Masters\DocumentType;
use App\Models\PossessionCase;
use App\Models\PossessionInspection;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Records a site inspection result (M10). `possession.inspect`. Each call is a
 * new immutable row — a FAILED / REINSPECTION_REQUIRED latest inspection blocks
 * the final handover until a later PASSED inspection.
 */
class RecordInspectionAction
{
    use RunsInTransaction;

    public function __construct(private readonly UploadDocumentAction $upload) {}

    /**
     * @param  array{status: string, inspection_date: string, inspected_by?: int|null, remarks?: string|null}  $data
     */
    public function handle(PossessionCase $case, array $data, User $actor, ?UploadedFile $report = null): PossessionInspection
    {
        if (! $actor->can('possession.inspect')) {
            throw new DomainException('You are not authorised to record site inspections.');
        }

        $status = InspectionStatus::tryFrom((string) ($data['status'] ?? ''));

        if ($status === null || $status === InspectionStatus::Pending) {
            throw new DomainException('A concrete inspection outcome (passed / failed / re-inspection) is required.');
        }

        return $this->transaction(function () use ($case, $data, $actor, $report, $status): PossessionInspection {
            /** @var PossessionCase $locked */
            $locked = PossessionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('booking');

            if (! in_array($locked->status, [PossessionCaseStatus::Scheduled, PossessionCaseStatus::Inspection, PossessionCaseStatus::ReadyForHandover], true)) {
                throw new DomainException("A {$locked->status->label()} possession case cannot record an inspection.");
            }

            $reportDocument = null;
            if ($report !== null) {
                $type = DocumentType::query()->where('code', 'SITE_INSPECTION_REPORT')->first();
                if ($type !== null) {
                    $reportDocument = $this->upload->handle($locked->booking, $type, $report, $actor, [
                        'title' => "Site inspection report — {$locked->case_number}",
                    ]);
                }
            }

            $inspection = PossessionInspection::create([
                'possession_case_id' => $locked->id,
                'inspected_by' => $data['inspected_by'] ?? $actor->id,
                'inspection_date' => $data['inspection_date'],
                'status' => $status,
                'remarks' => $data['remarks'] ?? null,
                'report_document_id' => $reportDocument?->id,
                'created_by' => $actor->id,
            ]);

            $update = ['inspection_at' => now()];
            if ($locked->status === PossessionCaseStatus::Scheduled) {
                $update['status'] = PossessionCaseStatus::Inspection;
            }
            // A failing inspection pulls a case that had advanced back to INSPECTION.
            if ($status->blocksHandover() && $locked->status === PossessionCaseStatus::ReadyForHandover) {
                $update['status'] = PossessionCaseStatus::Inspection;
            }
            $locked->forceFill($update)->save();

            PossessionTimeline::record(
                PossessionActivityType::InspectionRecorded,
                "Site inspection {$status->label()} on {$locked->case_number}.",
                $locked->booking, $locked->booking?->plot, null,
                ['possession_case_id' => $locked->id, 'inspection_id' => $inspection->id, 'status' => $status->value],
                $actor,
            );

            Log::info('possession_inspection.recorded', ['possession_case_id' => $locked->id, 'status' => $status->value, 'by' => $actor->id]);

            return $inspection;
        });
    }
}
