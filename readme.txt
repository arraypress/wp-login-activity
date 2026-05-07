# WP Login Activity

Lightweight login activity tracking for WordPress. Records every login, logout, failed attempt, and registration — with country detection across all major CDNs and email alerts when a successful login arrives from a country never seen for that user before.

## Features

- **Tracks four event types** — successful logins, logouts, failed login attempts (with the typed username/email), and new registrations.
- **Country detection across CDNs** — Cloudflare, CloudFront, Fastly, BunnyCDN, server-level GeoIP modules, and generic `X-Country-Code` headers, in that priority order. Powered by [arraypress/visitor-country](https://github.com/arraypress/visitor-country).
- **First-seen-country alerts** — when a user logs in successfully from a country we've never seen for them before, fire an email. The `is_new_country` flag is computed at write time, not at read time, so it's an O(1) lookup.
- **Source-tagged country values** — every row records *where* the country came from (`cloudflare`, `cloudfront`, `fastly`, etc.) so debugging "wrong country" reports is fast.
- **Retention purge** — daily cron deletes rows past the configured retention window, in batches.
- **User-profile section + Users list column** — see recent activity per user without leaving wp-admin.
- **Decoupled hooks** — `wp_login_activity_logged` fires on every insert; integrators can drop in a Slack forwarder / SIEM relay / custom email template by listening to that one action.

## Storage

Single BerlinDB-managed table (`wp_wpla_activity`). Indexed for the actual access patterns:

- `(user_id, date_created)` — per-user history list
- `(country_code)` — new-country novelty check
- `(event_type, date_created)` — admin filtering
- `(date_created)` — retention-purge scans
- `(ip_address)` and `(identifier(50))` — admin search

## Requirements

- PHP 8.0+
- WordPress 6.0+
- Composer (run `composer install` after cloning before activating)

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/arraypress/wp-login-activity.git
cd wp-login-activity
composer install --no-dev
```

Then activate from Plugins → Installed Plugins.

## Settings

Settings → Login Activity. Five fields, no upsells:

- Retention days (default 90; set to 0 to keep forever)
- Which event types to log (successful is always on; failed/logout/register are individually toggleable)
- New-country alert on/off
- Recipient: user, admin, or both

## Hooks

- `wp_login_activity_logged` — `( int $row_id, Activity $row )` after every insert
- `wp_login_activity_purged` — `( int $deleted, string $cutoff )` after the daily cron
- `wp_login_activity_visitor_ip` — filter the resolved IP
- `wp_login_activity_new_location_email_body` — filter the alert email body

## License

GPL-2.0-or-later
