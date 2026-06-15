<?php
/**
 * Schemat permalinku meczu (CLAUDE.md decyzja #7, D1.1) oraz flush reguł
 * przepisywania przy aktywacji wtyczki.
 *
 * Schemat: /mecz/{gospodarz}-{gosc}-{RRRR-MM-DD}, gdzie {gospodarz}/{gosc} to
 * PEŁNE polskie nazwy serwisowe drużyn (nazwy termów taksonomii „druzyna")
 * transliterowane do ASCII, kolejność gospodarz-gość z fixture'a, BEZ
 * fixture.id w URL. Baza „mecz" pochodzi z rewrite CPT (cpt.php); tu mieszka
 * REGUŁA budowy samego slug-a (post_name).
 *
 * WAŻNE (decyzja #7): slug generujemy RAZ przy tworzeniu wpisu. NIE
 * regenerujemy go przy re-imporcie ani przy zmianie nazwy drużyny
 * (stabilność linku > aktualność). Konsumentem tej funkcji jest slice
 * importu (Faza 2); w Fazie 1 utrwalamy tu sam schemat.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buduje slug meczu wg decyzji #7.
 *
 * @param string $home_name    Polska nazwa gospodarza (nazwa termu „druzyna").
 * @param string $away_name    Polska nazwa gościa (nazwa termu „druzyna").
 * @param string $kickoff_date Data meczu w formacie RRRR-MM-DD.
 * @return string Slug ASCII, np. „francja-chorwacja-2026-06-12".
 */
function hajlajty_match_build_slug( $home_name, $away_name, $kickoff_date ) {
	// sanitize_title( remove_accents() ) transliteruje polskie znaki do ASCII
	// (Południowa → poludniowa) i zamienia spacje na myślniki.
	$home = sanitize_title( remove_accents( $home_name ) );
	$away = sanitize_title( remove_accents( $away_name ) );

	return implode( '-', array_filter( array( $home, $away, $kickoff_date ) ) );
}

/* -------------------------------------------------------------------------
 * Flush reguł przepisywania przy aktywacji
 * ----------------------------------------------------------------------
 * CPT i taksonomie rejestrujemy na 'init', który NIE odpala się podczas
 * aktywacji — dlatego najpierw wołamy ich rejestrację ręcznie, potem flush.
 * Wszystkie trzy funkcje należą do tego samego slice'a (cpt.php/taxonomies.php),
 * więc wywołanie ich tutaj nie łamie granic slice'a.
 *
 * HAJLAJTY_CORE_FILE pochodzi z bootstrapu wtyczki (hajlajty-core.php).
 */
register_activation_hook( HAJLAJTY_CORE_FILE, 'hajlajty_match_activate' );

function hajlajty_match_activate() {
	hajlajty_match_register_post_type();
	hajlajty_match_register_taxonomies();
	flush_rewrite_rules();
}

/**
 * Sprzątanie przy dezaktywacji: usuń reguły CPT z bufora przepisywania.
 */
register_deactivation_hook( HAJLAJTY_CORE_FILE, 'flush_rewrite_rules' );
