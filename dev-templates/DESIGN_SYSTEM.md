# Brite-Tech Design System

The shared visual identity across Brite-Tech apps (as used in BT-Landingpage and BT-Attendance).
"Editorial dark" style: dark-first, flat surfaces, thin cyan hairline borders, squared corners,
uppercase tracked labels, film-grain texture.

Tailwind v4 (CSS-based `@theme` config, no tailwind.config.js).
Reference implementations: `BT-Attendance/resources/css/app.css` (Blade/Tailwind) and
`BT-Landingpage/src/index.css` (React/CSS variables).

## Typography

- **Display / headings / buttons / labels:** `Syne` (weights 600–800) — `font-display`, tight tracking (`letter-spacing: -0.01em` on headings)
- **Body:** `Inter` (300–700) — `font-sans`
- Google Fonts import: `Inter:wght@300;400;500;600;700` + `Syne:wght@600;700;800`
- Hero/page titles: Syne 700–800, `clamp()` sizing (e.g. `clamp(2.2rem, 4.5vw, 4rem)`), `line-height: 1.1`
- Body text: `text-sm`–`text-base`, relaxed leading (`1.7–1.85`), muted color
- **Eyebrow / section tag** (signature element): Syne, `text-xs`, `font-semibold`, `uppercase`, `tracking-[0.2em]`, cyan, with a leading horizontal rule (`before:h-px before:w-6 before:bg-brand-500`)

## Colors

### Brand cyan (primary) — `brand-*`

| Step | Hex | Note |
|---|---|---|
| brand-50 | `#f1f9fc` | |
| brand-100 | `#d9eff7` | |
| brand-200 | `#b9e2ef` | |
| brand-300 | `#7ec8e3` | landing `--blue` (dark-mode primary) |
| brand-400 | `#54b0d1` | |
| brand-500 | `#4a9bb5` | landing `--blue-dim` |
| brand-600 | `#35748a` | light-mode primary text/links |
| brand-700 | `#2c5f72` | |
| brand-800 | `#294e5e` | |
| brand-900 | `#26414f` | |
| brand-950 | `#172a34` | |

### Accent coral (CTA/actions) — `accent-*`

| Step | Hex | Note |
|---|---|---|
| accent-50 | `#fef4f0` | |
| accent-100 | `#fde7de` | |
| accent-200 | `#facdbd` | |
| accent-300 | `#f7ab90` | |
| accent-400 | `#f4845f` | landing `--orange` — primary button fill |
| accent-500 | `#ea6c44` | primary button hover |
| accent-600 | `#c85f3a` | landing `--orange-dim` |
| accent-700 | `#a54a2e` | |
| accent-800 | `#883f29` | |
| accent-900 | `#6f3626` | |
| accent-950 | `#3c1a11` | |

### Surfaces (dark-first)

| Token | Hex | Usage |
|---|---|---|
| `ink` | `#1c1c1e` | app/page background |
| `surface` | `#242428` | cards, panels |
| `deep` | `#141416` | chrome, wells, inputs, nav |
| `hair` | `#7ec8e326` | cyan hairline border (~15% opacity) — THE border color in dark mode |

Light mode (opt-in): bg `#f5f5f5`, card `#ffffff`, deep `#ebebeb`, blue `#2a8fb5`, orange `#e8612a`, text `#111111`, muted `#555555`, border `rgba(42,143,181,0.2)`.

Text: near-white `#eaeaea` on dark; muted `#7a7a85` (dark) / `#555555` (light) for secondary text — muted is used heavily.

### Color roles

- **Cyan (`brand`)** = identity, links, eyebrows/section tags, outline buttons, icon chips, hover borders
- **Coral (`accent`)** = primary CTAs, active states, destructive-adjacent emphasis
- **Signature gradient** (from the logo): `bg-linear-to-r from-brand-500 via-brand-400 to-accent-400`; gradient text via `bg-clip-text text-transparent`
- Headings often mix both: `<span class="blue">` / `<span class="orange">` words inside Syne titles

## Shape & texture

- **Corners: squared.** `rounded-none` on buttons, `rounded-xs` / `border-radius: 2px` on cards and chips. Never `rounded-lg`/`rounded-full` on containers (only scrollbar thumbs / blobs).
- **Borders: 1px hairline** in `hair` cyan — flat design, `shadow-xs` at most in light mode, no shadows in dark
- **Film-grain overlay** (signature texture, dark mode only): fixed fractal-noise SVG at `opacity .03`, `z-index 9999`, `pointer-events: none` — copy from existing app.css
- **Grid seams:** multi-card grids use `gap-px` with the border color as grid background so cards share 1px hairline seams (see services-grid, stats-bar)

## Components

### Buttons (`.btn` pattern)
- Base: inline-flex, `gap-2`, `px-5 py-2.5`, `rounded-none`, Syne (`font-display`), `text-xs font-semibold uppercase tracking-widest`, `focus:ring-2 focus:ring-offset-2`
- **Primary** = coral fill: `bg-accent-400 text-white hover:bg-accent-500`, hover lift `-translate-y-0.5` on landing
- **Outline/secondary** = cyan: `border border-brand-400 text-brand-600 hover:bg-brand-400 hover:text-white` (`dark:text-brand-300`)
- Full-width stacked on mobile

### Cards (`.card`)
- `rounded-xs border bg-white shadow-xs` / dark: `border-hair bg-surface shadow-none`
- Hover variant (`.card-hover`): `hover:-translate-y-0.5 hover:border-brand-400/40`, dark hover bg → `deep`
- Optional 3px left/top accent bar in blue or orange that animates in on hover

### Stat tiles
- Big Syne number (2.2–3rem, weight 800) in blue or orange + `uppercase tracking-[0.18em] text-muted` label below
- Laid out in a hairline-divided grid (`stats-bar` pattern); icon chips: `h-11 w-11 grid place-items-center rounded-xs`, tinted bg (`rgba(brand, 0.1)`) + hairline border

### Forms
- `@tailwindcss/forms` plugin; dark inputs: `border-brand-400/20 bg-deep text-slate-100`, placeholder `text-slate-500`
- Visible `<label>` always; errors below field, `text-xs`, coral/red
- Submit bottom-right with loading state

### Tables (Blade apps)
- Responsive stack pattern: `table.table-stack` collapses to labeled cards under 768px (`data-label` on cells, `.cell-head` for title cell) — reuse from BT-Attendance app.css

### Navigation
- Fixed navbar, transparent → `backdrop-blur` + hairline bottom border when scrolled
- Links: `text-[0.8rem] uppercase tracking-[0.12em] text-muted`, orange underline animates in on hover
- Mobile ≤1024px: hamburger (3-line, animates to X) + full-screen blurred overlay menu with numbered Syne items

## Motion

- Hover: small lifts (`-translate-y-0.5` to `-4px`), border-color shifts, accent bars growing — CSS `transition` 200–400ms; keep simple hovers in CSS, don't reach for JS
- Nothing flashy; no bounces, no long animations — motion stays editorial and restrained
- Icons from `lucide-react` (React projects)

### GSAP (preferred animation library — gsap.com, free incl. all plugins)

Use GSAP for anything beyond a simple CSS transition:

- **ScrollTrigger** — scroll-driven reveals and pins. Preferred over the legacy IntersectionObserver `.reveal` pattern for new work; keep the same feel (fade + `y: 30`, ~0.7s, `ease: 'power2.out'`, subtle stagger)
- **SplitText** — hero/section title entrances: split Syne headings by word/line, staggered fade-up
- **Flip** — layout/state transitions (filtering grids, expanding cards, tab content)
- **Draggable + Inertia** — carousels, sliders, drag interactions with momentum
- **Observer** — unified wheel/touch/pointer detection when ScrollTrigger doesn't fit

Conventions:

- React: `npm i gsap @gsap/react`, animate inside the `useGSAP()` hook (handles cleanup); register plugins once in a central module
- Blade: `npm i gsap`, import in `resources/js/app.js`, init on `DOMContentLoaded`
- Respect `prefers-reduced-motion` — gate non-essential animation behind `gsap.matchMedia()`
- Durations 0.4–0.8s, eases `power2.out`/`power3.out`; no `elastic`/`bounce`

## Theming

- **Dark-first.** Light mode is opt-in.
- Blade apps: class strategy — `html.dark`, `@custom-variant dark (&:where(.dark, .dark *))`
- React apps: `[data-theme='light']` attribute overriding CSS variables
- Always style both modes; `color-scheme` set accordingly
- Thin styled scrollbars (cyan-dim thumb, rounded)

## Layout & responsive

- Section padding: `py-28 px-12` desktop → `py-[4.5rem] px-6` mobile (landing); app pages use standard container + `space-y-6`
- Breakpoints in practice: ≤768px mobile, 769–1024px tablet (2-col grids), >1024px desktop (3–4 col)
- Must work at 375px; multi-column grids collapse to 1 col (2 col for stats) on mobile
- Print: hide `aside`/`header`, remove sidebar padding (app pages)

## Accessibility

- Focus: `focus:ring-2 focus:ring-offset-2` (ring-offset matches surface: white / `ink`)
- Icon-only buttons need `aria-label`
- Restore `cursor: pointer` on buttons (Tailwind v4 resets it)
- Uppercase-tracked text only for short labels, never body copy
