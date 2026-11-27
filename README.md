# Ty Kolle Hockey Development: Camp Registration System

A self-contained camp registration system for players, with an admin back office. It is written in plain PHP with MySQL/MariaDB. There is no framework and no Composer, so it runs on any standard shared host or VPS.

## Features

**Player side: a 6-step registration wizard**

1. **Account.** Player name, email, birthdate and a password the player chooses.
2. **Player info.** Asks "Are you 18 or older?". If the player is under 18, it collects the parent or guardian's name, relationship, email and phone. Every player then gives their last level of hockey played: the tier, league, team, season, position and shooting hand. The answer is checked against the birthdate.
3. **Player profile.** An Elite Prospects link, a league player page, another link, or "no profile" with an explanation. Admins use this to verify the player.
4. **Waiver.** The player reads the full waiver and signs it electronically by typing their name.
   - **Under 18:** the parent/guardian on file must sign, and the name typed has to match that parent's name.
   - **Adults:** the name typed has to match the player's own name.
   - **What's stored:** each signature is saved with the date, IP address, browser and a copy of the exact wording signed.
   - **Required:** the player can't reach camp selection until the current waiver is signed.
5. **Select camps.** Camps appear as selectable cards and the total updates as the player picks them. An **All Camps** option ticks every camp the player is eligible for in one click. Camps are greyed out, with the reason shown, when the player can't take them:
   - the camp is full, closed, or already purchased
   - the player is outside the camp's birth years
   - the player's level of play is **below the camp's minimum level**. Eligibility uses the claimed level, or the level an admin confirmed if they corrected it.

   These rules are also checked on the server at checkout.
6. **Checkout.** An order summary, confirmation that the waiver is signed, and a **demo payment**. The order is recorded as paid and no card is charged.

Players can log back in, see their camps and verification status, edit their info and add more camps later.

**Forgot password.** The login page has a "Forgot your password?" link. It emails a one-time reset link that expires after 60 minutes. Using the link also cancels any other outstanding reset links for that account. The form shows the same message whether or not the email has an account, so it can't be used to find out who is registered. Requests are rate-limited to 3 per account per hour and 5 per browser session per hour.

**Admin side** (`/admin/`)

- **Dashboard.** Registrations, revenue, how full each camp is, players waiting for verification and recent orders.
- **Camps & Pricing.** Create, edit, hide or delete camps. Each camp has a price, capacity, dates, schedule, location, eligible birth years, a **minimum level of play** and registration open/close dates. You can also change prices directly in the list.
- **Players & Verification.** Search and filter by camp, level and verification status. On each player's page you can:
  - **Run an auto-check** on the profile link. The system fetches the Elite Prospects or league page and checks the name, birthdate and the leagues in the season-by-season stats. It compares these with the level the player claimed and gives a 0–100 score with a checklist.
  - **Check pasted page text.** Some sites, Elite Prospects included, often block automated requests. In that case the admin copies the page (Ctrl+A, Ctrl+C) and pastes it in, and the same matching runs on the text.
  - Mark the player Verified, Flagged or Rejected, correct their level and add a note.
  - **Rejecting a player** does four things:
    - It sends the *Registration denied* email (optional), which lists their camps.
    - It **cancels all of their camp registrations**, which frees the spots. The confirmation shows the refund amount due.
    - It stops them registering for more camps.
    - The rejection can't be cleared by the player editing their profile. Only an admin can change the status.
  - When restoring a cancelled registration, the camp's capacity is checked first.
  - View and print the player's **signed waiver**.
  - Cancel or restore registrations, and reset the player's password.
- **Auto-check pending (10).** Checks pending players in batches of 10.
- **Reports & Rosters.** A printable roster for each camp, or all camps with one camp per page. Rosters have an attendance column and can be sorted by name, level, position, age or verification. There is also a level-of-play mix for each camp and CSV export (rosters, all registrations, all players).
- **Emails.**
  - **Built-in templates:** *Registration accepted*, *Registration denied* and *Password reset*. You can edit the wording of all three, and "Restore default wording" puts the original text back.
  - **Custom templates:** create, edit and delete as many as you like, for example camp reminders or schedule changes.
  - **Placeholders:** `{greeting_name}`, `{player_name}`, `{camp_list}`, `{level}`, `{verification_note}`, `{camp_name}`, `{login_link}` and more. Click one to insert it into the subject or message. The editor previews the email using a sample player.
  - **Automatic accepted/denied emails:** when you mark a player **Verified** or **Rejected**, a ticked checkbox sends *Registration accepted* or *Registration denied*. Players under 18 have their parent/guardian copied in. Your verification note fills `{verification_note}`.
  - **Send an email:** to one player, everyone in a camp, all registered players, or players with a given verification status. You can start from any template and adjust it for that one send.
  - **Sent log:** every email is recorded with its status (sent, failed or log-only), and you can open each one to see exactly what was sent. A player's page also lists the emails sent to them.
- **Levels of Play.** Edit the tiers players choose from, their rank, and the league keywords the verifier looks for.
- **Settings.** Organization name, contact email, logo URL, currency, opening or closing registration, administrator accounts and changing your password.
  - **Waiver:** edit the text here. Tick "make everyone sign the new version" for a significant change; players then have to sign again before adding camps. Leave it unticked for typo fixes, and existing signatures stay valid.
- **Rosters and CSV exports** show whether each player has signed the waiver.

## Requirements

- PHP **8.1+** with `pdo_mysql` and `curl`. `intl` is recommended for matching accented names.
- MySQL 5.7+ or MariaDB 10.3+
- Apache (an `.htaccess` file is included) or Nginx (see below)

## Installation

1. Create an empty MySQL database and a database user in your hosting control panel.
2. Upload the contents of this package to your web server. It can go in the web root or in a sub-folder such as `/register/`.
3. Make sure the app folder is writable by PHP for the installation step, so the installer can save `config.php`.
4. Browse to `https://your-site/install/` (or `https://your-site/register/install/`) and fill in:
   - the database host, port, name, user and password
   - the organization name, contact email and timezone
   - how email is sent: the server's default PHP mail(), an SMTP server, or "don't send" for demos
   - the first administrator's email and password
   - whether to load 5 sample camps
5. **Delete the `install/` folder** when the installer has finished.
6. Log in at `/login.php` with the administrator account. You will land on `/admin/`.

### Manual install (no web installer)

```bash
mysql -u USER -p DBNAME < install/schema.sql
mysql -u USER -p DBNAME < install/seed.sql
mysql -u USER -p DBNAME < install/sample_camps.sql   # optional
cp config.sample.php config.php                      # then edit the DB settings
```

Then create an administrator. Generate a password hash:

```bash
php -r "echo password_hash('YourPassword1', PASSWORD_DEFAULT), PHP_EOL;"
```

and insert the administrator:

```sql
INSERT INTO users (role, first_name, last_name, email, password_hash)
VALUES ('admin', 'Ty', 'Kolle', 'you@example.com', '<hash from above>');
```

### Nginx

`.htaccess` rules don't apply on Nginx. Add rules like these instead:

```nginx
location ~ ^/(includes|install/.*\.sql) { deny all; }
location ~ /config\.php$ { deny all; }
```

## Upgrading an existing install

Upload the new files over the old ones, but keep your `config.php`. Database changes are applied automatically the first time any page loads. To use the new email features, add the `'mail'` section from `config.sample.php` to your `config.php`. If you don't, emails are sent with PHP `mail()`.

## Matching the main website's look

All colors and fonts are CSS variables at the top of `assets/css/style.css`:

```css
--brand-dark:    #0d1117;  /* header / hero / footer */
--brand-primary: #c8102e;  /* buttons & highlights */
--brand-accent:  #6fb7e9;
--font-head: 'Oswald', ...;
--font-body: 'Open Sans', ...;
```

To use the real logo, either replace `assets/img/logo.svg` or set **Admin → Settings → Logo image URL**. The Google Fonts link is in `includes/header.php`.

## Email setup

Email delivery is set in the `'mail'` section of `config.php`. The installer fills it in for you.

| `transport` | Use when |
|---|---|
| `mail` | Your host supports PHP `mail()`. This is the default on most shared hosting. |
| `smtp` | Mail should go through a real mail server, such as Gmail or Google Workspace (`smtp.gmail.com`, port 587, TLS, using an app password), Microsoft 365 (`smtp.office365.com`, port 587, TLS), your host's mail server, or SendGrid/Mailgun. This is the most reliable option and the least likely to land in spam. |
| `log` | You're running a demo. Nothing is sent, but every email (including password reset links) appears in **Admin → Emails → Sent log**, so you can test the full flow. |

After changing these settings, use **Admin → Settings → Send test email to me** to check that delivery works.

Links in emails (password reset, login) use the **Site URL** under Admin → Settings. The installer sets it automatically. Update it if you move the system to a different address.

For best deliverability, set `from_email` to an address on your own domain and add SPF/DKIM records for that domain with your host.

## How the level verifier works

The code is in `includes/verify.php`.

| Check | Points |
|---|---|
| Name in the Elite Prospects link slug (`/player/12345/first-last`) | 15 |
| First and last name appear on the page | 25 |
| Exact birthdate (15) or birth year (8) on the page | up to 15 |
| Claimed level appears in the **most recent season** row (45), any season row (35), or only elsewhere on the page (15) | up to 45 |
| League name and team name typed by the player appear on the page | 5 each |
| Highest level in the stats is clearly lower than the claim and the claim isn't found | −10 |

A score of **70 or more** is a *likely match*, **40–69** *needs review*, and below 40 is a *possible mismatch*.

Leagues are only counted when they sit next to a season label such as `2025-26`. Menus and ads that mention "NHL" therefore don't count as the player's level. Short all-caps keywords like `OHL` and `AAA` are matched case-sensitively, and the longest keyword wins, so `U16 AAA` takes priority over `U16`. You can tune all of this in **Admin → Levels of Play** without editing code.

The score is only a guide. The admin always makes the final decision.

For security, the fetcher only requests public http/https addresses. Links that point at internal or private network addresses are refused.

## Going live with real payments

Demo mode is controlled by `'demo_mode' => true` in `config.php`. To take real payments, replace the order-creation block in `register.php` (step 5) with your payment provider, such as Stripe Checkout, Square or PayPal. Create the `orders` row as `pending`, and mark it `paid` in the provider's success callback or webhook. The `orders` table already has `payment_method` and `payment_ref` columns for this.

## File layout

```
index.php            Public landing page with camp list
register.php         5-step registration wizard
login.php / logout.php / account.php / confirmation.php
forgot_password.php / reset_password.php
admin/               Dashboard, camps, players, verification, reports, rosters, CSV, emails, levels, settings
includes/            Bootstrap, DB, auth, helpers, verifier, mailer, migrations, layout (blocked from the web)
install/             Web installer + SQL schema and seed data (delete after install)
assets/              CSS, JS, logo
config.sample.php    Configuration template
```

## Security notes

- Passwords are hashed with `password_hash` (bcrypt or argon2, depending on PHP).
- Every form is CSRF-protected, and every query uses PDO prepared statements.
- All output is escaped.
- Logins are throttled, and the session ID is regenerated on login.
- CSV exports are guarded against spreadsheet formula injection.
- Password reset tokens are random 256-bit values. Only a SHA-256 hash of each token is stored, and each one expires after 60 minutes and works once.
- Serve the site over **HTTPS**. The session cookie is marked `Secure` automatically when HTTPS is used.
