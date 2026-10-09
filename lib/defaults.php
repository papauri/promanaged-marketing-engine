<?php
/**
 * First-run content. Copied to data/*.json the first time the app starts;
 * after that everything is edited in the app (Template and Settings tabs).
 */

function pm_default_settings(): array
{
    return [
        'company_name'    => 'ProManaged IT',
        'tagline'         => 'Business systems, built and supported in Malawi',
        'address'         => 'Malawi',
        'phone'           => '',
        'email'           => 'info@promanaged-it.com',
        'website'         => 'www.promanaged-it.com',
        'signatory_name'  => '',
        'signatory_title' => 'Director',
        'currency'        => 'MWK', // base currency: the price list is kept in Kwacha. Other currencies are an option on each proposal.
        // rate = units of that currency per 1 Kwacha (the Settings screen shows it as "Kwacha per 1 unit"). round = round prices to this step.
        'currencies' => [
            ['code' => 'MWK', 'name' => 'Malawi Kwacha',      'rate' => 1,                'round' => 1000],
            ['code' => 'USD', 'name' => 'US dollars',         'rate' => 1 / 4200,         'round' => 1],
            ['code' => 'ZAR', 'name' => 'South African rand', 'rate' => 14 / 4200,        'round' => 10],
            ['code' => 'ZMW', 'name' => 'Zambian kwacha',     'rate' => 26 / 4200,        'round' => 10],
            ['code' => 'EUR', 'name' => 'euros',              'rate' => 0.92 / 4200,      'round' => 1],
            ['code' => 'GBP', 'name' => 'pounds sterling',    'rate' => 0.78 / 4200,      'round' => 1],
        ],
        'default_quote_currency' => 'MWK',
        'rates_date'      => '',
        'esign_tags'      => true,  // invisible DocuSign / Adobe Sign tags on the signature lines
        'online_signing'  => true,  // "sign online" link + QR code (needs APP_URL in .env)
        'accent_color'    => '#17375E',
        'link_url'        => '',     // website link added to ProManaged emails and WhatsApp messages
        'link_on'         => false,  // off until you switch it on in Settings
        // Travel Malawi: a separate brand that speaks for itself (stays only). Used when a package or lead belongs to it.
        'travel' => [
            'company_name' => 'Travel Malawi', 'tagline' => 'Direct bookings for independent stays across Malawi', 'address' => 'Malawi',
            'phone' => '', 'email' => '', 'website' => '', 'signatory_name' => '', 'signatory_title' => 'Founder',
            'accent_color' => '#047857', 'from_email' => '', // blank = send from the same mailbox, shown as "Travel Malawi"
            'link_url' => 'https://travel-malawi.ai.studio', 'link_on' => true, // shown in Travel Malawi emails and WhatsApp messages
            'charging' => false,  // OFF while onboarding is free. Tick it in Settings (or per proposal) when you start charging.
            'ref_prefix' => 'TM', 'next_ref' => 1,
            'online_signing' => true, 'esign_tags' => true,
            'smtp' => ['host' => '', 'port' => 465, 'encryption' => 'ssl', 'username' => '', 'password' => '', 'from_email' => '', 'from_name' => 'Travel Malawi', 'bcc_self' => true],
        ],
        'ref_prefix'      => 'PM',
        'next_ref'        => 1,
        'smtp' => [
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => '',
            'password'   => '',
            'from_email' => 'info@promanaged-it.com',
            'from_name'  => 'ProManaged IT',
            'bcc_self'   => true,
        ],
    ];
}

function pm_default_template(): array
{
    return [
        'title'      => 'Business Management System',
        'intro'      => 'One system that runs your sales, bookings, stock, staff and accounts, whether you run a hotel, lodge, restaurant, bar, gym, conference venue, shop or supermarket. You pay a single setup fee and a fixed monthly subscription.',
        'valid_days' => 30,
        'cover_hook' => 'Every day a business runs on paper, WhatsApp and memory, money leaves in places nobody is watching.',

        // Pain points. type = all | hotel | restaurant | gym | venue | retail.
        // The PDF shows the ones for the client's business type first, then the general ones.
        'pain_title' => 'Where businesses like yours lose money',
        'pain_intro' => 'None of these show up as a single loss. They leak out a little every day, which is why they are so expensive.',
        'pain_points' => [
            ['type' => 'hotel', 'pain' => 'Paying commission on your own guests', 'cost' => 'Guests who would have booked with you directly find you through an online agent, which keeps around 15% of the stay. You pay it on every booking, every month.', 'fix' => 'Guests book and pay on your own website, commission-free.'],
            ['type' => 'hotel', 'pain' => 'Rooms sold twice, or never sold at all', 'cost' => 'A double booking is an angry guest at reception and a refund. A room shown as taken when it is free is income that never comes back.', 'fix' => 'Live availability: a room is held the moment it is booked and released the moment it is not.'],
            ['type' => 'hotel', 'pain' => 'Extras that never reach the bill', 'cost' => 'Laundry, drinks, a late check-out: small charges noted on paper are forgotten at departure, and the guest is gone.', 'fix' => 'Every charge goes on the guest\'s bill as it happens and must be settled before check-out.'],
            ['type' => 'hotel', 'pain' => 'Guests waiting while you check which rooms are ready', 'cost' => 'Reception cannot see which rooms are clean, so arriving guests wait and first impressions suffer.', 'fix' => 'Housekeeping marks rooms clean and inspected; reception sees it instantly.'],
            ['type' => 'restaurant', 'pain' => 'Drinks poured but never rung up', 'cost' => 'Without stock linked to every sale, a bar cannot tell what was sold from what was given away or taken home.', 'fix' => 'Each sale deducts its ingredients from stock, so counts that do not match show you exactly where to look.'],
            ['type' => 'restaurant', 'pain' => 'Orders lost between the table and the kitchen', 'cost' => 'Handwritten or shouted orders get missed or misheard. Food is remade, tables wait and customers do not come back.', 'fix' => 'Orders go from the till straight to the kitchen and bar screens.'],
            ['type' => 'restaurant', 'pain' => 'Running out in the middle of service', 'cost' => 'The best-selling item runs out on a Friday night because nobody saw stock falling.', 'fix' => 'Low-stock alerts and reorder lists before you run out.'],
            ['type' => 'gym', 'pain' => 'Members training on expired packages', 'cost' => 'Without check-in tied to membership, lapsed members keep coming and their renewals are never collected.', 'fix' => 'Check-in shows straight away whether a membership is active, expiring or expired.'],
            ['type' => 'gym', 'pain' => 'Renewals that rely on memory', 'cost' => 'Nobody calls the member whose package ended last week, so they quietly stop paying.', 'fix' => 'Members, packages and expiry dates in one list you can act on every morning.'],
            ['type' => 'venue', 'pain' => 'Enquiries that go cold', 'cost' => 'A quotation that takes two days to send loses to the venue that replied in two hours.', 'fix' => 'Professional quotations sent in minutes, with every enquiry tracked to a decision.'],
            ['type' => 'venue', 'pain' => 'Deposits and balances chased from memory', 'cost' => 'Events go ahead with balances still owing because nobody had the full picture.', 'fix' => 'Every event shows what was quoted, paid and still due.'],
            ['type' => 'retail', 'pain' => 'Stock that disappears without a trace', 'cost' => 'When shelf counts never match the till, losses to theft, damage and expiry stay invisible until they are large.', 'fix' => 'Barcode receiving, stock counts and wastage records show the gap and when it happened.'],
            ['type' => 'retail', 'pain' => 'Money tied up in stock that does not sell', 'cost' => 'Ordering by feel means too much of what sits on the shelf and too little of what customers ask for.', 'fix' => 'Sales and stock reports show what moves, and reorder lists show what to buy.'],
            ['type' => 'all', 'pain' => 'End-of-day totals that never add up', 'cost' => 'Cash, card and mobile money are counted in different places. When the totals differ, nobody can say where the gap is, so it is written off.', 'fix' => 'Every sale, payment and refund lands in one end-of-day report.'],
            ['type' => 'all', 'pain' => 'A business that only runs properly when you are there', 'cost' => 'Away for a day, you cannot see what was sold, who paid or what went missing. You end up tied to the premises.', 'fix' => 'Sales, bookings and cash on your phone, wherever you are.'],
            ['type' => 'all', 'pain' => 'Discounts, refunds and price changes nobody approved', 'cost' => 'A discount for a friend, a price typed wrongly, a refund that never happened. With no record of who did it, it keeps happening.', 'fix' => 'Every change records who made it, when and why.'],
            ['type' => 'all', 'pain' => 'Hours lost re-typing between paper, WhatsApp and spreadsheets', 'cost' => 'Information lives in four places, gets lost between them, and staff spend their time copying instead of serving.', 'fix' => 'One system, one login, one set of figures.'],
            ['type' => 'all', 'pain' => 'Month-end tax panic', 'cost' => 'VAT and levy figures pieced together from receipts at the last minute, with penalties when they are wrong.', 'fix' => 'VAT and tourism levy worked out correctly on every invoice and receipt.'],
        ],
        'anchor_note' => 'That is less than one missed booking, one unrecorded round of drinks or one unpaid membership.',

        'benefits_title' => 'What changes on day one',
        'benefits' => [
            ['One system instead of five', 'Sales, bookings, stock, staff and reports share one login and one set of figures. Nothing is re-typed between systems at the end of the day.'],
            ['Only the parts you need', 'A gym gets members and classes; a supermarket gets tills and barcode stock; a hotel gets rooms and front desk. Add more areas as you grow.'],
            ['Commission-free online bookings', 'Hotels and lodges take bookings on their own website. Booking agents typically keep around 15% of each stay; we keep none.', 'hotel'],
            ['Built in Malawi, for Malawi', 'Kwacha pricing, VAT, the tourism levy and local phone and address formats are built in.'],
            ['Made for local conditions', 'Runs in a web browser and is built with Malawian internet speeds in mind.'],
            ['Works on what you already own', 'Runs in a browser on a desktop, tablet or phone. No servers to buy and nothing to install.'],
            ['A full audit trail', 'Every price change, payment, refund and cancellation records who did it, when and why.'],
            ['Already in use', 'The system already runs at hotels in Malawi.'],
        ],

        // Columns of the "what is included" grid, then one row per area: [area, description, cells...]
        'module_columns' => ['Hotel', 'Restaurant', 'Gym', 'Venue', 'Retail'],
        'modules' => [
            ['Website', 'Your own branded website. You edit the content yourself.', 'Yes', 'Yes', 'Yes', 'Yes', 'Optional'],
            ['Rooms and front desk', 'Online booking, reservations, calendar, check-in and check-out, room moves and upgrades', 'Yes', '', '', '', ''],
            ['Housekeeping and maintenance', 'Room status, cleaning and inspection, maintenance jobs', 'Yes', '', '', 'Yes', ''],
            ['Point of sale', 'Tills on tablet, phone or touch screen, table service, split bills, room charges', 'Optional', 'Yes', 'Optional', 'Optional', 'Yes'],
            ['Kitchen and bar screens', 'Orders sent straight from the till to the kitchen and bar', 'Optional', 'Yes', '', '', ''],
            ['Stock', 'Products, ingredients, recipes, suppliers, purchase orders, barcode receiving, counts and wastage', 'Optional', 'Yes', 'Optional', 'Optional', 'Yes'],
            ['Conference and events', 'Enquiries, quotations, event bookings and venue management', 'Optional', '', '', 'Yes', ''],
            ['Gym and wellness', 'Members, packages, classes, schedules and check-in', 'Optional', '', 'Yes', '', ''],
            ['Billing and payments', 'Invoices, receipts, refunds and credit notes with VAT and tourism levy', 'Yes', 'Yes', 'Yes', 'Yes', 'Yes'],
            ['Reports', 'End-of-day report, sales, occupancy, stock and accounting summaries', 'Yes', 'Yes', 'Yes', 'Yes', 'Yes'],
            ['Staff access', 'Individual logins with permissions set per person', 'Yes', 'Yes', 'Yes', 'Yes', 'Yes'],
        ],

        'packages' => [
            ['name' => 'Hotel Starter',        'type' => 'hotel',      'suited' => 'Lodges, guest houses and B&Bs up to 15 rooms',  'setup' => 3360000,  'monthly' => 504000],
            ['name' => 'Hotel Standard',       'type' => 'hotel',      'suited' => 'Hotels with a restaurant or bar, up to 40 rooms', 'setup' => 6300000, 'monthly' => 1050000],
            ['name' => 'Hotel Full',           'type' => 'hotel',      'suited' => 'Resorts and conference hotels, any size',        'setup' => 12600000, 'monthly' => 1680000],
            ['name' => 'Restaurant and Bar',   'type' => 'restaurant', 'suited' => 'Restaurants, bars, cafes and takeaways',         'setup' => 2520000,  'monthly' => 420000],
            ['name' => 'Gym and Fitness',      'type' => 'gym',        'suited' => 'Gyms, fitness studios and wellness centres',     'setup' => 2100000,  'monthly' => 336000],
            ['name' => 'Conference Venue',     'type' => 'venue',      'suited' => 'Conference centres and event venues',            'setup' => 2520000,  'monthly' => 420000],
            ['name' => 'Shop and Supermarket', 'type' => 'retail',     'suited' => 'Retail shops, mini-marts and supermarkets',      'setup' => 2520000,  'monthly' => 420000],
            // Other business lines. Prices are 0 until you set them in the Template tab: nothing is invented.
            ['name' => 'Stay Onboarding (Travel Malawi)', 'type' => 'onboarding', 'suited' => 'Lodges, guest houses, B&Bs, cottages and safari camps listing on Travel Malawi', 'setup' => 0, 'monthly' => 0],
            ['name' => 'Custom Software and Web App', 'type' => 'software', 'suited' => 'Internal tools, dashboards, customer portals and multi-user products', 'setup' => 0, 'monthly' => 0],
            ['name' => 'Business Website',            'type' => 'web',      'suited' => 'Organisations that need a professional website they can edit themselves', 'setup' => 0, 'monthly' => 0],
            ['name' => 'Hardware Sourcing',           'type' => 'hardware', 'suited' => 'Laptops, desktops, gaming PCs and peripherals sourced locally or internationally', 'setup' => 0, 'monthly' => 0],
            ['name' => 'IT Support Plan',             'type' => 'support',  'suited' => 'Device setup, email, backups, security and remote support for small teams', 'setup' => 0, 'monthly' => 0],
        ],

        // period: month = recurring, once = one-off, note = shown as text only (not added to totals)
        'extras' => [
            ['name' => 'Add-on module (any area marked Optional)', 'price' => 210000,  'period' => 'month', 'note' => 'per module'],
            ['name' => 'Additional till or kitchen screen beyond two', 'price' => 126000, 'period' => 'month', 'note' => 'each'],
            ['name' => 'On-site training after go-live', 'price' => 630000, 'period' => 'once', 'note' => 'per day, plus travel outside Blantyre and Lilongwe'],
            ['name' => 'Custom features or design changes', 'price' => 126000, 'period' => 'note', 'note' => 'per hour, quoted before work starts'],
            ['name' => 'Additional business under the same owner', 'price' => 0, 'period' => 'note', 'note' => '25% off that business\'s monthly subscription'],
            ['name' => 'Annual payment in advance', 'price' => 0, 'period' => 'note', 'note' => 'Two months free'],
        ],
        'fees_note' => 'All fees are in {currency_name} and exclude VAT.{fx_line} The monthly subscription covers hosting, regular backups, security updates, improvements to the system as they are released, and support as set out below.',

        'steps' => [
            ['Discovery', 'Week 1', 'We meet, in person where possible or online, to understand how you trade today, which areas you need and who will use the system.'],
            ['Branding and website', 'Weeks 1 to 2', 'Your logo, colours, photos and wording on your own web address.'],
            ['Your data', 'Weeks 1 to 2', 'We load your rooms and rates, menus and products, stock items, suppliers, staff logins and opening balances.'],
            ['Training', 'Weeks 2 to 3', 'Hands-on training for managers and staff, online or at your premises, with a short written guide.'],
            ['Go-live', 'Weeks 3 to 4', 'You start trading on the system, and we stay close through the first week to sort out any questions.'],
        ],
        'steps_note' => 'You provide your logo, photos, product and price lists, and one person who can make decisions. The timeline depends on how quickly we receive these; the setup fee stays the same.',

        'support_intro' => 'Support is included in every subscription, by WhatsApp, email and phone during working hours (Monday to Friday, 08:00 to 17:00, Malawi time). Messages sent outside those hours are answered the next working day.',
        'support' => [
            ['Urgent', 'The system is down, or you cannot take payments or serve customers', 'Same working day', 'As soon as possible'],
            ['High', 'One area is not working, such as reports or the kitchen screen', 'Within 1 working day', 'Agreed with you'],
            ['Normal', 'A question, a small fault or a change request', 'Within 2 working days', 'Agreed with you'],
        ],
        'support_note' => 'The subscription also covers hosting, regular backups, security updates and improvements to the system as they are released. Work done only for your business is quoted before it starts.',

        'terms' => [
            ['Term', 'The agreement runs for an initial 12 months, then continues month to month until either party ends it with 30 days\' written notice.'],
            ['Fees', 'You pay the setup fee and monthly subscription for your package and any extras, as set out in the Quotation.'],
            ['Invoicing and payment', 'The setup fee is invoiced on signing: 50% is due before work starts and 50% at go-live. The monthly subscription is invoiced in advance on the 1st of each month and is due within 14 days.'],
            ['Price review', 'We may review fees once a year with 60 days\' written notice. Fees will not rise during the initial 12 months.'],
            ['Late payment', 'If an invoice is unpaid 30 days after its due date, we may suspend access after 7 days\' written notice. Access is restored within one working day of payment. Your data is never deleted because of late payment.'],
            ['Licence', 'We grant you a non-exclusive right to use the system for your business during this agreement. The software, its design and its code remain the property of ProManaged IT and may not be copied, resold or transferred.'],
            ['Your data', 'All customer, guest, sales and financial records you enter belong to you. We use them only to provide the service, and we do not sell or share them.'],
            ['Leaving', 'On request at the end of the agreement, we provide a full export of your data in standard formats within 14 days, at no charge. We delete it 90 days after the agreement ends unless you ask us to sooner.'],
            ['Availability', 'We work to keep the system available and tell you in advance about planned maintenance, done outside trading hours where possible. We cannot promise uninterrupted service, for example during power or internet outages outside our control.'],
            ['Confidentiality', 'Each party keeps the other\'s business information confidential, during and after this agreement.'],
            ['Your responsibilities', 'You keep staff passwords private, give access only to authorised staff, check that prices and tax settings are correct for your business, and keep your own internet connection and devices in working order.'],
            ['Liability', 'Our total liability under this agreement is limited to the fees you paid in the 12 months before the claim. Neither party is liable for indirect loss, such as lost profit, except in cases of fraud or wilful misconduct.'],
            ['Ending early', 'Either party may end the agreement with 30 days\' written notice if the other seriously breaches it and does not put it right within that time. If you end the agreement without cause during the initial 12 months, the remaining subscription fees for that period become due.'],
            ['Governing law', 'This agreement is governed by the laws of the Republic of Malawi. Disputes are first discussed in good faith, and then referred to the courts of Malawi.'],
        ],

        // Emails, one per document type. Placeholders: {business} {contact} {reference} {package} {setup} {monthly}
        // {daily} {first_year} {valid_until} {type_plural} {pain_title} {pain_cost} {or_call} {signature} {sign_step} {sign_link}
        'emails' => [
            'pitch' => [
                'subject' => '{business}: every sale, payment and stock count in one place',
                'body' => "Dear {contact},\n\nWhen {business} closes tonight, will you know exactly what was sold, what was paid and what went missing?\n\nMost {type_plural} can't say, and that is where money quietly leaks: {pain_title}. Our system gives you {gains}, on the phones and computers you already have.\n\nI have attached a short plan for {business}. Worth 20 minutes to see it on your own figures? Just reply with a time{or_call}.\n\nKind regards,\n{signature}",
            ],
            'contract' => [
                'subject' => '{business}: your agreement and next steps ({reference})',
                'body' => "Dear {contact},\n\nThank you for choosing us. Your agreement for {business} is attached, reference {reference}.\n\nWhat you are getting: {gains}.\n\nNext step: {sign_step}\n\nAnything unclear? Reply and I will walk you through it{or_call}.\n\nKind regards,\n{signature}",
            ],
            'both' => [
                'subject' => '{business}: your plan and agreement ({reference})',
                'body' => "Dear {contact},\n\nWhen {business} closes tonight, will you know exactly what was sold, what was paid and what went missing?\n\nOur system gives you {gains}. Attached are your plan and agreement, reference {reference}.\n\nNext step: {sign_step}\n\nWould you like to see it on your own figures first? Reply with a time{or_call}.\n\nKind regards,\n{signature}",
            ],
        ],
    ];
}

/** Extra agent settings; merged under whatever is saved, so nothing existing is migrated or overwritten. */
function pm_agents_extra_defaults(): array
{
    return [
        'backlog_pause'  => true, // pause scouts/writers while unsent drafts pile up (scouts at 2x send_cap, new-lead writers at 1x)
        'snooze_days'    => ['not_now' => 30, 'ask_later' => 14, 'out_of_office' => 7], // used when a reply says "contact me later"
        'worker_timeout' => 240,  // seconds before a stuck agent process is killed
        'run_stale_hours' => 36,  // dead-man: warn when a brand's agent run has not finished for this long
    ];
}
