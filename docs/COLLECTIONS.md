# Collection Management, Dues & Aging (M8)

A **collection layer over M7** — it never becomes a second payment or accounting
system.

```
M6 BOOKING → M7 PAYMENT → M8 COLLECTION
```

## Financial source of truth

M8 has **no balance engine**. Every money figure is read from M7's
`App\Services\Payments\PaymentLedger`:

- Booking outstanding = `final_amount − Σ SUCCESS payments`
- Installment outstanding = `amount − Σ SUCCESS allocations`

M8 adds **workflow** (cases, follow-ups, promises, cheque bounces, penalties,
reminders, reports) around those numbers.

## Entities

| Table | Purpose |
| --- | --- |
| `collection_cases` | one per booking (unique), gets an owner + priority; **no money columns** |
| `collection_follow_ups` | logged collection interactions (separate from M5 `lead_follow_ups`); `idempotency_key` |
| `payment_promises` | promise to pay — NOT a payment; snapshots `outstanding_at_creation`; `idempotency_key` |
| `cheque_bounces` | one per payment; `bounce_date` / `bounce_reason` / `bank_charges` / `handled_by` |
| `bounce_penalties` | ASSESSED → APPROVED; `penalty_amount` / `reason` / `approved_by` — never posted to M7 |
| `collection_activities` | append-only timeline (same pattern as M5 `lead_activities`) |
| `collection_reminders` | in-app reminders, deduped by a composite unique key; no WhatsApp/SMS/Email |

## Enums

`CollectionCaseStatus` (Open / InProgress / PromiseToPay / Escalated / Resolved) ·
`CollectionPriority` (Low / Medium / High / Critical) ·
`AgingBucket` (0-30 / 31-60 / 61-90 / 91-180 / 180+) ·
`CollectionFollowUpOutcome` (Contacted / PromisedPayment / PaymentReceived / CallBack /
CustomerUnreachable / Dispute / ChequePending / Other) ·
`PromiseStatus` (Open / Kept / Broken / Cancelled) ·
`PenaltyStatus` (Assessed / Approved / Cancelled) ·
`CollectionActivityType` · `CollectionReminderType`.

## Dues calculation

Installment status is derived by `PaymentLedger::deriveInstallmentStatus` (M7):
`due_date > today` Upcoming · `== today` Due · `< today` unpaid Overdue ·
partial & not past due PartiallyPaid · partial & past due Overdue · fully
covered Paid. Balances are **never** hand-mutated.

## Aging calculation — `App\Services\Collections\AgingCalculator`

`days overdue = today − installment due date`. Paid / waived installments never
appear in aging; partially-paid ones contribute their **remaining outstanding**.
`bucketFor()` maps days → `AgingBucket` (null if not overdue).
`agingForBooking()` → `[bucket => Money]`; portfolio totals in the dashboard /
report services.

## Follow-up workflow

`ScheduleCollectionFollowUpAction` (idempotency key) creates a pending
follow-up; `CompleteCollectionFollowUpAction` records the outcome (mandatory),
optionally schedules the next as a **fresh row**, moves an Open case to
InProgress, and re-syncs the case. `collection_cases.next_follow_up_at` /
`last_follow_up_at` are denormalised pointers kept in sync.

## Promise workflow

`CreatePaymentPromiseAction`:

- amount `> 0` and `≤` the relevant M7 outstanding (installment or booking)
- **combined OPEN promises** for that scope may not exceed the outstanding —
  the `collection_cases` row is locked `FOR UPDATE` while this is checked
- snapshots `outstanding_at_creation`, moves the case to PROMISE_TO_PAY

`EvaluatePaymentPromisesAction` (run by the payment observer + nightly):

- **KEPT** when `outstanding_at_creation − current outstanding ≥ promised_amount`
  (a real SUCCESS M7 payment landed) — never from a button
- **BROKEN** when `promise_date` has passed unfulfilled

`CancelPaymentPromiseAction` — OPEN → CANCELLED only.

## Cheque bounce workflow — `RecordChequeBounceAction`

Builds on M7 cheque support. One bounce record per payment (idempotent).

- bounce while **PENDING** → M7 `VerifyPaymentAction(FAILED)` (payment kept, `cheque_status` BOUNCED)
- bounce after **CLEARED** → M7 `ReversePaymentAction(systemInitiated: true)` — the
  caller holds `cheques.bounce`; the payment is kept as **REVERSED**, its receipt voided
- opens / links a collection case, records the timeline event and a reminder

`MarkChequeClearedAction` clears a pending cheque via M7 `VerifyPaymentAction(SUCCESS)`.

## Penalty workflow

`AssessBouncePenaltyAction` (`penalties.assess`) → **ASSESSED** ·
`ApproveBouncePenaltyAction` (`penalties.approve`, idempotent) → **APPROVED**.
Explicit, authorised, traceable (`assessed_by/at`, `approved_by/at`). It is
**not** posted to the M7 booking total — accounting posting is a later milestone.

## Dashboard — `CollectionDashboardService`

Total receivable / collected / outstanding / overdue · due today / due this week ·
collected today · expected today (due today + promises due today) · overdue aging
(amount, bookings, customers per bucket) · open follow-ups / promises due /
broken promises / pending cheques / bounced cheques / open + unassigned cases.
All money from M7.

## Reports — `CollectionReportService`

1. **Outstanding** — customer, booking, plot, total, paid, outstanding
2. **Overdue** — customer, booking, installment, due date, amount, paid, outstanding, days overdue
3. **Aging** — bucket, amount, booking count, customer count
4. **Collection performance** — date, collector, amount, booking count

## Authorization scopes

Mirrors the M5 lead pattern (permission-based, not role-name):

- `collections.view` → cases **assigned to you** only
- `collections.view_all` → the whole queue

`CollectionCase::scopeVisibleTo` / `isVisibleTo`; `CollectionCasePolicy`
composes `can()` + visibility. New roles **Collection Manager** (view_all,
assign, approve penalties) and **Collection Executive** (assigned cases,
follow-ups, promises) exist, but authorization keys off permissions.

**A collection user can create follow-ups and promises and assign cases — they
can never fake a payment.** `payments.create` / `payments.verify` are not in any
collection role; "I'll pay tomorrow" is a `payment_promises` row, not a payment.

## Concurrency

- `collection_cases.booking_id` is **unique** — one case per booking, DB-guaranteed;
  `EnsureCollectionCaseAction` is idempotent and catches the race.
- Promise creation locks the `collection_cases` row `FOR UPDATE` and re-checks the
  combined-open-promises rule inside the lock (verified by a two-session MySQL test).
- Every mutating action runs in a transaction and re-reads state under a row lock.
- Successful payments are never double-counted — the ledger is the only counter.

## Idempotency

- `collection_follow_ups.idempotency_key` / `payment_promises.idempotency_key` (unique)
- `cheque_bounces.payment_id` unique — one bounce per payment
- follow-up completion, penalty approval and promise cancellation all no-op when
  already in the target state

## Reminders

`GenerateCollectionRemindersAction` (nightly `RefreshCollectionQueueJob`) upserts
in-app reminders — Due Today / Due Tomorrow / Overdue / Promise Due /
Promise Broken / Cheque Pending / Cheque Bounced — deduped by the
`collection_reminders_dedupe` composite unique key. **No external channels.**

## Scheduler / observer

- `App\Observers\PaymentCollectionObserver` — synchronously re-evaluates a booking's
  promises + case whenever an M7 payment status settles (Success / Failed / Reversed).
- `RefreshCollectionQueueJob` — nightly at 01:30: open cases for newly-overdue
  bookings, break stale promises, re-prioritise, regenerate reminders. `ShouldBeUnique`.

## UI

| Screen | Component | Route |
| --- | --- | --- |
| Dashboard | `CollectionDashboard` | `/collections/dashboard` |
| Collection queue (filters: priority, status, owner, project, aging, promise; search: customer / booking / plot / phone) | `CollectionQueue` | `/collections` |
| Case detail (owner, overdue installments, follow-ups, promises, cheques, penalties, timeline) | `CollectionCaseShow` | `/collections/{case}` |
| Reports | `CollectionReports` | `/collections/reports` |
| Booking collection tab | `BookingCollection` | `/bookings/{booking}/collection` |

Booking detail links to the collection tab; buyer detail gains a **Collection
profile** card (bookings, value, paid, outstanding, overdue, open + broken
promises, last payment — all from M7).

## Not in M8

Accounting / general ledger, trial balance, GST/TDS filing, bank reconciliation,
WhatsApp / SMS / Email, automated calling, advanced penalties, refund accounting,
registry, possession, transfer.
