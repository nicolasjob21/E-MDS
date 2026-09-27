# Project: E-MDS

Cheque Number Monitoring & Tracking System — enforces strict sequential usage of a business's
cheque numbers, with a full audit trail. Used by finance/accounting staff and admins.

## Stack

- **Backend:** Laravel 13 (PHP 8.5), Sanctum SPA (same-origin cookie/session) auth
- **Frontend:** React 19 + TypeScript via Vite, in `resources/js` (monolith — served by Laravel)
- **Styling:** Tailwind CSS v4 (CSS-based `@theme` in `resources/css/app.css`) — see @dev-templates/DESIGN_SYSTEM.md
- **Database:** PostgreSQL (`emds`)

## Commands

```bash
composer run dev          # serve + vite together (or: php artisan serve & npm run dev)
npm run build             # production frontend build
npm run type-check        # tsc --noEmit — must pass
npm run lint              # eslint — must pass
php artisan test          # backend tests — must pass
./vendor/bin/pint         # PHP formatter — must pass
php artisan migrate:fresh --seed   # reset DB + seed admin/staff + 500 cheques
```

Default seeded accounts: `admin` / `staff`, password `password` (override via `SEED_ADMIN_*`).

## Domain rules (the point of the app)

- Cheque numbers are registered per **physical cheque book**, by its printed first/last serial.
  Books need not adjoin: a new book may start well above the last number on file, and the
  numbers in between simply never existed. **No number is ever registered twice.**
- Only the **lowest available** cheque may be used next; skipping is rejected **server-side**.
- `ChequeService::useNext()` locks the next row `FOR UPDATE` inside a transaction — concurrency-safe.
- `ChequeService::addRange($user, $startAt, $endAt)` registers a book's serial range; it rejects
  any range overlapping an already-registered number, checked under a lock.
- Every login/logout, profile edit, password change, cheque use, and admin action is written to
  `cheque_logs` (append-only); the nightly validity sweep logs as `system`.
- A cheque is **valid exactly 90 calendar days from `cheque_date`** (`App\Support\Validity`,
  Asia/Manila, day 91 = stale); `validity_until` is derived on save. Eight statuses perish
  (registered → accepted_by_teller, plus released_to_payee); deposited/cancelled/spoiled/stale/
  replaced never do. `Cheque::effectiveStatus()` / `scopeEffectivelyIn()` report stale **on
  read**, so correctness never waits on `cheques:sweep-validity` (daily 00:05 Manila,
  idempotent), which also sends the **one-time 10-day alert** (`expiry_alert_sent_at`; email
  optional via `cheques.alert_email`). Stale is terminal, the number stays consumed, and an admin
  may **Replace** it with the next available number (`replaces_id`/`replaced_by_id`,
  `ChequeStaleService`).
- **Cheques run one ordered flow** (`ChequeStatus`, enforced server-side):
  `available` → *(no status — stored `registered`)* → **Print Draft** → **for_checking** → the
  **admin in charge (an Administrator or Super Admin)** either **Approves** → **for_final_print**, or **Returns** it
  with a required comment → **for_compliance** (the preparer edits and prints a new draft → back
  to for_checking; repeatable) → **Final Print** + "did it print?" confirm → **for_signature** →
  Assign Cheque to ACIC → **approved**, then **released_to_payee** (Branch A) or
  **forwarded_to_teller** → **accepted_by_teller** (label *Accepted*) → the accepting teller's
  **Forward to LBP** (any ACIC) / **Forward to Payee** (cheque ACICs, one/several/all cheques
  at a time with Received By, Date Received, Unit — `received_by_name`/`date_received`/
  `payee_unit_name`; the ACIC follows once every non-stale cheque is out; then LBP and Return to
  Admin are blocked) →
  **forwarded_to_land_bank** (label *Forwarded to LBP*) / **forwarded_to_payee** → **Action**:
  **Completed** (final; to the payee it takes Received By + Date Received per check) or **RTS**
  (reason + a status per check: completed / returned / cancelled / stale — Returned ones go out
  with the next Forward) (Branch B, the **whole ACIC**; `AcicTellerService`, only the accepting
  teller, no admin override; Confirm and Complete and Returned by Bank are retired).
  Status names shown exactly: For Checking, For Compliance, For Final Print, For Signature; no
  status shows no badge (tab: *Used*). Details (payee, account no., unit, amount, date) are
  edited **only** with no status or For Compliance (`PUT cheques/{cheque}`, each edit an `edited`
  timeline row) — cheques take **no correction requests**. Administrators and Super Admins are
  notified of a new draft; the preparer (who printed the latest draft) of approve/return. Ways out: **Cancel**
  (any status before an ACIC, reason) and **Spoil** (formerly Void; approved only; number stays
  used and the payment moves to a replacement on the next available number via `useNext()`,
  linked `replaces_id`/`replaced_by_id`, dated today, no status — `ChequeSpoilService`). The
  spoiled cheque comes off its ACIC (`spoiled_from_acic_id` remembers it); at For Signature its
  replacement may **Use previous ACIC** (`POST cheques/{cheque}/use-previous-acic`) only while
  that ACIC is with the admin (`Acic::notWithAdminBecause()`), else a new ACIC; the choice is
  noted on its timeline. RTS,
  Route for Signature and Mark as Received are retired (`out_for_signature`/`received`/`for_acic`
  remain only for old history rows). `nextStates()` is the whole transition table. Every step
  goes through `ChequeFlowService::move()`: row lock, `expected_status` check (*"This record was
  updated by another user. Refresh to continue."*), transition check, and a
  `cheque_status_history` row. **An ACIC-level step writes one history row per cheque on that
  ACIC.** Only **for_signature** cheques may go on an ACIC. Preparers (staff/admin/super admin)
  edit/print; admins (Administrator or Super Admin) approve/return and assign/release/forward/cancel/spoil; tellers
  accept/complete/return (`AcicService`, first-accept-wins via a conditional write). Teller
  dashboard: `/deposit-queue` (sidebar link for tellers only).
- **Roles:** `super_admin` (every admin power; only a Super Admin grants/changes/removes the role; the first via `php artisan users:make-super-admin`),
  `admin`, `staff`, `teller`. `User::isAdmin()` is true for both admin roles.
- An **LDDAP-ADA** draws on its **own check series** (`lddap_checks`), independent of
  `cheques.cheque_number` and `acics.acic_number` — using one consumes no cheque. Admins
  register blocks of it (`addRange`); an LDDAP takes the **lowest unused** number, never one out
  of order or skipped, and a block registered later *below* numbers in use is used first.
  `lddap_no` and `dv_no` are **`00-00-00000`** (`App\Support\DashedNumber`, wherever entered) and unique (DB index + form rule + locked re-check); `lddap_check_id` is unique and
  **nullable**. An LDDAP is added **one at a time, without a check number**, straight to
  **For Signature** — the only status before an ACIC. From there it is **assigned to an ACIC**, or
  an admin takes **RTS** (its own status and form — Date Received, Received By, RTS Unit, RTS
  Date, required Comment; editable, then **Resubmit** — required Comment, optional Notes
  (`lddap_routing_history.notes`) — back to For Signature; every RTS is its own history row and
  `rts_count` badges the record) or **Cancel** (own confirmation — Canceled By,
  Date Canceled, required Reason, stored on the record; status `canceled`, read-only from then
  on, never offered to Assign LDDAP to ACIC, LDDAP number stays used). Only the next valid step is ever offered or accepted, and every step is a
  `lddap_routing_history` entry (user, date, note).
  Put on an ACIC (only **For Signature** ones; it becomes **approved** = "on an ACIC", an
  `assigned` trail row; a re-assign sends the one coming off back to For Signature), which is
  **when it takes its check number**: N ticked
  records take the first run of N **consecutive** unused numbers (searching up from the lowest;
  a run broken by a used number is skipped whole and its free numbers kept for a smaller
  batch), in tick order, claimed `FOR UPDATE` by `LddapCheckAllocator` behind a lock on the
  lowest unused row so two users never overlap (a stale preview is refused by name: "Check
  number X is already used. Please refresh and try again."). Check # is read-only in every form.
  Teller receipt requires a check number and leaves the status alone. Each record carries
  an NCA Code (0000000 — exactly 7 digits, text), OBR and DV numbers (DV unique, 00-00-00000), a `NatureOfPayment`, a PCG unit (`unit_name`), the UACS object code (`obj_no`,
  optional), the date issued (`check_date`), a **payee** picked from **Creditors or PCG Personnel** in one
  search (`GET lddaps/payee-options`) — name, **payee type** and account number **copied** onto the
  record (no link; old LDDAPs keep a blank type), and the payee's unit fills Unit — plus the payment breakdown: `gross_amount`, W/TAX
  and VAT by rate, retention, liquidated damages, advance payment. **`amount` is the net
  payable**, derived from those; every money column is `decimal(14,2)` handled via
  `App\Support\Money` (bcmath), never floats. Withholding = `(gross ÷ 1.12) × rate`
  (`App\Support\Tax`, mirrored in `resources/js/lib/tax.ts`).
- An LDDAP carries its **own** status (for_signature → approved on the ACIC → teller statuses;
  for_signature → rts → for_signature; for_signature → canceled, final). The **Registered, For Out,
  Returned for ACIC and Approve** steps are retired: their forward/receive columns and trail rows
  stay in the database but are shown nowhere (not in the API, filtered out of the trail);
  `2026_09_28_000200` remaps those statuses (and approved-without-ACIC) to `for_signature`.
- While an LDDAP is **For Signature** or **RTS**, admin/staff **edit** it through the register form
  itself (`PUT lddaps/{lddap}`, `LddapDetailsRequest` — the one form request behind register and
  edit, `LddapService::update()` sharing `attributes()` with `register()`): every field
  pre-filled, own LDDAP number ignored by the unique rule, check number/status never touched,
  each edit kept in `lddap_edit_history` (user, time, `{field: {from, to}}`). Other statuses get
  no Edit. Once on an ACIC, staff propose a correction (LDDAP No., OBJ No.,
  Payee, Amount — never the check number) and an admin approves it. A pending request puts the
  record **on hold**: it can't be edited, received or reviewed until resolved.
- A correction (cheque or LDDAP) changes **details only** and never moves the record; **RTS** is
  the way back for one that came in wrong. A pending request puts the record on hold.
- An admin may instead `PATCH lddaps/{lddap}` to correct details **immediately** — reason
  required, recorded in the same history (`applied_directly`), refused while a request pends.
- **Every Unit field is a dropdown of the PCG unit list**, defined once in
  `config/pcg-units.json` (PHP: `App\Support\PcgUnits`; React: `lib/pcgUnits.ts` +
  `UnitSelect`) — never copied into a page. Saved as the unit's name in a text column (no units
  table), validated server-side (`PcgUnits::rule()`); batch upload matches it ignoring case/spaces
  (`PcgUnits::canonical()`).
- An **ACIC** groups **for-signature cheques** and/or **For Signature LDDAPs** for transmittal. Its number
  comes from a **registered series** (`acic_numbers`, admin `addRange`) — never registered twice,
  always the **lowest unused** next, so a block registered below numbers in use is used first; membership is `cheques.acic_id` / `lddaps.acic_id`,
  at most one ACIC each; many share one ACIC number, and a **Used** ACIC keeps accepting more
  (sign-off, not Used, closes membership). Admin/staff open and use ACICs. An ACIC is signed off by an admin
  (**used → approved**) from the View dialog; only an **approved** one may be forwarded — cheque
  or LDDAP ACIC alike, a confirmation, straight to the tellers as **Pending** until one accepts
  (the recipient-pick forward and the teller's old Complete are retired) — or printed. The ACIC
  tables show `Acic::displayStatus()` (the teller's status once forwarded), and a teller sees only
  forwarded ACICs (`scopeVisibleTo`). Admin/staff may **re-assign** a record on a Used
  or Approved ACIC — the one coming off is released back to the pool and can be used again.

## Layout

- `app/Enums/` — `UserRole`, `ChequeStatus`, `ChequeAction`, `AcicStatus`, `AcicNumberStatus`,
  `LddapStatus`, `LddapRoutingAction`, `LddapCheckStatus`, `NatureOfPayment`
- `config/acic.php` — the constants printed on the ACIC form (bank, agency, codes, signatories)
- `app/Support/AmountInWords.php` — spells amounts: the ACIC form's caps line and the cheque's
  Title-Case "… Pesos and 86/100 Only"
- `app/Support/Money.php`, `Tax.php` — decimal money arithmetic and the W/TAX–VAT rule
- `app/Support/Validity.php` — the 90-day cheque rule, in Asia/Manila calendar days
- `app/Console/Commands/SweepChequeValidity.php` — the nightly stale/alert sweep
- `app/Services/` — `DashboardService` (attention items by role + every register's counts),
  `ChequeService` (the register + locking), `ChequeFlowService` (every step of the cheque flow
  and its guards), `ChequeStaleService` (stale marking + Replace), `ChequeExpiryService` (the
  nightly sweep and the 10-day alert), `LddapCheckAllocator` (issues
  LDDAP check numbers under a row lock), `AcicService` (ACIC sequence +
  cheque linking), `LddapService` (the LDDAP check series, its use, review + ACIC linking),
  `LddapUpdateRequestService` (LDDAP corrections), `AccountHolderService` (creditors + PCG
  personnel: add, all-or-nothing batch upload via `App\Support\SpreadsheetReader`), `ActivityLogger` (audit)
- `app/Http/Controllers/` — thin; `Auth`, `Profile` (own profile + password), `Dashboard`, `Cheque`, `ChequeLog`, `Acic`, `Lddap`, `Creditor`/`PcgPersonnel` (via `AccountHolderController`), `User`
- `app/Http/Requests/` — all validation lives here
- `app/Http/Middleware/EnsureUserIsAdmin.php` — aliased `admin`
- `routes/api.php` — `/api/v1/*`; `routes/web.php` — SPA catch-all
- `resources/js/{pages,components,auth,lib}` — React app

## API (`/api/v1`)

`POST login` · `POST logout` · `GET me` · `PUT me` (own profile) · `PUT me/password` · `GET dashboard` · `GET cheques` · `GET cheques/summary` ·
`GET cheques/next` · `GET cheques/{cheque}/print` · `POST cheques/use` · `GET cheques/validity-summary` ·
`GET cheques/{cheque}/status-history` · **preparers:** `PUT cheques/{cheque}` · `POST cheques/{cheque}/print-draft|final-print` ·
**admin / super admin:** `POST cheques/{cheque}/approve-draft|return-draft` · **admin:** `POST cheques/{cheque}/release|cancel|spoil|replace` ·
`POST acics/{acic}/forward-to-teller` · **teller:** `POST acics/{acic}/accept|return-to-admin` · **accepting teller:** `POST acics/{acic}/teller-forward|teller-complete|teller-rts|forward-to-payee` · `GET acics/teller-queue` · `GET acics` · `GET acics/series` · `GET lddaps` · `GET lddaps/next-numbers` ·
`GET lddaps/options` · `GET payees` ·
`GET lddaps/series` · `GET lddaps/linkable` · **teller:** `POST lddaps/{lddap}/receive` ·
**admin/staff:** `POST acics` · `POST acics/{acic}/cheques` · `POST cheques/assign-acic` · `POST lddaps` ·
`POST lddaps/{lddap}/resubmit` · `GET lddaps/{lddap}/routing-history` ·
`POST lddaps/assign-acic` ·
`PUT lddaps/{lddap}` · `GET lddaps/{lddap}/edit-history` · `GET payees/{payee}` ·
`POST acics/{acic}/lddaps` · `GET|POST creditors` · `POST creditors/batch-upload` ·
`GET|POST pcg-personnel` · `POST pcg-personnel/batch-upload` · **admin:** `POST acics/add-range` · `POST acics/{acic}/approve` ·
`POST cheques/add-range` ·
`POST lddaps/add-range` · `POST lddaps/{lddap}/rts|cancel` · `PATCH lddaps/{lddap}` · `GET lddap-update-requests` ·
`POST lddap-update-requests/{id}/approve|reject` · `GET logs` · `GET|POST|PUT|DELETE users` ·
**staff:** `POST lddaps/{lddap}/update-requests`

## Rules

- Validate ALL input through Form Requests; keep controllers thin, logic in Services.
- UI work follows @dev-templates/DESIGN_SYSTEM.md (dark-first, Syne/Inter, squared corners, cyan hairlines).
- TypeScript: no `any`. Never edit a migration that has already run — add a new one.
- No new composer/npm deps without asking.

## Definition of done

`./vendor/bin/pint`, `php artisan test`, `npm run lint`, `npm run type-check` all pass;
UI verified at 375px and desktop; keyboard-navigable.

**Docs stay in sync:** any change to roles, the cheque lifecycle, a workflow, an API route, or the
data model MUST update `docs/DOCUMENTATION.md` and its Mermaid flow-charts in the SAME change, and
bump the "Last reviewed" date there. The docs are hand-maintained, not generated.
