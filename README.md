# WP Login Activity

Lightweight authentication-event tracking for WordPress. Records every login, logout, failed login, registration, password change, email change, and admin-role assignment, with cross-CDN country detection, browser/device/bot classification, and opt-in email alerts on the events that actually matter for security.

Designed to be simple to use and read, not a kitchen-sink "security suite". Five visible columns on the main table, the rest of the detail surfaced in a slide-in flyout when you actually need it.

---

## What gets captured

| Event | When |
|---|---|
| `login` | Successful authentication |
| `login_failed` | Bad-credential attempt (with whatever was typed) |
| `logout` | User-initiated session end |
| `registered` | New user account created |
| `password_changed` | Profile-edit OR lost-password reset |
| `email_changed` | Profile email-address change |
| `admin_assigned` | A user gained the Administrator role (new user OR promotion) |

Per row stored:

- User ID + the typed identifier (so failed attempts on bogus usernames are still searchable)
- Event type, IP address, country code (+ which source resolved it: Cloudflare, CloudFront, Fastly, BunnyCDN, server GeoIP, generic)
- Raw User-Agent + raw `Accept-Language`
- HTTP referer
- Snapshot of the user's primary role at event time
- Actor user ID (who PERFORMED the action — different from the subject for admin-driven changes)
- SHA-256 of the WP session token (for the "this is your current session" highlight)
- `is_new_country` flag — pre-computed at insert time so the read path is O(1)

---

## Email alerts

Five independent listeners, each toggleable in `Settings → Login Activity`:

| Alert | Default | Recipient |
|---|---|---|
| New-country login | on | The user who logged in (configurable: user / admin / both) |
| Administrator role assigned | on | All other admins (excludes the affected user) |
| Admin password changed | on | All other admins |
| Admin email address changed | on | All other admins |
| Failed-login burst (≥5 in 10min on one identifier) | **off** | All admins |

The admin-team alerts always go to OTHER admins, never the affected user — preventing a compromised account's "your password was changed" mail from being snuffed by the attacker.

The failed-login-burst alert dedupes per identifier with a 1-hour transient so a sustained brute-force generates one email, not one per attempt. The activity log still records every attempt regardless.

---

## Admin UI

### `Users → Login Activity`

Five-column table — Username · Event · IP · Country · Date. Click any row's username to open a sectioned **slide-in flyout** with the full detail (User / Where / How / Raw + footer with Filter-by-user / Filter-by-IP / Delete). Inline detail rows would have stretched the table layout; the flyout slide-in pattern (Linear/Stripe-style) keeps the table tight while putting more breathing room around the forensic data.

- **Status link bar** above the table — All / Logins / Failed / Logouts / Registrations / Password changes / Email changes / Admin assigned, each with a count
- **Sortable columns** (Username, Event, IP, Country, Date)
- **Date range filter** (From/To) above the table — inherited by the CSV exporter so a filtered view exports filtered rows
- **Search** — matches against identifier, IP, country, event type
- **Bulk delete** + per-row delete (gated on `manage_options`)
- **Screen Options** — per-page slider, column hide
- **Help tab** — describes each event type + alert behaviour
- **Click-cell-to-filter** — username click opens the flyout, IP click filters by IP
- **Current-session highlight** — the row representing the session you're currently using is tinted blue

### Dashboard widget

Three-counter "Login Activity" widget on the WP dashboard showing Events / Failed logins / New-country logins for the last 24 hours. Each counter is a clickable drill-in to the matching filtered view. Hide via the dashboard's own Screen Options.

### User profile section

Embedded `WP_List_Table` in compact mode on the user-edit screen — same visual style as core's Application Passwords table. 10 most-recent events for that user, with a "View full activity →" link to the scoped admin page.

### Users list column

"Last login" column on `wp-admin/users.php` showing the user's most-recent successful login in the site's timezone, with relative time underneath ("2 hours ago"). Privacy-gated — admins see all users, regular users see only their own row populated.

---

## CSV export

`Users → Login Activity → Export CSV` button (cap-gated on `manage_options`, nonce-protected). Streams matching rows in 1,000-row chunks via `fputcsv` against `php://output` — memory-bounded even on busy sites with hundreds of thousands of rows. Inherits the active filters (event type, user, search, date range, is-new-country) so a filtered view exports the filtered rows.

UTF-8 BOM in the file head so Excel auto-detects encoding. Filename includes UTC datetime + a short random suffix to prevent collisions on concurrent exports. **Formula-injection safe** — every cell whose first character is `=`, `+`, `-`, `@`, `\t`, or `\r` gets a leading single quote so opening the CSV in Excel / Sheets / Numbers can't fire formulas embedded in attacker-controlled fields (typed identifier, User-Agent, Referer, etc.).

---

## Architecture

- **Storage:** BerlinDB. Single table `wp_login_activity` with column metadata in a Schema class, query-layer access via the Query class, per-row model on the Row class. No raw `dbDelta`, no manual `$wpdb->prepare`, no orderby allowlist drift.
- **Country detection:** [arraypress/visitor-country](https://github.com/arraypress/visitor-country). Multi-source resolver covering Cloudflare, CloudFront, Fastly, BunnyCDN, server GeoIP, generic `X-Country-Code`. Records *which* source resolved each row.
- **IP detection:** [arraypress/wp-ip-utils](https://github.com/arraypress/wp-ip-utils). Strict-mode `IP::get()` for production (rejects private/loopback to defend against proxy spoofing) plus a permissive fallback for local dev / intranet / Docker, gated behind a filter.
- **Browser/OS/bot detection:** [arraypress/wp-user-agent-utils](https://github.com/arraypress/wp-user-agent-utils). The raw User-Agent is stored on the row; classification (browser, OS, device type, bot) happens at render time so future parser improvements automatically benefit historical rows.
- **Country names + flags:** [arraypress/wp-countries](https://github.com/arraypress/wp-countries). The Country column renders flag + full name; the raw `country_code` stays in the column for filtering.
- **Notification decoupling:** every listener subscribes to the `wp_login_activity_logged` action emitted by the Logger after each insert. Email alerts, audit forwarders, and SIEM integrations all hook there — the Logger doesn't know they exist.

---

## Why pre-compute `is_new_country` at write time

The "have we seen this user from this country before?" check is the entire reason the new-country alert exists. Doing it at READ time would mean a fresh subquery against the activity table on every page load and double-counting concurrent logins from a new country.

At INSERT time, the Logger runs ONE indexed query (`user_id` + `country_code` + `event_type=login`) and writes a `1` or `0` to the row. The notification listener reads the flag directly. Reads are O(1), writes pay one extra index lookup, and concurrent logins from a new country still produce one alert per row (acceptable).

---

## Public function API

`src/functions.php` is autoloaded via Composer's `autoload.files`:

| Function | Purpose |
|---|---|
| `wpla_query()` | Cached BerlinDB Query instance |
| `wpla_get_activity( $id )` | Fetch one row by ID |
| `wpla_get_user_activity( $user_id, $limit )` | Recent rows for a user |
| `wpla_get_last_login( $user_id )` | Most-recent successful login row |
| `wpla_count_failed_logins( $identifier, $field, $minutes )` | Failure-velocity counter for rate-limit integrations |

---

## Hooks

### Actions

| Hook | Args | Purpose |
|---|---|---|
| `wp_login_activity_logged` | `( int $row_id, ActivityRow $row )` | Fires after every successful insert |
| `wp_login_activity_purged` | `( int $deleted, string $cutoff )` | Fires after the daily retention cron |

### Filters

| Hook | Args | Purpose |
|---|---|---|
| `wp_login_activity_visitor_ip` | `( string $ip )` | Override the resolved IP |
| `wp_login_activity_allow_private_ip` | `( bool )` | Strict mode (false) rejects private/loopback IPs |
| `wp_login_activity_pre_insert_data` | `( array \| false $data, string $event_type, int $user_id, string $identifier )` | Mutate row data before insert; return `false` to skip |
| `wp_login_activity_should_send_alert` | `( bool, string $alert_type, ActivityRow $row )` | Suppress an alert (maintenance windows, SIEM dedup, etc.) |
| `wp_login_activity_alert_recipients` | `( string[] $recipients, string $context )` | Mutate recipient list (`$context` is `user` or `admins`) |
| `wp_login_activity_row_actions` | `( array $actions, ActivityRow $row )` | Append/replace per-row action links on the admin list table |
| `wp_login_activity_event_labels` | `( array $labels, string $event_type )` | Display labels for custom event types |
| `wp_login_activity_event_colours` | `( array $palette, string $event_type )` | Badge colours for custom event types |
| `wp_login_activity_ip_lookup_services` | `( array $services, string $ip, ActivityRow $row )` | Add/replace external-lookup services in the flyout |
| `wp_login_activity_ip_lookup_url` | `( string $url, string $ip, ActivityRow $row )` | Override the default IPInfo URL |
| `wp_login_activity_dashboard_widget_enabled` | `( bool )` | Suppress the dashboard widget site-wide |
| `wp_login_activity_failed_burst_threshold` | `( int )` | Brute-force burst threshold (default 5) |
| `wp_login_activity_failed_burst_window_minutes` | `( int )` | Brute-force burst window (default 10) |
| `wp_login_activity_new_location_email_body` | `( string $body, WP_User $user, ActivityRow $row )` | Override the new-location alert body |
| `wp_login_activity_admin_assigned_email_body` | `( string $body, ActivityRow $row )` | Override the admin-assigned alert body |
| `wp_login_activity_admin_password_changed_email_body` | `( string $body, ActivityRow $row )` | Override the admin-password-changed alert body |
| `wp_login_activity_admin_email_changed_email_body` | `( string $body, ActivityRow $row )` | Override the admin-email-changed alert body |
| `wp_login_activity_failed_burst_email_body` | `( string $body, ActivityRow $row, string $identifier, int $count, int $window )` | Override the failed-burst alert body |
| `wp_login_activity_remote_api_request_args` | `( array $args, array $config, array $context )` | (Reserved for future remote-key-fetch integrations) |

---

## Settings

`Settings → Login Activity`. Tight by design:

- **Retention** — purge rows older than N days (default 90; 0 = keep forever)
- **Events to log** — successful logins are always on; failed-login / logout / registration / password-change / email-change toggleable
- **Email alerts** — five independent toggles (new-country, admin-assigned, admin-password-changed, admin-email-changed, failed-login burst)
- **New-country recipient** — user / admin / both (admin-team alerts always go to admins regardless)

---

## Cron

Daily `wp_login_activity_cleanup` purges rows past the retention window in 1,000-row chunks. Idempotent re-registration: if the next-scheduled timestamp goes away (cron job dropped, plugin reactivated), it self-schedules on next page load. Retention=0 short-circuits to "keep forever" — useful for sites with compliance reporting requirements.

---

## Requirements

- PHP 8.0+
- WordPress 6.0+
- Composer (run `composer install --no-dev` after cloning)

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/arraypress/wp-login-activity.git
cd wp-login-activity
composer install --no-dev
```

Activate from `Plugins → Installed Plugins`.

---

## Tests

```bash
php tests/run.php
```

Self-contained PHP harness — no PHPUnit, no WP test core, no composer dev dependencies. 16 tests covering `wp-ip-utils` cascade, `wp-user-agent-utils` bot/browser detection, and every `ActivityRow` presentation helper. Add a real WP integration suite when there's CI to run it in.

---

## Design conventions

- **Don't store derived UA values.** Always store the raw User-Agent string. Browser / OS / device classification comes off the Row model on read via wp-user-agent-utils. Future parser improvements should retroactively benefit historical rows.
- **Don't query the activity table from inside an alert listener.** Everything the email needs is on the `ActivityRow` already — extra queries would defeat the point of pre-computing `is_new_country` at write time.
- **Don't add new event types without updating the Settings UI.** The settings checklist mirrors the event slugs Logger writes.
- **Don't try to prevent admin attackers.** An admin who's already in can truncate the table directly; plugin-level delete-blocking is theatre. Real defence = forwarding events to a write-once SIEM via the `wp_login_activity_logged` action.

---

## License

GPL-2.0-or-later
