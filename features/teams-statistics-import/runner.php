<?php
/**
 * Reużywalny rdzeń importu statystyk drużyny: resolve term „druzyna" po `api_id`
 * → fetch `/teams/statistics` → transform → zapis `team_stats_<league>_<season>`.
 * Ładowany ZAWSZE (bez guardu WP-CLI), żeby wołał go i `wp hajlajty team-stats`
 * (cli.php), i WP-Cron (cron.php) — jedno źródło logiki, dwa wejścia (wzorzec ze
 * standings-import/runner.php).
 *
 * Endpoint `/teams/statistics?league&season&team` jest z natury PER DRUŻYNĘ
 * (wymaga `team`). Ten rdzeń importuje JEDNĄ drużynę; orkiestracja pętli (cały
 * roster / `--team`) żyje w cli.php, a cykliczne odświeżanie — w cron.php.
 *
 * Kontrakt logowania: funkcje tu NIE wołają `WP_CLI::*` (poza zasięgiem crona) —
 * logują przez `hajlajty_import_log` (współdzielony z match-import/client.php),
 * a wynik zwracają STRUKTURALNIE (liczniki / WP_Error). Decyzję o
 * `WP_CLI::success/error` podejmuje wrapper CLI.
 *
 * Zapis to JEDNO `update_term_meta` kluczem `team_stats_<league>_<season>` — bez
 * read-modify-write: import danej (drużyna×liga×sezon) nadpisuje WYŁĄCZNIE swój
 * wiersz meta, inne pary (inna liga/sezon) zostają nietknięte.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Prefiks dynamicznego klucza meta statystyk (na termie „druzyna"). */
const HAJLAJTY_TEAM_STATS_META_PREFIX = 'team_stats_';

/**
 * Importuje statystyki JEDNEJ drużyny dla (liga, sezon) i zapisuje na jej termie
 * „druzyna". Resolucja termu po `api_id` (stabilne ID, NIGDY po nazwie EN).
 *
 * @param int|string $team_api_id api-football `team.id` = term meta `api_id` drużyny.
 * @param int        $league_id   api-football `league.id` (parametr zapytania + klucz meta).
 * @param int|string $season      Sezon, np. 2026 (tylko cyfry trafiają do klucza meta).
 * @return array{term_id:int,api_id:int,league_id:int,season:string,key:string}|WP_Error
 */
function hajlajty_team_stats_import_run( $team_api_id, $league_id, $season ) {
	$team_api_id = (int) $team_api_id;
	$league_id   = (int) $league_id;
	$season      = hajlajty_team_stats_normalize_season( $season );

	if ( $team_api_id <= 0 || $league_id <= 0 || '' === $season ) {
		return new WP_Error(
			'hajlajty_team_stats_args',
			'Podaj team api_id (>0), --league=<id> (>0) i --season=<rok>.'
		);
	}

	// Resolucja termu „druzyna" PRZED zapytaniem do API — brak seedu = nie ma
	// gdzie zapisać, więc nie marnujemy zapytania. Termu NIE tworzymy z importu
	// (#10: roster seedujemy z CSV; ten slice tylko wzbogaca istniejący term).
	$term_id = hajlajty_team_stats_find_druzyna_term( $team_api_id );
	if ( ! $term_id ) {
		hajlajty_import_log(
			sprintf( 'team-stats: brak termu „druzyna" dla api_id %d — zaseeduj roster. Pomijam.', $team_api_id ),
			'warning'
		);
		return new WP_Error( 'hajlajty_team_stats_no_term', sprintf( 'Brak termu „druzyna" dla api_id %d.', $team_api_id ) );
	}

	$response = hajlajty_import_request(
		'teams/statistics',
		array(
			'league' => $league_id,
			'season' => (int) $season,
			'team'   => $team_api_id,
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response; // klient już zalogował szczegóły; wrapper zamieni na błąd CLI.
	}

	$curated = hajlajty_team_stats_transform( $response, $league_id, $season );
	if ( empty( $curated ) ) {
		hajlajty_import_log(
			sprintf(
				'team-stats: puste/niepoprawne response dla api_id %d (liga %d, sezon %s) — nic nie zapisuję (zła liga/sezon/drużyna?).',
				$team_api_id,
				$league_id,
				$season
			),
			'warning'
		);
		return new WP_Error( 'hajlajty_team_stats_empty', 'Puste response z /teams/statistics.' );
	}

	$key  = hajlajty_team_stats_meta_key( $league_id, $season );
	$json = wp_json_encode( $curated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	update_term_meta( $term_id, $key, $json );

	hajlajty_import_log(
		sprintf(
			'team-stats zapisane: druzyna term #%d (api_id %d), liga %d, sezon %s → meta „%s".',
			$term_id,
			$team_api_id,
			$league_id,
			$season,
			$key
		)
	);

	return array(
		'term_id'   => $term_id,
		'api_id'    => $team_api_id,
		'league_id' => $league_id,
		'season'    => $season,
		'key'       => $key,
	);
}

/**
 * Klucz meta statystyk dla (liga, sezon): `team_stats_<league_id>_<season>`.
 * Namespace po OBU, bo term „druzyna" jest ligo-agnostyczny (patrz nagłówek
 * slice'a). Sezon już znormalizowany do cyfr przez wołającego.
 *
 * @param int        $league_id
 * @param int|string $season
 * @return string
 */
function hajlajty_team_stats_meta_key( $league_id, $season ) {
	return HAJLAJTY_TEAM_STATS_META_PREFIX . (int) $league_id . '_' . $season;
}

/**
 * Normalizuje sezon do samych cyfr (bezpieczny segment klucza meta). „2026" →
 * „2026"; cokolwiek innego (puste/litery) → „". Mirror standings.
 *
 * @param int|string $season
 * @return string
 */
function hajlajty_team_stats_normalize_season( $season ) {
	return (string) preg_replace( '/\D/', '', (string) $season );
}

/**
 * Term_id taksonomii „druzyna" po term meta `api_id` (stabilne ID, NIGDY po
 * nazwie). Slice trzyma własny resolver, by nie zależeć od runnera fixtures —
 * ta sama konwencja meta (`api_id`) co seed/import, ale własność slice'a.
 *
 * @param int $api_id
 * @return int term_id albo 0.
 */
function hajlajty_team_stats_find_druzyna_term( $api_id ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'druzyna',
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
			'meta_query' => array(
				array(
					'key'   => 'api_id',
					'value' => (string) (int) $api_id,
				),
			),
		)
	);

	return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? (int) $terms[0] : 0;
}
