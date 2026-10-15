<?php
/**
 * The guide (tab=guide): how the team and the admin use the app, in short steps. Every link is relative (?tab=...), so the guide works on any address
 * the app is put on, and a link opened while signed out comes back to its page after sign-in. The admin's part is shown to admins only.
 */
$isAdmin = pm_user_is_admin();
$hasProposals = !empty(pm_brand_profile($vb)['proposals']);
$a = fn(string $href, string $label): string => '<a href="' . pm_h($href) . '">' . pm_h($label) . '</a>';
$ag = fn(string $d): string => pm_h(function_exists('pm_agent_first') ? pm_agent_first($d) : ucfirst($d)); // the agents by the names the admin gave them

$team = [
    'start' => ['Start', [
        'Sign in with the username and password the admin gave you. To sign out, open your name at the top right.',
        'Pick the business in the menu at the top left. Every page then shows that business only.',
        'The top bar has one menu per department. The dot at the top right is the scheduler: green means emails and posts are going out on time.',
        'Nothing reaches a customer until someone presses Send, Approve or Publish.',
    ]],
    'day' => ['Every day', [
        'Open the ' . $a('?tab=home', 'Overview') . '. "Today\'s board" at the top is put together by ' . $ag('director') . ' with the department heads at the end of the morning run: each head says what they did and what waits for you, ' . $ag('director') . ' checks it against what we really have (the run happened, the Page and mailbox work, nothing stuck in the Outbox) and puts your few things first as buttons. It is checked again every hour. "Needs you now" lists what is waiting by department. Under it is what the AI team decided this morning: ' . $ag('director') . '\'s meeting, the orders, and how the team is talking.',
        'Answer replies first: ' . $a('?tab=agents&status=awaiting', 'Sales › Leads › Needs reply') . '.',
        'Send the follow-ups that are due. They are in the Today list on ' . $a('?tab=agents', 'Leads') . '.',
        'Read the new drafts in ' . $a('?tab=agents&status=drafted', 'Draft ready') . ', tick Approve for sending on the good ones (or Select all on this page once you have read them), then press Approve selected. To hold them until a set time, fill in Not before first.',
        'Work the ' . $a('?tab=whatsapp', 'WhatsApp queue') . '.',
        'Approve this week\'s posts in ' . $a('?tab=social', 'Social › Plan') . ' and answer people in ' . $a('?tab=social&view=inbox', 'Social › Inbox') . '.',
        'Reply to the people ' . $ag('social') . ' found asking for what we offer: ' . $a('?tab=agents&status=people', 'Leads › People looking') . '.',
        'Add a note to every lead you work on. The admin reads them.',
    ]],
    'leads' => ['Leads', [
        'To add a business, type its name in the Find box and press Find. To find new businesses, press Run the agents.',
        'The tabs sort the work: Active (something to do now), People looking, Ready to send, Needs reply, No email, Missing details, Draft ready, Follow-ups, Contacted, Replied, Proposal sent, Won and Closed. Filter by town, details, score, folder and how many a page (10 to 200). The list changes without reloading the page.',
        'Tick leads to work on several at once: Send now sends their emails straight away, Move to changes their stage, folders group them, and "Not right? Say why" with Turn down &amp; rewrite sends the drafts back to the writer with your reason. The writer learns from it.',
        $a('?tab=agents&status=people', 'People looking') . ': people ' . $ag('social') . ' found in public posts asking for what we offer. Press Open their post, then Copy the reply, paste it under their post, and press I replied. No app is allowed to answer under other people\'s posts, so this step is yours.',
        'Team sit-down on a lead lets the team talk it over and decide: send it, rewrite it with a new angle, wait, call them or drop them. The decision shows as a "Team:" chip, and the talk is on the lead. The busiest leads get a sit-down every morning by themselves.',
        'Open a lead to see what we know about the business, the conversation and the notes on the left, and the message on the right.',
        'Change the email or WhatsApp text if needed and press Save edits. Send me a test email shows you exactly what they will get.',
        'Send email sends it now. Approve for sending puts it in the queue, and it goes out during the ' . $a('?tab=targeting#sendschedule', 'sending hours') . '.',
        'Whoever sends the first message owns the lead. More › Assign to gives it to someone else, and the Mine filter shows yours.',
        'The chip on each lead shows who last wrote to them and when, for example "Jayne · WhatsApp 2 h ago". If a teammate wrote to them today, the app will not send yours.',
        'If they answered on WhatsApp or another inbox, use More › Paste their reply › Draft my answer.',
        'Keep the status right with More: They replied, Proposal sent, Won, Lost (give the reason) or Do not contact.',
        $a('?tab=agents&status=gaps', 'Missing details') . ' lists leads with no email or WhatsApp number. Fill them in under To: on the card.',
    ]],
    'whatsapp' => ['WhatsApp', [
        'In the ' . $a('?tab=whatsapp', 'WhatsApp queue') . ', press Send on WhatsApp. WhatsApp opens with the message ready. Press send there, and the app records it.',
        'If you sent from your phone without the button, open the lead and press More › I sent a WhatsApp from my phone.',
        'Only message mobile numbers, and stop at the daily limit. Anyone who says STOP is never messaged again.',
        'Someone asked by phone, on WhatsApp or at the door? Record it under ' . $a('?tab=whatsapp', 'WhatsApp › Log an enquiry') . '.',
    ]],
    'followups' => ['Follow-ups', [
        'A lead is due for a follow-up 4 days after our last message, with no reply. It gets 3 follow-ups at most.',
        'Press Draft with AI next to the task, or Draft follow-up with AI on the lead. Read the draft, change it if needed, then press Send follow-up.',
        'Every follow-up is counted for the person who sent it. Late ones show up for the admin.',
    ]],
    'proposals' => $hasProposals ? ['Proposals', [
        'Press Create proposal on the lead, or open ' . $a('?tab=proposal', 'Sales › New proposal') . '.',
        'Choose the package and the currency, preview the PDF, then email it.',
        'The client signs online. ' . $a('?tab=history', 'History') . ' shows when it is opened and signed.',
        'Prices and wording are in ' . $a('?tab=template', 'Template and prices') . '. Read them before you send.',
    ]] : ['One-page offer', [
        'This business has no proposals. It sends a one-page offer instead.',
        'Open the lead and choose One-page offer. You can email it once they have written to you.',
    ]],
    'social' => ['Social', [
        $a('?tab=social', 'Plan') . ': Plan posts writes a week of posts. Read and edit them, add a photo, then press Approve. Approved posts go out at their set time.',
        $a('?tab=social&view=leadposts', 'Lead posts') . ': turn an offer into posts. People who comment the keyword become leads.',
        $a('?tab=social&view=campaign', 'Campaigns') . ': one message becomes a feed post, 3 story frames for Facebook and Instagram, a carousel, a WhatsApp Status pack, a WhatsApp broadcast with a picture, and a flyer (picture, A4 and A5 PDF with a QR code). Check each item, change the words if needed, then press Approve all. The feed post and the stories go out together, the carousel the next day.',
        'Campaign parts and channels: tick what to make (feed post, stories, carousel, WhatsApp Status, WhatsApp broadcast, flyer) and where the posts go (Facebook, Instagram, LinkedIn, the hand-post channels). Untick the WhatsApp parts for socials only. Every item tells people exactly what to do: the offer, the word to send and your WhatsApp number (set the number in Business details).',
        'Campaign ideas: the top of Campaigns suggests what to run next (from your offers and their enquiries, What is selling, your free first step, and the AI once a week or when you press Suggest campaigns with the AI). Use this fills in the form. Each campaign\'s page shows What is happening: the steps done, the one thing to do next, when each item goes out, and the enquiries and reactions it brought.',
        'Campaign pictures: the drawn card and your own photo are free, and so is an AI picture made before. A new AI picture costs about 5 US cents. AI pictures show places and moods only, never your real rooms, products or people.',
        $a('?tab=social&view=leads', 'Social leads') . ': everyone who came to us on social media, by channel (Messenger, Facebook comments, Instagram, WhatsApp, public posts, LinkedIn, X; only the channels you have connected are shown) and by stage: answer them, follow up, waiting on them, gone quiet, won, closed. The head of Social drafts each answer and follow-up at her morning huddle and tells Replies and Follow-up what waits; each channel keeps its rules (Messenger and WhatsApp\'s 24 hours, Messenger\'s one follow-up until day 7, one private message to a comment, a public post answered under the post). Copy, open and press "I sent it", or send on Messenger from there; at most two follow-ups, then close them.',
        $a('?tab=social&view=inbox', 'Inbox') . ': comments and messages, each with a draft answer for you to check. "Answered by" shows who replied. A comment someone already answered, or a conversation answered in the last 30 minutes, is not answered twice.',
        $a('?tab=social', 'Plan') . ' starts with ' . $ag('social') . '\'s day. Each morning ' . $ag('social') . ', head of Social, reads our Page\'s comments and messages, adds the buyers to Leads, searches public posts in our towns for people asking for what we offer, meets the department heads and sets the day\'s tasks and what today\'s posts lean on. Press Listen now for a fresh look.',
        $a('?tab=social&view=channels', 'Channels') . ': posts for places the app cannot post to. Press "I\'ll post this" so nobody else does, publish it there, then press Mark posted.',
        $a('?tab=social&view=ads', 'Ads') . ': paid campaigns. They are created paused and cost nothing until you start them.',
        $a('?tab=social&view=gads', 'Google Ads') . ': a complete Google Search campaign written in one AI call from your facts and the words real people used: ad groups, keywords and negatives, 15 headlines and 4 descriptions each, sitelinks, callouts, a snippet, the budget (your daily cap), bidding, towns and hours. Google\'s limits and rules and our facts-only rule are checked in code, and every line can be changed. The app is not connected to Google Ads: download the CSV for Google Ads Editor (Account › Import › From file) or paste it in. Clicks go to our enquiry page, so each enquiry becomes a lead marked Google Ads; save the Google tag ID and conversion label and each enquiry is reported to Google as a conversion. Type each week\'s numbers to see the cost per lead, which the Director reads.',
    ]],
    'brand' => ['Brand', [
        $a('?tab=social&view=studio', 'Studio') . ' and ' . $a('?tab=social&view=kit', 'Brand kit') . ': covers and profile pictures.',
        $a('?tab=social&view=optimise', 'Profiles') . ': fix each channel\'s profile, and copy the text over where the app cannot change it. Every detail the app can change (Facebook intro, description, phone, email, website and hours; the WhatsApp Business profile; the business details) has "Change it", even when it is already good. "Write 3 options" and "Polish the wording" write with the marketing psychology that makes people message (their outcome first, who it is for with proof, an easy next step), from your facts only; when your details change, a note asks you to write them again.',
        $a('?tab=social&view=content#repost', 'Repost with new words') . ' (Social › Content, or "Repost with new words" on a Page post): the same picture or video from any earlier post, ours or on the Page wall, with a punchier caption in your voice (one small AI call on the cheapest model), as a draft at the day and time you choose on the channels you tick. Only channels we have permission to post to are offered. Approve it in the Plan and it goes out then.',
        $a('?tab=social&view=voice', 'Voice') . ': what the writers know about the business. Keep it true.',
    ]],
    'aiteam' => ['The AI team', [
        'The AI agents work like departments: ' . $ag('director') . ' (Director) sets the day and gives orders; ' . $ag('scouts') . ' (Scout) finds businesses; ' . $ag('research') . ' (Researcher) studies them; ' . $ag('contact') . ' (Contact Finder) finds emails, numbers and owner names; '
            . $ag('writers') . ' (Writer) writes the first emails; ' . $ag('followup') . ' (Follow-up) decides when to follow up; ' . $ag('replies') . ' (Replies) answers people who write back; ' . $ag('social') . ' (Social) runs the Page and finds people asking; '
            . $ag('sender') . ' (Sender) sends what you approved; ' . $ag('advisor') . ' (Advisor) shows what is selling.',
        'They report to ' . $ag('director') . ' at a stand-up at the end of the morning run, and that becomes Today\'s board on the Overview. They talk to each other: a reply teaches the Writers how to open, ' . $ag('social') . ' tells the Writers what people ask for, the Director\'s orders reach every department. You see the notes on the ' . $a('?tab=home', 'Overview') . ' and can strike one that is wrong.',
        'Every head has a goal, broken down from the month\'s goal (signed clients and replies, set under Insights › Results): the replies still needed and our reply rate say how many messages go out a working day, and so how many drafts the Writers keep ready, how many leads need an email and a study, and how many new businesses the Scouts bring. Before the morning meeting each head checks its goal; one that is behind asks the head that holds it up (the Sender asks the Writers for drafts, the Writers ask the Contact Finder for emails, the Scouts ask the Director where to search, or you for a setting), and the head asked answers. The ask comes down by itself once the goal is met. The Director orders the heads that are behind first, and Today\'s board shows each head against its goal.',
        'Every email is written to read as a real person\'s: professional, friendly, specific and useful. Code checks each one for anything that could look like spam or phishing (too many links, capitals, pressure words, a nameless greeting) and ' . $ag('sender') . ' raises it at the sit-down, so it is rewritten before you see it.',
        'Nothing reaches a customer until a person presses Send, Approve or Publish. Opening a page never asks the AI, and the daily AI spend has a limit.',
    ]],
    'results' => ['Results', [
        $a('?tab=social&view=results', 'Insights › Results') . ': what brought enquiries and wins.',
        $a('?tab=targeting#advisor', 'Sales › Targeting › What is selling') . ': which kinds of buyer, towns, offers and channels get answers and deals, why deals were lost, and 3 to 5 next moves for the week. A suggested new offer has a Make it an offer button that sends it to Lead posts.',
        "The morning run writes the day's brief on the " . $a('?tab=home', 'Overview') . " and the engagement playbook. Opening a page never asks the AI. To get a fresh one, press Refresh (brief) or Make today's playbook. If nothing has changed, you get the same advice at no cost.",
        $a('?tab=social&view=numbers', 'Enter numbers') . ': type in numbers the app cannot read by itself.',
    ]],
];

$admin = [
    'access' => ['People and access', [
        'Add people under ' . $a('?tab=settings&section=team#access', 'Operations › Team and currencies › Team access') . '. There you set their password and choose which businesses they can open. You can also turn an account off or delete it.',
        'A team member cannot delete leads, add a business, change accounts or open the Audit department.',
        'Send a new person the address of this page. If they are signed out, they land here after they sign in.',
    ]],
    'setup' => ['Settings', [
        $a('?tab=targeting', 'Sales › Targeting and messages') . ': who to look for, the daily limits, the ' . $a('?tab=targeting#sendschedule', 'sending hours') . ' and the ' . $a('?tab=targeting#defaultmsg', 'default first message') . '.',
        $a('?tab=settings&section=business', 'Business details') . ': name, contacts, logo and documents to share.',
        $a('?tab=settings&section=email', 'Email') . ': the mailbox each business sends from. Check the mail login after any change.',
        $a('?tab=settings&section=team#agentnames', 'Your AI team') . ': rename any AI agent (one word, no two alike). The new names show everywhere at once, also in past conversations.',
        $a('?tab=settings&section=connections', 'Connections') . ': Facebook, WhatsApp, other platforms and the AI keys. It also shows what the AI has cost in US dollars, today and over 30 days, and the daily spend limit (1 US dollar unless you change it). At the limit the AI stops until the next day.',
        $a('?tab=business&new=1', 'Add a business') . ': any kind of business. Give its name and what it does, plus its website and social pages if it has them. The AI reads them, searches the web for its listings, drafts its profile and who to sell to, and you check everything before it is saved.',
        'After it is created, the AI builds it up: it saves its social pages, draws its profile pictures and covers, suggests free offers, drafts the first posts and looks for its first leads. Follow the steps on its Build-up page (Operations › Businesses › Build-up). Nothing is sent or published until you approve it.',
    ]],
    'audit' => ['Audit', [
        $a('?tab=audit', 'Activity log') . ': every sign-in, failed sign-in, action and page opened, by person. Filter it by period, person or business, or download it as CSV.',
        $a('?tab=audit&section=followups', 'Follow-ups') . ': first messages, follow-ups, on-time rate, replies and wins per day, week or month, per person. Late follow-ups are at the bottom.',
        $a('?tab=audit&section=ai', 'AI auditor') . ': a score, findings and what to check, for any period. Tick the box to get one every Monday.',
        $a('?tab=audit&section=talk', 'Agent conversations') . ': what the AI agents said to each other and did, day by day, by name: the morning meeting, ' . $ag('social') . '\'s huddle, the sit-downs over leads and who told whom what. Delete a day you do not need.',
    ]],
    'week' => ['Every week', [
        'Monday: read the latest ' . $a('?tab=audit&section=ai', 'audit') . ' and talk through the findings with the team.',
        'Clear the late leads in ' . $a('?tab=audit&section=followups', 'Follow-ups') . ', or reassign them.',
        'Look for red rows in ' . $a('?tab=settings&section=health', 'Health and backups') . '. Fix them the same day.',
        'Check the ' . $a('?tab=template', 'prices') . ' and the ' . $a('?tab=targeting#defaultmsg', 'default first message') . ' are still right.',
        "Read each business's " . $a('?tab=targeting#advisor', 'next moves') . ' and act on one of them.',
        'Glance at the AI cost on ' . $a('?tab=settings&section=connections', 'Connections') . '. A normal week costs a few cents.',
    ]],
];

$sections = $team + ($isAdmin ? $admin : []);
?>
<?= pm_ui_head('Guide', 'How the team works in this app. Each link opens the page it names, for the business you have selected.') ?>
<nav class="filters guide-toc" aria-label="Guide contents">
  <?php foreach ($sections as $id => [$title]): ?><a href="#g-<?= $id ?>"><?= pm_h($title) ?></a><?php endforeach; ?>
</nav>
<div class="guide">
  <?php foreach ($team as $id => [$title, $steps]): ?>
  <section class="card" id="g-<?= $id ?>"><h2><?= pm_h($title) ?></h2><ol class="steps"><?php foreach ($steps as $s): ?><li><?= $s ?></li><?php endforeach; ?></ol></section>
  <?php endforeach; ?>
  <?php if ($isAdmin): ?>
  <h2 class="guide-part">For the admin</h2>
  <?php foreach ($admin as $id => [$title, $steps]): ?>
  <section class="card" id="g-<?= $id ?>"><h2><?= pm_h($title) ?></h2><ol class="steps"><?php foreach ($steps as $s): ?><li><?= $s ?></li><?php endforeach; ?></ol></section>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
