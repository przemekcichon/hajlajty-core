<?php
/**
 * WP-CLI: `wp hajlajty import-live` — odświeżenie meczów W TOKU jednym przebiegiem
 * (3e-ii). RĘCZNA komenda (bez crona — automatyzacja w oknach to 3e-iv): człowiek
 * odpala ją podczas trwających meczów, żeby front (single + listy) pokazał świeżą
 * minutę/wynik/oś po F5.
 *
 * REUŻYWA pipeline'u importu (client + transform + upsert): każdy element
 * `fixtures?live=…` ma TEN SAM kształt co zwykły `fixtures`, więc karmimy nim
 * istniejące `hajlajty_import_process_fixture()`. Skutkiem zapis idzie do
 * `match_data` + płaskiej meta `status` (3e-i) tak samo jak przy zwykłym imporcie
 * — żadnego równoległego renderera/magazynu. Składy live bierzemy z
 * `/fixtures/lineups` (już podpięte w process_fixture), więc NIE potrzeba
 * `/fixtures/players` (D3.6 rozwiązane: zero nowego mapowania).
 *
 * Dwie świadome decyzje (różnica wobec zwykłego importu):
 *  1. ŚLEDZONE LIGI ONLY: pytamy `fixtures?live=all`, a zawężamy do śledzonych
 *     lig PO STRONIE KLIENTA (po `league.id`). Param `live` w api-football
 *     przyjmuje TYLKO `all` albo listę z myślnikami `id-id-id` (≥2 ligi) — odrzuca
 *     pojedyncze `live=1`. `live=all` jest jedyną formą ważną dla DOWOLNEJ liczby
 *     śledzonych lig (także jednej). Detali (events/lineups/stats) i tak nie
 *     pobieramy dla nietrackowanych meczów — gatekeeperem jest filtr `league.id`
 *     + pre-check istnienia posta (niżej), zanim dotkniemy API/bazy.
 *  2. UPDATE-ONLY: import-live tylko ODŚWIEŻA istniejące mecze (pre-check po
 *     `fixture_id` PRZED process_fixture). Tworzenie wpisów z terminarza zostaje
 *     przy zwykłym `wp hajlajty import` (jeden punkt prawdy dla slugów/insertów).
 *
 * OGRANICZENIE (do domknięcia w 3e-iv): mecz, który właśnie skończył się (FT),
 * znika z `live=…`, więc import-live już go nie dotknie — jego `status` zostaje
 * na ostatniej wartości live. Sfinalizuj go zwykłym `wp hajlajty import
 * --league=<id> --season=<rok>` po meczu (zapisze FT → wypadnie z „Na żywo").
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

	// `live=all` (jedyna forma ważna dla dowolnej liczby lig; param `live` odrzuca
	// pojedyncze `id`). Zawężenie do śledzonych lig robimy niżej, po `league.id`.
	$live = hajlajty_import_request( 'fixtures', array( 'live' => 'all' ) );
	if ( is_wp_error( $live ) ) {
		WP_CLI::error( $live->get_error_message() );
	}
	if ( empty( $live ) ) {
		WP_CLI::success( 'Brak meczów na żywo nigdzie — nic do odświeżenia.' );
		return;
	}

	$tracked = array_flip( $leagues ); // O(1) lookup po league.id.
	$counts  = array(
		'updated' => 0,
		'absent'  => 0,
		'skipped' => 0,
	);

	foreach ( $live as $fixture ) {
		// Zawężenie klienckie: ligi spoza śledzonych pomijamy ZANIM dotkniemy bazy
		// czy API (zero kosztu dla setek nietrackowanych meczów z `live=all`).
		$lid = isset( $fixture['league']['id'] ) ? (int) $fixture['league']['id'] : 0;
		if ( ! isset( $tracked[ $lid ] ) ) {
			continue;
		}

		$fid = isset( $fixture['fixture']['id'] ) ? (int) $fixture['fixture']['id'] : 0;
		if ( ! $fid ) {
			WP_CLI::warning( 'Element live bez fixture.id — pomijam.' );
			$counts['skipped']++;
			continue;
		}

		// UPDATE-ONLY + oszczędność API: jeśli mecz nie istnieje w bazie, NIE wołamy
		// process_fixture (ten pobrałby sekcje szczegółowe przed sprawdzeniem posta).
		if ( ! hajlajty_import_find_post_by_fixture_id( $fid ) ) {
			WP_CLI::log( sprintf( 'fixture %d na żywo, ale brak w bazie — pomijam (zaimportuj terminarz zwykłym importem).', $fid ) );
			$counts['absent']++;
			continue;
		}

		$result = hajlajty_import_process_fixture( $fixture );
		$counts[ 'updated' === $result ? 'updated' : 'skipped' ]++;
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

/**
 * league.id wszystkich śledzonych rozgrywek (term meta `league_id` w „rozgrywki").
 *
 * @return int[] Unikalne, dodatnie league.id (może być puste, gdy brak seedu).
 */
function hajlajty_import_live_tracked_leagues() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'rozgrywki',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}

	$ids = array();
	foreach ( $terms as $tid ) {
		$lid = (int) get_term_meta( (int) $tid, 'league_id', true );
		if ( $lid > 0 ) {
			$ids[] = $lid;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Parsuje wartość --league (`1-2-39` lub `1,2,39`) na listę dodatnich int-ów.
 *
 * @param string $raw Surowa wartość flagi.
 * @return int[] Unikalne, dodatnie league.id.
 */
function hajlajty_import_live_parse_leagues( $raw ) {
	$parts = preg_split( '/[\s,\-]+/', trim( $raw ) );
	$ids   = array();
	foreach ( (array) $parts as $p ) {
		$p = (int) $p;
		if ( $p > 0 ) {
			$ids[] = $p;
		}
	}
	return array_values( array_unique( $ids ) );
}
