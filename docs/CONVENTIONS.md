# Engineering Conventions — King Builders Business OS

These conventions are established in **M0** and apply to every module built later
(Projects, Plots, Booking, Payments, Registry, …). The legacy CRM workflow is the
source of truth for *behaviour*; this document is the source of truth for *how we
implement it*.

---

## 1. Directory layout (`app/`)

| Folder            | Purpose                                                                              |
| ----------------- | ----------------------------------------------------------------------------------- |
| `app/Actions`     | Single-purpose write operations (`HoldPlot`, `TransferOwnership`). One public method. |
| `app/Services`    | Multi-step orchestration / integrations that don't fit a single Action.              |
| `app/Enums`       | String-backed enums for every domain status/type. Use `App\Enums\Concerns\HasLabel`. |
| `app/Policies`    | Authorization. One policy per model, registered via `Gate`/auto-discovery.           |
| `app/Support`     | Framework-agnostic helpers (`Branding`, value objects, concerns).                    |
| `app/Livewire`    | Full-page + nested Livewire components (the UI layer).                               |
| `app/Exceptions`  | `DomainException` + subclasses for expected business-rule failures.                  |

Empty folders are kept with `.gitkeep` until their milestone lands.

## 2. Validation

- **Form Requests** for controller endpoints; `$this->validate()` / `rules()` for
  Livewire components.
- Validate at the boundary (HTTP / Livewire). Actions & Services assume validated,
  typed input (prefer DTOs / typed args over arrays).
- Business-rule checks that need DB state (e.g. "plot not already sold") live in the
  Action and throw a `DomainException`, not in `rules()`.

## 3. Authorization

- Every mutating route/component checks a **Policy** (`$this->authorize(...)`).
- Roles & permissions arrive in **M1 (RBAC)**. Until then, policies may return
  `true` but the call sites must already be in place.
- Never authorize inside an Action — it must be callable from queue jobs & console.

## 4. Actions & Services

- Actions are invokable/`handle()`-style classes resolved from the container.
- A write path that touches >1 row uses `App\Support\Concerns\RunsInTransaction`
  and wraps the mutation in `$this->transaction(fn () => ...)`.
- Return the created/updated model or a typed result — never a raw array.
- Side effects (notifications, ledger entries) are dispatched **after** commit
  (`DB::afterCommit()` / queued events).

## 5. Database transactions

- Money, ownership, plot-status and cheque changes are **always** transactional.
- Use `lockForUpdate()` when reading a row you are about to mutate under contention
  (plot booking, cheque clearing).
- Migrations: additive only once a table ships; never edit a released migration.

## 6. Exceptions

- Expected failures → `App\Exceptions\DomainException` (renders 422 / toast).
- Programmer errors → let them bubble to the handler.
- Wrap third-party/integration errors in a dedicated exception before re-throwing.

## 7. Logging

- Use the `stack` channel. Structured context, no PII in messages:
  `Log::info('plot.held', ['plot_id' => $plot->id, 'buyer_id' => $buyer->id])`.
- Log **domain events** (state transitions), not CRUD noise.
- `report($e)` for caught-but-notable exceptions.

## 8. Enums

- One string-backed enum per status/type set, e.g. `PlotStatus`, `PaymentMethod`,
  `TransferType`.
- Implement `label()` and (for anything shown as a badge) `color()` returning a
  `<x-ui.badge>` variant.
- Cast on the model (`protected function casts()`).

## 9. Auditability

- Every domain state transition must be reconstructable. Standard approach:
  1. an **append-only** `*_events` / activity table per aggregate (added with the
     module), storing `event`, `causer_id`, `payload`, `created_at`;
  2. `created_by` / `updated_by` columns on mutable tables;
  3. domain events (`PlotHeld`, `OwnershipTransferred`) recorded in the same
     transaction as the change.
- No hard deletes on financial / ownership records — use soft deletes + a
  cancellation record (mirrors the legacy "Plot Cancellation" flow).

## 10. Front-end

- Blade + Livewire 3 + Tailwind v4 + Alpine (Alpine ships with Livewire — never
  import it separately).
- Use the `x-ui.*` component kit. Never hard-code a brand colour — reference the
  branding tokens (`bg-(--brand-primary)`, `text-(--content)`, …). Branding is
  configurable per project via `config/branding.php`.
- One full-page Livewire component per screen; extract nested components for
  reusable widgets.

## 11. Testing

- **Pest**. Feature tests for every screen and Action; Unit tests for enums,
  value objects, calculators (installment schedules, ledger math).
- Architecture tests (`tests/Feature/ArchTest.php`) enforce strict types & no
  debug statements.
- Money/date math is always covered by explicit unit tests.
