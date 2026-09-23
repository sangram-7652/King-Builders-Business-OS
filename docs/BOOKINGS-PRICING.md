# Bookings & Pricing Engine (M6)

The **booking is the financial and inventory boundary**. A confirmed booking is
historical financial truth: it records **who** (`booking_buyers`), **what plot**,
**when** (`booking_date` / `confirmed_at`), **at what price** (the money columns +
`booking_price_lines` + `pricing_snapshot`) and **under which authorisation**
(`created_by` / `confirmed_by` / `price_override_*`).

Hierarchy: **Buyer → Booking → Plot**. A booking references a plot; a
buyer is never attached directly to a plot.

## Entities

| Table | Purpose |
| --- | --- |
| `bookings` | one row per booking; `booking_number` (`BK-000001`) is the business id, **not** the primary key |
| `booking_buyers` | co-ownership pivot — `booking_id`, `buyer_id`, `ownership_percentage`, `is_primary` |
| `booking_price_lines` | itemised breakdown — one row per BASE / PLC / CHARGE / DISCOUNT / TAX component |
| `charge_types` (master) | configurable "other charges" (development, maintenance, legal, …) |
| `tax_rates` (master) | configurable tax percentages |

`bookings.active_plot_id` is a **stored generated column**
(`CASE WHEN status IN ('pending','confirmed') THEN plot_id END`) with a **unique
index** — the database guarantee that a plot has at most one live booking,
independent of any application race.

## Status lifecycle — `App\Enums\BookingStatus`

```
DRAFT ──▶ PENDING ──▶ CONFIRMED ──▶ CANCELLED
  └──────────┴────────────┘   (both can be cancelled)
```

The transition map is the single source of truth. There is **no** un-cancel and
**no** `CONFIRMED → PENDING`.

| State | Plot effect |
| --- | --- |
| DRAFT | none — inventory untouched, no reservation |
| PENDING | claims the plot (no other booking may be PENDING/CONFIRMED for it); `Plot::status` unchanged |
| CONFIRMED | `Plot::status` → `BOOKED`, any M4 hold metadata cleared |
| CANCELLED (from PENDING/DRAFT) | releases the reservation only |
| CANCELLED (from CONFIRMED) | plot `BOOKED → AVAILABLE`, transactional — **no** refund / penalty / payment reversal |

## Buyer rules — `App\Support\Bookings\BookingBuyerValidator`

At least one buyer · exactly one primary · every share `> 0` and `≤ 100` · shares
total **exactly** 100.00 · no buyer listed twice · archived buyers rejected.
Checked before any write (`SyncBookingBuyersAction`), and re-checked on confirm.

## Pricing engine — `App\Services\Pricing\PricingEngine`

The single **server-side authoritative** calculator. No calculation logic lives
in Blade or JS — the front end only previews what the engine returns, and the
server recalculates before every persist and again on confirmation.

```
Base (area × rate)
  + PLC            fixed │ % of base │ per sq ft
  + Other charges  fixed │ % of base │ per sq ft
  = Subtotal
  − Discounts      fixed │ % of subtotal │ per sq ft   (may not exceed the subtotal)
  = Taxable amount
  + Taxes          % of taxable │ fixed
  ( ± manual override adjustment, applied after tax )
  = Final amount
```

**Money is never a float.** `App\Support\Money` wraps bcmath at an internal
scale of 6; only the final per-line / per-total figure is rounded **half-up** to
the 2 dp the `DECIMAL(15,2)` columns hold. Negative rates and percentages above
100 are rejected.

### Price line structure

`booking_price_lines`: `type`, `name`, `calculation_type`, `quantity`, `rate`,
`amount` (the resolved figure), nullable `plc_type_id` / `charge_type_id` /
`tax_rate_id` (for reporting + delete-guard), `sort_order`, `metadata`.

### Price snapshot (confirmation)

On confirm the engine re-runs from the **persisted** config and the result is
frozen into `bookings.pricing_snapshot` (base area/rate/amount, every total, an
`engine_version` tag and the full line list). Later edits to a PLC / charge /
tax master **never** move a confirmed booking's totals — verified by
`PriceSnapshotTest`.

### Pricing area — `App\Support\Pricing\PricingArea`

Every booking rate is **₹ / sq ft**. `bookings.base_area` (the pricing quantity)
is therefore **always derived on the server from the plot**: `plot.area ×
AreaUnit::squareFeetFactor()` (sq ft 1 · sq yd 9 · sq m 10.7639104167 · acre
43560 · hectare 107639.104167), bcmath only, rounded half-up to 4 dp. A
`base_area` in a request is ignored — there is no manual-area override. The
plot's own `area` / `area_unit` are never changed. Confirmation refuses (never
silently re-prices) a booking whose stored area no longer matches its plot;
re-saving the booking re-prices it. Plot Size and Dimension are reference /
descriptive only and never enter the calculation.

### Precision

Base area, base rate and every line rate / quantity may have **at most 4
decimal places** (the `DECIMAL(15,4)` columns). More is rejected — never
silently rounded — by `CalculateBookingPriceAction`, so the preview, the stored
row and the confirmation recalculation always use the identical inputs.

### Manual override — `App\Actions\Bookings\OverrideBookingPriceAction`

Only a holder of `pricing.override`; a reason is mandatory and recorded with who
and when (`price_override_*`). The override is **not** a silent mutation — the
difference is added as an explicit, labelled `Manual price override` line
(`metadata = {override, target_final, reason, by, at}`) applied after tax, so
the breakdown still shows exactly where the number comes from. The
representation lives in `App\Services\Pricing\PriceOverrideService`.

- It is the ONLY way to create or remove an override (`handle()` / `remove()`).
  Override rows in a create/update payload are ignored.
- Editing a booking (`UpdateBookingAction`, the booking form) carries the
  persisted override forward unchanged — same target final amount, reason,
  user and time. The form shows it read-only.
- `price_overridden` and `price_override_by/at/reason` are always derived from
  the override line, so removing it clears all four together.

## Concurrency

Every state change that touches inventory runs in one transaction with the
**plot row locked `FOR UPDATE`** and the plot status re-read *inside* the lock:

- `SubmitBookingAction` (DRAFT → PENDING) — locks the plot, re-checks it is
  bookable and that no other live booking exists.
- `ConfirmBookingAction` — locks the booking row, then the plot row; re-reads
  the plot status; recalculates + freezes the snapshot; booking → CONFIRMED and
  plot → BOOKED; **anything that throws rolls the whole thing back** (no
  snapshot, no plot change).

A losing concurrent confirmation finds the plot already `BOOKED` and gets a
`DomainException` — no UI guard involved. Covered by `BookingInventoryTest`
(in-transaction re-check on every driver, `FOR UPDATE` assertion, a two-session
MySQL lock-wait test, and a rollback test) and the `active_plot_id` unique
index.

## Actions — `app/Actions/Bookings`

`CreateBookingAction`, `UpdateBookingAction`, `SubmitBookingAction`,
`ConfirmBookingAction`, `CancelBookingAction`, `DeleteBookingAction`,
`OverrideBookingPriceAction`, `CalculateBookingPriceAction`,
`SyncBookingBuyersAction`, `AddBookingBuyerAction`, `RemoveBookingBuyerAction`.
Livewire components stay thin — they authorise, call an action, flash a toast.

## RBAC

`bookings.{view, create, update, confirm, cancel, delete}` and
`pricing.{view, manage, override}` (`PermissionGroup::Finance` for `pricing`,
`Sales` for `bookings`). Backend-enforced in `BookingPolicy` **and** re-checked
in every action — the UI is never the only guard. Sales Manager gets the full
set; Sales Executive can build/edit drafts and see pricing but not confirm,
cancel or override.

## UI

| Screen | Component | Route |
| --- | --- | --- |
| Booking list (search, status/project filters, sortable) | `BookingIndex` | `/bookings` |
| Create / edit (plot picker → buyers → pricing config → live preview) | `BookingForm` | `/bookings/create`, `/bookings/{booking}/edit` |
| Detail (breakdown, snapshot, submit / confirm / cancel / delete / override) | `BookingShow` | `/bookings/{booking}` |

The plot detail screen shows the current booking (number + primary buyer) when a
plot is BOOKED, via `Plot::activeBooking`.

## Not in M6

Payments, installments, payment schedules, cheques, receipts, refunds, payment
reversals, TDS collection workflow, bank reconciliation, registry, possession,
transfer, advanced reports, notifications.
