# Registry & Documentation Management (M9)

Buyer and booking **document management**, the **agreement lifecycle**, a
**registry eligibility engine**, **registry cases** and **document handover**.
M9 *consumes* M6/M7/M8 state — it never modifies historical booking pricing and
never becomes a second financial system.

```
M6 BOOKING → M7 PAYMENT → M8 COLLECTION → M9 DOCUMENTS → AGREEMENT → REGISTRY → HANDOVER
```

## Two document worlds, one engine

Buyer KYC documents and booking documents are **conceptually separate** but run
through the same polymorphic engine:

| `documentable` | Typical documents |
| --- | --- |
| `Buyer` | Aadhaar, PAN, Address proof, Photograph, Bank proof |
| `Booking` | Booking form, Booking agreement, Payment proof, Registry doc, Registered deed, Handover ack, … |

The agreement's prepared/signed PDF and the handover acknowledgement are stored
as ordinary **booking** documents (`BOOKING_AGREEMENT` / `HANDOVER_ACK` slots)
and linked back via `agreements.document_id` / `document_handovers.document_id`,
so versioning + private storage are shared, not reinvented.

## Entities

| Table | Purpose |
| --- | --- |
| `document_types` (M2, extended) | + `applies_to` (buyer/booking), `default_required`, `supports_expiry` |
| `document_requirements` | configurable checklist — `project_id` NULL = global rule; a project row overrides it |
| `documents` | one row per `(documentable, document_type)` (`documents_slot_unique`); holds workflow state; soft-deletes |
| `document_versions` | **immutable** file versions — `unique(document_id, version)`; `path` + `checksum`, private disk only |
| `agreements` | `AGR-000001`; `terms_snapshot` (frozen M6 terms); `document_id` → versioned files |
| `registry_cases` | one per booking (unique); `REG-000001`; `eligibility_snapshot`; `status_before_hold` |
| `registry_appointments` | appointment history — reschedule supersedes (`superseded_at`), never overwrites |
| `registry_expenses` | `DECIMAL(15,2)` tracking only — RECORDED → APPROVED, **never posted to the booking** |
| `document_handovers` | one per booking (unique), opened at registry completion; terminal once handed over |
| `document_activities` | append-only timeline (same pattern as M5 `lead_activities` / M8 `collection_activities`) |

## Enums

`DocumentStatus` (Pending / Uploaded / UnderReview / Verified / Rejected / Expired) ·
`DocumentScope` (Buyer / Booking) ·
`AgreementStatus` (Draft / Prepared / Sent / Signed / Approved / Cancelled) ·
`AgreementType` (BookingAgreement / SaleAgreement / AllotmentLetter / Other) ·
`RegistryCaseStatus` (NotStarted / EligibilityPending / Ready / Scheduled / InProcess / Completed / Cancelled / OnHold) ·
`RegistryExpenseType` (StampDuty / RegistrationFee / Documentation / Processing / Other) ·
`HandoverStatus` (RegistryCompleted / DocumentsReady / HandoverScheduled / HandedOver) ·
`DocumentActivityType` (28 cases across document_* / agreement_* / registry_* / handover_*)

## Versioning

Uploads never overwrite. `App\Actions\Documents\UploadDocumentAction`:

1. computes the file `sha256` first; an identical re-upload of the current
   version is **idempotent** (returns without a new version)
2. locks the `documents` row `FOR UPDATE`, takes `max(version) + 1`
   (`CreatesDocumentVersion`), writes the file, appends an immutable
   `document_versions` row and repoints `current_version_id`
3. resets the slot to `UPLOADED` and clears verify/reject metadata

A verified/signed file is therefore preserved as an older version — a re-upload
just adds `v2` and moves the slot back to `UPLOADED` for re-review.

## Storage & security

- Private disk `documents` (`config/filesystems.php`): `visibility: private`,
  `serve: false`, **no `url`** — never web-served.
- Path = `{snake documentable}/{document id}/v{n}-{40 random}.{ext}` — a
  non-guessable component in every path.
- The **only** way to a file is `App\Http\Controllers\DocumentDownloadController`,
  guarded by `DocumentPolicy::download`: the module permission **and**
  `$user->can('view', $buyerOrBooking)`. Guessing a document / version id (IDOR)
  fails — a user who cannot see the buyer cannot reach that buyer's documents,
  and a mismatched version id 404s.
- Livewire actions resolve documents through `scopedDocument()`, which filters by
  the current documentable before `findOrFail` — a foreign id 404s.

## Agreement workflow

`DRAFT → PREPARED → SENT → SIGNED → APPROVED` (`→ CANCELLED` any time before
`APPROVED`), validated against `AgreementStatus::transitionMap()`.

- **Prepare** validates the booking (confirmed, has buyers, has a plot), freezes
  the M6 terms into `terms_snapshot` (read-only — the price snapshot itself is
  untouched) and renders a branded PDF as `v1` of the `BOOKING_AGREEMENT` slot.
  Re-preparing adds `v2`.
- **Sign** uploads the signed scan as a **new version** (the prepared PDF is
  never replaced) and records `signed_by` / `signed_at`.
- **Approve** needs `agreements.approve` and only from `SIGNED`.

## Registry eligibility engine

`App\Services\Registry\RegistryEligibilityService::evaluate(Booking)` is the
single place this logic lives (no duplication in Livewire/Blade). It returns a
structured `EligibilityResult` (`eligible` + `checks[]` + `reasons()`):

| Check | Source |
| --- | --- |
| booking confirmed | M6 |
| plot valid, active, BOOKED | M4 |
| required buyer KYC documents verified | `DocumentChecklistService::forBuyer` |
| required booking documents verified | `DocumentChecklistService::forBooking` |
| agreement at least SIGNED | M9 |
| no overdue balance | M8 `PaymentLedger::bookingOverdue` (if `block_on_overdue`) |
| ≥ N % collected | M7 `PaymentLedger::bookingPaid` vs `final_amount` |

Thresholds live in `config/registry.php` (`REGISTRY_REQUIRED_PAID_PERCENT`,
`REGISTRY_BLOCK_ON_OVERDUE`).

## Registry case workflow

`InitiateRegistryCaseAction` opens one case per booking (`REG-` sequence,
unique `booking_id`, idempotent under a race), runs the engine and sets
`READY` vs `ELIGIBILITY_PENDING`, snapshotting the evaluation.
`RefreshRegistryEligibilityAction` re-runs it and flips
`ELIGIBILITY_PENDING ⇄ READY` only — a `SCHEDULED`/`IN_PROCESS`/`COMPLETED` case
is never downgraded.

Scheduling (`registry.schedule`) requires a `READY` case + an office and writes a
`registry_appointments` row; rescheduling supersedes the live appointment
(history preserved). Completion (`registry.complete`) requires a
`registered_document_number`, optionally uploads the `REGISTERED_DEED`, and opens
the `document_handovers` row (`firstOrCreate` — idempotent). Hold/resume restores
the pre-hold status; a `COMPLETED` case cannot be cancelled.

## Registry expenses

`registry_expenses` is **tracking only** — `Money` (bcmath) → `DECIMAL(15,2)`,
never float, `RECORDED → APPROVED`, idempotent approval, and **nothing is ever
posted to the M6/M7 booking financials**. There is no accounting ledger.

## Handover workflow

`REGISTRY_COMPLETED → DOCUMENTS_READY → HANDOVER_SCHEDULED → HANDED_OVER`.
Completion (`handover.complete`) requires `received_by`, uploads the
`HANDOVER_ACK` document, and is idempotent — no duplicate completed handover. A
completed handover can only be undone through `HandoverWorkflowAction::reverse`
(also `handover.complete`), which records `reversed_by` / `reversal_reason`.

## Completeness

`DocumentChecklistService` derives `required / received / verified / rejected /
pending` on every read from requirements + documents. **No completion percentage
is ever stored.** `ChecklistResult::isComplete()` = every required slot is
`VERIFIED`.

## Permissions (M1 RBAC)

`documents.{view,upload,verify,reject,download,delete}` ·
`agreements.{view,create,update,approve}` ·
`registry.{view,create,update,schedule,complete}` ·
`registry_expenses.{view,create,approve}` ·
`handover.{view,create,complete}`

Seeded onto **Registry Manager** (full set), with read/partial slices for
**Sales Manager** and **Accountant**. A `VERIFIED` document is protected from
`documents.delete`.

## Concurrency & idempotency

| Risk | Guard |
| --- | --- |
| document version numbers | `documents` row `lockForUpdate` + `unique(document_id, version)` |
| duplicate version from identical re-upload | `sha256` compare against `current_version` |
| registry case number / one-per-booking | `SequenceGenerator` + `unique(booking_id)` + `QueryException` catch → return existing |
| agreement number | `SequenceGenerator` + `unique(agreement_number)` |
| registry completion / handover completion | row `lockForUpdate`, re-read status inside the transaction, idempotent for the target state |
| handover opened once | `DocumentHandover::firstOrCreate(booking_id)` + `unique(booking_id)` |

## Scheduled work

`ExpireDocumentsJob` (`ShouldBeUnique`) runs daily at 02:00
(`routes/console.php`, name `expire-documents`) → `ExpireDocumentsAction` moves
documents past `expires_at` to `EXPIRED`.

## Screens

`/documents` (document dashboard) · `/registry` (registry dashboard) ·
`/buyers/{buyer}/documents` · `/bookings/{booking}/documents` (checklist +
agreement) · `/bookings/{booking}/registry` (eligibility, case, appointments,
expenses, handover) · `/documents/{document}/versions/{version}/download`.
