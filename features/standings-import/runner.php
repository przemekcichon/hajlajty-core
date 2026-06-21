<?php
/**
 * Reużywalny rdzeń importu standings: fetch → resolve term „rozgrywki" po
 * `league_id` → transform → zapis `standings_<sezon>`. Ładowany ZAWSZE (bez
 * guardu WP-CLI), żeby wołał go i komenda `wp hajlajty standings` (cli.php), i
 * WP-Cron (cron.php) — jedno źródło logiki, dwa wejścia (wzorzec z match-import
 * runner.php).
 *
 * Kontrakt logowania: funkcje tu NIE wołają `WP_CLI::*` (poza zasięgiem crona) —
 * logują przez `hajlajty_import_log` (współdzielony z match-import/client.php),
 * a wynik zwracają STRUKTURALNIE (liczniki / WP_Error). Decyzję o
 * `WP_CLI::success/error` podejmuje wrapper CLI.
 *
 * Zapis to JEDNO `update_term_meta` kluczem `standings_<sezon>` — bez
 * read-modify-write: import danego sezonu nadpisuje WYŁĄCZNIE swój wiersz meta,
 * inne sezony (np. `standings_2022`) zostają nietknięte.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Prefiks dynamicznego klucza meta tabeli grup (na termie „rozgrywki"). */
const HAJLAJTY_STANDINGS_META_PREFIX = 'standings_';

/**
 * Importuje tabelę grup dla (league_id, sezon) i zapisuje na termie „rozgrywki".
 *
 * @param int        $league_id api-football `league.id` (klucz resolucji termu).
 * @param int|string $season    Sezon, np. 2026 (tylko cyfry trafiają do klucza meta).
 * @return array{term_id:int,league_id:int,season:string,groups:int,rows:int,skipped:int}|WP_Error
 */
function hajlajty_standings_import_run( $league_id, $season ) {
	$league_id = (int) $league_id;
	$season    = hajlajty_standings_normalize_season( $season );

	if ( $league_id <= 0 || '' === $season ) {
		return new WP_Error( 'hajlajty_standings_args', 'Podaj --league=<id> (>0) i --season=<rok>.' );
	}

	// Resolucja termu „rozgrywki" PRZED zapytaniem do API — brak seedu = nie ma gdzie
	// zapisać, więc nie marnujemy zapytania. Term NIE jest tworzony z importu (#10:
	// rozgrywki seedujemy z CSV; standings tylko wzbogaca istniejący term).
	$term_id = hajlajty_standings_find_rozgrywki_term( $league_id );
	if ( ! $term_id ) {
		hajlajty_import_log(
			sprintf( 'standings: brak termu „rozgrywki" dla league_id %d — zaseeduj rozgrywki. Pomijam.', $league_id ),
			'warning'
		);
		return new WP_Error( 'hajlajty_standings_no_term', sprintf( 'Brak termu „rozgrywki" dla league_id %d.', $league_id ) );
	}

	$response = hajlajty_import_request( 'standings', array( 'league' => $league_id, 'season' => (int) $season ) );
	if ( is_wp_error( $response ) ) {
		return $response; // klient już zalogował szczegóły; wrapper zamieni na błąd CLI.
	}

	$table = hajlajty_standings_transform( $response );
	if ( empty( $table ) ) {
		hajlajty_import_log(
			sprintf( 'standings: zero grup A–L dla league_id %d, sezon %s — nic nie zapisuję (zła liga/sezon?).', $league_id, $season ),
			'warning'
		);
		return new WP_Error( 'hajlajty_standings_empty', 'Brak grup A–L w odpowiedzi /standings.' );
	}

	$json = wp_json_encode( $table, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	update_term_meta( $term_id, HAJLAJTY_STANDINGS_META_PREFIX . $season, $json );

	$groups  = count( $table );
	$rows    = array_sum( array_map( 'count', $table ) );
	$skipped = max( 0, hajlajty_standings_count_input_rows( $response ) - $rows );

	hajlajty_import_log(
		sprintf(
			'standings zapisane: rozgrywki term #%d (league_id %d), sezon %s → grup %d, wierszy %d, pominięto %d (Group Stage / braki).',
			$term_id,
			$league_id,
			$season,
			$groups,
			$rows,
			$skipped
		)
	);

	return array(
		'term_id'   => $term_id,
		'league_id' => $league_id,
		'season'    => $season,
		'groups'    => $groups,
		'rows'      => $rows,
		'skipped'   => $skipped,
	);
}

/**
 * Normalizuje sezon do samych cyfr (bezpieczny segment klucza meta). „2026" →
 * „2026"; cokolwiek innego (puste/litery) → „".
 *
 * @param int|string $season
 * @return string
 */
function hajlajty_standings_normalize_season( $season ) {
	return (string) preg_replace( '/\D/', '', (string) $season );
}

/**
 * Term_id taksonomii „rozgrywki" po term meta `league_id` (stabilne ID, NIGDY po
 * nazwie). Slice trzyma własny resolver, by nie zależeć od runnera fixtures —
 * to ta sama konwencja meta (`league_id`) co seed/import, ale własność slice'a.
 *
 * @param int $league_id
 * @return int term_id albo 0.
 */
function hajlajty_standings_find_rozgrywki_term( $league_id ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'rozgrywki',
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
			'meta_query' => array(
				array(
					'key'   => 'league_id',
					'value' => (string) (int) $league_id,
				),
			),
		)
	);

	return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? (int) $terms[0] : 0;
}
