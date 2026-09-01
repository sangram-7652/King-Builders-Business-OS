# Plot Inventory & Lifecycle (M4)

The leaf of **Project → Block → Plot**. M4 is inventory + lifecycle only — no
buyers, bookings, pricing, registry or possession.

## `plots` table (`App\Models\Plot`)

`project_id` + `block_id` (both FK `restrictOnDelete`), `plot_number`,
`plot_category_id` / `plot_size_id` / `plot_dimension_id` (nullable FK → M2
masters, `nullOnDelete`), **`area` + `area_unit` (snapshot)**, `facing`
(`App\Enums\PlotFacing`, nullable), `status` (`App\Enums\PlotStatus`),
`is_active`, hold metadata (`held_at`, `hold_expires_at`, `hold_reason`,
`held_by`), soft deletes.

- **Unique `(project_id, block_id, plot_number)`** — "Block A / 101" is valid in
  every project, once per project+block.
- `restrictOnDelete` on project/block + `Block`/`Project::businessDependents()`
  now returns `['plots' => …]`, so a project/block with plots can't be
  destructively deleted (M3's guard lights up).
- M2 `PlotCategory` / `PlotSize` / `PlotDimension` gained
  `referencingRelations() => ['plots']` — a referenced master can't be hard-deleted.

### Area snapshot

`plot_size_id` records *which* named size was chosen; `area` + `area_unit` are
**copied onto the plot at creation** and thereafter independent. Editing a Plot
Size master never rewrites a plot's area. (Booking-level snapshots are M5/M6.)

## Status — `App\Enums\PlotStatus`

`Available · Hold · Booked · Sold · Cancelled · Transferred`. Transition map (single
source of truth):

```
Available → Hold
Hold      → Available, Booked
Booked    → Sold, Cancelled
Sold / Cancelled / Transferred → terminal
```

`TRANSFERRED` is **never** a target of the generic `ChangePlotStatus` action — it
needs a dedicated transfer workflow (later milestone).

`AVAILABLE ⇄ HOLD` go through `HoldPlotAction` / `ReleasePlotHoldAction` (hold
metadata + row locking). `HOLD → BOOKED`, `BOOKED → SOLD/CANCELLED` go through
`ChangePlotStatus` — valid here, but ultimately driven by M5+ modules.

## Actions

`App\Actions\Plots\`: `CreatePlot`, `UpdatePlot` (never touches status/hold),
`ChangePlotStatus` (generic, map-enforced, row-locked), `HoldPlotAction`,
`ReleasePlotHoldAction`, `TogglePlotActive`, `BulkCreatePlots`,
`ExpirePlotHoldsAction`. Shared `Concerns\ResolvesPlotArea` (block∈project check
+ area-snapshot resolution).

## Hold lifecycle

1. `HoldPlotAction(plotId, heldBy, expiresAt?, reason?)` — `AVAILABLE → HOLD`,
   sets the 4 hold fields.
2. `ReleasePlotHoldAction(plotId, context)` — `HOLD → AVAILABLE`, clears the hold
   fields. `context` = `manual` | `expiry` for the audit log.
3. Any other exit from `HOLD` (e.g. `→ BOOKED` via `ChangePlotStatus`) also
   clears the hold fields.

## Concurrency

Both `HoldPlotAction` and `ReleasePlotHoldAction`:

```
DB::transaction(function () {
    $plot = Plot::whereKey($id)->lockForUpdate()->firstOrFail();  // SELECT … FOR UPDATE
    // re-read status INSIDE the lock
    if ($plot->status !== Available) throw new DomainException(...);
    // mutate + save
});
```

The row lock serialises concurrent requests at MySQL; the in-transaction
re-read means the loser of a race sees `HOLD` and is rejected. Verified by
`tests/Feature/Plots/PlotConcurrencyTest.php`:
- the losing hold is rejected once the plot is claimed (every driver);
- `HoldPlotAction` emits `SELECT … FOR UPDATE` (MySQL);
- two live MySQL sessions: session B's `FOR UPDATE` times out while session A
  holds the lock (real DB serialisation).

## Bulk creation

`BulkCreatePlots::handle(Project, Block, config)` — `prefix` + `from..to` +
optional zero-pad `pad`. Cap: **500 per run**. Flow: validate range → check *none*
of the generated numbers already exist in `(project, block)` → build all rows →
`DB::transaction` → `Plot::insert($rows)`. Any `QueryException` (e.g. the unique
index catching a concurrent run) → `DomainException`, whole batch rolled back —
**never partially created**.

## Scheduler / queue

- `App\Jobs\ExpirePlotHoldsJob` (`ShouldQueue` + `ShouldBeUnique`, `uniqueFor 300`)
  → runs `ExpirePlotHoldsAction`.
- `routes/console.php`: `Schedule::job(new ExpirePlotHoldsJob)->everyFiveMinutes()
  ->name('expire-plot-holds')->withoutOverlapping()` — dispatched onto the Redis
  queue, processed by the `queue` container.
- `ExpirePlotHoldsAction` — `chunkById` over `Plot::holdExpired()`, each plot
  re-locked & re-checked in its own transaction; a plot that has meanwhile moved
  to `BOOKED` (etc.) is skipped. **Idempotent** — a second run releases nothing.
- Manual: `php artisan plots:expire-holds`.

## RBAC

`plots.{view, create, update, delete, hold, release, activate, archive, bulk_create}`
(`PermissionGroup::Inventory`). `plots.update` also gates generic status changes.
`App\Policies\PlotPolicy` (auto-discovered). Sales Manager gets the full set;
Sales Executive gets `view` + `hold` + `release`; other operational roles get `view`.

## UI

| Screen | Component | Route |
| --- | --- | --- |
| Project inventory (live status counts + per-block breakdown) | `ProjectInventory` | project detail → **Inventory** tab |
| Block plot list (counts, search + status/category/size/dimension/facing/visibility filters, sortable, pagination, hold/release/edit) | `PlotIndex` | `/projects/{project}/blocks/{block}/plots` |
| Plot create / edit | `PlotForm` | `.../plots/create`, `.../plots/{plot}/edit` |
| Bulk add | `PlotBulkCreate` | `.../plots/bulk` |
| Plot detail (spec, hold panel, status dropdown, archive; reserved sections listed, not implemented) | `PlotShow` | `.../plots/{plot}` |

Routes use `->scopeBindings()` — a block from another project or a plot from
another block 404s. Views in `resources/views/livewire/plots/`. All counts are
live DB aggregates (`App\Support\Plots\PlotStatusCounts`) — never fabricated.
