<?php
/**
 * SEG-03: plugins closed or not updated on WordPress.org.
 *
 * The only external call of the plugin. It only runs if an administrator has
 * accepted it in Settings (off by default). It sends api.wordpress.org the slug
 * (folder name) of each installed plugin and nothing else.
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * WordPress.org plugins API lookup.
 */
class Inochi_Pulso_Checks_WPOrg extends Inochi_Pulso_Checks_Base {

	const API          = 'https://api.wordpress.org/plugins/info/1.2/';
	const STEP_SECONDS = 15;

	/**
	 * Premium plugins whose folder name matches a plugin closed (or different) on WordPress.org.
	 */
	const PREMIUM = array( 'sitepress-multilingual-cms', 'wpml-string-translation', 'wpml-translation-management', 'wpml-media-translation', 'woocommerce-multilingual', 'revslider', 'js_composer', 'fusion-builder', 'fusion-core', 'gravityforms', 'wp-rocket', 'elementor-pro', 'layerslider', 'bricks', 'perfmatters', 'advanced-custom-fields-pro', 'wpforms', 'searchwp', 'facetwp', 'facetwp-i18n', 'admin-columns-pro', 'wp-all-import-pro', 'wp-all-export-pro', 'gp-premium', 'astra-addon', 'ultimate-elementor', 'essential-grid', 'the7-elements', 'dt-the7-core', 'envato-market' );

	/**
	 * Scan step. It can ask for more rounds ("again") when there are many plugins.
	 *
	 * @param array $scan_payload Data already gathered (needs "plugins" from the security step).
	 */
	public static function run( $scan_payload = array() ) {
		if ( ! Inochi_Pulso_Settings::wporg_allowed() ) {
			return array(
				'findings' => array(
					self::auto( 'SEG-03', __( 'WordPress.org lookup turned off', 'inochi-pulso' ), Inochi_Pulso_Catalog::NO_DATA, __( 'To know whether any plugin is closed or has not been updated for more than 2 years, turn on "Check plugins on WordPress.org" in Settings and scan again. Until then this check does not score.', 'inochi-pulso' ) ),
				),
				'payload'  => array(),
			);
		}

		$plugins = (array) ( $scan_payload['plugins'] ?? array() );
		$cache   = (array) ( $scan_payload['wporg_cache'] ?? array() );
		$start   = microtime( true );
		$pending = 0;

		foreach ( $plugins as $p ) {
			$slug = sanitize_key( $p['slug'] ?? '' );
			if ( '' === $slug || isset( $cache[ $slug ] ) ) {
				continue;
			}
			if ( ( microtime( true ) - $start ) > self::STEP_SECONDS ) {
				++$pending;
				continue;
			}
			$cache[ $slug ] = self::lookup( $slug );
		}
		if ( $pending ) {
			return array(
				'findings' => array(),
				'payload'  => array( 'wporg_cache' => $cache ),
				'again'    => true,
				/* translators: 1: plugins checked, 2: plugins left. */
				'note'     => sprintf( __( '%1$d plugins checked, %2$d left…', 'inochi-pulso' ), count( $cache ), $pending ),
			);
		}
		return self::summarise( $plugins, $cache );
	}

	/**
	 * Status of a plugin on WordPress.org.
	 *
	 * @param string $slug Slug.
	 */
	private static function lookup( $slug ) {
		$url = add_query_arg(
			array(
				'action'  => 'plugin_information',
				'request' => array(
					'slug'   => $slug,
					'fields' => array_fill_keys( array( 'sections', 'description', 'reviews', 'banners', 'icons', 'screenshots', 'versions', 'contributors', 'tags', 'ratings', 'donate_link', 'compatibility' ), 0 ),
				),
			),
			self::API
		);
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Inochi-Pulso/' . INOCHI_PULSO_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 'error' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : array();
		if ( isset( $data['error'] ) ) {
			$closed = 'closed' === $data['error'] || ! empty( $data['closed'] );
			return array(
				'status' => $closed ? 'closed' : 'not_found',
				'date'   => sanitize_text_field( (string) ( $data['closed_date'] ?? '' ) ),
				'reason' => sanitize_text_field( (string) ( $data['reason_text'] ?? ( $data['reason'] ?? '' ) ) ),
			);
		}
		if ( 200 === $code && ! empty( $data['slug'] ) ) {
			return array(
				'status'       => 'ok',
				'last_updated' => sanitize_text_field( (string) ( $data['last_updated'] ?? '' ) ),
				'tested'       => sanitize_text_field( (string) ( $data['tested'] ?? '' ) ),
			);
		}
		return array( 'status' => 'error' );
	}

	/**
	 * Final SEG-03 result and data per plugin.
	 *
	 * @param array $plugins Plugins of the scan.
	 * @param array $cache   slug => status on WordPress.org.
	 */
	private static function summarise( array $plugins, array $cache ) {
		$closed  = array();
		$premium = array();
		$old     = array();
		$rows    = array();
		$errors  = 0;
		$local   = Inochi_Pulso_Util::plugins();
		foreach ( $plugins as $p ) {
			$slug = sanitize_key( $p['slug'] ?? '' );
			$info = $cache[ $slug ] ?? array( 'status' => 'error' );
			if ( 'closed' === $info['status'] && self::is_other_plugin( $slug, (string) ( $info['date'] ?? '' ), $local[ $slug ]['file'] ?? '' ) ) {
				$p['wporg'] = 'other';
				$premium[]  = $slug . ( empty( $info['date'] ) ? '' : ' (' . $info['date'] . ')' );
			} elseif ( 'closed' === $info['status'] ) {
				$p['wporg'] = 'closed';
				$closed[]   = $slug;
			} elseif ( 'ok' === $info['status'] ) {
				$p['wporg']        = 'ok';
				$ts                = strtotime( (string) $info['last_updated'] );
				$p['last_updated'] = $ts ? gmdate( 'Y-m-d', $ts ) : '';
				$p['tested']       = $info['tested'];
				if ( $ts && $ts < time() - 2 * YEAR_IN_SECONDS ) {
					$old[] = $slug . ' (' . gmdate( 'Y-m-d', $ts ) . ')';
				}
			} elseif ( 'error' === $info['status'] ) {
				++$errors;
			}
			$rows[] = $p;
		}

		$lines = array();
		foreach ( $closed as $s ) {
			/* translators: %s: plugin slug. */
			$lines[] = sprintf( __( 'Closed: %s', 'inochi-pulso' ), $s ) . ( empty( $cache[ $s ]['date'] ) ? '' : ' (' . $cache[ $s ]['date'] . ')' ) . ( empty( $cache[ $s ]['reason'] ) || 'Unknown' === $cache[ $s ]['reason'] ? '' : ' — ' . $cache[ $s ]['reason'] );
		}
		foreach ( $premium as $s ) {
			/* translators: %s: plugin slug and closing date. */
			$lines[] = sprintf( __( 'Not counted as closed: %s. It is a premium plugin, or one updated outside WordPress.org, with the same folder name as a plugin closed there years ago.', 'inochi-pulso' ), $s );
		}
		foreach ( $old as $s ) {
			/* translators: %s: plugin slug and date. */
			$lines[] = sprintf( __( 'Not updated for more than 2 years: %s', 'inochi-pulso' ), $s );
		}
		/* translators: 1: plugins checked, 2: errors. */
		$lines[] = sprintf( __( 'Checked on the WordPress.org plugins API: %1$d (with errors: %2$d).', 'inochi-pulso' ), count( $cache ), $errors );

		if ( $closed ) {
			$state = 'Fallo';
		} elseif ( $old ) {
			$state = 'Aviso';
		} elseif ( $errors ) {
			$state = Inochi_Pulso_Catalog::NO_DATA; // Without a full answer an OK cannot be stated.
		} else {
			$state = 'OK';
		}
		if ( $closed || $old ) {
			/* translators: 1: closed plugins, 2: plugins not updated. */
			$value = sprintf( __( '%1$d closed and %2$d not updated for more than 2 years', 'inochi-pulso' ), count( $closed ), count( $old ) );
		} elseif ( $errors ) {
			$value = __( 'WordPress.org did not answer for every plugin', 'inochi-pulso' );
		} else {
			$value = __( 'None closed or abandoned', 'inochi-pulso' );
		}

		return array(
			'findings' => array( self::auto( 'SEG-03', $value, $state, implode( "\n", $lines ) ) ),
			'payload'  => array( 'plugins' => $rows ),
		);
	}

	/**
	 * A plugin closed on WordPress.org that is not the installed one: a known premium
	 * plugin with the same folder name, or a plugin closed more than 3 years ago whose
	 * installed code changed more than a year after the closing date (so it is updated
	 * from somewhere else).
	 *
	 * @param string $slug   Folder name.
	 * @param string $closed Closing date on WordPress.org.
	 * @param string $file   Main file, relative to the plugins folder.
	 */
	private static function is_other_plugin( $slug, $closed, $file ) {
		if ( in_array( $slug, self::PREMIUM, true ) ) {
			return true;
		}
		$ts = strtotime( $closed );
		if ( ! $ts || $ts > time() - 3 * YEAR_IN_SECONDS || '' === $file ) {
			return false;
		}
		$mtime = @filemtime( WP_PLUGIN_DIR . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file means no evidence.
		return $mtime && $mtime > $ts + YEAR_IN_SECONDS;
	}
}
