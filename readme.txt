=== Around Form Stats ===
Contributors: around
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later

Push Quform submission metadata to Around Form Stats. No personal form field data is transmitted.

== Description ==

Around Form Stats connects WordPress + Quform sites to a central Laravel dashboard.

* Listens for successfully processed Quform submissions
* Stores events in a local retry queue
* Sends metadata-only batches to the API
* Heartbeats for connection health
* Enrollment key exchange for per-site API tokens

No names, emails, messages, or IP addresses are sent.

== Installation ==

1. Copy this folder to `wp-content/plugins/around-form-stats`
2. Activate the plugin
3. Go to Settings → Around Form Stats
4. Enter the API URL and enrollment key from your Laravel dashboard

Bulk install options:

    define('AROUND_FORM_STATS_API_URL', 'https://stats.example.com');
    define('AROUND_FORM_STATS_ENROLLMENT_KEY', 'afs_...');

Or via WP-CLI:

    wp around-form-stats connect --key="afs_..." --api-url="https://stats.example.com"

== Frequently Asked Questions ==

= What counts as a submission? =

A Quform submission that passed validation and completed normal form processing.

= What if the API is down? =

Events stay in a local queue and are retried by WP-Cron and on the next successful opportunity.

== Changelog ==

= 1.0.2 =
* Add GitHub Releases updates on the WordPress Plugins screen

= 1.0.1 =
* Fix Quform submission capture (use quform_post_process filter correctly)

= 1.0.0 =
* Initial release
