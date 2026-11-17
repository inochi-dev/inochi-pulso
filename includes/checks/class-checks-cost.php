<?php
/**
 * Cost per plugin: autoload, own tables, cron events and scheduled actions, per plugin.
 *
 * Also produces REN-08 (autoload from inactive or deleted plugins) and REN-12 (cron).
 *
 * Attribution rules (no guessing):
 * - A hook with registered callbacks belongs to the code that registered them (by file path).
 * - Otherwise, a name (option, table, hook) belongs to the code that contains it literally.
 * - When several plugins contain it, it belongs to the one whose folder name starts the item
 *   name (woocommerce_* → WooCommerce); if that does not settle it, it goes to "Unattributed".
 * - A name that no installed code contains goes to "Leftovers" only when the search finished;
 *   if it was cut short by the time or size limit, it goes to "Unattributed".
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cost per plugin.
 */
class Inochi_Pulso_Checks_Cost extends Inochi_Pulso_Checks_Base {

	const MAX_BYTES   = 314572800; // 300 MB of code at most.
	const MAX_SECONDS = 25;
	const ITEMS       = 15; // Items kept per row and column.

	const KEY_CORE         = 'core';
	const KEY_LEFTOVERS    = 'leftovers';
	const KEY_UNATTRIBUTED = 'unattributed';
	const KEY_SHARED_AS    = 'shared:action-scheduler';

	/**
	 * Prefixes too generic to attribute a built name.
	 */
	const GENERIC_PREF = array( 'wp_', 'the_', 'my_', 'site_', 'widget_', 'theme_', 'user_', 'db_', 'auto_', 'default_', 'page_', 'post_', 'use_', 'show_', 'can_', 'new_', 'old_', 'last_', 'cache_', 'get_', 'set_', 'is_', 'has_', 'option_', 'options_', 'settings_', 'data_', 'plugin_', 'plugins_', 'admin_', 'transient_' );

	/**
	 * WordPress core tables (without prefix). Content tables: their size is not a plugin cost.
	 */
	const CORE_TABLES = array( 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'options', 'links', 'users', 'usermeta', 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'blog_versions' );

	/**
	 * Runs the step.
	 *
	 * @param array $payload Data gathered by earlier steps.
	 */
	public static function run( array $payload = array() ) {
		Inochi_Pulso_Util::extend_time( 120 );
		$plugins = Inochi_Pulso_Util::plugins();
		$items   = array_merge( self::autoload_items(), self::table_items( (array) ( $payload['tables'] ?? array() ) ), self::cron_items(), self::action_items() );

		// 1. Hooks with callbacks: owner by the file of the callback. Core functions used as
		// callbacks (__return_true…) say nothing about the owner: the name search decides then.
		foreach ( $items as $i => $item ) {
			if ( ( 'cron' === $item['type'] || 'action' === $item['type'] ) && empty( $item['preset'] ) ) {
				list( $any, $owners )    = self::callback_owners( $item['name'] );
				$items[ $i ]['callback'] = $any;
				if ( $owners ) {
					$items[ $i ]['owner'] = self::decide( $owners, $item['name'], $plugins );
					$items[ $i ]['how']   = 'callback';
				}
			}
		}

		// 2. Search the installed code for the rest.
		$needles = array();
		foreach ( $items as $item ) {
			if ( empty( $item['owner'] ) && empty( $item['preset'] ) ) {
				$needles[ $item['needle'] ] = true;
			}
		}
		list( $full, $built, $complete ) = self::search( array_keys( $needles ) );

		foreach ( $items as $i => $item ) {
			if ( ! empty( $item['owner'] ) ) {
				continue;
			}
			if ( ! empty( $item['preset'] ) ) {
				$items[ $i ]['owner'] = $item['preset'];
				$items[ $i ]['how']   = $item['how'] ?? 'rule';
				continue;
			}
			$needle = $item['needle'];
			if ( ! empty( $full[ $needle ] ) ) {
				$items[ $i ]['owner'] = self::decide( array_keys( $full[ $needle ] ), $needle, $plugins );
				$items[ $i ]['how']   = 'name';
			} elseif ( ! empty( $built[ $needle ] ) ) {
				$items[ $i ]['owner'] = self::decide( array_keys( $built[ $needle ] ), $needle, $plugins );
				$items[ $i ]['how']   = 'prefix';
			} else {
				$items[ $i ]['owner'] = $complete ? self::KEY_LEFTOVERS : self::KEY_UNATTRIBUTED;
				$items[ $i ]['how']   = $complete ? 'none' : 'incomplete';
			}
		}

		$rows = self::rows( $items, $plugins );

		return array(
			'findings' => array( self::ren08( $rows, $complete ), self::ren12( $items ) ),
			'payload'  => array(
				'cost'          => array_values( $rows ),
				'cost_complete' => $complete,
			),
		);
	}

	// ------------------------------------------------------------------ Items.

	/**
	 * Every autoloaded option, by size.
	 */
	private static function autoload_items() {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name AS name, LENGTH(option_value) AS bytes FROM %i WHERE autoload IN (' . Inochi_Pulso_Checks_Database::autoload_placeholders() . ') ORDER BY bytes DESC', array_merge( array( $wpdb->options ), Inochi_Pulso_Checks_Database::autoload_values() ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only scan; generated %s placeholders, one per autoload value.
		$items = array();
		foreach ( (array) $rows as $r ) {
			$name = (string) $r['name'];
			if ( self::is_own( $name ) ) {
				continue;
			}
			$item   = array(
				'type'   => 'option',
				'name'   => $name,
				'bytes'  => (int) $r['bytes'],
				'needle' => $name,
			);
			$widget = 0 === strpos( $name, 'widget_' ) ? self::widget_owner( substr( $name, 7 ) ) : '';
			if ( $name === $wpdb->prefix . 'user_roles' ) {
				$item['preset'] = self::KEY_CORE;
			} elseif ( '' !== $widget ) {
				$item['preset'] = $widget; // Owner of the registered widget class.
				$item['how']    = 'widget';
			} elseif ( 0 === strpos( $name, 'theme_mods_' ) ) {
				$item['preset'] = 'theme:' . substr( $name, 11 );
			} elseif ( preg_match( '/^_(?:site_)?transient_(?:timeout_)?(.+)$/', $name, $m ) ) {
				$item['needle'] = $m[1]; // Transients are attributed by their own name.
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Tables with the WordPress prefix (from the database step).
	 *
	 * @param array $tables Rows: name, bytes, rows.
	 */
	private static function table_items( array $tables ) {
		global $wpdb;
		$items = array();
		foreach ( $tables as $t ) {
			$name  = (string) ( $t['name'] ?? '' );
			$short = substr( $name, strlen( $wpdb->prefix ) );
			if ( is_multisite() ) {
				$short = (string) preg_replace( '/^\d+_/', '', $short );
			}
			if ( '' === $short || self::is_own( $short ) ) {
				continue;
			}
			$item = array(
				'type'   => 'table',
				'name'   => $name,
				'bytes'  => (int) ( $t['bytes'] ?? 0 ),
				'rows'   => (int) ( $t['rows'] ?? 0 ),
				'needle' => $short,
			);
			if ( in_array( $short, self::CORE_TABLES, true ) ) {
				$item['preset'] = self::KEY_CORE;
			} elseif ( 0 === strpos( $short, 'actionscheduler_' ) ) {
				$item['preset'] = self::KEY_SHARED_AS; // Library bundled by several plugins.
				$item['how']    = 'shared';
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Cron events (one item per hook).
	 */
	private static function cron_items() {
		$crons = function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array();
		$now   = time();
		$hooks = array();
		foreach ( $crons as $ts => $list ) {
			foreach ( (array) $list as $hook => $events ) {
				if ( self::is_own( $hook ) ) {
					continue;
				}
				if ( ! isset( $hooks[ $hook ] ) ) {
					$hooks[ $hook ] = array(
						'type'   => 'cron',
						'name'   => (string) $hook,
						'needle' => (string) $hook,
						'events' => 0,
						'late'   => 0,
					);
				}
				$hooks[ $hook ]['events'] += count( (array) $events );
				if ( (int) $ts < $now - HOUR_IN_SECONDS ) {
					$hooks[ $hook ]['late'] = max( $hooks[ $hook ]['late'], $now - (int) $ts );
				}
			}
		}
		return array_values( $hooks );
	}

	/**
	 * Failed (30 days) or late (> 1 h) scheduled actions, by hook and group.
	 */
	private static function action_items() {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
			return array();
		}
		$has_groups = $groups === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $groups ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
		$month      = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$hour       = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only scan.
		if ( $has_groups ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.hook AS hook, g.slug AS grp,
						SUM(CASE WHEN a.status = 'failed' AND a.last_attempt_gmt > %s THEN 1 ELSE 0 END) AS failed,
						SUM(CASE WHEN a.status = 'pending' AND a.scheduled_date_gmt < %s THEN 1 ELSE 0 END) AS late
					FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id
					WHERE ( a.status = 'failed' AND a.last_attempt_gmt > %s ) OR ( a.status = 'pending' AND a.scheduled_date_gmt < %s )
					GROUP BY a.hook, g.slug",
					$month,
					$hour,
					$actions,
					$groups,
					$month,
					$hour
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.hook AS hook, '' AS grp,
						SUM(CASE WHEN a.status = 'failed' AND a.last_attempt_gmt > %s THEN 1 ELSE 0 END) AS failed,
						SUM(CASE WHEN a.status = 'pending' AND a.scheduled_date_gmt < %s THEN 1 ELSE 0 END) AS late
					FROM %i a
					WHERE ( a.status = 'failed' AND a.last_attempt_gmt > %s ) OR ( a.status = 'pending' AND a.scheduled_date_gmt < %s )
					GROUP BY a.hook",
					$month,
					$hour,
					$actions,
					$month,
					$hour
				),
				ARRAY_A
			);
		}
		// phpcs:enable
		$items = array();
		foreach ( (array) $rows as $r ) {
			$hook = (string) $r['hook'];
			if ( self::is_own( $hook ) ) {
				continue;
			}
			$item  = array(
				'type'   => 'action',
				'name'   => $hook,
				'group'  => (string) $r['grp'],
				'needle' => $hook,
				'failed' => (int) $r['failed'],
				'late'   => (int) $r['late'],
			);
			$group = (string) $r['grp'];
			if ( '' !== $group && isset( Inochi_Pulso_Util::plugins()[ $group ] ) ) {
				$item['preset'] = 'plugin:' . $group; // Group named exactly like a plugin folder.
				$item['how']    = 'group';
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Items that belong to Pulso itself.
	 *
	 * @param string $name Option, table or hook name.
	 */
	private static function is_own( $name ) {
		return 0 === strpos( (string) $name, 'inochi_pulso' );
	}

	/**
	 * Owner of a registered widget, by the file of its class.
	 *
	 * @param string $id_base Widget id_base.
	 */
	private static function widget_owner( $id_base ) {
		global $wp_widget_factory;
		if ( ! is_object( $wp_widget_factory ) || empty( $wp_widget_factory->widgets ) ) {
			return '';
		}
		foreach ( $wp_widget_factory->widgets as $widget ) {
			if ( is_object( $widget ) && isset( $widget->id_base ) && $widget->id_base === $id_base ) {
				try {
					$ref = new ReflectionClass( $widget );
					return self::owner_of_file( (string) $ref->getFileName() );
				} catch ( Throwable $e ) {
					return '';
				}
			}
		}
		return '';
	}

	// ------------------------------------------------------------------ Attribution.

	/**
	 * Callbacks registered on a hook: whether there is any, and the owners of the non-core ones
	 * (plugin:slug, mu:file, theme:slug).
	 *
	 * @param string $hook Hook.
	 * @return array{0: bool, 1: string[]}
	 */
	private static function callback_owners( $hook ) {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
			return array( false, array() );
		}
		$owners = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $cb ) {
				$owner = self::owner_of_file( self::callback_file( $cb['function'] ?? null ) );
				if ( $owner && self::KEY_CORE !== $owner ) {
					$owners[ $owner ] = true;
				}
			}
		}
		return array( true, array_keys( $owners ) );
	}

	/**
	 * File where a callback is defined.
	 *
	 * @param mixed $fn Callback.
	 */
	private static function callback_file( $fn ) {
		try {
			if ( is_string( $fn ) && false !== strpos( $fn, '::' ) ) {
				$fn = explode( '::', $fn, 2 );
			}
			if ( is_array( $fn ) && 2 === count( $fn ) ) {
				$ref = new ReflectionMethod( $fn[0], $fn[1] );
			} elseif ( is_object( $fn ) && ! ( $fn instanceof Closure ) ) {
				$ref = new ReflectionMethod( $fn, '__invoke' );
			} elseif ( is_string( $fn ) || $fn instanceof Closure ) {
				$ref = new ReflectionFunction( $fn );
			} else {
				return '';
			}
			return (string) $ref->getFileName();
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Owner key of a file path.
	 *
	 * @param string $file Absolute path.
	 */
	private static function owner_of_file( $file ) {
		if ( '' === (string) $file ) {
			return '';
		}
		$file = wp_normalize_path( $file );
		$map  = array(
			wp_normalize_path( WP_PLUGIN_DIR ) . '/'    => 'plugin',
			wp_normalize_path( WPMU_PLUGIN_DIR ) . '/'  => 'mu',
			wp_normalize_path( get_theme_root() ) . '/' => 'theme',
			wp_normalize_path( ABSPATH . WPINC ) . '/'  => 'core',
			wp_normalize_path( ABSPATH . 'wp-admin' ) . '/' => 'core',
		);
		foreach ( $map as $root => $kind ) {
			if ( 0 !== strpos( $file, $root ) ) {
				continue;
			}
			if ( 'core' === $kind ) {
				return self::KEY_CORE;
			}
			$rest  = substr( $file, strlen( $root ) );
			$first = explode( '/', $rest )[0];
			if ( 'plugin' === $kind ) {
				return 'plugin:' . ( false !== strpos( $rest, '/' ) ? $first : basename( $first, '.php' ) );
			}
			return 'mu' === $kind ? 'mu:' . $first : 'theme:' . $first;
		}
		return '';
	}

	/**
	 * Picks an owner among candidates, or "Unattributed" when it is not clear.
	 *
	 * @param string[] $owners  Candidate owner keys.
	 * @param string   $name    Item name.
	 * @param array    $plugins Installed plugins.
	 */
	private static function decide( array $owners, $name, array $plugins ) {
		$self   = 'plugin:' . basename( dirname( INOCHI_PULSO_FILE ) );
		$owners = array_values( array_diff( array_unique( $owners ), array( $self ) ) );
		if ( 1 === count( $owners ) ) {
			return $owners[0];
		}
		if ( ! $owners ) {
			return self::KEY_UNATTRIBUTED;
		}
		// Several candidates: the one whose folder name starts the item name.
		$lower = strtolower( (string) $name );
		$hits  = array();
		foreach ( $owners as $o ) {
			if ( self::KEY_CORE === $o ) {
				continue;
			}
			$slug = strtolower( str_replace( '-', '_', (string) substr( $o, strpos( $o, ':' ) + 1 ) ) );
			$slug = (string) preg_replace( '/\.php$/', '', $slug );
			if ( strlen( $slug ) >= 3 && 0 === strpos( str_replace( '-', '_', $lower ), $slug ) ) {
				$next = substr( str_replace( '-', '_', $lower ), strlen( $slug ), 1 );
				if ( '' === $next || '_' === $next ) {
					$hits[] = $o;
				}
			}
		}
		if ( 1 === count( $hits ) ) {
			return $hits[0];
		}
		if ( in_array( self::KEY_CORE, $owners, true ) && ! $hits ) {
			return self::KEY_CORE; // Core and some plugins use the same name: it is a core name.
		}
		return self::KEY_UNATTRIBUTED;
	}

	// ------------------------------------------------------------------ Code search.

	/**
	 * Code to search: path => owner key. Pulso itself is skipped.
	 */
	private static function sources() {
		$src  = array(
			ABSPATH . WPINC      => self::KEY_CORE,
			ABSPATH . 'wp-admin' => self::KEY_CORE,
		);
		$self = basename( dirname( INOCHI_PULSO_FILE ) );
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( basename( $dir ) !== $self ) {
				$src[ $dir ] = 'plugin:' . basename( $dir );
			}
		}
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*.php' ) as $file ) {
			$src[ $file ] = 'plugin:' . basename( $file, '.php' );
		}
		foreach ( (array) glob( WPMU_PLUGIN_DIR . '/*' ) as $path ) {
			if ( is_dir( $path ) || '.php' === substr( $path, -4 ) ) {
				$src[ $path ] = 'mu:' . basename( $path );
			}
		}
		foreach ( (array) glob( get_theme_root() . '/*', GLOB_ONLYDIR ) as $dir ) {
			$src[ $dir ] = 'theme:' . basename( $dir );
		}
		return $src;
	}

	/**
	 * Looks for each name in the installed code, owner by owner.
	 *
	 * @param string[] $needles Names.
	 * @return array{0: array, 1: array, 2: bool} full matches [name => [owner => true]], built-name matches, whether the search finished.
	 */
	private static function search( array $needles ) {
		$full  = array();
		$built = array();
		if ( ! $needles ) {
			return array( $full, $built, true );
		}
		$odd = array(); // Names with characters outside the token pattern: plain substring search.
		foreach ( $needles as $n ) {
			if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $n ) ) {
				$odd[] = $n;
			}
		}
		$start = microtime( true );
		$read  = 0;
		foreach ( self::sources() as $path => $owner ) {
			$tokens = array();
			$pref   = array();
			$files  = is_file( $path ) ? array( $path ) : self::php_files( $path, $start + self::MAX_SECONDS );
			foreach ( $files as $file ) {
				if ( ( microtime( true ) - $start ) > self::MAX_SECONDS || $read > self::MAX_BYTES ) {
					return array( $full, $built, false );
				}
				$read += self::tokens( $file, $tokens, $pref, $odd, $full, $owner );
			}
			foreach ( $needles as $n ) {
				if ( isset( $tokens[ $n ] ) ) {
					$full[ $n ][ $owner ] = true;
				} elseif ( $pref && empty( $full[ $n ] ) ) {
					$p = self::built_prefix( $n, $pref );
					if ( '' !== $p ) {
						$built[ $n ][ $owner ] = $p;
					}
				}
			}
		}
		// A full match anywhere beats a built-name match.
		foreach ( array_keys( $built ) as $n ) {
			if ( ! empty( $full[ $n ] ) ) {
				unset( $built[ $n ] );
			}
		}
		return array( $full, $built, ( microtime( true ) - $start ) <= self::MAX_SECONDS );
	}

	/**
	 * Adds the tokens of a PHP file to a set. Optionally collects built-name prefixes
	 * ('prefix_' . $x or "prefix_{$x}") and plain substring matches for odd names.
	 *
	 * @param string $file   File.
	 * @param array  $tokens Token set (by reference).
	 * @param array  $pref   Prefix set (by reference).
	 * @param array  $odd    Odd names.
	 * @param array  $full   Full matches (by reference), for odd names.
	 * @param string $owner  Owner key.
	 * @return int Bytes read.
	 */
	private static function tokens( $file, array &$tokens, array &$pref, array $odd, array &$full, $owner ) {
		$content = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read-only scan.
		if ( false === $content || '' === $content ) {
			return 0;
		}
		if ( preg_match_all( '/[A-Za-z0-9_\-]{3,}/', $content, $m ) ) {
			$tokens += array_flip( $m[0] );
		}
		if ( preg_match_all( '/([\'"])([A-Za-z0-9_\-]{4,})\1\s*\.|"([A-Za-z0-9_\-]{4,})\{?\$/', $content, $m ) ) {
			foreach ( array_merge( $m[2], $m[3] ) as $p ) {
				if ( '' !== $p && preg_match( '/[_\-]$/', $p ) && ! in_array( strtolower( $p ), self::GENERIC_PREF, true ) ) {
					$pref[ $p ] = true;
				}
			}
		}
		foreach ( $odd as $n ) {
			if ( false !== strpos( $content, $n ) ) {
				$full[ $n ][ $owner ] = true;
			}
		}
		return strlen( $content );
	}

	/**
	 * Longest built-name prefix (ending in _ or -) that starts a name.
	 *
	 * @param string $name Name.
	 * @param array  $pref Prefix set.
	 */
	private static function built_prefix( $name, array $pref ) {
		$best = '';
		$len  = strlen( $name );
		for ( $i = 3; $i < $len - 1; $i++ ) {
			$c = $name[ $i ];
			if ( '_' === $c || '-' === $c ) {
				$p = substr( $name, 0, $i + 1 );
				if ( isset( $pref[ $p ] ) ) {
					$best = $p;
				}
			}
		}
		return $best;
	}

	/**
	 * PHP files of a folder (recursive), skipping tests, assets and caches.
	 *
	 * @param string $dir      Folder.
	 * @param float  $deadline Time limit.
	 */
	private static function php_files( $dir, $deadline ) {
		$skip = array( 'node_modules', 'tests', 'test', 'cache', '.git', 'assets', 'languages', 'images', 'img', 'css', 'js', 'fonts' );
		try {
			$dirs = new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				static function ( $current ) use ( $skip, $deadline ) {
					if ( microtime( true ) > $deadline ) {
						return false;
					}
					if ( $current->isDir() ) {
						return ! $current->isLink() && ! in_array( strtolower( $current->getFilename() ), $skip, true );
					}
					return 'php' === strtolower( $current->getExtension() );
				}
			);
			foreach ( new RecursiveIteratorIterator( $dirs ) as $f ) {
				if ( $f->isFile() ) {
					yield $f->getPathname();
				}
			}
		} catch ( Exception $e ) {
			return;
		}
	}

	// ------------------------------------------------------------------ Rows and findings.

	/**
	 * Groups items into one row per owner.
	 *
	 * @param array $items   Attributed items.
	 * @param array $plugins Installed plugins.
	 */
	private static function rows( array $items, array $plugins ) {
		$rows = array();
		foreach ( $items as $item ) {
			$key = $item['owner'];
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = self::empty_row( $key, $plugins );
			}
			$r = &$rows[ $key ];
			switch ( $item['type'] ) {
				case 'option':
					$r['autoload_bytes'] += $item['bytes'];
					++$r['autoload_options'];
					$r['items']['options'][] = array( $item['name'], $item['bytes'], $item['how'] );
					break;
				case 'table':
					if ( self::KEY_CORE !== $key ) { // Core tables hold content: not a cost.
						$r['table_bytes'] += $item['bytes'];
						++$r['tables'];
						$r['items']['tables'][] = array( $item['name'], $item['bytes'], $item['rows'], $item['how'] );
					}
					break;
				case 'cron':
					$orphan               = self::is_orphan( $item, $plugins );
					$r['cron_events']    += $item['events'];
					$r['cron_orphans']   += $orphan ? $item['events'] : 0;
					$r['cron_late']      += $item['late'] ? 1 : 0;
					$r['items']['cron'][] = array( $item['name'], $item['events'], $orphan, $item['late'], $item['how'] );
					break;
				case 'action':
					$r['actions_failed']    += $item['failed'];
					$r['actions_late']      += $item['late'];
					$r['items']['actions'][] = array( $item['name'], $item['group'], $item['failed'], $item['late'], $item['how'] );
					break;
			}
			unset( $r );
		}
		foreach ( $rows as $k => $r ) {
			foreach ( $r['items'] as $col => $list ) {
				usort(
					$list,
					static function ( $a, $b ) {
						return $b[1] <=> $a[1];
					}
				);
				$rows[ $k ]['items'][ $col ] = array_slice( $list, 0, self::ITEMS );
			}
			$rows[ $k ]['impact'] = self::impact( $rows[ $k ] );
		}
		uasort(
			$rows,
			static function ( $a, $b ) {
				return $b['impact'] <=> $a['impact'];
			}
		);
		return $rows;
	}

	/**
	 * An empty row for an owner.
	 *
	 * @param string $key     Owner key.
	 * @param array  $plugins Installed plugins.
	 */
	private static function empty_row( $key, array $plugins ) {
		$kind = substr( $key, 0, (int) strpos( $key . ':', ':' ) );
		$slug = (string) substr( $key, strlen( $kind ) + 1 );
		$name = $slug;
		$st   = '';
		switch ( $kind ) {
			case 'plugin':
				$name = $plugins[ $slug ]['name'] ?? $slug;
				$st   = isset( $plugins[ $slug ] ) ? ( $plugins[ $slug ]['active'] ? 'active' : 'inactive' ) : 'deleted';
				break;
			case 'theme':
				$theme = wp_get_theme( $slug );
				$name  = $theme->exists() ? $theme->get( 'Name' ) : $slug;
				$st    = ! $theme->exists() ? 'deleted' : ( in_array( $slug, array( get_stylesheet(), get_template() ), true ) ? 'active' : 'inactive' );
				break;
			case 'mu':
				$st = 'active';
				break;
			case 'shared':
				$name = 'Action Scheduler';
				$st   = 'shared';
				break;
			case self::KEY_CORE:
				$name = 'WordPress';
				$st   = 'core';
				break;
			case self::KEY_LEFTOVERS:
			case self::KEY_UNATTRIBUTED:
				$name = '';
				$st   = $kind;
				break;
		}
		return array(
			'key'              => $key,
			'kind'             => $kind,
			'slug'             => $slug,
			'name'             => $name,
			'status'           => $st,
			'autoload_bytes'   => 0,
			'autoload_options' => 0,
			'table_bytes'      => 0,
			'tables'           => 0,
			'cron_events'      => 0,
			'cron_orphans'     => 0,
			'cron_late'        => 0,
			'actions_failed'   => 0,
			'actions_late'     => 0,
			'items'            => array(
				'options' => array(),
				'tables'  => array(),
				'cron'    => array(),
				'actions' => array(),
			),
		);
	}

	/**
	 * Is a cron hook orphaned? No callback, and no active code uses the name.
	 *
	 * @param array $item    Cron item.
	 * @param array $plugins Installed plugins.
	 */
	private static function is_orphan( array $item, array $plugins ) {
		if ( ! empty( $item['callback'] ) ) {
			return false;
		}
		$o = $item['owner'];
		if ( in_array( $o, array( self::KEY_LEFTOVERS ), true ) ) {
			return true;
		}
		if ( 0 === strpos( $o, 'plugin:' ) ) {
			$slug = substr( $o, 7 );
			return empty( $plugins[ $slug ]['active'] ); // Inactive or deleted plugin.
		}
		if ( 0 === strpos( $o, 'theme:' ) ) {
			$slug = substr( $o, 6 );
			return ! in_array( $slug, array( get_stylesheet(), get_template() ), true );
		}
		// Core, mu-plugins and active code that registers the hook in another context (cron, admin):
		// not counted as orphaned. Unattributed: unknown, not counted.
		return false;
	}

	/**
	 * Impact used to order the rows. Autoload weighs most: it is read on every page.
	 *
	 * @param array $r Row.
	 */
	private static function impact( array $r ) {
		return $r['autoload_bytes'] / 1024 + $r['table_bytes'] / 1048576 * 2 + $r['cron_orphans'] * 10 + $r['cron_late'] * 10 + $r['actions_failed'] + $r['actions_late'];
	}

	/**
	 * REN-08: autoload of inactive plugins and leftovers.
	 *
	 * @param array $rows     Rows.
	 * @param bool  $complete Whether the code search finished.
	 */
	private static function ren08( array $rows, $complete ) {
		$orphan = 0;
		$lines  = array();
		foreach ( $rows as $r ) {
			$is_orphan = self::KEY_LEFTOVERS === $r['key'] || in_array( $r['status'], array( 'inactive', 'deleted' ), true );
			if ( $is_orphan && $r['autoload_bytes'] > 0 ) {
				$orphan += $r['autoload_bytes'];
				/* translators: 1: plugin or group name, 2: size, 3: number of options. */
				$lines[] = sprintf( _n( '%1$s: %2$s in %3$d option', '%1$s: %2$s in %3$d options', $r['autoload_options'], 'inochi-pulso' ), self::label( $r ), Inochi_Pulso_Util::bytes( $r['autoload_bytes'] ), $r['autoload_options'] );
			}
		}
		$kb = $orphan / 1024;
		/* translators: %s: size in KB. */
		$value = sprintf( __( '%s KB from inactive or deleted plugins', 'inochi-pulso' ), Inochi_Pulso_Util::num( $kb ) );
		$ev    = __( 'Every autoloaded option, attributed by searching its name in the installed code. Details in the cost per plugin table.', 'inochi-pulso' ) . "\n" . ( $lines ? implode( "\n", $lines ) : __( 'None.', 'inochi-pulso' ) );
		if ( ! $complete ) {
			$ev .= "\n" . __( 'The code search stopped at the time or size limit: options without a match are listed as unattributed, not as leftovers.', 'inochi-pulso' );
			return self::partial( 'REN-08', $value, $ev );
		}
		return self::auto( 'REN-08', $value, Inochi_Pulso_Util::grade( $kb, 100, 500 ), $ev );
	}

	/**
	 * REN-12: late and orphaned cron events.
	 *
	 * @param array $items Attributed items.
	 */
	private static function ren12( array $items ) {
		$plugins = Inochi_Pulso_Util::plugins();
		$total   = 0;
		$late    = array();
		$orphans = array();
		foreach ( $items as $item ) {
			if ( 'cron' !== $item['type'] ) {
				continue;
			}
			$total += $item['events'];
			if ( $item['late'] ) {
				$late[ $item['name'] ] = $item['late'];
			}
			if ( self::is_orphan( $item, $plugins ) ) {
				$orphans[] = $item['name'];
			}
		}
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$state    = $late ? 'Fallo' : ( $orphans ? 'Aviso' : 'OK' );
		$value    = sprintf(
			/* translators: 1: events, 2: late events, 3: orphaned events, 4: WP-Cron status. */
			__( '%1$d events · %2$d late > 1 h · %3$d orphaned · WP-Cron %4$s', 'inochi-pulso' ),
			$total,
			count( $late ),
			count( $orphans ),
			$disabled ? __( 'disabled (system cron)', 'inochi-pulso' ) : __( 'active', 'inochi-pulso' )
		);
		$lines = array();
		foreach ( $late as $hook => $secs ) {
			/* translators: 1: hook, 2: hours. */
			$lines[] = sprintf( __( 'Late: %1$s (%2$s h)', 'inochi-pulso' ), $hook, Inochi_Pulso_Util::num( $secs / 3600, 1 ) );
		}
		foreach ( $orphans as $hook ) {
			/* translators: %s: hook. */
			$lines[] = sprintf( __( 'Orphaned (only an inactive or deleted plugin uses it): %s', 'inochi-pulso' ), $hook );
		}
		if ( $disabled ) {
			$lines[] = __( 'DISABLE_WP_CRON is true: check that the server has a task that calls wp-cron.php.', 'inochi-pulso' );
		}
		$lines[] = __( 'A hook counts as orphaned only when no callback is registered and no active code uses its name.', 'inochi-pulso' );
		return self::auto( 'REN-12', $value, $state, implode( "\n", $lines ) );
	}

	/**
	 * Display name of a row.
	 *
	 * @param array $r Row.
	 */
	public static function label( array $r ) {
		switch ( $r['kind'] ) {
			case self::KEY_LEFTOVERS:
				return __( 'Leftovers (no installed code uses them)', 'inochi-pulso' );
			case self::KEY_UNATTRIBUTED:
				return __( 'Unattributed', 'inochi-pulso' );
			case 'theme':
				/* translators: %s: theme name. */
				return sprintf( __( 'Theme: %s', 'inochi-pulso' ), $r['name'] );
			case 'mu':
				/* translators: %s: file or folder in mu-plugins. */
				return sprintf( __( 'Must-use: %s', 'inochi-pulso' ), $r['name'] );
			case 'shared':
				/* translators: %s: library name. */
				return sprintf( __( '%s (shared library)', 'inochi-pulso' ), $r['name'] );
		}
		return (string) $r['name'];
	}

	/**
	 * Display status of a row.
	 *
	 * @param string $status Status key.
	 */
	public static function status_label( $status ) {
		$labels = array(
			'active'       => __( 'Active', 'inochi-pulso' ),
			'inactive'     => __( 'Inactive', 'inochi-pulso' ),
			'deleted'      => __( 'Deleted', 'inochi-pulso' ),
			'core'         => __( 'Core', 'inochi-pulso' ),
			'shared'       => __( 'Shared', 'inochi-pulso' ),
			'leftovers'    => __( 'No code', 'inochi-pulso' ),
			'unattributed' => __( 'Unclear', 'inochi-pulso' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * How an item was attributed.
	 *
	 * @param string $how Key.
	 */
	public static function how_label( $how ) {
		$labels = array(
			'callback'   => __( 'registered callback', 'inochi-pulso' ),
			'name'       => __( 'name in the code', 'inochi-pulso' ),
			'prefix'     => __( 'name built from a prefix in the code', 'inochi-pulso' ),
			'rule'       => __( 'WordPress naming rule', 'inochi-pulso' ),
			'widget'     => __( 'registered widget', 'inochi-pulso' ),
			'group'      => __( 'action group named like the plugin', 'inochi-pulso' ),
			'shared'     => __( 'Action Scheduler table, shared by several plugins', 'inochi-pulso' ),
			'none'       => __( 'not found in the installed code', 'inochi-pulso' ),
			'incomplete' => __( 'search stopped at the limit', 'inochi-pulso' ),
		);
		return $labels[ $how ] ?? $how;
	}
}
