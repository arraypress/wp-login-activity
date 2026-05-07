# WP Login Activity

WordPress plugin that records every authentication event (login, logout, failed login, registration) into a BerlinDB table, surfaces them in admin, and emails the user/admin when a login arrives from a country never seen for that user before.

Architecturally a sibling of `edd-fraud-filter` and `edd-serial-keys` — same BerlinDB component shape, same single-singleton bootstrap, same "decoupled action hook drives notifications" pattern. Smaller surface area than either of those.

## Quick orientation

- **Entry:** `wp-login-activity.php` → `Plugin::instance()` in `src/Plugin.php`
- **Namespace:** `ArrayPress\WP\LoginActivity`
- **Text domain:** `wp-login-activity`
- **Constants prefix:** `WP_LOGIN_ACTIVITY_*`
- **Hook prefix:** `wp_login_activity_*`
- **Composer package:** `arraypress/wp-login-activity`
- **Requires:** PHP 8.0+, WordPress 6.0+

PSR-4 autoloading via Composer (no custom `slurp()` here — the plugin is small enough that explicit `new` calls in `Plugin::boot()` are cleaner than auto-include scanning).

## The event flow

```
WordPress fires:
  wp_login / wp_login_failed / wp_logout / user_register
              │
              ▼
  Tracking\Logger::on_*()
              │
              ▼
  resolve_ip()      → wp-ip-utils IP::get()
  resolve_country() → visitor-country Country::resolve_detailed()
  resolve_user_agent() → wp-user-agent-utils UserAgent::get() (raw)
  is_first_time_country( user_id, country ) → BerlinDB query
              │
              ▼
  Plugin::query()->add_item([...])           ← BerlinDB insert
              │
              ▼
  do_action( 'wp_login_activity_logged', $row_id, ActivityRow $row )
              │
              ├─ Notifications\NewLocationAlert (always loaded)
              ├─ third-party listeners (SIEM, Slack, audit forwarders)
              └─ test hooks
```

## Architecture

- **Storage = BerlinDB.** Single table (`wp_wpla_activity`). Schema/Table/Query/Row component pattern — no raw `dbDelta`, no manual `$wpdb->prepare`, no hand-maintained orderby allowlist (BerlinDB's column metadata governs all of that).
- **Country detection = visitor-country.** Multi-source resolver covering Cloudflare, CloudFront, Fastly, BunnyCDN, server GeoIP modules, and generic `X-Country-Code` headers. Each row records *which* source provided the country (`country_source` column) so "wrong country" debugging is fast.
- **IP detection = wp-ip-utils.** `IP::get()` already handles the CF-Connecting-IP / X-Forwarded-For / X-Real-IP / REMOTE_ADDR cascade with `filter_var` validation. Anonymisation (GDPR) is a one-call helper.
- **UA detection = wp-user-agent-utils.** Browser/OS/device/bot classification — but **always store the raw UA string** in the column. Formatting happens at render time on the Row model. Storing raw means future parser-library upgrades automatically improve classification on existing rows; storing pre-parsed labels would lock historical rows.
- **Notification decoupling.** `NewLocationAlert` is a `wp_login_activity_logged` listener, not a method on Logger. Swapping to Slack / SIEM / SMS is removing the listener and adding your own — no fork required.

## Why pre-compute `is_new_country` at write time

The "have we seen this user from this country before?" question is the entire reason the alert exists. It used to be answered at *read* time — a fresh subquery against the activity table on every login. That doesn't scale and double-counts simultaneous logins.

Now: at insert time, `Logger::is_first_time_country()` runs ONE indexed query (`user_id + country_code + event_type=login`) and writes a `1` or `0` to the row. The notification listener reads the flag directly. Reads are O(1), writes pay one extra index lookup, and concurrent logins from a new country still produce one alert per row (acceptable).

The novelty check excludes failed logins from the lookup — otherwise a fraudster could suppress the new-country flag by trying-and-failing first.

## BerlinDB tables

Single component registered in `Plugin::boot()`:

- `Database\Tables\Activity` → `wp_wpla_activity` (DDL + version + upgrades)
- `Database\Schemas\Activity` (column metadata; drives query-layer validation)
- `Database\Queries\Activity` (the queryable surface)
- `Database\Rows\Activity` (per-row model + presentation helpers)

Schema version format: `YYYYMMDDN` — bump when adding/changing columns AND register a corresponding `__YYYYMMDDN()` upgrade method.

## Indexes

Tuned for actual access patterns:

- `(user_id, date_created)` — per-user history list (UserProfile, ActivityPage when filtering by user)
- `(country_code)` — new-country novelty check
- `(event_type, date_created)` — admin filtering by event type
- `(date_created)` — retention-purge scans
- `(ip_address)`, `(identifier(50))` — admin search

## Settings

`Settings → Login Activity`. Five fields:

- Retention days (default 90; 0 = keep forever)
- Per-event-type toggles for failed/logout/registration logging (successful login is always on)
- New-country alert on/off
- Recipient: user / admin / both

Stored under `wp_login_activity_*` option keys. The Logger and NewLocationAlert read these on every event — no caching, options-cache handles it.

## Public function API

`src/functions.php` is loaded via `composer.json` → `autoload.files`, so the helpers are eagerly available on every request:

| Function | Purpose |
|---|---|
| `wpla_query()` | Cached BerlinDB Query instance |
| `wpla_get_activity( $id )` | Fetch one row by ID |
| `wpla_get_user_activity( $user_id, $limit )` | Recent rows for a user |
| `wpla_get_last_login( $user_id )` | Most recent successful login row |
| `wpla_count_failed_logins( $identifier, $field, $minutes )` | Failure velocity counter — useful for rate-limit integrations |

## Hooks

| Hook | Type | Args |
|---|---|---|
| `wp_login_activity_logged` | action | `( int $row_id, ActivityRow $row )` after every insert |
| `wp_login_activity_purged` | action | `( int $deleted, string $cutoff )` after the daily cron |
| `wp_login_activity_visitor_ip` | filter | `( string $ip )` — override the resolved IP |
| `wp_login_activity_new_location_email_body` | filter | `( string $body, WP_User $user, ActivityRow $row )` |

## Conventions

- **Don't store derived UA values.** Always store the raw User-Agent string. Browser/OS/device classification comes off the Row model on read via wp-user-agent-utils. Future parser improvements should retroactively benefit historical rows.
- **Don't hardcode country lists.** The plugin uses ISO-3166 alpha-2 codes throughout; humanisation (flag emoji, country names) is a presentation-layer concern that delegates to libraries.
- **Don't query the activity table from inside the alert listener.** Everything the email needs is on the `ActivityRow` already — extra queries would defeat the point of pre-computing `is_new_country` at write time.
- **Don't add new event types without updating the Settings UI.** The settings checklist mirrors the event slugs Logger writes.

## Cron

Daily `wp_login_activity_cleanup` registered by `Tracking\Cleanup`. Idempotent: re-registers itself on every page load if the next-scheduled timestamp has gone away. Retention=0 short-circuits to "keep forever". Batch size of 1000 rows per tick to keep table locks short on busy sites.

## Composer

```bash
composer install --no-dev
```

Vendor packages:

- `berlindb/core` — declarative table component
- `arraypress/visitor-country` — multi-source country resolver
- `arraypress/wp-ip-utils` — IP cascade resolver + GDPR anonymisation
- `arraypress/wp-user-agent-utils` — browser/OS/device/bot classification

## Common commands

```bash
# Lint everything
find . \( -path ./vendor -o -path ./.git \) -prune -o -type f -name '*.php' -print \
  | xargs -I {} php -l {} 2>&1 | grep -v "No syntax errors"

# Run the unit-test harness
php tests/run.php
```

## Known TODOs

- [ ] Schema upgrade migrations (versioning ready, no upgrades to apply yet).
- [ ] WP-CLI commands — useful for `wp wpla purge`, `wp wpla user <id>`, but not blocking.
- [ ] Search box on the admin list page — Schema columns are already marked `searchable`, just needs a UI form.
