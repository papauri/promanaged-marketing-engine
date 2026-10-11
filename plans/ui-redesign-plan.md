# Plan: Redesign the department UI - compact, uniform, natural

> **For Cline (DeepSeek V4 Pro).** Read this file and implement exactly the phase you are given. Do not explore beyond the files listed in that phase.
> Work **one phase per task**. After a phase: run the whole test suite, commit, push to `origin main`, stop, report in 5 lines.
> If something is missing or contradicts the code, stop and say exactly what is missing. Do not re-investigate the codebase.

## Goal

Make every screen of the firm (Overview, Sales, Social, Brand, Insights, Operations) look like one calm, compact product: one type scale, one spacing scale, one set of buttons, chips, cards, fields and tables, one page layout per kind of screen. Same features, same markup contracts, less vertical space, clearer hierarchy.

## Safety rules (read first, they override everything)

1. **Never press an action button in the live app** (http://127.0.0.1:8085). Buttons send email, post to Facebook, call the AI and spend money. Only load pages to look at them. Do not submit any form.
2. **Never delete or edit anything in `data/`.** Tests run on a temp copy. Never `rm` files in `data/`.
3. **Keep the app server running on 127.0.0.1:8085** at all times (`start.bat` / `ensure_server.php`). Do not stop it.
4. **No PHP behaviour changes.** Allowed: `assets/app.css`, `assets/ui.js`, `assets/app.js` (restyle hooks only), class names and wrapper markup in `lib/view_*.php`, `lib/ui.php`, `index.php` (HTML only), plus new test files. Never change handlers (`pm_do_*`), form field names, routes, `id=` anchors or `data-*` hooks.
5. **Public client pages are out of scope**: `sign.php`, `enquire.php`, `wa.php`, `media.php`, PDF code (`lib/pdf.php`, `lib/onepager.php`).
6. Out of scope: dark mode, new features, new fonts or CDN assets, JS frameworks, renaming routes.

## Evidence (measured on the running app, 11 Oct 2026)

| Finding | Number |
|---|---|
| `assets/app.css` | 785 lines in 6 stacked layers (base, shared, v3, v4, v5, v6) that override each other |
| Declared more than once | `:root` 4x, `body` 4x, `.btn` 3x, `.card` 3x, `h1` 3x, `label` 3x, `main` 3x, `.row` 3x, `.flash` 3x |
| Font sizes in CSS | 18 distinct (10, 11, 11.5, 12, 12.5, 13, 13.5, 14, 15, 16, 17, 18, 20, 21, 22, 24, 25, 30 px); on Leads, 13px is used by 895 text nodes and 14px by 439 |
| Radii | 5, 6, 7, 8, 9, 10, 12 px + pills + circles |
| Colours | 43 distinct hex values, 4 "ok" greens and 3 "danger" tints |
| Inline `style="` attributes | 338 across `lib/*.php` + `index.php`; 6 views and `lib/sx_variants.php` carry their own `<style>` block (the `.opt-*` rules are pasted twice) |
| Chip classes | 5 (`.pill`, `.chip`, `.sx-chip`, `.pick`, `.rs-chip`) |
| Card classes | 15+ (`.card`, `.sx-card`, `.bizcard`, `.lpcard`, `.wacard`, `.repcard`, `.msgbox`, `.linkbox`, `.aibox`, `.morecard`, `.brandcard`, `.dept`, ...) |
| Controls | buttons 38px and 32px, inputs 38px and 32px, plus ad-hoc heights |
| Leads page | 561 buttons, **43 primary buttons**, page height 3174px |
| Chrome above first card (Leads) | top bar 57 + head 102 + tabs 41 = first card starts 253px from the top |
| Two accents | business `--accent` and department `--dept` both colour buttons/links |

## Design decisions (the owner may veto; defaults are chosen so work is not blocked)

1. **One accent per screen = the department colour (`--dept`)**: primary buttons, active tab, links, focus ring, selected state. Business `--accent` is used only for the top 3px stripe, the business switcher and brand artwork previews.
2. **Denser and calmer**: 14px body, 36px controls, 28px compact controls, 12px card padding vertically.
3. **Four emphasis levels for buttons** (filled, tonal, outline, ghost) so a page can carry many actions without 43 filled buttons.
4. **Natural look**: soft 1px borders, one very light shadow, sentence case labels (uppercase only for small group labels), earthy department colours already in `pm_ui_nav()`.

## Design tokens (single `:root` block, top of `assets/app.css`)

| Group | Tokens and values |
|---|---|
| Surface | `--bg #f5f6f8`, `--surface #fff`, `--surface-2 #f9fafb`, `--line #e4e7ec`, `--line-strong #cdd2d9` |
| Text | `--ink #111827`, `--ink-2 #374151`, `--muted #667085` (placeholders use `--muted`, never lighter) |
| Status (text / bg / line) | ok `#157f3d / #ecf8f0 / #cfe6d6`; warn `#9a5b00 / #fff6e5 / #f0dcaa`; bad `#b42318 / #fdf2f1 / #f1cdc9`; info = `--dept` mixed 12% into white |
| Accents | `--accent` (business, set in `<head>`), `--dept` (set on `<body>`), `--tint: color-mix(in srgb, var(--dept) 10%, #fff)` |
| Type scale (px) | `--fs-1 12` caption, chip, hint, table head - `--fs-2 13` dense body, small buttons, table cells - `--fs-3 14` body, buttons, inputs - `--fs-4 16` feature titles - `--fs-5 20` page title - `--fs-6 24` KPI value, Overview greeting |
| Weights | 400, 500, 600 only (no 650, 700). Line height 1.4 body, 1.25 headings |
| Font | `system-ui, "Segoe UI Variable Text", "Segoe UI", -apple-system, Roboto, Helvetica, Arial, sans-serif` |
| Space (px) | `--s-1 4`, `--s-2 8`, `--s-3 12`, `--s-4 16`, `--s-5 24`, `--s-6 32`. Nothing else for margin, padding or gap |
| Radius | `--r-ctl 8` (buttons, inputs, menus, inner blocks), `--r-card 12` (cards, popovers), `--r-pill 999`, circles `50%` |
| Shadow | `--sh-card 0 1px 2px rgba(16,24,40,.05)`, `--sh-pop 0 12px 32px rgba(16,24,40,.14)`, `--sh-bar 0 -4px 16px rgba(16,24,40,.06)` |
| Focus | `--ring: 0 0 0 3px color-mix(in srgb, var(--dept) 22%, transparent)`; keyboard focus outline 2px `--dept`, offset 2px |
| Controls | `--ctl 36px` default, `--ctl-sm 28px` compact; under `@media (pointer:coarse), (max-width:860px)` both become `44px` and input font 16px |
| Layout | `--page-w 1180px`, wide pages (`main:has(.withpage)`) 1560px; main padding `16px 20px 80px`; gap between cards `--s-3` |
| Motion | 120ms ease on background, border, box-shadow only; respect `prefers-reduced-motion` |

Keep old names as aliases while migrating (`--card: var(--surface)`, `--radius: var(--r-card)`, `--shadow: var(--sh-card)`, `--danger: var(--bad)`, `--okbg`, `--warnbg`); delete the aliases in Phase 6 when nothing uses them.

## Component spec

### Buttons (the main fix)

| Variant | Look | Use |
|---|---|---|
| Filled `.btn.primary` | bg `--dept`, white text, hover darkens 8% | The one main action of a section. **Max 1 per card/section/form.** |
| Tonal `.btn.small.primary` (and `.btn.primary.small`) | bg `--tint`, text `--dept`, border dept 30% | Row-level main action in lists, tables, task rows. Replaces the filled look automatically |
| Outline `.btn` | white, 1px `--line-strong`, ink text, hover `--surface-2` | Everything else |
| Ghost `.btn.ghost`, `button.link` | transparent, `--muted` text, hover `--bg` | Open, Copy, Undo, Details |
| Danger `.btn.danger` | white, text `--bad`, hover `--bad-bg`; never filled | Delete, Remove, Reject. Keep existing `data-confirm` |
| Icon `button.icon`, `.btn.icon` | square 36/28, ghost look, needs `aria-label` | Remove x, close |

- Sizes: **only** `--ctl` (36) and `.small` (28). Delete every other height (`38px`, `32px`, `42px`).
- Order in a `.btns` group: primary, outline, ghost; a danger button goes last with `margin-left:auto`.
- States: hover, `:active`, `:focus-visible` (ring), `:disabled` (opacity .5, no pointer), all defined once.
- Legacy classes to fold into the four variants: `.btn.aigen`, `.btn.waskip`, `.btn.wasend`, `.btns.stick`, `.btn.stick`. JS hooks on `.aigen` stay.
- On `pointer:coarse` buttons are 44px and a lone button in a form footer may be full width.

### Chips and status
One `.chip` (height 20, padding 0 8, 12px/500, pill). Variants `.ok .warn .bad .info`; neutral by default. Status always has a word (and optionally a 6px dot via `.chip.dot`), never colour alone. Aliases to the same rule: `.pill`, `.sx-chip`, `.rs-chip`, `.pick`; `.pill.hot` = ok. Filter and toggle chips (`.filters a`, `.chipbar a`) are 28px, `.on` = ink fill, white text.

### Cards
`.card`: `--surface`, 1px `--line`, radius 12, `--sh-card`, padding `12px 16px`, margin-bottom `--s-3`. Card head = `h2` (14/600 ink) with actions on the right (`.card-head` flex, min-height 28). Variants: `.card.flat` (no shadow, `--surface-2`, used when nested), `.card.warn|.ok|.bad` (tinted banner, replaces ad-hoc red/amber boxes and most `.flash`), `.card.item` (padding `8px 12px`, for repeated items), `.card.empty` (centred, one sentence plus one button). Give the 15 legacy card classes the same base through **one shared selector list** in CSS (do not rename markup). Nested card inside a card is always flat.

### Fields
Label above (12/500 muted, 4px gap). Control height `--ctl`; textarea min-height 72. Help text `.hint` (12 muted) 4px below. Error text 12 `--bad` below the field. Focus: border `--dept` + `--ring`. Form grid `.row`: gap 12, `repeat(auto-fit, minmax(220px,1fr))`, `align-items:start`. Max 6 fields per card; each card has an `h2` and one hint line. Save bar `.formbar`: sticky bottom, inside the page width, `--sh-bar`.

### Tables and lists
One table look for `table.grid`, `.sx-table`, `.rs`, `.hist`: head 12/600 muted (sentence case), cells 13px, padding `6px 8px` (row about 32px), bottom border only, row hover `--surface-2`, numbers right-aligned with `tabular-nums`, wrapper `overflow-x:auto` on small screens. One list-row look (min-height 36: leading checkbox or icon, title + muted meta, chip, actions right) for `.sx-checks li`, `.idearow`, `.roster li`, `.nextlist li`, `.hq-needrow`.

### Shell
| Part | Spec |
|---|---|
| Top bar | 48px (was 57). Business switcher left (max 220px), department nav 13/500 with 16px icons, status pill collapses to a dot under 1100px, "Working as" select 28px. Keep the 3px `--accent` stripe |
| Page head | Row 1: department eyebrow (icon 14 + name, 12/600 `--dept`) and its job text (12 muted, hidden under 860px). Row 2: `h1` 20/600 with `p.sub` 13 muted on the same baseline when short, wrapping when long. Page actions top-right. **Total height at most 64px** (was 102) |
| Tabs | 36px tall, 13/500, 2px `--dept` underline, horizontal scroll on small screens |
| Content start | First card starts at most **185px** from the top of the window (was 253) |
| Menus | `--r-card`, `--sh-pop`, items 32px, group labels 11px uppercase |

### Page templates (every view uses exactly one)
| Template | Anatomy | Views |
|---|---|---|
| A Dashboard | KPI strip (tiles 8px 12px, value 24/600), two columns, department cards | `view_home.php` |
| B Queue | Toolbar (search, filter chips, one primary), dense rows or `.card.item`, sticky bulk bar | `view_agents.php`, `view_social.php` (Plan), `view_social_page.php` (Inbox, Page posts), `view_whatsapp.php`, `view_social_leadposts.php`, `view_social_content.php` |
| C Settings | Stack of titled cards (max 6 fields), sticky save bar | `view_ops_business.php`, `view_ops_email.php`, `view_ops_connections.php`, `view_ops_team.php`, `view_ops_health.php`, `view_social_voice.php`, `view_sales_targeting.php`, `view_social_numbers.php`, `view_business.php` (wizard) |
| D Fix-it list | Score header, one collapsible card per area, item rows with chip + inline actions | `view_social_optimise.php` (Profiles), audit in `view_social_page.php`, `view_social_channels.php`, `view_social_growth.php` |
| E Gallery | Grid of equal cards with preview on top, actions underneath | `view_social_studio.php`, `view_social_kit.php` |
| F Report | KPI tiles, one table or chart per card | `view_social_results.php`, `view_outbound.php`, `view_drafts.php` |
| Proposals | Same as B/C, markup lives in `index.php` (tabs `proposal`, `history`, `template`) | `index.php` |

### Utilities (replace inline styles)
Define once in `app.css`: `.stack` (flex column, gap `--s-3`), `.cluster` (flex wrap, gap `--s-2`, center), `.grow`, `.right` (`margin-left:auto`), `.nowrap`, `.tnum`, `.t-sm` (13px), `.t-xs` (12px), `.mt-0 .mt-1 .mt-2 .mt-3 .mt-4`, `.mb-0 .mb-2 .mb-3`, `.w-full`, `.center`. Inline `style=` is only allowed for data-driven custom properties (`--bz`, `--dept`, `--v`, `--w`) and bar widths in `%`.

### Snapping tables (use for the mechanical pass)
| Property | Old values | New |
|---|---|---|
| font-size | 10, 10.8, 11, 11.5, 12.5 | 12 |
| | 13.5, 15 (non-title) | 14 |
| | 15, 17, 18 (titles, headlines) | 16 |
| | 21, 22 | 20 |
| | 25, 30 | 24 |
| radius | 5, 6, 7, 9, 10 | 8 (things you click or type in, inner blocks) |
| | 10, 14 (containers) | 12 |
| padding / margin / gap | 5, 6, 7, 9, 10, 11, 14, 18, 20, 22, 26 | nearest of 4, 8, 12, 16, 24, 32; ties go down (denser) |
| colours | `#cfe3d4`, `#cfe6d6` | `--ok-line` |
| | `#f0dcaa` | `--warn-line` |
| | `#f1cdc9`, `#f3c4bf`, `#ecd0cc` | `--bad-line` |
| | `#98a2b3` | `--muted` for text, `--line-strong` for dots and borders |
| Allowed exceptions | decorative glyphs up to 26px (`.go` arrow, `.bizcard.add` plus), lead-post mock-up serif (`.lpshot b`), loader spinner | |

## Files to Change

- `assets/app.css` - rewritten in place into 7 ordered sections: tokens, base, shell, components, patterns, pages, responsive/print
- `assets/ui.js` - only if a hook class is renamed (should not be); keep dropdown and fold behaviour
- `lib/ui.php` - page head, tabs, top bar markup tweaks (keep every contract below), small inline-style removals
- `index.php` - remove 42 inline styles in the shell and proposal screens; keep the two `<style>` blocks (login, `--accent`)
- `lib/view_home.php`, `lib/view_agents.php`, `lib/view_whatsapp.php`, `lib/view_sales_targeting.php`, `lib/view_outbound.php`, `lib/view_drafts.php`, `lib/view_business.php`
- `lib/view_social.php`, `lib/view_social_leadposts.php`, `lib/view_social_content.php`, `lib/view_social_channels.php`, `lib/view_social_growth.php`, `lib/view_social_page.php`
- `lib/view_social_studio.php`, `lib/view_social_kit.php`, `lib/view_social_optimise.php`, `lib/view_social_voice.php`
- `lib/view_social_results.php`, `lib/view_social_numbers.php`
- `lib/view_ops_business.php`, `lib/view_ops_email.php`, `lib/view_ops_connections.php`, `lib/view_ops_health.php`, `lib/view_ops_team.php`, `lib/view_settings.php`
- `lib/sx_variants.php`, `lib/sx_optimise.php` (`pm_opt_item_html`), `lib/sx_ops.php`, `lib/sx_report.php`, `lib/sx_wacampaign.php`, `lib/sx_amplify.php` - inline styles and the `<style>` in `sx_variants.php` only
- `tests/test_ui_system.php` - new guard test (Phase 0)
- `UI.md` - new, 1 page design-system summary (Phase 8)

## Constraints / Things NOT to touch

**Markup contracts asserted by existing tests (keep exactly):**
`class="topbar"` (once per page), `class="tabs"`, `class="pagehead"`, `class="mainnav"`, `class="navitem"`, `<div class="dd navdd on" style="--dept:#...">` (exact string), `body data-dept="..."`, `class="eyebrow"` containing the department name, its job text and an `<svg`, `<h1>Plan · `, `<h1>Studio · `, `<h1>Leads · <Business>`, plain `<h2>Comments to answer</h2>`, `<h2>On the Page now</h2>`, `<h2>Autopilot</h2>`, `<h3>Agent settings</h3>`, `class="hq-hero"`, `class="hq-kpis"`, `class="hq-kpi `, `class="hq-needrow"`, `class="dept"`, `class="roster"`, `class="opt-alt"`, `data-copy`, `data-suggest data-web="1"`, tab and menu link texts (Plan, Lead posts, Content, Channels, Inbox, Playbook, Studio, Brand kit, Profiles, Voice, Page clean-up, Results, Audit, Enter numbers, Business details, Connections, Targeting and messages, WhatsApp, Overview, Operations).

**JS hooks (restyle only, never rename):** `.dd .ddbtn .menu .open`, `#busy #busyMsg #busyTime #runbar #runfill #runsub`, `[data-copy] [data-copy-target] [data-copy-from] [data-ask] [data-confirm] [data-pick-all] [data-pick-count] [data-fill] [data-reframe] [data-wa-log] [data-busy] [data-suggest]`, `.sx-pick .aigen .composer .lead .wacard .replybox .repcard .sticky .cmsg`, `table.heat td[data-v]`, `iframe.fbplug`, `main .card`, `form.msg`.

**Anchors:** `#c-<channel>`, `#i-<key>`, `#words`, `#w-<key>`, `#health`, `#rules`, `#catalogue`, `#google`, `#profile`.

**Other:** do not change the `<style>:root{--accent:...}</style>` in `<head>` or the `--dept` set on `<body>`; keep `@media print` hiding the chrome; keep `<noscript>`-safe behaviour (links work without JS); do not lower any existing contrast.

**Known failing before this work (not yours):** `tests/test_measure.php` has 2 failing assertions ("brand usage is separate", "focus mix by default"). Leave them.

## Phases

Each phase ends with: (a) all tests pass except the 2 known, (b) `php tests/test_ui_system.php` budgets not worse than before, (c) a visual check of the QA pages below by loading them (no clicking actions), (d) commit with a clear message, push to `origin main`.

Run all tests (Git Bash): `for f in tests/test_*.php; do echo "$f: $(php $f 2>&1 | tail -1)"; done`
PowerShell: `Get-ChildItem tests\test_*.php | ForEach-Object { "$($_.Name): " + (php $_.FullName 2>&1 | Select-Object -Last 1) }`

### Phase 0 - Guard tests and baseline (no visual change)
- New `tests/test_ui_system.php`, in the style of `tests/test_departments.php` (`require boot.php`, `pm_test_boot()`, `pm_t_assert`, temp data, render views the way its `page()` helper does; DOM checks with `DOMDocument`).
- Checks, each with a **budget constant at the top set to today's value** and a failure message that lists the first 10 offenders: (1) font-size values in `app.css` outside `{12,13,14,16,20,24}` px (plus the exceptions list); (2) border-radius values outside `{8px,12px,999px,50%,0}` or `var(--r-*)`; (3) number of `:root` blocks (target 1); (4) number of `<style>` blocks in `lib/view_*.php` and `lib/sx_*.php` (target 0); (5) `style="` attributes using banned properties (`margin`, `padding`, `font-size`, `color`, `background`, `border`, `line-height`, `width` in px) in `lib/*.php` and `index.php`; (6) per rendered screen, count of non-small `.btn.primary` inside one `.card`, `details`, `.sx-card`, `form` (target at most 1); (7) contrast: compute WCAG ratio for `ink/bg`, `muted/surface`, white on each `--dept` colour from `pm_ui_nav()`, `ok/ok-bg`, `warn/warn-bg`, `bad/bad-bg`, all at least 4.5.
- Screens to render: every URL in the QA table, for `promanaged` and the custom business created the way `tests/test_departments.php` does.
- Done when: the test runs, prints today's counts, passes on today's code, and the whole suite is green.

### Phase 1 - Tokens and mechanical snapping
- `assets/app.css`: add the single token block at the very top (aliases for old names). Replace magic numbers by tokens using the snapping tables. Keep rule order and selectors unchanged in this phase.
- Done when: font-size and radius budgets in the guard test are 0 for `app.css`, nothing else changed, pages look almost identical (a few px tighter).

### Phase 2 - Collapse the layers (highest risk, go slowly)
- Write a throwaway script (not committed) that lists every selector defined more than once in `app.css` with its declarations in order.
- Merge in batches of at most 5 selectors: keep the declarations that currently **win** (the last ones), put them in the first definition, delete the later duplicates. Order of batches: `:root, body, main, h1, h3, label` then `.btn*` then `.card*` then `.row, .hint, .pill, .flash, .sub` then inputs and `details.card > summary` then everything else.
- Re-order the file into the 7 sections. After each batch run the tests and load 3 QA pages.
- Done when: no selector (as a whole) is declared twice, `:root` appears once, file is at most 700 lines, QA pages look unchanged.

### Phase 3 - Shell
- `assets/app.css` shell section and, only if needed, `lib/ui.php` (`pm_ui_head`, `pm_ui_tabs`, `pm_ui_topbar`) to meet the Shell spec: top bar 48px, page head at most 64px, tabs 36px, content starts at most 185px from the top.
- Keep every markup contract. `pm_ui_head` may add classes and reorder children but must still output `class="eyebrow"` with department name + `<svg`, the `.job` text, `<h1>` and `p.sub`.
- Measure in the browser console: `document.querySelector('.topbar').offsetHeight`, `.pagehead`, `.tabs`, first `.card` top. Record the four numbers in the commit message.

### Phase 4 - Components
In this order, each as its own commit: (1) buttons per spec incl. tonal rule `.btn.small.primary`, remove all other heights; (2) chips (one rule, alias list, `.pill.hot` = ok); (3) cards (shared selector list, variants, nested = flat); (4) fields and form grid, save bar; (5) tables and list rows; (6) `details`/accordion (one chevron style) and flash/banner as `.card.ok|warn|bad`; (7) menus and toast.
- Replace the five legacy button classes (see Buttons) with the four variants in markup where the class is only presentational.
- Done when: guard test shows buttons only at 36/28px, one chip rule, one card base, and the primary-per-section budget is not worse.

### Phase 5 - Screens by template (one commit per view file)
For each file, in this order: `view_home.php`, `view_agents.php`, `view_social.php`, `view_social_optimise.php` (and `pm_opt_item_html` in `lib/sx_optimise.php`), `view_social_studio.php`, `view_social_kit.php`, `view_social_page.php`, `view_social_channels.php`, `view_social_leadposts.php`, `view_social_content.php`, `view_social_growth.php`, `view_social_results.php`, `view_social_numbers.php`, `view_social_voice.php`, `view_sales_targeting.php`, `view_whatsapp.php`, `view_ops_*.php`, `view_business.php`, `view_outbound.php`, `view_drafts.php`, then the proposal tabs in `index.php`.
Checklist per file:
1. Apply its template (A to F). Group fields into titled cards, max 6 fields per card.
2. Remove every inline `style=` that is not a custom property; use utilities. Move any `<style>` block into `app.css` (patterns or pages section); delete the duplicate.
3. Buttons: at most one filled primary per section. In repeated rows keep `.small` (renders tonal). Destructive actions are `.btn.danger`.
4. Replace ad-hoc red/amber boxes by `.card.warn|.bad`, empty lists by `.card.empty` with one sentence and one button.
5. Run the guard test: offenders for this file must reach 0.

### Phase 6 - Delete dead CSS and aliases
- Script (throwaway): for every class selector in `app.css`, grep `lib/`, `index.php`, `assets/*.js` for the class name; list those with 0 hits. Delete them in batches of 10, run tests, load QA pages.
- Remove the temporary token aliases when nothing uses them.
- Done when: `app.css` is at most 650 lines, one `:root`, no duplicate declarations, all guard budgets are 0.

### Phase 7 - Responsive and accessibility
- Under 860px: top bar wraps into two rows (switcher + status, then scrolling nav), tabs scroll, two-column grids collapse, tables scroll horizontally, bulk bar and save bar fit the width.
- `pointer:coarse` and under 860px: controls 44px, inputs 16px font.
- Every interactive element has a visible `:focus-visible`; icon buttons have `aria-label`; chips carry text; `prefers-reduced-motion` stops transitions and the spinner.
- Check at 375px, 768px, 1280px, 1560px wide on the QA pages.

### Phase 8 - Close out
- Add `UI.md` (one page): tokens, the four button levels, chip/card/field/table rules, templates, utilities, "how to add a new screen". Add a line pointing to it in `README.txt`.
- Final full test run, final QA pass, move this plan to `plans/archive/`.

## QA pages (load only; use a 1280px window, then 375px)
`?tab=home`, `?tab=agents`, `?tab=whatsapp`, `?tab=targeting`, `?tab=social`, `?tab=social&view=leadposts`, `?tab=social&view=content`, `?tab=social&view=channels`, `?tab=social&view=inbox`, `?tab=social&view=page`, `?tab=social&view=growth`, `?tab=social&view=studio`, `?tab=social&view=kit`, `?tab=social&view=optimise`, `?tab=social&view=voice`, `?tab=social&view=results`, `?tab=social&view=audit`, `?tab=social&view=numbers`, `?tab=settings&section=business`, `?tab=settings&section=email`, `?tab=settings&section=connections`, `?tab=settings&section=team`, `?tab=business&new=1`, `?tab=proposal`, `?tab=history`, `?tab=template`. Base URL `http://127.0.0.1:8085/index.php`.

## Targets (check at the end)
| Measure | Now | Target |
|---|---|---|
| `app.css` lines / `:root` blocks | 785 / 4 | at most 650 / 1 |
| Font sizes in CSS | 18 | 6 |
| Radii (px values) | 7 | 2 (+ pill, circle) |
| Button heights | 38, 32 + ad-hoc | 36, 28 |
| Inline `style=` with banned properties | 338 | at most 40, all data-driven |
| `<style>` blocks in views | 7 | 0 |
| Filled primary buttons per section | up to 43 on Leads | at most 1 |
| First card from top of window (Leads) | 253px | at most 185px |
| Leads page height | 3174px | about 2600px or less |
| Chip / card base classes | 5 / 15+ | 1 / 1 (plus variants) |

## Kickoff prompts (copy one per Cline task)
- "Read plans/ui-redesign-plan.md and implement Phase 0 exactly as described. Do not explore beyond the files listed. Run the full tests, commit, push, report in 5 lines."
- Same sentence with "Phase 1", "Phase 2", ... one task each. Phase 5 may be split: one task per three view files.

## Open Questions
None blocking. Defaults chosen: department colour is the only accent on a screen; 14px body with 36px controls; tonal buttons for small primaries; no dark mode.
