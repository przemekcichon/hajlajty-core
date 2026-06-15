<?php
/**
 * CPT 'mecz' — centralny typ treści serwisu (zapowiedź / live / skrót to jeden
 * wpis w różnych stanach, nie osobne typy).
 *
 * Headless-ready (CLAUDE.md decyzja #6): show_in_rest teraz, show_in_graphql
 * jako flaga na przyszłą migrację do Next.js + WPGraphQL (wtyczki NIE
 * instalujemy w Fazie 1 — ustawiamy tylko flagę).
 *
 * supports = title + editor + thumbnail: editor (post content) zostaje na
 * ręczne opisy/zapowiedzi (decyzja #5), NIE na dane meczowe — te trzymamy
 * w meta `match_data` (decyzja #3, slice importu w Fazie 2).
 *
 * Funkcja jest nazwana, bo wywołuje ją też hook aktywacji w permalink.php
 * (rejestracja przed flush_rewrite_rules).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'hajlajty_match_register_post_type' );

function hajlajty_match_register_post_type() {
	$labels = array(
		'name'               => 'Mecze',
		'singular_name'      => 'Mecz',
		'menu_name'          => 'Mecze',
		'add_new'            => 'Dodaj nowy',
		'add_new_item'       => 'Dodaj nowy mecz',
		'edit_item'          => 'Edytuj mecz',
		'new_item'           => 'Nowy mecz',
		'view_item'          => 'Zobacz mecz',
		'view_items'         => 'Zobacz mecze',
		'all_items'          => 'Wszystkie mecze',
		'search_items'       => 'Szukaj meczów',
		'not_found'          => 'Nie znaleziono meczów',
		'not_found_in_trash' => 'Brak meczów w koszu',
	);

	$args = array(
		'labels'              => $labels,
		'public'              => true,
		'has_archive'         => true,
		'menu_icon'           => 'dashicons-tickets-alt',
		'supports'            => array( 'title', 'editor', 'thumbnail' ),
		// Permalink: /mecz/{slug}. Schemat slug-a (gospodarz-gosc-data) ustala
		// permalink.php / import (decyzja #7); tu definiujemy tylko bazę 'mecz'.
		'rewrite'             => array(
			'slug'       => 'mecz',
			'with_front' => false,
		),
		'show_in_rest'        => true,
		'show_in_graphql'     => true,
		'graphql_single_name' => 'mecz',
		'graphql_plural_name' => 'mecze',
	);

	register_post_type( 'mecz', $args );
}
