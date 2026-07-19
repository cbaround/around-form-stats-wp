# Around Form Stats (WordPress plugin)

Push-based Quform submission monitoring. Sends **metadata only** to the Laravel API — no personal form fields.

## Install

```bash
# Symlink or copy into a WordPress site:
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/around-form-stats
```

Activate in WP Admin, then **Settings → Around Form Stats**.

## Updates (WordPress Plugins screen)

This plugin checks **GitHub Releases** on [cbaround/around-form-stats-wp](https://github.com/cbaround/around-form-stats-wp) and shows updates under **Plugins → Installed Plugins**, same as wordpress.org plugins.

Because the repo is private, add a GitHub token with `repo` (or fine-grained read access) in `wp-config.php`:

```php
define('AROUND_FORM_STATS_GITHUB_TOKEN', 'ghp_...');
```

### How to publish a new version

1. Bump `Version` in `around-form-stats.php`, `AFS_VERSION`, and `Stable tag` in `readme.txt`
2. Commit and push to `main`
3. Create a GitHub release with tag `vX.Y.Z` and a zip asset named `around-form-stats.zip`:

```bash
VERSION=1.0.2
rm -rf /tmp/around-form-stats /tmp/around-form-stats.zip
mkdir -p /tmp/around-form-stats
rsync -a --exclude .git --exclude local-origin ./ /tmp/around-form-stats/
cd /tmp && zip -r around-form-stats.zip around-form-stats
gh release create "v${VERSION}" /tmp/around-form-stats.zip \
  --repo cbaround/around-form-stats-wp \
  --title "v${VERSION}" \
  --notes "See readme.txt changelog."
```

WordPress sites will then see **Update now** for that version.

## Connect

From the Laravel dashboard, create an enrollment key, then either:

1. Paste **API URL** + **enrollment key** in the plugin settings, or
2. Define in `wp-config.php`:

```php
define('AROUND_FORM_STATS_API_URL', 'https://around-form-stats-production-qbotdt.laravel.cloud');
define('AROUND_FORM_STATS_ENROLLMENT_KEY', 'afs_...');
```

3. Or WP-CLI:

```bash
wp around-form-stats connect \
  --key="afs_..." \
  --api-url="https://around-form-stats-production-qbotdt.laravel.cloud"
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
