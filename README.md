# ProManaged Marketing Engine

A PHP app the marketing team uses every day to find leads, write to them, follow up, send proposals and run the social pages. It handles more than one business.

Nothing goes to a customer until a person approves it.

## What it does

- **Sales:** finds businesses, drafts the first email and the WhatsApp message, and handles follow-ups and replies. Proposals are signed online.
- **Social:** plans posts, publishes them, and handles comments, messages and ads.
- **Brand:** covers, profile pictures, profile text and the voice the writers follow.
- **Insights:** what brought enquiries and wins.
- **Operations:** business details, email, connections, health checks and team accounts.
- **Audit** (admin only): an activity log, follow-up counts and a weekly review.

The team's how-to is in the app itself: **your name (top right) › Guide**.

## Run it on a PC

Requires PHP 8.1 or newer with the curl, mbstring, openssl, gd, iconv and fileinfo extensions.

```
composer install --no-dev
php -S 127.0.0.1:8085 router.php
```

Then open http://127.0.0.1:8085. On Windows, `start.bat` does the same.

## Put it on a server

1. Upload the folder to your hosting, for example `public_html/promanaged_proposals`.
2. Create `.env` on the server. Set `APP_URL` to the app's address and `CRON_KEY` to a long random text, and add the mail and AI keys.
3. Open the address and sign in. An admin manages accounts under **Operations › Team and currencies**.
4. Check that `/.env` and `/data/` return "Forbidden". The `.htaccess` files take care of this on Apache.

`.env`, `data/` and `output/` are never in git. They hold secrets, leads and signed documents.

## Scheduling on a server

Add these in cPanel › Cron Jobs, using your own address and paths:

```
*/15 * * * *   curl -s "https://YOUR-SITE/promanaged_proposals/cron.php?key=CRON_KEY" >/dev/null
30 7 * * 1-5   php /home/USER/public_html/promanaged_proposals/lib/run_agents.php
35 7 * * 1-5   php /home/USER/public_html/promanaged_proposals/lib/run_agents.php travel
*/15 * * * *   php /home/USER/public_html/promanaged_proposals/lib/send_due.php
*/15 * * * *   php /home/USER/public_html/promanaged_proposals/lib/poll_run.php
```

Once these run, **Operations › Health and backups** shows the scheduler as green.

## For developers

- `UI.md` describes the screens and styles.
- `MARKETING.md` is the working plan for the marketing features.
