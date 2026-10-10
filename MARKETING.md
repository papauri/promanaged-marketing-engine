# MARKETING.md — ProManaged Marketing Engine · Agent & Social Growth Register (cycle 3)

> **Working document for the marketing side of the ProManaged marketing engine** (`ProManaged Proposals` repo).
> Claude and Cline read this file FIRST in every marketing session. Cycle 1 shipped 24 improvements (git: `cb8cf50`), cycle 2 shipped 16 (git: `95af670`);
> this file restarts the cycle from the new inventory. When the register below is empty, this file is wiped and cycle 4 begins.

---

## 1 · How this document works

1. **SUGGEST** — any session may add gaps and ideas as `PROPOSED` rows in the register (IDs like `C3-A01`), each with *What / Why / Where / Done-when*.
2. **ACCEPT** — only the owner accepts. Say "accept C3-A01" (or edit the row's status to `ACCEPTED`).
3. **BUILD** — an `ACCEPTED` row is implemented, covered by a test where the harness allows it, committed, and marked `DONE` with date + commit SHA.
4. **WIPE** — when every row in the register is `DONE`, delete this file and write a fresh one for cycle 4 (git preserves history).

Status legend: `PROPOSED` → `ACCEPTED` → `BUILDING` → `DONE`.

A fresh session must be able to resume purely from this file + the code. Never implement a row that is not `ACCEPTED`.

---

## 2 · Non-negotiables (every existing and future agent obeys these)

1. **Human gate** — nothing goes out without the owner: emails need `approved_at`, social posts need `approved` status (unless Auto-publish is explicitly on), WhatsApp needs the owner to press send (or an approver to approve a campaign or a drafted answer), X posts publish only from approved posts.
2. **STOP is absolute** — suppression by exact address AND company domain (`pm_suppressed`), permanent "Do not contact" blocks (never archived), STOP line in every email, STOP honoured on WhatsApp (`pm_wa_biz_handle`, re-checked at every campaign send) and reply-email intents.
3. **Honesty** — never invent names, emails, phones, metrics, awards, prices, dates or capabilities. Facts come only from published sources. `PM_AGENT_RULES` is the minimum bar. The partnership radar lists only pages it found, with their address.
4. **Malawi focus** — skip international chains and out-of-market businesses.
5. **Caps & pacing** — warm-up daily send caps that drift adaptively (`pm_adaptive_send_cap`), per-brand AI token budgets, WhatsApp daily limit (40, shared by hand-sent messages, answers and campaigns), one contact per company domain per week, max 2 recycled + 2 repurposed posts/week, send window Mon–Fri 08:00–16:30, 1 email per scheduler pass with 4–8 min jitter, 3 campaign messages per pass, bounce breaker.
6. **Clean copy** — no prices in cold emails, no shouting/spam/phish wording (`pm_outreach_lint`), one plain link at most, honest subjects, lint gates on social drafts, engagement drafts, campaigns and partnership messages.
7. **Two brands, two voices** — ProManaged IT (Build / Source / Support, business buyers) and Travel Malawi (hosts & travellers). Each keeps its own mailbox, tokens, pricing, numbering, signature, trend line and partnership list.
8. **Cost discipline** — cheapest model that does the job, per-model health quarantine, provider failover (Gemini ↔ Anthropic), scout backpressure when drafts pile up (`pm_backlog_state`).
9. **Optional channels stay off** — X, WhatsApp Business (answers and campaigns) do nothing unless their keys exist in `.env`; nothing new may switch a channel on without keys.

---

## 3 · Where we are today (post cycle 2)

### 3.1 The agent swarm (`run_agents.php`, `lib/agents.php`, `lib/sx_learn.php`, `lib/sx_harvest.php`)

| Agent | Function | Ships |
|---|---|---|
| **Director** | `pm_director_brief` | Daily brief + numeric `focus_weights` per sector; scouts allocate slots proportionally. |
| **Scout** | `pm_agent_scout` | Sector × city rotation, weighted by Director/outcome weights; source rotation; archived leads count as known. |
| **Watcher** | `pm_agent_watch` | One call/day per brand: fresh signals (new sites, hiring ads, reopenings). |
| **Contact Finder** | `pm_agent_contact` | Owner/manager name + published contacts; trusted-source gate. |
| **Re-verify** | `pm_agent_reverify` | Periodic pass over stale leads; the result (new address, nothing changed) shows on the lead card and in Today. |
| **Qualifier** | `pm_agent_qualify` | Scores with past-results context; **re-scores once research lands** (`pm_requalify_due`, 3 a run). |
| **Writer** | `pm_agent_write` | Email + WhatsApp drafts; each lead gets a subject style by arm (A statement / B question); mirrors the winning style. |
| **Follow-up** | `pm_agent_followup` | Engagement-aware; carries its own subject arm, logged when sent (`followup_sent`), judged by `pm_jobg_email_exp`. |
| **Win-back** | `pm_agent_winback` | Fresh angle for lost leads, 60d cooldown; draft on the lead card, sent by the owner (`pm_send_first … winback_draft`). |
| **Post-sign** | `pm_agent_postsign` | Testimonial + Google review (with the configured link) + referral drafts; sent one at a time (`pm_send_postsign`). |
| **Research** | `pm_agent_research` | Deep dive; auto-queued for follow-up-bound and proposal-bound leads. |
| **Polish** | `pm_agent_polish` | Proposal wording polish. |
| **Reply** | `pm_agent_reply` | Classifies email + WhatsApp; `needs_human` escalation; email and WhatsApp each have a draft-or-auto switch. |
| **Learning layer** | `pm_outcome_weights`, `pm_jobg_email_exp`, `pm_jobg_learnings_share`, `pm_jobg_thread_sync` | Outcome weights, first-email and follow-up subject experiments, cross-brand learnings, Messenger→thread sync. |
| **Campaigns** | `lib/sx_wacampaign.php` | WhatsApp batches: drafted, approved by an approver, sent 3 per pass in working hours, STOP re-checked per send. |

### 3.2 Socials (`lib/social*.php`, `lib/sx_*.php`, `lib/sx_boost.php`, `lib/sx_amplify.php`)

- Planner reads outcome weights + per-brand trends + audience mix + cross-brand lessons + best audience segment (`pm_plan_learnings`); the "Why this week's mix" and partnership cards show once at the top of Plan.
- Posts carry a `segment` (the audience they speak to); the scoreboard breaks results down by segment; X numbers are typed per post and score the post when nothing else does.
- Weekly partnership radar (one search call per brand, real pages only, draft DMs); per-brand trend radar; repurposing re-cuts the winner's own picture (story / carousel cover, photo layout when there is a photo); experiments stop early only when one arm is clearly worse.
- Measure-steer unchanged: snapshots, scoreboard, funnel, ads, weekly report, recycle, GBP, manual channels, brand kit.

### 3.3 Trust, privacy and ops

- Deliverability self-check + bounce trends; adaptive send caps; **Forget lead** (also from the archive) and **Export**; JSON store with locks/backups/restore; token watchdog; rate limits; SSRF guard.
- **Setup-health panel** (Settings) lists every channel's ready/not-ready state and what to add; **lead archiving** (12 quiet months → `data/leads_archive.json`, restorable); **housekeeping** (daily clear of old agent-job, rate-limit and temp files); **weekly data archive** (secrets scrubbed, 8 weeks kept, zip or `.json.gz`).

---

## 4 · The multi-swarm blueprint — where cycle 2 landed

✓ = shipped · **bold** = still proposed (cycle 3 candidates below).

| Swarm | Mission | Agents | Status |
|---|---|---|---|
| **HUNT** — find | Fresh, high-probability businesses | Scout ✓ · Contact Finder ✓ · Research ✓ · Watcher ✓ · Re-verify results in UI ✓ · **Directory scout passes** | Mostly shipped |
| **HARVEST** — convert | Leads → replied → proposed → signed | Qualifier ✓ (re-scores) · Writer ✓ · Follow-up ✓ · Win-back ✓ · Post-sign ✓ · **Proposal-opened re-score** | Shipped; signal loops open |
| **REPLY** — respond | Answer everything fast | Reply ✓ · Page Judge ✓ · Thread sync ✓ · WA responder ✓ · Review-before-send ✓ | Shipped |
| **BROADCAST** — publish | A presence that compounds | Planner ✓ · Artist ✓ · Variants ✓ · Publisher ✓ · Recycle ✓ · Repurposer ✓ · Trend radar ✓ · X ✓ · Segments ✓ · **Plan by best segment** | Shipped; steering open |
| **GROWTH** — presence | Own the platforms buyers search | GBP ✓ · Ads hints ✓ · Reviews ✓ (with link) · Partnership radar ✓ · **Track partnership outreach** · **Count reviews won** | Mostly shipped |
| **GUARDIAN** — protect | Honest, quiet, cheap | Suppression ✓ · Deliverability ✓ · Adaptive caps ✓ · Forget/export ✓ · Failover ✓ · Setup health ✓ · Housekeeping ✓ · Archive ✓ · **Restore from a weekly archive** | Shipped; recovery tools open |

---

## 5 · Improvement register — cycle 3 (all PROPOSED until the owner accepts)

### 5.1 Agents, replies and outreach

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| C3-A01 | WhatsApp opt-in record: store when and how each lead agreed to WhatsApp messages (they wrote first, replied to a message, ticked a form) and let campaigns choose "opted-in only" (default on) | `lib/sx_wacampaign.php`, `lib/wa_biz.php`, lead card | Leads carry `wa_optin` with source and date; a campaign skips anyone without it unless the owner switches that off for the campaign; tests green | PROPOSED |
| C3-A02 | Several WhatsApp templates: name templates in settings and pick one per campaign, instead of one `WA_BIZ_TEMPLATE` for everything | `lib/sx_wacampaign.php`, `lib/view_whatsapp.php`, settings | A campaign stores its template; sends use it; tests green | PROPOSED |
| C3-A03 | Win-back and post-sign asks by WhatsApp Business too, inside the 24-hour window, with the same approval click | `lib/view_drafts.php`, `index.php`, `lib/wa_biz.php` | A "Send on WhatsApp Business" button appears when the window is open; STOP and lint apply; tests green | PROPOSED |
| C3-A04 | Re-score on engagement signals, not only research: a proposal opened twice or a reply raises the score and moves the lead up Today | `lib/sx_harvest.php`, `lib/run_agents.php` | Engagement events refresh the score within one run (capped); tests green | PROPOSED |
| C3-A05 | Early stop for the first-email subject experiment, with the same strict rule as social experiments | `lib/sx_learn.php` | A clearly losing subject style stops being used before 20 sends; tests green | PROPOSED |
| C3-A06 | Track partnership outreach: mark a suggestion Sent / Replied / No, and feed the outcome back so the radar learns which kinds of pages answer | `lib/sx_amplify.php`, Plan card | Each suggestion has a status; the next radar call is told which kinds worked; tests green | PROPOSED |
| C3-A07 | Count reviews won: when the owner ticks "review posted" on a post-sign lead, show reviews per month on the Results screen and thank the client | `lib/sx_harvest.php`, Results view | A tick records the review; Results shows a monthly count; tests green | PROPOSED |
| C3-A08 | Plan by best segment: when a segment clearly outperforms (3+ scored posts, 1.3x the average), give its topics a larger share of the next plan (bounded, explained in "Why this week's mix") | `lib/sx_planner.php`, `lib/sx_bank.php` | Planner weights seeds by segment performance within a cap; the mix panel says so; tests green | PROPOSED |
| C3-A09 | Directory scout passes: a scout source that reads public business directories and chambers' member lists (names and published contacts only) | `lib/sx_learn.php` scout sources, `lib/agents.php` | A weekly pass adds leads tagged `src: directory` with a source link; honesty and dedupe rules apply; tests green | PROPOSED |

### 5.2 Trust, ops and recovery

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| C3-G01 | Restore from a weekly archive: list the archives in Settings, download one, and restore a single file with a confirmation (the current file is backed up first) | `lib/sx_ops.php`, `lib/view_settings.php` | A restore puts one file back and keeps the replaced one; refuses unknown names; tests green | PROPOSED |
| C3-G02 | Setup-health test buttons: run the existing mail-login, Facebook and WhatsApp checks from the panel row itself | `lib/sx_ops.php`, `index.php` | Each row with a check has a button that reports ok or the reason; tests green | PROPOSED |
| C3-G03 | Archive search and bulk restore on the Agents screen (by name, city, type) | `lib/view_agents.php`, `lib/sx_ops.php` | Owner can find an archived lead by search and restore several at once; tests green | PROPOSED |
| C3-G04 | Archive the old: also move published social posts older than 18 months (their numbers kept in a summary row) out of `social_posts.json` | `lib/sx_ops.php` | Old posts leave the live file, the scoreboard still counts their summary; restorable; tests green | PROPOSED |

---

## 6 · Done log

### Cycle 2 — shipped 2026-10-10 in `95af670` (register marked done in `90fff3f`)

All 16 rows (C2-A01 … C2-A12, C2-G01 … C2-G04): win-back, post-sign and re-check results in the UI and in Today; re-score after research; follow-up subject arms (and the two arms now really differ); review-before-send for WhatsApp Business answers; per-segment scoreboard; X numbers typed per post; early-stop experiments; WhatsApp campaign drafts behind approval; the weekly partnership radar; per-brand trends; picture re-cuts of winners; Google review link in post-sign asks; setup-health panel; lead archiving; housekeeping; weekly data archive. Decisions: X numbers are typed in, never read from X; an experiment may stop early only with 3+ posts a side, a worse arm at half or less and no overlap; archiving never touches clients or "do not contact" leads; the weekly archive is a zip when php-zip exists, else a `.json.gz` (php-zip is not installed on the dev machine, so only the gzip path ran in tests). Fixes found on the way: `PM_REPLY_INTENTS` was defined in both `engage.php` and `sx_replies.php` (email intents vs comment intents) so whichever loaded second silently broke; the price lint matched the em dash in a "— Company" sign-off; template copies had no `hook`; the Plan strategy panel repeated under every post. Tests: cycle 2 237 + channels 164 + measure 190 + marketing 105. `test_measure` reads copies of real `data/` files, so a few follower/focus checks can differ with live data (identical on the previous commit).

### Cycle 1 — shipped 2026-10-10 in `cb8cf50` (register a8aa216)

24 accepted rows — outcome-weighted scouting, watcher, re-verify, engagement follow-ups, subject A/B, Director weights, win-back, post-sign, unified inbox, research queue, provider failover, planner learning + strategy panel, trend radar, repurposing, custom experiments, proactive drafts, ads hints, X posting, audience segments, shared learnings, WA Business responder, deliverability checks, adaptive caps, forget/export. Skipped by owner: MG-M01, MG-M02.

*When every cycle-3 row is DONE, wipe this file and write cycle 4 from the new inventory.*

---

## 7 · Session rules for Claude / Cline

1. Read this file FIRST in any marketing session. It is the plan; the code is the implementation.
2. Add new gaps only as `PROPOSED` rows. Never implement a row whose status is not `ACCEPTED`.
3. When the owner accepts (explicitly, e.g. "accept C3-A04"): mark `ACCEPTED`, build it, test it, commit, then mark `DONE` with date + commit SHA and move it to the Done log.
4. A change that contradicts §2 (Non-negotiables) is never a valid suggestion.
5. When the register is empty: celebrate, wipe this file, and write the next MARKETING.md from the new inventory.
