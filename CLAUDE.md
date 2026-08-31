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
- Every login/logout, cheque use, and admin action is written to `cheque_logs` (append-only).
- Both tables' actions follow the record's status: **Used/Received** → Review (all three outcomes in
  one dialog); **Returned** → staff get Action (outcome section hidden, Edit Details →
  update request; approving it applies the details *and* signs the record off as **Approved**),
  admin get Review; **Approved** → Assign to an ACIC. The LDDAP table works the same way; its teller receipt and admin direct
  correction live in the detail modal, reached from the LDDAP number.
- An **LDDAP-ADA** draws on its **own check series** (`lddap_checks`), independent of
  `cheques.cheque_number` and `acics.acic_number` — using one consumes no cheque. Admins
  register blocks of it (`addRange`); an LDDAP takes the **lowest unused** number, never one out
  of order or skipped, and a block registered later *below* numbers in use is used first.
  `lddaps.lddap_check_id` and `lddap_no` are both unique. "Use Check Number" registers N rows
  against the N lowest unused numbers — all-or-nothing, `start_at` must be the real next number.
- An LDDAP carries its **own** status (used → received → approved | compliance | cancelled):
  teller confirms receipt, admin reviews. Approved and Cancelled are final. Only **approved**
  LDDAPs may go on an ACIC.
- Staff never edit an LDDAP directly: they propose a correction (LDDAP No., OBJ No., Payee,
  Amount — never the check number) and an admin approves it. A pending request puts the record
  **on hold**: it can't be received or reviewed until resolved. Same flow as cheque corrections.
- **Returned** hands the record back to **the staff member who used the number** — they are
  notified that it needs their attention, and only they may correct it (server-enforced; other
  staff see `Returned to {name}`). They update
  the details, which notifies admins to confirm it in Update Requests. Approving that correction
  is the sign-off — the record moves straight to Approved. Same rule for cheques.
- An admin may instead `PATCH lddaps/{lddap}` to correct details **immediately** — reason
  required, recorded in the same history (`applied_directly`), refused while a request pends.
- An **ACIC** groups **approved cheques** and/or **approved LDDAPs** for transmittal. Its number
  comes from a **registered series** (`acic_numbers`, admin `addRange`) — never registered twice,
  always the **lowest unused** next, so a block registered below numbers in use is used first; membership is `cheques.acic_id` / `lddaps.acic_id`,
  at most one ACIC each; many share one ACIC number, and a **Used** ACIC keeps accepting more
  (sign-off, not Used, closes membership). Admin/staff open and use ACICs. An ACIC is signed off by an admin
  (**used → approved**) from the View dialog; only an **approved** one may be forwarded (admin,
  to a user **or** a typed-in name) or printed. Admin/staff may **re-assign** a record on a Used
  or Approved ACIC — the one coming off is released back to the pool and can be used again.

## Layout

- `app/Enums/` — `UserRole`, `ChequeStatus`, `ChequeAction`, `AcicStatus`, `AcicNumberStatus`,
  `LddapStatus`,
  `LddapCheckStatus`
- `config/acic.php` — the constants printed on the ACIC form (bank, agency, codes, signatories)
- `app/Support/AmountInWords.php` — spells the form's AMOUNT IN WORDS line
- `app/Services/` — `ChequeService` (business logic + locking), `AcicService` (ACIC sequence +
  cheque linking), `LddapService` (the LDDAP check series, its use, review + ACIC linking),
  `LddapUpdateRequestService` (LDDAP corrections), `ActivityLogger` (audit)
- `app/Http/Controllers/` — thin; `Auth`, `Cheque`, `ChequeLog`, `Acic`, `Lddap`, `User`
- `app/Http/Requests/` — all validation lives here
- `app/Http/Middleware/EnsureUserIsAdmin.php` — aliased `admin`
- `routes/api.php` — `/api/v1/*`; `routes/web.php` — SPA catch-all
- `resources/js/{pages,components,auth,lib}` — React app

## API (`/api/v1`)

`POST login` · `POST logout` · `GET me` · `GET cheques` · `GET cheques/summary` ·
`GET cheques/next` · `POST cheques/use` · `GET acics` · `GET acics/series` · `GET lddaps` · `GET lddaps/next-numbers` ·
`GET lddaps/series` · `GET lddaps/linkable` · **teller:** `POST lddaps/{lddap}/receive` ·
**admin/staff:** `POST acics` · `POST acics/{acic}/cheques` · `POST lddaps/use-cheque` ·
`POST acics/{acic}/lddaps` · **admin:** `POST acics/add-range` · `POST acics/{acic}/approve` · `POST acics/{acic}/forward` ·
`POST cheques/add-range` ·
`POST lddaps/add-range` · `POST lddaps/{lddap}/review` · `PATCH lddaps/{lddap}` · `GET lddap-update-requests` ·
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
