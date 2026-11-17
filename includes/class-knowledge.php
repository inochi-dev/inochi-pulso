<?php
/**
 * What each finding means for the business, what to do and how to check it.
 *
 * {num} is replaced by the measured amount (without it, the check name is used).
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge base.
 */
class Inochi_Pulso_Knowledge {

	/**
	 * Hours used only to order findings of the same weight (quick fixes first).
	 */
	const HOURS = array(
		'REN-01' => 5,
		'REN-05' => 3,
		'REN-06' => 2,
		'REN-07' => 2,
		'REN-08' => 1,
		'REN-09' => 2,
		'REN-10' => 0.5,
		'REN-11' => 0.5,
		'REN-12' => 1,
		'REN-14' => 0.5,
		'REN-16' => 3,
		'SEG-01' => 4,
		'SEG-03' => 4,
		'SEG-04' => 1,
		'SEG-06' => 2,
		'SEG-07' => 1,
		'SEG-08' => 0.5,
		'SEG-09' => 0.5,
		'SEG-10' => 0.25,
		'SEG-11' => 1,
		'SEG-12' => 0.5,
		'SEG-13' => 0.25,
		'SEG-14' => 0.5,
		'SEG-15' => 0.5,
		'WOO-01' => 3,
		'WOO-02' => 3,
		'WOO-03' => 0.5,
		'WOO-05' => 2,
		'WOO-06' => 1,
		'WOO-07' => 5,
		'WOO-08' => 2,
		'WOO-09' => 0.5,
		'MAN-06' => 1,
		'MAN-07' => 3,
	);

	/**
	 * Hours of a check (for ordering only).
	 *
	 * @param string $code Code.
	 */
	public static function hours( $code ) {
		return (float) ( self::HOURS[ $code ] ?? 4 );
	}

	/**
	 * Texts of every check: title, cost, proposal (lines), verify, target, variants.
	 */
	private static function data() {
		return array(
			'REN-01' => array(
				'title'    => __( 'The server takes {num} to start responding', 'inochi-pulso' ),
				'cost'     => __( 'Every page, cart and checkout included, starts loading late. Visitors wait looking at a blank screen, and Google takes it into account.', 'inochi-pulso' ),
				'proposal' => array( __( 'Measure how much of the time is PHP, database or network on the same 5 pages.', 'inochi-pulso' ), __( 'Fix what weighs most (queries, autoload, slow plugins, cache).', 'inochi-pulso' ), __( 'Check whether the hosting has enough resources for the real traffic.', 'inochi-pulso' ) ),
				'verify'   => __( 'Response time of the same 5 pages before and after.', 'inochi-pulso' ),
				'target'   => __( 'Under 0.6 s', 'inochi-pulso' ),
			),
			'REN-05' => array(
				'title'    => __( 'No page cache: every visit builds the page from scratch', 'inochi-pulso' ),
				'cost'     => __( 'The server repeats the same work on every visit: slower pages, load peaks and a worse position in Google.', 'inochi-pulso' ),
				'proposal' => array( __( 'Turn on page caching (plugin or server), excluding cart, checkout and my account.', 'inochi-pulso' ), __( 'Set up purging when content or stock changes.', 'inochi-pulso' ) ),
				'verify'   => __( 'Cache header showing HIT and home page response time before and after.', 'inochi-pulso' ),
				'target'   => __( 'Pages served from cache (HIT)', 'inochi-pulso' ),
			),
			'REN-06' => array(
				'title'    => __( 'No object cache: the database repeats the same queries', 'inochi-pulso' ),
				'cost'     => __( 'More queries per page and more time in cart and checkout, which page caching cannot serve.', 'inochi-pulso' ),
				'proposal' => array( __( 'Turn on Redis or Memcached in the hosting.', 'inochi-pulso' ), __( 'Install the object cache connector and check that the cart still works.', 'inochi-pulso' ) ),
				'verify'   => __( 'Queries per page and cart response time before and after.', 'inochi-pulso' ),
				'target'   => __( 'Object cache active', 'inochi-pulso' ),
			),
			'REN-07' => array(
				'title'    => __( '{num} of data that WordPress loads on every visit', 'inochi-pulso' ),
				'cost'     => __( 'Every page, cart and checkout included, takes longer to serve. WordPress warns from 0.8 MB.', 'inochi-pulso' ),
				'proposal' => array( __( 'Back up the database before touching anything.', 'inochi-pulso' ), __( 'Look at the cost per plugin table to see which plugin loads the most.', 'inochi-pulso' ), __( 'Delete the options of plugins that are no longer installed.', 'inochi-pulso' ), __( 'Stop autoloading options that only the dashboard uses.', 'inochi-pulso' ) ),
				'verify'   => __( 'Autoload size and response time on the same 5 pages.', 'inochi-pulso' ),
				'target'   => __( 'Under 0.8 MB', 'inochi-pulso' ),
			),
			'REN-08' => array(
				'title'    => __( '{num} of data from inactive or deleted plugins loaded on every visit', 'inochi-pulso' ),
				'cost'     => __( 'Data from plugins that are no longer used is still loaded on every page.', 'inochi-pulso' ),
				'proposal' => array( __( 'Confirm that the owner plugin is no longer needed (see the cost per plugin table).', 'inochi-pulso' ), __( 'Back up the database and delete those options.', 'inochi-pulso' ) ),
				'verify'   => __( 'Autoload from inactive or deleted plugins close to 0 on the next scan.', 'inochi-pulso' ),
				'target'   => __( 'Under 100 KB', 'inochi-pulso' ),
			),
			'REN-09' => array(
				'title'    => __( 'Log tables or leftovers that bloat the database', 'inochi-pulso' ),
				'cost'     => __( 'Slower and heavier backups, longer restores and slower queries.', 'inochi-pulso' ),
				'proposal' => array( __( 'Find the plugin of each large table in the cost per plugin table.', 'inochi-pulso' ), __( 'Empty old logs and drop tables of uninstalled plugins, with a backup first.', 'inochi-pulso' ), __( 'Limit log retention from now on.', 'inochi-pulso' ) ),
				'verify'   => __( 'Size of the database and of the backup before and after.', 'inochi-pulso' ),
				'target'   => __( 'No log table above 100 MB', 'inochi-pulso' ),
			),
			'REN-10' => array(
				'title'    => __( 'Expired temporary data in the database ({num})', 'inochi-pulso' ),
				'cost'     => __( 'It takes space and slows down the options table, which is read on every visit.', 'inochi-pulso' ),
				'proposal' => array( __( 'Delete the expired transients.', 'inochi-pulso' ), __( 'Check that the clean-up cron runs.', 'inochi-pulso' ) ),
				'verify'   => __( 'Number of expired transients on the next scan.', 'inochi-pulso' ),
				'target'   => __( 'Under 500', 'inochi-pulso' ),
			),
			'REN-11' => array(
				'title'    => __( 'Unlimited content revisions ({num})', 'inochi-pulso' ),
				'cost'     => __( 'A bigger database than needed: slower backups and heavier queries.', 'inochi-pulso' ),
				'proposal' => array( __( 'Limit revisions (for example, to 10 per post).', 'inochi-pulso' ), __( 'Delete old revisions, with a backup first.', 'inochi-pulso' ) ),
				'verify'   => __( 'Number of revisions and database size.', 'inochi-pulso' ),
				'target'   => __( 'Limited revisions', 'inochi-pulso' ),
			),
			'REN-12' => array(
				'title'    => __( 'Late or ownerless scheduled tasks', 'inochi-pulso' ),
				'variants' => array(
					'orphans' => array(
						'title'    => __( 'Scheduled tasks from plugins that are no longer active', 'inochi-pulso' ),
						'cost'     => __( 'WordPress keeps trying to run tasks that no longer do anything: useless work on every run and noise that hides the real problems.', 'inochi-pulso' ),
						'proposal' => array( __( 'Delete the orphaned tasks, with a backup first.', 'inochi-pulso' ), __( 'Replace WP-Cron with a server task every 5 minutes.', 'inochi-pulso' ) ),
					),
				),
				'cost'     => __( 'Automatic tasks that do not run on time: emails, backups, clean-ups or syncs delayed without anyone noticing.', 'inochi-pulso' ),
				'proposal' => array( __( 'Replace WP-Cron with a server task every 5 minutes.', 'inochi-pulso' ), __( 'Delete the tasks of uninstalled plugins.', 'inochi-pulso' ) ),
				'verify'   => __( 'No events more than 1 hour late on the next scan.', 'inochi-pulso' ),
				'target'   => __( 'No late or orphaned tasks, with a real server cron', 'inochi-pulso' ),
			),
			'REN-14' => array(
				'title'    => __( 'PHP settings below the recommended values', 'inochi-pulso' ),
				'cost'     => __( 'Memory errors in heavy processes (imports, large orders) and slower PHP without OPcache.', 'inochi-pulso' ),
				'proposal' => array( __( 'Raise memory_limit to 256M or more.', 'inochi-pulso' ), __( 'Turn on OPcache in the hosting.', 'inochi-pulso' ) ),
				'verify'   => __( 'Values in Site Health → Info → Server.', 'inochi-pulso' ),
				'target'   => __( '256 MB or more and OPcache active', 'inochi-pulso' ),
			),
			'REN-16' => array(
				'title'    => __( 'Too many active plugins ({num}): many parts to maintain', 'inochi-pulso' ),
				'cost'     => __( 'Every plugin adds code, queries and risk. Above 40 it is hard to know what each one does and which one fails.', 'inochi-pulso' ),
				'proposal' => array( __( 'List what each plugin is for.', 'inochi-pulso' ), __( 'Remove the ones that are not used or duplicate features.', 'inochi-pulso' ) ),
				'verify'   => __( 'Number of active plugins and response time.', 'inochi-pulso' ),
				'target'   => __( '40 or fewer, each with a clear purpose', 'inochi-pulso' ),
			),
			'SEG-01' => array(
				'title'    => __( 'Pending updates in core, plugins or theme ({num})', 'inochi-pulso' ),
				'cost'     => __( 'Old versions pile up known bugs and vulnerabilities. The longer you wait, the riskier it is to update everything at once.', 'inochi-pulso' ),
				'proposal' => array( __( 'Full backup and a test on a staging site.', 'inochi-pulso' ), __( 'Update minor versions first and then major ones, one at a time.', 'inochi-pulso' ), __( 'Check forms, cart and checkout after each update.', 'inochi-pulso' ) ),
				'verify'   => __( 'Dashboard → Updates with nothing pending.', 'inochi-pulso' ),
				'target'   => __( 'Everything up to date', 'inochi-pulso' ),
			),
			'SEG-03' => array(
				'title'    => __( 'Plugins abandoned or removed from WordPress.org', 'inochi-pulso' ),
				'cost'     => __( 'Nobody fixes their bugs. If a vulnerability appears there will be no patch, and they tend to stop working with new WordPress versions.', 'inochi-pulso' ),
				'proposal' => array( __( 'Find a maintained alternative for each one.', 'inochi-pulso' ), __( 'Replace them on a staging site and migrate their data.', 'inochi-pulso' ) ),
				'verify'   => __( 'No plugin closed or without updates for more than 2 years.', 'inochi-pulso' ),
				'target'   => __( 'No closed or abandoned plugins', 'inochi-pulso' ),
			),
			'SEG-04' => array(
				'title'    => __( 'Premium or custom plugins without guaranteed updates', 'inochi-pulso' ),
				'cost'     => __( 'Without an active licence, security patches do not arrive. A plugin of doubtful origin can bring malicious code.', 'inochi-pulso' ),
				'proposal' => array( __( 'Renew the licences of the premium plugins in use.', 'inochi-pulso' ), __( 'Replace any copy of doubtful origin with the official one.', 'inochi-pulso' ) ),
				'verify'   => __( 'Active licence and current version for each premium plugin.', 'inochi-pulso' ),
				'target'   => __( 'All with an active licence and updates', 'inochi-pulso' ),
			),
			'SEG-06' => array(
				'title'    => __( 'The PHP version is losing or has lost security support', 'inochi-pulso' ),
				'cost'     => __( 'PHP security bugs are no longer fixed. New plugins stop being compatible.', 'inochi-pulso' ),
				'proposal' => array( __( 'Test the site with a current PHP version on a staging site.', 'inochi-pulso' ), __( 'Change the version in the hosting panel and check the error log.', 'inochi-pulso' ) ),
				'verify'   => __( 'A PHP version with security support and no new errors in the log.', 'inochi-pulso' ),
				'target'   => __( 'PHP 8.3 or later', 'inochi-pulso' ),
			),
			'SEG-07' => array(
				'title'    => __( 'Problems with the secure connection (HTTPS)', 'inochi-pulso' ),
				'cost'     => __( '"Not secure" warnings in the browser, exposed forms and payments, and lost trust.', 'inochi-pulso' ),
				'proposal' => array( __( 'Force HTTPS on the whole site.', 'inochi-pulso' ), __( 'Fix the resources loaded over http.', 'inochi-pulso' ), __( 'Turn on HSTS once everything works over HTTPS.', 'inochi-pulso' ) ),
				'verify'   => __( 'Redirect, certificate and a browser console without warnings.', 'inochi-pulso' ),
				'target'   => __( 'Full HTTPS, with HSTS and no mixed content', 'inochi-pulso' ),
			),
			'SEG-08' => array(
				'title'    => __( 'Basic security headers are missing', 'inochi-pulso' ),
				'cost'     => __( 'The site is more exposed to attacks that embed it in other sites or abuse the visitor’s browser.', 'inochi-pulso' ),
				'proposal' => array( __( 'Add X-Content-Type-Options, X-Frame-Options and Referrer-Policy in the server or the CDN.', 'inochi-pulso' ) ),
				'verify'   => __( 'Headers present in the home page response.', 'inochi-pulso' ),
				'target'   => __( 'The 3 basic headers', 'inochi-pulso' ),
			),
			'SEG-09' => array(
				'title'    => __( 'Error information exposed to the public', 'inochi-pulso' ),
				'cost'     => __( 'Errors show paths, versions and internal data that make an attack easier. The log can include customer data.', 'inochi-pulso' ),
				'proposal' => array( __( 'Turn off error display in production.', 'inochi-pulso' ), __( 'Move the log out of the public folder or block access to it.', 'inochi-pulso' ) ),
				'verify'   => __( 'No errors on screen and debug.log not downloadable.', 'inochi-pulso' ),
				'target'   => __( 'Errors hidden and log unreachable', 'inochi-pulso' ),
			),
			'SEG-10' => array(
				'title'    => __( 'The dashboard can edit the site’s code', 'inochi-pulso' ),
				'cost'     => __( 'Anyone who gets into an administrator account can inject code in seconds without server access.', 'inochi-pulso' ),
				'proposal' => array( __( 'Add DISALLOW_FILE_EDIT to wp-config.php.', 'inochi-pulso' ) ),
				'verify'   => __( 'The file editor no longer appears in the dashboard.', 'inochi-pulso' ),
				'target'   => __( 'Editor turned off', 'inochi-pulso' ),
			),
			'SEG-11' => array(
				'title'    => __( 'Administrator accounts without enough protection', 'inochi-pulso' ),
				'cost'     => __( 'A stolen password gives full control of the site. Accounts without a known owner are doors nobody watches.', 'inochi-pulso' ),
				'proposal' => array( __( 'Delete or downgrade the accounts that are not used.', 'inochi-pulso' ), __( 'Turn on two-factor authentication for every administrator.', 'inochi-pulso' ), __( 'Rename or delete the "admin" account.', 'inochi-pulso' ) ),
				'verify'   => __( 'Number of administrators and two-factor authentication active for all.', 'inochi-pulso' ),
				'target'   => __( 'At most 3 administrators, all with an owner and two-factor authentication', 'inochi-pulso' ),
			),
			'SEG-12' => array(
				'title'    => __( 'The site publishes its usernames', 'inochi-pulso' ),
				'cost'     => __( 'It helps brute-force attacks: the attacker already has half of the credentials.', 'inochi-pulso' ),
				'proposal' => array( __( 'Limit the REST API user list to logged-in users.', 'inochi-pulso' ), __( 'Block the ?author= redirect.', 'inochi-pulso' ) ),
				'verify'   => __( 'Neither request returns names.', 'inochi-pulso' ),
				'target'   => __( 'No public username', 'inochi-pulso' ),
			),
			'SEG-13' => array(
				'title'    => __( 'XML-RPC open but not used', 'inochi-pulso' ),
				'cost'     => __( 'It is a common way in for mass brute-force attacks and server overload.', 'inochi-pulso' ),
				'proposal' => array( __( 'Block xmlrpc.php in the server or the CDN if nothing needs it.', 'inochi-pulso' ) ),
				'verify'   => __( 'xmlrpc.php answers 403.', 'inochi-pulso' ),
				'target'   => __( 'XML-RPC blocked or protected', 'inochi-pulso' ),
			),
			'SEG-14' => array(
				'title'    => __( 'File permissions too open', 'inochi-pulso' ),
				'cost'     => __( 'Other users of the server or a compromised process can read passwords or change files.', 'inochi-pulso' ),
				'proposal' => array( __( 'wp-config.php to 600 or 640.', 'inochi-pulso' ), __( 'Folders to 755 and files to 644.', 'inochi-pulso' ) ),
				'verify'   => __( 'No world-writable files.', 'inochi-pulso' ),
				'target'   => __( 'wp-config.php at 600 or 640 and nothing world-writable', 'inochi-pulso' ),
			),
			'SEG-15' => array(
				'title'    => __( 'Leftover files reachable from the web', 'inochi-pulso' ),
				'cost'     => __( 'A downloadable database dump exposes all customer data and passwords. A forgotten installer lets anyone take over the site.', 'inochi-pulso' ),
				'proposal' => array( __( 'Delete the leftover files today.', 'inochi-pulso' ), __( 'Check the access logs in case someone downloaded them.', 'inochi-pulso' ) ),
				'verify'   => __( 'The files no longer exist or answer from the web.', 'inochi-pulso' ),
				'target'   => __( 'No leftover files', 'inochi-pulso' ),
			),
			'WOO-01' => array(
				'title'    => __( 'Outdated WooCommerce or templates', 'inochi-pulso' ),
				'cost'     => __( 'Old theme templates can break cart or checkout after an update, or keep bugs that are already fixed.', 'inochi-pulso' ),
				'proposal' => array( __( 'Update the theme’s overridden templates to the current version.', 'inochi-pulso' ), __( 'Update WooCommerce on a staging site and test the purchase process.', 'inochi-pulso' ) ),
				'verify'   => __( 'System status without outdated templates.', 'inochi-pulso' ),
				'target'   => __( 'WooCommerce and templates up to date', 'inochi-pulso' ),
			),
			'WOO-02' => array(
				'title'    => __( 'Orders do not use WooCommerce’s modern storage (HPOS)', 'inochi-pulso' ),
				'variants' => array(
					'pending_sync' => array(
						'title'    => __( 'Orders pending sync in WooCommerce', 'inochi-pulso' ),
						'cost'     => __( 'The two copies of the orders do not match: if you had to go back to the old storage, orders would be missing.', 'inochi-pulso' ),
						'proposal' => array( __( 'Let the sync finish, or turn it off if it is no longer needed.', 'inochi-pulso' ) ),
						'verify'   => __( 'No orders pending sync.', 'inochi-pulso' ),
					),
				),
				'cost'     => __( 'Order queries get slower as the store grows, and future compatibility is lower.', 'inochi-pulso' ),
				'proposal' => array( __( 'Check plugin compatibility.', 'inochi-pulso' ), __( 'Turn on sync, migrate and switch to HPOS.', 'inochi-pulso' ) ),
				'verify'   => __( 'HPOS active and no orders pending sync.', 'inochi-pulso' ),
				'target'   => __( 'HPOS active and no orders pending', 'inochi-pulso' ),
			),
			'WOO-03' => array(
				'title'    => __( 'A basic store page is missing', 'inochi-pulso' ),
				'cost'     => __( 'A cart, checkout or account page that fails stops people from buying.', 'inochi-pulso' ),
				'proposal' => array( __( 'Assign and publish the right page in the WooCommerce settings.', 'inochi-pulso' ) ),
				'verify'   => __( 'The 4 pages assigned and a full test order.', 'inochi-pulso' ),
				'target'   => __( 'The 4 pages assigned and published', 'inochi-pulso' ),
			),
			'WOO-05' => array(
				'title'    => __( 'Automatic notifications to other systems are failing', 'inochi-pulso' ),
				'cost'     => __( 'Orders that do not reach the ERP, the warehouse or invoicing without anyone noticing.', 'inochi-pulso' ),
				'proposal' => array( __( 'Check the destination of each failing webhook.', 'inochi-pulso' ), __( 'Turn it back on and resend the orders that did not arrive.', 'inochi-pulso' ) ),
				'verify'   => __( 'Webhooks active and without failures for a week.', 'inochi-pulso' ),
				'target'   => __( 'Webhooks without failures', 'inochi-pulso' ),
			),
			'WOO-06' => array(
				'title'    => __( 'Orders not closed for more than a week', 'inochi-pulso' ),
				'cost'     => __( 'Orders nobody finishes handling: customers waiting for an answer or a delivery, and sales and stock figures that do not add up.', 'inochi-pulso' ),
				'proposal' => array( __( 'Confirm with the team whether this is their normal way of working.', 'inochi-pulso' ), __( 'Close the orders already resolved.', 'inochi-pulso' ), __( 'Fix the step of the process that leaves them stuck.', 'inochi-pulso' ) ),
				'verify'   => __( 'No orders processing or on hold for more than 7 days.', 'inochi-pulso' ),
				'target'   => __( 'No order open for more than 7 days', 'inochi-pulso' ),
			),
			'WOO-07' => array(
				'title'    => __( 'Automatic processes failing ({num} in 30 days)', 'inochi-pulso' ),
				'cost'     => __( 'Automatic processes that do not finish: outdated stock, emails not sent or incomplete syncs.', 'inochi-pulso' ),
				'proposal' => array( __( 'Read the failure reason of each type of action.', 'inochi-pulso' ), __( 'Fix the cause and run the pending actions again.', 'inochi-pulso' ) ),
				'verify'   => __( 'Failed actions over the next 30 days.', 'inochi-pulso' ),
				'target'   => __( 'Fewer than 10 failures a month', 'inochi-pulso' ),
			),
			'WOO-08' => array(
				'title'    => __( 'Automatic processes running late ({num})', 'inochi-pulso' ),
				'cost'     => __( 'The task queue is not moving: store processes run late or not at all.', 'inochi-pulso' ),
				'proposal' => array( __( 'Check that the server cron works.', 'inochi-pulso' ), __( 'Unblock the queue and watch it for a week.', 'inochi-pulso' ) ),
				'verify'   => __( 'No actions more than 1 hour late.', 'inochi-pulso' ),
				'target'   => __( 'Fewer than 10 late actions', 'inochi-pulso' ),
			),
			'WOO-09' => array(
				'title'    => __( 'Expired shopping sessions piling up ({num})', 'inochi-pulso' ),
				'cost'     => __( 'A table that grows without control and slows down the cart.', 'inochi-pulso' ),
				'proposal' => array( __( 'Clean up the expired sessions.', 'inochi-pulso' ), __( 'Check that the automatic clean-up works.', 'inochi-pulso' ) ),
				'verify'   => __( 'Expired sessions below 10,000.', 'inochi-pulso' ),
				'target'   => __( 'Fewer than 10,000', 'inochi-pulso' ),
			),
			'MAN-06' => array(
				'title'    => __( 'Updates without a procedure', 'inochi-pulso' ),
				'cost'     => __( 'A plugin that updates itself can break forms or checkout without anyone checking.', 'inochi-pulso' ),
				'proposal' => array( __( 'Turn off automatic updates for critical plugins.', 'inochi-pulso' ), __( 'Procedure: backup, test, update and check.', 'inochi-pulso' ) ),
				'verify'   => __( 'Procedure written down and applied at the next update.', 'inochi-pulso' ),
				'target'   => __( 'Written update procedure', 'inochi-pulso' ),
			),
			'MAN-07' => array(
				'title'    => __( 'Serious PHP errors in the last 7 days ({num})', 'inochi-pulso' ),
				'cost'     => __( 'Pages or processes that fail for some visitors without anyone seeing it.', 'inochi-pulso' ),
				'proposal' => array( __( 'Find the plugin or template behind each error.', 'inochi-pulso' ), __( 'Fix it or replace it.', 'inochi-pulso' ) ),
				'verify'   => __( 'Error log without fatal errors for 7 days.', 'inochi-pulso' ),
				'target'   => __( 'No fatal errors', 'inochi-pulso' ),
			),
		);
	}

	/**
	 * Finding texts.
	 *
	 * @param string $code    Code.
	 * @param string $state   State (Fallo or Aviso).
	 * @param string $value   Measured value.
	 * @param string $variant Variant key set by the check, if any.
	 * @return array|null title, cost, proposal (text with lines), verify, target.
	 */
	public static function finding( $code, $state, $value = '', $variant = '' ) {
		$d = self::data();
		if ( ! isset( $d[ $code ] ) ) {
			return null;
		}
		$t = $d[ $code ];
		if ( '' !== $variant && isset( $t['variants'][ $variant ] ) ) {
			$t = array_merge( $t, $t['variants'][ $variant ] );
		}
		$num   = self::leading_number( $value );
		$title = $t['title'];
		if ( false !== strpos( $title, '{num}' ) ) {
			if ( '' === $num ) {
				$c     = Inochi_Pulso_Catalog::get( $code );
				$title = $c ? $c['name'] : $code;
			} else {
				$title = str_replace( '{num}', $num, $title );
			}
		}
		return array(
			'title'    => self::sentence( $title ),
			'cost'     => $t['cost'],
			'proposal' => implode( "\n", $t['proposal'] ),
			'verify'   => $t['verify'],
			'target'   => $t['target'] ?? '',
			'state'    => $state,
		);
	}

	/**
	 * Amount with its unit at the start of a value ("1.70 s (…)" → "1.70 s").
	 *
	 * @param string $value Value.
	 */
	public static function leading_number( $value ) {
		return preg_match( '/^\s*([0-9][0-9.,\s\x{00A0}\x{202F}]*?\s?(?:ms|s|MB|KB|GB|B|%)?)(?=\s|$|\))/u', (string) $value, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * First letter in upper case.
	 *
	 * @param string $text Text.
	 */
	private static function sentence( $text ) {
		if ( '' === $text ) {
			return $text;
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 ) : ucfirst( $text );
	}
}
