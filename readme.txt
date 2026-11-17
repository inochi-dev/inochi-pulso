=== Inochi Pulso – Autoload Cost & Site Audit ===
Contributors: inochidev
Tags: autoload, plugin performance, slow wordpress, woocommerce performance, site audit
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find which plugin is slowing your site: autoload, tables, cron and WooCommerce issues per plugin, in one read-only scan.

== Description ==

Inochi Pulso tells you what each plugin costs your site and what to fix first.

Run one scan from Tools → Inochi Pulso. Pulso reads your site without changing anything and gives you a cost per plugin table and a prioritised report: urgent, stabilise, improve.

= What makes it different =

* **Cost per plugin, in one scan.** One row per plugin — active, inactive or already deleted — with its autoloaded data, its own database tables, its cron events (orphaned ones included) and its failed or late scheduled actions. No need to browse for days before you get numbers.
* **No guessing in the attribution.** Every item is attributed by the callback registered for it, by finding its name in the installed code, or by the table and option naming rules of a few well-known plugins (WooCommerce, WPML, Yoast SEO, Wordfence). When more than one plugin could own it, it goes to "Unattributed" instead of being pinned on the wrong plugin.
* **WordPress and WooCommerce operations in the same report.** Store pages, failing webhooks, stuck orders, failed and late scheduled actions, expired sessions and HPOS status.
* **No invented results.** A check that cannot run shows "No data" and does not count towards the score. Every finding shows its evidence. Severity is fixed per rule.
* **Read-only and leaves no trace.** Install, scan, export, uninstall. Uninstalling removes the plugin's tables and options.

= What it checks =

* Database: autoload size, largest tables, expired transients, post revisions.
* Plugins: cost per plugin (autoload, tables, cron, scheduled actions), pending updates, plugins not from WordPress.org, number of active plugins; optionally, plugins closed or not updated on WordPress.org (off by default, see External services).
* Server: response time (TTFB) measured from your own site, page cache, persistent object cache, PHP version and settings, cron.
* Security basics: HTTPS, security headers, debug output, file editor, administrator accounts, user enumeration, XML-RPC, file permissions, leftover backups and installers reachable from the web.
* Maintenance: automatic updates, recurring PHP errors.
* WooCommerce (if active): version and outdated template overrides, HPOS, store pages, webhooks, stuck orders, failed and late scheduled actions, expired sessions.

= Export =

Printable HTML (save as PDF from your browser), CSV and JSON, with the cost per plugin table first. Exports never include usernames or email addresses.

= Site Health =

Pulso adds two tests to Tools → Site Health: the result of the last scan and the plugins with the highest cost. They read the last scan; they do not scan again.

= Languages =

English and Spanish (es_ES).

= Who it is for =

Site owners, developers and agencies who need to know which plugin is weighing down a WordPress or WooCommerce site before deciding what to remove, replace or fix.

== External services ==

By default, Inochi Pulso does not contact any external service. During a scan it only sends requests to your own site, to measure response times and to check which files can be reached from the web.

If you turn on "Check plugins on WordPress.org" in Tools → Inochi Pulso → Settings, Pulso sends the slug (folder name) of each installed plugin, including premium or custom plugins, to the WordPress.org Plugins API to find plugins that are closed or have not been updated in more than two years. No other data is sent. This option is off until you enable it, and you can turn it off at any time.

* Service: WordPress.org Plugins API — https://api.wordpress.org/plugins/info/1.2/
* Privacy policy: https://wordpress.org/about/privacy/

Pulso does not contact Inochi servers and does not collect telemetry.

== Installation ==

1. Install from Plugins → Add New, or upload the ZIP.
2. Activate.
3. Go to Tools → Inochi Pulso and click "Start scan".

== Frequently Asked Questions ==

= Does Pulso change anything on my site? =

No. Every check is read-only. The only data Pulso writes is its own scan results, in two tables that are removed when you uninstall it.

= Will the scan slow down my site? =

The scan runs in short steps from the Pulso screen, with a progress bar, and does not use WP-Cron. It sends about 40 requests to your own site, which may show up in your server statistics or firewall log. In our tests on two WooCommerce stores, with 33 and 46 plugins, a scan took 43 and 75 seconds; larger sites take longer.

= Why does a check say "No data"? =

Because Pulso could not get a reliable value (for example, the site cannot reach itself). Pulso does not guess: that check does not count towards the score.

= Does it check for vulnerabilities? =

No. Use a dedicated security plugin for that. Pulso focuses on performance and maintenance cost.

= Is my data sent anywhere? =

Only if you enable the optional WordPress.org check described in External services. Nothing is sent to Inochi.

== Screenshots ==

1. Cost per plugin: autoload, tables, cron and scheduled actions for each plugin, from a single scan.
2. Prioritised report — urgent, stabilise, improve — with the evidence behind every finding.
3. "No data": checks that could not run don't count. We don't invent results.
4. WooCommerce operations: store pages, failing webhooks, stuck orders and failed scheduled actions.
5. Step-by-step read-only scan with progress bar, no WP-Cron.
6. Export to printable HTML, CSV and JSON.

== Changelog ==

= 1.0.0 =
* First public release: cost per plugin, 34 read-only checks, prioritised report, HTML/CSV/JSON exports, Site Health tests, Spanish translation.
