<?php
/**
 * Data of a scan for the screen and the exports: JSON, CSV and printable HTML report.
 *
 * No export includes usernames or emails: evidence is stored without them and is
 * cleaned again here with Inochi_Pulso_Util::redact().
 *
 * @package InochiPulso
 */

defined( 'ABSPATH' ) || exit;

/**
 * Export of a scan.
 */
class Inochi_Pulso_Export {

	const UTM = 'utm_source=inochi-pulso&utm_medium=plugin&utm_campaign=informe';

	/**
	 * Shown state key: no decided state counts as "No data".
	 *
	 * @param array|null $f Result.
	 */
	public static function state( $f ) {
		$s = $f ? (string) $f['state'] : '';
		return '' === $s ? Inochi_Pulso_Catalog::NO_DATA : $s;
	}

	/**
	 * Shared data of a scan for the screen and the exports.
	 *
	 * @param int $scan_id ID.
	 * @return array|null
	 */
	public static function data( $scan_id ) {
		$scan = Inochi_Pulso_DB::get_scan( $scan_id );
		if ( ! $scan ) {
			return null;
		}
		$findings = Inochi_Pulso_DB::get_findings( $scan_id );
		$payload  = (array) $scan['payload'];
		$is_woo   = ! empty( $payload['gen']['is_woo'] );
		$variants = (array) ( $payload['variants'] ?? array() );
		$score    = Inochi_Pulso_Catalog::score( $findings, $is_woo );

		$rows = array();
		foreach ( Inochi_Pulso_Catalog::all() as $code => $c ) {
			$f      = $findings[ $code ] ?? null;
			$state  = self::state( $f );
			$rows[] = array(
				'code'     => $code,
				'area'     => $c['area'],
				'name'     => $c['name'],
				'severity' => $c['severity'],
				'state'    => $state,
				'value'    => $f ? Inochi_Pulso_Util::redact( $f['value'] ) : '',
				'evidence' => $f ? Inochi_Pulso_Util::redact( $f['evidence'] ) : '',
				'phase'    => in_array( $state, array( 'Fallo', 'Aviso' ), true ) ? Inochi_Pulso_Catalog::phase( $code, $state ) : '',
			);
		}

		$priorities = array();
		foreach ( Inochi_Pulso_Catalog::rank( $findings ) as $code ) {
			$state = self::state( $findings[ $code ] );
			$k     = Inochi_Pulso_Knowledge::finding( $code, $state, (string) $findings[ $code ]['value'], (string) ( $variants[ $code ] ?? '' ) );
			if ( ! $k ) {
				continue;
			}
			$priorities[ Inochi_Pulso_Catalog::phase( $code, $state ) ][] = array(
				'code'     => $code,
				'state'    => $state,
				'title'    => $k['title'],
				'cost'     => $k['cost'],
				'proposal' => $k['proposal'],
				'verify'   => $k['verify'],
				'target'   => $k['target'],
				'value'    => Inochi_Pulso_Util::redact( $findings[ $code ]['value'] ),
			);
		}

		return array(
			'scan'          => $scan,
			'site'          => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'score'         => $score,
			'rows'          => $rows,
			'priorities'    => $priorities,
			'cost'          => (array) ( $payload['cost'] ?? array() ),
			'cost_complete' => ! empty( $payload['cost_complete'] ),
			'has_actions'   => self::has_actions( (array) ( $payload['cost'] ?? array() ) ),
			'errors'        => (array) ( $payload['errors'] ?? array() ),
		);
	}

	/**
	 * Does any row have scheduled actions? (To hide the column when there is no Action Scheduler.)
	 *
	 * @param array $cost Rows.
	 */
	private static function has_actions( array $cost ) {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		foreach ( $cost as $r ) {
			if ( ! empty( $r['actions_failed'] ) || ! empty( $r['actions_late'] ) ) {
				return true;
			}
		}
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only check.
	}

	/**
	 * Cost per plugin rows ready to show: label, status and formatted columns.
	 *
	 * @param array $d Data.
	 */
	public static function cost_rows( array $d ) {
		$out = array();
		foreach ( $d['cost'] as $r ) {
			$out[] = array(
				'label'    => Inochi_Pulso_Checks_Cost::label( $r ),
				'status'   => Inochi_Pulso_Checks_Cost::status_label( (string) $r['status'] ),
				'autoload' => $r['autoload_bytes'] ? Inochi_Pulso_Util::bytes( $r['autoload_bytes'] ) . ' · ' . sprintf(
					/* translators: %s: number of options. */
					_n( '%s option', '%s options', (int) $r['autoload_options'], 'inochi-pulso' ),
					Inochi_Pulso_Util::num( $r['autoload_options'] )
				) : '—',
				'tables'   => $r['tables'] ? Inochi_Pulso_Util::bytes( $r['table_bytes'] ) . ' · ' . sprintf(
					/* translators: %s: number of tables. */
					_n( '%s table', '%s tables', (int) $r['tables'], 'inochi-pulso' ),
					Inochi_Pulso_Util::num( $r['tables'] )
				) : '—',
				'cron'     => $r['cron_events'] ? Inochi_Pulso_Util::num( $r['cron_events'] ) . ( $r['cron_orphans'] ? ' · ' . sprintf(
					/* translators: %s: number of orphaned events. */
					__( '%s orphaned', 'inochi-pulso' ),
					Inochi_Pulso_Util::num( $r['cron_orphans'] )
				) : '' ) . ( $r['cron_late'] ? ' · ' . sprintf(
					/* translators: %s: number of late hooks. */
					__( '%s late', 'inochi-pulso' ),
					Inochi_Pulso_Util::num( $r['cron_late'] )
				) : '' ) : '—',
				'actions'  => ( $r['actions_failed'] || $r['actions_late'] ) ? sprintf(
					/* translators: 1: failed actions, 2: late actions. */
					__( '%1$s failed · %2$s late', 'inochi-pulso' ),
					Inochi_Pulso_Util::num( $r['actions_failed'] ),
					Inochi_Pulso_Util::num( $r['actions_late'] )
				) : '—',
				'raw'      => $r,
			);
		}
		return $out;
	}

	/**
	 * Detail lines of a cost row (biggest items of each column).
	 *
	 * @param array $r Raw row.
	 * @return string[]
	 */
	public static function cost_details( array $r ) {
		$lines = array();
		foreach ( (array) ( $r['items']['options'] ?? array() ) as $i ) {
			/* translators: 1: option name, 2: size, 3: how it was attributed. */
			$lines[] = sprintf( __( 'Option %1$s · %2$s · %3$s', 'inochi-pulso' ), $i[0], Inochi_Pulso_Util::bytes( $i[1] ), Inochi_Pulso_Checks_Cost::how_label( $i[2] ) );
		}
		foreach ( (array) ( $r['items']['tables'] ?? array() ) as $i ) {
			/* translators: 1: table name, 2: size, 3: rows, 4: how it was attributed. */
			$lines[] = sprintf( __( 'Table %1$s · %2$s · %3$s rows · %4$s', 'inochi-pulso' ), $i[0], Inochi_Pulso_Util::bytes( $i[1] ), Inochi_Pulso_Util::num( $i[2] ), Inochi_Pulso_Checks_Cost::how_label( $i[3] ) );
		}
		foreach ( (array) ( $r['items']['cron'] ?? array() ) as $i ) {
			$flags = array();
			if ( $i[2] ) {
				$flags[] = __( 'orphaned', 'inochi-pulso' );
			}
			if ( $i[3] ) {
				/* translators: %s: hours. */
				$flags[] = sprintf( __( '%s h late', 'inochi-pulso' ), Inochi_Pulso_Util::num( $i[3] / 3600, 1 ) );
			}
			/* translators: 1: hook, 2: number of events, 3: flags, 4: how it was attributed. */
			$lines[] = sprintf( __( 'Cron %1$s · %2$s events%3$s · %4$s', 'inochi-pulso' ), $i[0], Inochi_Pulso_Util::num( $i[1] ), $flags ? ' · ' . implode( ', ', $flags ) : '', Inochi_Pulso_Checks_Cost::how_label( $i[4] ) );
		}
		foreach ( (array) ( $r['items']['actions'] ?? array() ) as $i ) {
			/* translators: 1: hook, 2: group, 3: failed, 4: late, 5: how it was attributed. */
			$lines[] = sprintf( __( 'Action %1$s (group: %2$s) · %3$s failed · %4$s late · %5$s', 'inochi-pulso' ), $i[0], '' !== $i[1] ? $i[1] : '—', Inochi_Pulso_Util::num( $i[2] ), Inochi_Pulso_Util::num( $i[3] ), Inochi_Pulso_Checks_Cost::how_label( $i[4] ) );
		}
		return $lines;
	}

	/**
	 * Explanation shown above the cost table.
	 *
	 * @param array $d Data.
	 */
	public static function cost_note( array $d ) {
		$note = __( 'Each item is attributed by its registered callback or by searching its name in the installed code. When several plugins could own it, it goes to "Unattributed": Pulso does not guess. Core content tables (posts, users, orders…) are not counted.', 'inochi-pulso' );
		if ( ! $d['cost_complete'] ) {
			$note .= ' ' . __( 'The code search stopped at its time or size limit: items without a match are in "Unattributed".', 'inochi-pulso' );
		}
		return $note;
	}

	/**
	 * File name of an export.
	 *
	 * @param array  $d   Data.
	 * @param string $ext Extension.
	 */
	public static function filename( array $d, $ext ) {
		return sanitize_file_name( 'pulso-' . $d['site'] . '-' . substr( (string) $d['scan']['created_at'], 0, 10 ) . '-' . (int) $d['scan']['id'] . '.' . $ext );
	}

	/**
	 * JSON (keys in English; states, areas and severities as labels in the site language).
	 *
	 * @param array $d Data.
	 */
	public static function json( array $d ) {
		$checks = array();
		foreach ( $d['rows'] as $r ) {
			$checks[] = array(
				'code'     => $r['code'],
				'area'     => Inochi_Pulso_Catalog::area_label( $r['area'] ),
				'name'     => $r['name'],
				'severity' => Inochi_Pulso_Catalog::severity_label( $r['severity'] ),
				'state'    => Inochi_Pulso_Catalog::state_label( $r['state'] ),
				'priority' => $r['phase'] ? Inochi_Pulso_Catalog::phase_label( $r['phase'] ) : '',
				'value'    => $r['value'],
				'evidence' => $r['evidence'],
			);
		}
		$cost = array();
		foreach ( self::cost_rows( $d ) as $c ) {
			$r      = $c['raw'];
			$cost[] = array(
				'name'             => $c['label'],
				'slug'             => (string) $r['slug'],
				'kind'             => (string) $r['kind'],
				'status'           => $c['status'],
				'autoload_bytes'   => (int) $r['autoload_bytes'],
				'autoload_options' => (int) $r['autoload_options'],
				'table_bytes'      => (int) $r['table_bytes'],
				'tables'           => (int) $r['tables'],
				'cron_events'      => (int) $r['cron_events'],
				'cron_orphans'     => (int) $r['cron_orphans'],
				'cron_late_hooks'  => (int) $r['cron_late'],
				'actions_failed'   => (int) $r['actions_failed'],
				'actions_late'     => (int) $r['actions_late'],
				'details'          => self::cost_details( $r ),
			);
		}
		$areas = array();
		foreach ( $d['score']['areas'] as $name => $a ) {
			if ( $a['applies'] ) {
				$areas[ Inochi_Pulso_Catalog::area_label( $name ) ] = $a['score'];
			}
		}
		return (string) wp_json_encode(
			array(
				'generator'       => 'Inochi Pulso ' . INOCHI_PULSO_VERSION,
				'site'            => $d['site'],
				'scan_id'         => (int) $d['scan']['id'],
				'created_at'      => $d['scan']['created_at'],
				'score'           => array(
					'global' => $d['score']['global'],
					'areas'  => $areas,
				),
				'cost_per_plugin' => $cost,
				'cost_complete'   => $d['cost_complete'],
				'checks'          => $checks,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * CSV separated by semicolons, with BOM for spreadsheets: cost per plugin first, then the checks.
	 *
	 * @param array $d Data.
	 */
	public static function csv( array $d ) {
		$h = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream.
		fwrite( $h, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in-memory stream.
		self::put( $h, array( __( 'Cost per plugin', 'inochi-pulso' ) ) );
		self::put( $h, array( __( 'Plugin', 'inochi-pulso' ), __( 'Status', 'inochi-pulso' ), __( 'Autoload (KB)', 'inochi-pulso' ), __( 'Autoloaded options', 'inochi-pulso' ), __( 'Own tables (MB)', 'inochi-pulso' ), __( 'Tables', 'inochi-pulso' ), __( 'Cron events', 'inochi-pulso' ), __( 'Orphaned events', 'inochi-pulso' ), __( 'Failed actions (30 days)', 'inochi-pulso' ), __( 'Late actions (> 1 h)', 'inochi-pulso' ) ) );
		foreach ( self::cost_rows( $d ) as $c ) {
			$r = $c['raw'];
			self::put( $h, array( $c['label'], $c['status'], Inochi_Pulso_Util::num( $r['autoload_bytes'] / 1024, 1 ), (int) $r['autoload_options'], Inochi_Pulso_Util::num( $r['table_bytes'] / 1048576, 2 ), (int) $r['tables'], (int) $r['cron_events'], (int) $r['cron_orphans'], (int) $r['actions_failed'], (int) $r['actions_late'] ) );
		}
		self::put( $h, array() );
		self::put( $h, array( __( 'Code', 'inochi-pulso' ), __( 'Area', 'inochi-pulso' ), __( 'Check', 'inochi-pulso' ), __( 'Severity', 'inochi-pulso' ), __( 'State', 'inochi-pulso' ), __( 'Priority', 'inochi-pulso' ), __( 'Measured value', 'inochi-pulso' ), __( 'Evidence', 'inochi-pulso' ) ) );
		foreach ( $d['rows'] as $r ) {
			self::put( $h, array( $r['code'], Inochi_Pulso_Catalog::area_label( $r['area'] ), $r['name'], Inochi_Pulso_Catalog::severity_label( $r['severity'] ), Inochi_Pulso_Catalog::state_label( $r['state'] ), $r['phase'] ? Inochi_Pulso_Catalog::phase_label( $r['phase'] ) : '', $r['value'], $r['evidence'] ) );
		}
		rewind( $h );
		$out = (string) stream_get_contents( $h );
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.
		return $out;
	}

	/**
	 * Writes a CSV row with every cell protected against formulas.
	 *
	 * @param resource $h     Stream.
	 * @param array    $cells Cells.
	 */
	private static function put( $h, array $cells ) {
		fputcsv( $h, array_map( array( __CLASS__, 'cell' ), $cells ), ';', '"', '\\' );
	}

	/**
	 * Stops a spreadsheet from reading a cell as a formula.
	 *
	 * @param mixed $v Value.
	 */
	private static function cell( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/**
	 * Link to Inochi (the only one in the plugin), with UTM.
	 */
	public static function inochi_url() {
		return 'https://inochi.dev/auditoria-wordpress/?' . self::UTM;
	}

	/**
	 * Printable HTML report (full document, inline styles, no external resources).
	 *
	 * @param array $d Data.
	 */
	public static function html( array $d ) {
		$o   = array();
		$o[] = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex">';
		$o[] = '<title>' . esc_html( 'Inochi Pulso · ' . $d['site'] . ' · ' . $d['scan']['created_at'] ) . '</title>';
		$o[] = '<style>body{font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#0b1f33;max-width:1000px;margin:24px auto;padding:0 16px}h1{font-size:22px;margin:0 0 4px}h2{font-size:17px;margin:28px 0 8px;border-bottom:2px solid #0b1f33;padding-bottom:4px}h3{font-size:15px;margin:16px 0 4px}table{border-collapse:collapse;width:100%;margin:8px 0}th,td{border:1px solid #ccd3da;padding:5px 7px;text-align:left;vertical-align:top}th{background:#eef1f4}.s-OK{color:#0a7d3b}.s-Aviso{color:#9a5b00}.s-Fallo{color:#b3261e;font-weight:600}.muted{color:#5b6b7a}pre,ul.d{white-space:pre-wrap;font-size:12px;margin:0}ul.d{padding-left:16px}.score{font-size:32px;font-weight:700}.no-print{margin:12px 0}@media print{.no-print{display:none}h2{break-after:avoid}tr{break-inside:avoid}}</style></head><body>';
		$o[] = '<p class="no-print"><button type="button" onclick="window.print()">' . esc_html__( 'Print or save as PDF', 'inochi-pulso' ) . '</button></p>';
		$o[] = '<h1>' . esc_html__( 'Inochi Pulso report', 'inochi-pulso' ) . '</h1>';
		$o[] = '<p class="muted">' . esc_html( $d['site'] . ' · ' . $d['scan']['created_at'] . ' UTC · Inochi Pulso ' . INOCHI_PULSO_VERSION ) . '</p>';

		$o[] = '<h2>' . esc_html__( 'Site health', 'inochi-pulso' ) . '</h2>';
		$o[] = '<p><span class="score">' . esc_html( null === $d['score']['global'] ? '—' : $d['score']['global'] . ' / 100' ) . '</span></p>';
		$o[] = self::score_table( $d, '' );
		$o[] = '<p class="muted">' . esc_html__( 'What could not be checked shows as "No data" and does not count in the score.', 'inochi-pulso' ) . '</p>';

		$o[] = '<h2>' . esc_html__( 'Cost per plugin', 'inochi-pulso' ) . '</h2>';
		$o[] = self::cost_table( $d, '', true );

		foreach ( Inochi_Pulso_Catalog::PHASES as $phase ) {
			if ( empty( $d['priorities'][ $phase ] ) ) {
				continue;
			}
			/* translators: %s: priority (Urgent, Stabilisation, Improvement). */
			$o[] = '<h2>' . esc_html( sprintf( __( 'Priority: %s', 'inochi-pulso' ), Inochi_Pulso_Catalog::phase_label( $phase ) ) ) . '</h2>';
			foreach ( $d['priorities'][ $phase ] as $p ) {
				$o[] = self::priority( $p, 'h3', '' );
			}
		}

		$o[] = '<h2>' . esc_html__( 'All checks', 'inochi-pulso' ) . '</h2>';
		$o[] = self::findings_table( $d['rows'], '', false );
		$o[] = '</body></html>';
		return implode( "\n", $o );
	}

	/**
	 * Score table (HTML).
	 *
	 * @param array  $d     Data.
	 * @param string $class Table class.
	 */
	public static function score_table( array $d, $class ) {
		$o = '<table class="' . esc_attr( $class ) . '"><thead><tr><th>' . esc_html__( 'Area', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'Score', 'inochi-pulso' ) . '</th><th>' . esc_html_x( 'Fail', 'check state', 'inochi-pulso' ) . '</th><th>' . esc_html_x( 'Warning', 'check state', 'inochi-pulso' ) . '</th><th>' . esc_html_x( 'No data', 'check state', 'inochi-pulso' ) . '</th></tr></thead><tbody>';
		foreach ( $d['score']['areas'] as $name => $a ) {
			if ( ! $a['applies'] ) {
				continue;
			}
			$o .= '<tr><td>' . esc_html( Inochi_Pulso_Catalog::area_label( $name ) ) . '</td><td>' . esc_html( (string) $a['score'] ) . '</td><td>' . (int) $a['fails'] . '</td><td>' . (int) $a['warns'] . '</td><td>' . (int) $a['nodata'] . '</td></tr>';
		}
		return $o . '</tbody></table>';
	}

	/**
	 * Cost per plugin table (HTML).
	 *
	 * @param array  $d       Data.
	 * @param string $class   Table class.
	 * @param bool   $details Show the details as a list instead of a toggle.
	 */
	public static function cost_table( array $d, $class, $details = false ) {
		$rows = self::cost_rows( $d );
		if ( ! $rows ) {
			return '<p>' . esc_html__( 'No data: the cost per plugin step did not finish.', 'inochi-pulso' ) . '</p>';
		}
		$o  = '<p class="description muted">' . esc_html( self::cost_note( $d ) ) . '</p>';
		$o .= '<table class="' . esc_attr( $class ) . '"><thead><tr><th>' . esc_html__( 'Plugin', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'Status', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'Autoload', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'Own tables', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'Cron events', 'inochi-pulso' ) . '</th>';
		if ( $d['has_actions'] ) {
			$o .= '<th>' . esc_html__( 'Scheduled actions', 'inochi-pulso' ) . '</th>';
		}
		$o .= '</tr></thead><tbody>';
		foreach ( $rows as $c ) {
			$lines = self::cost_details( $c['raw'] );
			$list  = $lines ? '<ul class="d"><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>' : '';
			$name  = esc_html( $c['label'] );
			if ( $list ) {
				$name = $details ? '<strong>' . $name . '</strong>' . $list : '<details><summary>' . $name . '</summary>' . $list . '</details>';
			}
			$o .= '<tr><td>' . $name . '</td><td>' . esc_html( $c['status'] ) . '</td><td>' . esc_html( $c['autoload'] ) . '</td><td>' . esc_html( $c['tables'] ) . '</td><td>' . esc_html( $c['cron'] ) . '</td>';
			if ( $d['has_actions'] ) {
				$o .= '<td>' . esc_html( $c['actions'] ) . '</td>';
			}
			$o .= '</tr>';
		}
		return $o . '</tbody></table>';
	}

	/**
	 * One priority (HTML).
	 *
	 * @param array  $p     Priority.
	 * @param string $tag   Heading tag.
	 * @param string $class Wrapper class.
	 */
	public static function priority( array $p, $tag, $class ) {
		$tag = in_array( $tag, array( 'h3', 'h4' ), true ) ? $tag : 'h3';
		$o   = '<div class="' . esc_attr( $class ) . '"><' . $tag . '><code>' . esc_html( $p['code'] ) . '</code> ' . esc_html( $p['title'] ) . '</' . $tag . '>';
		$o  .= '<p>' . esc_html( $p['cost'] ) . '</p>';
		$o  .= '<p><strong>' . esc_html__( 'Today:', 'inochi-pulso' ) . '</strong> ' . esc_html( $p['value'] );
		if ( $p['target'] ) {
			$o .= ' · <strong>' . esc_html__( 'Target:', 'inochi-pulso' ) . '</strong> ' . esc_html( $p['target'] );
		}
		$o .= '</p><p><strong>' . esc_html__( 'What to do:', 'inochi-pulso' ) . '</strong></p><ul>';
		foreach ( array_filter( explode( "\n", $p['proposal'] ) ) as $line ) {
			$o .= '<li>' . esc_html( $line ) . '</li>';
		}
		$o .= '</ul><p class="description muted"><strong>' . esc_html__( 'How to check it:', 'inochi-pulso' ) . '</strong> ' . esc_html( $p['verify'] ) . '</p></div>';
		return $o;
	}

	/**
	 * Table of every check, grouped by area (HTML).
	 *
	 * @param array  $rows     Rows.
	 * @param string $class    Table class.
	 * @param bool   $evidence Show evidence toggles.
	 */
	public static function findings_table( array $rows, $class, $evidence = true ) {
		$o    = '<table class="' . esc_attr( $class ) . '"><thead><tr><th>' . esc_html__( 'Code', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'Check', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'Measured value', 'inochi-pulso' ) . '</th><th>' . esc_html__( 'State', 'inochi-pulso' ) . '</th></tr></thead><tbody>';
		$area = '';
		foreach ( $rows as $r ) {
			if ( $r['area'] !== $area ) {
				$area = $r['area'];
				$o   .= '<tr class="ip-area"><th colspan="4">' . esc_html( Inochi_Pulso_Catalog::area_label( $area ) ) . '</th></tr>';
			}
			$ev = ( $evidence && '' !== $r['evidence'] ) ? '<details><summary>' . esc_html__( 'Evidence', 'inochi-pulso' ) . '</summary><pre>' . esc_html( $r['evidence'] ) . '</pre></details>' : '';
			$o .= '<tr><td><code>' . esc_html( $r['code'] ) . '</code></td><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( '' !== $r['value'] ? $r['value'] : '—' ) . $ev . '</td>';
			$o .= '<td class="s-' . esc_attr( sanitize_html_class( $r['state'] ) ) . '"><span class="ip-state ' . esc_attr( self::state_class( $r['state'] ) ) . '">' . esc_html( Inochi_Pulso_Catalog::state_label( $r['state'] ) ) . '</span></td></tr>';
		}
		return $o . '</tbody></table>';
	}

	/**
	 * CSS class of a state.
	 *
	 * @param string $state State key.
	 */
	public static function state_class( $state ) {
		$map = array(
			'OK'        => 'ip-ok',
			'Aviso'     => 'ip-aviso',
			'Fallo'     => 'ip-fallo',
			'N.A.'      => 'ip-na',
			'Sin datos' => 'ip-empty',
		);
		return $map[ $state ] ?? 'ip-empty';
	}
}
