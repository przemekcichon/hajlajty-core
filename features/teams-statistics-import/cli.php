<?php
/**
 * WP-CLI: `wp hajlajty team-stats` — import statystyk drużyny z api-football
 * `/teams/statistics` na term „druzyna". RĘCZNA brama (mirror `wp hajlajty
 * standings`): pierwszy import danej pary (liga, sezon) odpala człowiek; cykliczne
 * odświeżanie znanych par przejmuje cron (cron.php) — ta sama logika
 * `hajlajty_team_stats_import_run()` (runner.php).
 *
 * Orkiestracja pętli żyje TU (rdzeń runnera importuje JEDNĄ drużynę): z `--team`
 * importujemy jedną, bez `--team` — iterujemy wszystkie termy „druzyna" z meta
 * `api_id` (zaseedowany roster). Wynik zbiorczy: ile drużyn, zapisane, pominięte
 * (brak termu / puste response).
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

WP_CLI::add_command( 'hajlajty team-stats', 'hajlajty_team_stats_command' );

/**
 * Importuje statystyki drużyn dla ligi i sezonu.
 *
 * ## OPTIONS
 *
 * --league=<id>
 * : api-football league.id (np. 1 = World Cup).
 *
 * --season=<rok>
 * : Sezon, np. 2026.
 *
 * [--team=<api_id>]
 * : Tylko jedna drużyna (term meta `api_id`). Bez tej flagi: cały zaseedowany roster.
 *
 * ## EXAMPLES
 *
 *     wp hajlajty team-stats --league=1 --season=2026 --team=1113
 *     wp hajlajty team-stats --league=1 --season=2026
 *
 * @when after_wp_load
 *
 * @param array $args       Nieużywane.
 * @param array $assoc_args Flagi: league, season, team.
 */
function hajlajty_team_stats_command( $args, $assoc_args ) {
	$league = isset( $assoc_args['league'] ) ? (int) $assoc_args['league'] : 0;
	$season = isset( $assoc_args['season'] ) ? $assoc_args['season'] : '';

	if ( $league <= 0 || '' === hajlajty_team_stats_normalize_season( $season ) ) {
		WP_CLI::error( 'Podaj --league=<id> (>0) i --season=<rok>.' );
	}

	// Pojedyncza drużyna.
	if ( isset( $assoc_args['team'] ) ) {
		$result = hajlajty_team_stats_import_run( (int) $assoc_args['team'], $league, $season );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success(
			sprintf(
				'Statystyki zapisane: druzyna #%d (api_id %d), liga %d, sezon %s → meta „%s".',
				$result['term_id'],
				$result['api_id'],
				$result['league_id'],
				$result['season'],
				$result['key']
			)
		);
		return;
	}

	// Cały roster: iterujemy wszystkie termy „druzyna" z `api_id`. ŚWIADOMY kompromis
	// (#8 — bez abstrakcji na zapas): bez --team odpytujemy WSZYSTKIE drużyny, także
	// te spoza danej ligi. Dla rostera WŚ (~48 reprezentacji) to bez znaczenia. Gdy
	// roster urośnie o kluby (Faza 5), nie-uczestnicy zwrócą puste response → skip
	// (jedno zapytanie każdy). Wtedy warto filtrować po przynależności do ligi — ale
	// ta mapa jeszcze nie istnieje, więc nie dorabiamy jej teraz. Cron jest już
	// oszczędny: odświeża tylko pary, które ktoś realnie zaimportował (cron.php).
	$api_ids = hajlajty_team_stats_all_team_api_ids();
	if ( empty( $api_ids ) ) {
		WP_CLI::error( 'Brak drużyn z meta api_id — zaseeduj roster (wp hajlajty roster).' );
	}

	$total   = count( $api_ids );
	$saved   = 0;
	$skipped = 0;
	foreach ( $api_ids as $i => $api_id ) {
		$result = hajlajty_team_stats_import_run( $api_id, $league, $season );
		if ( ! is_wp_error( $result ) ) {
			++$saved;
			continue;
		}

		// Błąd ŁAGODNY i lokalny dla jednej drużyny: brak zaseedowanego termu albo
		// drużyna nie gra w tej lidze (puste response). Pomijamy ją i lecimy dalej —
		// to normalny przebieg sweepu, nie awaria.
		if ( in_array( $result->get_error_code(), array( 'hajlajty_team_stats_no_term', 'hajlajty_team_stats_empty' ), true ) ) {
			++$skipped;
			continue;
		}

		// Błąd TRANSPORTU/KONFIGURACJI (HTTP, wyczerpany limit api-football, brak
		// klucza, błąd w ciele) dotyczy CAŁEGO sweepu, nie tej jednej drużyny —
		// kolejne zapytania to czysta strata limitu i powtórzą ten sam błąd.
		// Przerywamy i raportujemy, jak daleko doszliśmy (brama budżetowa, #8).
		WP_CLI::error(
			sprintf(
				'Sweep przerwany na drużynie api_id %d (%d/%d): %s. Dotąd zapisane %d, pominięte %d.',
				$api_id,
				$i + 1,
				$total,
				$result->get_error_message(),
				$saved,
				$skipped
			)
		);
	}

	// Po tej pętli `pominięte` znaczy WYŁĄCZNIE „brak termu / puste response" —
	// błędy transportu już by ją przerwały powyżej, więc etykieta jest dokładna.
	WP_CLI::success(
		sprintf(
			'Team-stats (liga %d, sezon %s): drużyn %d, zapisane %d, pominięte %d (brak termu / drużyna spoza ligi).',
			$league,
			hajlajty_team_stats_normalize_season( $season ),
			$total,
			$saved,
			$skipped
		)
	);
}

/**
 * Wszystkie `api_id` drużyn z zaseedowanego rostera (term meta „api_id" w
 * taksonomii „druzyna"). Pusta lista = brak seedu. Orkiestracja pętli rostera —
 * stąd tu, w cli.php, nie w rdzeniu runnera (#8: nie uogólniaj na zapas).
 *
 * @return int[] Lista api_id (>0), może być pusta.
 */
function hajlajty_team_stats_all_team_api_ids() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'druzyna',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}

	$api_ids = array();
	foreach ( $terms as $term_id ) {
		$api_id = (int) get_term_meta( (int) $term_id, 'api_id', true );
		if ( $api_id > 0 ) {
			$api_ids[] = $api_id;
		}
	}
	return $api_ids;
}
