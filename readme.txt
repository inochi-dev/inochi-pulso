=== Inochi Pulso – Plugin Cost & Site Audit ===
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

Run one scan from Tools → Inochi Pulso. Pulso reads your site without changing anything and gives you a prioritised report: urgent, stabilise, improve.

= What makes it different =

* **Autoload per plugin, in one scan.** The heaviest autoloaded options are attributed to the plugin that owns them, including plugins that are inactive or already deleted. No need to browse for days before you get numbers.
* **WordPress and WooCommerce operations in the same report.** Store pages, active gateways, failing webhooks, stuck orders, failed and late scheduled actions, expired sessions and HPOS status.
* **No invented results.** A check that cannot run shows "No data" and does not count towards the score. Every finding shows its evidence. Severity is fixed per rule.
* **Read-only and leaves no trace.** Install, scan, export, uninstall. Uninstalling removes the plugin's tables and options.

= What it checks =

* Database: autoload size, largest tables, expired transients, post revisions.
* Plugins: autoload per plugin, pending updates, plugins not from WordPress.org, number of active plugins; optionally, plugins closed or not updated on WordPress.org (off by default, see External services).
* Server: response time (TTFB) measured from your own site, page cache, persistent object cache, PHP version and settings, cron.
* Security basics: HTTPS, security headers, debug output, file editor, administrator accounts, user enumeration, XML-RPC, file permissions, leftover backups and installers reachable from the web, login protection.
* Maintenance: detected backup system, automatic updates, recurring PHP errors.
* WooCommerce (if active): version and outdated template overrides, HPOS, store pages, gateways, webhooks, stuck orders, failed and late scheduled actions, expired sessions.

= Export =

Printable HTML (save as PDF from your browser), CSV and JSON. Exports never include usernames or email addresses.

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

The scan runs in short steps from the Pulso screen, with a progress bar, and does not use WP-Cron. It sends about 40 requests to your own site, which may show up in your server statistics or firewall log.

= Why does a check say "No data"? =

Because Pulso could not get a reliable value (for example, the site cannot reach itself). Pulso does not guess: that check does not count towards the score.

= Does it check for vulnerabilities? =

No. Use a dedicated security plugin for that. Pulso focuses on performance and maintenance cost.

= Is my data sent anywhere? =

Only if you enable the optional WordPress.org check described in External services. Nothing is sent to Inochi.

== Screenshots ==

1. Autoload per plugin, from a single scan.
2. Prioritised report — urgent, stabilise, improve — with the evidence behind every finding.
3. "No data": checks that could not run don't count. We don't invent results.
4. WooCommerce operations: gateways, failing webhooks, stuck orders and failed scheduled actions.
5. Step-by-step read-only scan with progress bar, no WP-Cron.
6. Export to printable HTML, CSV and JSON.

== Changelog ==

= 1.0.0 =
* First public release.
