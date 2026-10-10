# MARKETING.md — ProManaged Marketing Engine · Agent & Social Growth Register (cycle 4)

> **Working document for the marketing side of the ProManaged marketing engine** (`ProManaged Proposals` repo).
> Claude and Cline read this file FIRST in every marketing session. Cycle 1 shipped 24 improvements (git: `cb8cf50`), cycle 2 shipped 16 (`95af670`),
> cycle 3 shipped 13 (`1ad09f7`); this file restarts the cycle from the new inventory. When the register below is empty, this file is wiped and cycle 5 begins.

---

## 1 · How this document works

1. **SUGGEST** — any session may add gaps and ideas as `PROPOSED` rows in the register (IDs like `C4-A01`), each with *What / Why / Where / Done-when*.
2. **ACCEPT** — only the owner accepts. Say "accept C4-A01" (or edit the row's status to `ACCEPTED`).
3. **BUILD** — an `ACCEPTED` row is implemented, covered by a test where the harness allows it, committed, and marked `DONE` with date + commit SHA.
4. **WIPE** — when every row in the register is `DONE`, delete this file and write a fresh one for cycle 5 (git preserves history).

Status legend: `PROPOSED` → `ACCEPTED` → `BUILDING` → `DONE`.

A fresh session must be able to resume purely from this file + the code. Never implement a row that is not `ACCEPTED`. (Direct requests from the owner in chat are
accepted by being made; they are logged in the Done log below, not in the register.)

---

## 2 · Non-negotiables (every existing and future agent obeys these)

1. **Human gate** — nothing goes out without the owner: emails need `approved_at`, social posts need `approved` status (unless Auto-publish is explicitly on), WhatsApp needs the owner to press send (or an approver to approve a campaign or a drafted answer), X posts publish only from approved posts. Lead posts are drafts like any other.
2. **STOP is absolute** — suppression by exact address AND company domain (`pm_suppressed`), permanent "Do not contact" blocks (never archived), STOP line in every email, STOP honoured on WhatsApp (`pm_wa_biz_handle`, re-checked at every campaign send) and reply-email intents. WhatsApp campaigns reach only people who agreed to WhatsApp messages (`pm_wa_optin`) unless the owner switches that off for one campaign.
3. **Honesty** — never invent names, emails, phones, metrics, awards, prices, dates or capabilities. Facts come only from published sources or the owner's own words. `PM_AGENT_RULES` is the minimum bar. The partnership radar and the directory scout list only pages they found, with their address. "Unhinged" copy is energy, never a licence for a claim, a number, a price, a guarantee or fake urgency.
4. **Malawi focus** — skip international chains and out-of-market businesses.
5. **Caps & pacing** — warm-up daily send caps that drift adaptively (`pm_adaptive_send_cap`), per-brand AI token budgets, WhatsApp daily limit (40, shared by hand-sent messages, answers, asks and campaigns), one contact per company domain per week, max 2 recycled + 2 repurposed posts/week, one feed post a day per business, send window Mon–Fri 08:00–16:30, 1 email per scheduler pass with 4–8 min jitter, 3 campaign messages per pass, bounce breaker.
6. **Clean copy** — no prices in cold emails, no shouting/spam/phish wording (`pm_outreach_lint`), one plain link at most, honest subjects, lint gates on social drafts, lead posts, engagement drafts, campaigns and partnership messages.
7. **Every business is its own voice** — businesses live in a registry (`lib/brands.php`): ProManaged IT, Travel Malawi, and any business added in the app. Each keeps its own mailbox, tokens, brain (what the AI may say), numbering, signature, trend line, partner list, offers and limits. Code never writes `$brand === 'travel' ? … : …` to mean "the other business": it asks `pm_brand_ids`, `pm_brand_name`, `pm_brand_profile`, `pm_brand_env_prefix`. `tests/test_brands.php` proves a third business never receives another business's words.
8. **Cost discipline** — cheapest model that does the job, per-model health quarantine, provider failover (Gemini ↔ Anthropic), scout backpressure when drafts pile up (`pm_backlog_state`). Lead posts, landing pages and keyword replies use no AI.
9. **Optional channels stay off** — X, WhatsApp Business (answers and campaigns) do nothing unless their keys exist in `.env`; nothing new may switch a channel on without keys. Paid reach (the Ads screen) is created paused.

---

## 3 · Where we are today (post cycle 3)

### 3.1 The agent swarm (`run_agents.php`, `lib/agents.php`, `lib/sx_learn.php`, `lib/sx_harvest.php`)

| Agent | Function | Ships |
|---|---|---|
| **Director** | `pm_director_brief` | Daily brief + numeric `focus_weights` per sector; scouts allocate slots proportionally. |
| **Scout** | `pm_agent_scout` | Sector × city rotation, weighted by Director/outcome weights; archived leads count as known. |
| **Directory scout** | `pm_agent_directory` | Weekly: reads public directories and member lists for one town; leads tagged `src: directory` with the listing page. |
| **Watcher** | `pm_agent_watch` | One call/day per business: fresh signals. |
| **Contact Finder / Re-verify** | `pm_agent_contact`, `pm_agent_reverify` | Owner names and published contacts; periodic re-check shown on the lead card. |
| **Qualifier** | `pm_agent_qualify` | Scores with past results; re-scores once research lands; **engagement raises the score** (proposal opened twice, a reply; capped at 25 points, never above 95). |
| **Writer / Follow-up** | `pm_agent_write`, `pm_agent_followup` | Email + WhatsApp drafts; subject arms A/B; first-email subjects stop early when one style clearly loses (Fisher exact). |
| **Win-back / Post-sign** | `pm_agent_winback`, `pm_agent_postsign` | Drafts the owner sends by email or **WhatsApp Business inside the 24-hour window**; a counted Google review drafts a thank-you. |
| **Reply** | `pm_agent_reply` | Classifies email + WhatsApp, answered as the lead's own business; email and WhatsApp each have a draft-or-auto switch. |
| **Campaigns** | `lib/sx_wacampaign.php` | WhatsApp batches to people who agreed to WhatsApp, with a chosen approved template, approved by an approver, STOP re-checked per send. |

### 3.2 Socials (`lib/social*.php`, `lib/sx_*.php`)

- Planner reads outcome weights, per-business trends, audience mix, cross-business lessons and **the best audience segment** (bounded at 40% of a plan, stated in "Why this week's mix").
- **Lead posts** (`lib/sx_leadposts.php`, `lib/leadoffers.php`, Social > Lead posts): offers → 12 templates × Calm/Bold/Unhinged → drafts with a drawn button, a comment keyword (buyer by code), a landing page (`enquire.php?mode=offer`) or a tap-to-chat link; per-offer results.
- Weekly partnership radar with Sent / Replied / No tracking that the radar learns from; per-business trends; picture re-cuts; early-stop experiments; X numbers typed per post; segment scoreboard; reviews won per month; old published posts archived after 18 months with their numbers kept.

### 3.3 Businesses, trust and ops

- **Add a business** in three steps (questions → check the AI draft → ready checklist); own settings page, own mailbox (typed in or `<ID>_SMTP_*` in `.env`), hide with data kept; the scheduler starts its daily run.
- One shell for every screen (`lib/ui.php`, `assets/ui.js`): top bar with business menu and drop-downs, page head, tab style, foldable sections.
- Setup health with **Test now** buttons (mail login, inbox, Facebook, WhatsApp); weekly `data/*.json` archive with **restore of one file** (the replaced file is kept; secrets are never overwritten); archive search and bulk restore for leads.

---

## 4 · The multi-swarm blueprint

✓ = shipped · **bold** = proposed below.

| Swarm | Mission | Agents | Status |
|---|---|---|---|
| **HUNT** — find | Fresh, high-probability businesses | Scout ✓ · Directory scout ✓ · Contact Finder ✓ · Research ✓ · Watcher ✓ · **Import my own contacts** · **Onboard from a website** | Shipped; intake open |
| **HARVEST** — convert | Leads → replied → proposed → signed | Qualifier ✓ · Writer ✓ · Follow-up ✓ · Win-back ✓ · Post-sign ✓ · engagement score ✓ | Shipped; **proposals for added businesses** open |
| **REPLY** — respond | Answer everything fast | Reply ✓ · Page Judge ✓ · keyword buyers ✓ · Thread sync ✓ · WA responder ✓ | Shipped; **one WhatsApp number per business** open |
| **BROADCAST** — publish | A presence that compounds | Planner ✓ · Lead posts ✓ · Artist ✓ · Variants ✓ · Publisher ✓ · Recycle ✓ · Repurposer ✓ · X ✓ · **Offer experiments** · **QR poster** | Shipped; testing open |
| **GROWTH** — presence | Own the platforms buyers search | GBP ✓ · Ads hints ✓ · Reviews ✓ · Partnership radar ✓ (tracked) · **Paid lead ad from an offer** | Mostly shipped |
| **GUARDIAN** — protect | Honest, quiet, cheap | Suppression ✓ · Deliverability ✓ · Caps ✓ · Forget/export ✓ · Setup health + tests ✓ · Archive + restore ✓ · **Unhide a business** | Shipped |

---

## 5 · Improvement register — cycle 4 (all PROPOSED until the owner accepts)

### 5.1 Agents, replies and outreach

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| C4-A01 | One WhatsApp Business number per business: the webhook and the campaign sender pick the phone id and token by the lead's business (`<ID>_WA_BIZ_*`), so an added business does not share ProManaged's number | `lib/wa_biz.php`, `wa.php`, `lib/sx_wacampaign.php` | A lead's message and a campaign use its own business's number; one without keys stays off; tests green | PROPOSED |
| C4-A02 | Per-business X account: `X_*` keys with the business prefix; a business without its own keys never posts to another business's X | `lib/sx_boost.php` (`pm_x_cfg`) | `pm_x_cfg($brand)` reads `<PREFIX>X_*`; the posting job and Setup health use it; tests green | PROPOSED |
| C4-A03 | Auto-capture keyword commenters as leads (no AI): when the judge sees an offer keyword, add the person as a lead (status Replied, source offer) and tell the owner, instead of waiting for "Add buyers to leads" | `lib/social_growth.php`, `lib/sx_leadposts.php` | A keyword comment creates one lead (deduped by Facebook id) carrying the offer; the owner is alerted once; tests green | PROPOSED |
| C4-A04 | Import my own contacts: upload a CSV of existing customers or enquiries for a business; rows become leads with a recorded WhatsApp opt-in only when the file says so, and STOP / duplicates are honoured | `lib/view_agents.php`, `lib/outbound.php` | A preview shows what will be added and what is skipped (duplicates, suppressed); nothing is contacted by importing; tests green | PROPOSED |
| C4-A05 | Onboard from a website: paste the business's website in the add-a-business form and prefill the answers from the site (one fetch, facts only, each fact with the page it came from) | `lib/brands.php`, `lib/view_business.php` | The draft shows each prefilled fact with its source; anything not found stays empty; tests green | PROPOSED |
| C4-A06 | A simple one-page offer PDF for an added business (no price list): its name, what it offers, who to contact, from its own brain | `lib/pdf.php`, `index.php` | An added business can email a one-page "about us and what we offer" PDF to a lead, through the normal approval and lint | PROPOSED |

### 5.2 Socials and growth

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| C4-S01 | Offer experiments: pair two volumes (or two templates) of the same offer as a social experiment and keep the winner as the offer's default volume | `lib/sx_leadposts.php`, `lib/sx_experiments.php` | Two drafts are tagged as arms; the early-stop rule judges them; the winner sets the default; tests green | PROPOSED |
| C4-S02 | QR poster: a printable picture with the offer's headline, its button and a QR code to the landing page (or the tap-to-chat link), sized for A5 and for a WhatsApp Status | `lib/sx_leadposts.php`, `lib/sx_cards.php` | One click makes the picture; the QR opens the page with `src=qr`; tests green | PROPOSED |
| C4-S03 | A paid lead ad from an offer: create a paused Facebook lead-form ad (the form's questions are the offer's questions), budget-capped by the existing Ads rules | `lib/social_growth.php` (Ads), `lib/sx_leadposts.php` | The Ads screen plans and creates a paused lead ad from an offer; spend stays under the daily cap; tests green with the Graph stub | PROPOSED |
| C4-S04 | Directory leads visible: a filter and a tag on the Leads screen for `src: directory` (and `watch`, `scout`), with the listing page as the source link | `lib/view_agents.php` | The owner can filter by where a lead was found and sees the source link on the card; tests green | PROPOSED |

### 5.3 Trust, ops and recovery

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| C4-G01 | Unhide a business from the screen (today it needs `data/brands.json` edited by hand) | `lib/view_business.php`, `index.php` | Hidden businesses are listed under Settings with an Unhide button; tests green | PROPOSED |
| C4-G02 | Test buttons for LinkedIn and X in Setup health, like mail, inbox, Facebook and WhatsApp | `lib/sx_ops.php`, `lib/sx_boost.php` | Each connected row has Test now and reports ok or the reason; tests green | PROPOSED |
| C4-G03 | A visible record of what the scheduler did last (per job, per business, with the time), so "nothing happened" can be told from "it ran and found nothing" | `lib/social_jobs.php`, `lib/sx_ops.php` | Setup health shows each job's last run and result; tests green | PROPOSED |

---

## 6 · Done log

### Owner requests in chat, 2026-10-11 (not register rows)

- **Any business, not two** (`237aff3`, `056c459`): businesses are a registry (`lib/brands.php`); add one with three questions; every agent, reply, social planner, scheduler job and screen speaks as the business it works for. Fixes found on the way: WhatsApp replies were drafted as whichever business the process started in; the Director card assumed advice existed.
- **One look for every screen** (`056c459`): top bar with drop-downs, page head, one tab style, foldable sections, Leads / WhatsApp / Social / Proposals / Settings; `tests/test_pages.php` loads every screen for every business over HTTP.
- **Lead posts** (`427c982`): free, unlimited, honest lead-capture posts — see §3.2. The lead card now shows where a lead came from (the panel existed but was never drawn). Facts: Facebook ads are never free (the Ads screen stays paid and paused); a normal Facebook post cannot carry a clickable button, so the button is in the picture, on the landing page and as the tap-to-chat link, plus the Page's own action button.

### Cycle 3 — shipped 2026-10-10 in `1ad09f7`

All 13 rows (C3-A01 … C3-A09, C3-G01 … C3-G04): WhatsApp opt-in records and opted-in-only campaigns; several named templates, one per campaign; win-back and post-sign asks on WhatsApp Business inside the 24-hour window; engagement raises the score; early stop for first-email subjects (Fisher exact test, at least 6 sends an arm); partnership outreach tracking that the radar learns from; reviews won per month and a thank-you draft; planning by the best segment (40% cap); a weekly directory scout; restore of one file from a weekly archive; Test now buttons in Setup health; archive search with bulk restore; old published posts archived with their numbers kept. Decisions: the opt-in is worked out from the conversation as well as recorded (a WhatsApp message from them counts; STOP never does); a campaign's "agreed only" default is on and is the owner's per-campaign choice; a restore keeps the current mail logins and tokens, and keeps the file it replaces in `data/backup/`; the directory scout lists only members it saw on a page, with the page address. Tests: cycle 3 182.

### Cycle 2 — shipped 2026-10-10 in `95af670` (register marked done in `90fff3f`)

All 16 rows (C2-A01 … C2-A12, C2-G01 … C2-G04): win-back, post-sign and re-check results in the UI; re-score after research; follow-up subject arms; review-before-send for WhatsApp Business answers; per-segment scoreboard; X numbers; early-stop experiments; WhatsApp campaign drafts behind approval; partnership radar; per-brand trends; picture re-cuts; Google review link; setup-health panel; lead archiving; housekeeping; weekly data archive.

### Cycle 1 — shipped 2026-10-10 in `cb8cf50` (register a8aa216)

24 accepted rows — outcome-weighted scouting, watcher, re-verify, engagement follow-ups, subject A/B, Director weights, win-back, post-sign, unified inbox, research queue, provider failover, planner learning + strategy panel, trend radar, repurposing, custom experiments, proactive drafts, ads hints, X posting, audience segments, shared learnings, WA Business responder, deliverability checks, adaptive caps, forget/export. Skipped by owner: MG-M01, MG-M02.

*When every cycle-4 row is DONE, wipe this file and write cycle 5 from the new inventory.*

---

## 7 · Session rules for Claude / Cline

1. Read this file FIRST in any marketing session. It is the plan; the code is the implementation.
2. Add new gaps only as `PROPOSED` rows. Never implement a row whose status is not `ACCEPTED`.
3. When the owner accepts (explicitly, e.g. "accept C4-A04"): mark `ACCEPTED`, build it, test it, commit, then mark `DONE` with date + commit SHA and move it to the Done log.
4. A change that contradicts §2 (Non-negotiables) is never a valid suggestion.
5. When the register is empty: celebrate, wipe this file, and write the next MARKETING.md from the new inventory.
6. Tests: `php tests/test_brands.php`, `test_pages.php`, `test_leadposts.php`, `test_cycle2.php`, `test_cycle3.php`, `test_channels.php`, `test_marketing.php`, `test_measure.php` (the last reads copies of the real `data/`, so a few follower/focus checks can differ with live data; compare against the previous commit before blaming a change).
