# UI system (one page)

Every screen of the firm uses one stylesheet, `assets/app.css`, in seven ordered sections: **1 tokens, 2 base, 3 shell, 4 components, 5 patterns, 6 pages, 7 responsive and print**. Each selector is defined once. Add to the section it belongs to; do not append override layers. The guard test that enforced this (budgets at 0 or 1) was removed with the test suite; keep to the rules by hand.

## Tokens (the single `:root` block)

| Group | Tokens |
|---|---|
| Surface and line | `--bg #f5f4f0` (warm paper, with a soft glow of the department colour at the top), `--surface #fff`, `--surface-2 #faf9f7`, `--field #f8f7f4` (inside of fields), `--line #e9e7e1`, `--line-strong #d6d3cb`, `--bar #0f1013` (the top bar) |
| Text | `--ink #15161a`, `--ink-2 #3a3b42` (labels), `--muted #6e6d73` (also for placeholders) |
| Fonts | `--font` Inter (assets/fonts/sans*.ttf) for everything; `--display` Source Serif (serif-bold.ttf) for `h1` page titles only. Big numbers stay Inter (tabular) |
| Status (text / bg / line) | `--ok`, `--warn`, `--bad`, each with `-bg` and `-line`. "Info" is `--dept` mixed into white |
| Accents | `--accent` = the business (set in `<head>`: top stripe, business switcher, brand previews only). `--dept` = the department (set on `<body>`): primary buttons, active tab, links, focus ring, selected state |
| Type | `--fs-1 12` caption, chip, hint, table head - `--fs-2 13` dense body, small buttons, table cells - `--fs-3 14` body, buttons, fields - `--fs-4 16` feature titles - `--fs-5 20` page title - `--fs-6 24` big numbers. Weights 400, 500, 600 only |
| Space | `--s-1 4`, `--s-2 8`, `--s-3 12`, `--s-4 16`, `--s-5 24`, `--s-6 32`. Nothing else for margin, padding, gap |
| Radius | `--r-ctl 10` (fields), 12 for text areas, `--r-card 18` (cards), `--r-pill` (buttons, tabs, chips, the save bar), `50%` for circles |
| Shadow | `--sh-card`, `--sh-pop`, `--sh-bar` |
| Controls | `--ctl 40px`, `--ctl-sm 30px`; both become 44px on touch or under 860px, and fields go to 16px |
| Motion | `--ease`; cards, the page head and flashes rise in once (`@keyframes rise`), primary buttons lift 1px on hover. All off under reduced motion |
| Layout | `--page-w 1200px`; pages with `.withpage` use 1560px |

Department colours live only in `pm_ui_nav()` (`lib/ui.php`); each passes 4.5:1 with white text (checked by the guard test).

## Buttons: four levels, plus danger and icon

| Level | Markup | Use |
|---|---|---|
| Filled | `.btn.primary` | The one main action of a card, section or form. **At most one per card/section/form** |
| Tonal | `.btn.small.primary` | The main action of each row in a list or table (same class, rendered light automatically) |
| Outline | `.btn` | Everything else |
| Ghost | `.btn.ghost`, `button.link` | Open, Copy, Undo, Details |
| Danger | `.btn.danger` | Delete, Remove, Reject (outlined, never filled; keep `data-confirm`) |
| Icon | `button.icon` | Remove x, close. Always with `aria-label` |

Two heights only: 40 and `.small` 30. Buttons are pills; the filled one has a soft gradient and a shadow in the department colour. In a `.btns` group: primary first, outline, ghost, danger last.

## Chips, cards, fields, tables

- **Chip**: `.chip` (alias `.pill`, `.sx-chip`, `.rs-chip`, `.pick`), 20px, pill, 12/500. Variants `.ok .warn .bad .info`; `.chip.dot` adds a dot. Status always has a word, never colour alone.
- **Card**: `.card` is the base (white, 1px line, radius 12, light shadow). The other card classes (`.sx-card`, `.bizcard`, `.dept`, `.lpcard`, ...) share the same base in one selector list. Variants: `.card.flat` (a card inside a card is flat automatically), `.card.item` (repeated items), `.card.empty` (one sentence, one button), `.card.ok|warn|bad` (banners; also `.flash.ok|err`). Card head: `.card-head` with an `h2` (14/600).
- **Fields**: label above (12 muted), control 36px, `.hint` under it (12 muted). Grid `.row` (auto-fit, min 240px, gap 12). At most 6 fields per card. Save bar `.formbar` is sticky at the bottom of the page.
- **Tables**: `table.grid`, `.sx-table`, `.rs`, `.hist` share one look (head 12/600 muted, cells 13px, bottom borders, row hover; `td.num` right-aligned with tabular numbers). They scroll sideways under 860px.
- **List rows**: `.sx-checks li`, `.idearow`, `.roster li`, `.nextlist li`, `.hq-needrow`: title and muted meta on the left, chip, actions on the right.

## Page templates

Every screen is one of: **A Dashboard** (`view_home.php`), **B Queue** (toolbar, dense rows, sticky bulk bar: Leads, WhatsApp, Plan, Inbox, Lead posts, Content), **C Settings** (stack of titled cards with a sticky save bar: Operations pages, Voice, Targeting, Enter numbers, business wizard), **D Fix-it list** (score, one collapsible card per area: Profiles, Audit, Channels, Growth), **E Gallery** (equal cards, preview on top: Studio, Brand kit), **F Report** (tiles, one table or chart per card: Results, Outbound, Drafts). Proposal tabs follow B and C (markup in `index.php`).

## Shell

Dark glass top bar 58px with a 2px `--accent` stripe; each department is a pill (the open one white, its icon in the department colour). Page head: the department as a tinted pill, a serif `h1`, one-line `p.sub`, actions. The department's pages are a segmented pill strip (the open page filled in `--dept`). Fields are filled (`--field`) and turn white with a `--dept` ring on focus. The first card starts about 184px from the top of the window. Under 860px the bar wraps, tabs scroll, grids collapse.

## Utilities (use these instead of `style=`)

`.stack`, `.cluster`, `.grow`, `.right` (`margin-left:auto`), `.nowrap`, `.tnum`, `.t-xs`, `.t-sm`, `.t-md`, `.mt-0..6`, `.mb-0..3`, `.w-full`, `.center`. Inline `style=` is allowed only for data-driven custom properties (`--bz`, `--dept`, `--v`, `--w`) and bar widths in `%`.

## Adding a new screen

1. Register the page once in `pm_ui_nav()` (`lib/ui.php`); the top bar, tabs, department label and colour follow from it. A setting must have one editor only.
2. Start the view with `pm_ui_head($title, $sub, $actions)`; pick the template (A to F) and reuse its pattern.
3. Compose with the components above. No `<style>` block, no `style=` except the data-driven exceptions, no new font size, radius, colour or spacing value; if something is missing, add one rule to the right section of `app.css`.
4. One filled `.btn.primary` per card; row actions use `.btn.small.primary`; destructive ones `.btn.danger` with `data-confirm`.
5. Never rename the hooks `ui.js` and `app.js` rely on (`.dd .ddbtn .menu .open`, `[data-copy]`, `[data-confirm]`, `[data-busy]`, `.aigen`, `form.msg`, ...) or the `id=` anchors other pages link to.
6. Load the page at 1280px and 390px wide and check nothing overflows.

## One job per page (the cleanup rules)

- **A number is shown once.** The Overview and Insights hold the figures (leads, replies, health, goals); Sales, Social and Brand pages do not repeat them as tiles. The Director's strategy and its Refresh button live on the Overview only.
- **A status is said once.** A setup problem is shown by Operations > Health and as one row on the Overview; a page does not add its own banner for it (the Social plan keeps only its Connect Facebook card).
- **A setting has one editor.** Who to look for, the limits, the replies and the agents on/off: Sales > Targeting. The AI engine, model and budget: Operations > Connections. The email text: one "Default first message" per business (Travel Malawi's AI email is the same text with the `{opening}` line written by the AI). Switching business or adding one: the top-left menu only. Prices: Template and prices (the proposal form only copies them).
- **Empty states are one line, not a card.** A section with nothing in it is left out (Content: suggested mix, bring back what worked) until it has something to show.
- **The page head is title, then one line of help.** Page-level buttons go in `.headacts`; none of them may repeat a tab or the top bar.
- **Text boxes show what is typed.** `textarea` grows to its content (assets/app.js, up to 70% of the window), a list of words or towns is a `textarea` and not a one-line field, and long help text sits under the field, never in the label.
- **Every page that holds settings can be reset.** The "Defaults" bar at the bottom of the page (lib/sx_reset.php, registered by page link in `pm_rs_pages()`) removes only that page's settings, never data, identity or logins, and keeps one Undo. A new settings page adds one entry there.
- **Business-specific pages show only that business.** Template and prices shows the packages and wording of the business in use (the other business's rows stay in the form, hidden, so saving keeps them).
