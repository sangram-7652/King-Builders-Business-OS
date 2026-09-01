# Master Data (M2)

Configuration data consumed by the future business modules (Projects, Plots,
Booking, Payments, Registry, Possession). **No business tables** are created in M2.

## The 15 masters

| Group | Master | Table | Notes |
| --- | --- | --- | --- |
| Property | Plot Categories | `plot_categories` | unique `code`, `sort_order` |
| Property | Plot Sizes / Areas | `plot_sizes` | numeric `area` + `unit` (`App\Enums\Masters\AreaUnit`) |
| Property | Plot Dimensions | `plot_dimensions` | numeric `width`/`length` + `unit`; unique `(width,length,unit)` |
| Property | PLC Types | `plc_types` | `calculation_type` (fixed / per-sq-ft / percentage) + `value` |
| Finance | TDS Rules | `tds_rules` | `percentage` (0–100), `applicable_from` / `applicable_until` |
| Finance | Interest Rules | `interest_rules` | `interest_type`, `rate`, `frequency`, `grace_period_days`, effective dates |
| Finance | Payment Types | `payment_types` | seeded set is `is_system` |
| Finance | Payment Modes | `payment_modes` | seeded set is `is_system`; `requires_reference` flag |
| Finance | Banks | `banks` | unique `name` / `code` |
| Finance | Bank Branches | `bank_branches` | `bank_id` FK (restrict), unique `ifsc`, unique `(bank_id,name)` |
| Location | States | `states` | unique `name` / `code` |
| Location | Cities | `cities` | `state_id` FK (restrict), unique `(state_id,name)` |
| Documents | Document Types | `document_types` | seeded set is `is_system` |
| Operations | Cancellation Reasons | `cancellation_reasons` | seeded set is `is_system` |
| Operations | Transfer Reasons | `transfer_reasons` | seeded set is `is_system` |

Every master table has: `is_active` (bool), `sort_order` (uint), `timestamps`,
**soft deletes**.

## Architecture — one screen, many masters

There are **no per-master controllers or Livewire classes**. Each master is a
declarative `App\Masters\Resources\<Name>Resource` (model, labels, group,
`fields()`, `rules()`, `filters()`). `App\Masters\MasterRegistry` catalogues
them.

- `App\Livewire\Masters\MasterDashboard` — `/settings/masters` overview
- `App\Livewire\Masters\MasterIndex` — `/settings/masters/{resource}` list (search, status + resource filters, pagination, activate/deactivate, delete)
- `App\Livewire\Masters\MasterForm` — `/settings/masters/{resource}/create` and `/{record}/edit`
- Views: `resources/views/livewire/masters/{dashboard,index,form,_field}.blade.php`

`App\Masters\Field` is a small builder describing one field (type, validation
hint, `->display()` for list cells, `->default()`). The generic `_field` partial
maps a field type to the existing `x-ui.*` components.

## Authorization

Permissions: `masters.view`, `masters.create`, `masters.update`, `masters.delete`
(`App\Enums\PermissionGroup::MasterData`). Granted to Super Admin / Admin (all)
and `masters.view` to every operational role; Accountant also gets create/update.

Enforced at **all three layers** (M1 convention):

1. **Route** — `permission:masters.*` middleware on every masters route.
2. **Component** — `MasterIndex` / `MasterForm` call `$this->authorize(...)` on
   every action.
3. **Policy** — a single `App\Policies\MasterDataPolicy` is registered against
   all 15 model classes in `AppServiceProvider`.

Hiding sidebar links / buttons is **not** the boundary — a direct Livewire call
by an unauthorised user returns 403 (covered by tests).

## Delete & historical-integrity rules

- Deletes are **soft** — a master referenced by historical business data stays
  resolvable (`Model::withTrashed()`).
- `App\Actions\Masters\DeleteMaster` refuses to delete a row that is:
  - `is_system` (seeded reference data), or
  - referenced by child data — `MasterModel::referencingRelations()` lists the
    relations to check. **Empty today**; each consuming module (M3+) adds its
    relation (e.g. `PlcType::referencingRelations() => ['bookingLines']`).
- The primary lifecycle control is **activate / deactivate**, not delete.
- **Snapshots** (e.g. the PLC rate applied to a booking) are *not* built in M2 —
  that belongs to the Booking / Pricing milestone (M6). M2 just keeps the master
  structures clean enough to snapshot from.

## Seeding

`Database\Seeders\Masters\MasterDataSeeder` runs 15 child seeders, each
idempotent (`updateOrCreate` on a natural key). Wired into `DatabaseSeeder` and
the container entrypoint (`db:seed --force`). Seeds real reference data
(Indian states/UTs, common banks, the prompt's payment types/modes, document
types, cancellation/transfer reasons) — **never** fake projects/plots/buyers.
