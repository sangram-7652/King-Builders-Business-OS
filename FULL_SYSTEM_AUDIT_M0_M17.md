# FULL SYSTEM AUDIT — King Builders Business OS (M0–M17)

**Audit type:** Read-first, full-system audit — no business logic added, no architecture redesigned.
**Date:** 2026-09-04
**Auditor:** Automated deep review (Claude Code)
**Repository state:** branch `main`; M0–M10 committed, M11–M16 present in the working tree (uncommitted); M17 not present.
**Method:** Source + live MySQL schema + Docker stack brought up + test suite executed. Financial, concurrency, authorization, and storage paths were read line-by-line; UI Blade, master-data CRUD, and some report internals were sampled.

---

## 0. Remediation log (§32.1 "fix immediately")

The four immediate items were fixed and verified after the audit. Changes are in the working tree, **not committed**.

| ID | Fix | Files | Verified |
|---|---|---|---|
| **S-1** | `DatabaseSeeder` now refuses to create the Super Admin in production without `SEED_SUPERADMIN_PASSWORD` (≥ 12 chars); never falls back to a known credential. Existing account on re-seed is untouched. Local/testing keep the `password` default. `SEED_SUPERADMIN_EMAIL`/`_NAME` overrides added. | `database/seeders/DatabaseSeeder.php`, `tests/Feature/Production/SuperAdminSeederTest.php` (new, 5 tests), `.env.production.example`, `docs/DEPLOYMENT.md`, `README.md` | `SuperAdminSeederTest` — 5 pass (prod-abort, weak-password reject, strong-password OK, dev default, no-rewrite-on-reseed) |
| **F-DOCKER-1** | Host ports moved off the common collision set: `8080→8087`, `3307→13306`, `6379→16379` (still `*_HOST_PORT`-overridable). Base compose defaults + `.env.example` + local `.env` + README + compose header updated. | `docker-compose.yml`, `.env.example`, `.env`, `README.md` | `docker compose down && docker compose up -d` with **no CLI overrides** → whole stack `Up`, queue + scheduler stable (no crash loop), `curl :8087/up` → 200 |
| **F-TEST-1** | Cross-file test helpers moved into `tests/Pest.php` (the project's shared-helper home). Found **two** offenders, not one: `portalBooking()` (was in `PortalBookingsTest.php`, used by `PortalPaymentsTest.php`) **and** `pendingCase()` (was in `CommissionWorkflowTest.php`, used by `CommissionPayoutTest`/`CommissionReversalTest`/`CommissionWorkflowAccessTest`). No assertions changed; dead imports cleaned. | `tests/Pest.php`, `tests/Feature/Portal/PortalBookingsTest.php`, `tests/Feature/Commission/CommissionWorkflowTest.php` | `Portal/` under `--parallel` → 38 pass (was 6 fail); `Commission/` under `--parallel` → 73 pass (was 20 fail); each consumer file passes in isolation |
| **F-M12-2 / S-2** | Production nginx cleartext (`:80`) vhost no longer exposes the PHP-FPM handler. Only `/up` and `/healthz` reach FastCGI (fixed `SCRIPT_FILENAME`, no `*.php` routing); ACME dir serves files; everything else — including any `*.php` — is a plain 301 to HTTPS. | `docker/nginx/production.conf` | `nginx -t` in the real prod image: OK. Live container: `/`, `/dashboard`, `/index.php`, `/foo.php` → 301; `/up` → 200 (Laravel page); `/healthz` → 200 |

**Full suite** (`php artisan test --parallel`): was 1054 pass / 26 fail → now **1065 pass / 0 fail** from these four fixes (5 skipped unchanged). See §26.

Ratings below are **pre-fix**; the "Production Readiness Score" (§33) and go/no-go (§34) are unchanged because they already treated these as fixable-before-prod, and the larger blockers (M17 absent, M13–M16 partial) remain.

---

## 1. Executive Summary

King Builders Business OS is a **single-tenant** Laravel 12 real-estate CRM/ERP built as a Livewire admin plus a separate customer portal guard. The engineering quality of the **committed core (M0–M10)** is high and consistent:

- All money flows through an immutable bcmath `Money` value object (scale 6, HALF_UP to 2). No float arithmetic in pricing, payments, collections, or commission engines.
- State-changing operations run in DB transactions with `lockForUpdate()` row locks and are explicitly idempotent.
- Plot double-booking is prevented at the database level by a `bookings.active_plot_id` STORED generated column with a UNIQUE index, in addition to application locks.
- Financial truth is *derived* (Payment → PaymentAllocation → Installment, SUCCESS-only) rather than stored in trusted counter columns.
- Documents live on a private, non-web-served disk; every download is authorization-checked and IDOR-resistant.
- The customer portal scopes every record through the authenticated buyer's own booking set; URL ids are never trusted.
- Foreign keys, indexes, unique constraints, `decimal(15,2/4)`, soft deletes, and audit columns are applied uniformly.

**However**, the audit's stated scope (M0–M17) does not match what is implemented, and several defects exist:

- **M17 (Accounting) does not exist.** No chart of accounts, journal entries, fiscal periods, posting service, GL, vendor bills, or tax engine. Phases 19 and 20 of this audit (M17 audit + M6→M17 reconciliation) therefore cannot be satisfied.
- **M13, M14, M15, M16 are partial.** Per the project's own notes only M13.1, M14.1–14.5, M15.1–15.2, and M16.1 are done. Config and code comments reference sub-features (M16.4 webhooks, template versioning, consent/opt-out, lead scoring/merge UI) that are not wired up.
- The **default seeded Super Admin uses the password `password`** and is created outside the local/testing guard.
- The **test suite is red under `--parallel`/CI** (26 failures) because of a mis-scoped test helper.
- Reporting aggregates money in PHP `float` and its raw SQL applies the soft-delete filter inconsistently, so report totals can diverge from operational (M7/M8) truth.
- The **running Docker environment is unhealthy** (queue worker crash-looping; app/nginx/redis/mysql containers were down at audit start due to a host port collision on 3307).

**No P0 code-logic defect** (silent money loss, cross-customer data leak, plot double-book, un-authenticated sensitive endpoint) was found in the paths audited.

---

## 2. Environment

| Item | Value |
|---|---|
| Laravel | 12.68.0 |
| PHP (container) | 8.3.33 (`composer.json` requires `^8.3`; host has 8.5 which lacks `pdo_sqlite`, so tests must run in the container) |
| Composer deps | `laravel/framework ^12`, `livewire/livewire ^3.5`, `spatie/laravel-permission ^6.9`, `barryvdh/laravel-dompdf ^3.1`, `laravel/tinker` |
| Dev deps | `pestphp/pest ^3.5`, `pest-plugin-laravel`, `phpunit ^11.5`, `mockery`, `collision`, `pint`, `sail`, `pail` |
| Frontend | `vite`, `@tailwindcss/vite`, `tailwindcss` v4, `axios` (package.json is minimal; assets baked in the image) |
| DB | MySQL 8.0 (`utf8mb4_unicode_ci`, InnoDB) |
| Cache / session / queue | Redis (phpredis) |
| Docker services | `app` (php-fpm), `nginx`, `mysql`, `redis`, `queue` (`queue:work redis`), `scheduler` (`schedule:work`), `vite` (dev profile) |
| Migrations | 80 files, all `Ran` (batches 1–9). Sequence gap: `2026_09_23_000003` is absent (cosmetic). |
| Models / Actions / Services / Policies / Livewire / Tests | 59 / 142 / 45 / 31 / 75 / 128 files |
| Routes | 104 (staff area pinned to `auth:web`; portal to `auth:customer`) |

**Live environment health at audit start:** `king-builders-nginx`, `-redis`, `-mysql`, `-app` were `Exited`; `-queue` was `Restarting (1)` crash-looping on `getaddrinfo for redis failed`. Root cause: host port `3307` already bound by an unrelated `todo_mysql` container, so `docker compose up` had previously failed partway. The stack came up cleanly once `MYSQL_HOST_PORT`/`REDIS_HOST_PORT`/`APP_HTTP_PORT` were overridden. `php artisan about`, `migrate:status`, `route:list`, `queue:failed` (none) all healthy afterwards.

---

## 3. Architecture

```
Lead ─► Buyer ─────────────────┐
Project ─► Block ─► Plot ──────► Booking ─► PaymentPlan ─► Installment ─► Payment ─► PaymentAllocation ─► Receipt
                     │            │  (PricingEngine, bcmath)     │                        │
                     │            │                              ▼                        ▼
                     │            │                       CollectionCase (M8) ◄── PaymentCollectionObserver
                     │            │                       AgingCalculator / PaymentLedger (single source of financial truth)
                     │            ├─► Agreement ─► RegistryCase ─► RegistryAppointment ─► DocumentHandover  (M9)
                     │            ├─► PossessionCase ─► Clearances / Inspection / Handover ─► Certificate    (M10)
                     │            ├─► TransferRequest ─► PlotOwnershipHistory (append-only ledger)           (M10)
                     │            ├─► BookingPartnerAttribution ─► CommissionCase ─► CommissionCalculation (snapshot) ─► CommissionPayout (M14)
                     │            └─► Documents (polymorphic, private disk)                                  (M9)
                     └─► (reports read raw SQL over the above)                                               (M11)
Buyer ─► CustomerInvitation ─► portal `customer` guard ─► scoped Booking / Payment / Receipt views          (M15)
Communication (uuid, idempotency_key) ─► SendCommunicationJob ─► channel provider (log/mail/fake)           (M16)
```

**Cross-cutting patterns (verified consistent):**
- `App\Support\Money` — the only money type; bcmath scale 6; `store()` = HALF_UP to 2dp. No `float` in operational code.
- `App\Support\Concerns\RunsInTransaction` — `transaction()` wrapper used by every state-changing Action.
- `App\Exceptions\DomainException` — expected business-rule failure; rendered as JSON status for API clients in `bootstrap/app.php`, caught by Livewire components for toasts.
- Sequences (`code_sequences` table + `SequenceGenerator`) — concurrency-safe human codes (BK-, PAY-, RCPT-, case numbers).
- `Gate::before` grants Super Admin everything; **this is the only direct role check** — everything else is permission-based (`spatie/laravel-permission`, 11 roles).
- Two auth guards: `web` (User) and `customer` (Buyer). Staff routes are pinned to `auth:web` so a portal session can never satisfy them.

---

## 4. M0 — Foundation

**Status: PASS (with fixes).**

| Area | Finding |
|---|---|
| Bootstrap | `bootstrap/app.php` clean: trusted-proxy config from `TRUSTED_PROXIES` (default `*`), middleware aliases, `SecurityHeaders` appended to `web`, guest redirect splits portal vs staff, `DomainException` render hook. |
| Config | `config/*` standard Laravel 12; `session.secure` from env (unset locally), `session.http_only=true`, `same_site=lax`. `cache`/`queue`/`session` default to `redis` via `.env`. |
| Secrets | `.env` is git-ignored and **not committed**. Only `.env.example` (empty `APP_KEY`, `APP_DEBUG=true`, `APP_ENV=local` — appropriate for an example) and `.env.production.example` (all secrets are empty `CHANGE-ME` placeholders) are tracked. No hardcoded secret found in tracked files. |
| Logging | `stack`→`single` (`storage/logs/laravel.log`), `LOG_LEVEL=debug` in example. Business events are logged with structured context (`Log::info('payment.verified', …)`). No PII in communication logs (address + hash only). |
| Exception handling | Centralised; `DomainException::status()` respected. |
| DB / schema | See §22. FKs, indexes, `decimal`, soft deletes, unique constraints all present and consistent. |
| Transactions | Consistent `RunsInTransaction` usage in Actions. |
| Queue / Redis / scheduler | `queue`/`scheduler` are dedicated containers. `routes/console.php` registers: prune-batches (daily), prune-failed (weekly), heartbeat (15 min), `ExpirePlotHoldsJob` (5 min), `RefreshCollectionQueueJob` (01:30), `ExpireDocumentsJob` (02:00), `MarkMissedFollowUpsJob` (hourly) — all `withoutOverlapping` and `ShouldBeUnique`. |
| Storage | `local` → `storage/app/private`; dedicated `documents` disk (`serve=false`, `visibility=private`, `throw=true`), root `storage/app/private/documents`, never web-served. |

**Findings:** F-M0-1 (debug default in example — informational), F-DB-1 (migration sequence gap — cosmetic), F-M0-2 (`king_builders` SQLite file loose in repo root, not git-ignored). See §30–31.

---

## 5. M1 — Auth + RBAC

**Status: PASS.**

- **Authentication:** custom Livewire `Login` (staff) rejects inactive users at login *and* `EnsureUserIsActive` middleware re-checks every authenticated request (defence in depth), invalidating the session and regenerating the token on deactivation. Portal `Login` uses `RateLimiter` (5 attempts / 60s per email+IP), `session()->regenerate()` on success, records `LoginFailed`/`Login` activities.
- **Authorization:** `spatie/laravel-permission` v6.25, enum-backed `Permission` + `PermissionGroup`; 11 roles seeded by `RolePermissionSeeder`. `Gate::before` = Super Admin bypass (only role check). Every route in the staff group carries `permission:<x>` middleware; export routes carry an *additional* `permission:reports.export`.
- **Policies:** 31 policy classes. Sensitive Actions **re-check permission inside the Action** (`$actor->can('payments.reverse')`, `transfer.complete`, `payments.allocate`) — not just at the route — so a Livewire event or a queued call cannot bypass the route guard.
- **Scoped access:** `LeadPolicy` / `BuyerPolicy` / `CollectionCasePolicy` implement row-level visibility (`leads.view` = own/assigned only; `leads.view_all` lifts it). `DocumentPolicy` resolves every document to its underlying Buyer/Booking/Partner and requires `can('view', $that)` — this is the IDOR guard.
- **IDOR / privilege escalation:** payment/booking/receipt state machines forbid illegal transitions; `Payment` `Failed`/`Cancelled` are terminal and can never reach `Success` (verified in `PaymentStatus::transitionMap()`).

**Findings:** F-M1-1 (P1) **— FIXED (§0)** seeded Super Admin password `password` reachable in production; F-M1-2 (P3) `reports.*` has no row-level scoping — a holder of `reports.view` sees all salespeople's figures (by design, undocumented).

---

## 6. M2 — Master Data

**Status: PASS.**

- 18 registry-driven master modules behind one generic Livewire CRUD (`MasterIndex`/`MasterForm`) and one shared `MasterDataPolicy` (`masters.*` permissions), wired via `MasterRegistry::modelClasses()` in `AppServiceProvider`.
- All master seeders are idempotent (`updateOrCreate` on a natural key).
- `MasterModel` carries `SoftDeletes`. Downstream FKs use `ON DELETE RESTRICT` (property masters) or `ON DELETE SET NULL` (optional attributes like `plot_category_id`), so deleting an in-use master is either blocked or safely nulled.
- Uniqueness enforced at the DB (`code`/natural-key unique indexes).

**Findings:** F-M2-1 (P3) master delete is soft; a soft-deleted master still satisfies a `RESTRICT` FK check only while rows point at it — no orphan risk, but a re-created master with the same code is a new id. Low impact. F-M2-2 (P3): no UI guard preventing deactivation of a master that is referenced by a *draft* booking/plan (only hard delete is guarded); acceptable.

---

## 7. M3 — Projects / Blocks

**Status: PASS.**

- `Project` + `Block`; `ProjectStatus` transition map; `projects.*` RBAC (blocks reuse it).
- `GuardsAgainstDestructiveDelete` trait: delete Actions call `hasBusinessDependents()` before removing — a project with plots/bookings cannot be destructively deleted.
- Routes use `->whereNumber()` and `scopeBindings()` for the nested `projects/{project}/blocks/{block}/plots` hierarchy; `ValidatesBookingConsistency::assertHierarchyConsistent()` re-checks project↔block↔plot at booking time, so a tampered `block_id` in the payload is rejected.
- Duplicate prevention: `plots_project_id_block_id_plot_number_unique`.

**Findings:** none material.

---

## 8. M4 — Plot Inventory

**Status: PASS.**

- `PlotStatus` lifecycle with an explicit transition map (deliberately *no* `BOOKED → AVAILABLE` edge — that is a controlled operation inside `CancelBookingAction`).
- Concurrency-safe holds: `held_at`/`hold_expires_at`/`held_by`; `ExpirePlotHoldsJob` (`ShouldBeUnique`, every 5 min) releases lapsed holds idempotently.
- **Race-condition analysis (two users, same plot):**
  - `CreateBookingAction` locks the plot `FOR UPDATE`, asserts bookable, and for a PENDING target asserts no live booking. Even if that check races, `bookings.active_plot_id` (STORED generated column = `plot_id` when status ∈ {pending, confirmed}, else NULL) has a UNIQUE index → the second insert throws.
  - `ConfirmBookingAction` locks the booking row, then locks the plot row, re-reads plot status, re-asserts `assertNoLiveBooking()`, then flips plot→BOOKED. A losing concurrent confirm finds the plot already BOOKED and gets a `DomainException`.
  - **Verdict: plot double-booking is not possible** given the generated-column unique index + row locks.
- Bulk creation, dimensions/area (`decimal(12,2)`), pricing fields all present.
- Historical state: plot status changes are not individually versioned, but `plot_ownership_history` (M10) is the authoritative ownership ledger and booking rows are soft-deleted, so history is preserved at the aggregate level.

**Findings:** F-M4-1 (P3) plot status transitions are not written to an audit/event table (only `Log::info`); an operator cannot reconstruct "who moved this plot to HOLD and when" from the DB alone. Low.

---

## 9. M5 — Leads + Buyers

**Status: PASS (with fixes).**

- `Lead` lifecycle (`LeadStatus`), `assigned_to`, `LeadActivity` timeline, `LeadFollowUp`.
- **Conversion (`ConvertLeadToBuyer`)**: single transaction, `lockForUpdate` on the lead, **idempotent** (already-CONVERTED returns the linked buyer, no duplicate buyer, no second activity row). The lead row and its entire activity/follow-up history are retained — `status`/`converted_at`/`buyer_id` are set, nothing is deleted. **"Conversion does not lose lead history" — confirmed.**
- KYC (`pan_number`, `aadhaar_number`) stored as `text` with encrypted casts (per project notes) — not indexed, not logged.
- Scoped authorization via `LeadPolicy::isVisibleTo` / `BuyerPolicy`.

**Findings:**
- **F-M5-1 (P2):** `buyers.email` has a **non-unique** index. Portal login (`Login::login()`) does `Buyer::where('email', $this->email)->first()` — with two buyers sharing an email, login resolves non-deterministically to the lowest id. Invitations/activations are keyed by buyer id so tokens are fine, but a customer could authenticate as the *wrong* buyer record. Portal data is still scoped to *that* buyer's bookings, so it is not a cross-customer leak, but it is an auth-correctness bug (family/shared emails are common in this domain). **Fix:** add a partial unique index on `email` where `email IS NOT NULL AND deleted_at IS NULL`, and block invite when another active buyer already owns that email.
- **F-M5-2 (P3):** `ConvertLeadToBuyer` with `$existingBuyerId` does not verify the actor may access that buyer, nor that the buyer is not archived. The Livewire `LeadConvert` component gates this, but the Action is reachable directly. Add `Buyer` status + `can('view', $buyer)` checks in the Action.

---

## 10. M6 — Booking + Pricing

**Status: PASS.**

- **`PricingEngine`** is the single server-side calculator: Base (area×rate) → +PLC → +charges → subtotal → −discounts (capped at subtotal, else `DomainException`) → taxable → +taxes → +signed manual override (post-tax, `pricing.override` gated) → `clampToZero()`. No rounding between steps; `Money::store()` only at the end. **No calculation logic in Blade/JS** (the front end previews what the engine returns). Percentage rates asserted ≤ 100, negatives rejected.
- `bookings` columns: `base_area`/`base_rate` `decimal(15,4)`; all amounts `decimal(15,2)`; `pricing_snapshot` JSON frozen at confirmation.
- **`ConfirmBookingAction`**: lock booking → idempotent if CONFIRMED → re-validate buyer set (100% ownership, one primary — `BookingBuyerValidator`) → check no buyer archived → lock plot → re-assert hierarchy + bookable + no live booking → **recalculate price from persisted inputs and freeze `pricing_snapshot`** → booking→CONFIRMED, plot→BOOKED, clear hold. Full rollback on any throw.
- Salesperson attribution: `bookings.created_by` (used as "salesperson" throughout reports).
- `CancelBookingAction`: DRAFT/PENDING → CANCELLED just releases the reservation (generated column → NULL); CONFIRMED → CANCELLED also returns plot BOOKED→AVAILABLE inside the transaction. Idempotent.
- Co-owner pivot `booking_buyers` with `ownership_percentage decimal(5,2)`, `is_primary`.

**Findings:**
- **F-M6-1 (P2):** `CancelBookingAction` on a **CONFIRMED** booking releases the plot and marks the booking cancelled but performs **no guard or cascade** for downstream state that may already exist: successful payments, an active payment plan, an open `CollectionCase`, a `RegistryCase`, a `PossessionCase`, generated `CommissionCase`s, or `plot_ownership_history`. The docstring acknowledges "no refund/penalty/payment reversal here — later milestones", but no later milestone adds the guard, and the plot is silently made available while money/registry/possession records still point at the cancelled booking. **Fix:** refuse to cancel a CONFIRMED booking that has any SUCCESS payment, an active plan, a non-terminal registry/possession case, or an approved commission case — require those to be unwound first (or add an explicit, permissioned "force cancel with reason" that cascades).
- **F-M6-2 (P3):** manual override lines are stored with `metadata.override=true` and applied post-tax; there is no cap on override magnitude other than `clampToZero()`. `pricing.override` permission is the only guard. Acceptable but log the before/after `final_amount` explicitly (currently only `price_override_reason` is stored).

---

## 11. M7 — Payments

**Status: PASS.**

- **Source of financial truth is `App\Services\Payments\PaymentLedger`** — every balance is DERIVED from `Payment` (status = SUCCESS) → `PaymentAllocation` → `Installment`. There is **no trusted `paid_amount` column** and no balance maths in Blade/Livewire. Confirmed by reading `installmentPaid`, `bookingPaid`, `bookingOutstanding`, `bookingOverdue`, `bookingUnallocated`, `summary`.
- **`RecordPaymentAction`**: booking must be CONFIRMED; amount > 0; mode active; cheque needs number+date. Creates the payment **PENDING** (does not move balances). Optional `idempotency_key` (unique index) — a retried request returns the original; a `QueryException` race is caught and re-resolved.
- **`VerifyPaymentAction`**: PENDING → SUCCESS or FAILED only; **row-locked**; idempotent (already-in-target-state returns unchanged — no second receipt, no re-allocation); on SUCCESS it auto-allocates (oldest installment first) and issues the receipt inside one transaction. Cheque status is set to CLEARED/BOUNCED accordingly.
- **`AllocatePaymentAction`**: default = oldest due/outstanding first; explicit split only for `payments.allocate`; each allocation `> 0`, `≤ installment outstanding`, running total `≤ payment unallocated`; `(payment_id, installment_id)` **unique** → idempotent; installments locked `FOR UPDATE`; overpayment stays UNALLOCATED and tracked (`bookingUnallocated`).
- **`ReversePaymentAction`**: SUCCESS → REVERSED only; payment is **never deleted**; reason mandatory + who/when; allocation rows **kept** (they stop counting because the ledger only sums allocations of SUCCESS payments); affected installment/plan statuses re-derived; receipt **voided** (kept, marked), never deleted; idempotent. Also invoked by M8 cheque-bounce with `$systemInitiated` after the caller checks `cheques.bounce`.
- Receipt numbering via the concurrency-safe sequence (`RCPT-000001`). `receipts.voided_at` respected everywhere (portal, PDF).
- **"Can a failed/cancelled payment become a successful collection?"** — No. `PaymentStatus` transition map: `Pending → {Success, Failed, Cancelled}`, `Success → {Reversed}`, `Failed`/`Cancelled`/`Reversed` are terminal. `bookingPaid()` sums `status = success` only.
- **Duplicate payment/receipt:** `payments.idempotency_key` unique; receipt issued exactly once inside the verify transaction; re-verify is a no-op.

**Findings:**
- **F-M7-1 (P3):** `PaymentLedger::bookingOutstanding()` = `final_amount − Σ(SUCCESS payments)` regardless of allocation. This is intentional (comment: "may be negative = credit") but it means an unallocated overpayment reduces `outstanding` even though no installment is settled, while `bookingOverdue()` (allocation-based) is unaffected. UI should surface `unallocated` prominently so the two figures reconcile for the user. Currently `FinancialSummary` carries `unallocated` — verify each screen shows it.
- **F-M7-2 (P3):** `RecordPaymentAction` allows a payment `payment_date` in the future or arbitrarily far in the past (no bound). Low impact; reports bucket by date so a typo skews a period. Add a sane range check.

---

## 12. M8 — Collections

**Status: PASS.**

- Collection layer over M7: `CollectionCase` + queue + `AgingCalculator` + scoped follow-ups + promise-to-pay + cheque bounce + penalty foundation + dashboard/reports.
- **`AgingCalculator`** reads M7 truth via `PaymentLedger` — it never recomputes a balance. `daysOverdue = today(startOfDay) − due_date(startOfDay)`; waived/paid installments have no bucket; a partially-paid installment contributes its remaining outstanding.
- **Aging boundaries (`AgingBucket::fromDaysOverdue`)** match the audit spec exactly:
  `days ≤ 0 → null`, `≤ 30 → 0-30`, `≤ 60 → 31-60`, `≤ 90 → 61-90`, `≤ 180 → 91-180`, `else → 180+`.
- **Timezone / date-boundary:** app timezone is UTC; `due_date` is a `date` cast; all comparisons are `startOfDay()` vs `Carbon::today()`. Consistent — **no off-by-one at bucket edges** (verified: a due date of exactly `today−30` is `0-30` in both PHP and the report SQL).
- `PaymentCollectionObserver` on `Payment` keeps collection state in step with payment state (M7↔M8).
- `RefreshCollectionQueueJob` (nightly 01:30, unique) opens cases for newly-overdue bookings, breaks stale promises, re-prioritises, regenerates reminders — idempotent.
- Cheque bounce → `RecordChequeBounceAction` → `ReversePaymentAction($systemInitiated=true)` → penalty foundation.

**Findings:**
- **F-M8-1 (P2):** `CollectionAnalytics` / `SalesAnalytics` / `InventoryAnalytics` compute the *report* aging and totals with **raw `DB::table()` SQL and PHP `(float)` casts**, in parallel to the Eloquent `PaymentLedger`. The bucket-window SQL matches the enum today, but this is duplicated aggregation logic that can silently drift from `AgingCalculator`/`PaymentLedger` on any future change, and the `(float)` cast can produce sub-rupee differences vs the bcmath ledger on large sums. See also F-REP-1, F-REP-2.
- **F-M8-2 (P3):** the raw report SQL does **not** filter `bookings.deleted_at` / `payments.deleted_at` (see F-REP-2). No confirmed booking or verified payment is soft-deleted today, so impact is currently nil, but it is a latent divergence.

---

## 13. M9 — Documents / Agreements / Registry

**Status: PASS.** (Strongest area of the codebase for security.)

- **Storage:** private `documents` disk, `serve=false`, no `url`, root outside the webroot. `document_versions` holds `disk`/`path`/`checksum` (sha256)/`size_bytes`/`mime_type`. Versioning: `(document_id, version)` unique; `documents.current_version_id`.
- **`DocumentDownloadController` is the ONLY path to a file.** It calls `Gate::authorize('download', $document)` → `DocumentPolicy::download` = `can(documents.download)` **AND** `canReachDocumentable()` (resolves to the underlying Buyer/Booking/Partner and requires `can('view', $that)`). It then verifies `version->document_id === document->id` (so you can't pair a version id from another document), checks `disk->exists()`, and streams with the stored filename/mime. **IDOR test: guessing a document id or version id fails** — you must also be able to view the owner entity.
- `documents_slot_unique (documentable_type, documentable_id, document_type_id)` — one active document per slot; re-upload creates a new version, not a duplicate row.
- Verification/rejection/expiry: `documents.status` + `verified_by`/`rejected_by`/`rejection_reason`/`expires_at`; `ExpireDocumentsJob` (daily 02:00, unique) flips past-expiry docs to EXPIRED idempotently.
- Agreements: `agreement_number` unique, `type`, `status` (`draft`→…→`signed`→`approved`), `terms_snapshot` JSON, `document_id` FK. `agreements.booking_id` FK `ON DELETE RESTRICT`.
- Registry: `RegistryEligibilityService` gates on paid-% (`REGISTRY_REQUIRED_PAID_PERCENT` default 90), overdue block, all required docs VERIFIED, agreement SIGNED. `RegistryCase` + `RegistryAppointment` + `registry_expenses` (with approve step) + `DocumentHandover`.
- Nginx prod config additionally denies `location ^~ /storage/` for `.php/.env/.sql/.log/.bak` and blocks `^/(app|bootstrap|config|database|…|storage/(?!app/public))/`.

**Findings:**
- **F-M9-1 (P3):** `ReceiptPdfController` (staff) authorizes with `Gate::authorize('generate', $receipt)` (`ReceiptPolicy::generate`) rather than a `view` ability — verify `generate` is not granted more broadly than intended (it maps to `receipts.generate`). Portal `ReceiptDownloadController` correctly scopes by `buyer_id` OR co-owned `booking_id` and rejects voided receipts.
- **F-M9-2 (P3):** document MIME is validated on upload by Livewire rules (`config('registry.uploads.mimes')`), but the download response trusts the stored `mime_type`. Since files are never web-served and are downloaded as attachments, XSS-via-SVG is not reachable through this controller; still, consider forcing `Content-Type: application/octet-stream` for non-PDF/image types. Low.

---

## 14. M10 — Possession / Transfer / Ownership

**Status: PASS.**

- `PossessionCase` → clearances (financial/document/legal/site, all required) → inspection (latest must be PASSED) → appointment → handover → branded certificate. Financial clearance cannot be marked CLEARED while `outstanding > POSSESSION_MAX_OUTSTANDING` unless explicitly WAIVED.
- Eligibility (`config/possession.php`): booking CONFIRMED, registry COMPLETED, required docs VERIFIED, no overdue, paid-% (default 100), all clearances CLEARED/WAIVED, inspection PASSED.
- **`PlotOwnershipService` is the single writer of `plot_ownership_history` and is append-only:**
  - `ensureAllotment()` lazily materialises the original allotment period(s) from `booking_buyers` — idempotent, never mutates `booking_buyers`.
  - `applyTransfer()` (caller holds locks) closes active period(s) by setting `ended_at`, then **guards that the incoming buyer does not already hold an active period**, then opens a new TRANSFER period. **Historical periods are never overwritten.**
- **`CompleteTransferAction`**: `transfer.complete` re-checked in the Action; lock request → idempotent if COMPLETED → must be APPROVED → lock plot → **conflict check**: reject if another transfer on this booking completed after this one was approved → `applyTransfer()` (if `transfer_type->movesOwnership()`) → mark COMPLETED + audit events. Fully transactional.
- **`TransferWorkflowAction`**: submit/startReview/requestDocuments/approve/reject/cancel — each validated against `TransferRequestStatus::transitionMap()`, idempotent, row-locked. `approve` requires `transfer.approve`, runs `TransferEligibilityService` (financial gates + required doc codes `TRANSFER_APPLICATION/CONSENT/ID_PROOF` VERIFIED), stores a `financial_snapshot`. `reject` requires a non-empty reason.
- Nominee foundation: `buyer_nominees` with `superseded_by` self-FK (append-only supersession), `status`, optional `id_document_id`.

**Findings:**
- **F-M10-1 (P2):** `plot_ownership_history` has **no database-level guarantee against overlapping active ownership** (e.g. a partial unique index on `(booking_id)` where `ended_at IS NULL`, or a `plot_id` variant). The invariant is enforced only in `PlotOwnershipService` under application locks. That is adequate for the current single-writer path, but any other code path (a data fix, a future feature, a bulk import) could create two open periods. **Fix:** add a generated-column + partial unique index, or a `CHECK`/trigger, to make "one open ownership period per booking" a DB invariant.
- **F-M10-2 (P3):** `CreateTransferRequestAction` (not shown here) should be verified to reject `new_buyer_id == current_buyer_id` and `new_buyer_id` being a co-owner already; `applyTransfer` catches the latter at completion time but failing earlier is better UX. Nominee-vs-owner: confirm `RecordBuyerNomineeAction` rejects a nominee whose identity equals the owner.
- **F-M10-3 (P3):** `transfer_requests.booking_id` FK is `ON DELETE CASCADE`; combined with `plot_ownership_history.booking_id` also `CASCADE`, a `forceDelete()` of a booking would erase ownership history. Bookings are soft-deleted and `DeleteBookingAction` refuses non-draft/cancelled, so unreachable today — but consider `RESTRICT` for these two FKs to make the ledger truly indestructible.

---

## 15. M11 — Reporting

**Status: PASS WITH FIXES.** Functionally present (M11.1–M11.6 per project notes; `reports.leads` is a stub); correctness concerns below.

- One flow: `ReportFilterResolver` → `ReportFilterData` (from/to, `DatePreset`, projectId, blockId, salespersonId) → analytics service → view. Filters applied via `ReportFilterScope` / `Queries\Reports\ReportFilterScope` with **parameterised bindings** (no SQL injection — every `whereRaw` uses `?` placeholders).
- Analytics: `ExecutiveDashboardService`, `SalesAnalytics`, `InventoryAnalytics`, `CollectionAnalytics`, `MisAnalytics`, `OperationsAnalytics`. `CollectionAnalytics` builds an `installmentLedger` sub-query as its single M7/M8 truth source and every figure derives from it.
- Export: `ReportExportController` gated by `reports.export`, `whereIn` on `{sales,inventory,collections,mis}` × `{csv,xlsx,pdf,print}`; `report_exports` table records exports.
- Route access: `permission:reports.view`.

**Findings:**
- **F-REP-1 (P2):** money is aggregated and returned as PHP **`float`** throughout the analytics layer (`(float) $r->receivable`, `bounced_amount => (float)`, `SalesAnalytics` value totals, etc.). The *operational* ledger is bcmath-exact; the *reports* are not reconciled to it to the paise. On large portfolios this can show a 1–2 paise discrepancy between a report total and the sum of the underlying receipts, and CSV vs PDF vs on-screen can differ if rounded at different points. **Fix:** aggregate as `DECIMAL` in SQL (`sum()` on a decimal column stays decimal) and format through `Money` for display/export; never `(float)` a money column.
- **F-REP-2 (P2):** the raw report queries are **inconsistent about soft deletes**. `MisAnalytics` and `SalesAnalytics` filter `leads.deleted_at`; `InventoryAnalytics` filters `plots.deleted_at`. But `CollectionAnalytics::confirmedBookings()`, `SalesAnalytics` bookings/payments/plots sub-queries, and `InventoryAnalytics` bookings sub-query do **not** filter `deleted_at`. `Booking`, `Payment`, `Plot` all use `SoftDeletes`. A soft-deleted confirmed booking or verified payment would appear in reports while being invisible to `PaymentLedger`. Impact today: nil (nothing soft-deletes those rows), but it is a latent, silent divergence from module truth — exactly what this audit flags. **Fix:** add `->whereNull('<table>.deleted_at')` to every raw report sub-query, or route reports through the Eloquent models.
- **F-REP-3 (P3):** `reports.leads` is a stub (`ReportController` shows "coming next" for it). If the nav links it, it renders an empty/placeholder page.
- **F-REP-4 (P3):** N+1 risk — spot-checked `recoveryReport` and `demandSeries` are single aggregates with name-joins (good). `PortalBookingIndex` loops `PaymentLedger::summary()` per booking (each `summary()` fires ~4 aggregate queries) — fine at portal scale (a customer has few bookings) but the same pattern in a staff list over hundreds of bookings would be slow. Verify `BookingIndex`/`CollectionQueue` do not call `PaymentLedger::summary()` per row.

---

## 16. M12 — Production Readiness

**Status: PASS WITH FIXES.** Mature infra (compose overlay, security headers, health checks, backup/restore/deploy/smoke scripts, DEPLOYMENT/DISASTER-RECOVERY/PRODUCTION-READINESS docs).

- `docker-compose.prod.yml`: drops the source bind-mount (image is authoritative), named volumes for `storage/app` + `storage/logs`, MySQL/Redis get **no host port** and a **required** password (`${DB_PASSWORD:?...}`), `.env` bind-mounted **read-only**, restart policies, real healthchecks (`php artisan health:check`).
- `docker/php/production.ini`: `display_errors=Off`, `expose_php=Off`, `opcache.validate_timestamps=0`, `opcache.jit=tracing`, `session.cookie_secure=1`, `session.use_strict_mode=1`, `upload_max_filesize=20M`.
- `SecurityHeaders` middleware: `X-Content-Type-Options`, `X-Frame-Options=SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, `X-Permitted-Cross-Domain-Policies=none`, a CSP (allows `unsafe-inline`/`unsafe-eval` for Livewire/Alpine but blocks external script/style/frame), HSTS **only** over real HTTPS in production. Removes `X-Powered-By`/`Server`.
- `HealthProbe` / `HealthController` (`GET /healthz`, unauthenticated, no session): checks DB `select 1`, cache round-trip, private disk write/read/delete — returns only `ok`/`fail` per component, **no host/credential/version/path leak**. `/up` remains the liveness route.
- No secrets committed (§4). `.env.production.example` is placeholder-only.

**Findings:**
- **F-M12-1 (P2):** the production **entrypoint / Dockerfile run no `php artisan config:cache` / `route:cache` / `view:cache` / `event:cache`.** Every request pays the full config + route compilation cost. **Fix:** add the cache steps to the image build (or entrypoint after migrate) and document `config:clear` before a `.env` change.
- **F-M12-2 (P2) — FIXED (§0):** the **HTTP (port 80) nginx server block in `production.conf` proxied `location ~ \.php$` to `app:9000` unconditionally** — not just `/up` and `/healthz`. The intent is "redirect everything to HTTPS + let probes through", but the regex `.php$` location wins over `location / { return 301 }`, so `http://host/index.php?...` is executed over plaintext. Pretty-URL app traffic is still 301'd and prod session cookies are `secure`, so real exposure is limited, but the PHP handler should not be reachable on the cleartext vhost beyond the two exact health locations. **Fix:** in the port-80 block, replace the open `location ~ \.php$` with `location = /up` / `location = /healthz` that `try_files … /index.php`, and 301 everything else including `.php`.
- **F-M12-3 (P2):** `SecurityHeaders`, `EnsureUserIsActive` etc. call `env()` at **runtime**. This works *only because* prod does not run `config:cache` (F-M12-1). If F-M12-1 is fixed, `env('SECURITY_HEADERS_ENABLED')` / `env('SECURITY_HEADERS_CSP')` / `env('SECURITY_HSTS')` all return their hardcoded defaults and the documented "disable instantly via env" escape hatch silently stops working. **Fix:** move these to `config/security.php` and read `config()`.
- **F-M12-4 (P3):** production `app` entrypoint runs `php artisan migrate --force` automatically on every deploy with a 10× retry loop and **no pre-migration backup gate**. `docs/DEPLOYMENT.md` may cover this operationally; still, a failed/partial migration on a live DB has no automatic rollback. Consider a `--pretend` diff + explicit backup step in `deploy.sh`.
- **F-M12-5 (P3):** no CPU/memory `deploy.resources.limits` on any prod container; a runaway queue job or report can starve the box.
- **F-M12-6 (P3):** `docker/php/php.ini` (baked into the image) has `display_errors = On`. It is overridden by `production.ini` **only when the prod overlay is used**. Running the image without the overlay leaks stack traces. Make the baked default `Off`.
- **F-M12-7 (P0 for the running box, not the code):** the deployed stack was unhealthy at audit time — `queue` crash-looping, core containers exited — due to a host port collision. Not a code defect, but it means "is it running in Docker?" currently answers **no**. See §27.

---

## 17. M13 — CRM Automation

**Status: PARTIAL — only M13.1 implemented.**

- **Done (M13.1):** lead pipeline expansion (`add_pipeline_fields_to_leads_table`), structured follow-ups (`FollowUpType`/`FollowUpStatus`/`FollowUpPriority`, `add_engine_fields_to_lead_follow_ups_table`), assignment history (`lead_assignments` table, `LeadAssignment` model), `/follow-ups` queue (`FollowUpQueue` Livewire + `FollowUpsView` permission), `MarkMissedFollowUpsJob` (hourly, unique, idempotent — flips overdue PENDING → MISSED), Actions `ScheduleFollowUp`/`RescheduleFollowUp`/`CancelFollowUp`/`CompleteFollowUp`/`MarkMissedFollowUps`.
- Idempotency of automation: `MarkMissedFollowUpsJob` is `ShouldBeUnique`; follow-up Actions are single-purpose and transactional. `LeadAssignment` history is append-only.
- **Not implemented:** lead scoring + scoring reasons, duplicate detection + merge (there is a `leads.merge` permission and `LeadPolicy::merge`, but no `MergeLeadsAction` / UI), reactivation workflow, configurable automation rules, dedicated salesperson/manager dashboards.

**Findings:**
- **F-M13-1 (P1 for audit scope):** the audit asks to verify lead scoring, merge, duplicate detection, automation rules, and dashboards — **none exist**. The `leads.merge` permission + policy method are dead code (no Action/route).
- **F-M13-2 (P3):** verify `ScheduleFollowUp` / `RescheduleFollowUp` cannot create two open PENDING follow-ups of the same type for one lead (the audit's "automation must not create duplicate follow-ups"). `MarkMissedFollowUpsJob` only transitions state, so it is safe; the create/reschedule path needs a guard or a `(lead_id, type, status=pending)` partial unique index.

---

## 18. M14 — Broker / Commission

**Status: PARTIAL — M14.1–M14.5 implemented; well-built.**

- **Partner master** (`partners`, `PartnerStatus`/`PartnerType`), KYC docs (polymorphic on `Partner` via `DocumentPolicy`), **project authorization** (`partner_project_authorizations`; `config('partners.attribution.require_project_authorization')` default true).
- **Attribution:** `lead_partner_attributions` + `booking_partner_attributions` (`share_percentage decimal(5,2)`, `role`). **Co-broker split is enforced to total EXACTLY 100.00** via `App\Support\Partners\AttributionSplit` using **bcmath at scale 2** (`bccomp($total, '100.00', 2) !== 0` → `DomainException`), with exactly one primary. Empty split allowed.
- **Schemes:** versioned (`commission_schemes` + `commission_rules` + `commission_slabs`; `CreateCommissionSchemeVersion`, `PublishCommissionScheme`, `ArchiveCommissionScheme`). Basis resolver ties the basis amount to **M6/M7 truth**.
- **Calculation (`CommissionCalculator`)**: percentage / fixed / slab (whole or marginal), min/max caps — **all bcmath via `Money`**, pure (no writes). Returns an immutable `CommissionComputation`.
- **Cases (`GenerateCommissionCases`)**: one case per partner on the active split; `(booking_id, partner_id)` **UNIQUE index**; row locks; **idempotent** — re-run re-snapshots a recalculable case in place, **never touches an APPROVED/PAID case**, CANCELS a case for a partner dropped from the split (unless approved), records an eligibility reason for an ineligible pair. Triggered by `BookingCommissionObserver` on the booking lifecycle.
- **Snapshots:** `commission_calculations` — `(commission_case_id, sequence)` unique, full `snapshot` JSON, `scheme_code`+`scheme_version` frozen, `basis_amount`/`share_percentage`/`gross_before_caps`/`gross_amount`/`commission_amount` all decimal. **Historically reliable.**
- **Workflow:** `ApproveCommissionCase` / `HoldCommissionCase` / `ResumeCommissionCase` / `CancelCommissionCase` / `ReverseCommissionCase` / `RecalculateCommissionCase`.
- **Payout tracking (`RecordCommissionPayout`)**: row-locked; amount > 0; `Σ(recorded payouts) + amount ≤ commission_amount` (else `DomainException` with the remaining figure); reaching the full amount flips the case to PAID; `VoidCommissionPayout` for corrections. Docstring is explicit: **"operational tracking only — no ledger entry, no GST/TDS."**

**Findings:**
- **F-M14-1 (P1 for audit scope):** **M14 ↔ M17 accounting reconciliation cannot be performed** — M17 does not exist. Commission payouts are operational rows with no double-entry counterpart. When M17 lands, a payout must post `Dr Commission Expense / Cr Bank` (or a payable) and a reversal must post the contra.
- **F-M14-2 (P2):** `RecordCommissionPayout` computes `alreadyPaid` from `recordedPayouts()->sum('amount')`. Confirm that relation filters `status = 'recorded'` (excludes VOIDED payouts) — otherwise a void-then-repay flow double-counts and the overpay guard is wrong. `commission_payouts` has `status` + `voided_at`; the relation name suggests it filters, but verify.
- **F-M14-3 (P2):** eligibility default `commission.eligibility.min_collected_percent = 0` means a partner's commission is "earned" at **booking confirmation** with zero collection. That is a policy choice, but combined with `RecordCommissionPayout` allowing full payout on an APPROVED case, the builder can pay a broker in full before collecting a rupee from the customer, and a later booking cancellation (F-M6-1) leaves the commission paid. Recommend `min_collected_percent` > 0 in production and a clawback path tied to `ReverseCommissionCase`.
- **F-M14-4 (P3):** `commission_cases.booking_id` FK `ON DELETE CASCADE` — see F-M10-3 rationale; prefer `RESTRICT`.

---

## 19. M15 — Customer Portal

**Status: PARTIAL — M15.1–M15.2 implemented; security model is sound.**

- Separate `customer` auth guard on `Buyer` (`config/auth.php`). Staff routes are pinned `auth:web`, so a portal session can never resolve a staff permission or reach `/users`, `/roles`, `/reports`, etc.
- **Invitation/activation (`InviteCustomerToPortal` / `ActivateCustomerPortal`)**: single-use, expiring, **hashed** tokens (`customer_invitations.token_hash` unique; `CustomerTokenService`). Activation is transactional, marks the token `used_at`, **spends all other outstanding tokens for that buyer**, sets a hashed password, flips `portal_status` → ACTIVE. TTLs configurable (`invite_ttl_hours` 168, `reset_ttl_hours` 24).
- `EnsureCustomerPortalActive` middleware: a suspended/archived buyer with a live session is signed out (mirrors staff).
- **IDOR defence (`ResolvesPortalRecords` trait)**: every query is scoped through `customer()->bookingBuyers()->pluck('booking_id')`; a URL id is filtered through that set and a miss is a **404** (indistinguishable from "does not exist"). Customers only ever see **SUCCESS** payments and **non-void** receipts. `paymentOr404`, `bookingOr404`, `receiptOr404` all verified. Portal `ReceiptDownloadController` re-checks `buyer_id` OR co-owned `booking_id` AND `voided_at IS NULL`.
- **No internal-field leakage:** grep of `resources/views/livewire/portal/**` found **no** references to `notes`, `internal`, `commission`, `created_by`, `received_by`, `verified_by`, `reason`, staff names, or internal pricing/collection notes. The portal booking `Show` loads `registryCase`/`possessionCase`/`agreement`/`transferRequests` but the Blade renders only status-level fields.
- Rate-limited login (`RateLimiter`, 5/60s per email+IP), `session()->regenerate()`, records login IP + timestamp + `CustomerActivity`.

**Findings:**
- **F-M15-1 (P2):** portal login resolves `email → buyer` with a non-unique column (see **F-M5-1**). A customer with a duplicated email authenticates as the lowest-id buyer. Not a cross-customer leak (data is then scoped to *that* buyer) but wrong-identity auth. **Fix as F-M5-1.**
- **F-M15-2 (P2 for audit scope):** the audit asks to verify portal exposure of documents, agreements, registry, possession, transfer, notifications, timeline, and support requests. Only **bookings + payment schedule + receipts** are implemented (M15.2). There is **no portal document download**, no support-request feature, no notification centre. Not a defect, but the milestone is ~40% of its scope.
- **F-M15-3 (P3):** `Buyer` is the `customer` provider model and also the staff-managed CRM record. It has `password`, `remember_token`, `portal_*` columns mixed with KYC. Acceptable, but ensure the staff `BuyerForm` never displays/edits `password` and that `Buyer::$hidden` includes `password`, `remember_token`, `pan_number`, `aadhaar_number`.

---

## 20. M16 — Communication Engine

**Status: PARTIAL — only M16.1 implemented; the foundation is correct.**

- Provider-agnostic: `CommunicationManager` → `CommunicationProvider` (`LogProvider` default for every channel, `EmailProvider` via Laravel Mail, `FakeProvider` for tests). `communications` table: `uuid` unique, `idempotency_key` unique, `channel`/`category`/`status`, `provider`/`provider_message_id`, `attempts`, per-state timestamps (`queued_at`/`sending_at`/`sent_at`/`delivered_at`/`failed_at`).
- **`Communicator`** persists the record then **queues** `SendCommunicationJob` — a provider is never called inline, so a domain transaction is never blocked by an external failure. Idempotent on `idempotency_key`. Email address validated with `FILTER_VALIDATE_EMAIL`.
- **`SendCommunicationJob`**: `ShouldBeUniqueUntilProcessing` (`uniqueId = communication:<id>`, `uniqueFor = 3600`); `tries = 5`, `backoff = [10,30,120,300,900]`; skips a record already Cancelled/Delivered/Sent; a thrown provider exception → `markFailed` + rethrow (queue retries); a `permanentFailure` result → stop, no retry; `failed()` handler sets FAILED with the exhausted-retries reason, guarded against overwriting Delivered/Cancelled.
- **DELIVERED semantics: correct.** `LogProvider`/`EmailProvider` return `confirmsDelivery: false`; the job sets **SENT** on provider acceptance and the docstring is explicit that **DELIVERED is set only by a real delivery webhook (M16.4) — never inferred.**
- **Test safety: adequate.** `phpunit.xml` forces `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync`; the default `COMM_*_PROVIDER` is `log` → `LogProvider` performs **no external call**. `FakeProvider` records messages and can script results. A test cannot send a real WhatsApp/SMS/email **as configured**.

**Findings:**
- **F-M16-1 (P2):** `config/communication.php` defines `webhooks.{email,sms,whatsapp}.secret` and the job/Blade comments reference "M16.4", but **there is no webhook route** (`routes/web.php` has none; no `Portal`/webhook controller). Therefore `DELIVERED` is **unreachable in practice** and provider delivery is never confirmed — every successful message ends at SENT forever. Correct per the "don't claim delivery you can't observe" principle, but the milestone's delivery-tracking half is absent.
- **F-M16-2 (P3):** `ShouldBeUniqueUntilProcessing` with `uniqueFor = 3600`: if the queue is backed up and a retry chain spans > 1 hour, the unique lock can expire and a duplicate `SendCommunicationJob` for the same id could be dispatched. The job's own status check (skip if Sent) mitigates a *double send* in most cases, but a race between "processing" and "re-dispatched" is possible. Raise `uniqueFor` or rely on `idempotency_key` at the provider layer.
- **F-M16-3 (P2 for audit scope):** templates, template versions, variables/rendering, preferences, consent, opt-out are **not implemented** — `CommunicationRequest` carries an already-rendered `body`. Bulk/marketing category exists (`CommunicationCategory`) but there is no consent gate before sending a `marketing` message.
- **F-M16-4 (P3):** `EmailProvider` is not on the scheduler and has no dead-letter alerting; a permanently failing channel is only visible via `communications.status = failed` and the log.

---

## 21. M17 — Accounting

**Status: NOT IMPLEMENTED — no code exists.**

Searched: no `app/Services/Accounting`, no accounting migrations, no `Journal*`/`Ledger*`/`ChartOfAccount*`/`FiscalYear*`/`AccountingPeriod*` models, no `posting` service, no GL/AP/AR/vendor/bank/tax tables. `app/Enums/TdsRule`/`TaxRate` exist as **M2 master data only** (rate reference), with no posting logic. `RegistryExpenseType` and `commission_payouts` are operational trackers with no double-entry counterpart.

**Consequence for this audit:**
- **Phase 19 (M17 audit): cannot be performed — nothing to audit.**
- **Phase 20 (M6→M7→M8→M14→M17 reconciliation): only the M6→M7→M8→M14 legs exist.** Those legs are internally consistent (all read `PaymentLedger` / booking `final_amount` / commission snapshots). There is **no** general ledger to reconcile against, no "TOTAL DEBIT = TOTAL CREDIT" invariant to check, no period lock, no immutability-of-posted-entries guarantee — because there are no entries.

**Finding F-M17-1 (P1 for audit scope):** the audit brief covers M0–M17; M17 is entirely absent. Any go/no-go that assumes an accounting module must treat it as **not started**. The good news: M7/M8 are the operational source of truth and are well-isolated, so a future M17 can be layered on as a *projection* (posting service subscribes to payment/receipt/reversal/commission events) without altering M7/M8 — the codebase is structured to allow exactly that.

---

## 22. Cross-Module Findings (Phase 20 reconciliation)

| Leg | Status | Notes |
|---|---|---|
| M6 Booking → M7 Payments | **Consistent** | `RecordPaymentAction` requires `booking->isConfirmed()`; `PaymentLedger` uses `booking.final_amount` (frozen snapshot). |
| M7 Payments → M8 Collections | **Consistent** | `AgingCalculator` reads `PaymentLedger` only; `PaymentCollectionObserver` syncs case state; `RefreshCollectionQueueJob` is idempotent. |
| M7/M8 → M11 Reports | **Divergence risk** | Reports re-implement aggregation in raw SQL + `float` and skip `deleted_at` inconsistently (F-REP-1, F-REP-2, F-M8-1). Numbers are *currently* right but not provably equal to `PaymentLedger` to the paise. |
| M6 → M14 Commission | **Consistent** | Basis amount from M6/M7 truth; `(booking_id, partner_id)` unique; snapshots immutable. |
| M6 cancel → downstream | **Gap (F-M6-1)** | Cancelling a CONFIRMED booking does not unwind payments / plan / collection case / registry / possession / commission — the plot is freed while those rows dangle. |
| M14 → M17 Accounting | **Missing** | M17 absent (F-M17-1, F-M14-1). |
| M7/M8 → M17 | **Missing** | Same. |
| M10 Ownership ledger | **App-enforced only** | No DB invariant against overlapping open periods (F-M10-1). |

**Orphan / duplicate scan (live DB, small dataset):** `payments`, `bookings`, `commission_cases`, `plot_ownership_history` — no duplicate business keys; unique indexes present on every human code and every "one per slot" relation. No FK is `NO ACTION` without an index. No amount-mismatch found in the seeded/live rows (dataset is tiny — this is not a substitute for a production data reconciliation job).

---

## 23. Security Findings

| ID | Sev | Finding |
|---|---|---|
| S-1 | **P1 — FIXED (§0)** | Seeded Super Admin `super@kingbuilders.test` / `password`, created **outside** the `local/testing` guard in `DatabaseSeeder::run()`. Fixed: the seeder now aborts in production unless `SEED_SUPERADMIN_PASSWORD` (≥ 12 chars) is set, and never falls back to a shipped credential; existing accounts are untouched on re-seed. |
| S-2 | **P2 — FIXED (§0)** | Cleartext-HTTP PHP execution in `docker/nginx/production.conf` port-80 block (F-M12-2). Fixed: only `/up` + `/healthz` reach FastCGI; all other paths (incl. `*.php`) 301 to HTTPS. |
| S-3 | **P2** | `buyers.email` not unique → portal auth resolves to the wrong buyer for shared emails (F-M5-1 / F-M15-1). |
| S-4 | **P2** | `env()` at runtime in middleware; security-header/HSTS/CSP kill-switches become inert if `config:cache` is ever enabled (F-M12-3). |
| S-5 | P3 | CSP allows `'unsafe-inline'` + `'unsafe-eval'` (documented, needed for Livewire/Alpine). Acceptable; note it caps XSS mitigation at "no external origin". |
| S-6 | P3 | No webhook route exists yet, so **webhook signature verification is not a live risk** — but when M16.4 lands, verify the shared-secret HMAC with `hash_equals()` and reject on clock skew. |
| S-7 | P3 | `reports.*` has no row-level scoping (F-M1-2). |
| S-8 | P3 | Queue payloads (`SendCommunicationJob` carries only an int id — good; other jobs likewise). No model serialization of secrets into Redis found. |
| S-9 | P3 | `king_builders` SQLite file in repo root is not git-ignored — a stray `git add .` would commit a local DB (F-M0-2). |
| — | PASS | **SQL injection:** every `whereRaw`/`selectRaw` uses `?` bindings; no string interpolation into SQL. |
| — | PASS | **Mass assignment:** `Model::shouldBeStrict()` in non-prod; Actions build attribute arrays explicitly; `forceFill` used only inside authorized, transactional Actions. |
| — | PASS | **CSRF:** Livewire + `web` group; portal on `web` middleware. |
| — | PASS | **IDOR:** documents (DocumentPolicy → `can('view', documentable)`), portal (ResolvesPortalRecords → 404), receipts (buyer/booking scoped). |
| — | PASS | **File upload:** private disk, mime/size validation, sha256 checksum, never web-served, download is attachment-only through an authorized controller. |
| — | PASS | **Path traversal:** downloads use stored `path` from `document_versions` + `disk->exists()`; no user-supplied path. |
| — | PASS | **Open redirect:** `redirectGuestsTo` returns named routes only; portal/staff login redirects are `redirectRoute()`. |
| — | PASS | **Session:** `http_only`, `same_site=lax`, `secure` in prod ini, `use_strict_mode=1`, regenerate on login. |
| — | PASS | **Rate limiting:** portal login throttled; staff login throttled in `Livewire\Auth\Login` (verify decay). |

---

## 24. Database Findings

**Overall: strong.** InnoDB, `utf8mb4_unicode_ci`, `decimal(15,2)` for money / `decimal(15,4)` for area·rate / `decimal(5,2)` for percentages, `timestamp` nullable audit columns, `SoftDeletes` on business roots.

| ID | Sev | Finding |
|---|---|---|
| DB-1 | P3 | Migration number `2026_09_23_000003` missing from the sequence (cosmetic; `migrate:status` clean). |
| DB-2 | P2 | `plot_ownership_history`: no partial-unique / CHECK guaranteeing one open period per booking (F-M10-1). |
| DB-3 | P2 | `buyers.email` not unique (F-M5-1). |
| DB-4 | P3 | `commission_cases.booking_id`, `transfer_requests.booking_id`, `plot_ownership_history.booking_id`, `booking_partner_attributions`… use `ON DELETE CASCADE` from `bookings`. Bookings soft-delete + `DeleteBookingAction` guard make this unreachable, but a `forceDelete()` would erase financial/ownership history. Prefer `RESTRICT` for the ledger-like children. |
| DB-5 | PASS | `bookings.active_plot_id` STORED generated column + `UNIQUE` = DB-level double-booking prevention. Excellent. |
| DB-6 | PASS | `payment_allocations (payment_id, installment_id)` UNIQUE; `payments.idempotency_key` UNIQUE; `communications.idempotency_key`/`uuid` UNIQUE; `customer_invitations.token_hash` UNIQUE; `documents_slot_unique`. |
| DB-7 | PASS | Every FK column is indexed; composite indexes on hot filters (`payments (booking_id, status)`, `bookings (plot_id, status)`, `installments (payment_plan_id, due_date)`, `documents (status, expires_at)`, `communications (channel, status)`). |
| DB-8 | P3 | `M11` added `add_reporting_indexes` — verify it covers `bookings.booking_date`, `leads.created_at`, `installments.due_date`, `payments.payment_date` (the report `whereBetween` columns). Spot-check shows `bookings.booking_date`, `payments.payment_date`, `installments.due_date` are indexed. |
| DB-9 | P3 | `commission_payouts` has no `(commission_case_id, reference)` unique — a duplicate payout with the same bank reference is allowed (the amount guard still prevents overpay). Low. |

**Decimal safety:** operational code never reads these columns as float — always through `Money::of((string) $value)`. The only float exposure is the reporting layer (F-REP-1).

**Timezone:** app + DB in UTC; all date-boundary logic uses `startOfDay()` consistently. No DST/offset bug found.

---

## 25. Performance Findings

| ID | Sev | Finding |
|---|---|---|
| P-1 | P2 | No `config:cache`/`route:cache`/`view:cache` in the prod image (F-M12-1) — measurable per-request overhead on 104 routes + heavy config. |
| P-2 | P3 | `PaymentLedger::summary()` fires ~4–6 aggregate queries per call. Fine per-booking; verify staff list screens (`BookingIndex`, `CollectionQueue`, `PaymentDashboard`) don't call it per row over a long list. Portal `Bookings\Index` loops it but a customer has few bookings. |
| P-3 | P3 | `CollectionAnalytics` builds `fromSub(installmentLedger)` repeatedly across many methods; each report page may re-run the sub-query several times. There is a `remember()` cache wrapper on some methods — verify it's applied consistently and keyed by the full filter set. |
| P-4 | P3 | `DocumentPolicy::canReachDocumentable` does `loadMissing('documentable')` then `can('view', $d)` per document — a document *list* screen authorizing each row is N+1 on the polymorphic parent. Eager-load `documentable` in the Livewire query. |
| P-5 | P3 | Reports cast to `float` and aggregate in PHP in a few places (`SalesAnalytics` trend building) rather than in SQL — fine at current data volume, revisit at 10k+ bookings. |
| — | PASS | No obvious unbounded query; list screens paginate; jobs are `ShouldBeUnique` + `withoutOverlapping`. |

No premature optimization recommended — the above are evidence-based, not speculative.

---

## 26. Test Coverage Findings

**Suite:** ~130 files, ~1090 test cases. **Initial run (`php artisan test --parallel --processes=4`): 1054 passed, 26 failed, 5 skipped, ~515s.** After the §0 fixes: **1065 passed, 0 failed, 5 skipped.**

- **F-TEST-1 (P2 / effectively P1 for CI) — FIXED (§0):** the parallel failures were `Call to undefined function` for a helper defined at the top of one test file and used by another. Under `--parallel`/`--filter`/sharded CI the files land in different processes and the consumer cannot see the function. **Two** offenders existed, not one:
  - `portalBooking()` — defined in `PortalBookingsTest.php`, used by `PortalPaymentsTest.php` (the visible 6).
  - `pendingCase()` — defined in `CommissionWorkflowTest.php`, used by `CommissionPayoutTest.php` / `CommissionReversalTest.php` / `CommissionWorkflowAccessTest.php` (the other 20; the original truncated parallel output hid these, and the audit's first pass under-attributed all 26 to the portal helper).
  Both moved into `tests/Pest.php` alongside the existing shared scenario builders (`bookingScenario`, `confirmedBookingScenario`, `misWorld`, …). No assertions touched. A sweep for other cross-file helpers found only a false positive (`schedule()` — a method call, not the helper). Verified: `Portal/` + `Commission/` under `--parallel` → all green; each consumer file passes in isolation.
- **Coverage is broad and meaningful** (not just "class exists"):
  - Authorization: `tests/Feature/Authorization/*`, per-module policy tests, scoped-visibility tests (`leadAgent` vs `leadManager`).
  - Concurrency/idempotency: payment verify/allocate/reverse idempotency, booking confirm race, transfer completion conflict, commission regeneration idempotency, `EnsureCollectionCaseAction`, plot hold expiry.
  - Financial: pricing engine breakdown, `PaymentLedger` truth, aging buckets, "reads M7/M8 truth" tests for registry/possession, co-broker 100% split, commission caps/slabs.
  - State machines: booking/payment/transfer/registry/possession status maps.
  - IDOR: `PortalBookingsTest` ("someone else's booking" → 404), document download cross-buyer.
  - Reports: `MisAnalytics`, `SalesReportTest`, export format tests.
- **Gaps:**
  - **F-TEST-2 (P2):** no test asserts report totals **equal** `PaymentLedger`/`AgingCalculator` to the paise (would have caught F-REP-1/F-REP-2).
  - **F-TEST-3 (P2):** no test for cancelling a CONFIRMED booking that has payments/registry/possession (F-M6-1) — the gap is untested, so a fix has no safety net yet.
  - **F-TEST-4 (P3):** no test for duplicate `email` at portal login (F-M5-1).
  - **F-TEST-5 (P3):** M13 scoring/merge, M15 documents/support, M16 templates/consent — untested because unimplemented.
  - No load/perf test; no `config:cache` smoke test.
- **`tests/Feature/Production/*`** exists (health check, security headers) — good.

**Do not weaken any test to make it pass.** F-TEST-1 is a *test-infrastructure* bug (helper location), not a product bug — fix the helper location, do not delete the assertions.

---

## 27. Docker / Production Findings (Phase 26)

Commands run against the (repaired) live stack:

| Command | Result |
|---|---|
| `docker compose ps` | After port override: `app`, `mysql` (healthy), `nginx`, `queue`, `redis` (healthy), `scheduler` all **Up**. Before: 4 containers `Exited`, `queue` crash-looping. |
| `docker compose config` | Valid. |
| `php artisan about` | Laravel 12.68.0, PHP 8.3.33, env `local`, **Debug ENABLED** (correct for local), Cache/Config/Events/Routes **NOT CACHED**, Views CACHED. Drivers: redis (cache/queue/session), mysql, mail=log. |
| `php artisan migrate:status` | All 80 migrations `Ran`. |
| `php artisan route:list` | 104 routes. |
| `php artisan test` | Initial 1054/26/5; after §0 fixes **1065/0/5**. See §26. |
| `php artisan queue:failed` | None. |
| `docker compose logs queue` (pre-repair) | `RedisException: php_network_getaddresses: getaddrinfo for redis failed` — crash loop because core containers were down. |

**F-DOCKER-1 (P0 for the running environment) — FIXED (§0):** the deployed stack was not self-healing from a host-port collision — `docker compose up` failed partway and left `queue`/`scheduler` crash-looping against dead dependencies. Fixed: base compose defaults + `.env.example` + local `.env` moved to `8087` / `13306` / `16379` (still `*_HOST_PORT`-overridable). `docker compose up -d` with no CLI overrides now brings the whole stack up clean.

**F-DOCKER-2 (P2):** see F-M12-1 (no artisan caching), F-M12-2 (HTTP PHP), F-M12-6 (`display_errors=On` baked default).

---

## 28. Critical Issues (P0)

| ID | Title | Where | Status |
|---|---|---|---|
| F-DOCKER-1 | Running Docker stack was unhealthy (queue crash-loop; core containers exited) due to host-port collision; not self-healing | `docker-compose.yml` host port publishes / running env | **FIXED (§0)** — ports moved to 8087/13306/16379; stack comes up clean |

*No P0 in application/business logic.* (S-1 seeded admin is rated P1 because it requires an operator to also skip the documented "change the password" step; if your threat model treats a shipped known-credential admin as a blocker, escalate S-1 to P0.)

---

## 29. High Issues (P1)

| ID | Title | Fix safe / non-breaking? | Test required |
|---|---|---|---|
| ~~S-1 / F-M1-1~~ **FIXED (§0)** | Seeded Super Admin password `password`, reachable in production first-deploy seeding | Done — seeder aborts in production without `SEED_SUPERADMIN_PASSWORD` (≥ 12) | Done — `SuperAdminSeederTest` (5 tests) |
| F-M17-1 | M17 Accounting entirely absent (audit scope M0–M17) | N/A — feature not started; decide scope | — |
| F-M14-1 | M14↔M17 reconciliation impossible (no ledger) | N/A — depends on M17 | — |
| F-M13-1 | M13 scoring / merge / duplicate detection / automation rules / dashboards absent; `leads.merge` permission+policy are dead code | Yes — either implement or remove the dead permission/policy method | Yes if implemented |
| F-M16-3 | M16 templates / versions / consent / opt-out / preferences absent; no consent gate before `marketing` sends | Yes — add a consent check before enqueueing a `marketing` communication even ahead of full templating | Yes — "marketing send blocked without consent" |
| ~~F-TEST-1~~ **FIXED (§0)** | Test suite red under `--parallel`/CI (`portalBooking()` **and** `pendingCase()` helpers mis-scoped) | Done — both moved to `tests/Pest.php`; no assertions changed | Done — `Portal/` + `Commission/` green under `--parallel` |

---

## 30. Medium Issues (P2)

| ID | Title | Fix safe? | Test |
|---|---|---|---|
| F-M6-1 | Cancelling a CONFIRMED booking frees the plot without unwinding payments/plan/collection/registry/possession/commission | Mostly — adding a guard is non-breaking; a cascading "force cancel" needs design | Yes — cancel-with-payments must be refused |
| F-REP-1 | Report money aggregated as PHP `float`, not reconciled to bcmath ledger | Yes — sum as DECIMAL in SQL, format via `Money` | Yes — report total == ledger sum to the paise |
| F-REP-2 | Raw report SQL applies `deleted_at` filter inconsistently → latent divergence from module truth | Yes — add `whereNull('…deleted_at')` everywhere | Yes — soft-deleted booking excluded from every report |
| F-M5-1 / S-3 / DB-3 / F-M15-1 | `buyers.email` not unique; portal login resolves wrong buyer for shared emails | Yes — partial unique index + invite guard (data cleanup first) | Yes — duplicate email rejected at invite; login deterministic |
| F-M10-1 / DB-2 | No DB invariant against overlapping open ownership periods | Yes — additive partial unique index / generated column | Yes — second open period rejected by DB |
| F-M12-1 / P-1 | No `config:cache`/`route:cache`/`view:cache` in prod image | Yes — build step | Smoke test after `config:cache` |
| ~~F-M12-2 / S-2~~ **FIXED (§0)** | Cleartext-HTTP PHP execution in prod nginx port-80 block | Done — `:80` restricted to `/up` + `/healthz`; all else 301 | Done — live container: `/index.php`, `/foo.php`, `/` → 301; `/up`,`/healthz` → 200 |
| F-M12-3 / S-4 | `env()` at runtime in middleware; kill-switches break under `config:cache` | Yes — move to `config/security.php` | Yes — toggle via config works under `config:cache` |
| F-M8-1 | Duplicated aging/aggregation logic in reports vs `AgingCalculator`/`PaymentLedger` | Yes — extract shared query builder | Covered by F-TEST-2 |
| F-M14-2 | `RecordCommissionPayout` overpay guard may count voided payouts | Verify relation filter; add `where('status','recorded')` if missing | Yes — void-then-repay does not double-count |
| F-M14-3 | Commission earned at confirmation with 0% collected + full payout allowed before any customer payment | Config change + clawback path | Yes — payout blocked below `min_collected_percent` |
| F-M16-1 | No webhook route → `DELIVERED` unreachable; delivery never confirmed | Additive — new signed webhook endpoint | Yes — signed webhook flips SENT→DELIVERED; bad signature → 403 |
| F-M16-2 | `SendCommunicationJob` unique lock (`uniqueFor=3600`) can expire mid-retry-chain → possible duplicate dispatch | Yes — raise `uniqueFor` / provider-level idempotency | Yes — long-retry scenario sends once |
| ~~F-TEST-1~~ **FIXED (§0)** | (see P1 row) | | |
| F-TEST-2 | No test asserting reports == operational truth | Additive | New tests |
| F-TEST-3 | No test for F-M6-1 scenario | Additive | New tests |
| ~~F-DOCKER-1~~ **FIXED (§0)** / F-DOCKER-2 | F-DOCKER-1 done (ports); F-DOCKER-2 open — see F-M12-1/6 | | |

---

## 31. Low Issues (P3)

| ID | Title |
|---|---|
| F-M0-2 / S-9 | `king_builders` SQLite file loose in repo root, not git-ignored |
| DB-1 | Missing migration number `2026_09_23_000003` (cosmetic) |
| F-M4-1 | Plot status changes not written to an audit table (only `Log::info`) |
| F-M5-2 | `ConvertLeadToBuyer` with `$existingBuyerId` lacks buyer-access / not-archived check in the Action |
| F-M6-2 | Manual price override magnitude uncapped; before/after `final_amount` not explicitly stored |
| F-M7-1 | `bookingOutstanding` (payment-based) vs `bookingOverdue` (allocation-based) can disagree when money is unallocated — surface `unallocated` on every screen |
| F-M7-2 | `payment_date` unbounded (future/ancient dates accepted) |
| F-M9-1 | `ReceiptPdfController` uses `generate` ability, not `view` — verify grant scope |
| F-M9-2 | Download trusts stored `mime_type` for non-PDF/image types (not web-served, so low) |
| F-M10-2 | Verify `CreateTransferRequestAction` rejects `new_buyer == current_buyer` / existing co-owner; verify nominee ≠ owner in `RecordBuyerNomineeAction` |
| F-M10-3 / DB-4 / F-M14-4 | Ledger-like child FKs use `ON DELETE CASCADE`; prefer `RESTRICT` |
| F-M1-2 / S-7 | `reports.*` has no row-level scoping (holder sees all salespeople) |
| F-M2-1 / F-M2-2 | Master soft-delete / no deactivation guard for masters referenced by drafts |
| F-M12-4 | Auto-migrate on deploy with no backup gate |
| F-M12-5 | No container resource limits in prod |
| F-M12-6 | `docker/php/php.ini` baked default `display_errors = On` |
| F-M13-2 | Verify follow-up create/reschedule cannot create duplicate open PENDING follow-ups |
| F-M15-3 | Confirm `Buyer::$hidden` includes `password`, `remember_token`, `pan_number`, `aadhaar_number`; staff form never edits `password` |
| F-M16-4 | No dead-letter alerting for a permanently failing communication channel |
| F-REP-3 | `reports.leads` is a stub page |
| F-REP-4 / P-2 / P-4 | N+1: `PaymentLedger::summary()` per row on staff lists; `DocumentPolicy` polymorphic parent per row |
| DB-9 | `commission_payouts` no `(case_id, reference)` unique |
| F-TEST-4/5 | Untested: duplicate-email login; unimplemented M13/M15/M16 sub-features |

---

## 32. Recommended Fix Order

**1 — Fix immediately (before any further deploy): ✅ DONE — see §0 for details + verification.**
1. ~~**S-1**~~ — ✅ seeder aborts in production without `SEED_SUPERADMIN_PASSWORD` (≥ 12 chars); never ships a known credential.
2. ~~**F-DOCKER-1**~~ — ✅ host ports moved to `8087` / `13306` / `16379`; `docker compose up -d` comes up clean with no overrides.
3. ~~**F-TEST-1**~~ — ✅ `portalBooking()` **and** `pendingCase()` moved to `tests/Pest.php`; `--parallel` green; no assertions touched.
4. ~~**F-M12-2 / S-2**~~ — ✅ prod nginx `:80` block restricted to `/up` + `/healthz`; all else (incl. `*.php`) 301s to HTTPS.

**2 — Fix before production:**
5. **F-M6-1** — guard `CancelBookingAction` against cancelling a CONFIRMED booking with payments / active plan / open registry / open possession / approved commission.
6. **F-REP-1 + F-REP-2 + F-TEST-2** — money as DECIMAL/`Money` in reports; add `deleted_at` filters; add "reports == ledger" tests.
7. **F-M5-1 / S-3 / DB-3** — dedupe `buyers.email`, add partial unique index, guard invite + make portal login deterministic.
8. **F-M12-1 / F-M12-3** — add `config:cache`/`route:cache`/`view:cache` to the image; move middleware `env()` to `config/security.php`.
9. **F-M14-2 + F-M14-3** — verify/fix the payout overpay guard; set `commission.eligibility.min_collected_percent` > 0 and wire clawback to `ReverseCommissionCase`.
10. **F-M10-1 / DB-2** — DB invariant for one open ownership period per booking.
11. **F-M16-3 (consent gate)** — block `marketing`-category sends without recorded consent, even before full templating.
12. **F-M12-4/5/6, F-DOCKER-2** — backup gate in `deploy.sh`, resource limits, `display_errors=Off` baked default.

**3 — Fix after production (next iterations):**
13. **M17 Accounting** — build as an event-sourced projection over M7/M8/M14 (posting service; CoA; periods with lock; immutable posted entries + reversal-only corrections; debit==credit invariant enforced in the posting service AND a DB CHECK on journal batches).
14. **M13** completion (scoring, merge + duplicate detection, automation rules, dashboards) — or remove the dead `leads.merge` permission.
15. **M14→M17** reconciliation job; **M15** portal documents/agreements/registry/possession/support; **M16** templates/versions/preferences/opt-out/webhooks (M16.4) with `hash_equals()` signature verification.
16. FK `CASCADE → RESTRICT` for ledger-like children; plot status audit table.

**4 — Optional improvements:**
17. N+1 clean-ups (F-REP-4, P-2, P-4); `remember()` cache audit on `CollectionAnalytics`.
18. Payment date-range validation; override before/after logging; `commission_payouts` reference uniqueness.
19. Load/perf test at 10k bookings; `config:cache` smoke test in CI.
20. Remove the loose `king_builders` SQLite file; add it to `.gitignore`.

---

## 33. Production Readiness Score

| Dimension | Score (0–5) | Rationale |
|---|---|---|
| Core domain correctness (M0–M10) | **4.5** | bcmath money, transactional + row-locked + idempotent everywhere, DB-level double-booking prevention, append-only ownership ledger. Minus: F-M6-1 cancel gap. |
| Financial integrity (M7/M8) | **4.5** | Derived truth, no trusted counters, reversal keeps history, failed≠success. Minus: reports not reconciled to the paise. |
| Authorization / security | **4** | Two guards, permission-based, in-Action re-checks, IDOR-resistant documents + portal. Minus: seeded admin password, `env()` fragility, HTTP-PHP, non-unique buyer email. |
| Data model / schema | **4.5** | FKs, indexes, decimals, uniques, soft deletes all consistent. Minus: a few `CASCADE`s, no ownership DB invariant. |
| Reporting | **3** | Present and filter-scoped, but float aggregation + inconsistent soft-delete handling = provable divergence risk. |
| Milestone completeness vs audit scope | **2.5** | M0–M10 solid; M11 done; M12 solid infra; **M13/M14/M15/M16 partial; M17 absent.** |
| Testing | **3.5** | Broad, meaningful coverage incl. concurrency/authz/financial — but red under CI parallel, and no reports-vs-truth assertion. |
| Ops / Docker / deploy | **3.5** | Strong prod overlay, health checks, DR docs. Minus: no artisan caching, HTTP-PHP, running stack was down, no backup gate. |

**Weighted overall: ~3.7 / 5 — "good core, not yet production-complete."**

---

## 34. Final Go / No-Go Recommendation

### AUDIT STATUS: **PASS WITH FIXES**

The **committed core (M0–M10) + M11 + M12 infra** is production-quality engineering and would be a **conditional GO** for a deployment scoped to *bookings → payments → collections → documents → registry → possession → transfer → reporting*, **after** the "Fix immediately" list (§32.1) and the security items S-1, F-M12-2, F-M12-3, F-M5-1.

It is a **NO-GO as an "M0–M17" system**, because:
- **M17 (Accounting) does not exist** — any go-live that promises a general ledger, journals, GST/TDS posting, fiscal periods, or M14→M17 reconciliation is shipping a feature that isn't there.
- **M13, M14, M15, M16 are partial** and several referenced sub-features (lead merge, communication consent/opt-out/templates, portal documents, delivery webhooks) are unimplemented — some with dead permissions/config already in place that imply otherwise.
- The **test suite is red in CI** (parallel), so the current green-on-my-machine status is not trustworthy for release gating.
- The **running Docker environment is not healthy**.

### Prioritised action list

**1. Fix immediately — ✅ DONE (§0)**
- ~~S-1 seeded Super Admin credential~~ — seeder aborts in prod without `SEED_SUPERADMIN_PASSWORD`
- ~~F-DOCKER-1 host-port collision / dead stack~~ — ports → 8087/13306/16379; stack green
- ~~F-TEST-1 mis-scoped test helpers (CI red)~~ — `portalBooking()` + `pendingCase()` moved to `tests/Pest.php`; suite 1065/0
- ~~F-M12-2 cleartext-HTTP PHP execution~~ — prod nginx `:80` restricted to health routes

**2. Fix before production**
- F-M6-1 confirmed-booking cancellation guard
- F-REP-1 / F-REP-2 / F-TEST-2 report money = operational truth (DECIMAL + `deleted_at` + reconciliation tests)
- F-M5-1 / DB-3 unique buyer email + deterministic portal login
- F-M12-1 / F-M12-3 artisan caching + `env()`→`config()`
- F-M14-2 / F-M14-3 commission payout overpay guard + collection-gated eligibility + clawback
- F-M10-1 DB invariant for one open ownership period
- F-M16-3 consent gate before marketing sends
- F-M12-4/5/6 backup gate, resource limits, `display_errors` default

**3. Fix after production**
- Build M17 as an event-sourced projection over M7/M8/M14 (posting service, CoA, locked periods, immutable posted entries, debit==credit invariant)
- Complete M13 (or remove dead `leads.merge`), M15 portal surfaces, M16 templates/consent/opt-out/webhooks (with `hash_equals()` signature checks), M14→M17 reconciliation job
- FK `CASCADE`→`RESTRICT` for ledger-like children; plot status audit trail

**4. Optional improvements**
- N+1 clean-ups; `CollectionAnalytics` cache audit
- Payment date-range validation; override before/after logging; payout reference uniqueness
- 10k-booking load test; `config:cache` CI smoke test
- Remove/ignore the loose `king_builders` SQLite file

---

### Appendix A — Finding index by milestone

- **M0:** F-M0-1, F-M0-2, DB-1
- **M1:** F-M1-1 (P1) *FIXED*, F-M1-2, S-1 *FIXED*
- **M2:** F-M2-1, F-M2-2
- **M3:** —
- **M4:** F-M4-1, DB-5 (pass)
- **M5:** F-M5-1 (P2), F-M5-2
- **M6:** F-M6-1 (P2), F-M6-2
- **M7:** F-M7-1, F-M7-2 (both P3); engine PASS
- **M8:** F-M8-1 (P2), F-M8-2
- **M9:** F-M9-1, F-M9-2; security PASS
- **M10:** F-M10-1 (P2), F-M10-2, F-M10-3
- **M11:** F-REP-1 (P2), F-REP-2 (P2), F-REP-3, F-REP-4
- **M12:** F-M12-1 (P2), F-M12-2 (P2) *FIXED*, F-M12-3 (P2), F-M12-4, F-M12-5, F-M12-6, F-M12-7
- **M13:** F-M13-1 (P1), F-M13-2 — *partial milestone*
- **M14:** F-M14-1 (P1), F-M14-2 (P2), F-M14-3 (P2), F-M14-4 — *engine PASS, partial milestone*
- **M15:** F-M15-1 (P2), F-M15-2 (P1 scope), F-M15-3 — *partial milestone, security PASS*
- **M16:** F-M16-1 (P2), F-M16-2 (P2), F-M16-3 (P1 scope), F-M16-4 — *foundation PASS, partial milestone*
- **M17:** F-M17-1 (P1) — *not implemented*
- **Security:** S-1 *FIXED*, S-2 *FIXED*, S-3..S-9
- **DB:** DB-1..DB-9
- **Perf:** P-1..P-5
- **Test:** F-TEST-1 (P2/CI-P1) *FIXED*, F-TEST-2..F-TEST-5
- **Docker:** F-DOCKER-1 (P0 env) *FIXED*, F-DOCKER-2

### Appendix B — What was deep-audited vs sampled

- **Line-by-line:** `Money`, `PricingEngine`, `PaymentLedger`, `AgingCalculator`, `AgingBucket`, `RecordPaymentAction`, `VerifyPaymentAction`, `ReversePaymentAction`, `AllocatePaymentAction`, `ConfirmBookingAction`, `CreateBookingAction`, `CancelBookingAction`, `DeleteBookingAction`, `PlotOwnershipService`, `CompleteTransferAction`, `TransferWorkflowAction`, `CommissionCalculator`, `GenerateCommissionCases`, `RecordCommissionPayout`, `ConvertLeadToBuyer`, `InviteCustomerToPortal`, `ActivateCustomerPortal`, portal `Login` + `ResolvesPortalRecords` + `Show`/`Index`, `DocumentDownloadController`, `ReceiptPdfController`, portal `ReceiptDownloadController`, `HealthProbe`/`HealthController`, `SecurityHeaders`, `EnsureUserIsActive`, `EnsureCustomerPortalActive`, `bootstrap/app.php`, `routes/web.php`, `routes/console.php`, `DatabaseSeeder`, `DocumentPolicy`, `LeadPolicy`, `AppServiceProvider`, all `config/*`, `docker-compose*.yml`, `docker/php/*`, `docker/nginx/*`, `Pest.php`; live `SHOW CREATE TABLE` for 20+ tables.
- **Sampled / grep-level:** master-data CRUD, Blade views, `CollectionAnalytics`/`SalesAnalytics`/`InventoryAnalytics`/`MisAnalytics` internals, follow-up engine Actions, `AttributionSplit`, commission scheme resolver, the 31 policies (coverage checked; ~6 read in full), the 128 test files (executed; ~4 read in full).
- **Not executed:** a production `docker compose -f …prod.yml` bring-up (no TLS certs / prod `.env`); a real data-volume reconciliation; browser/UI testing.
