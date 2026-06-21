<?php
/**
 * WP-Cron: cykliczne odświeżanie zaimportowanych tabel grup. Odświeża WYŁĄCZNIE
 * pary (liga, sezon), które ktoś już raz zaimportował ręcznie (`wp hajlajty
 * standings`) — czyli termy „rozgrywki" mające meta `standings_<sezon>`. Dzięki
 * temu cron NIE zgaduje „bieżącego sezonu" i na świeżej instalacji bez żadnych
 * standings robi ZERO zapytań do API (brama budżetowa, jak live-cron match-import).
 *
 * Cron ORKIESTRUJE, nie kopiuje: woła `hajlajty_standings_import_run()` z
 * runner.php — tę samą logikę co ręczna komenda. Jedno źródło, dwa wejścia.
 *
 * Kadencja: harmonogram WBUDOWANY `hourly` — standings zmienia się wolno (po
 * kolejkach), więc godzinowy refresh wystarcza; bez własnego interwału (#8 — bez
 * abstrakcji na zapas, w przeciwieństwie do sub-minutowego live-cronu fixtures).
 * PEWNA kadencja na PROD wymaga systemowego crona bijącego `wp cron event run
 * --due-now` — to ops/deploy, nie kod (patrz docs/cron-produkcja.md).
 *
 * Slice match-import jest właścicielem swojego live-eventu; ten slice — swojego.
 * Plik ładowany ZAWSZE (callback crona biega poza CLI).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Nazwa zaplanowanego eventu odświeżania standings. */
const HAJLAJTY_STANDINGS_CRON_HOOK = 'hajlajty_standings_import_tick';

/**
 * Idempotentna rejestracja eventu na `init` (guard `wp_next_scheduled` czyni to
 * tanim także dla wtyczki już aktywnej — activation hook by jej nie złapał).
 */
function hajlajty_standings_cron_ensure_event() {
	if ( ! wp_next_scheduled( HAJLAJTY_STANDINGS_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'hourly', HAJLAJTY_STANDINGS_CRON_HOOK );
	}
}
add_action( 'init', 'hajlajty_standings_cron_ensure_event' );

/**
 * Sprząta event przy deaktywacji wtyczki (bez sieroty w tabeli cronów).
 * HAJLAJTY_CORE_FILE definiuje bootstrap wtyczki.
 */
function hajlajty_standings_cron_clear_event() {
	wp_clear_scheduled_hook( HAJLAJTY_STANDINGS_CRON_HOOK );
}
if ( defined( 'HAJLAJTY_CORE_FILE' ) ) {
	register_deactivation_hook( HAJLAJTY_CORE_FILE, 'hajlajty_standings_cron_clear_event' );
}

/**
 * Callback eventu: dla każdego termu „rozgrywki" z `league_id` odświeża każdy
 * sezon, dla którego term ma już meta `standings_<sezon>`. Brak takich par =
 * zero zapytań do API. Loguje przez `hajlajty_import_log` (pod cronem → error_log).
 */
function hajlajty_standings_cron_tick() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'rozgrywki',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return; // brak seedu „rozgrywki" — nic do odświeżenia.
	}

	$refreshed = 0;
	$failed    = 0;
	foreach ( $terms as $term_id ) {
		$league_id = (int) get_term_meta( (int) $term_id, 'league_id', true );
		if ( $league_id <= 0 ) {
			continue; // term bez league_id (np. utworzony ręcznie) — pomiń.
		}

		foreach ( hajlajty_standings_term_seasons( (int) $term_id ) as $season ) {
			$result = hajlajty_standings_import_run( $league_id, $season );
			if ( is_wp_error( $result ) ) {
				++$failed;
			} else {
				++$refreshed;
			}
		}
	}

	if ( $refreshed > 0 || $failed > 0 ) {
		hajlajty_import_log(
			sprintf( 'cron standings: odświeżono %d par (liga, sezon), błędów %d.', $refreshed, $failed )
		);
	}
}
add_action( HAJLAJTY_STANDINGS_CRON_HOOK, 'hajlajty_standings_cron_tick' );

/**
 * Sezony, dla których term „rozgrywki" ma już zapisaną tabelę (klucze meta
 * `standings_<cyfry>`). To zbiór par do odświeżenia — cron nie tworzy nowych.
 *
 * @param int $term_id
 * @return string[] Lista sezonów (same cyfry), może być pusta.
 */
function hajlajty_standings_term_seasons( $term_id ) {
	$all_meta = get_term_meta( $term_id );
	if ( empty( $all_meta ) || ! is_array( $all_meta ) ) {
		return array();
	}

	$pattern = '/^' . preg_quote( HAJLAJTY_STANDINGS_META_PREFIX, '/' ) . '(\d+)$/';
	$seasons = array();
	foreach ( array_keys( $all_meta ) as $meta_key ) {
		if ( preg_match( $pattern, (string) $meta_key, $m ) ) {
			$seasons[] = $m[1];
		}
	}
	return $seasons;
}
