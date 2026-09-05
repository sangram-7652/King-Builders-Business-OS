# PRODUCTION REMEDIATION — King Builders Business OS (M0–M16)

**Task type:** Remediation of the findings in `FULL_SYSTEM_AUDIT_M0_M17.md`. No new features, no architecture redesign.
**Date:** 2026-09-04
**Scope:** the "fix before production" findings — M6, M8, M10, M11, M12, M14, M16 — plus low-risk cleanups.
**Out of scope (deliberately not touched):** M17 Accounting, M18 Mobile, lead scoring / merge, full marketing automation, provider-specific communication integrations.
**Baseline:** the four §32.1 "fix immediately" items (S-1, F-DOCKER-1, F-TEST-1, F-M12-2) were already committed on `main` at `95bf2e5`; this work preserves them.

---

## 1. Executive Summary

Every "fix before production" finding from the audit has been remediated in the working tree. The changes are additive and defensive:

- **F-M6-1** — `CancelBookingAction` now refuses to cancel a CONFIRMED booking while downstream financial/operational records reference it (payments, active plan, collection / registry / possession case, ownership transfer, signed agreement). The existing commission cascade (`BookingCommissionObserver`) is preserved and is *not* a blocker.
- **F-REP-1** — every money value leaving the analytics layer is canonicalised through bcmath (`FormatsReportMoney`); a new reconciliation test proves the collections report equals the operational `PaymentLedger` / `AgingCalculator` to the paise, including awkward non-round amounts.
- **F-REP-2** — every raw report query now applies `deleted_at IS NULL` for `bookings`, `payments`, `plots`, `leads`, `payment_plans` via `ReportFilterScope::notDeleted()`; a soft-deleted record can no longer appear in a report.
- **F-M5-1 / S-3 / DB-3 / F-M15-1** — a buyer's email is normalised (trim + lower-case) and made globally unique among live buyers via a STORED generated column + unique index (single-tenant system → global uniqueness is correct). Portal login, password reset and invitation all resolve email deterministically; an invitation cannot create an ambiguous identity.
- **F-M10-1 / DB-2** — the "one open ownership period per (booking, buyer)" invariant is now a database constraint (two VIRTUAL generated columns + a composite unique index; MySQL- and SQLite-compatible, no PostgreSQL-only partial index).
- **F-M14-2** — verified: voided payouts are already excluded from the overpayment guard (`recordedPayouts()` filters `status = recorded`). Locked in with an explicit test.
- **F-M14-3** — the configured `commission.eligibility.min_collected_percent` gate is now re-checked at payout time against current M7 truth (`CommissionEligibilityService::collectionShortfallReason()`), so a reversed payment / bounced cheque since case generation blocks the payout.
- **F-M12-3 / S-4** — all runtime `env()` calls removed from `app/`; security-header settings moved to `config/security.php`. A test asserts `app/` has zero `env()` calls, so `config:cache` is provably safe.
- **F-M12-1** — the production entrypoint runs `php artisan optimize` (config/route/event/view cache) at startup (not build, so the real `.env` is used).
- **F-M12-6** — `docker/php/php.ini` bakes `display_errors = Off`; production no longer depends only on the overlay.
- **F-M12-4** — `docker/deploy.sh` gained a preflight (compose validity, DB reachability, disk headroom), a verified-backup gate, a `migrate --pretend` dry run, and explicit mid-deploy failure guidance. No automatic destructive rollback.
- **F-M12-5** — `docker-compose.prod.yml` sets CPU/memory limits on every service, all env-overridable.
- **F-M16-3** — `Communicator` blocks any MARKETING-category message unless the recipient (a Buyer or Lead) has active marketing consent; transactional messages are never gated. Consent/opt-out columns + a `HasMarketingConsent` trait added.
- **F-M16-1** — a signed provider delivery webhook (`POST /webhooks/communication/{channel}`) is the only path to SENT→DELIVERED (or SENT→FAILED on a bounce). HMAC-SHA256 per channel, `hash_equals`, per-`(channel, event_id)` idempotency table, channel-scoped resolution.
- **F-M16-2** — `SendCommunicationJob` now takes a DB-level atomic claim (`Communication::claimForSending()`) with a lease, so two job instances for the same communication can never both call the provider; the queue's unique lock is demoted to a secondary hint (`uniqueFor` widened to 24 h).
- **F-M8-1** — the aging-bucket day boundaries (30/60/90/180) live in exactly one place (`AgingBucket::dayBounds()`); both `AgingBucket::fromDaysOverdue()` and `CollectionAnalytics` derive from it. A boundary test proves the operational and reporting paths agree at every edge.

**~55 new tests** were added; **no existing assertion was weakened**. Two existing tests were *corrected* because they asserted the vulnerable behaviour the audit flagged (see §4).

---

## 2. Findings Fixed

| ID | Finding | Fix | Test |
|---|---|---|---|
| F-M6-1 | Confirmed-booking cancellation frees the plot without unwinding downstream state | `BookingCancellationGuard` (`app/Support/Bookings/`) — blockers for live payments / active plan / open collection / registry / possession case / in-progress transfer / signed agreement; wired into `CancelBookingAction` for CONFIRMED bookings only | `BookingCancellationGuardTest` (12) |
| F-REP-1 | Report money aggregated as PHP `float` | `FormatsReportMoney` trait — every SQL aggregate canonicalised through `Money` (bcmath); PHP money subtraction via `reportMoneyMinus()` | `ReportLedgerReconciliationTest` (2), plus `AgingBucketBoundaryTest` |
| F-REP-2 | Raw report SQL inconsistently excludes soft-deleted rows | `ReportFilterScope::notDeleted(...)` applied at every base-table entry point across `CollectionAnalytics`, `SalesAnalytics`, `InventoryAnalytics`, `MisAnalytics`, `OperationsAnalytics` | `ReportSoftDeleteTest` (see §17), plus existing report suite (213) |
| F-M5-1 / S-3 / DB-3 / F-M15-1 | `buyers.email` not unique → non-deterministic portal login | migration `2026_09_27_000001` (normalise → de-dupe → `email_canonical` STORED generated column + unique index); `Buyer::normalizeEmail()` + `email` mutator; deterministic resolution in `Login`, `RequestCustomerPasswordReset`, invite guard in `InviteCustomerToPortal`; `BuyerFactory` gives unique emails | `BuyerEmailIdentityTest` (9), updated `BuyerManagementTest` |
| F-M10-1 / DB-2 | No DB invariant against overlapping active ownership | migration `2026_09_27_000002` — `open_booking_id` / `open_buyer_id` VIRTUAL generated columns + composite unique index | `OwnershipHistoryInvariantTest` (4) |
| F-M14-2 | Overpayment guard may count voided payouts | verified already correct (`recordedPayouts()` filters `status = recorded`) — locked in | `CommissionPayoutGuardsTest` (1 of 4) |
| F-M14-3 | Commission payable at 0% collection | `CommissionEligibilityService::collectionShortfallReason()` re-checked in `RecordCommissionPayout` against current M7 truth | `CommissionPayoutGuardsTest` (3 of 4) |
| F-M12-1 | Prod image ships no artisan caches | `php artisan optimize` in `docker/php/entrypoint.sh` for `APP_ENV=production` (startup, not build) | `ConfigCacheSafetyTest` |
| F-M12-3 / S-4 | Runtime `env()` in middleware breaks under `config:cache` | `config/security.php`; `SecurityHeaders` + `bootstrap/app.php` read config; **zero `env()` in `app/`** | `ConfigCacheSafetyTest` (3), updated `SecurityHeadersTest` |
| F-M12-4 | Deploy runs migrations with no safety gate | `docker/deploy.sh` — preflight, DB-reachability wait, verified-backup gate, `migrate --pretend`, mid-deploy failure guidance | `bash -n` clean; manual dry-run documented |
| F-M12-5 | No container resource limits | `deploy.resources.limits` on app / nginx / mysql / redis / queue / scheduler in `docker-compose.prod.yml`, env-overridable | `docker compose config` valid |
| F-M12-6 | Baked PHP `display_errors = On` | `docker/php/php.ini` → `Off` (+ `display_startup_errors = Off`) | prod-hardening suite |
| F-M16-1 | No webhook → DELIVERED unreachable | `WebhookVerifier` (HMAC), `WebhookController`, `communication_webhook_events` table, route in `bootstrap/app.php` (`throttle:120,1`, no session/CSRF) | `DeliveryWebhookTest` (10) |
| F-M16-2 | Job unique lock can expire mid-retry → duplicate send | `Communication::claimForSending()` atomic lease claim in `SendCommunicationJob`; `uniqueFor` → 86400 | `CommunicationJobIdempotencyTest` (5) |
| F-M16-3 | No consent gate before marketing sends | `HasMarketingConsent` trait on Buyer + Lead; migration `2026_09_27_000003`; gate in `Communicator::send()` | `MarketingConsentTest` (6) |
| F-M8-1 | Aging boundaries duplicated across `AgingCalculator` / `CollectionAnalytics` | `AgingBucket::dayBounds()` / `::thresholds()` single source; both paths derive from it | `AgingBucketBoundaryTest` (3) |

For each: **root cause**, **fix**, **why it's safe**, **test**, **verification** — see §8 and §9.

---

## 3. Findings Intentionally Deferred

| ID | Why deferred |
|---|---|
| M17 Accounting (F-M17-1, F-M14-1, M7/M8→M17 recon) | Explicitly out of scope. M17 does not exist; building it is the next milestone. Commission payouts remain operational-only (no ledger entry) as before. |
| F-M13-1 (lead scoring / merge / automation / dashboards) | New feature work, out of scope. The dead `leads.merge` permission + `LeadPolicy::merge()` were left in place (removing them is also out of scope and low-risk to keep). |
| F-REP-1 full decimal-typed DTOs | The analytics DTOs still declare `float`. Changing every DTO/blade/exporter signature is a large non-behavioural churn on a passing suite. The bcmath canonicalisation + reconciliation test guarantee correctness at the boundary; a full type migration is a follow-up. |
| F-M16 templates / versions / preferences / opt-out UI | Beyond "consent gate". Only the gate + a minimal consent model were added. |
| DB-4 / F-M10-3 / F-M14-4 (`CASCADE` → `RESTRICT` on ledger-like FKs) | Unreachable today (soft-deletes + delete-action guards). Changing FK cascade behaviour on live tables is riskier than the latent issue; recommended as a scheduled maintenance migration. |
| F-M1-2 / S-7 (report row-level scoping) | By design (`reports.view` = management view). Documented, not changed. |
| DB-1 (missing migration number `2026_09_23_000003`) | Cosmetic gap in the sequence; `migrate:status` is clean. No action. |

---

## 4. Existing Tests Corrected (not weakened)

Two tests asserted the exact behaviour the audit flagged as a defect. They were rewritten to assert the corrected behaviour — no assertion was removed to make a test pass:

1. **`BuyerManagementTest` → "phone / email are not unique — households may share them"** — split into "phone is not unique" (kept) + "email IS unique among live buyers (F-M5-1)" + "a soft-deleted buyer does not block re-registering the same email". The old test encoded the ambiguous-login vulnerability.
2. **`SecurityHeadersTest` → "can be disabled entirely via env" / "honours a custom CSP from env"** — rewritten to drive the middleware through `config('security.*')` instead of `putenv()`, matching the F-M12-3 refactor. Same behaviour verified, now `config:cache`-safe. A new "omits the CSP header when set to 'off'" case was added.

Helper fixes (no behaviour change): `PortalDashboardTest::portalCustomerWithBooking()` and `BuyerFactory` now generate unique emails (required by the new unique index).

---

## 5. Files Changed

**New — application:**
- `app/Support/Bookings/BookingCancellationGuard.php`
- `app/Support/Reports/Concerns/FormatsReportMoney.php`
- `app/Models/Concerns/HasMarketingConsent.php`
- `app/Communication/WebhookVerifier.php`
- `app/Http/Controllers/Communication/WebhookController.php`
- `config/security.php`

**New — migrations:**
- `2026_09_27_000001_normalize_and_unique_buyer_email.php`
- `2026_09_27_000002_add_open_ownership_period_invariant.php`
- `2026_09_27_000003_add_marketing_consent_to_buyers_and_leads.php`
- `2026_09_27_000004_create_communication_webhook_events_table.php`

**Modified — application:**
- `app/Actions/Bookings/CancelBookingAction.php` — inject + apply `BookingCancellationGuard`
- `app/Actions/Payments/RecordPaymentAction.php` — payment-date sanity bounds (cleanup)
- `app/Actions/Commission/RecordCommissionPayout.php` — inject `CommissionEligibilityService`, re-check collection gate
- `app/Services/Commission/CommissionEligibilityService.php` — extract `collectionShortfallReason()`
- `app/Services/Reports/CollectionAnalytics.php` — `notDeleted()` + `FormatsReportMoney` + `AgingBucket::thresholds()`
- `app/Services/Reports/SalesAnalytics.php`, `InventoryAnalytics.php`, `MisAnalytics.php`, `OperationsAnalytics.php` — `whereNull(deleted_at)` on every raw base query
- `app/Queries/Reports/ReportFilterScope.php` — `notDeleted()` helper
- `app/Enums/AgingBucket.php` — `dayBounds()` / `bounds()` / `thresholds()`; `fromDaysOverdue()` derives from them
- `app/Models/Buyer.php` — `email` mutator + `normalizeEmail()`, `HasMarketingConsent`, consent casts
- `app/Models/Lead.php` — `HasMarketingConsent`, consent casts
- `app/Models/Communication.php` — `claimForSending()`
- `app/Livewire/Portal/Auth/Login.php` — deterministic email resolution
- `app/Actions/Customers/InviteCustomerToPortal.php` — duplicate-email guard
- `app/Actions/Customers/RequestCustomerPasswordReset.php` — normalised email lookup
- `app/Services/Communication/Communicator.php` — marketing consent gate
- `app/Jobs/SendCommunicationJob.php` — claim-based idempotency, `uniqueFor`
- `app/Http/Middleware/SecurityHeaders.php` — `config('security.*')`
- `bootstrap/app.php` — webhook route; trusted-proxy comment
- `database/factories/BuyerFactory.php` — unique emails

**Modified — infra / config:**
- `config/communication.php` — (unchanged; secrets already present)
- `docker/php/php.ini` — `display_errors = Off`
- `docker/php/entrypoint.sh` — `php artisan optimize` for production
- `docker/deploy.sh` — preflight / backup gate / pretend / failure guidance
- `docker-compose.prod.yml` — resource limits, `TRUSTED_PROXIES` env
- `.gitignore` — `/king_builders` (stray SQLite scratch DB); file removed from the index

**New — tests:** `BookingCancellationGuardTest`, `BuyerEmailIdentityTest`, `OwnershipHistoryInvariantTest`, `CommissionPayoutGuardsTest`, `MarketingConsentTest`, `CommunicationJobIdempotencyTest`, `DeliveryWebhookTest`, `ConfigCacheSafetyTest`, `SuperAdminSeederTest` (from the earlier commit), `ReportLedgerReconciliationTest`, `AgingBucketBoundaryTest`, `ReportSoftDeleteTest`.

---

## 6. Migrations Added

| Migration | Effect | Reversible |
|---|---|---|
| `2026_09_27_000001_normalize_and_unique_buyer_email` | normalise emails, NULL duplicates on all-but-oldest live buyer (logged), add `email_canonical` STORED generated column + unique index | yes (`down()` drops index + column; NULLed emails are not restored — recover from the `buyers.email_deduplicated` log) |
| `2026_09_27_000002_add_open_ownership_period_invariant` | `open_booking_id` / `open_buyer_id` VIRTUAL generated columns + composite unique index on `plot_ownership_history` | yes |
| `2026_09_27_000003_add_marketing_consent_to_buyers_and_leads` | `marketing_consent_at` / `marketing_opt_out_at` timestamps on `buyers` + `leads` | yes |
| `2026_09_27_000004_create_communication_webhook_events_table` | new table with unique `(channel, event_id)` | yes (`dropIfExists`) |

All four are additive. The only data mutation is the buyer-email de-duplication in `000001`, which is logged and only touches emails that were *already* ambiguous.

---

## 7. Routes Added / Changed

| Method | URI | Name | Middleware | Notes |
|---|---|---|---|---|
| POST | `/webhooks/communication/{channel}` | `communication.webhook` | `throttle:120,1` | Registered in `bootstrap/app.php` `then:` — outside `web`, so **no session, no CSRF**. Authenticity = per-channel HMAC signature. |

No existing route changed.

---

## 8. Security Changes

- **F-M5-1** — email is now a reliable identity; a duplicate can no longer be created and portal login is deterministic. Cross-customer IDOR protections (`ResolvesPortalRecords`) are untouched and still pass.
- **F-M12-3 / S-4** — no runtime `env()` in `app/`; the security-header kill-switch works from cached config.
- **F-M16-1** — webhook endpoint is HMAC-signed (`hash_equals`), rate-limited, idempotent, channel-scoped. A bad/missing signature is 403 and changes nothing. A channel with no configured secret rejects every webhook.
- **F-M16-3** — marketing messages cannot go to a party without recorded consent, or one who opted out.
- **F-M6-1** — a confirmed booking with money attached can no longer be silently unwound.
- No new authentication/authorization surface was added except the signed webhook. No secrets were committed.

---

## 9. Financial Correctness Changes

- **F-REP-1 + F-M8-1** — report money is bcmath-canonicalised; report aging boundaries and the operational `AgingCalculator` share one source. `ReportLedgerReconciliationTest` proves the collections report == `PaymentLedger`/`AgingCalculator` to the paise on non-round amounts.
- **F-REP-2** — a soft-deleted booking / payment / plan / plan-row can no longer inflate a report figure.
- **F-M14-2 / F-M14-3** — commission payouts respect the collection threshold against *current* M7 truth and never double-count a voided payout.
- **F-M6-1** — operational truth (payments, plans, cases) is protected from an unsafe booking cancellation.
- No operational (M7/M8) calculation was changed. `PaymentLedger`, `AgingCalculator`, `Money`, the pricing engine and the commission calculator are untouched.

---

## 10. Test Coverage Added

**65 new / rewritten tests**, all green.

| Area | New tests |
|---|---|
| A. Booking cancellation safety | `BookingCancellationGuardTest` — 12 (no-deps happy path, payment, plan, collection, registry, possession, transfer, agreement, rejected-transfer allowed, commission cascade preserved, DRAFT/PENDING unaffected, plot-not-freed on rejection) |
| B. Report decimal precision | `ReportLedgerReconciliationTest` — 2 (KPIs vs ledger to the paise; reconciliation block) |
| C. Report soft-delete consistency | `ReportSoftDeleteTest` — soft-deleted booking / payment excluded from every report |
| D. Buyer email uniqueness / login ambiguity | `BuyerEmailIdentityTest` — 9 |
| E. Ownership overlap DB invariant | `OwnershipHistoryInvariantTest` — 4 (DB rejects 2nd open period; co-owners allowed; re-own after close; transfer flow intact) |
| F. config:cache safety | `ConfigCacheSafetyTest` — 3 (no `env()` in `app/`; config exposure; middleware reads config) |
| G. M14 payout void handling | `CommissionPayoutGuardsTest` — 1 |
| H. Minimum-collection commission gate | `CommissionPayoutGuardsTest` — 3 |
| I. Marketing consent | `MarketingConsentTest` — 6 |
| J. Communication webhook signature / idempotency / isolation | `DeliveryWebhookTest` — 10 |
| K. Communication retry idempotency | `CommunicationJobIdempotencyTest` — 5 |
| L. M8 / report aging boundary consistency | `AgingBucketBoundaryTest` — 3 |

---

## 11. Docker Verification

Run against the local stack (service names from `docker-compose.yml`):

| Command | Result |
|---|---|
| `docker compose ps` | 6/6 up; `mysql` + `redis` healthy |
| `docker compose config -q` (base) | valid |
| `docker compose -f docker-compose.yml -f docker-compose.prod.yml config -q` (with dummy secrets) | valid — the new `deploy.resources.limits` blocks parse |
| `docker compose exec app php artisan about` | Laravel 12.68.0, PHP 8.3.33 |
| `docker compose exec app php artisan migrate:status` | all 84 migrations `Ran` (the 4 new ones in batches 10–13) |
| `docker compose exec app php artisan route:list` | 105 routes (+1 = `communication.webhook`) |
| `docker compose exec app php artisan queue:failed` | none |
| `php artisan migrate:rollback --step=4` then `migrate` | all 4 new migrations roll back **and** re-apply cleanly |
| `bash -n docker/deploy.sh` | clean |

## 12. Config-Cache Verification

`php artisan optimize` (config + event + route + view cache) runs clean **with the new webhook route and `config/security.php`**; the app boots and serves `/up` + `/healthz` from cached config; `route:cache` succeeds (controller route, no closure); `optimize:clear` restores. `ConfigCacheSafetyTest` asserts `app/` contains **zero** runtime `env()` calls, so caching cannot silently change behaviour.

---

## 13. Before / After

| | Before (baseline `95bf2e5`) | After remediation |
|---|---|---|
| **`php artisan test --parallel --processes=4`** | 1085 passed / 0 failed / 5 skipped | **1147 passed / 0 failed / 5 skipped** (4820 assertions, ~560 s) |
| Runtime `env()` calls in `app/` | 3 (`SecurityHeaders`) | **0** |
| Report money aggregation | PHP `float`, unreconciled | bcmath-canonicalised; reconciliation test to the paise |
| Report raw-SQL soft-delete filter | inconsistent | applied at every base-table entry point |
| `buyers.email` | non-unique, non-normalised | normalised + `email_canonical` unique index |
| Overlapping open ownership periods | app-enforced only | DB composite unique index |
| Commission payout below collection threshold | allowed | blocked (against current M7 truth) |
| Marketing message without consent | allowed | blocked |
| Communication SENT → DELIVERED | unreachable (no webhook) | signed, idempotent webhook |
| Concurrent `SendCommunicationJob` instances | could both call the provider | atomic DB claim — one only |
| Prod nginx `:80` PHP | (fixed in `95bf2e5`) | preserved |
| Prod artisan caches | none | `optimize` on startup |
| Prod `display_errors` | `On` (overlay-dependent) | `Off` baked |
| Prod container limits | none | CPU/memory on all 6 services |
| Deploy migration safety | no gate | preflight + verified-backup gate + `--pretend` |

> **Note on the earlier 194-failure `--parallel` report:** that run was corrupted by several `php artisan test` processes executing concurrently in the same container (shared paratest cache dir). A single clean `--parallel` run — and every isolated suite re-run — is fully green. Always run one test process at a time in this container.

### Docker verification — see §11. Config-cache verification — see §12.

---

## 14. Remaining Risks

| Risk | Severity | Mitigation / recommendation |
|---|---|---|
| Analytics DTOs still typed `float` | Low | Correctness is guarded by `ReportLedgerReconciliationTest`; migrate DTOs to decimal strings in a follow-up. |
| Buyer-email de-dup migration NULLs the newer duplicates | Low | Only touches already-ambiguous data; logged as `buyers.email_deduplicated`. Run on a copy first; review the log after. |
| `email_canonical` STORED generated column requires a table rewrite on `buyers` | Low | `buyers` is small; take the standard pre-migration backup (deploy.sh gate). |
| Webhook payload is a normalised shape, not provider-native | Low | Provider adapters are a follow-up; the verification + idempotency + state-machine core is done. |
| Ledger-like FKs still `ON DELETE CASCADE` | Low | Unreachable today; schedule a `RESTRICT` migration. |
| M17 absent | (scope) | Next milestone. |

---

## 15. Production Readiness Assessment

The audit's "fix before production" list is cleared. Combined with the already-committed §32.1 fixes, the **M0–M16 system** is a **conditional GO** for a deployment scoped to *bookings → payments → collections → documents → registry → possession → transfer → reporting → partners/commission → customer portal → communication*, subject to:

1. running the four new migrations behind the deploy.sh backup gate,
2. setting `COMM_*_WEBHOOK_SECRET` per channel that will report delivery (else delivery tracking stays off — SENT is the terminal state, which is correct),
3. the operator-side items already in `docs/DEPLOYMENT.md` (real `.env`, TLS certs, `SEED_SUPERADMIN_PASSWORD`).

M13 (CRM automation beyond M13.1), M15 portal surfaces beyond bookings/payments, M16 templating, and **M17 Accounting** remain future milestones.

---

## REMEDIATION STATUS: **PASS WITH REMAINING LOW-RISK ISSUES**

### Remaining issues (all low-risk, none a production blocker)

1. Analytics DTOs still declare `float` (correctness test-guarded; type migration is a follow-up).
2. `communication_webhook_events` has no retention/prune job — add a `Schedule::command('model:prune')` or a dedicated sweep.
3. Provider-native webhook payload adapters not built (normalised shape only).
4. Ledger-like child FKs (`commission_cases`, `transfer_requests`, `plot_ownership_history`, …) still `ON DELETE CASCADE` from `bookings` — schedule a `RESTRICT` migration.
5. Dead `leads.merge` permission + `LeadPolicy::merge()` left in place (M13 scope).
6. Report row-level scoping (`reports.view` = all salespeople) — by design, undocumented in-app.
7. Missing migration number `2026_09_23_000003` — cosmetic.
