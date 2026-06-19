<?php
/**
 * WP-CLI: `wp hajlajty import` — import meczów z api-football do CPT „mecz".
 * CIENKI wrapper: guard + add_command + callback, który woła reużywalny rdzeń
 * (runner.php) i opakowuje jego strukturalny wynik w kanały WP-CLI. Cała logika
 * (collect → process_fixture → upsert) żyje w runner.php, żeby wołał ją też cron
 * (3e-iv-a) — jedno źródło, dwa wejścia.
 *
 * Reguły importu (CLAUDE.md #7/#10, plan Faza 2) opisane przy logice w runner.php:
 * dedup po `fixture_id`, slug TYLKO przy insert, post_date = czas publikacji
 * (wariant B), taksonomie po stabilnych ID (api_id / league.id / league.season).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

WP_CLI::add_command( 'hajlajty import', 'hajlajty_import_command' );

/**
 * Importuje mecze z api-football do CPT „mecz".
 *
 * ## OPTIONS
 *
 * [--fixture=<id>]
 * : Pojedynczy mecz po api-football fixture.id.
 *
 * [--league=<id>]
 * : api-football league.id (wymaga --season).
 *
 * [--season=<rok>]
 * : Sezon, np. 2026 (wymaga --league).
 *
 * ## EXAMPLES
 *
 *     wp hajlajty import --fixture=1539000
 *     wp hajlajty import --league=1 --season=2026
 *
 * @when after_wp_load
 *
 * @param array $args       Nieużywane.
 * @param array $assoc_args Flagi: fixture, league, season.
 */
function hajlajty_import_command( $args, $assoc_args ) {
	$fixtures = hajlajty_import_collect_fixtures( $assoc_args );
	if ( is_wp_error( $fixtures ) ) {
		WP_CLI::error( $fixtures->get_error_message() );
	}
	if ( empty( $fixtures ) ) {
		WP_CLI::warning( 'api-football zwróciło pustą listę fixtures dla podanych parametrów.' );
		return;
	}

	$counts = hajlajty_import_run_batch( $fixtures );

	WP_CLI::success(
		sprintf(
			'Import zakończony: dodano %d, zaktualizowano %d, pominięto %d.',
			$counts['inserted'],
			$counts['updated'],
			$counts['skipped']
		)
	);
}
