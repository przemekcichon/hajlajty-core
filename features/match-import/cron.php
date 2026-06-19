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
 * Callback eventu: w oknie odświeża live, poza oknem kończy bez zapytań do API.
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

	$counts = hajlajty_import_live_run( $leagues );
	if ( is_wp_error( $counts ) ) {
		hajlajty_import_log( 'cron live-import: ' . $counts->get_error_message(), 'warning' );
		return;
	}

	hajlajty_import_log(
		sprintf(
			'cron live-import w oknie: zaktualizowano %d, poza bazą %d, pominięto %d.',
			$counts['updated'],
			$counts['absent'],
			$counts['skipped']
		)
	);
}
add_action( HAJLAJTY_CRON_LIVE_HOOK, 'hajlajty_cron_live_import_tick' );
