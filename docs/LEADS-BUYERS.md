# Leads & Buyers / Customer Management (M5)

The customer foundation. The hierarchy is **Lead → Buyer → Booking (M6)** — a
Lead is never attached directly to a Plot.

## Lead

`leads`: name, phone (indexed, **not unique**), email, `lead_source_id`
(FK → `lead_sources` master), `assigned_to` (FK users), status, notes,
`follow_up_at`, `converted_at` / `converted_by` / `buyer_id`, `created_by`.
Soft deletes.

Phone is intentionally **not unique** — the same person may enquire many times and
every submission keeps its own marketing source.

### `App\Enums\LeadStatus`

`New · Contacted · Interested · FollowUp · Qualified · Converted · Lost`.
Transition map is the single source of truth; `CONVERTED` is **never** a target
of `ChangeLeadStatus` (conversion is its own action) and is terminal;
`CONVERTED → New` and similar invalid moves are rejected. `Lost` can be
re-opened to `New` / `Contacted`.

### Assignment & scope

`assigned_to` is a plain FK to `users` — no salesperson table. Authorization is
**scoped, not role-based**:

- `leads.view` → see only leads you own (`created_by`) or are assigned to;
- `leads.view_all` → see the whole pipeline.

`Lead::scopeVisibleTo($user)` and `Lead::isVisibleTo($user)` implement it;
`LeadPolicy` composes `can(...)` + visibility. `AssignLead` refuses inactive
assignees.

### Follow-ups (`lead_follow_ups`)

`due_at`, `note`, `outcome` (`App\Enums\FollowUpOutcome`), `completed_at`,
`created_by`. `ScheduleFollowUp` / `CompleteFollowUp` keep `leads.follow_up_at`
in sync (= the earliest pending `due_at`). A completed follow-up can't be
completed again.

### Activity timeline (`lead_activities`)

Append-only (`UPDATED_AT = null`, no factory). `Lead::recordActivity(type, desc,
props, causer)` is the only writer. Events: created, assigned/unassigned,
status_changed, follow_up_scheduled/completed, converted. `properties` is a small
JSON blob — **never PII**.

## Buyer

`buyers`: `customer_code` (**unique**, `BUY-000001…`), first/middle/last name,
phone (indexed, **not unique**), alternate_phone, email (indexed, not unique),
date_of_birth, gender, occupation, address, `state_id`/`city_id` (FK → M2
masters), pincode, **`pan_number` / `aadhaar_number` (encrypted)**, status,
`created_by`. Soft deletes.

Contact details are **not unique** — households legitimately share a phone/email.

### `App\Enums\BuyerStatus`

`Active · Inactive · Archived`, with a transition map (`ChangeBuyerStatus`).

### Customer code — concurrency-safe

`code_sequences` table (per-key monotonic counter). `App\Support\Sequences\
SequenceGenerator::next('buyer')` locks the row `FOR UPDATE` inside the caller's
transaction, so racing conversions get distinct numbers. `Buyer::formatCode()`
turns the number into `BUY-000001`. The unique index is the final guard.

## Conversion — `ConvertLeadToBuyer`

```
DB::transaction:
  lock the lead row (FOR UPDATE), re-read status
  if CONVERTED  -> return the already-linked buyer  (IDEMPOTENT — no new buyer, no 2nd activity)
  if not QUALIFIED -> DomainException
  existing buyer id  -> load it
  else new buyer data -> CreateBuyer (bumps the sequence in the same tx)
  lead.status = CONVERTED; set converted_at / converted_by / buyer_id
  record "converted" activity
COMMIT   (anything throws -> full ROLLBACK, incl. the sequence increment)
```

## Duplicate handling — `App\Support\Leads\DuplicateFinder`

Phone (compared on trailing digits, so `+91 98765 43210` == `9876543210`) is the
primary signal, email secondary. `->leads()` and `->buyers()` return matches;
**nothing is ever merged or deleted automatically**:

- **Lead form** shows a warning listing matching leads — creation still allowed.
- **Convert screen** lists matching buyers with *Use this buyer* / *Create a new
  buyer instead* — the operator chooses explicitly.

## Sensitive data (PAN / Aadhaar)

- `encrypted` cast → ciphertext at rest (`text` columns; not searchable/indexable).
- `$hidden` → never appear in `toArray()` / JSON.
- Masked by default (`Buyer::maskedPan()` → `••••••234F`); the buyer detail page
  has a **Reveal** toggle gated by `buyers.documents` (`BuyerPolicy::viewDocuments`),
  re-authorised on every toggle.
- Actions log the buyer id / customer_code only — **never** the values; not in
  `DomainException` messages either. Covered by `BuyerSensitiveDataTest`.
- The buyer form only shows / accepts KYC fields for a `buyers.documents` holder;
  a blank value on edit keeps the stored one.

## RBAC

Leads: `leads.{view, view_all, create, update, delete, assign, convert, follow_up}`.
Buyers: `buyers.{view, create, update, delete, archive, documents}`
(`PermissionGroup::Sales`). Sales Manager gets the full set incl. `view_all` &
`documents`; Sales Executive gets scoped `leads.*` (no `view_all`/`assign`/`delete`)
+ `buyers` create/update; Viewer gets read + `leads.view_all`.

## Actions

`App\Actions\Leads\`: `CreateLead`, `UpdateLead`, `ChangeLeadStatus`,
`AssignLead`, `ScheduleFollowUp`, `CompleteFollowUp`, `ConvertLeadToBuyer`.
`App\Actions\Buyers\`: `CreateBuyer`, `UpdateBuyer`, `ChangeBuyerStatus`
(+ `Concerns\ValidatesBuyerLocation`).

## UI

| Screen | Component | Route |
| --- | --- | --- |
| Lead list (search, status/source/assigned/follow-up filters, sortable) | `LeadIndex` | `/leads` |
| Lead create / edit (live duplicate warning) | `LeadForm` | `/leads/create`, `/leads/{lead}/edit` |
| Lead detail (basic info, source, assignment, status, follow-ups, activity, conversion) | `LeadShow` | `/leads/{lead}` |
| Convert (existing-buyer picker + new-buyer form) | `LeadConvert` | `/leads/{lead}/convert` |
| Buyer list | `BuyerIndex` | `/buyers` |
| Buyer create / edit | `BuyerForm` | `/buyers/create`, `/buyers/{buyer}/edit` |
| Buyer detail (masked KYC + gated reveal) | `BuyerShow` | `/buyers/{buyer}` |

Lead Sources are a 16th Master Data resource (`Settings → Master Data → Sales`).
Sidebar gains **Leads** and **Buyers** (permission-gated).
