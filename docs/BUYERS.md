# Buyers / Customer Management (M5)

The customer foundation. The hierarchy is **Buyer → Booking (M6)** — a Buyer
is created directly (there is no lead/enquiry stage) and is never attached
directly to a Plot.

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
transaction, so racing creates get distinct numbers. `Buyer::formatCode()`
turns the number into `BUY-000001`. The unique index is the final guard.

## Duplicate handling — `App\Support\Buyers\DuplicateFinder`

Phone (compared on trailing digits, so `+91 98765 43210` == `9876543210`) is the
primary signal, email secondary. `->buyers()` returns matches; **nothing is
ever merged or deleted automatically** — the buyer create form shows a warning
listing matching existing buyers, creation still allowed.

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

`buyers.{view, create, update, delete, archive, documents}`
(`PermissionGroup::Sales`). Sales Manager gets the full set incl.
`documents`; Sales Executive gets create/update (no delete/archive/documents).

## Actions

`App\Actions\Buyers\`: `CreateBuyer`, `UpdateBuyer`, `ChangeBuyerStatus`
(+ `Concerns\ValidatesBuyerLocation`).

## UI

| Screen | Component | Route |
| --- | --- | --- |
| Buyer list | `BuyerIndex` | `/buyers` |
| Buyer create / edit (live duplicate warning) | `BuyerForm` | `/buyers/create`, `/buyers/{buyer}/edit` |
| Buyer detail (masked KYC + gated reveal) | `BuyerShow` | `/buyers/{buyer}` |

Sidebar gains **Buyers** (permission-gated).
