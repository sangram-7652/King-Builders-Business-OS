# Possession, Transfer & Ownership History (M10)

The final node of the legacy workflow: handing the plot over to the customer and
moving ownership. M10 *consumes* M6/M7/M8/M9 — it never modifies booking pricing,
registry records or historical buyer data, and it is not a financial system.

```
BOOKING → PAYMENT → COLLECTION → DOCUMENTS → REGISTRY → POSSESSION → TRANSFER / OWNERSHIP HISTORY
```

## Entities

| Table | Purpose |
| --- | --- |
| `possession_cases` | one per booking (unique); `POS-000001`; `eligibility_snapshot`; `status_before_hold`; `certificate_document_id` |
| `possession_clearances` | one row per `(case, category)` — FINANCIAL / DOCUMENT / LEGAL / SITE; PENDING → CLEARED / REJECTED / WAIVED |
| `possession_appointments` | appointment history — reschedule supersedes (`superseded_at`), never overwrites |
| `possession_inspections` | many per case; latest row is the current outcome; a FAILED / REINSPECTION_REQUIRED latest blocks handover |
| `possession_handovers` | one per case (unique); READY_FOR_HANDOVER → HANDOVER → ACKNOWLEDGEMENT → COMPLETED |
| `transfer_requests` | `TRF-000001`; DRAFT → SUBMITTED → UNDER_REVIEW ⇄ DOCUMENTS_PENDING → APPROVED → COMPLETED (or REJECTED / CANCELLED) |
| `plot_ownership_history` | append-only ownership ledger — `ended_at` NULL = current owner |
| `buyer_nominees` | nominee foundation — append-only supersession; **not** owners |
| `possession_activities` | append-only timeline (same pattern as M5 / M8 / M9) |

## Enums

`PossessionCaseStatus` (NotStarted / EligibilityPending / Ready / Scheduled / Inspection / ReadyForHandover / Completed / OnHold / Cancelled) ·
`ClearanceCategory` (Financial / Document / Legal / Site) ·
`ClearanceStatus` (Pending / Cleared / Rejected / Waived — `isSatisfied()` = Cleared|Waived) ·
`InspectionStatus` (Pending / Passed / Failed / ReinspectionRequired — `blocksHandover()`) ·
`PossessionHandoverStatus` (ReadyForHandover / Handover / Acknowledgement / Completed) ·
`TransferRequestStatus` (8 states + `transitionMap()` + `isBlockingActive()`) ·
`TransferType` (OwnerChange / NomineeChange / FamilyTransfer / SaleTransfer / LegalTransfer / Other — `movesOwnership()`) ·
`OwnershipType` (Allotment / Transfer) ·
`PossessionActivityType` (25 cases across possession / transfer / ownership / nominee)

`PlotStatus` gains one case — **`PossessionCompleted`** — reachable only from
`Booked` / `Sold`, terminal. Nothing else in the M4 lifecycle changed;
`Transferred` stays a controlled state that the generic action still refuses.

## Possession eligibility

`App\Services\Possession\PossessionEligibilityService::evaluate(Booking)` — the
single authority (no duplication in Livewire/Blade), mutates nothing. Checks:
booking confirmed, plot valid + active, **M9 registry case COMPLETED**, required
booking documents verified (M9 checklist), no overdue balance (M8), ≥ N%
collected (M7). Thresholds in `config/possession.php`
(`POSSESSION_REQUIRE_REGISTRY`, `POSSESSION_BLOCK_ON_OVERDUE`,
`POSSESSION_REQUIRED_PAID_PERCENT`). Decides READY ⇄ ELIGIBILITY_PENDING.

## Clearance system

`InitiatePossessionCaseAction` seeds the four `possession_clearances` rows.
`RecordClearanceAction` (`possession.clear`) records PENDING → CLEARED /
REJECTED / WAIVED:

- **Nothing is waived automatically** — WAIVED requires a reason.
- **FINANCIAL** reads M7/M8 truth (`PaymentLedger::bookingOutstanding` /
  `bookingOverdue`) and stores the figures in `snapshot`. It **cannot be
  CLEARED** while outstanding exceeds `possession.financial_clearance.max_outstanding`
  — an explicit WAIVED decision is the only exception. There is no second balance
  engine.

## Possession checklist

`PossessionChecklistService::for(PossessionCase)` aggregates readiness for
handover — every eligibility prerequisite + every REQUIRED clearance satisfied +
the latest inspection PASSED. Config-driven, nothing stored, no percentage
persisted. `markReadyForHandover()` refuses while the checklist is incomplete.

## Inspection workflow

`RecordInspectionAction` (`possession.inspect`) appends an immutable
`possession_inspections` row (PASSED / FAILED / REINSPECTION_REQUIRED), optionally
uploading a `SITE_INSPECTION_REPORT` document. A FAILED latest inspection pulls an
advanced case back to INSPECTION and blocks `PossessionHandoverAction` until a
later PASSED inspection is recorded.

## Handover workflow

`READY_FOR_HANDOVER → HANDOVER → ACKNOWLEDGEMENT → COMPLETED`
(`App\Actions\Possession\PossessionHandoverAction`, `possession.complete`):

1. `startHandover()` — records `handover_date`, `received_by`, receiver identity
   & relation; requires a PASSED inspection.
2. `recordAcknowledgement()` — uploads the `POSSESSION_ACK` document.
3. `complete()` — idempotent; moves the possession case to COMPLETED and the plot
   to `POSSESSION_COMPLETED` **atomically**. A completed handover is terminal and
   never duplicated.

## Possession certificate

`GeneratePossessionCertificateAction` (`possession.complete`) renders the
certificate via the shared M7/M9 dompdf + `Branding` architecture and stores it
as a versioned `POSSESSION_CERTIFICATE` document on the booking
(`certificate_document_id`). Idempotent — a case that already has a certificate
returns it unchanged. No second PDF engine.

## Transfer workflow

`CreateTransferRequestAction` (`transfer.create`) opens a DRAFT (`TRF-` sequence,
at most one open transfer per booking, `current_buyer_id` resolved from the
ownership ledger). `TransferWorkflowAction` drives submit / review / documents /
approve / reject / cancel, validated against `TransferRequestStatus::transitionMap()`.

**Approval** (`transfer.approve`) runs `TransferEligibilityService`:
booking confirmed, a distinct incoming buyer (ownership-moving types), no other
active transfer, financial clearance (outstanding / overdue within
`config/transfer.php` unless a `financial_waiver_reason` is recorded), and the
required transfer documents (`TRANSFER_APPLICATION` / `TRANSFER_CONSENT` /
`TRANSFER_ID_PROOF`) VERIFIED. The evaluation is snapshotted onto the request.

**Completion** (`transfer.complete`, `CompleteTransferAction`) is fully
transactional:

1. lock the request; must be APPROVED (idempotent if already COMPLETED)
2. lock the plot row
3. reject if another transfer on the booking completed since this one was approved
4. ownership-moving types → `PlotOwnershipService::applyTransfer()`
5. mark COMPLETED + write the audit events

## Ownership history

`App\Services\Ownership\PlotOwnershipService` is the only writer:

- `ensureAllotment(Booking)` — lazily materialises the original period(s) from
  the M6 `booking_buyers` pivot (idempotent). Called when a possession case or
  transfer is opened.
- `applyTransfer(TransferRequest)` — closes the active period(s) (`ended_at`) and
  opens a new `Transfer` period for the incoming buyer.

Rules enforced: append-only (no destructive history), `booking_buyers` is never
mutated, no overlapping active period for the same `(booking, buyer)`, exactly
one active period after a transfer, one active `is_primary` period per plot. A
nominee change moves nothing here.

## Nominee foundation

`buyer_nominees` + `RecordBuyerNomineeAction` (`transfer.create`). A nominee is
never an owner and never appears in `plot_ownership_history`; a change supersedes
the active row (append-only, `superseded_by`).

## Customer 360 / Plot 360

- **Buyer detail** adds an *Ownership, transfers & nominees* card (plot ownership
  periods, incoming/outgoing transfers, nominees) — all from existing relations.
- **Plot detail** adds a *Possession & ownership* card (possession case, full
  ownership history with the current owner flagged, transfers).
- **Booking detail** gets *Possession* and *Transfers* action buttons.

## Permissions (M1 RBAC)

`possession.{view,create,schedule,inspect,complete,clear}` ·
`transfer.{view,create,review,approve,complete}` · `ownership.view`

Full set on **Possession Manager**; read/create slices on **Sales Manager** and
**Registry Manager**. `transfer.approve` / `transfer.complete` /
`possession.complete` are **never** on a normal sales role.

## Concurrency & idempotency

| Risk | Guard |
| --- | --- |
| Possession / transfer case number, one-per-booking | `SequenceGenerator` + `unique(booking_id)` / `unique(*_number)` + `QueryException` → return existing |
| Possession completion / plot state | case row `lockForUpdate`, status re-read in-transaction, idempotent; plot state via the locked `ChangePlotStatus` |
| Duplicate completed handover | idempotent return on `Completed` + `unique(possession_case_id)` |
| Two approved transfers | request + plot row `lockForUpdate`; a stale second transfer is rejected on the post-approval conflict check |
| Overlapping ownership | `PlotOwnershipService` closes the active period(s) under lock and rejects a duplicate active period for the incoming buyer |
| Duplicate certificate | idempotent return when `certificate_document_id` is set |

## Tests

`tests/Feature/Possession/` — 67 Pest tests (workflow, handover + certificate,
transfer, ownership history, security / IDOR / RBAC, concurrency, schema,
screens) covering the spec's 39 numbered cases.
