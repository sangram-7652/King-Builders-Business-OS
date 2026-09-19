# User Guide — How King Builders Business OS Works

This is the plain-language companion to the other `docs/*.md` files, which
describe *data models and internals* module by module. This guide instead
answers: **what does this system do, who uses which part, and how does a
sale move through it end to end?**

If you want engineering detail on any module (fields, actions, invariants),
the matching technical doc is linked at the end of each section below.

---

## 1. What this is

A real-estate ERP for running a plotted-development business: everything
from a customer buying a plot through to "the plot is registered, handed
over, and the customer owns it." One system replaces separate
spreadsheets/tools for sales, collections, documentation, and registry.

Two separate front doors:

- **Staff app** — everyone inside the business (sales, accounts, collections,
  registry, management). Guard: `web`.
- **Customer portal** — buyers log in themselves to see *their own* bookings,
  payment schedule, and receipts. Guard: `customer`. Completely separate
  session/login from staff — a customer session can never access staff
  screens and vice versa.

## 2. Logging in

- Staff: `/login` (Livewire form). Seeded super admin —
  `super@kingbuilders.test` / `password` (change this before going anywhere
  near production).
- Customer portal: `/portal/login`. Buyers don't self-register — an admin/
  sales user invites them from the buyer record, the buyer gets an
  activation link (`/portal/activate/{token}`), sets a password, and from
  then on logs in normally. If a customer's account is deactivated, they're
  blocked by the `customer.active` middleware even with a valid session.

Once logged in, the left sidebar only shows what your role has permission
to see — if a module is invisible to you, it's a permissions issue, not a
missing feature. See [`docs/RBAC.md`](RBAC.md) for how permissions work
under the hood.

## 3. Roles — who does what

Permissions are fine-grained (`bookings.create`, `payments.verify`, …) and
assigned to roles, not hard-coded per user. The seeded roles and their
intent:

| Role | Day-to-day job |
|---|---|
| **Super Admin / Admin** | Full access to everything, including Users, Roles, and Master Data. |
| **Sales Manager** | Everything a Sales Executive does, plus pricing overrides, booking cancellation, collections oversight, and project/plot setup. |
| **Sales Executive** | Owns buyers → bookings. Can hold/release plots and view (not manage) pricing and payments. |
| **Accountant** | Payment plans, recording/verifying/allocating/reversing payments, receipts, cheque bounces, penalties, registry expenses, and the commission payout workflow. |
| **Collection Manager / Collection Executive** | The collections queue and cases, promise-to-pay tracking, cheque follow-up. Manager has team-wide visibility and assignment; Executive works their own queue. |
| **Registry Manager** | Documents (upload/verify/reject), agreements, registry cases and appointments, registry expenses, document handover. |
| **Possession Manager** | Possession eligibility, clearances, site inspection, customer handover, and ownership transfer requests end to end. |
| **Associate Manager** | Channel partner (broker) onboarding/approval, commission schemes, and the commission case workflow. |
| **Viewer** | Read-only across everything — for oversight/audit users who shouldn't change data. |

A user can hold more than one role. Custom roles can be created from
**Administration → Roles & Permissions** without touching code.

## 4. The business flow, end to end

This is the backbone every plot sale follows through the system:

```
Buyer  ──book a plot──▶  Booking (priced, confirmed)
                               │
                ┌──────────────┼───────────────┐
                ▼              ▼               ▼
         Payment Plan   Documents/Agreement  Channel-partner
         + Payments      (KYC + sale docs)    commission
                │              │
                ▼              ▼
          Collections    Registry (eligibility →
          (if overdue)    appointment → completion)
                               │
                               ▼
                      Possession → Handover
                               │
                               ▼
               Ownership Transfer (if resold/gifted)
                 + append-only ownership ledger
```

Everything downstream is *gated* by what happened upstream — you can't book
a plot that's on hold for someone else, you can't reach registry until the
payment/document eligibility checks pass, and possession/handover checks
outstanding dues and clearances first. The system enforces this instead of
relying on someone remembering the checklist.

## 5. How to create a project

A **Project** is a development/site (e.g. "Madhav Kunj") — the top of the
`Project → Block → Plot` hierarchy. You need `projects.create` permission
(Admin and Sales Manager have it by default).

1. Go to **Projects** in the sidebar, then click **New Project**
   (`/projects/create`).
2. **Basics**
   - **Name** — required, e.g. `Madhav Kunj`.
   - **Description** — optional.
   - **Launch date** — optional.
3. **Location**
   - **State** — required. Pick this first.
   - **City** — optional, and its list only loads *after* a state is
     selected (it's filtered to that state).
   - **Address**, **Pincode** — optional.
   - States and cities come from **Master Data** — if the state/city you
     need isn't in the dropdown, it has to be added there first
     (Settings → Master Data → States/Cities).
4. Click **Create project**. A new project always starts in **Planning**
   status — you can't set the initial status yourself.

The create form is intentionally short — **Code**, **Latitude/Longitude**,
**Primary contact**, and **Imagery** are no longer asked for up front. A
unique code is generated for you automatically (e.g. from the project
name). If you need to set a specific code, adjust coordinates, add a site
contact, or set a logo/cover image path, open the project afterwards and
use **Edit** — those fields still live there.

**Right after creating it**, from the project's page you'll typically:

- **Add Blocks** — go to the project's **Blocks** tab and create at least
  one block (name + unique code within the project, e.g. Block A). Plots
  are always created under a block, so this is usually the next step.
- **Move it to Active** — use **Change status** on the project page once
  it's ready to sell from. The allowed moves are
  `Planning → Active/OnHold`, `Active → OnHold/Completed`,
  `OnHold → Active/Closed`, `Completed → Closed` — you can't skip a status
  or reopen a Closed project from the UI.
- **Add plot inventory** — once at least one block exists, create plots
  under it (Plots is its own module, M4 — see [`docs/PLOTS.md`](PLOTS.md)).

→ Full technical detail: [`docs/PROJECTS.md`](PROJECTS.md)

## 6. How to book a plot

A **Booking** turns "buyer + plot" into a priced sale. You need
`bookings.create` permission (Admin, Sales Manager and Sales Executive all
have it by default). A buyer must already exist (add one from **Buyers**
first if you don't have one yet), and the plot you want must be
`Available` or already `On Hold` for you.

1. Go to **Bookings** in the sidebar, then click **New Booking**
   (`/bookings/create`).
2. **Pick the plot** — choose Project, then Block, then Plot. Only plots
   that are `Available` or on `Hold` are offered; a plot that's already
   `Booked`/`Sold` won't show up.
3. **Add buyer(s)** — at least one, with exactly one marked **primary**.
   For a co-owned purchase, add every buyer and set each one's ownership
   share — the shares must add up to exactly 100%.
4. **Set the price** — enter the base area/rate, then optionally add PLC
   (location charge), other charges, discounts and tax lines. A live
   preview below the form recalculates as you type: Base + PLC + Charges
   − Discount = Subtotal, then + Tax = Final. This is the same calculation
   the server re-runs and trusts — the preview is just a convenience.
5. Click either:
   - **Save as draft** — nothing touches inventory yet; the plot stays
     free for anyone else.
   - **Save & Submit** — moves the booking straight to **Pending**, which
     claims the plot so no one else can submit or confirm a booking
     against it while yours is live.

**From the booking's page** (`/bookings/{id}`) after that:

- **Submit** — if you saved as a draft, do this when you're ready to claim
  the plot (`Draft → Pending`).
- **Confirm** — requires `bookings.confirm` (Sales Manager and above).
  This freezes the price into a permanent snapshot (later price-list
  changes never affect it) and flips the plot to `Booked`. Do this only
  once the buyer has genuinely committed.
- **Cancel** — requires `bookings.cancel`. Works from Pending or
  Confirmed; cancelling a Confirmed booking frees the plot back to
  `Available`. It does not touch payments or refunds — that's handled
  separately in Finance/Collections.
- **Override price** — requires `pricing.override`. Use only when the
  computed price is wrong for a legitimate reason; a reason is mandatory
  and the adjustment shows up as its own labelled line, never a silent
  edit.

There's no going backwards in the lifecycle — no un-cancelling, and no
moving a Confirmed booking back to Pending.

Once confirmed, the booking's page is the hub for everything downstream:
**Payment Plan/Payments**, **Documents**, **Registry**,
**Possession/Transfers**, and **Channel-partner/Commission**.

→ Full technical detail: [`docs/BOOKINGS-PRICING.md`](BOOKINGS-PRICING.md)

## 7. Module-by-module walkthrough

### Dashboard (`/dashboard`)
Landing page after login. Summarizes what's relevant to your role.

### Buyers (`/buyers`)
The customer record, created directly (there is no lead/enquiry stage):
contact details, encrypted KYC, linked bookings, and the buyer's document
checklist. This is also where you send a customer-portal invitation.
→ [`docs/BUYERS.md`](BUYERS.md)

### Projects (`/projects`) & Plots
Projects are the top of the hierarchy (a development/site), divided into
Blocks. Plot inventory (individual sellable units, their lifecycle —
Available → Held → Booked → Sold) lives under Plots, reached from a
project/block. Plot holds expire automatically if a booking isn't
confirmed in time, freeing the plot back up.
→ [`docs/PROJECTS.md`](PROJECTS.md), [`docs/PLOTS.md`](PLOTS.md)

### Bookings (`/bookings`)
Turns "buyer + plot" into a priced sale: base price + PLC (location
charge) + other charges − discount + tax, computed server-side to the
paisa. Confirming a booking locks the plot and takes a permanent price
snapshot — later price-list changes never retroactively affect a
confirmed booking. Overriding the computed price requires a specific
permission and is logged. A booking's page is also the hub linking out to
its payment plan, documents, registry case, possession case, transfers,
and commission. → [`docs/BOOKINGS-PRICING.md`](BOOKINGS-PRICING.md)

### Finance / Payments (`/finance`, payment plans & payments)
Each booking gets a Payment Plan broken into Installments. Payments are
recorded against the plan, verified, then allocated oldest-installment-
first. Outstanding/overdue amounts are always derived from this ledger
(never a stored balance field), so they can't drift out of sync. A verified
payment produces a branded PDF receipt. Reversals are supported with a full
audit trail. → [`docs/PAYMENTS.md`](PAYMENTS.md)

### Collections (`/collections`)
Where overdue accounts are worked. A collection **case** opens automatically
against a booking once it's overdue, feeding a prioritized **queue** and
**aging buckets** (30/60/90+ days). Collectors log follow-ups, capture
promise-to-pay commitments (only closed by an actual payment, never just by
saying so), and record cheque bounces, which can trigger penalties.
→ [`docs/COLLECTIONS.md`](COLLECTIONS.md)

### Documents (`/documents`)
Central store for both buyer KYC documents and booking sale documents
against a configurable checklist per document type. Every upload is
versioned (never overwritten), stored privately, and only downloadable by
someone authorized for that specific record — not by URL guessing. Includes
the sale-agreement lifecycle (prepare → sign → approve).
→ [`docs/REGISTRY-DOCUMENTATION.md`](REGISTRY-DOCUMENTATION.md)

### Registry (`/registry`)
Handles the legal registration of the sale. A registry eligibility check
reads live state from bookings/payments/documents ("has this booking paid
enough and does it have the required verified documents?") before a
registry case can even open. From there: schedule an appointment, track
registry expenses, complete registration, and hand the registered documents
to the customer. → [`docs/REGISTRY-DOCUMENTATION.md`](REGISTRY-DOCUMENTATION.md)

### Possession (`/possession`) & Transfers (`/transfers`)
After registry, possession runs eligibility → clearances → site inspection
→ customer handover, ending in a branded possession certificate. Ownership
**Transfers** (resale, gift, inheritance) go through review → approval →
transactional completion, and every change of hands is recorded on an
append-only ownership ledger — the full chain of title for a plot is always
reconstructable. → [`docs/POSSESSION-TRANSFER.md`](POSSESSION-TRANSFER.md)

### Channel Partners (`/partners`) & Commissions (`/commissions`)
Brokers/referral partners are onboarded with their own KYC, then
*attributed* to a booking (including co-broker splits). Commission
is computed against a versioned commission scheme and locked into an
immutable snapshot when a commission case is generated, then moves through
approve → payout → (if needed) reverse.

### Reports (`/reports`)
Analytics over the same data every other module writes — Overview, Sales,
Inventory, Collections, and MIS. Every report can be filtered and exported
(CSV/XLSX/PDF/print). This is read-only: it never changes underlying
records, so it's safe to hand to management without booking-level access.

### Customer Portal (`/portal`)
What a buyer sees after logging in themselves: their own bookings, payment
schedule, and downloadable receipts. Nothing else — it's a narrow,
read-mostly view scoped to that one buyer's own records.

### Administration
- **Users / Roles & Permissions** — create staff accounts, assign roles,
  or build a custom role from scratch.
- **Master Data (Settings → Master Data)** — the shared reference lists
  everything else depends on (states, cities, charge types, tax rates, plot
  types, document types, etc.) — one generic screen reused across all 18+
  master modules rather than a bespoke CRUD per list.
  → [`docs/MASTER-DATA.md`](MASTER-DATA.md)

## 8. Quick reference — "how do I…"

| Task | Where |
|---|---|
| Add a new customer | Buyers → New Buyer |
| Sell a plot | Booking → New Booking (pick buyer + plot, review computed price, Confirm) |
| Record a payment | Booking → Payments → Record Payment, then Verify |
| Chase an overdue account | Collections → Queue |
| Upload/verify a KYC or sale document | Buyer/Booking → Documents |
| Check if a booking can go to registry | Registry → Eligibility (on the booking's registry tab) |
| Hand over a plot after registration | Possession → the booking's possession case |
| Move ownership to someone else | Transfers → New Transfer Request |
| Pay out a broker | Commissions → the commission case → Approve → Payout |
| Invite a customer to the portal | Buyer → Portal → Send Invitation |
| Add/edit a dropdown list (states, charge types, …) | Settings → Master Data |
| Give someone access to a screen | Administration → Users (assign role) or Roles & Permissions (edit what a role can do) |

## 9. Where to go deeper

Every module above has a matching technical doc under `docs/` describing
the actual data model, invariants, and Actions/state machines enforcing
them: [`RBAC.md`](RBAC.md), [`MASTER-DATA.md`](MASTER-DATA.md),
[`PROJECTS.md`](PROJECTS.md), [`PLOTS.md`](PLOTS.md),
[`BUYERS.md`](BUYERS.md), [`BOOKINGS-PRICING.md`](BOOKINGS-PRICING.md),
[`PAYMENTS.md`](PAYMENTS.md), [`COLLECTIONS.md`](COLLECTIONS.md),
[`REGISTRY-DOCUMENTATION.md`](REGISTRY-DOCUMENTATION.md),
[`POSSESSION-TRANSFER.md`](POSSESSION-TRANSFER.md),
[`CONVENTIONS.md`](CONVENTIONS.md) (engineering conventions),
[`DEPLOYMENT.md`](DEPLOYMENT.md) and
[`DISASTER-RECOVERY.md`](DISASTER-RECOVERY.md) (running it in production).
