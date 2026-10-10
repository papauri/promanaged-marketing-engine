# MARKETING.md — ProManaged Marketing Engine · Agent & Social Growth Register (cycle 2)

> **Working document for the marketing side of the ProManaged marketing engine** (`ProManaged Proposals` repo).
> Claude and Cline read this file FIRST in every marketing session. Cycle 1 shipped 24 improvements (git: `cb8cf50`);
> this file restarts the cycle from the new inventory. When the register below is empty, this file is wiped and cycle 3 begins.

---

## 1 · How this document works

1. **SUGGEST** — any session may add gaps and ideas as `PROPOSED` rows in the register (IDs like `C2-A01`), each with *What / Why / Where / Done-when*.
2. **ACCEPT** — only the owner accepts. Say "accept C2-A01" (or edit the row's status to `ACCEPTED`).
3. **BUILD** — an `ACCEPTED` row is implemented, covered by a test where the harness allows it, committed, and marked `DONE` with date + commit SHA.
4. **WIPE** — when every row in the register is `DONE`, delete this file and write a fresh one for cycle 3 (git preserves history).

Status legend: `PROPOSED` → `ACCEPTED` → `BUILDING` → `DONE`.

A fresh session must be able to resume purely from this file + the code. Never implement a row that is not `ACCEPTED`.

---

## 2 · Non-negotiables (every existing and future agent obeys these)

1. **Human gate** — nothing goes out without the owner: emails need `approved_at`, social posts need `approved` status (unless Auto-publish is explicitly on), WhatsApp needs the owner to press send, X posts publish only from approved posts.
2. **STOP is absolute** — suppression by exact address AND company domain (`pm_suppressed`), permanent "Do not contact" blocks, STOP line in every email, STOP honoured on WhatsApp (`pm_wa_biz_handle`) and reply-email intents.
3. **Honesty** — never invent names, emails, phones, metrics, awards, prices, dates or capabilities. Facts come only from published sources. `PM_AGENT_RULES` is the minimum bar.
4. **Malawi focus** — skip international chains and out-of-market businesses.
5. **Caps & pacing** — warm-up daily send caps that now drift adaptively (`pm_adaptive_send_cap`), per-brand AI token budgets, WhatsApp daily limit (40), one contact per company domain per week, max 2 recycled + 2 repurposed posts/week, send window Mon–Fri 08:00–16:30, 1 email per scheduler pass with 4–8 min jitter, bounce breaker.
6. **Clean copy** — no prices in cold emails, no shouting/spam/phish wording (`pm_outreach_lint`), one plain link at most, honest subjects, lint gates on social drafts and engagement drafts.
7. **Two brands, two voices** — ProManaged IT (Build / Source / Support, business buyers) and Travel Malawi (hosts & travellers). Each keeps its own mailbox, tokens, pricing, numbering, signature.
8. **Cost discipline** — cheapest model that does the job, per-model health quarantine, provider failover (Gemini ↔ Anthropic), scout backpressure when drafts pile up (`pm_backlog_state`).
9. **Optional channels stay off** — X and WhatsApp Business auto-answers do nothing unless their keys exist in `.env`; nothing new may switch a channel on without keys.

---

## 3 · Where we are today (post cycle 1)

### 3.1 The agent swarm (`run_agents.php`, `lib/agents.php`, `lib/sx_learn.php`)

| Agent | Function | Ships |
|---|---|---|
| **Director** | `pm_director_brief` | Daily brief + numeric `focus_weights` per sector; scouts allocate slots proportionally. |
| **Scout** | `pm_agent_scout` | Sector × city rotation, weighted by Director/outcome weights; source rotation (web / directory / social). |
| **Watcher** | `pm_agent_watch` | One call/day per brand: fresh signals (new sites, hiring ads, reopenings). |
| **Contact Finder** | `pm_agent_contact` | Owner/manager name + published contacts; trusted-source gate. |
| **Re-verify** | `pm_agent_reverify` | Periodic pass over stale leads; replaces dead addresses, stamps `contact_checked_at`. |
| **Qualifier** | `pm_agent_qualify` | Scores with past-results context (`pm_learn_qualifier_line`); package + pain. |
| **Writer** | `pm_agent_write` | Email + WhatsApp drafts; mirrors the winning subject style (`pm_email_exp_example`); subject A/B arms assigned. |
| **Follow-up** | `pm_agent_followup` | Engagement-aware (replied / opened / silent get different angles). |
| **Win-back** | `pm_agent_winback` | Fresh angle for lost leads, 60d cooldown, drafts only. |
| **Post-sign** | `pm_agent_postsign` | Testimonial + Google review + referral drafts after a signed deal. |
| **Research** | `pm_agent_research` | Deep dive; auto-queued for follow-up-bound and proposal-bound leads (`pm_research_queue`). |
| **Polish** | `pm_agent_polish` | Proposal wording polish. |
| **Reply** | `pm_agent_reply` | Classifies email + WhatsApp; `needs_human` escalation; `resume_on`. |
| **Learning layer** | `pm_outcome_weights`, `pm_jobg_email_exp`, `pm_jobg_learnings_share`, `pm_jobg_thread_sync` | Outcome weights, subject experiments, cross-brand learnings, Messenger→thread sync. |

### 3.2 Socials (`lib/social*.php`, `lib/sx_*.php`, `lib/sx_boost.php`)

- Planner reads outcome weights + trends + audience mix + cross-brand lessons (`pm_plan_learnings`); "Why this week's mix" panel.
- Trend radar (`pm_jobg_trends`), auto-repurposing (`pm_job_repurpose`, 2/week), custom experiments (`pm_experiment_start_custom`), proactive engagement drafts (`pm_jobg_proactive`), ads refresh/pacing hints (`pm_sx_ads_trend_hints`), X posting (`pm_job_channels_x`, off without keys), audience segments (`pm_pro_audience` + `pm_job_audience_segments`), WhatsApp Business responder (`lib/wa_biz.php` + `wa.php`, off without keys).
- Measure-steer unchanged: snapshots, scoreboard, funnel, ads, weekly report, recycle, GBP, manual channels, brand kit.

### 3.3 Trust and privacy

- Deliverability self-check + bounce trends in Email health; adaptive send caps; **Forget lead** and **Export** buttons; JSON store with locks/backups/restore; token watchdog; rate limits; SSRF guard.

---

## 4 · The multi-swarm blueprint — where cycle 1 landed

✓ = shipped in cycle 1 · **bold** = still proposed (cycle 2 candidates below).

| Swarm | Mission | Agents | Status |
|---|---|---|---|
| **HUNT** — find | Fresh, high-probability businesses | Scout ✓ · Contact Finder ✓ · Research ✓ · Watcher ✓ · **Directory scout passes** · **Web-change watcher refinement** | Mostly shipped |
| **HARVEST** — convert | Leads → replied → proposed → signed | Qualifier ✓ · Writer ✓ · Follow-up ✓ · Win-back ✓ · Post-sign ✓ | Shipped; drafts need UI |
| **REPLY** — respond | Answer everything fast | Reply ✓ · Page Judge ✓ · Thread sync ✓ · WA responder ✓ · **Review-before-send mode** | Shipped; review mode open |
| **BROADCAST** — publish | A presence that compounds | Planner ✓ · Artist ✓ · Variants ✓ · Publisher ✓ · Recycle ✓ · Repurposer ✓ · Trend radar ✓ · X ✓ · **Segment-tagged posts** · **Per-brand trends** | Shipped; measurement gaps |
| **GROWTH** — presence | Own the platforms buyers search | GBP ✓ · Ads hints ✓ · Reviews (post-sign) ✓ · **Partnership radar** · **GBP link in review asks** | Partially shipped |
| **GUARDIAN** — protect | Honest, quiet, cheap | Suppression ✓ · Deliverability ✓ · Adaptive caps ✓ · Forget/export ✓ · Provider failover ✓ · **Setup-health panel** · **Housekeeping job** | Shipped; ops polish open |

---

## 5 · Improvement register — cycle 2

> **Accepted 2026-10-10** by the owner ("implement marketing md"): all 16 rows were accepted and built, and are `DONE` below.

### 5.1 Agents, replies and outreach

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| C2-A01 | Surface the new agents' drafts in the UI: win-back and post-sign drafts with review/send buttons, re-verified contact results, plus daily-plan tasks for all three | `lib/view_agents.php`, `lib/agents.php` `pm_daily_plan` | Owner can review and send win-back/post-sign drafts from the lead card; tasks appear in Today; tests green | DONE 2026-10-10 · 95af670 |
| C2-A02 | Re-qualify when research lands: a fresh score + package once `research` is stored (capped per run) | `run_agents.php` or a `pm_job_requalify` | Researched leads get a refreshed score/package within one run; tests green | DONE 2026-10-10 · 95af670 |
| C2-A03 | Extend subject A/B to follow-up emails (arms stored like `email_arm`, evaluated by `pm_jobg_email_exp`) | `lib/sx_learn.php`, `run_agents.php` | Follow-ups carry an arm; eval covers both first and follow-up sends; tests green | DONE 2026-10-10 · 95af670 |
| C2-A04 | Review-before-send mode for auto-replies: a setting that turns email/WhatsApp auto-answers into drafts the owner approves | `lib/engage.php`, `lib/wa_biz.php`, a settings switch | Draft-only mode works for both channels; default stays auto-with-escalation; tests green | DONE 2026-10-10 · 95af670 |
| C2-A05 | Segment-tagged posts: stamp `audience` on every planned post and break the scoreboard engagement down by segment | `lib/sx_planner.php`, `lib/sx_scorecard.php` | Posts carry the segment they speak to; scoreboard shows per-segment numbers; tests green | DONE 2026-10-10 · 95af670 |
| C2-A06 | X metrics: manual entry (likes, reposts, replies) like other manual channels, shown in Results | `lib/sx_manual.php` or `lib/sx_boost.php` + Results view | Owner can type X numbers per post; they score the post; tests green | DONE 2026-10-10 · 95af670 |
| C2-A07 | WhatsApp outbound campaign drafts: scheduled message batches with the same approval gate, STOP and daily-limit guardrails (only when WA Business is connected) | new module + a screen | Drafts queue for owner approval; sends respect STOP and caps; tests green | DONE 2026-10-10 · 95af670 |
| C2-A08 | Post-sign review asks automatically include the business's Google review link from settings | `lib/sx_learn.php` `pm_agent_postsign` + settings | Review draft carries the configured link; tests green | DONE 2026-10-10 · 95af670 |
| C2-A09 | Early-stop experiments: end a test early when one arm is already clearly worse, before min_posts | `lib/sx_experiments.php` | Clearly-losing arm stops early with a verdict; tests green | DONE 2026-10-10 · 95af670 |
| C2-A10 | Partnership radar: one weekly AI call listing local pages/influencers worth collaborating with (draft-only DM suggestions) | new job in `lib/sx_boost.php` | Weekly suggestions stored and shown in Plan; linted; tests green | DONE 2026-10-10 · 95af670 |
| C2-A11 | Per-brand trend radar: tourism/season trends for Travel Malawi, business trends for ProManaged | `pm_jobg_trends` split per brand | Each brand's planner gets its own trend line; tests green | DONE 2026-10-10 · 95af670 |
| C2-A12 | Repurpose the winning image, not just the text: re-render the top performer's card in other layouts (story/carousel cards) | `lib/sx_boost.php` + `lib/sx_cards.php` | A winner's picture is re-cut into at least one new card layout as a draft; tests green | DONE 2026-10-10 · 95af670 |

### 5.2 Trust, ops and housekeeping

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| C2-G01 | Setup-health panel: one glance at which channels are configured (SMTP, FB, IG, LI, X, WA, IMAP, CRON_KEY, APP_URL) and what to add | `lib/view_settings.php` or a new panel | A panel lists every channel's ready/not-ready state; tests green | DONE 2026-10-10 · 95af670 |
| C2-G02 | Lead archiving: auto-archive leads untouched for 12 months so lists stay fast and clean | `lib/agents.php` + a `pm_job_archiving` | Old leads move to an archive file, recoverable, out of the main lists; tests green | DONE 2026-10-10 · 95af670 |
| C2-G03 | Housekeeping job: clear stale `agent_jobs` files, old rate-limit files and temp uploads | new `pm_jobg_housekeeping` | Old temp files are removed on a schedule, nothing live is touched; tests green | DONE 2026-10-10 · 95af670 |
| C2-G04 | Weekly data archive: zip `data/*.json` (no secrets) to a dated archive with retention | new `pm_jobg_archive` | Weekly archive lands in a safe folder, 8 weeks kept; tests green | DONE 2026-10-10 · 95af670 |

---

## 6 · Done log

### Cycle 2 — shipped 2026-10-10 in `95af670`

All 16 rows (C2-A01 … C2-A12, C2-G01 … C2-G04). Decisions worth knowing: the two subject arms (A statement, B question) now really differ, so the subject experiment compares something (cycle 1 only labelled arms); X numbers are typed in, never read from X; an experiment may stop early only with 3+ posts a side, a worse arm at half or less, and no overlap; archiving never touches clients or "do not contact" leads; the weekly archive is a zip when php-zip exists, else a `.json.gz` (php-zip is not installed on the dev machine, so only the gzip path ran in tests). Fixes found on the way: `PM_REPLY_INTENTS` was defined in both `engage.php` and `sx_replies.php` (email intents vs comment intents), so whichever loaded second silently broke; the price lint matched the em dash in a "— Company" sign-off; template copies had no `hook`; the Plan strategy panel repeated under every post (moved to the once-only `plan_top` slot). Tests: cycle 2 237 + channels 164 + measure 190 + marketing 105. `test_measure` also reads copies of real `data/` files, so a few follower/focus checks can differ with live data (same on the previous commit).

### Cycle 1 — shipped 2026-10-10 in `cb8cf50` (register a8aa216)

24 accepted rows — outcome-weighted scouting, watcher, re-verify, engagement follow-ups, subject A/B, Director weights, win-back, post-sign, unified inbox, research queue, provider failover, planner learning + strategy panel, trend radar, repurposing, custom experiments, proactive drafts, ads hints, X posting, audience segments, shared learnings, WA Business responder, deliverability checks, adaptive caps, forget/export. Skipped by owner: MG-M01, MG-M02. Tests: 105 + 190 + 164, all green.

*When every cycle-2 row is DONE, wipe this file and write cycle 3 from the new inventory.*

---

## 7 · Session rules for Claude / Cline

1. Read this file FIRST in any marketing session. It is the plan; the code is the implementation.
2. Add new gaps only as `PROPOSED` rows. Never implement a row whose status is not `ACCEPTED`.
3. When the owner accepts (explicitly, e.g. "accept C2-A04"): mark `ACCEPTED`, build it, test it, commit, then mark `DONE` with date + commit SHA and move it to the Done log.
4. A change that contradicts §2 (Non-negotiables) is never a valid suggestion.
5. When the register is empty: celebrate, wipe this file, and write the next MARKETING.md from the new inventory.



