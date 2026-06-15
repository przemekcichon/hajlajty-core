<?php
/**
 * Slice "match" — właściciel CAŁEGO modelu meczu: CPT 'mecz', publiczne
 * taksonomie (druzyna, rozgrywki, sezon, kanal), term meta drużyn/rozgrywek,
 * pola ACF skrótu wideo i schemat permalinku. Zgodnie z CLAUDE.md: „slice
 * 'match' rejestruje własny CPT i taksonomie".
 *
 * To jest bootstrap slice'a: ładuje wszystkie pozostałe pliki tego katalogu.
 * Każdy z nich SAM podpina swoje hooki WP (init, acf/init, *_form_fields …).
 * Dodanie nowej części slice'a = wrzucenie pliku do tego katalogu, bez
 * dotykania bootstrapu wtyczki ani tego pliku.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( glob( __DIR__ . '/*.php' ) as $part ) {
	if ( $part !== __FILE__ ) {
		require_once $part;
	}
}
