<?php
/**
 * Reużywalny rdzeń importu meczów: pipeline pojedynczego fixture'a + orkiestracja
 * live. Ładowany ZAWSZE (bez guardu WP-CLI), żeby wołał go i `wp hajlajty import`/
 * `import-live` (cienkie wrappery w cli.php/cli-live.php), i WP-Cron (3e-iv-a).
 *
 * Dlaczego osobny plik: do 3e-iv-a logika ta żyła ZA `if ( ! WP_CLI ) return;`
 * w cli.php/cli-live.php, więc callback crona nie miał czego wywołać. Wydzielenie
 * (refaktor, zero zmiany zachowania) daje JEDNO źródło logiki wołane z dwóch
 * miejsc. Reguła rozstrzygająca z CLAUDE.md („czy ten kod zniknie razem z tą
 * funkcją?"): import to rdzeń slice'a match-import, więc zostaje w slice — komendy
 * i cron to tylko jego wejścia.
 *
 * Kontrakt logowania: funkcje tu NIE wołają `WP_CLI::*` (poza zasięgiem crona) —
 * logują przez `hajlajty_import_log` (client.php), które pod CLI używa kanałów
 * WP-CLI, a pod cronem pisze do error_log. Wynik zwracają STRUKTURALNIE (liczniki
 * / kod / WP_Error); decyzję o `WP_CLI::success`/`error` podejmuje wrapper CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zbiera listę fixtures wg flag: --fixture albo --league + --season.
 *
 * @param array $assoc_args Flagi: fixture, league, season.
 * @return array|WP_Error Lista elementów `fixtures` albo WP_Error.
 */
function hajlajty_import_collect_fixtures( $assoc_args ) {
	if ( isset( $assoc_args['fixture'] ) ) {
		return hajlajty_import_request( 'fixtures', array( 'id' => (int) $assoc_args['fixture'] ) );
	}

	if ( isset( $assoc_args['league'], $assoc_args['season'] ) ) {
		return hajlajty_import_request(
			'fixtures',
			array(
				'league' => (int) $assoc_args['league'],
				'season' => (int) $assoc_args['season'],
			)
		);
	}

	return new WP_Error( 'hajlajty_import_args', 'Podaj --fixture=<id> albo --league=<id> --season=<rok>.' );
}

/**
 * Przetwarza listę fixtures (insert/update/skip każdego). Zwraca liczniki —
 * wrapper CLI zamienia je na `WP_CLI::success`. Reużywane przez cron i komendę.
 *
 * @param array $fixtures Lista elementów odpowiedzi `fixtures`.
 * @return array{inserted:int,updated:int,skipped:int}
 */
function hajlajty_import_run_batch( $fixtures ) {
	$counts = array(
		'inserted' => 0,
		'updated'  => 0,
		'skipped'  => 0,
	);

	foreach ( (array) $fixtures as $fixture ) {
		$result = hajlajty_import_process_fixture( $fixture );
		$counts[ $result ]++;
	}

	return $counts;
}

/**
 * Przetwarza jeden fixture: pobiera sekcje, buduje match_data, upsertuje post.
 *
 * @param array $fixture Jeden element odpowiedzi `fixtures`.
 * @return string inserted|updated|skipped
 */
function hajlajty_import_process_fixture( $fixture ) {
	if ( empty( $fixture['fixture']['id'] ) ) {
		hajlajty_import_log( 'Element fixtures bez fixture.id — pomijam.', 'warning' );
		return 'skipped';
	}

	$fixture_id = (int) $fixture['fixture']['id'];
	$status     = (string) ( $fixture['fixture']['status']['short'] ?? '' );
	$home_id    = (int) ( $fixture['teams']['home']['id'] ?? 0 );
	$away_id    = (int) ( $fixture['teams']['away']['id'] ?? 0 );

	// Sekcje szczegółowe pobieramy tylko, gdy mecz ma realny przebieg
	// (oszczędność limitu API; zapowiedź NS i tak zwraca puste response).
	$events     = array();
	$lineups    = array();
	$statistics = array();
	if ( hajlajty_import_has_detail( $status ) ) {
		$events     = hajlajty_import_request_or_empty( 'fixtures/events', array( 'fixture' => $fixture_id ) );
		$lineups    = hajlajty_import_request_or_empty( 'fixtures/lineups', array( 'fixture' => $fixture_id ) );
		$statistics = hajlajty_import_request_or_empty( 'fixtures/statistics', array( 'fixture' => $fixture_id ) );
	}

	$match_data = hajlajty_import_build_match_data( $fixture, $events, $lineups, $statistics );
	$json       = wp_json_encode( $match_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	// Termin meczu w UTC do płaskiej meta `kickoff` (grupa 3, klucz sortujący —
	// MySQL nie sortuje po wartości wewnątrz match_data JSON). NIE trafia już do
	// post_date (wariant B): przyszły kickoff wpychał post w status 'future'.
	$kickoff     = (string) ( $fixture['fixture']['date'] ?? '' );
	$ts          = $kickoff ? strtotime( $kickoff ) : false;
	$gmt_kickoff = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';

	$existing_id = hajlajty_import_find_post_by_fixture_id( $fixture_id );

	if ( $existing_id ) {
		// UPDATE: odświeżamy dane (meta); NIE ruszamy slug/tytułu (redakcja) ani
		// post_date (= czas publikacji, wariant B). Wyjątek: post osierocony w
		// 'future' przez starą Fazę 2 (post_date = przyszły kickoff). WP wymusza
		// 'future' DOPÓKI post_date jest w przyszłości, więc samo post_status=
		// publish nie wystarczy — przesuwamy post_date na teraz i publikujemy.
		if ( 'future' === get_post_status( $existing_id ) ) {
			$now_gmt = current_time( 'mysql', true );
			wp_update_post(
				array(
					'ID'            => $existing_id,
					'post_status'   => 'publish',
					'post_date'     => get_date_from_gmt( $now_gmt ),
					'post_date_gmt' => $now_gmt,
					'edit_date'     => true, // bez tego WP nie zmieni post_date.
				)
			);
		}
		update_post_meta( $existing_id, 'match_data', $json );
		update_post_meta( $existing_id, 'fixture_id', $fixture_id );
		if ( $gmt_kickoff ) {
			update_post_meta( $existing_id, 'kickoff', $gmt_kickoff );
		}
		if ( '' !== $status ) {
			// Płaski klucz filtra list (grupa 3) — surowy short, motyw mapuje na stan.
			update_post_meta( $existing_id, 'status', $status );
		}
		hajlajty_import_assign_taxonomies( $existing_id, $home_id, $away_id, $fixture );

		hajlajty_import_log( sprintf( 'fixture %d → update posta #%d', $fixture_id, $existing_id ) );
		return 'updated';
	}

	// INSERT: slug budujemy RAZ z PL nazw drużyn (resolucja po api_id).
	$home_name = hajlajty_import_team_name_by_api_id( $home_id );
	$away_name = hajlajty_import_team_name_by_api_id( $away_id );
	if ( null === $home_name || null === $away_name ) {
		hajlajty_import_log(
			sprintf(
				'fixture %d: brak termu „druzyna" dla api_id %d — zaseeduj drużynę przed importem. Pomijam (slug musi być stabilny).',
				$fixture_id,
				null === $home_name ? $home_id : $away_id
			),
			'warning'
		);
		return 'skipped';
	}

	$slug = hajlajty_match_build_slug( $home_name, $away_name, hajlajty_import_kickoff_date( $kickoff ) );

	// post_date NIE jest ustawiany — WP użyje czasu utworzenia (teraz), więc mecz
	// jest 'publish' od razu, także zapowiedź z przyszłym kickoffem (wariant B).
	$postarr = array(
		'post_type'   => 'mecz',
		'post_status' => 'publish',
		'post_title'  => $home_name . ' – ' . $away_name,
		'post_name'   => $slug,
	);

	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		hajlajty_import_log( sprintf( 'fixture %d: nie udało się utworzyć posta: %s', $fixture_id, $post_id->get_error_message() ), 'warning' );
		return 'skipped';
	}

	update_post_meta( $post_id, 'match_data', $json );
	update_post_meta( $post_id, 'fixture_id', $fixture_id );
	if ( $gmt_kickoff ) {
		update_post_meta( $post_id, 'kickoff', $gmt_kickoff );
	}
	if ( '' !== $status ) {
		// Płaski klucz filtra list (grupa 3) — surowy short, motyw mapuje na stan.
		update_post_meta( $post_id, 'status', $status );
	}
	hajlajty_import_assign_taxonomies( $post_id, $home_id, $away_id, $fixture );

	hajlajty_import_log( sprintf( 'fixture %d → nowy post #%d (slug: %s)', $fixture_id, $post_id, $slug ) );
	return 'inserted';
}

/**
 * Czy pobierać sekcje szczegółowe? Nie dla statusów „bez przebiegu"
 * (zapowiedź / przełożony / odwołany) — oszczędza zapytania do API.
 */
function hajlajty_import_has_detail( $status_short ) {
	$no_play = array( 'NS', 'TBD', 'PST', 'CANC' );
	return '' !== $status_short && ! in_array( $status_short, $no_play, true );
}

/**
 * Znajduje post „mecz" po meta `fixture_id`. Zwraca ID albo 0.
 */
function hajlajty_import_find_post_by_fixture_id( $fixture_id ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'mecz',
			'post_status'    => 'any',
			'meta_key'       => 'fixture_id',
			'meta_value'     => $fixture_id,
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		)
	);

	return $query->have_posts() ? (int) $query->posts[0] : 0;
}

/**
 * Przypisuje taksonomie zaimportowanego meczu. Brak termu drużyny/rozgrywek =
 * ostrzeżenie + pominięcie tej taksonomii (seed jest warunkiem importu).
 */
function hajlajty_import_assign_taxonomies( $post_id, $home_id, $away_id, $fixture ) {
	// druzyna ×2 (po api_id).
	$team_term_ids = array();
	foreach ( array( $home_id, $away_id ) as $api_id ) {
		$tid = hajlajty_import_find_term_id_by_meta( 'druzyna', 'api_id', $api_id );
		if ( $tid ) {
			$team_term_ids[] = $tid;
		}
	}
	if ( $team_term_ids ) {
		wp_set_object_terms( $post_id, $team_term_ids, 'druzyna', false );
	}

	// rozgrywki (po league.id).
	$league_id = (int) ( $fixture['league']['id'] ?? 0 );
	if ( $league_id ) {
		$league_term_id = hajlajty_import_find_term_id_by_meta( 'rozgrywki', 'league_id', $league_id );
		if ( $league_term_id ) {
			wp_set_object_terms( $post_id, array( $league_term_id ), 'rozgrywki', false );
		} else {
			hajlajty_import_log( sprintf( 'brak termu „rozgrywki" dla league_id %d — zaseeduj rozgrywki.', $league_id ), 'warning' );
		}
	}

	// sezon (po league.season) — tworzymy term, jeśli brak (sezon nie jest seedowany).
	$season = $fixture['league']['season'] ?? null;
	if ( null !== $season && '' !== (string) $season ) {
		$season_term_id = hajlajty_import_ensure_season_term( $season );
		if ( $season_term_id ) {
			wp_set_object_terms( $post_id, array( $season_term_id ), 'sezon', false );
		}
	}

	// `kanal` NIE z importu — to pole redakcyjne (decyzja #10).
}

/**
 * Resolwuje term po term meta (stabilne ID). Zwraca term_id albo 0.
 */
function hajlajty_import_find_term_id_by_meta( $taxonomy, $meta_key, $meta_value ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
			'meta_query' => array(
				array(
					'key'   => $meta_key,
					'value' => (string) $meta_value,
				),
			),
		)
	);

	return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? (int) $terms[0] : 0;
}

/**
 * Polska nazwa drużyny (nazwa termu) po api_id. null = brak termu (seed gap).
 */
function hajlajty_import_team_name_by_api_id( $api_id ) {
	$term_id = hajlajty_import_find_term_id_by_meta( 'druzyna', 'api_id', $api_id );
	if ( ! $term_id ) {
		return null;
	}
	$term = get_term( $term_id, 'druzyna' );
	return ( $term && ! is_wp_error( $term ) ) ? $term->name : null;
}

/**
 * Zwraca term_id sezonu o nazwie = (string) $season; tworzy, jeśli nie istnieje.
 * Sezon nie jest seedowany (to atrybut meczu, nie encja wymagająca PL nazwy).
 */
function hajlajty_import_ensure_season_term( $season ) {
	$name     = (string) $season;
	$existing = term_exists( $name, 'sezon' );
	if ( $existing ) {
		return (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
	}

	$created = wp_insert_term( $name, 'sezon' );
	return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
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

/**
 * Orkiestracja live-importu: jeden request `fixtures?live=all`, zawężenie do
 * śledzonych lig PO STRONIE KLIENTA, UPDATE-ONLY (pre-check po fixture_id), zapis
 * przez `hajlajty_import_process_fixture`. Reużywane przez komendę `import-live`
 * i przez cron (3e-iv-a).
 *
 * Decyzje (3e-ii, niezmienione): `live=all` to jedyna forma ważna dla dowolnej
 * liczby śledzonych lig; detali nie pobieramy dla nietrackowanych/nieobecnych
 * meczów (gatekeeper: filtr league.id + pre-check posta przed dotknięciem API/bazy).
 *
 * @param int[]      $leagues Lista śledzonych league.id (gate kliencki).
 * @param array|null $live    Opcjonalnie wcześniej pobrany `live=all` (cron pobiera
 *                            go RAZ i karmi nim też auto-FT — „ten sam request").
 *                            null = pobierz tutaj (ścieżka ręcznej komendy).
 * @return array{updated:int,absent:int,skipped:int,live_total:int}|WP_Error
 *         live_total = liczba meczów zwróconych przez `live=all` (przed filtrem lig).
 */
function hajlajty_import_live_run( $leagues, $live = null ) {
	// `live=all` (jedyna forma ważna dla dowolnej liczby lig; param `live` odrzuca
	// pojedyncze `id`). Zawężenie do śledzonych lig robimy niżej, po `league.id`.
	if ( null === $live ) {
		$live = hajlajty_import_request( 'fixtures', array( 'live' => 'all' ) );
	}
	if ( is_wp_error( $live ) ) {
		return $live;
	}

	$counts = array(
		'updated'    => 0,
		'absent'     => 0,
		'skipped'    => 0,
		'live_total' => is_array( $live ) ? count( $live ) : 0,
	);
	if ( empty( $live ) ) {
		return $counts; // Brak meczów na żywo nigdzie — nic do odświeżenia.
	}

	$tracked = array_flip( $leagues ); // O(1) lookup po league.id.

	foreach ( $live as $fixture ) {
		// Zawężenie klienckie: ligi spoza śledzonych pomijamy ZANIM dotkniemy bazy
		// czy API (zero kosztu dla setek nietrackowanych meczów z `live=all`).
		$lid = isset( $fixture['league']['id'] ) ? (int) $fixture['league']['id'] : 0;
		if ( ! isset( $tracked[ $lid ] ) ) {
			continue;
		}

		$fid = isset( $fixture['fixture']['id'] ) ? (int) $fixture['fixture']['id'] : 0;
		if ( ! $fid ) {
			hajlajty_import_log( 'Element live bez fixture.id — pomijam.', 'warning' );
			$counts['skipped']++;
			continue;
		}

		// UPDATE-ONLY + oszczędność API: jeśli mecz nie istnieje w bazie, NIE wołamy
		// process_fixture (ten pobrałby sekcje szczegółowe przed sprawdzeniem posta).
		if ( ! hajlajty_import_find_post_by_fixture_id( $fid ) ) {
			hajlajty_import_log( sprintf( 'fixture %d na żywo, ale brak w bazie — pomijam (zaimportuj terminarz zwykłym importem).', $fid ) );
			$counts['absent']++;
			continue;
		}

		$result = hajlajty_import_process_fixture( $fixture );
		$counts[ 'updated' === $result ? 'updated' : 'skipped' ]++;
	}

	return $counts;
}
