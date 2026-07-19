# Around Form Stats (WordPress plugin)

Push-based Quform submission monitoring. Sends **metadata only** to the Laravel API — no personal form fields.

## Install

```bash
# Symlink or copy into a WordPress site:
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/around-form-stats
```

Activate in WP Admin, then **Settings → Around Form Stats**.

## Connect

From the Laravel dashboard, create an enrollment key, then either:

1. Paste **API URL** + **enrollment key** in the plugin settings, or
2. Define in `wp-config.php`:

```php
define('AROUND_FORM_STATS_API_URL', 'http://127.0.0.1:8000');
define('AROUND_FORM_STATS_ENROLLMENT_KEY', 'afs_...');
```

3. Or WP-CLI:

```bash
wp around-form-stats connect --key="afs_..." --api-url="http://127.0.0.1:8000"
wp around-form-stats status
wp around-form-stats flush
wp around-form-stats heartbeat
```

New sites appear as **Pending approval** in Laravel until approved.

## Architecture

```text
Quform success hook
  → QuformAdapter (metadata only)
  → local afs_event_queue table
  → POST /api/v1/submissions/batch
  → delete only explicitly accepted event IDs
```

Heartbeat: `POST /api/v1/sites/heartbeat` (hourly WP-Cron).

## Quform hook spike

Primary hook: `quform_post_process`.

Verify on your Quform versions:

* AJAX and non-AJAX forms
* Spam / discarded submissions do not fire
* Entry storage disabled still fires
* No double-fire (request-level dedupe is included)

Enable alternate hook if needed:

```php
add_filter('afs_enable_quform_form_submitted_hook', '__return_true');
```
