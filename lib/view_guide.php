<?php
/**
 * The guide (tab=guide): how the team and the admin use the app, in short steps. Every link is relative (?tab=...), so the guide works on any address
 * the app is put on, and a link opened while signed out comes back to its page after sign-in. The admin's part is shown to admins only.
 */
$isAdmin = pm_user_is_admin();
$hasProposals = !empty(pm_brand_profile($vb)['proposals']);
$a = fn(string $href, string $label): string => '<a href="' . pm_h($href) . '">' . pm_h($label) . '</a>';

$team = [
    'start' => ['Start', [
        'Sign in with the username and password the admin gave you. To sign out, open your name at the top right.',
        'Pick the business in the menu at the top left. Every page then shows that business only.',
        'The top bar has one menu per department. The dot at the top right is the scheduler: green means emails and posts are going out on time.',
        'Nothing reaches a customer until someone presses Send, Approve or Publish.',
    ]],
    'day' => ['Every day', [
        'Open the ' . $a('?tab=home', 'Overview') . '. "Needs you now" lists what is waiting.',
        'Answer replies first: ' . $a('?tab=agents&status=awaiting', 'Sales › Leads › Needs reply') . '.',
        'Send the follow-ups that are due. They are in the Today list on ' . $a('?tab=agents', 'Leads') . '.',
        'Read the new drafts in ' . $a('?tab=agents&status=drafted', 'Draft ready') . ', tick Approve for sending on the good ones (or Select all on this page once you have read them), then press Approve selected. To hold them until a set time, fill in Not before first.',
        'Work the ' . $a('?tab=whatsapp', 'WhatsApp queue') . '.',
        'Approve this week\'s posts in ' . $a('?tab=social', 'Social › Plan') . ' and answer people in ' . $a('?tab=social&view=inbox', 'Social › Inbox') . '.',
        'Add a note to every lead you work on. The admin reads them.',
    ]],
    'leads' => ['Leads', [
        'To add a business, type its name in the Find box and press Find. To find new businesses, press Run the agents.',
        'Leads come 10 to a page. Use Previous and Next under the list. An "open" link to a lead takes you straight to its page.',
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
        $a('?tab=social&view=inbox', 'Inbox') . ': comments and messages, each with a draft answer for you to check. "Answered by" shows who replied. A comment someone already answered, or a conversation answered in the last 30 minutes, is not answered twice.',
        $a('?tab=social&view=channels', 'Channels') . ': posts for places the app cannot post to. Press "I\'ll post this" so nobody else does, publish it there, then press Mark posted.',
        $a('?tab=social&view=ads', 'Ads') . ': paid campaigns. They are created paused and cost nothing until you start them.',
    ]],
    'brand' => ['Brand', [
        $a('?tab=social&view=studio', 'Studio') . ' and ' . $a('?tab=social&view=kit', 'Brand kit') . ': covers and profile pictures.',
        $a('?tab=social&view=optimise', 'Profiles') . ': fix each channel\'s profile, and copy the text over where the app cannot change it.',
        $a('?tab=social&view=voice', 'Voice') . ': what the writers know about the business. Keep it true.',
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
        $a('?tab=settings&section=connections', 'Connections') . ': Facebook, WhatsApp, other platforms and the AI keys. It also shows what the AI has cost in US dollars, today and over 30 days, and the daily spend limit (1 US dollar unless you change it). At the limit the AI stops until the next day.',
        $a('?tab=business&new=1', 'Add a business') . ': any kind of business. Give its name and what it does, plus its website and social pages if it has them. The AI reads them, searches the web for its listings, drafts its profile and who to sell to, and you check everything before it is saved.',
        'After it is created, the AI builds it up: it saves its social pages, draws its profile pictures and covers, suggests free offers, drafts the first posts and looks for its first leads. Follow the steps on its Build-up page (Operations › Businesses › Build-up). Nothing is sent or published until you approve it.',
    ]],
    'audit' => ['Audit', [
        $a('?tab=audit', 'Activity log') . ': every sign-in, failed sign-in, action and page opened, by person. Filter it by period, person or business, or download it as CSV.',
        $a('?tab=audit&section=followups', 'Follow-ups') . ': first messages, follow-ups, on-time rate, replies and wins per day, week or month, per person. Late follow-ups are at the bottom.',
        $a('?tab=audit&section=ai', 'AI auditor') . ': a score, findings and what to check, for any period. Tick the box to get one every Monday.',
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
