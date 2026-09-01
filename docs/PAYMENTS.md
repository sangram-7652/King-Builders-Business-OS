# Payments, Installments & Receipts (M7)

Collection against a **confirmed** booking's frozen financial obligation. The M6
price snapshot is **never** altered here.

```
BOOKING (price snapshot)
   ↓
PAYMENT PLAN  ──▶ INSTALLMENTS
   ↓
PAYMENTS  ──▶ PAYMENT ALLOCATIONS ──▶ INSTALLMENTS
   ↓
RECEIPTS
```

## Financial principle

There is **no** `booking.paid_amount`. Every balance is derived by
`App\Services\Payments\PaymentLedger` from Payment → PaymentAllocation →
Installment, and **only `PaymentStatus::Success` payments count**. All money is
`App\Support\Money` (bcmath, DECIMAL(15,2)) — never float.

| Figure | Definition |
| --- | --- |
| installment paid | Σ allocations of SUCCESS payments to that installment |
| installment outstanding | `amount − paid` (0 if WAIVED) |
| booking paid | Σ amount of SUCCESS payments on the booking |
| booking outstanding | `final_amount − booking paid` |
| booking overdue | Σ outstanding on past-due, unsettled installments |
| payment unallocated | SUCCESS payment `amount − Σ its allocations` |

## Entities

| Table | Notes |
| --- | --- |
| `payment_plans` | `total_amount` reconciles with `booking.final_amount` unless `allows_variance`. `active_plan_booking_id` = STORED generated column + UNIQUE → one live (DRAFT/ACTIVE) plan per booking |
| `installments` | `amount` values reconcile EXACTLY with the plan total; `status` derived + re-computed after every allocation / reversal |
| `payments` | `payment_number` (PAY-000001, sequence, not PK); `idempotency_key` (nullable unique); cheque metadata columns; soft deletes but **never deleted through the app** |
| `payment_allocations` | `unique(payment_id, installment_id)` — a retried allocation never double-counts. Append-only |
| `receipts` | `receipt_number` (RCPT-000001); `unique(payment_id)` — one per payment; snapshots buyer name + mode label; voided (not deleted) on reversal |

Payment methods reuse the M2 **`payment_modes`** master (+ a new `is_cheque`
flag) — CASH / UPI / BANK_TRANSFER / CHEQUE / CARD / ONLINE / OTHER, all
configurable, no hard-coded values in the pipeline.

## Enums

`PaymentPlanStatus` (Draft→Active→Completed | Cancelled) ·
`InstallmentStatus` (Upcoming / Due / PartiallyPaid / Paid / Overdue / Waived) ·
`PaymentStatus` (Pending→Success→Reversed, Pending→Failed | Cancelled) ·
`ChequeStatus` (Pending→Cleared | Bounced).

## Payment plan generator — `PaymentPlanGenerator`

`generate(Money $total, array $rows)` → `list<InstallmentSpec>`.

- **percentage** rows: must total exactly 100%; each `= round(total × pct, 2)`
- **fixed** rows: must total exactly `$total`
- the **last** installment absorbs the rounding remainder → Σ installments == total, always
- no negative installment; can't mix percentage + fixed

## Allocation strategy — `AllocatePaymentAction`

- default: **oldest installment first** (by due date, then number)
- explicit split allowed for `payments.allocate` holders
- each allocation `> 0`, `≤ installment outstanding`, running total `≤ payment unallocated`
- installment rows are locked `FOR UPDATE` — two concurrent allocations can't both consume the same balance
- **overpayment is never hidden**: leftover stays UNALLOCATED and tracked (`paymentUnallocated`), allocatable later
- idempotent via `unique(payment_id, installment_id)` + a pre-check that skips pairs already allocated

Auto-allocation + receipt issue happen inside `VerifyPaymentAction` on SUCCESS,
in one transaction with the payment row locked.

## Payment workflow

```
RecordPaymentAction   → PENDING   (PAY number, cheque_status Pending for cheques)
VerifyPaymentAction   → SUCCESS   → auto-allocate + issue receipt + (cheque) CLEARED
                      → FAILED    → (cheque) BOUNCED, no balance movement
AllocatePaymentAction → apply a SUCCESS payment to installments (auto / explicit)
ReversePaymentAction  → SUCCESS → REVERSED
```

## Reversal strategy — `ReversePaymentAction`

A confirmed payment is **never deleted**. `SUCCESS → REVERSED` with a mandatory
reason + who/when. Allocation rows are **kept** (history) — they stop counting
because the ledger only sums SUCCESS payments. Affected installment + plan
statuses are re-derived; the receipt is **voided** (marked, not deleted).
Idempotent — reversing a REVERSED payment is a no-op.

## Receipts & PDF

Issued automatically on SUCCESS verification (`GenerateReceiptAction`, one per
payment, idempotent). The printable PDF (`GET /receipts/{id}/pdf`,
`ReceiptPdfController`) renders `resources/views/receipts/pdf.blade.php` via
**barryvdh/laravel-dompdf**, tenant-branded through `App\Support\Branding`
(company name + primary colour). It shows company, project, booking, buyer,
payment, amount, mode, reference, date, received-by and the receipt number; a
voided receipt is stamped VOID.

## Concurrency

- Every balance-affecting action runs in a transaction with the relevant rows
  (`payments`, `installments`, `code_sequences`) locked `FOR UPDATE` and state
  re-read inside the lock.
- `PaymentConcurrencyTest`: two allocations can't consume the same installment
  balance; `FOR UPDATE` assertion on allocation; distinct PAY / RCPT numbers
  under repeated generation; a two-session MySQL lock-wait test on the
  `code_sequences` row.

## Idempotency

- `payments.idempotency_key` (unique) — a retried "record payment" returns the
  original.
- `VerifyPaymentAction` re-reads status under lock: a repeat verify returns the
  existing payment — no second receipt, no re-allocation, no double count.
- `AllocatePaymentAction`: `unique(payment_id, installment_id)` + auto-alloc
  no-ops when nothing is unallocated.

## RBAC

`payment_plans.{view,create,update,activate}` · `payments.{view,create,verify,
allocate,reverse}` · `receipts.{view,generate}` — all `PermissionGroup::Finance`,
enforced in `PaymentPlanPolicy` / `PaymentPolicy` / `ReceiptPolicy` **and**
re-checked in every action. `PaymentPolicy::delete` is always `false`. Accountant
gets the full set; Sales Manager gets plan + record + view; Sales Executive /
cashier get record + view only.

## UI

| Screen | Component | Route |
| --- | --- | --- |
| Finance dashboard (outstanding, overdue, pending verification) | `PaymentDashboard` | `/finance` |
| Booking payments (plan builder, installment table, payment table, verify / allocate / reverse) | `BookingPayments` | `/bookings/{booking}/payments` |
| Payments list | `PaymentIndex` | `/payments` |
| Payment detail (verify / allocate auto+manual / reverse) | `PaymentShow` | `/payments/{payment}` |
| Receipt view + Download PDF | `ReceiptShow` | `/receipts/{receipt}` |
| Receipt PDF | `ReceiptPdfController` | `/receipts/{receipt}/pdf` |

Booking detail gains a **Financials** card (total / paid / outstanding / overdue).

## Not in M7

Bank reconciliation, general ledger, GST/TDS filing, refund management, advanced
penalty engine, collection automation, payment gateways / webhooks, registry,
possession, transfer, advanced reports, notifications.
