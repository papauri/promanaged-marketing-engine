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
  4. Check that https://promanaged-it.com/proposals/.env shows "Forbidden".

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
  Proposals stay ProManaged IT and Travel Malawi only (they use those price lists and terms). "Hide this business" keeps all its data.
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
