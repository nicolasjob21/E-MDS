# Project: ChequeWatch

Cheque Number Monitoring & Tracking System — enforces strict sequential usage of a business's
cheque numbers, with a full audit trail. Used by finance/accounting staff and admins.

## Stack

- **Backend:** Laravel 13 (PHP 8.5), Sanctum SPA (same-origin cookie/session) auth
- **Frontend:** React 19 + TypeScript via Vite, in `resources/js` (monolith — served by Laravel)
- **Styling:** Tailwind CSS v4 (CSS-based `@theme` in `resources/css/app.css`) — see @dev-templates/DESIGN_SYSTEM.md
- **Database:** PostgreSQL (`chequewatch`)

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

- Cheque numbers are **one continuous integer sequence** — no batches.
- Only the **lowest available** cheque may be used next; skipping is rejected **server-side**.
- `ChequeService::useNext()` locks the next row `FOR UPDATE` inside a transaction — concurrency-safe.
- `ChequeService::addRange()` always continues from `last + 1` (gap/duplicate-proof); `start_at` is
  honoured only for the very first range.
- Every login/logout, cheque use, and admin action is written to `cheque_logs` (append-only).

## Layout

- `app/Enums/` — `UserRole`, `ChequeStatus`, `ChequeAction`
- `app/Services/` — `ChequeService` (business logic + locking), `ActivityLogger` (audit)
- `app/Http/Controllers/` — thin; `Auth`, `Cheque`, `ChequeLog`, `User`
- `app/Http/Requests/` — all validation lives here
- `app/Http/Middleware/EnsureUserIsAdmin.php` — aliased `admin`
- `routes/api.php` — `/api/v1/*`; `routes/web.php` — SPA catch-all
- `resources/js/{pages,components,auth,lib}` — React app

## API (`/api/v1`)

`POST login` · `POST logout` · `GET me` · `GET cheques` · `GET cheques/summary` ·
`GET cheques/next` · `POST cheques/use` · **admin:** `POST cheques/add-range` · `GET logs` ·
`GET|POST|PUT|DELETE users`

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
