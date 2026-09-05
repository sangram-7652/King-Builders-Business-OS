<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\DocumentStatus;
use App\Enums\PossessionCaseStatus;
use App\Enums\RegistryCaseStatus;
use App\Enums\TransferRequestStatus;
use App\Queries\Reports\ReportFilterScope;
use App\Support\Reports\ReportFilterData;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational (M9/M10) pending counts for the executive dashboard's
 * "Attention required" section (M11.2).
 *
 * Every count is a current-state snapshot on real M9/M10 rows — no invented
 * data. Registry / possession / transfer are booking-linked, so they respect
 * the project and salesperson filters (not the date window). Document
 * verification is an org-wide queue (buyer KYC documents are not project-bound)
 * and is intentionally not project-scoped.
 */
class OperationsAnalytics
{
    public function registryPending(ReportFilterData $filters): int
    {
        return $this->bookingLinked('registry_cases', $filters)
            ->whereNotIn('registry_cases.status', $this->terminal(RegistryCaseStatus::cases()))
            ->count();
    }

    public function possessionPending(ReportFilterData $filters): int
    {
        return $this->bookingLinked('possession_cases', $filters)
            ->whereNotIn('possession_cases.status', $this->terminal(PossessionCaseStatus::cases()))
            ->count();
    }

    /** Open transfers (submitted → approved) — the ones that block a new transfer. */
    public function transferPending(ReportFilterData $filters): int
    {
        $blocking = array_values(array_map(
            fn (TransferRequestStatus $s) => $s->value,
            array_filter(TransferRequestStatus::cases(), fn (TransferRequestStatus $s) => $s->isBlockingActive()),
        ));

        return $this->bookingLinked('transfer_requests', $filters)
            ->whereIn('transfer_requests.status', $blocking)
            ->count();
    }

    public function transferAwaitingApproval(ReportFilterData $filters): int
    {
        return $this->bookingLinked('transfer_requests', $filters)
            ->where('transfer_requests.status', TransferRequestStatus::UnderReview->value)
            ->count();
    }

    /** Uploaded / under-review documents anywhere in the system. Org-wide. */
    public function documentsAwaitingVerification(ReportFilterData $filters): int
    {
        return DB::table('documents')
            ->whereNull('deleted_at')
            ->whereIn('status', [DocumentStatus::Uploaded->value, DocumentStatus::UnderReview->value])
            ->count();
    }

    /**
     * A booking-linked table joined to `bookings` and scoped by project +
     * salesperson.
     */
    private function bookingLinked(string $table, ReportFilterData $filters): Builder
    {
        $query = DB::table($table)->join('bookings', 'bookings.id', '=', "{$table}.booking_id")
            ->whereNull('bookings.deleted_at');
        ReportFilterScope::project($query, $filters, 'bookings.project_id');
        ReportFilterScope::salesperson($query, $filters, 'bookings.created_by');

        return $query;
    }

    /**
     * @param  list<RegistryCaseStatus|PossessionCaseStatus>  $cases
     * @return list<string>
     */
    private function terminal(array $cases): array
    {
        return array_values(array_map(
            fn ($s) => $s->value,
            array_filter($cases, fn ($s) => $s->isTerminal()),
        ));
    }
}
