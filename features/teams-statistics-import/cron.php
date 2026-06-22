<?php
/**
 * WP-Cron: cykliczne odświeżanie zaimportowanych statystyk drużyn. Odświeża
 * WYŁĄCZNIE trójki (drużyna, liga, sezon), które ktoś już raz zaimportował ręcznie
 * (`wp hajlajty team-stats`) — czyli termy „druzyna" mające meta
 * `team_stats_<league>_<season>`. Dzięki temu cron NIE zgaduje „bieżącego sezonu"
 * i na świeżej instalacji bez żadnych statystyk robi ZERO zapytań do API (brama
 * budżetowa, jak cron standings/live match-import).
 *
 * Cron ORKIESTRUJE, nie kopiuje: woła `hajlajty_team_stats_import_run()` z
 * runner.php — tę samą logikę co ręczna komenda. Jedno źródło, dwa wejścia.
 *
 * Kadencja: harmonogram WBUDOWANY `daily` — statystyki drużyny zmieniają się
 * RZADZIEJ niż tabela grup (aktualizują się dopiero po kolejnym meczu drużyny, a
 * te są co kilka dni), więc dzienny refresh wystarcza i oszczędza limit API; bez
 * własnego interwału (#8 — bez abstrakcji na zapas). PEWNA kadencja na PROD wymaga
 * systemowego crona bijącego `wp cron event run --due-now` — to ops/deploy, nie
 * kod (patrz docs/cron-produkcja.md).
 *
 * Slice jest właścicielem swojego eventu; plik ładowany ZAWSZE (callback crona
 * biega poza CLI).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Nazwa zaplanowanego eventu odświeżania statystyk drużyn. */
const HAJLAJTY_TEAM_STATS_CRON_HOOK = 'hajlajty_team_stats_import_tick';

/**
 * Idempotentna rejestracja eventu na `init` (guard `wp_next_scheduled` czyni to
 * tanim także dla wtyczki już aktywnej — activation hook by jej nie złapał).
 */
function hajlajty_team_stats_cron_ensure_event() {
	if ( ! wp_next_scheduled( HAJLAJTY_TEAM_STATS_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'daily', HAJLAJTY_TEAM_STATS_CRON_HOOK );
	}
}
add_action( 'init', 'hajlajty_team_stats_cron_ensure_event' );

/**
 * Sprząta event przy deaktywacji wtyczki (bez sieroty w tabeli cronów).
 * HAJLAJTY_CORE_FILE definiuje bootstrap wtyczki.
 */
function hajlajty_team_stats_cron_clear_event() {
	wp_clear_scheduled_hook( HAJLAJTY_TEAM_STATS_CRON_HOOK );
}
if ( defined( 'HAJLAJTY_CORE_FILE' ) ) {
	register_deactivation_hook( HAJLAJTY_CORE_FILE, 'hajlajty_team_stats_cron_clear_event' );
}

/**
 * Callback eventu: dla każdego termu „druzyna" z `api_id` odświeża każdą parę
 * (liga, sezon), dla której term ma już meta `team_stats_<league>_<season>`. Brak
 * takich par = zero zapytań do API. Loguje przez `hajlajty_import_log` (pod cronem
 * → error_log).
 */
function hajlajty_team_stats_cron_tick() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'druzyna',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return; // brak seedu „druzyna" — nic do odświeżenia.
	}

	$refreshed = 0;
	$failed    = 0;
	foreach ( $terms as $term_id ) {
		$api_id = (int) get_term_meta( (int) $term_id, 'api_id', true );
		if ( $api_id <= 0 ) {
			continue; // term bez api_id (np. utworzony ręcznie) — pomiń.
		}

		foreach ( hajlajty_team_stats_term_pairs( (int) $term_id ) as $pair ) {
			$result = hajlajty_team_stats_import_run( $api_id, $pair['league'], $pair['season'] );
			if ( is_wp_error( $result ) ) {
				++$failed;
			} else {
				++$refreshed;
			}
		}
	}

	if ( $refreshed > 0 || $failed > 0 ) {
		hajlajty_import_log(
			sprintf( 'cron team-stats: odświeżono %d trójek (drużyna, liga, sezon), błędów %d.', $refreshed, $failed )
		);
	}
}
add_action( HAJLAJTY_TEAM_STATS_CRON_HOOK, 'hajlajty_team_stats_cron_tick' );

/**
 * Pary (liga, sezon), dla których term „druzyna" ma już zapisane statystyki
 * (klucze meta `team_stats_<league>_<season>`). To zbiór trójek do odświeżenia —
 * cron nie tworzy nowych. Oba segmenty to czyste cyfry rozdzielone jednym `_`,
 * więc rozbicie jest jednoznaczne.
 *
 * @param int $term_id
 * @return array<int,array{league:int,season:string}> Może być pusta.
 */
function hajlajty_team_stats_term_pairs( $term_id ) {
	$all_meta = get_term_meta( $term_id );
	if ( empty( $all_meta ) || ! is_array( $all_meta ) ) {
		return array();
	}

	$pattern = '/^' . preg_quote( HAJLAJTY_TEAM_STATS_META_PREFIX, '/' ) . '(\d+)_(\d+)$/';
	$pairs   = array();
	foreach ( array_keys( $all_meta ) as $meta_key ) {
		if ( preg_match( $pattern, (string) $meta_key, $m ) ) {
			$pairs[] = array(
				'league' => (int) $m[1],
				'season' => $m[2],
			);
		}
	}
	return $pairs;
}
