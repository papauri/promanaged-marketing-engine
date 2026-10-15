# ENGINEERING.md — how we build and run our apps

Status: DRAFT for discussion. Nothing here changes code by itself; it changes how we decide, review and ship.

## 1. Why this exists

AI can produce a working demo in a weekend. A demo is the happy path: one user, clean data, nothing failing.
A product is everything else: the second customer, the retried request, the expired token, the restore at 3am,
the auditor's question. Most of the real work, and most of the real risk, sits there.

So our rule is simple:

> **AI writes drafts. People own the result.** Every line that ships has a human who read it, understands it,
> and is on the hook for it.

We are not trying to build everything on the long list of "things a real app needs". We are trying to know
which of them apply to us **now**, do those properly, and write down which ones we are consciously deferring.

## 2. Principles

1. **Design before code.** Data model, permissions and failure behaviour are decided on paper first. The AI
   implements a design; it does not invent one table by table.
2. **Every external call can fail.** Network, Graph API, payment, email, AI model. Decide the behaviour
   (retry, queue, alert, give up) before writing the call.
3. **Everything irreversible needs a human.** Deleting, publishing, charging, messaging customers, spending
   money. Default is draft/paused/approval; autonomy is something a business is switched into, with a limit.
4. **If it isn't observable, it isn't done.** We must be able to see that it ran, that it failed, and what it cost.
5. **If it isn't tested, it's a hope.** Backups, restores, deploys, rollbacks, webhooks: practised, not assumed.
6. **Boring beats clever.** Small files, plain names, one way of doing each thing.
7. **Write down the why.** Code shows what; only people and docs remember why.
8. **Secrets and customer data are radioactive.** Not in the repo, not in chat history, not in prompts, not in logs.

## 3. Definition of Done (every change, no exceptions)

A change is done only when all of these are true:

- [ ] A person other than the author (or the AI) has **read the diff** and can explain it.
- [ ] Server-side validation exists; the form check is a convenience, not the defence.
- [ ] Every failure path has defined behaviour: user-facing message, log line, retry or alert.
- [ ] Anything that writes to an external service is **idempotent** or guarded against double-send.
- [ ] No secret, token or personal data in code, logs, fixtures or the commit.
- [ ] New config is documented (name, purpose, safe example) in `.env.example` and the README.
- [ ] There is at least one automated test for the logic, or a written reason why not.
- [ ] Empty, loading and error states exist for any screen touched.
- [ ] If behaviour changes for users or operators, the changelog/doc is updated.
- [ ] Rollback is known: how do we undo this in under 10 minutes?

## 4. Using AI coding tools safely

- Give the AI a design and constraints; ask it to follow them. Do not ask it to "build the feature" and accept
  whatever shape comes back.
- **Read everything it wrote.** Ask it to explain anything you can't follow. If neither of you can explain it,
  it doesn't ship.
- Never paste secrets into a chat. If one ever was pasted, **rotate it**: treat it as leaked.
- Verify every new package: it exists, it's the one we meant, it's maintained, and we actually need it.
  Prefer what is already in `composer.json`.
- Keep AI agents on **least privilege**: no production credentials, no ability to deploy or delete data by
  themselves. Anything that can't be undone gets a human approval step.
- Put a **spend cap and alert** on every AI/API key. A runaway agent loop already cost us $1,382.59 once.
- Delete dead code the AI leaves behind. Don't let one file keep growing; split it when it gets hard to read.
- Prompt-injection applies to us: any user-supplied text that reaches a model (comments, messages, reviews)
  is untrusted input. A model reading it must not be able to take irreversible actions by itself.

## 5. What we adopt, by stage

Rather than 200 items, four tiers. Each tier assumes the one above it.

### Tier 0 — always (every app, from day one)

- Source control with **small, reviewed changes**; short-lived branches; no direct pushes to main.
- Secrets only in environment/secret storage; `.env.example` committed; `.env` never.
- Backups that run on a schedule **and a restore we have actually tried**.
- Logs that record *who did what and when*, never passwords, tokens or card numbers.
- Server-side validation and output escaping everywhere a user can type; prepared statements for any SQL.
- Dependencies pinned (lockfile) and reviewed when added.
- A README a new person can follow to run the app locally in under an hour.

### Tier 1 — before it touches paying customers

- **Designed data model** (see §6) with migrations that move forward and can roll back.
- Authentication done properly: reset links that expire and work once, session timeout, login rate limits,
  lockouts that don't strand a legitimate owner, a way to kill all sessions for one user.
- Authorization on **every** read and write: user A cannot reach user B's data by editing an ID in a URL.
- **Money as integers** in minor units, with currency; explicit rounding and tax rules.
- **Idempotency keys** on anything that charges, sends or posts; de-duplicate double-clicks.
- Soft deletes for user data; "I deleted it by accident" must be a support ticket, not an incident.
- Time stored in UTC, displayed in the user's zone; names with apostrophes and non-Latin text tested.
- Background jobs: queue, retry with backoff, dead-letter/visible failure list, and no double-runs of cron.
- Error tracking, uptime check from outside our network, and an alert that reaches a human.
- Deploy pipeline that runs tests; one-step rollback; separate dev/staging/production configs.
- Privacy policy and terms that match what the app actually does.
- Cost alerts on every paid service.

### Tier 2 — before outside customers, integrations or enterprise buyers

- Roles and permissions beyond admin/non-admin; audit trail for admin impersonation.
- Tenant isolation, tested by trying to break it.
- Versioned API; customer-managed API keys that can be revoked; rate limits; webhook signature checks and retries.
- OAuth token refresh and expiry tracking for every integration.
- Dependency scanning, security headers, strict CORS, upload scanning and size limits.
- Encrypted secrets at rest; rotation procedure; stolen-laptop plan.
- Runbooks, on-call owner, postmortems that change something, a status page.
- Load test before any launch push; infrastructure written as code.
- Pen test and a prepared answer pack for security questionnaires.
- Accessibility baseline (keyboard, screen reader, contrast).

### Tier 3 — only when a contract or law requires it

SSO/SAML/SCIM, SOC 2, HIPAA and BAAs, PCI scope, GDPR deletion across backups and logs, data residency,
second region, quarterly access reviews. These are **organisational** work (policies, evidence, auditors),
not just code. We start them only when a deal or regulation puts them on the table, and we budget for them then.

> Deferring is fine. Deferring **silently** is not. Every skipped item is recorded in §9.

## 6. Design before build

For anything that adds a feature, an integration or a table, write a half-page design first:

1. **Problem and outcome** — what changes for whom?
2. **Data model** — entities, owners (which business/tenant), lifecycle (created, archived, deleted), retention.
3. **Permissions** — who can read, write, approve, delete?
4. **Failure behaviour** — what happens when each dependency is down or slow? What is retried, what is alerted?
5. **Cost and limits** — API quotas, AI spend, storage growth.
6. **Rollout and rollback** — feature flag or switch, who turns it on, how we turn it off.
7. **Open questions.**

Record decisions that took real thought as short ADRs in `docs/adr/NNN-title.md`
(context, decision, alternatives, consequences). One page, not a thesis.

## 7. Review and branching

- Trunk plus short branches. One branch, one purpose; open a draft PR early.
- A PR is small enough to read in 15 minutes. If it isn't, split it.
- Reviewer checks, in order: correctness and failure paths → security and data exposure → tests →
  readability → naming/style. "Looks fine" is not a review.
- AI-authored code gets the **same** review as human code, no discount.
- The author explains the *why* in the PR description, plus how it was tested and how to roll back.

## 8. Operating what we run

- **Observe:** structured logs, error tracking that groups duplicates, a few dashboards (errors, latency,
  throughput, queue depth, API usage, spend).
- **Alert:** only on things that need a human; tune any alert that pages for nothing.
- **Practise:** restore a backup, roll back a deploy, rotate a key. Calendar them quarterly.
- **Respond:** every service has a named owner and a runbook; incidents end with a written postmortem and at
  least one change that makes the same incident less likely.
- **Maintain:** monthly dependency update; framework upgrades scheduled before end of security support.
- **Own it:** accounts, domains, repos and keys belong to the company, with at least two people who can
  access them. Bus factor of one is an open risk.

## 9. Where we stand today (this engine) — honest baseline

From a read-only audit of this repository. This is the starting backlog, not blame.

| Area | Today | Target tier |
|---|---|---|
| Automated tests | None (code references stubs such as `PM_TEST`, `PM_GRAPH_STUB`, but no tests exist) | 0 |
| Storage | Flat JSON files under `data/`; no database, so no migrations, indexes or point-in-time recovery | 1 |
| Backups and restore | Backups exist; restore never rehearsed (verify) | 0 |
| Secrets | Plaintext in `.env` (also written by the app); no `.env.example` | 0 / 2 |
| Code structure | `index.php` is a ~174 KB monolith; large `lib/` with mixed concerns | 1 |
| Integrations | Facebook/Instagram use a pasted Explorer token: no OAuth, no refresh for the ads token, no expiry alerts | 1–2 |
| Retries | Graph reads are cached and rate-limited, but failed writes are not retried or queued | 1 |
| Webhooks | Only the WhatsApp webhook; its signature check is optional; Meta sources are polled | 1 |
| Monitoring | Health screen exists; no usage/rate-limit view, no external uptime check, no paging | 1 |
| API version drift | Graph v23.0 from env, but v21.0 hard-coded in places | 1 |
| Docs | README lacks Meta setup and the list of environment variables | 0 |
| Compliance | No privacy policy, data-deletion or deauthorise endpoints | 1–2 |
| Cost controls | Ads have a daily cap; no overall AI/API spend alert (verify) | 1 |
| Approval model | Strong: draft → approved → published; ads created paused | keep |

Deferred on purpose (review when a deal or regulation changes this): SSO/SAML/SCIM, SOC 2, HIPAA/PCI,
data residency, second region.

## 10. First 30 days (suggested order)

1. **Safety net:** `.env.example`, README setup section, token-expiry and scheduler-heartbeat alerts, spend
   alerts, one practised restore.
2. **Test harness:** tests around the Graph wrapper, the publish pipeline and the approval rules, before
   refactoring anything.
3. **Retry/queue for external writes** with a visible failed-jobs list.
4. **Design doc** for the storage question (stay on files with locking and snapshots, or move to a database),
   decided by what the next customers need.
5. **Slice the monolith** gradually, behind the new tests, one area at a time.
6. **Integrations hardening:** real OAuth, webhooks, version pinning, compliance endpoints.

## 11. Ownership

Fill in; an empty row is a risk.

| Area | Owner | Backup |
|---|---|---|
| Architecture / data model | | |
| Security and secrets | | |
| Deploys and infrastructure | | |
| Integrations (Meta, WhatsApp, email) | | |
| On-call / incidents | | |
| Documentation and ADRs | | |

## 12. Living document

Review this file quarterly. When we get surprised by an incident, add what we learned here. If a rule keeps
getting skipped, either the rule is wrong or the process is; fix that, don't ignore it.
