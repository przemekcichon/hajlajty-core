<?php
/**
 * WP-CLI: `wp hajlajty standings` — import tabel grup z api-football /standings
 * do termu „rozgrywki". RĘCZNA brama (mirror `wp hajlajty import`): pierwszy import
 * danego sezonu robi człowiek; cykliczne odświeżanie znanych par przejmuje cron
 * (cron.php) — ta sama logika `hajlajty_standings_import_run()` (runner.php).
 *
 * CIENKI wrapper: guard WP-CLI + add_command + callback zamieniający strukturalny
 * wynik runnera na kanały WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

WP_CLI::add_command( 'hajlajty standings', 'hajlajty_standings_command' );

/**
 * Importuje tabelę grup dla ligi i sezonu.
 *
 * ## OPTIONS
 *
 * --league=<id>
 * : api-football league.id (musi mieć zaseedowany term „rozgrywki" z tym league_id).
 *
 * --season=<rok>
 * : Sezon, np. 2026.
 *
 * ## EXAMPLES
 *
 *     wp hajlajty standings --league=1 --season=2026
 *
 * @when after_wp_load
 *
 * @param array $args       Nieużywane.
 * @param array $assoc_args Flagi: league, season.
 */
function hajlajty_standings_command( $args, $assoc_args ) {
	$league = isset( $assoc_args['league'] ) ? (int) $assoc_args['league'] : 0;
	$season = isset( $assoc_args['season'] ) ? $assoc_args['season'] : '';

	$result = hajlajty_standings_import_run( $league, $season );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $result->get_error_message() );
	}

	WP_CLI::success(
		sprintf(
			'Standings zapisane: rozgrywki #%d, sezon %s — grup %d, wierszy %d, pominięto %d.',
			$result['term_id'],
			$result['season'],
			$result['groups'],
			$result['rows'],
			$result['skipped']
		)
	);
}
