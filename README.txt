PROMANAGED IT — PROPOSALS
=========================

Make a proposal and/or service agreement PDF for a client, email it, and let the
client accept and sign online.

MARKETING PLAN
  MARKETING.md is the working plan for the marketing agents and socials: the gap
  analysis, the multi-swarm blueprint, and the register of proposed → accepted →
  done improvements. Claude and Cline read it first in every marketing session;
  nothing in it is built until the owner accepts it. When the register is empty
  the file is wiped and the cycle starts over.

START ON THIS COMPUTER
  Double-click start.bat  (opens http://127.0.0.1:8085)
  or:  php -S 127.0.0.1:8000 router.php     (always include router.php)

CURRENCIES
  Your price list is kept in USD. On "New proposal", pick the currency to quote
  in (MWK, USD, ZAR, ZMW, EUR, GBP...) and check the rate. Prices convert and
  round automatically (Kwacha to the nearest 1,000).
  Settings > Currencies: update the rates, add currencies, set the default.

SIGNING — three ways
  1. Online (best): the PDF and email carry a "Review and sign" link + QR code.
     The client signs with finger or mouse; a signed PDF with a signature
     certificate is emailed to them and to you, and History shows "Signed".
     Needs the app on your website (see below).
  2. DocuSign / Adobe Acrobat Sign: upload the PDF. Signature and date fields
     are pre-tagged (Adobe text tags; DocuSign field names + \s1\ \d1\ anchors).
  3. In any PDF reader: the Acceptance page has fillable fields.

PUT IT ONLINE (for online signing)
  1. Upload the whole folder to your hosting, e.g. public_html/proposals
     (PHP 8.1 or newer). The .htaccess files keep .env, data/, lib/, vendor/
     and output/ private.
  2. In .env on the server, set:
        APP_URL=https://promanaged-it.com/proposals
        APP_PASSWORD=a-long-password-only-you-know
  3. Open https://promanaged-it.com/proposals and sign in with that password.
     Clients only ever see sign.php with their own private link.
  4. Check that https://promanaged-it.com/proposals/.env shows "Forbidden" (and /data/settings.json too: a host that ignores .htaccess,
     such as nginx, needs the same folders blocked in its own config).

  GITIGNORED, SO NOT IN GIT: upload these by hand the first time (the rest of the folder comes from git)
     .env      create it on the server: copy yours, then set APP_URL, APP_PASSWORD and CRON_KEY. Never upload .env.bak or .env.*
     data/     your leads, settings, prices, posts, proposals and businesses (about 4 MB); keep the folder writable by PHP. Skip *.lock, *.stamp,
               backup/, graph_cache/ and thumbs/ (they are rebuilt)
     output/   past proposal PDFs including signed copies, only if you want them; PHP must be able to write here
     vendor/   do not upload (45 MB): run "composer install --no-dev" on the server; no SSH? upload the folder instead
     assets/   any *signature* picture (assets/signature.png, travel_signature.png) is ignored; logos of businesses you add are uploaded in Settings
  Needs PHP 8.1+ with curl, mbstring, gd with FreeType (the post pictures), openssl, iconv and fileinfo.
  Run the app from ONE place: once the server has your data/, stop using the local copy, or the two drift apart.

  SCHEDULING ON A SERVER (replaces schedule_agents.bat, which is for a Windows PC). cPanel > Cron Jobs, with your own paths:
     */15 * * * *   curl -s "https://promanaged-it.com/proposals/cron.php?key=THE_CRON_KEY" >/dev/null
                    (publishes approved posts, runs campaigns and every social job, starts added businesses' daily runs)
     30 7 * * 1-5   php /home/USER/public_html/proposals/lib/run_agents.php; php /home/USER/public_html/proposals/lib/run_agents.php travel
     */15 * * * *   php /home/USER/public_html/proposals/lib/send_due.php       (sends owner-approved emails, one per run, in working hours)
     */15 * * * *   php /home/USER/public_html/proposals/lib/poll_run.php       (reads replies and bounces)
  CRON_KEY=a-long-random-text goes in .env. If the host blocks exec(), also add one line per added business:  php .../lib/run_agents.php <id>
  The WhatsApp webhook is https://promanaged-it.com/proposals/wa.php. Settings > Setup health shows "Scheduler" turning green once cron runs.

FILES
  .env                 mail server, APP_URL, APP_PASSWORD (never shared, gitignored)
  data/settings.json   company details, currencies, rates
  data/template.json   prices (USD) and all wording
  data/history.json    everything you sent and its status
  data/proposals/      one record per proposal: what was sent, who signed, when
  output/              every PDF, including signed copies
  assets/logo.png      logo   ·   assets/signature.png  your signature (optional)

PROSPECTING AGENTS (Agents tab)
  A swarm of AI agents works in parallel: Scouts search the web for Malawian businesses
  that need the system, Contact Finders look for the owner and a public contact, the
  Qualifier scores each lead and picks the package, the Writer drafts an email and a
  WhatsApp message, the Follow-up agent nudges quiet leads, and the Planner builds
  today's task list. Agents only DRAFT. Nothing is sent until you press Send.
  Setup:  add ANTHROPIC_API_KEY=... and/or GEMINI_API_KEY=... to .env. Gemini uses Google Search grounding (GEMINI_MODEL, default gemini-2.5-flash).
          Mix them: AGENT_WEB_PROVIDER=gemini (Scouts/Contact Finders)  AGENT_TEXT_PROVIDER=anthropic (Qualifier/Writer). One key is enough: AGENT_PROVIDER=gemini.
          Claude model:  (optional: AGENT_MODEL=claude-sonnet-5-5 for lower cost)
  Run:    "Run the agents now" on the Agents tab, or daily via schedule_agents.bat / cron: php lib/run_agents.php
  Every email carries a STOP line; "Do not contact" blocks a business permanently. Daily send limit is in Agent settings.

  Gemini models: the app asks Google each day for the three newest Gemini text models and, on "Auto", spreads work across them
  (falling through to the next one if a model is busy). Pick one model instead under Agent settings.
  Marketing, not spam: one first email per business, one contact per company domain per week, honest subject lines,
  sender identity and address in every email, STOP line plus List-Unsubscribe header, and a pre-send check that blocks
  spam wording, shouting, fake Re:/Fwd:, link shorteners and anything that asks for passwords or payment.
  New packages for Custom Software, Website, Hardware Sourcing and IT Support are in the Template tab with prices at 0: set your prices.

PROPOSALS FOR EVERY BUSINESS LINE
  Packages for Custom Software, Website, Hardware Sourcing and IT Support have their own cover, problems, inclusions, steps, support,
  terms and emails (Template tab > Business lines). Prices are 0 until you set them. Read every commitment and clause before sending.
  "Tailor with AI (pitch perfect)" on the proposal form rewrites the cover line, problems and email for that client using only the
  facts held about them, then a second pass reviews it like a sceptical buyer. Preview the PDF before sending.

REPLIES
  Add IMAP details to .env (IMAP_HOST / IMAP_USER / IMAP_PASS; they default to your SMTP_ ones, port 993). Each run, and the
  "Check replies" button, reads your inbox (read-only, nothing is deleted or marked read), matches replies from businesses you
  have written to, classifies them (interested, question, meeting, not now, not interested, STOP, out of office, bounce) and drafts
  the answer. STOP requests mark the business do-not-contact at once. Setting "Replies" to auto sends only simple, safe answers;
  anything about price, terms, complaints or anything the agent is unsure of waits for you. Replies from WhatsApp can be pasted in.

TRAVEL MALAWI (second brand, stays only)
  Agents tab > "Travel Malawi · stays": its own leads, targets (lodges, guest houses, B&Bs, cottages, safari camps, resorts, hostels),
  daily limits and plan. Everything it sends, drafts or replies is signed Travel Malawi, never ProManaged IT.
  Proposals: the "Stay Onboarding (Travel Malawi)" package makes an onboarding proposal from Travel Malawi, in Travel Malawi's name and colour.
  It says Free while onboarding is free. Tick "Charge for onboarding" on the proposal (or "We are charging for onboarding" in Settings > Travel Malawi)
  when you start charging, and set the package price in the Template tab. Add a logo as assets/travel_logo.png or upload it in Settings.
  Run it: php lib/run_agents.php travel     (schedule_agents.bat runs both brands each morning)

KEEPING AI SPEND LOW
  Default "Economy": the cheapest suitable Gemini model does research, scoring and replies (Flash-Lite, about 1 second, no hidden thinking);
  a stronger model writes only the short customer-facing emails and proposals. A model that hangs or fails is skipped for two hours.
  Every call is counted; Agent settings shows today's tokens and a daily budget (default 400,000, all brands) that stops the agents when reached.
  Typical costs measured: find and score a business ~1,400 tokens; an outreach draft ~650; a tailored proposal ~1,600.
  The agents only search for contacts when a lead has no email, write for leads scoring 60+, and prepare 2 tailored proposals a day (75+ only).
EMAILS AND QUOTATIONS
  Emails are short (about 60 words), specific to the business, gain-led, and never contain prices; the app blocks an email that does.
  Prices live in the proposal. Per proposal you can show exact amounts or "From" prices (the contract then adds a Final price term).
  "Edit the proposal wording" on the proposal form changes what the client reads before it goes out. "Find a business by name" (proposal
  tab and Agents tab, for either brand) researches one named business and prepares its proposal.

TWO MAIL ACCOUNTS
  ProManaged IT sends with SMTP_* in .env; Travel Malawi sends with its own TM_SMTP_* (copied from ProManaged for now, sender name "Travel Malawi").
  When Travel Malawi has its own domain mailbox, edit the TM_SMTP_* lines (and add TM_IMAP_HOST / TM_IMAP_USER / TM_IMAP_PASS if replies
  land in a different mailbox). Settings > Travel Malawi has buttons that log in to each mail server without sending anything.
MARKETING DIRECTOR
  Top of the Agents tab: today's strategy for the selected brand (funnel numbers, signed value, 3 to 5 priorities, what to search more of).
  One small AI call per brand per day; the scouts give half of their daily searches to the Director's focus types.
SERVER
  ensure_server.php starts the app on http://127.0.0.1:8085 if it is not already running (a Claude Code SessionStart hook runs it).

SCREENS
  One look for every screen (assets/app.css) and one loader (assets/app.js): while any task runs a full-page loader shows what is happening
  and pauses every other button, so two tasks cannot be started at once. While the agents search, the Agents screen shows live progress
  and new leads found so far. The web screens and the background agent run can edit leads at the same time without overwriting each other.
  The agents skip businesses listed under existing_clients in the agent settings file (never pitch our own clients as new ProManaged leads).

KWACHA FIRST
  The price list is kept in Kwacha (MWK) and quotes default to MWK. Another currency is one pick on the proposal ("Quote in"), converting at the
  rate shown ("1 USD = 4,200 MWK"); rates are under Settings > Other currencies. An older dollar price list was moved to the same Kwacha amounts.
TWO BRANDS, TWO LOOKS
  The brand switch in the header changes the logo, colour, packages and screens. Travel Malawi uses its own TM mark (assets/travel_logo.png) on
  proposals, emails, the signing page and this app.
OWNER AND MANAGER NAMES
  Scouts and the Contact Finder look for the owner or manager. A name is used in the greeting only when it comes from the business's own website
  or its Facebook or LinkedIn page; any other source shows as "Possible:" for you to confirm. "Find owner names" runs it on leads already in the list.
MARKETING TEAM
  Settings > Marketing team. Then: "Working as" menu in the header, notes signed with the name, "Assign to" on each lead, a "Mine" filter,
  and Call / WhatsApp / Copy buttons on every lead.

WHATSAPP QUEUE (tab "WhatsApp")
  Ready leads (mobile number + drafted message) in score order. "Send on WhatsApp" opens WhatsApp with the message ready (name greeting, sign-off,
  STOP line); you press send there; the app logs it, assigns you, and moves on. Daily limit (default 40) protects the number. Follow-ups appear after
  the follow-up window. Landlines are skipped. Quick replies on the same page. This is click-to-chat; automatic bulk sending needs the Meta WhatsApp Business API.

TWO BUSINESSES, SAME SETUP
  Settings > Travel Malawi has the same sections as ProManaged IT: Company (incl. its own reference prefix and numbering, TM-YYYY-NNN),
  logo, website link, Signing (own signature; online signing and e-sign tags), and Email sending (own mailbox; TM_SMTP_* in .env wins).
  History and the New proposal screen show the business selected in the header.

SOCIAL (tab "Social", per business)
  "Plan posts" writes a week of Facebook posts in one AI call: branded 1080x1080 pictures (drawn by the app), short statuses and phone-video
  scripts. Edit, attach your own photo or video, approve; approved posts publish at their time (every 30 minutes via schedule_agents.bat, and
  whenever the app is open). Shows followers, engagement on recent posts, and comments without a reply, with an AI-drafted answer to post.
  Settings > Social media: connection steps and an Auto-publish switch. Needs FB_PAGE_ID and FB_PAGE_TOKEN (TM_ for Travel Malawi) in .env;
  IG_USER_ID adds Instagram once the app is online (APP_URL), because Instagram fetches pictures from a web address (media.php).
  Leads now record their Facebook/Instagram and "where they lack online" (no website, inactive page, no online booking) for sharper pitches.
  X (Twitter) and WhatsApp Business: see "ONE WHATSAPP NUMBER AND ONE X ACCOUNT PER BUSINESS" below. Without keys they are completely off.

REVIEW BEFORE SEND, CAMPAIGNS, ARCHIVE, HEALTH (cycle 2)
  Agents > More > Agent settings: "Email replies" and "WhatsApp Business answers" can each be "Draft for my approval". A drafted WhatsApp answer
  is sent with "Send on WhatsApp Business" (only inside the 24 hours after they wrote); anything that needs a person always waits for you.
  WhatsApp tab > Campaigns (only with WA_BIZ_* keys): a message plus an audience, drafted by anyone, approved by an approver, then sent a few at a
  time Mon-Fri 08:00-16:30 inside the daily WhatsApp limit. STOP is checked again at every send. Outside the 24-hour window WhatsApp delivers only an
  approved template: set WA_BIZ_TEMPLATE (and WA_BIZ_TEMPLATE_LANG, default en); its {{1}} is filled with the first name.
  Lead cards show the win-back draft, the post-sign asks (testimonial, Google review with the link from Social > Channels, referral) and what a
  contact re-check found; each is sent by you. Researched leads are re-scored once (3 a run). Settings > Setup health lists what is connected.
  Leads untouched for 12 months (never clients or "do not contact") move to data/leads_archive.json, restorable under Agents > More > Archive.
  The scheduler also clears old temp files daily and saves a weekly backup of data/*.json without secrets to data/archive/ (8 weeks kept; a
  .zip when PHP has php-zip, else a .json.gz). Social > Results shows results by audience segment and lets you type X numbers per post.

ANY BUSINESS, NOT JUST TWO (cycle 3)
  The top bar's business menu has "+ Add a business" (also Settings > Add a business). Three questions (name, what it does, who buys) start it;
  more answers (cities, kinds of business to look for, true facts, a free first step, voice, email) make the agents better. One small AI call drafts the
  profile from your answers only (numbers you did not write are dropped); with no AI key you get a plain draft. You check the draft, press Create, and
  the business has its own voice, mailbox, leads, posts, hashtags, partner radar, limits and settings page. Nothing is sent or switched on by creating
  it. Its agents start by themselves each weekday morning from the scheduler (no change to the scheduled task), or press "Run the agents now".
  Its mail login can be typed in its Settings page or put in .env with its own prefix (id in capitals + underscore, e.g. GREENGROCERS_SMTP_HOST).
  Proposals stay ProManaged IT and Travel Malawi only (they use those price lists and terms). "Hide this business" keeps all its data, and Settings >
  "Hidden businesses" brings it back. "Delete this business for good" (its Settings page, or a hidden one in that list) removes everything it owns: its
  leads and archived leads, posts and their pictures, offers, WhatsApp campaigns and templates, strategy, settings, mailbox details, logo and saved files, and
  the other businesses are never touched. You type its name to confirm; a backup of what was removed is kept first in data/deleted/<id>-<date>.json (tick
  box, on by default; nothing is deleted if the backup cannot be written), and it is refused while the agents are running for that business. ProManaged IT
  and Travel Malawi cannot be deleted. Lines in .env that belong to it (e.g. GREENGROCERS_SMTP_HOST) are yours to remove by hand.
  Code rule: never write `$brand === 'travel' ? ...` to mean "the other business"; ask lib/brands.php (pm_brand_ids, pm_brand_name, pm_brand_profile).
  tests/test_brands.php proves a third business never receives another business's words; tests/test_pages.php loads every screen for every business.

THE AI LEARNS THE BUSINESS (lib/brand_study.php)
  When you add a business, give its website and, if you like, paste any text about it. The app reads up to five pages of the site (home, about,
  services, contact; never pricing, special offers, blog or privacy; only public addresses, so nothing inside your network is ever fetched), then ONE AI call
  learns: what the business is and offers; FACTS about it, each carried by a sentence copied from your words or from a page that was really
  fetched (the app checks the sentence is there, and drops any fact with a number nobody wrote or a price); WHO TO TARGET (kinds of buyer best fit first,
  why they buy, signs they need it, who is not a fit, where to start, what to lead with: the AI's advice, with no statistics, for you to edit);
  and up to five questions only you can answer. Answer them and press "Update the draft" (your edits are kept; the site is not read again).
  Facts show where each came from. Email and phone shown on the site fill blanks you left, for you to confirm. Nothing is saved until you press Create.
  What was learned steers the scouts (who they are, why they buy, who to skip) and the qualifier (ideal customers, signs of need) for that business
  only. Settings > the business > "Who to target, and why" edits it, and "Study the business again" reads the site again and shows only what is
  new, with a tick box each: nothing is removed or overwritten. Social > Lead posts > "Suggest offers" turns the business's facts into three offer
  ideas (same rules as an offer you type; with no AI key, the free first step becomes the starting point).

ONE WHATSAPP NUMBER AND ONE X ACCOUNT PER BUSINESS
  Each business uses only its own keys, which never fall back to another business's: ProManaged IT WA_BIZ_*, X_*; Travel Malawi TM_WA_BIZ_*,
  TM_X_*; a business you added <ID>_WA_BIZ_*, <ID>_X_* (ID in capitals). WhatsApp: *_TOKEN, *_PHONE_ID, *_VERIFY (and *_TEMPLATE, *_TEMPLATE_LANG for
  campaigns outside the 24-hour window, *_APP_SECRET to check that calls really come from WhatsApp). X: *_API_KEY, *_API_SECRET, *_ACCESS_TOKEN,
  *_ACCESS_SECRET, then switch X on in that business's Social settings. One webhook address, /wa.php, serves every number: Meta says which number a
  message came to and that decides the business. WhatsApp templates belong to a number, so each business has its own list. Settings > Setup health has
  a WhatsApp and an X row per business that names the keys it needs.
  Someone new who writes to a business's number becomes that business's lead (named as WhatsApp names them, source whatsapp, the offer when they send
  an offer's keyword) and you are told. A keyword gets the owner's own reply words for that offer (no AI); anything else gets an answer drafted for you,
  never sent to a stranger by itself. The owner is told when the AI cannot draft one, and the message is still kept.

THE TRAVEL MALAWI FIRST EMAIL (lib/tm_email.php)
  A first email to a stay is a greeting, ONE personal opening the AI writes for that place (who is writing, and how we came across them: no numbers, prices
  or links allowed), then a standard text that is the same for every stay: what Travel Malawi is, what a host gets, the set-up steps with the link to
  https://ulendomalawi.com, that it is free for now, and why. That text is the owner's own wording in Settings > Travel Malawi > "First email to a stay" (the
  starting text uses only facts the app already holds; the reason for not charging is "we are building the platform together with our first hosts", edit it
  there), with a sample email, its word count and whether it passes the wording rules (one plain link, no prices, no spam words, 20 to 190 words). Leads >
  Travel Malawi shows how many unsent drafts are still in the old format and a "Redraft" button (one AI call per six emails, never sends); drafts that are
  approved, sent or edited by hand are left alone.

FOLLOW-UPS WITH AI, AND TODAY'S STRATEGY (lib/followups.php, lib/director.php)
  A lead we wrote to that goes quiet (default 4 days, at most 3 follow-ups, not snoozed, not left with only a dead email) is "due". The Follow-up agent writes
  one new angle, never a repeat and never pressure, as a DRAFT for you to read and send; nothing here sends anything. It is drafted three ways: by itself in
  the daily agent run; by the scheduler once each working morning (08:00 to 17:00, at most 12 a day, off with the Follow-up switch in the agent settings or
  without an AI key); and on demand on the Leads screen: "Draft with AI" beside a follow-up task, "Draft follow-up with AI" on a lead's card, and "Draft N
  follow-ups with AI" for every due one in a single press.
  Today's strategy (the Director's brief at the top of the Leads screen) shows what is on your desk right now (replies to answer, follow-ups due and how many
  are drafted, drafts to read, approved and waiting) and is written again by itself after each agent run, after you press "Draft N follow-ups", and when that
  work moves (at most every 90 minutes), so it no longer repeats yesterday's advice. "Refresh strategy" still rewrites it at once.

THE FIRM AS DEPARTMENTS: EVERY DEPARTMENT HAS ONE JOB, EVERY SETTING ONE OWNER (lib/ui.php, lib/home.php, lib/view_home.php)
  The app opens on the Agency overview (?tab=home). Every page belongs to one department, and a setting is edited in exactly one place; everywhere else it is a link or a read-only status.
    Sales (win customers)             Leads, WhatsApp (and "Log an enquiry"), Targeting and messages, New proposal, History, Template and prices
    Social (publish, answer people)   Plan (and its Publishing rules: auto-publish, where posts go, when), Lead posts, Content, Channels (hand-posting), Inbox, Page posts, Playbook, Ads
    Brand (look and sound right)      Studio, Brand kit, Profiles (the fixes per channel and the profile words), Voice (what the AI knows), Page clean-up
    Insights (measure and advise)     Results, Audit, Enter numbers
    Operations (keep it running)      Business details, Email, Connections, Health and backups, Team and currencies; "+ Add a business"
  Where things went: Sales > Targeting and messages is the one place for what we sell, who to look for, the daily limits, replies, which agents are on, the first-email wording (Travel Malawi) and,
  for a business you added, who buys and its one-page offer; its roster and diary are there too. Social > Content holds the pillars, the suggested mix, the idea bank, the proof bank, hashtags and
  "bring back what worked". Social > Inbox holds messages, comments, what to hide or delete, autopilot and how replies sound. Brand > Profiles holds every profile fix and the profile words
  (they were in the Studio). Brand > Voice holds what the AI knows (it was in Settings). Insights > Enter numbers holds the numbers you type in (they were under Results). Operations > Connections
  holds the Facebook connection, every platform's link and setup steps, the picture engine and the enquiry form (the old "Accounts & branding": its address still works and leads there).
  The Audit judges and points at the owner of each setting; a finding that can be fixed on the spot has its own button, which calls the same handler the owner's page does.
  One registry, pm_ui_nav(), lists every page once and drives the top bar (an icon and a drop-down per department, the current one lit in its colour), the department and job above each page title,
  the colour of the screen (--dept on <body>) and the tab strip, which shows only the pages of the department you are in. A page that moved is mapped in PM_UI_MOVED. Add a page by adding it to the
  registry. tests/test_departments.php fails when a setting has two editors again (it lists each editor and the one screen that may hold it).
  Saving: each Operations page and each Brand/Sales form sends a hidden _sec and saves only its own part (lib/settings_save.php: business, email, team, voice, messages, social; for a business you
  added: details, email, voice, targeting, social), so a form on one page can never blank a field that lives on another. A form that names no part saves everything, as before.
  The overview shows a greeting, the Director's brief, six numbers, what needs you now, one card per department (state, numbers, team on duty with the reason for anyone off, next steps),
  every business at a glance and the team's diary. It reads stored data only (no AI, no network), so it opens at once. tests/test_home.php.
THE HUMAN VOICE (lib/human.php) AND HAND-MADE LOOKS (lib/sx_studio.php) THROUGHOUT THE MARKETING DEPARTMENT
  Everything a customer reads that an AI helped write goes through two things. (1) PM_HUMAN_RULES, a few lines added to the writers' instructions (first email, follow-up, win-back,
  Travel Malawi, compose, replies, the proposal email, posts, the playbook, profile words): plain everyday words, one specific detail instead of a general claim, and a list of stock
  phrases never to use (delve, leverage, seamless, robust, elevate, unlock, game-changer, "thrilled to announce", "in today's fast-paced world", "not just X, but Y", em dashes ...).
  (2) pm_human_polish(), a free code pass over what comes back: em dashes become commas or full stops (ranges like Mon-Fri and 09:00-17:00 keep their hyphen), stock openers are cut,
  buzzwords swapped for plain ones, exclamation marks removed from emails and kept to one in WhatsApp, emoji removed from emails. Links, numbers, phone numbers and meaning are never
  touched, and polishing twice changes nothing. pm_human_flags() lists what is left; profile words with a remaining flag are dropped.
  The Studio's look follows the same idea: designs are made to look hand-made, not generated. The default is "Paper and ink" in earthy colours (clay, sage, olive, ink, bone, rosewood ...)
  with paper grain and fibre; the other hand-made styles are Cut paper, Terrazzo, Weave, a flat Dusk landscape poster, Lake waves, Minimal and Your photo. The glowing/technical looks
  (Soft glow, Rings, Blueprint, Facets, Diamonds, Spotlight, Ribbons) are tucked under "Digital looks". Colours the AI chooses are softened in code: indigo and violet drift to a muted ink
  blue, magenta to clay, neon is calmed; Surprise me picks hand-made looks three times as often. The Studio also has "Profile words": three options (plain and warm, specific and confident,
  short and memorable) per profile field (Facebook intro and description, Instagram bio, WhatsApp, Google, LinkedIn, X, TikTok, YouTube) in ONE AI call for the channels you tick, kept only
  when they fit the limit, stay in the facts and read naturally; choosing one makes it the suggestion on Optimise and the Audit, and Facebook and WhatsApp can apply it at once.
  "Look for inspiration" opens Pinterest and Behance searches for these looks.

TODAY'S PLAYBOOK (Social > Growth)
  It is brought up to date by itself: opening Growth refreshes a playbook from a past day, the scheduler does it each morning (06:00 to 18:00) even with autopilot off, and a full agent run
  updates it. Same people and leads as before: yesterday's open tasks are carried over (done ones dropped) with no AI call; new comments, messages or warm leads (or a lead quiet for
  another three days) mean one new AI call; a quiet day is still today's playbook; a failed try is not repeated for an hour.

SOCIAL > AUDIT: EVERY FINDING CAN BE ACTED ON (lib/sx_audit_actions.php, lib/view_social_page.php)
  The AI audit (one call) now also labels each fix with a kind (about, description, hours, phone, website, email, cover, picture, posting, replies, messages, cta, formats,
  pin, reviews, timing) and, for the intro and description, offers wording; it names each post by id, gives a one-sentence reply style, and lists ideas. The Audit screen turns
  all of it into actions:
    - "Change the channel settings" (worked out from the Page's own numbers, no AI, and shown even before the audit is run): the weekly posting goal, the hours new posts go out
      (the planner then uses YOUR hours instead of its own; "Use the automatic times" undoes it), the mix of post themes (themes that get more reactions than average get more room,
      never below 5), which channels post by themselves (Instagram linked but off; LinkedIn on but not connected), and how replies sound (added to every reply the AI drafts).
      Each shows what it is now and what is suggested; you can edit the value; "Apply the N safe suggestions" does the safe ones at once.
    - "Fix these first": each fix shows the same card as Optimise (intro, description, hours, phone, website, cover, picture, "fill the calendar") with the AI's own wording filled
      in, but only when that wording passes the facts-only guard (an invented number or boast is not used); replies, Messenger, enquiries and pictures-and-video link to the screen
      that fixes them; a fix with no screen has "Show me how (AI)" (one small call that gives 3 to 5 plain steps).
    - "Post by post": "Rewrite with AI" (one small call, facts only, no new numbers or boasts) gives an editable better version; "Save to Facebook" replaces that post's text
      (the usual Page-edit safety checks apply). "Edit by hand" opens the post.
    - "Ideas for next week": "Add to content ideas" puts the idea in the idea bank so the planner writes a post from it.
  Everything applied is added to the apply log shown on Optimise. Every other channel's profile is on Social > Optimise (the Audit links to it with each channel's score).

SOCIAL > STUDIO: DESIGNED PROFILE PICTURES AND COVERS (lib/sx_studio.php, lib/view_social_studio.php)
  Not generated pictures: every cover and profile picture is DESIGNED BY CODE (GD) from a small design: a style, four colours, a texture and your words (a quiet name
  line, a headline, a supporting line, up to three contact items). Eleven cover styles (Aurora, Dusk over the lake, Lake waves, Rings, Blueprint, Facets, Diamonds,
  Spotlight, Ribbons, Minimal, and Your photo when you have uploaded one), five profile styles (logo on a plate, monogram, glow ring, bold tile, aurora), twelve colour
  sets (three from the business's own colour), and the writing is placed inside the part of each platform's picture that is always shown (the Facebook cover keeps its
  lower left clear for the profile picture; LinkedIn and X keep clear of their avatar; YouTube and Stories centre it). Every size is drawn exactly as the platform wants
  it: Facebook, LinkedIn, X, YouTube, Google, a 1200x630 link preview, a Story/Status background, and profile pictures for every platform. A logo with a see-through
  background, or a logo on a plain tile, is used as a clean white or coloured mark; the monogram (PM, TM ...) is the fallback.
  "Surprise me" re-rolls the style, colours and arrangement in code (no AI). "New idea from the AI" is ONE small call that art-directs a design (style, colours,
  texture, words); code draws it, and any words that add a number, link, boast or claim are not used (the look still is). "Use this design everywhere" saves it as the
  business's design: the branding kit (Accounts & branding), downloads and "Set on Facebook" then all come from it; "Back to the classic cover" undoes it. Earlier
  designs are kept in a strip to bring back. Pictures are cached under data/brandkit/<id>/studio/ (old ones are cleared by themselves).

SOCIAL > OPTIMISE: FIX EVERY CHANNEL FROM ONE SCREEN (lib/sx_optimise.php, lib/view_social_optimise.php)
  One checklist per channel (Business details, Facebook Page, Instagram, WhatsApp Business, Google Business Profile; X, LinkedIn, TikTok and YouTube when you tick
  them under "Channels you use"), scored out of 100, with a page-health score for the whole business that is kept day by day. It is all worked out in CODE, no AI:
  the Page, Instagram and WhatsApp profiles are read, every gap gets a ready suggestion built from the business's own details and facts, and you see it (and can
  edit it) before anything is applied. Three kinds of fix:
    - Applied for you, with one press: Facebook intro, description, phone, website, email, opening hours, profile picture and cover (made from your logo and cover
      wording); the WhatsApp Business about line, description, address, email, website and category; the business details themselves (phone, email, website,
      address, one-line description, opening hours: saved once and used by every channel's suggestion); the Google review link; and "Fill the calendar", which adds
      five draft posts from your facts (no AI, nothing is approved or posted). If a channel refuses (Facebook does not let every Page be edited by an app) you see
      the channel's own reason and the text stays on screen to copy.
    - To copy, open and tick: Instagram bio and link, Google profile (hours, description, services, photos, review requests), X, LinkedIn, TikTok, YouTube bios,
      the WhatsApp greeting and away messages, the Facebook username, location and message button. Those platforms give apps no way to edit a profile.
    - Links to the screen that fixes it: unanswered comments, Messenger conversations waiting, scam comments to decide on, posts that never invite an enquiry.
  "Apply the N ready suggestions" does every complete, safe one at once (it never overwrites a Page phone or website that differs from your business details, never
  uses opening hours you have not confirmed, and says what it did). Opening hours start as a labelled starting point (Monday to Friday 08:00-17:00); hours already on
  the Facebook Page are taken as yours. Optional AI: "Polish the wording" is ONE small call that rewords the text suggestions; a reworded text is kept only if it adds
  no number, link, email, phone, boast or exclamation mark and fits the limit. The scheduler re-checks once a day (reads only) and Today shows one line when the
  score is under 90. A channel that is not connected is not scored; its first item is to connect it.

CONTACT NAMES, EMAILS, WHATSAPP NUMBERS, SUBJECT LINES (lib/contacts.php, lib/subjects.php)
  The Contact Finder now reads the business's OWN website first (home page plus the about, team, owner and contact pages): emails, phone numbers and WhatsApp
  links come straight off the page by code; the owner's or manager's name is taken only when the AI points to a sentence that is really on the page, the name
  is in it and the role is owner/director/manager (never kitchen, restaurant, housekeeping or front-desk staff). A name from the business's own page is
  used in the greeting; a name from anywhere else stays a "possible" name with a "Use <name> as the contact" button. The web search runs only for what the
  site did not give. On a lead's card, "no email" and "no WhatsApp" tags show what is missing, with boxes to type an email or a WhatsApp number (a landline
  is kept as the phone, not WhatsApp) and "Find missing details with AI" for that one lead. Nothing is sent by any of it.
  Subject lines: the writers are told to write a capital letter first and capitals on every town, area, road, person and business name; code then puts
  right what slips through without changing a word (VAT, M1, Area 44, Lake Malawi ...), anything sent is corrected the same way, and the Leads screen offers
  "Fix N subject lines" for drafts already written (code first, then one cheap AI call per 20 for names code does not know; the AI's version is used only
  when every word is unchanged; hand-edited drafts are left alone).

EMAIL WORKS ON YOUR PC BUT NOT FROM THE SERVER
  Settings > the business > "Check the mail login" (also "Test now" in Setup health) logs in without sending, and when it fails says why and what to do:
  it tries the usual ports (465, 587, 25) from the server and tells you which one opens. The commonest cause on a website host is that it BLOCKS outgoing
  mail connections to other mail servers (your own host's mailbox works; one at Namecheap/Google/etc. does not). Fixes, in order: ask the host to allow
  outgoing connections to that mail server; use the port that does open (TM_SMTP_PORT / TM_SMTP_SECURE, 465 = ssl, 587 = tls); use a mailbox on the same
  host; or an email-sending service. "Save and send a test email" sends a plain test as that business through its own mailbox, to the mailbox itself or to
  any address you type beside the button (an outside inbox shows spam-folder trouble), and Setup health says when a business is borrowing another's mailbox.

SUGGESTIONS WHILE YOU TYPE (lib/suggest.php, assets/ui.js)
  The "Find a business" boxes (Leads, and Proposals > find a business) suggest as you type. At once and free: businesses you already know across every
  business in the app (leads, archived leads, businesses you sent a proposal to, existing clients), best match first; a lead of the business on screen
  opens that lead, anything else fills the box (a business an earlier web search found is suggested at once next time). After a short pause, from 3 letters: one web-grounded AI call for real businesses in Malawi with that name
  (only places for Travel Malawi), cached for a week and limited to 60 an hour; those are marked "From a web search: check before you use" and only fill
  the box, because the usual Find step still checks them. Arrow keys, Enter and Escape work. No AI key: only the first part. If it shows nothing on a website,
  open /index.php?names=sun&brand=promanaged while signed in: it should print JSON; a 401 means the session ended (the box says so), and no page change after an
  update means the server still has the old files (update it, then Ctrl+F5).

THE ONE-PAGE OFFER (businesses added in the app)
  They have no proposals or price list, so each has a one-page PDF instead: what it does, what it offers, true facts, a free first step and how to reach
  it, in its colour and logo, made only from its own words and with no prices (a claim or a price is flagged). Settings > the business downloads it; on a
  lead's card "One-page offer" emails it with a message you can change, only to someone who has written to the business, with the same wording rules and
  STOP line as any email.

ONE LOOK FOR EVERY SCREEN (lib/ui.php, assets/ui.js)
  One sticky top bar (business menu, Leads / WhatsApp / Social / Proposals / Settings with drop-downs, scheduler status), one page head and one tab
  style (Social and Proposals), and foldable sections that remember whether you left them open. Screens call pm_ui_head / pm_ui_tabs instead of
  printing their own navigation, so they cannot drift apart again.

LEAD POSTS (Social > Lead posts): free, unlimited, honest
  An OFFER (what you give, the problem it fixes, a button, a comment keyword) becomes ready-to-approve posts from 12 templates at three volumes
  (Calm, Bold, Unhinged). Each post has a picture with a real button, a comment-the-keyword ask, and a link: the offer's landing page when the app
  is online (enquire.php?mode=offer: big buttons and a short form with your own questions) or a tap-to-chat WhatsApp link that already says the
  keyword. Anyone who comments the keyword is judged a buyer by code (no AI), with a ready reply, and joins your leads. Leads from the form carry the
  offer and the post code they came from; Social > Lead posts counts posts, landing visits, leads and keyword comments per offer.
  Everything is a DRAFT in Plan until you approve it, goes out one a day by the normal schedule, and passes the same lint as every post: no invented
  numbers, prices, claims, guarantees or fake deadlines. Unhinged is energy, not licence. Facebook ads are never free: organic posts are; paid reach is
  the Ads screen (campaigns are created paused). Facebook does not let a normal post carry a clickable button, so also set the Page's own action
  button to "Send WhatsApp message".
