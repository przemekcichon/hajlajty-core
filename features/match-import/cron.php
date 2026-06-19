<?php
/**
 * WP-Cron: zautomatyzowany live-import w OKNACH meczowych (3e-iv-a). Zastępuje
 * ręczne odpalanie `wp hajlajty import-live` zaplanowanym eventem ~1 min, ale
 * TYLKO wokół znanych `kickoff` śledzonych lig — nie ślepy polling 24/7 (budżet
 * API). Poza oknem callback kończy NATYCHMIAST, bez zapytań do live-API.
 *
 * Cron ORKIESTRUJE, nie kopiuje: woła `hajlajty_import_live_run()` z runner.php —
 * tę samą logikę co ręczna komenda `import-live`. Jedno źródło, dwa wejścia.
 *
 * Realia kadencji (USTALENIE 3e-iv-a): WP-Cron jest request-driven, a granulacja
 * OS-crona to min 1 min — „~15 s" jest nieosiągalne i niepotrzebne (poller 3e-iii
 * bije co 30 s, redakcja nie potrzebuje sekund). Celujemy w ~1 min. PEWNA kadencja
 * na PROD wymaga systemowego crona bijącego `wp cron event run --due-now` co
 * minutę — to ops/deploy, nie kod (udokumentowane w PR).
 *
 * Slice match-import jest właścicielem tego eventu (rejestracja na hooku WP,
 * cienki bootstrap) — vertical slice. Plik ładowany ZAWSZE (bez guardu WP-CLI),
 * bo callback crona biega poza CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Nazwa zaplanowanego eventu (hook akcji wołany przez WP-Cron). */
const HAJLAJTY_CRON_LIVE_HOOK = 'hajlajty_live_import_tick';

/** Nazwa własnego harmonogramu (kadencja ~1 min). */
const HAJLAJTY_CRON_LIVE_SCHEDULE = 'hajlajty_one_minute';

/** Okno meczowe: ile minut PRZED i PO `kickoff` cron pollu­je live. */
const HAJLAJTY_CRON_WINDOW_PRE_MIN  = 5;
const HAJLAJTY_CRON_WINDOW_POST_MIN = 180;

/**
 * Dodaje własny harmonogram ~1 min (WP nie ma wbudowanego sub-godzinowego).
 *
 * @param array $schedules Mapa harmonogramów WP-Cron.
 * @return array
 */
function hajlajty_cron_add_interval( $schedules ) {
	$schedules[ HAJLAJTY_CRON_LIVE_SCHEDULE ] = array(
		'interval' => MINUTE_IN_SECONDS,
		'display'  => 'Co minutę (hajlajty live-import)',
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'hajlajty_cron_add_interval' );

/**
 * Idempotentna rejestracja eventu: planuje go tylko, gdy nie jest zaplanowany.
 * Na `init` (każdy request) — guard `wp_next_scheduled` czyni to tanim i pewnym
 * także dla wtyczki już aktywnej (activation hook by jej nie złapał).
 */
function hajlajty_cron_ensure_event() {
	if ( ! wp_next_scheduled( HAJLAJTY_CRON_LIVE_HOOK ) ) {
		wp_schedule_event( time(), HAJLAJTY_CRON_LIVE_SCHEDULE, HAJLAJTY_CRON_LIVE_HOOK );
	}
}
add_action( 'init', 'hajlajty_cron_ensure_event' );

/**
 * Sprząta zaplanowany event przy deaktywacji wtyczki (nie zostawiamy sieroty
 * w tabeli cronów). HAJLAJTY_CORE_FILE definiuje bootstrap wtyczki.
 */
function hajlajty_cron_clear_event() {
	wp_clear_scheduled_hook( HAJLAJTY_CRON_LIVE_HOOK );
}
if ( defined( 'HAJLAJTY_CORE_FILE' ) ) {
	register_deactivation_hook( HAJLAJTY_CORE_FILE, 'hajlajty_cron_clear_event' );
}

/**
 * Granice okna meczowego w UTC `Y-m-d H:i:s` (format płaskiej meta `kickoff`).
 *
 * @return array{0:string,1:string} [dolna, górna] granica do meta_query BETWEEN.
 */
function hajlajty_cron_window_bounds() {
	$now = time();
	$lo  = gmdate( 'Y-m-d H:i:s', $now - HAJLAJTY_CRON_WINDOW_POST_MIN * MINUTE_IN_SECONDS );
	$hi  = gmdate( 'Y-m-d H:i:s', $now + HAJLAJTY_CRON_WINDOW_PRE_MIN * MINUTE_IN_SECONDS );
	return array( $lo, $hi );
}

/**
 * Kody statusu, po których mecz NIE wyśle już sygnału live — gate BUDŻETOWY okna,
 * NIE źródło prawdy „live" (to motyw, 3e-i, `hajlajty_status_live_codes`). Core
 * trzyma własny, wąski zbiór terminalnych kodów wyłącznie do decyzji „czy jeszcze
 * pollować ten mecz": zakończone (FT/AET/PEN) + nierozegrane terminalnie
 * (PST/CANC/ABD/AWD/WO). Mecz w oknie czasowym z takim statusem NIE otwiera okna.
 *
 * @return string[]
 */
function hajlajty_cron_finished_status_codes() {
	return array( 'FT', 'AET', 'PEN', 'PST', 'CANC', 'ABD', 'AWD', 'WO' );
}

/**
 * Czy istnieje śledzony mecz w oknie: `kickoff ∈ [teraz−POST, teraz+PRE]` i status
 * jeszcze nie terminalny? Decyduje, czy w tym tiku w ogóle dotykać live-API.
 *
 * @return bool
 */
function hajlajty_cron_has_match_in_window() {
	list( $lo, $hi ) = hajlajty_cron_window_bounds();

	$query = new WP_Query(
		array(
			'post_type'      => 'mecz',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'kickoff',
					'value'   => array( $lo, $hi ),
					'compare' => 'BETWEEN',
					'type'    => 'CHAR',
				),
				array(
					'key'     => 'status',
					'value'   => hajlajty_cron_finished_status_codes(),
					'compare' => 'NOT IN',
				),
			),
		)
	);

	return $query->have_posts();
}

/**
 * Kody statusu „w grze" (api-football „In Play") — gate auto-FT: mecz z takim
 * płaskim `status` uważamy w bazie za live i sprawdzamy, czy nie zniknął z
 * `live=all`. To MAŁY STAŁY LOOKUP fiksowanego słownika api-football (wyjątek
 * dozwolony przez CLAUDE.md), NIE źródło prawdy renderu — mapa short→stan PL żyje
 * w motywie (`hajlajty_status_live_codes`, 3e-i); core ma własny operacyjny zbiór
 * do swojej decyzji „czy ten mecz wciąż trwa", bez sięgania przez granicę artefaktu.
 *
 * @return string[]
 */
function hajlajty_cron_live_status_codes() {
	return array( '1H', 'HT', '2H', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE' );
}

/**
 * Indeksuje pobrany `live=all` po `fixture.id` (O(1) lookup dla auto-FT).
 *
 * @param array $live Odpowiedź `fixtures?live=all`.
 * @return array<int,true> Mapa fixture_id → true.
 */
function hajlajty_cron_index_live_fixture_ids( $live ) {
	$ids = array();
	foreach ( (array) $live as $fixture ) {
		$fid = isset( $fixture['fixture']['id'] ) ? (int) $fixture['fixture']['id'] : 0;
		if ( $fid ) {
			$ids[ $fid ] = true;
		}
	}
	return $ids;
}

/**
 * Auto-finalizacja FT bez nowego magazynu stanu: porównuje posty DB-live (płaska
 * `status` ∈ kody live) z bieżącym zbiorem `live=all`. Mecz obecny w DB-live, a
 * NIEOBECNY w `live=all`, zniknął bo się skończył — domykamy go targetowanym
 * `fixtures?id=<id>` (ścieżka `import --fixture`) i zapisujemy cokolwiek API zwróci.
 *
 * Idempotentne: ponowny `process_fixture` z tym samym statusem to no-op danych;
 * `FT/AET/PEN` → płaska `status` nie-live → poller 3e-iii dostaje `data-live="0"`
 * i milknie; `HT` (gdyby chwilowo zniknął) → zapis HT, mecz zostaje live.
 *
 * @param array<int,true> $live_ids Zbiór fixture_id obecnych w `live=all`.
 * @return int Liczba domkniętych meczów.
 */
function hajlajty_cron_auto_finalize( $live_ids ) {
	$db_live = new WP_Query(
		array(
			'post_type'      => 'mecz',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => 'status',
					'value'   => hajlajty_cron_live_status_codes(),
					'compare' => 'IN',
				),
			),
		)
	);

	$closed = 0;
	foreach ( $db_live->posts as $post_id ) {
		$fid = (int) get_post_meta( $post_id, 'fixture_id', true );
		if ( ! $fid || isset( $live_ids[ $fid ] ) ) {
			continue; // brak fixture_id albo wciąż na żywo — zostaw.
		}

		// Zniknął z live=all → domknij jednym targetowanym requestem.
		$fixtures = hajlajty_import_request( 'fixtures', array( 'id' => $fid ) );
		if ( is_wp_error( $fixtures ) || empty( $fixtures ) ) {
			hajlajty_import_log(
				sprintf(
					'auto-FT: fixture %d zniknął z live, ale fixtures?id zwróciło %s — pomijam.',
					$fid,
					is_wp_error( $fixtures ) ? $fixtures->get_error_message() : 'pusto'
				),
				'warning'
			);
			continue;
		}

		hajlajty_import_process_fixture( $fixtures[0] ); // zapis cokolwiek API zwróci.
		$closed++;
	}

	return $closed;
}

/**
 * Callback eventu: w oknie odświeża live + domyka mecze po gwizdku; poza oknem
 * kończy bez zapytań do API. Pobiera `live=all` RAZ i karmi nim oba kroki (budżet).
 * Loguje przez `hajlajty_import_log` (pod cronem → error_log).
 */
function hajlajty_cron_live_import_tick() {
	$leagues = hajlajty_import_live_tracked_leagues();
	if ( empty( $leagues ) ) {
		return; // Brak śledzonych lig (brak seedu „rozgrywki") — nic do roboty.
	}

	if ( ! hajlajty_cron_has_match_in_window() ) {
		return; // Poza oknem meczowym — ZERO zapytań do live-API (budżet).
	}

	// Jeden `live=all` na tik — wspólny dla odświeżenia i diffu auto-FT.
	$live = hajlajty_import_request( 'fixtures', array( 'live' => 'all' ) );
	if ( is_wp_error( $live ) ) {
		hajlajty_import_log( 'cron live-import: ' . $live->get_error_message(), 'warning' );
		return;
	}

	$counts = hajlajty_import_live_run( $leagues, $live );
	$closed = hajlajty_cron_auto_finalize( hajlajty_cron_index_live_fixture_ids( $live ) );

	hajlajty_import_log(
		sprintf(
			'cron live-import w oknie: zaktualizowano %d, poza bazą %d, pominięto %d, domknięto FT %d.',
			$counts['updated'],
			$counts['absent'],
			$counts['skipped'],
			$closed
		)
	);
}
add_action( HAJLAJTY_CRON_LIVE_HOOK, 'hajlajty_cron_live_import_tick' );
