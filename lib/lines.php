<?php
/**
 * Line-specific proposal wording. The base template (defaults.php) is written for the industry management system.
 * Each business line below overrides the parts of the proposal that differ: cover, pain points, benefits, what is included,
 * steps, support, terms and emails. Edit them in the Template tab ("Business lines"). Anything not set here falls back to the base.
 *
 * Wording is deliberately free of invented prices, guarantees and timelines: review every commitment before you send one.
 */

function pm_line_names(): array
{
    return ['software' => 'Custom software and web apps', 'web' => 'Business websites', 'hardware' => 'Hardware sourcing', 'support' => 'IT support', 'onboarding' => 'Travel Malawi: stay onboarding'];
}

/** Build the three emails for a line from a few phrases, using the same placeholders as the base emails. */
function pm_line_emails(string $hook, string $offer, string $what): array
{
    $sig = "Kind regards,
{signature}";
    return [
        'pitch' => [
            'subject' => $offer,
            'body' => "Dear {contact},

$hook

With us, {business} gets {gains}.

I have attached a short plan for {business}. Worth 20 minutes to talk it through? Just reply with a time{or_call}.

$sig",
        ],
        'contract' => [
            'subject' => '{business}: your agreement and next steps ({reference})',
            'body' => "Dear {contact},

Thank you for choosing to work with us. Your agreement is attached, reference {reference}.

What you are getting: {gains}.

Next step: {sign_step}

Anything unclear? Reply to this email{or_call} and I will walk you through it.

$sig",
        ],
        'both' => [
            'subject' => '{business}: your plan and agreement ({reference})',
            'body' => "Dear {contact},

$hook

With us, {business} gets {gains}. Attached are your plan and agreement, reference {reference}.

Next step: {sign_step}

Would you like to talk it through first? Reply with a time{or_call}.

$sig",
        ],
    ];
}

function pm_default_lines(): array
{
    $supportTable = [
        ['Critical', 'Something that stops your work completely', 'Same working day', 'As soon as possible'],
        ['High', 'One area is not working properly', 'Within 1 working day', 'Agreed with you'],
        ['Normal', 'A question, a small fault or a change request', 'Within 2 working days', 'Agreed with you'],
    ];
    $commonTerms = [
        ['Confidentiality', 'Each party keeps the other\'s business information confidential, during and after this agreement.'],
        ['Liability', 'Our total liability under this agreement is limited to the fees you paid in the 12 months before the claim. Neither party is liable for indirect loss, such as lost profit, except in cases of fraud or wilful misconduct.'],
        ['Governing law', 'This agreement is governed by the laws of the Republic of Malawi. Disputes are first discussed in good faith, and then referred to the courts of Malawi.'],
    ];

    return [
        'onboarding' => pm_onboarding_line(),
        'software' => [
            'title' => 'Custom Software Proposal',
            'intro' => 'Software built around how your organisation actually works: web apps, internal tools, dashboards and customer portals. We scope it with you first, build it in stages you can see, and support it after launch.',
            'cover_hook' => 'The software you run on should fit your work, not the other way round.',
            'pain_title' => 'Where spreadsheets and off-the-shelf tools cost you',
            'pain_intro' => 'These rarely show up as one big failure. They show up as hours lost, mistakes repeated and decisions made without the full picture.',
            'pain_points' => [
                ['type' => 'all', 'pain' => 'The same information typed in three places', 'cost' => 'Spreadsheets, email and chat each hold part of the truth. Staff spend their time copying between them, and the copies drift apart.', 'fix' => 'One system holds the record. Everyone works from the same figures.'],
                ['type' => 'all', 'pain' => 'Work that depends on one person', 'cost' => 'When the one person who understands the process is away, work slows or stops.', 'fix' => 'The process is built into the software, with roles and clear steps.'],
                ['type' => 'all', 'pain' => 'Software that almost fits', 'cost' => 'Staff build workarounds for what the tool cannot do, and the workarounds become the real process.', 'fix' => 'We build around your real process, scoped and agreed before we start.'],
                ['type' => 'all', 'pain' => 'No clear view of what is happening', 'cost' => 'Answers to simple questions take a day of collecting figures, so decisions wait.', 'fix' => 'Dashboards and reports that show the answer when you ask.'],
                ['type' => 'all', 'pain' => 'Customers and partners who cannot help themselves', 'cost' => 'Every status question, form or request has to go through a person.', 'fix' => 'Secure accounts and portals so they can do it themselves.'],
            ],
            'anchor_note' => 'Compare it with the time your team spends each week working around the problem.',
            'benefits_title' => 'How we work',
            'benefits' => [
                ['Scoped before you commit', 'We write down what will be built, so you know what you are agreeing to.'],
                ['Built in stages you can see', 'You review working software as it grows, not a surprise at the end.'],
                ['Secure by design', 'User accounts, secure logins and permissions set per person.'],
                ['Runs in a browser', 'Nothing to install. Works on the desktops, tablets and phones you already own.'],
                ['Built in Malawi, working across borders', 'Local payment and tax realities understood, with remote working that suits you wherever you are.'],
                ['Support after launch', 'Questions after launch are part of the relationship.'],
            ],
            'included' => [
                ['Discovery and scope', 'We learn how you work today and write down what will be built.'],
                ['Design and build', 'The application, built in stages with your review at each one.'],
                ['Accounts and security', 'User accounts, secure logins and roles.'],
                ['Dashboards and reports', 'The figures you need, when you need them.'],
                ['Hosting and backups', 'Your application kept running and backed up.'],
                ['Training and handover', 'Your team shown how to use it, with a written guide.'],
            ],
            'included_lead' => 'What the build covers. The detailed scope is agreed in writing before work starts.',
            'package_note' => 'Build fee, then monthly hosting and support',
            'steps_lead' => 'Timelines depend on the scope agreed in discovery. We confirm them in writing before you sign.',
            'steps' => [
                ['Discovery', 'First', 'We learn how you work today, who will use the software and what a good result looks like.'],
                ['Scope', 'Before you commit', 'We write down what will be built so that price and expectations match.'],
                ['Design', 'Early', 'Screens and flows for you to review before we build them.'],
                ['Build in stages', 'Main phase', 'You see working software at each stage and give feedback.'],
                ['Launch and training', 'Final', 'We go live with you, show your staff how to use it, and stay close in the first days.'],
            ],
            'steps_note' => 'You give timely feedback, the content and data we need, and a contact person with authority to decide. Delays in receiving these extend the timeline.',
            'support_intro' => 'Support is included in your monthly fee, by phone, WhatsApp and email during working hours.',
            'support' => $supportTable,
            'support_note' => 'The monthly fee covers hosting, backups, security updates and support. New features and changes beyond the agreed scope are quoted before work starts.',
            'extras' => [
                ['name' => 'New features or changes after launch', 'price' => 0, 'period' => 'note', 'note' => 'quoted before work starts'],
                ['name' => 'Extra training session', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
                ['name' => 'Integrations with other systems', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
            ],
            'fees_note' => 'All fees are in {currency_name} and exclude VAT.{fx_line} The monthly fee covers hosting, backups, security updates and support as set out below.',
            'terms' => array_merge([
                ['Scope', 'What will be built is set out in the written scope agreed before work starts. Changes to the scope are quoted and agreed in writing before they are built.'],
                ['Fees and payment', 'The build fee is invoiced on signing: 50% is due before work starts and 50% at launch. The monthly fee starts at launch, is invoiced in advance and is due within 14 days.'],
                ['Your responsibilities', 'You give timely feedback, supply the content and data we need, and name a contact person who can decide. Delays on your side may extend the timeline.'],
                ['Ownership', 'Who owns the custom software and its code is agreed in writing in the scope before work starts. Your own data always belongs to you.'],
                ['Your data', 'All records you enter belong to you. We use them only to provide the service and do not sell or share them. On request we provide an export in standard formats.'],
                ['Hosting and support', 'After launch the monthly fee covers hosting, backups, security updates and support. Either party may end hosting and support with 30 days\' written notice.'],
            ], $commonTerms),
            'emails' => pm_line_emails(
                'Most organisations end up working around their software instead of with it, and the workaround quietly becomes the process.',
                '{business}: software built around how you work',
                'what you need the software to do'
            ),
        ],

        'web' => [
            'title' => 'Business Website Proposal',
            'intro' => 'A professional website for your organisation that loads on a phone, says clearly what you do and makes it easy to get in touch, built so you can keep it up to date yourself.',
            'cover_hook' => 'When someone searches for you, your website is the first thing they judge you by.',
            'pain_title' => 'What a weak or missing website costs you',
            'pain_intro' => 'Customers check you online before they call, visit or book. What they find decides whether they do.',
            'pain_points' => [
                ['type' => 'all', 'pain' => 'Customers cannot find you, or cannot trust what they find', 'cost' => 'No website, or an outdated one, sends enquiries to the business that looks more professional.', 'fix' => 'A clear, current website with your services, contact details and proof of your work.'],
                ['type' => 'all', 'pain' => 'Out-of-date information', 'cost' => 'Wrong prices, old opening hours and closed offers lead to confused customers and wasted calls.', 'fix' => 'A site you can edit yourself in minutes.'],
                ['type' => 'all', 'pain' => 'No easy way to enquire or book', 'cost' => 'People who cannot reach you easily give up and go elsewhere.', 'fix' => 'Enquiry forms, WhatsApp and call buttons that work on a phone.'],
                ['type' => 'all', 'pain' => 'Paying every time something small changes', 'cost' => 'When only the developer can change a sentence, updates are delayed or skipped.', 'fix' => 'We train you to make everyday changes yourself.'],
                ['type' => 'all', 'pain' => 'A site that is slow or broken on phones', 'cost' => 'Most visitors use a phone. A site that does not work well there loses them.', 'fix' => 'Designed for phones first.'],
            ],
            'anchor_note' => 'Compare it with a single customer you lose because they could not find or trust you online.',
            'benefits_title' => 'What you get',
            'benefits' => [
                ['Designed around your brand', 'Your logo, colours, photos and wording.'],
                ['Works on every screen', 'Looks right on a phone, tablet and computer.'],
                ['You can edit it yourself', 'Everyday changes without calling a developer.'],
                ['Easy to contact you', 'Forms, WhatsApp and phone buttons where visitors look for them.'],
                ['Set up properly', 'Your web address, hosting and basic search visibility arranged for you.'],
                ['Support after launch', 'Questions after launch are part of the relationship.'],
            ],
            'included' => [
                ['Design in your branding', 'Your logo, colours and photos.'],
                ['Pages and wording', 'Home, services, about and contact, with wording written with you.'],
                ['Enquiry forms and contact buttons', 'Ways for visitors to reach you from any device.'],
                ['Mobile-friendly build', 'Designed for phones first.'],
                ['Web address and hosting setup', 'Arranged and connected for you.'],
                ['Training', 'A short session so you can edit it yourself.'],
            ],
            'included_lead' => 'What the website covers. Extra pages and features can be added later.',
            'package_note' => 'Website build, then monthly hosting and care',
            'steps_lead' => 'Timelines depend on how quickly we receive your content and feedback. We confirm them with you before you sign.',
            'steps' => [
                ['Brief', 'First', 'We learn what you do, who your customers are and what you want visitors to do.'],
                ['Design', 'Early', 'A design in your branding for you to approve.'],
                ['Build and content', 'Main phase', 'We build the pages and place your wording and photos.'],
                ['Review', 'Before launch', 'You check everything and we make the changes.'],
                ['Launch and training', 'Final', 'We put the site live and show you how to edit it.'],
            ],
            'steps_note' => 'You provide your logo, photos and key information, and give feedback promptly. Delays in receiving these extend the timeline.',
            'support_intro' => 'Support is included in your monthly fee, by phone, WhatsApp and email during working hours.',
            'support' => $supportTable,
            'support_note' => 'The monthly fee covers hosting, backups, security updates and support. New pages and larger changes are quoted before work starts.',
            'extras' => [
                ['name' => 'Extra pages or features', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
                ['name' => 'Photography or copywriting', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
            ],
            'fees_note' => 'All fees are in {currency_name} and exclude VAT.{fx_line} The monthly fee covers hosting, backups, security updates and support as set out below.',
            'terms' => array_merge([
                ['Scope', 'The pages and features are those set out in this proposal. Additional work is quoted and agreed in writing before it starts.'],
                ['Fees and payment', 'The build fee is invoiced on signing: 50% is due before work starts and 50% at launch. The monthly fee starts at launch, is invoiced in advance and is due within 14 days.'],
                ['Your content', 'You confirm you have the right to use the text, images and logos you give us.'],
                ['Your data', 'Information collected through your website belongs to you. We use it only to provide the service and do not sell or share it.'],
                ['Hosting and care', 'The monthly fee covers hosting, backups, security updates and support. Either party may end it with 30 days\' written notice.'],
            ], $commonTerms),
            'emails' => pm_line_emails(
                'When a customer searches for a business like yours, your website decides whether they call you or the next one.',
                '{business}: a website that wins you enquiries',
                'what you want your website to do for you'
            ),
        ],

        'hardware' => [
            'title' => 'Hardware Sourcing Proposal',
            'intro' => 'We find, buy and deliver the computers and equipment you need, including items that are out of stock locally or that international sellers will not ship to you directly, with payment arrangements that work locally.',
            'cover_hook' => 'The right equipment, delivered to you, without the guesswork of buying it abroad.',
            'pain_title' => 'What buying equipment usually costs you',
            'pain_intro' => 'Getting the right device is rarely as simple as ordering it. These are the problems we take off your hands.',
            'pain_points' => [
                ['type' => 'all', 'pain' => 'The item you need is out of stock locally', 'cost' => 'Waiting for local stock delays your work, and substitutes may not fit the job.', 'fix' => 'We source it from suppliers elsewhere and deliver it to you.'],
                ['type' => 'all', 'pain' => 'Overseas sellers will not ship to you', 'cost' => 'Many international shops decline deliveries to Malawi, so the best price or model is out of reach.', 'fix' => 'We buy on your behalf and handle the shipping.'],
                ['type' => 'all', 'pain' => 'Paying international sellers is difficult', 'cost' => 'Cards and transfers that work abroad often do not work for you.', 'fix' => 'Local-friendly payment on international orders.'],
                ['type' => 'all', 'pain' => 'Buying the wrong specification', 'cost' => 'A machine that is too slow wastes your staff\'s time, and one that is too powerful wastes your money.', 'fix' => 'We recommend equipment to match what you actually do.'],
                ['type' => 'all', 'pain' => 'Equipment that arrives but is not ready to use', 'cost' => 'Boxes sit unopened because nobody has time to set them up properly.', 'fix' => 'Optional setup and configuration on delivery.'],
            ],
            'anchor_note' => '',
            'benefits_title' => 'How it works',
            'benefits' => [
                ['Advice that fits your need', 'We recommend equipment for the work you do and your budget.'],
                ['Local and international sourcing', 'From suppliers in Malawi or elsewhere, including items not sold locally.'],
                ['We handle the purchase', 'You do not need an international card or a forwarding address.'],
                ['Delivered to you', 'Shipping and delivery arranged and tracked.'],
                ['Ready to use', 'Optional setup, migration and configuration on delivery.'],
                ['Support afterwards', 'Questions after delivery are part of the relationship.'],
            ],
            'included' => [
                ['Requirements and recommendation', 'We confirm what you need and recommend equipment to match.'],
                ['Sourcing and price comparison', 'Options and prices from suppliers, with a clear quote.'],
                ['Purchase on your behalf', 'We buy once you approve the quote.'],
                ['Shipping and delivery', 'Arranged and tracked until it reaches you.'],
                ['Local-friendly payment', 'You pay us locally for international orders.'],
            ],
            'included_lead' => 'What a sourcing order covers. Each order is quoted item by item before you commit.',
            'package_note' => 'Sourcing fee. The price of the equipment itself is quoted separately',
            'steps_lead' => 'Delivery times depend on the supplier and shipping route. We give you an estimate with each quote.',
            'steps' => [
                ['Requirements', 'First', 'You tell us what you need, how it will be used and your budget.'],
                ['Quote', 'Next', 'We send options and a clear quote for the equipment, our fee and delivery.'],
                ['Order and payment', 'On approval', 'You approve the quote and pay as agreed. We place the order.'],
                ['Shipping', 'In transit', 'We keep you updated until it arrives.'],
                ['Delivery and setup', 'On arrival', 'We hand over the equipment, with setup if you asked for it.'],
            ],
            'steps_note' => 'Prices of equipment can change between quote and order. A quote is held for the period shown on it.',
            'support_intro' => 'After delivery we help with setup questions and with warranty claims.',
            'support' => [
                ['Critical', 'Equipment that arrived damaged or not working', 'Same working day', 'We work with the supplier to put it right'],
                ['High', 'Equipment faults within the warranty period', 'Within 1 working day', 'Depends on the manufacturer or supplier'],
                ['Normal', 'Setup questions and advice', 'Within 2 working days', 'Agreed with you'],
            ],
            'support_note' => 'Manufacturer and supplier warranties apply to the equipment. We help you make a claim.',
            'extras' => [
                ['name' => 'Setup and configuration on delivery', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
                ['name' => 'Data migration from your old device', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
                ['name' => 'Delivery to another town', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
            ],
            'fees_note' => 'All fees are in {currency_name} and exclude VAT.{fx_line} The price of the equipment is quoted separately for each order and is not included in the figures above unless stated.',
            'show_first_year' => false,
            'terms' => array_merge([
                ['Quotes', 'Each order is quoted before we buy. A quote is valid for the period shown on it, because supplier prices and exchange rates change.'],
                ['Payment', 'Payment for the equipment and our fee is due as set out on the quote, before we place the order.'],
                ['Delivery', 'Delivery times are estimates supplied by suppliers and carriers. We keep you informed but cannot guarantee dates outside our control.'],
                ['Duties and taxes', 'Import duties and taxes are included in the quote or stated separately on it.'],
                ['Warranty and returns', 'The manufacturer\'s or supplier\'s warranty and returns policy applies to the equipment. We help you claim under it.'],
                ['Risk and ownership', 'Ownership passes to you when you have paid in full. Risk passes to you on delivery.'],
            ], $commonTerms),
            'emails' => pm_line_emails(
                'Getting the right equipment is often harder than it should be: items out of stock locally, overseas sellers that will not ship here and payments that do not go through.',
                '{business}: the right equipment, delivered to you',
                'the equipment you are looking for'
            ),
        ],

        'support' => [
            'title' => 'IT Support Proposal',
            'intro' => 'Someone to call when technology gets in the way: device setup, email, files, backups and everyday security, with remote sessions where you watch every step on screen.',
            'cover_hook' => 'When something stops working, you should know exactly who to call.',
            'pain_title' => 'What unmanaged IT costs you',
            'pain_intro' => 'Small IT problems rarely cause a single big loss. They cost a little time, often, and a lot when something is lost.',
            'pain_points' => [
                ['type' => 'all', 'pain' => 'Staff losing time to computer problems', 'cost' => 'Slow machines, printer faults and email trouble interrupt work, and people try to fix them without knowing how.', 'fix' => 'A support contact who fixes it, remotely where possible.'],
                ['type' => 'all', 'pain' => 'Lost files and no usable backup', 'cost' => 'One failed drive or deleted folder can mean weeks of work gone.', 'fix' => 'Backups set up and checked, with help to recover what is lost.'],
                ['type' => 'all', 'pain' => 'Weak passwords and email scams', 'cost' => 'One staff member clicking the wrong link can expose accounts, money and customer details.', 'fix' => 'Everyday security set up properly and your staff shown what to watch for.'],
                ['type' => 'all', 'pain' => 'Devices that are slow, old or set up badly', 'cost' => 'Equipment that is not looked after slows everybody down.', 'fix' => 'Setup, cleanup and honest advice on when to replace.'],
                ['type' => 'all', 'pain' => 'No one to call', 'cost' => 'When there is no support, small faults wait until they become big ones.', 'fix' => 'One number and one person who knows your setup.'],
            ],
            'anchor_note' => 'Compare it with the working time your team loses to one bad IT day.',
            'benefits_title' => 'How support works',
            'benefits' => [
                ['Remote sessions you can watch', 'We connect to your screen and you see every step we take.'],
                ['Human help', 'You reach the person who knows your setup, not a ticket queue.'],
                ['Set up properly once', 'Devices, accounts, email and backups configured correctly from the start.'],
                ['Everyday security', 'Passwords, updates and safe habits for your team.'],
                ['Plain language', 'We explain what went wrong and how to avoid it.'],
                ['Part of the relationship', 'Questions after we finish are welcome.'],
            ],
            'included' => [
                ['Remote support sessions', 'Help on your screen, with you watching every step.'],
                ['Device setup and migration', 'New devices configured and your files moved across.'],
                ['Email, accounts and passwords', 'Set up, fixed and secured.'],
                ['Backups and recovery', 'Backups arranged and checked; help recovering lost files.'],
                ['Everyday security', 'Updates, safe settings and guidance for staff.'],
                ['Advice on equipment', 'Honest guidance on what to buy and when to replace.'],
            ],
            'included_lead' => 'What your support plan covers. Work outside this, such as large projects, is quoted before it starts.',
            'package_note' => 'Setup fee, then monthly support',
            'steps_lead' => 'We confirm the timeline with you before you sign.',
            'steps' => [
                ['Assessment', 'First', 'We look at your devices, accounts, email and backups and tell you plainly what we find.'],
                ['Set-up', 'Early', 'We fix what matters most: backups, accounts, updates and security basics.'],
                ['Onboarding', 'Next', 'We show your team how to reach us and what to do when something goes wrong.'],
                ['Ongoing support', 'Monthly', 'Help whenever you need it within the plan.'],
                ['Review', 'Regularly', 'We check in on how it is going and what to improve.'],
            ],
            'steps_note' => 'You give us access to the devices and accounts we need and tell us who is allowed to ask for changes.',
            'support_intro' => 'Support is by phone, WhatsApp, email and remote session during working hours.',
            'support' => $supportTable,
            'support_note' => 'Response times are from when you report the problem during working hours. Projects and on-site work are quoted separately.',
            'extras' => [
                ['name' => 'On-site visit', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
                ['name' => 'Support outside working hours', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
                ['name' => 'New device setup and migration', 'price' => 0, 'period' => 'note', 'note' => 'quoted on request'],
            ],
            'fees_note' => 'All fees are in {currency_name} and exclude VAT.{fx_line} The monthly fee covers support as set out below.',
            'terms' => array_merge([
                ['Services', 'We provide the support described in this proposal during working hours. Work outside it is quoted and agreed in writing before it starts.'],
                ['Fees and payment', 'The setup fee is invoiced on signing. The monthly fee is invoiced in advance on the 1st of each month and is due within 14 days.'],
                ['Access', 'You give us access to the devices and accounts we need and tell us who may ask for changes. We use that access only to provide the service.'],
                ['Your data', 'We treat everything we see on your devices and accounts as confidential and use it only to provide the service.'],
                ['Your responsibilities', 'You keep passwords private, tell us promptly about problems and keep your own internet connection in working order.'],
                ['Term', 'The agreement continues month to month. Either party may end it with 30 days\' written notice.'],
            ], $commonTerms),
            'emails' => pm_line_emails(
                'Most small teams have nobody to call when a computer, an email account or a backup lets them down, and a small problem waits until it becomes a big one.',
                '{business}: IT that just works',
                'where technology gets in the way for your team'
            ),
        ],
    ];
}

/** Which business line a package belongs to: software|web|hardware|support, or '' for the industry management system. */
function pm_line_of_type(string $type): string
{
    return in_array($type, ['software', 'web', 'hardware', 'support', 'onboarding'], true) ? $type : '';
}

/** The template with the business line's wording laid over the base. Safe to call on any template. */
function pm_apply_line(array $tpl, string $type): array
{
    $line = pm_line_of_type($type);
    if ($line === '') {
        return $tpl;
    }
    $over = $tpl['lines'][$line] ?? pm_default_lines()[$line];
    foreach ($over as $k => $v) {
        if ($k === 'emails') {
            foreach ($v as $dk => $row) {
                $tpl['emails'][$dk] = array_replace($tpl['emails'][$dk] ?? [], $row);
            }
        } elseif ($k === 'extras') {
            $tpl['extras'] = $v; // line extras replace the system's add-on modules
        } else {
            $tpl[$k] = $v;
        }
    }
    if ($line === 'onboarding') {
        // Free until you start charging. The proposal's "charge" box (default from Settings) decides which wording is used.
        if (!empty($tpl['charging'])) {
            $tpl['fees_note'] = $over['fees_charging'] ?? $tpl['fees_note'];
            foreach ($tpl['terms'] as $i => $t) {
                if (($t[0] ?? '') === 'Fees') {
                    $tpl['terms'][$i][1] = $over['fees_charging'] ?? $t[1];
                }
            }
            $tpl['free'] = false;
        } else {
            $tpl['free'] = true;
        }
    }
    return $tpl;
}

/** AI-tailored wording for one client (see pm_agent_polish). Laid over the line template last. */
function pm_apply_ai(array $tpl, ?array $ai, string $type): array
{
    if (!$ai) {
        return $tpl;
    }
    if (trim((string)($ai['cover_hook'] ?? '')) !== '') {
        $tpl['cover_hook'] = trim((string)$ai['cover_hook']);
    }
    $pains = [];
    foreach ((array)($ai['pain_points'] ?? []) as $x) {
        if (is_array($x) && !empty($x['pain']) && !empty($x['cost']) && !empty($x['fix'])) {
            $pains[] = ['type' => 'all', 'pain' => (string)$x['pain'], 'cost' => (string)$x['cost'], 'fix' => (string)$x['fix'], 'fact' => (string)($x['fact'] ?? '')];
        }
    }
    if ($pains) {
        $tpl['pain_points'] = $pains;
    }
    $know = array_values(array_filter(array_map('trim', array_map('strval', (array)($ai['what_we_know'] ?? [])))));
    if ($know) {
        $tpl['what_we_know'] = array_slice($know, 0, 4);
    }
    $tpl['compact'] = true; // a tailored proposal is short: the client's facts, their problems, the price
    $gains = array_values(array_filter(array_map('trim', array_map('strval', (array)($ai['gains'] ?? [])))));
    if ($gains) {
        $tpl['gains'] = array_slice($gains, 0, 3);
    }
    if (trim((string)($ai['intro'] ?? '')) !== '') {
        $tpl['intro'] = trim((string)$ai['intro']);
    }
    return $tpl;
}

/* ---------------- Editing helpers: one row per line, columns separated by " | " ---------------- */

const PM_LINE_TEXT = [
    'title' => 'Title', 'intro' => 'Introduction', 'cover_hook' => 'Cover line', 'pain_title' => 'Problems heading', 'pain_intro' => 'Problems introduction',
    'benefits_title' => 'Benefits heading', 'included_lead' => 'Included: introduction', 'package_note' => 'Quotation row note', 'anchor_note' => 'Cost comparison line (blank to hide)',
    'steps_lead' => 'Getting started: introduction', 'steps_note' => 'Getting started: note', 'support_intro' => 'Support introduction', 'support_note' => 'Support note', 'fees_note' => 'Fees note',
];
const PM_LINE_LISTS = [
    'pain_points' => ['Problems (problem | what it costs them | how it stops)', ['pain', 'cost', 'fix']],
    'benefits' => ['Benefits (heading | text)', 2],
    'included' => ['What is included (area | what it covers)', 2],
    'steps' => ['Getting started (step | when | what happens)', 3],
    'support' => ['Support (priority | example | first response | target fix)', 4],
    'extras' => ['Optional extras (name | price | month, once or note | note)', ['name', 'price', 'period', 'note']],
    'terms' => ['Terms (clause | wording)', 2],
];

function pm_rows_to_text(array $rows, array|int $cols): string
{
    $out = [];
    foreach ($rows as $r) {
        $out[] = implode(' | ', is_array($cols) ? array_map(fn($k) => (string)($r[$k] ?? ''), $cols) : array_map('strval', array_slice(array_values($r), 0, $cols)));
    }
    return implode("\n", $out);
}

function pm_text_to_rows(string $text, array|int $cols): array
{
    $n = is_array($cols) ? count($cols) : $cols;
    $rows = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $parts = array_pad(array_map('trim', explode('|', $line, $n)), $n, '');
        if (is_array($cols)) {
            $row = array_combine($cols, $parts);
            if (isset($row['price'])) {
                $row['price'] = (float)$row['price'];
                $row['period'] = in_array($row['period'], ['month', 'once', 'note'], true) ? $row['period'] : 'note';
            }
            if (array_key_exists('pain', $row)) {
                $row = ['type' => 'all'] + $row;
            }
            $rows[] = $row;
        } else {
            $rows[] = $parts;
        }
    }
    return $rows;
}

/** Travel Malawi: stay onboarding. Every statement here comes from the Travel Malawi platform's own host pages; review before sending. */
function pm_onboarding_line(): array
{
    $sig = "Kind regards,\n{signature}";
    return [
        'title' => 'Stay Onboarding Proposal',
        'intro' => 'Travel Malawi is a direct booking platform connecting travellers with independent lodges, B&Bs, cottages, guest houses and safari camps across Malawi. This proposal explains how your property would be listed, what you keep and what you get.',
        'cover_hook' => 'Be found by travellers, and be booked directly.',
        'pain_title' => 'Where independent stays lose bookings and margin',
        'pain_intro' => 'Most independent stays do the work of hosting and then share the margin, or never get found at all.',
        'pain_points' => [
            ['type' => 'all', 'pain' => 'Commission on every booking', 'cost' => 'When guests book through an agent, a share of every stay goes to the agent, on guests you may have found yourself.', 'fix' => 'Guests book you directly. You keep the rate you set, with no commission taken off the top.'],
            ['type' => 'all', 'pain' => 'Travellers who cannot find you', 'cost' => 'A stay that is easy to miss online is rarely considered, however good it is.', 'fix' => 'A reviewed listing on a platform built for independent stays across Malawi.'],
            ['type' => 'all', 'pain' => 'Enquiries scattered across phone, WhatsApp and email', 'cost' => 'Requests get missed or answered late when they arrive in four places.', 'fix' => 'Requests land in your dashboard and confirmations go out over WhatsApp.'],
            ['type' => 'all', 'pain' => 'Availability that is out of date', 'cost' => 'Rooms promised twice, or shown as free when they are not, cost you goodwill and income.', 'fix' => 'Manage rooms, rates and blocked dates yourself in one place.'],
            ['type' => 'all', 'pain' => 'Paying before you earn', 'cost' => 'Listing fees and monthly charges are paid whether or not bookings come.', 'fix' => 'No listing fee and no monthly charge while onboarding is free.'],
        ],
        'anchor_note' => '',
        'benefits_title' => 'What you get',
        'benefits' => [
            ['You keep your rate', 'No commission taken off the top, no listing fee, no monthly charge while onboarding is free.'],
            ['Guests deal with you directly', 'Guests pay you at the property, in kwacha or dollars.'],
            ['Requests straight to you', 'They arrive in your dashboard, and confirmations go out over WhatsApp.'],
            ['Checked before it goes live', 'We review every listing, so guests can trust what they find.'],
            ['You stay in control', 'Manage your rooms, rates and blocked dates yourself.'],
            ['A guide for new hosts', 'A host onboarding starter pack and guide to get your listing right.'],
        ],
        'included' => [
            ['Your property listing', 'Photos, rooms, rates, amenities and location.'],
            ['Availability and blocked dates', 'You decide what is open and at what rate.'],
            ['Direct guest requests', 'Delivered to your dashboard, with confirmations over WhatsApp.'],
            ['Review before going live', 'Every listing is checked before it goes live.'],
            ['Host onboarding guide', 'A starter pack for getting your listing right.'],
        ],
        'included_lead' => 'What your listing on Travel Malawi includes.',
        'package_note' => 'Onboarding and listing',
        'steps_lead' => 'Most of this is done by you at your own pace. We can help with any step.',
        'steps' => [
            ['Create your host account', 'First', 'Sign up for free. Your login also works for booking trips.'],
            ['Add your property', 'Next', 'Photos, rooms, rates and amenities. We can help you get it right.'],
            ['Review', 'Before going live', 'We check the listing for accuracy and quality.'],
            ['Go live', 'After review', 'Your property is open to travellers.'],
            ['Receive requests', 'From then on', 'Requests arrive in your dashboard and confirmations go out over WhatsApp.'],
        ],
        'steps_note' => 'You are responsible for the accuracy of your listing, rates and availability.',
        'support_intro' => 'If you get stuck, ask us.',
        'support' => [
            ['Normal', 'A question about your listing or dashboard', 'Within 2 working days', 'Agreed with you'],
            ['High', 'Your listing is showing wrong or has stopped working', 'Within 1 working day', 'As soon as possible'],
        ],
        'support_note' => 'Support is by email and WhatsApp during working hours.',
        'extras' => [],
        'fees_note' => 'There is no charge for onboarding or listing at this time. If fees are ever introduced, we will tell you in writing at least 30 days before they apply, and you may remove your listing at any time.',
        'fees_charging' => 'Fees are in {currency_name} and exclude VAT.{fx_line} They are invoiced as set out in the Quotation and are due within 14 days. Any change to fees is notified in writing at least 30 days before it applies.',
        'show_first_year' => false,
        'terms' => [
            ['Fees', 'There is no charge for onboarding or listing at this time. If fees are ever introduced, we will tell you in writing at least 30 days before they apply, and you may remove your listing at any time.'],
            ['Your listing', 'You confirm that the information, rates and photographs you provide are accurate and that you have the right to use them. You keep your availability and rates up to date.'],
            ['Bookings', 'Bookings are made directly between you and your guests. Guests pay you at the property unless you agree otherwise with them.'],
            ['Review', 'Every listing is reviewed before it goes live. We may decline, edit with your agreement, or remove a listing that is inaccurate or misleading.'],
            ['Your data', 'Your property information and your guests\' details are used only to provide the service and are not sold.'],
            ['Leaving', 'You may remove your listing at any time. Either party may end this agreement by written notice.'],
            ['Confidentiality', 'Each party keeps the other\'s business information confidential, during and after this agreement.'],
            ['Governing law', 'This agreement is governed by the laws of the Republic of Malawi. Disputes are first discussed in good faith, and then referred to the courts of Malawi.'],
        ],
        'emails' => [
            'pitch' => [
                'subject' => 'Listing {business} on Travel Malawi',
                'body' => "Hello {contact},\n\nTravel Malawi is a direct booking platform for independent lodges, B&Bs, cottages, guest houses and safari camps across Malawi. Guests book you directly, you keep the rate you set, and requests reach you in your dashboard and over WhatsApp.\n\n{fee_line}\n\nI have attached a short proposal for {business} explaining how onboarding works. If it is of interest, just reply to this email{or_call} and we will help you get listed.\n\n$sig",
            ],
            'contract' => [
                'subject' => '{business}: your Travel Malawi agreement ({reference})',
                'body' => "Hello {contact},\n\nThank you for choosing to list {business} on Travel Malawi. Your agreement is attached, reference {reference}.\n\n{fee_line}\n\nNext step: {sign_step}\n\nIf anything is unclear, reply to this email{or_call}.\n\n$sig",
            ],
            'both' => [
                'subject' => 'Listing {business} on Travel Malawi ({reference})',
                'body' => "Hello {contact},\n\nTravel Malawi is a direct booking platform for independent stays across Malawi. Guests book you directly and you keep the rate you set.\n\n{fee_line}\n\nAttached are our proposal and agreement for {business}, reference {reference}.\n\nNext step: {sign_step}\n\nIf you would like to talk it through first, reply to this email{or_call}.\n\n$sig",
            ],
        ],
    ];
}
