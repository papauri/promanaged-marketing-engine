# MARKETING.md — ProManaged Marketing Engine · Agent & Social Growth Register

> **Working document for the marketing side of the ProManaged marketing engine** (`ProManaged Proposals` repo).
> Claude and Cline read this file FIRST in every marketing session. It is the single source of truth for what the
> marketing agents and socials should become. When the register below is empty, this file is wiped and the cycle starts over.

---

## 1 · How this document works

1. **SUGGEST** — any session may add gaps and ideas as `PROPOSED` rows in the register (IDs like `MG-A01`), each with *What / Why / Where / Done-when*.
2. **ACCEPT** — only the owner accepts. Say "accept MG-A01" (or edit the row's status to `ACCEPTED`).
3. **BUILD** — an `ACCEPTED` row is implemented in the codebase, covered by a test where the harness allows it, committed, and marked `DONE` with date + commit SHA.
4. **WIPE** — when every row in the register is `DONE`, delete this file and write a fresh one for the next cycle (git preserves history).

Status legend: `PROPOSED` → `ACCEPTED` → `BUILDING` → `DONE`.

A fresh session must be able to resume purely from this file + the code. Never implement a row that is not `ACCEPTED`.

---

## 2 · Non-negotiables (every existing and future agent obeys these)

These are the engine's hard rules, already enforced in code — new work must never weaken them:

1. **Human gate** — nothing goes out without the owner: emails need `approved_at`, social posts need `approved` status (unless Auto-publish is explicitly on), WhatsApp needs the owner to press send.
2. **STOP is absolute** — suppression by exact address AND company domain (`pm_suppressed`), permanent "Do not contact" blocks, STOP line in every email.
3. **Honesty** — never invent names, emails, phones, metrics, awards, prices, dates or capabilities. Facts come only from published sources. `PM_AGENT_RULES` is the minimum bar.
4. **Malawi focus** — skip international chains and out-of-market businesses.
5. **Caps & pacing** — warm-up daily send caps, per-brand AI token budgets, WhatsApp daily limit (40), one contact per company domain per week, max 2 recycled posts/week, send window Mon–Fri 08:00–16:30, 1 email per scheduler pass with 4–8 min jitter, bounce breaker.
6. **Clean copy** — no prices in cold emails, no shouting/spam/phish wording (`pm_outreach_lint`), one plain link at most, honest subjects.
7. **Two brands, two voices** — ProManaged IT (Build / Source / Support, business buyers) and Travel Malawi (hosts & travellers). Each keeps its own mailbox, tokens, pricing, numbering, signature.
8. **Cost discipline** — cheapest model that does the job, per-model health quarantine, scout backpressure when drafts pile up (`pm_backlog_state`).

---

## 3 · Where we are today (inventory, grounded in the code)

### 3.1 The agent swarm — one daily run (`run_agents.php`, `lib/agents.php`, `lib/engage.php`)

| Agent | Function | What it does today |
|---|---|---|
| **Director** | `pm_director_brief` | One small AI call per brand per day: headline, 3–5 priorities, focus/drop business types (half of the scouts' searches follow them), an experiment pick, `social_focus` + `social_note` for socials. |
| **Scout** | `pm_agent_scout` | Web search (Gemini Search grounding) for sector × city combos on a rotating schedule; skips known names; records evidence, social gaps, need signals, best-fit offering. |
| **Contact Finder** | `pm_agent_contact` | Owner/manager name + published contacts, only for leads that lack them; trusted-source gate; never invents. |
| **Qualifier** | `pm_agent_qualify` | Scores each lead, picks the package, names the pain. Score computed once at intake. |
| **Writer** | `pm_agent_write` | Drafts the first email + WhatsApp message; drafts pass `pm_outreach_lint`, address verification and suppression before approval. |
| **Follow-up** | `pm_agent_followup` | One nudge after the follow-up window; capped by follow-up rules. |
| **Research** | `pm_agent_research` | On-demand deep dive for one lead (used by compose/polish). |
| **Polish** | `pm_agent_polish` | Proposal wording polish. |
| **Reply** | `pm_agent_reply` | Classifies incoming email (IMAP): interested / question / meeting / not_now / stop / bounce…; drafts a reply, sets `needs_human` when unsure, `resume_on` for later. |

Sending is a slow drip (`lib/send_due.php`): one owner-approved email per scheduler pass, Mon–Fri working hours, random 4–8 min spacing, bounce breaker, daily warm-up caps, per-domain-per-week limit, DNS/MX + typo + disposable-domain verification.

### 3.2 Socials (`lib/social*.php` + the `lib/sx_*.php` measure-steer modules)

- **Planner** (`pm_plan_posts`) — a week of posts per brand in one AI call; offline template fallback so the calendar is never empty; cadence + free slots.
- **Artist** (`lib/sx_cards.php`) — GD-drawn branded 1080×1080 cards, layouts, slides, special frames; zero external design tools.
- **Variants** (`lib/sx_variants.php`) — one idea re-written per channel (FB / IG / LinkedIn / WhatsApp / Google) with per-channel length, links, hooks, tags.
- **Publisher** (`pm_social_publish`, `lib/sx_channels_api.php`) — Facebook (image, carousel, story, reel), Instagram (container flow), LinkedIn (API version gate); idempotent — never posts twice; the last guard is lint.
- **Autopilot** (`pm_social_autopilot`, `lib/social_growth.php`) — judges Page comments (buyer / question / complaint / junk), drafts replies within a reply budget, private replies, human escalation.
- **Measure** (`lib/sx_metrics.php`, `sx_scorecard.php`, `sx_report.php`) — 24h/3d/7d snapshots per post, follower series, scoreboard with min-sample guards, pillar/format/time weights, funnel (leads → replied → won → value), ad results + hints, weekly report, KPI panels.
- **Experiments** (`lib/sx_experiments.php`) — fixed menu of A/B tests, alternating arms, verdicts, learnings feed.
- **Recycle** (`lib/sx_recycle.php`) — top 20% evergreen performers come back as fresh drafts (2/week cap).
- **Presence** — Google Business Profile posts + review replies (`lib/sx_gbp.php`), profile optimisation ticks (`lib/sx_profile.php`), brand kit (`lib/social_platforms.php`).
- **WhatsApp** — click-to-chat queue in score order with drafted messages; the owner presses send; no Business API automation.
- **Manual channels** (`lib/sx_manual.php`) — TikTok / X / YouTube handled as shoot/publish task lists (film-day, shot specs, status ideas), not native posting.

### 3.3 Trust, money and ops

- Token budgets (global + per brand) with usage accounting incl. thinking tokens (`lib/agents.php`), model health quarantine, heartbeat + dead-man checks, token watchdog (`lib/sx_core_watchdog.php`), backups + corrupt-file recovery (`lib/store.php`).
- Signed deals → per-lead value (`pm_lead_signed_value`), pipeline stats by source (`pm_pipeline_stats`), social funnel and cost-per-conversation.

---

## 4 · Gap analysis — where the agents and socials must improve

### 4.1 Agent swarm gaps (the daily run)

1. **No outcome-based learning loop.** Qualifier scores once at intake; replies, wins and signed value never feed back into where scouts search or how leads are scored. The funnel data already exists (`pm_social_funnel`, `won_value_by_src`). The swarm should get smarter every week from its own results: weight sectors, cities, sources and offerings by reply rate and signed value.
2. **Scout breadth is one dimension.** Rotation covers sector × city, but every scout does the same kind of web search. Missing: Google Business Profile / Maps pass, Malawi directory pass, Facebook page discovery, and a *web-change watcher* (new websites, hiring ads, "closing down" signals, new pages) that finds businesses the day they become findable.
3. **Contacts decay and nobody re-checks.** The Contact Finder only runs when a lead is missing contacts. Bounced addresses are cleared, but there is no periodic re-verification pass for stale leads — good businesses get dropped because an old address died.
4. **Follow-ups are blind.** One generic nudge after a window. The engine already records proposal opens (`sign.php` view tracking), replies and thread history — follow-ups should be engagement-aware (they opened but didn't reply → different angle; silent → shorter, different channel).
5. **No subject-line experimentation.** Experiments are social-only. Email open/reply outcomes are measured, so subject A/B per segment is cheap and learnable.
6. **Director speaks words, not weights.** `focus`/`drop` are type lists; scouts can only follow types, not intensities. Give the Director machine-readable weights per sector so scout slots allocate proportionally to expected value.
7. **No win-back.** `lost` leads just sit there (a signature can reopen them, `pm_funnel_mark`). A win-back agent should revisit lost/lapsed leads on a long window with a fresh angle — cheapest deals in the pipeline.
8. **No post-sign agents.** After a signed deal there is no testimonial ask, no review request (GBP), no referral ask. These are the highest-converting touches in the whole engine and they are absent.
9. **Reply covers email only.** Facebook inbox has the autopilot; WhatsApp is manual; the three threads never merge. One business can be talking to us on three channels with three different states.
10. **Research is manual-only.** The top leads heading for a follow-up should get an automatic research pass so the writer has fresh facts.
11. **Provider fragility.** Model-level fallback is excellent (Gemini order + health quarantine), but there is no provider-level fallback: a Gemini outage fails the whole scout batch. With both keys present, retry the failed job on Anthropic.

### 4.2 Social gaps

1. **The planner does not learn from outcomes strongly enough.** Scoreboard weights exist (`pm_social_perf_weights`) but the plan prompt should carry them explicitly: more of the pillars/formats/times that won, fewer of what failed — and the plan should explain its choices to the owner.
2. **No trend/event radar.** The Malawi calendar (`pm_malawi_calendar`) is fixed holidays. Missing: local news, sports, weather, fuel/currency moves and tourism seasons that change what businesses care about this week.
3. **Repurposing is thin.** Recycle exists, but a winner is not automatically re-cut as a reel, story, carousel or Google post (only manual conversions like `pm_do_reel_to_carousel`).
4. **Experiments are a fixed menu.** The owner cannot frame their own hypothesis ("long captions vs short", "photo vs card"). The A/B machinery is general — only the menu is closed.
5. **No proactive engagement.** The autopilot only responds to comments on our own Page. Nothing comments on other pages, joins relevant groups, or engages with partner/community content.
6. **Ads are read-only.** Insights + hints + manual entry. Missing: creative refresh when frequency/CPC degrades, budget pacing on outcomes, and any Google Ads awareness (currently Facebook-only).
7. **TikTok / X / YouTube are task lists, not channels.** Fine for video-first platforms — but X supports a simple API; the engine could post natively there and keep TikTok/YouTube manual.
8. **Audience segments exist for travel only** (`pm_classify_audience`). ProManaged should classify buyers (shop, hotel, office, clinic…) and the planner should address segments explicitly.
9. **Cross-brand learning is implicit.** What wins for Travel Malawi never informs ProManaged and vice versa; share proven pillars/hooks/times in the shared learnings file.
10. **WhatsApp is capped at click-to-chat.** The queue is well built; the next step is the WhatsApp Business API for a responder + broadcast with the same STOP/suppression rules (only when Meta approves the business).

### 4.3 Trust, deliverability and money gaps

1. **No self-check of our own deliverability posture** — SPF / DKIM / DMARC of our sending domains are never verified (we check the recipient's MX, never our own auth), and there is no bounce/reply trend panel. Consider a dedicated sending subdomain for cold outreach.
2. **Static caps.** Daily send caps are settings, not adaptive: a healthy week should slowly raise the cap within a ceiling; a bouncy week should lower it before the breaker trips.
3. **Privacy tools.** No "forget this lead" (purge everywhere) and no single-lead/all-data export.
4. **No full-funnel money view.** Social has a funnel; outreach has pipeline stats — but nobody shows cost per signed deal across email, WhatsApp, social and ads in one place, so the Director cannot allocate budget between channels.
5. **Provider failover** (also in 4.1) and **scheduler independence**: the engine currently leans on a Windows PC (`schedule_agents.bat`, `popen` launches). The host-side scheduler path (`cron.php`) should also run the agent swarm so marketing survives the PC being off.

---

## 5 · The multi-swarm blueprint (target architecture)

The single daily run becomes **six swarms**, each with its own agents, cadence, KPIs and guardrails. Scheduler jobs (`pm_job_*`/`pm_jobg_*`) already give us the cadence machinery — swarms are just more of those, grouped and coordinated by the Director.

| Swarm | Mission | Agents (bold = new) | Cadence | KPIs | Guardrails |
|---|---|---|---|---|---|
| **HUNT** — find | A constant supply of fresh, high-probability businesses | Scout · Contact Finder · Research · **Directory Scout** (GBP/Maps, Malawi directories) · **Watcher** (new sites, hiring ads, expiry signals) | Daily, rotating sources | New leads/day, % with contacts, dedupe rate | New-per-day cap, backpressure pause, STOP list always respected |
| **HARVEST** — convert | Turn leads into replied, proposed, signed | Qualifier · Writer · Follow-up · **Win-back** · **Post-sign** (testimonial + review + referral asks) | Daily drip | Reply rate, proposal rate, signed value, cost per signed deal | Approval gate, warm-up caps, engagement-aware follow-ups, one-per-domain-per-week |
| **REPLY** — respond | Answer everything, on every channel, fast | Reply (email) · Page Judge · **Unified inbox thread** (email + FB + WA merged per lead) · **WA Business responder** (when approved) | Every 10–15 min | First-reply minutes, unanswered > 2h, needs_human rate | needs_human always escalates; STOP wins; no links/prices invented |
| **BROADCAST** — publish | A social presence that compounds | Planner · Artist · Variants · Publisher · Recycle · **Repurposer** · **Trend radar** | Weekly plan + hourly publish | Followers, engagement/1k, enquiries, posts/week | Approval unless Auto-publish; lint last guard; 2 recycled/week; outcome-weighted pillars |
| **GROWTH** — presence | Own the platforms where buyers search | GBP optimizer · Ads agent · **Reviews agent** (proactive asks) · **SEO/content agent** (site articles) · **Partner agent** (collabs, groups) | Daily/weekly | GBP views/calls, cost per conversation, review count, site traffic | Honest reviews only; budget ladder respected; no bought reviews |
| **GUARDIAN** — protect | Keep every swarm honest, quiet and cheap | Suppression keeper · **Deliverability watcher** (SPF/DKIM/DMARC, bounce trends) · Lint enforcer · Budget watchdog · **Provider failover** | Every run | Bounce rate, spam complaints, token spend vs budget, tokens working | Non-negotiables in §2 are law; adaptive caps only within ceilings |

**Coordination rules**

1. The **Director** remains the brain: it reads every swarm's KPI panel and writes one short brief per brand per day (focus weights, not just type names — gap 4.1.6).
2. Swarms share the **same leads.json** and the same learnings store — cross-brand and cross-swarm learnings (§4.2.9) land in one file the Director reads.
3. **Token share**: HARVEST and BROADCAST get most of the budget (they touch people and publish); HUNT is capped per new-lead-per-day; GUARDIAN is near-zero-token (checks + alerts).
4. Every new agent ships with a test in `tests/` using the boot harness pattern (temp data + stubs + "real data unchanged").

---

## 6 · Improvement register — cycle 1 · COMPLETE (2026-10-10)

### 6.1 Agents

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| MG-A01 | Outcome-weighted scouting: weekly weights per sector/city/source/offering from reply rate + signed value; scouts and qualifier consume them | `run_agents.php`, `lib/agents.php`, `lib/director.php` | Weights file written weekly; scout slots and qualifier scores use it; tests green | DONE |
| MG-A02 | Source-diverse scouts + web-change watcher (GBP/Maps, Malawi directories, Facebook pages; new sites, hiring ads) | new `lib/sx_*.php` module + `run_agents.php` | Two new source passes + watcher job ship with tests | DONE |
| MG-A03 | Contact re-verification pass for stale leads (re-check decays, not only missing) | `lib/agents.php` `pm_agent_contact` + a scheduler job | Periodic job re-verifies old contacts, capped per run; tests green | DONE |
| MG-A04 | Engagement-aware follow-ups (opened-but-silent vs never-opened get different angles/channels) | `lib/agents.php` `pm_agent_followup`, `lib/send_due.php` | Follow-up draft varies by view/open/reply signals; tests green | DONE |
| MG-A05 | Email subject A/B experiments with learned winners (extend the social experiment machinery) | `lib/sx_experiments.php` + `pm_agent_write` | Subject arms alternate; winner feeds the Writer; tests green | DONE |
| MG-A06 | Director outputs numeric per-sector weights (not just type lists) | `lib/director.php` | Brief carries weights; `pm_scout_targets` allocates slots by weight; tests green | DONE |
| MG-A07 | Win-back agent: revisit lost/lapsed leads on a long window with a fresh angle | new agent + job in `run_agents.php` | Drafts for lost leads appear, approval + lint gates, long cooldown; tests green | DONE |
| MG-A08 | Post-sign agent: testimonial, GBP review and referral asks after a signed deal | new job after `status=signed` | Drafts appear post-sign, human-approved, STOP-respecting; tests green | DONE |
| MG-A09 | Unified inbox: one thread per lead across email + Facebook + WhatsApp | `lib/engage.php`, `lib/sx_inbound.php`, views | Merged thread view per lead; replies land on the channel they came from; tests green | DONE |
| MG-A10 | Auto-research queue: top-N leads get a research pass before follow-ups | `run_agents.php` + `pm_agent_research` | Token-capped research job runs before follow-up phase; tests green | DONE |
| MG-A11 | Provider failover: a failed Gemini job retries on Anthropic when both keys exist | `lib/agents.php` `pm_claude`/`pm_provider`, `agent_worker.php` | Failed provider retries once on the other; tests with stubs green | DONE |

### 6.2 Socials

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| MG-S01 | Planner consumes outcome weights and explains its choices to the owner | `lib/sx_planner.php`, `pm_plan_posts` | Plan prompt carries pillar/format/time weights; UI shows why each post was chosen; tests green | DONE |
| MG-S02 | Trend/event radar: weekly pass on local news, sports, weather, seasons → a trends file the planner reads | new job + `lib/sx_planner.php` | Trends file refreshed weekly; planner uses it; tests green | DONE |
| MG-S03 | Auto-repurposing: winners re-cut as reel/story/carousel/GBP post drafts | `lib/sx_recycle.php` or new module | A winning post produces N repurposed drafts with lint; tests green | DONE |
| MG-S04 | Custom experiments: the owner frames their own hypothesis | `lib/sx_experiments.php` + UI | Owner-defined hypothesis key creates a valid run; tests green | DONE |
| MG-S05 | Proactive engagement: draft comments on other pages / relevant groups | `lib/social_growth.php` autopilot | Draft-only engagement suggestions with caps + honesty rules; tests green | DONE |
| MG-S06 | Ads creative refresh + budget pacing from outcomes | `lib/sx_report.php`, `lib/social_growth.php` | Refresh trigger on degrading CPC/frequency + pacing hints; tests green | DONE |
| MG-S07 | X (Twitter) native posting via API (TikTok/YouTube stay manual) | `lib/sx_channels_api.php` + variant | X posts publish with per-channel variant + lint; tests green | DONE |
| MG-S08 | ProManaged audience segments (shop, hotel, office, clinic…) like travel's classifier | new module + `pm_plan_posts` | Segments classified and used by the planner; tests green | DONE |
| MG-S09 | Cross-brand learnings: one shared learnings file both brands' planners read | `lib/sx_scorecard.php` learnings | Travel ↔ ProManaged share proven pillars/hooks/times; tests green | DONE |
| MG-S10 | WhatsApp Business API responder + broadcast (only when Meta approves; same STOP/suppression rules) | new module, gated | Responder answers with the Reply agent's draft; STOP respected; tests green | DONE |

### 6.3 Trust, deliverability and money

| ID | Suggestion | Where | Done when | Status |
|---|---|---|---|---|
| MG-G01 | Deliverability self-check: SPF/DKIM/DMARC job + bounce/reply trend panel | new job + a panel | Auth check runs weekly; trends visible in UI; tests green | DONE |
| MG-G02 | Adaptive send caps: raise/lower within ceilings from trailing bounce rate | `lib/outbound.php`, `lib/send_due.php` | Caps drift within bounds; tests green | DONE |
| MG-G03 | Privacy tools: "forget this lead" (purge everywhere) + JSON export | `lib/agents.php` + a UI action | Purge removes lead + thread + records everywhere; export works; tests green | DONE |
| MG-M01 | Full-funnel money: cost per signed deal across email, WhatsApp, social, ads | `lib/director.php` + a panel | One panel shows money by channel; Director reads it; tests green | SKIPPED (owner request: deploying to their domain) |
| MG-M02 | Host-side swarm scheduling: `cron.php` can run the agent swarm so marketing survives the PC being off | `cron.php`, `run_agents.php` | Web cron runs agents behind CRON_KEY; documented; tested | SKIPPED (owner request: deploying to their domain) |

---

## 7 · Done log (cycle 1)

All 24 accepted rows built, tested and shipped on 2026-10-10 in one pass:

- **New modules**: `lib/sx_learn.php` (outcome weights, subject experiments, provider failover, watcher / re-verify / win-back / post-sign agents, research queue, inbox sync, deliverability, adaptive caps, privacy, shared learnings), `lib/sx_boost.php` (trends, repurposing, proactive drafts, X posting, audience segments, strategy panel), `lib/wa_biz.php` + `wa.php` (WhatsApp Business responder, off unless configured).
- **Wired in**: `lib/agents.php` (qualifier weights, engagement follow-ups, subject-style writer, weighted `pm_scout_targets`, failover), `lib/director.php` (focus weights), `lib/run_agents.php` (watcher, re-verify, research-queue, win-back, post-sign phases + subject arms), `lib/agent_worker.php` (4 new agents), `lib/sx_planner.php` (plan learnings), `lib/sx_experiments.php` (custom experiments), `lib/sx_report.php` (ad trend hints), `lib/outbound.php` (adaptive caps), `lib/view_outbound.php` (deliverability + bounce trends), `lib/view_agents.php` + `index.php` (forget/export UI), `router.php` (wa.php).
- **Skipped by owner request** (deploying to their domain): MG-M01, MG-M02.
- **Tests**: `tests/test_marketing.php` — 105 assertions, 0 failed. `test_measure.php` 190/0, `test_channels.php` 164/0 unchanged.

*Register empty of open work: per the lifecycle, wipe this file and write cycle 2 from the new inventory.*

---

## 8 · Session rules for Claude / Cline

1. Read this file FIRST in any marketing session. It is the plan; the code is the implementation.
2. Add new gaps only as `PROPOSED` rows. Never implement a row whose status is not `ACCEPTED`.
3. When the owner accepts (explicitly, e.g. "accept MG-A04"): mark `ACCEPTED`, build it, test it, commit, then mark `DONE` with date + commit SHA and move it to the Done log.
4. A change that contradicts §2 (Non-negotiables) is never a valid suggestion.
5. When the register is empty: celebrate, wipe this file, and write the next MARKETING.md from the new inventory.







