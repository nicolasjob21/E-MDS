# Project: [PROJECT NAME]

[One sentence: what this app does and who uses it.]

## Stack

- **Backend:** Laravel [11.x] (PHP [8.3])
- **Frontend:** [pick one, delete the other]
  - Blade + Alpine.js (server-rendered, like BT-Attendance)
  - React + TypeScript via Vite (like BT-Landingpage, but in TS)
- **Styling:** Tailwind CSS v4 (CSS-based `@theme`, no tailwind.config.js) — see @DESIGN_SYSTEM.md before any UI work
- **Animation:** GSAP (`gsap`, plus `@gsap/react` in React projects) — pre-approved dependency; usage rules in @DESIGN_SYSTEM.md
- **Database:** [MySQL / PostgreSQL / SQLite]

Reference projects for established patterns:
- `~/BT-Attendance` — Laravel + Blade + Alpine + Tailwind v4 (app UI patterns)
- `~/BT-Landingpage` — React + Vite + Tailwind v4 (marketing/landing patterns)

## Commands

```bash
composer run dev          # start local dev server (or: php artisan serve + npm run dev)
npm run build             # production frontend build
php artisan test          # backend tests
./vendor/bin/pint         # PHP formatter — must pass before done
npm run lint              # JS/TS linter — must pass before done
npm run type-check        # TypeScript check (React projects) — must pass before done
php artisan migrate       # run migrations
```

## Folder structure

- `app/Http/Controllers/` — controllers, keep thin; logic goes in `app/Services/`
- `app/Http/Requests/` — Form Requests; ALL input validation lives here, never in controllers
- `app/Models/` — Eloquent models
- `routes/web.php`, `routes/api.php` — routes
- `resources/views/` — Blade templates (Blade projects)
- `resources/views/components/` — reusable Blade components — reuse before creating new ones
- `resources/js/` — React app (React projects)
- `resources/js/components/` — reusable React components — reuse before creating new ones
- `resources/js/pages/` — page-level components
- `tests/Feature/` — endpoint tests; every new/changed endpoint gets one

## API conventions

- Routes: `api/v1/...`, kebab-case URLs, RESTful verbs
- Success response: `{ "data": ... }` — use API Resources (`app/Http/Resources/`)
- Error response: `{ "message": "...", "errors": { "field": ["..."] } }` (Laravel default)
- Auth: [Sanctum / session] — state which endpoints are public vs. authenticated

## Rules

- UI work: follow @DESIGN_SYSTEM.md — reuse existing components; do not create new Button/Input/Card variants unless none fit
- For new pages/sections, also use the globally installed taste skills (`design-taste-frontend`, `high-end-visual-design`, `gpt-taste` for GSAP motion). Precedence: DESIGN_SYSTEM.md wins on brand — fonts, colors, corner radii, component recipes are fixed; the taste skills guide layout, composition, hierarchy, and motion quality within that brand
- Validate ALL input through Form Requests
- No new composer/npm dependencies without asking first
- TypeScript: no `any` (React projects)
- Never edit migrations that have already run — create a new one
- Don't touch auth scaffolding unless the task is explicitly about auth

## Definition of done

- `./vendor/bin/pint` passes
- `php artisan test` passes (including new tests for changed endpoints)
- React projects: `npm run lint` and `npm run type-check` pass
- UI verified responsive: 375px mobile and desktop
- Keyboard navigation works; interactive elements have proper labels
