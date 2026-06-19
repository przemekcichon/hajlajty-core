<?php
/**
 * WP-CLI: `wp hajlajty import-live` — odświeżenie meczów W TOKU jednym przebiegiem
 * (3e-ii). RĘCZNA komenda: człowiek odpala ją podczas trwających meczów, żeby front
 * (single + listy) pokazał świeżą minutę/wynik/oś po F5. Automatyzacja w oknach to
 * cron 3e-iv-a (cron.php) — woła TĘ SAMĄ logikę co ta komenda.
 *
 * CIENKI wrapper: guard + add_command + callback. Cała orkiestracja (request
 * `fixtures?live=all` → filtr po śledzonych ligach → UPDATE-ONLY pre-check →
 * process_fixture) żyje w `hajlajty_import_live_run()` (runner.php), reużywana
 * przez cron. Tu tylko: resolucja lig z flag + zamiana liczników na WP_CLI::success.
 *
 * REUŻYWA pipeline'u importu (client + transform + upsert): każdy element
 * `fixtures?live=…` ma TEN SAM kształt co zwykły `fixtures`. Składy live z
 * `/fixtures/lineups` (już w process_fixture) — bez `/fixtures/players` (D3.6).
 *
 * OGRANICZENIE domknięte w 3e-iv-a: mecz tuż po `FT` znika z `live=…`, więc
 * import-live już go nie dotknie. Cron z auto-FT (cron.php) domyka go sam
 * targetowanym `fixtures?id=<id>`; ręcznie nadal: `wp hajlajty import --fixture=<id>`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

WP_CLI::add_command( 'hajlajty import-live', 'hajlajty_import_live_command' );

/**
 * Odświeża mecze trwające w śledzonych ligach (jeden request `fixtures?live=…`).
 *
 * ## OPTIONS
 *
 * [--league=<ids>]
 * : Lista league.id (np. `1-2-39` albo `1,2,39`). Domyślnie: wszystkie śledzone
 *   ligi (term meta `league_id` taksonomii „rozgrywki").
 *
 * ## EXAMPLES
 *
 *     wp hajlajty import-live
 *     wp hajlajty import-live --league=1
 *
 * @when after_wp_load
 *
 * @param array $args       Nieużywane.
 * @param array $assoc_args Flagi: league.
 */
function hajlajty_import_live_command( $args, $assoc_args ) {
	$leagues = isset( $assoc_args['league'] )
		? hajlajty_import_live_parse_leagues( (string) $assoc_args['league'] )
		: hajlajty_import_live_tracked_leagues();

	if ( empty( $leagues ) ) {
		WP_CLI::error( 'Brak śledzonych lig (term meta „league_id" w „rozgrywki"). Zaseeduj rozgrywki albo podaj --league=<id>.' );
	}

	$counts = hajlajty_import_live_run( $leagues );
	if ( is_wp_error( $counts ) ) {
		WP_CLI::error( $counts->get_error_message() );
	}

	if ( 0 === $counts['live_total'] ) {
		WP_CLI::success( 'Brak meczów na żywo nigdzie — nic do odświeżenia.' );
		return;
	}

	WP_CLI::success(
		sprintf(
			'Live odświeżone: zaktualizowano %d, poza bazą %d, pominięto %d.',
			$counts['updated'],
			$counts['absent'],
			$counts['skipped']
		)
	);
}
