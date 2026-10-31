# UI system (one page)

The app looks and works like the new Outlook (web / Windows), only calmer: one accent colour, few borders, few chips, white space, nothing decorative. When in doubt, remove.

Every screen of the firm uses one stylesheet, `assets/app.css`, in seven ordered sections: **1 tokens, 2 base, 3 shell, 4 components, 5 patterns, 6 pages, 7 responsive and print**. Each selector is defined once. Add to the section it belongs to; do not append override layers. The guard test that enforced this (budgets at 0 or 1) was removed with the test suite; keep to the rules by hand.

## Tokens (the single `:root` block)

| Group | Tokens |
|---|---|
| Surface and line | Fluent 2 neutrals: `--bg #f5f5f5` (page), `--surface #fff` (cards, panes), `--surface-2 #fafafa`, `--field #fff`, `--line #e0e0e0` (hairlines), `--line-strong #d1d1d1` (button and field edges) |
| Text | `--ink #242424`, `--ink-2 #424242` (labels), `--muted #616161` (also placeholders and the field's bottom edge) |
| Fonts | `--font` "Segoe UI Variable Text", Segoe UI (Outlook's font on Windows), then the bundled Inter (assets/fonts/sans*.ttf) elsewhere; `--display` is the same. Body text 14px; `h1` 20/600. Big numbers tabular |
| Status (text / bg / line) | `--ok`, `--warn`, `--bad`, each with `-bg` and `-line`. "Info" is `--dept` mixed into white |
| One accent | `--accent` = the business colour (set in `<head>`: brand previews only). `--dept` = the same colour made safe for white text: `pm_ui_safe_accent()` (`lib/ui.php`) mixes it with black until it reaches 4.5:1 and `index.php` sets it on `<body>` for every screen. Header, primary buttons, focus underline, active tab, selection, the rail's open item and unread bars all use `--dept`. No per-department colours (the `color` entries in `pm_ui_nav()` are not used for the page) |
| Type | `--fs-1 12` caption, chip, hint - `--fs-2 13` dense body, sub lines - `--fs-3 14` body, buttons, fields - `--fs-4 16` feature titles - `--fs-5 20` page and pane titles - `--fs-6 24` big numbers. Weights 400, 500, 600 only |
| Space | `--s-1 4`, `--s-2 8`, `--s-3 12`, `--s-4 16`, `--s-5 24`, `--s-6 32` (cards pad 16 20) |
| Radius | `--r-ctl 4` (buttons, fields, menu rows, rail tiles), `--r-card 8` (cards, panes, menus, flyouts), `--r-pill` (chips only), `50%` for avatars |
| Shadow | `--sh-card` (Fluent shadow2: the edge of a card, no border), `--sh-pop` (shadow16: menus, flyouts), `--sh-bar` |
| Controls | `--ctl 32px`, `--ctl-sm 28px` (Outlook's sizes); both become 44px on touch or under 860px, and fields go to 16px |
| Shell | `--hdr 48px` (56 on phones), `--rail 68px` (0 on phones), `--tabbar 56px`. Every sticky top is `calc(var(--hdr) + 8 or 12px)` |
| Motion | `--ease` (Fluent's decelerate curve). Cards rise in once (`rise`); menus fade and drop from their button (`pop`, 180ms); rail flyouts slide in after a 150ms hover intent; an opened fold unfolds (`unfold`). No lifts, no press-scale. All off under reduced motion |
| Layout | `--page-w 1200px` (1320 at 1920px and up), centred in the space right of the rail. `.mailview` and `.withpage` pages use the full width right of the rail |

## Buttons: four levels, plus danger and icon

| Level | Markup | Use |
|---|---|---|
| Filled | `.btn.primary` | The one main action of a card, section or form. **At most one per card/section/form** |
| Tonal | `.btn.small.primary` | The main action of each row in a list or table (same class, rendered light automatically) |
| Outline | `.btn` | Everything else: white, 1px `--line-strong`, 600 |
| Subtle | `.btn.ghost`, `button.link` | Open, Copy, Undo, Details: transparent, a faint grey on hover. Command bars render their outline buttons subtle |
| Danger | `.btn.danger` | Delete, Remove, Reject (outlined, never filled; keep `data-confirm`) |
| Icon | `button.icon` | Remove x, close. Always with `aria-label` |

Two heights only: 32 and `.small` 28 (44 on touch). Radius 4, flat (Fluent): the filled one is plain `--dept` and darker on hover. In a `.btns` group: primary first, outline, subtle, danger last.

## Chips, cards, fields, tables

- **Chip**: `.chip` (alias `.pill`, `.sx-chip`, `.rs-chip`, `.pick`), 20px, pill, 12/500. Variants `.ok .warn .bad .info`; `.chip.dot` adds a dot. Status always has a word, never colour alone. Use a chip only for what needs doing; plain muted text for the rest.
- **Card**: `.card` is the base (white, radius 8, no visible border: `--sh-card` is its edge; padding 16 20). The other card classes (`.sx-card`, `.bizcard`, `.lpcard`, ...) share the base in one selector list (the border is transparent, so `.card.ok|warn|bad`, `.bizcard` and `.hq-bizrow` can colour it). Variants: `.card.flat` (a card inside a card is flat automatically), `.card.item`, `.card.empty`, `.card.ok|warn|bad` (banners; also `.flash.ok|err`). Card head: `.card-head` with an `h2` (15/600); a folded card (`details.card`) has its title in the `summary` at the same size. Cards stack 16 apart; a section title between them (`h3`) has 24 above and 8 below.
- **Fields** (Fluent input): white, 1px `--line-strong` with a darker `--muted` bottom edge, `--ink-2` on hover, a 2px `--dept` underline on focus. Label above (12), control 32px, `.hint` under it (12 muted). Grid `.row` (auto-fit, min 240px, gap 12). At most 6 fields per card. Save bar `.formbar` is sticky at the bottom of the page.
- **Tables**: `table.grid`, `.sx-table`, `.rs`, `.hist` share one look (head 12/600 muted, cells 13px, hairlines, row hover; `td.num` right-aligned with tabular numbers). They scroll sideways under 860px. Long lists come 10 to a page. The Leads list and the activity log are cut on the server with `pm_ui_page()` (`lib/ui.php`: Previous / "11–20 of 47" / Next links that keep the other filters); their bulk "Select all" ticks only the page on screen, and `?at=<lead id>` (added by redirects, and by `assets/app.js` for any `#l<id>` link) opens the page that holds that lead. Any other table with more than 10 rows, or a list marked `data-page-items="<row selector>"`, is paged in the browser with Previous / Next / Show all; `data-nopage` keeps a table whole.
- **List rows**: `.sx-checks li`, `.idearow`, `.roster li`, `.nextlist li`, `.hq-needrow`: title and muted meta on the left, chip, actions on the right.

## Page templates

Every screen is one of: **A Dashboard** (`view_home.php`), **B Queue** (toolbar, dense rows, sticky bulk bar: Leads, WhatsApp, Plan, Inbox, Lead posts, Content), **C Settings** (stack of titled cards with a sticky save bar: Operations pages, Voice, Targeting, Enter numbers, business wizard), **D Fix-it list** (score, one collapsible card per area: Profiles, Audit, Channels, Growth), **E Gallery** (equal cards, preview on top: Studio, Brand kit), **F Report** (tiles, one table or chart per card: Results, Outbound, Drafts). Proposal tabs follow B and C (markup in `index.php`).

**B with folders** (Leads, Mailbox) is Outlook's mail view and fills the width right of the rail. Like mail, every item is in exactly one list of the pane (Leads: `pm_lead_box()`, `PM_LEAD_BOXES`), so the counts add up; the owner's own folders are extra groups. A lead card reads top down in three blocks: the next move (`.nextmove`, three lines), About them (facts with sources, the rest folded), then the message; history and notes fold away. `.mailview` holds `aside.mailside` with `pm_ui_folderpane()` (`lib/ui.php`: groups with a small chevron, folders indented with their count on the right, no count when empty, the open one on a grey tile with a `--dept` pill on its left, a `hot` count in `--dept`) and a `.mailmain` column: one flat command bar (search, subtle actions, `More`, one filled button), a `.listbar` (folder name and count, list search and Filters), then the list as **one card of rows** split by hairlines (`.leadlist`, `.mb-list`), never a card per row. **Message rows**: a 32px initials avatar (white initials on a fixed 8-colour palette picked by a hash of the name: `.lav` in Leads, `.av-in` in Mailbox), line 1 the name (600 when unread) with the score or time right-aligned in 12px muted, line 2 type · city or the subject in 13px muted plus at most two chips that need action; a row that waits for you gets a 3px `--dept` bar on its left like unread mail. Mailbox actions show on hover (keyboard focus too) on a desktop and under the text on touch. **Reading pane** (Leads, 1280px and up, `assets/app.js`): `.leadpanes` becomes list column (440, 460 from 1600) | `section.lead.readpane` (sticky, white, full height); opening a row moves its `div.body` into the pane (not a copy: forms keep their ids) under a head with the name, type · city and the row's chips, and marks the row `.sel` (tinted, with the bar). Empty state "Select a lead to read it". Below 1280px, or without JS, a row opens in place. Under 900px the folder pane becomes a row of pills that scrolls sideways.

## Shell

- **Header** (`pm_ui_topbar`): 48px, full width, sticky, solid `--dept` with white text, like Outlook's. Left: the waffle button that opens the business menu (`.dd.biz`, logo and "Working on" inside), then the business name. Centre: one search box (max 560, 32px, white on focus) that is a GET form to the Leads page (`q`). Right: the scheduler dot, a "?" to the Guide, the account avatar (initials only; its menu holds the name, the businesses they may open, Team access for an admin, Sign out).
- **App rail** (`pm_ui_rail`): `nav.apprail`, a sibling after `</header>`, fixed on the left, 68px, page grey. One 64x56 tile per department of `pm_ui_nav()`: 20px line icon over an 11px label; hover faint grey; the open department white with the card shadow, `--dept` icon and label, and a 3x20 `--dept` pill on its left edge. Its pages fly out to the right on hover (150ms intent) or from the hidden caret button on keyboard focus (`.navdd .ddbtn.caretbtn`, `.open`); the flyout is white, radius 8, `--sh-pop`, 32px rows, a `--dept` pill on the current page. The rail never clips (no overflow).
- **Page head**: almost none. `h1` 20/600, one line of `.sub` 13 muted, actions on the right. The department label (`.eyebrows`) stays in the markup but is hidden: the rail says where you are.
- **Ribbon tabs** (`pm_ui_tabs`): the department's pages as plain 14px words, the open one ink 600 with a 3px rounded `--dept` bar under it; one hairline under the row, no boxes.
- **Content** sits right of the rail on `--bg`; panes and cards are white. The first card starts about 170px from the top of the window (a page with a two-line sub a little lower).
- **Phones (under 860px)**: the header is 56px with the waffle, the search (flex) and the avatar; the rail becomes a **bottom tab bar** (fixed, 56px + safe area, white, hairline on top, 22px icons over 10px labels, the open one in `--dept` with a short pill above the icon, no flyouts: the ribbon tabs give the pages). `main` keeps 88px + safe area free at the bottom and the sticky bars (`.sx-bulk.on`, `.formbar`, `.btns.stick`, the toast) sit above the tab bar. Every control is 44px, fields 16px, tabs scroll, grids collapse, tables scroll inside themselves, header menus open full width, and nothing may be wider than the screen (every page is checked at 390px).

## Utilities (use these instead of `style=`)

`.stack`, `.cluster`, `.grow`, `.right` (`margin-left:auto`), `.nowrap`, `.tnum`, `.t-xs`, `.t-sm`, `.t-md`, `.mt-0..6`, `.mb-0..3`, `.w-full`, `.center`. Inline `style=` is allowed only for data-driven custom properties (`--bz`, `--dept`, `--av`, `--v`, `--w`) and bar widths in `%`.

## Adding a new screen

1. Register the page once in `pm_ui_nav()` (`lib/ui.php`); the rail, its flyout, the bottom tab bar and the ribbon tabs follow from it. A setting must have one editor only.
2. Start the view with `pm_ui_head($title, $sub, $actions)`; pick the template (A to F) and reuse its pattern.
3. Compose with the components above. No `<style>` block, no `style=` except the data-driven exceptions, no new font size, radius, colour or spacing value, no second accent colour; if something is missing, add one rule to the right section of `app.css`.
4. One filled `.btn.primary` per card; row actions use `.btn.small.primary`; destructive ones `.btn.danger` with `data-confirm`.
5. Never rename the hooks `ui.js` and `app.js` rely on (`.dd .ddbtn .menu .open`, `.navdd .caretbtn`, `[data-copy]`, `[data-confirm]`, `[data-busy]`, `.aigen`, `form.msg`, `.lsel`, `#leadsel`, `.leadpanes`, ...) or the `id=` anchors other pages link to.
6. Load the page at 390, 1280, 1440 and 2560px wide: nothing overflows on a phone, nothing looks lost or stretched on a big screen.

## One job per page (the cleanup rules)

- **A number is shown once.** The Overview and Insights hold the figures (leads, replies, health, goals); Sales, Social and Brand pages do not repeat them as tiles. The Director's strategy and its Refresh button live on the Overview only.
- **A status is said once.** A setup problem is shown by Operations > Health and as one row on the Overview; a page does not add its own banner for it (the Social plan keeps only its Connect Facebook card).
- **A setting has one editor.** Who to look for, the limits, the replies and the agents on/off: Sales > Targeting. The AI engine, model and budget: Operations > Connections. The email text: one "Default first message" per business (Travel Malawi's AI email is the same text with the `{opening}` line written by the AI). Switching business or adding one: the waffle menu in the header only. Prices: Template and prices (the proposal form only copies them).
- **Empty states are one line, not a card.** A section with nothing in it is left out (Content: suggested mix, bring back what worked) until it has something to show.
- **The page head is title, then one line of help.** Page-level buttons go in `.headacts`; none of them may repeat a tab, the rail or the header.
- **Text boxes show what is typed.** `textarea` grows to its content (assets/app.js, up to 70% of the window, also when a lead's body moves into the reading pane), a list of words or towns is a `textarea` and not a one-line field, and long help text sits under the field, never in the label.
- **Every page that holds settings can be reset.** The "Defaults" bar at the bottom of the page (lib/sx_reset.php, registered by page link in `pm_rs_pages()`) removes only that page's settings, never data, identity or logins, and keeps one Undo. A new settings page adds one entry there.
- **Business-specific pages show only that business.** Template and prices shows the packages and wording of the business in use (the other business's rows stay in the form, hidden, so saving keeps them).
