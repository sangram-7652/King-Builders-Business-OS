# Projects / Sites & Blocks (M3)

The top of the business hierarchy: **Project → Block → Plot → …**. M3 builds
Project + Block. Plot inventory is M4 — there are no plot tables or plot counts.

## Entities

### `projects` (`App\Models\Project`)

`name`, `code` (unique), `slug` (unique, URL-only — never the business key),
`description`, `status` (`App\Enums\ProjectStatus`), `is_active`,
`address`, `state_id`/`city_id` (nullable FK → M2 `states`/`cities`, `nullOnDelete`),
`pincode`, `latitude`/`longitude`, `contact_name`/`_phone`/`_email`,
`logo_path`/`cover_image_path` (paths only — no uploader in M3), `launch_date`.
Soft deletes.

### `blocks` (`App\Models\Block`)

`project_id` (FK, `cascadeOnDelete`), `name`, `code`, `description`,
`sort_order`, `is_active`. Soft deletes. **Unique `(project_id, code)`** — "Block A"
is valid in every project but only once per project.

Relationships: `Project hasMany Block` / `Block belongsTo Project`
(`Project::activeBlocks()` for the `is_active` subset).

## Status — `App\Enums\ProjectStatus`

`Planning · Active · OnHold · Completed · Closed`. The transition map is the
**single source of truth** (`ProjectStatus::transitionMap()`):

```
Planning  → Active, OnHold
Active    → OnHold, Completed
OnHold    → Active, Closed
Completed → Closed
Closed    → (terminal)
```

Reopening a `Closed` project is deliberately **not** a transition — it must be a
controlled business action added later. Status only ever changes through
`App\Actions\Projects\ChangeProjectStatus`, which throws `DomainException` on an
illegal move. Nothing else compares status strings.

`is_active` is a **separate** axis (archive / un-list) — orthogonal to `status`.

## Actions

`App\Actions\Projects\{SaveProject, ChangeProjectStatus, ToggleProjectActive, DeleteProject}`
`App\Actions\Blocks\{SaveBlock, ToggleBlockActive, DeleteBlock}`

- `SaveProject` fixes status to `Planning` and generates the slug on create;
  on update it never touches slug or status. Re-validates city∈state.
- `DeleteProject` soft-deletes the project and cascades a soft-delete to its
  blocks (one transaction). Refuses if `hasBusinessDependents()` (M4+).

## Delete / archive & historical integrity

`App\Models\Concerns\GuardsAgainstDestructiveDelete` — `businessDependents()`
returns `[]` today. Each future milestone appends its relation
(`['plots' => $this->plots(), 'bookings' => …]`); the delete Actions and the
policies already call `hasBusinessDependents()`, so enforcement lights up
automatically. M3 deletes are always **soft**.

## RBAC

Permissions (`PermissionGroup::Inventory`): `projects.view`, `projects.create`,
`projects.update`, `projects.delete`, **`projects.activate`**, **`projects.archive`**.
`projects.update` also gates status transitions and all block management —
**there is no `blocks.*` permission**. Granted in full to Super Admin / Admin and
Sales Manager; `projects.view` to the other operational roles + Viewer.

Enforced at route middleware + Livewire `$this->authorize()` + `ProjectPolicy` /
`BlockPolicy` (auto-discovered).

## UI

| Screen | Component | Route |
| --- | --- | --- |
| List (search, status/state/city/visibility filters, sortable columns, pagination) | `ProjectIndex` | `GET /projects` |
| Create / Edit | `ProjectForm` | `/projects/create`, `/projects/{project}/edit` |
| Detail — tabs Overview / Location / Blocks / Activity | `ProjectShow` | `/projects/{project}` (`?tab=`) |
| Block management (nested in the Blocks tab, slide-over form) | `ProjectBlocks` | — |

Views in `resources/views/livewire/projects/`. Sidebar gains a **Projects** item
(gated by `projects.view`); `projects` is removed from the "coming soon" roadmap
list. No fabricated plot statistics anywhere — the detail page shows only real
block counts.
