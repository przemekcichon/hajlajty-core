<?php
/**
 * Publiczne taksonomie meczu — filtry/chipy frontu (CLAUDE.md decyzja #4):
 * druzyna, rozgrywki, sezon, kanal. Wszystkie headless-ready (show_in_rest +
 * flaga show_in_graphql).
 *
 * CELOWO NIE rejestrujemy `status_wideo` — „mecz ma wideo" to POCHODNA
 * obecności pola `skrot_url` (decyzja #9 / D1.4), nie taksonomia. Link do
 * YouTube to pole ACF (acf.php), też nie taksonomia.
 *
 * hierarchical=true wszędzie: dla redaktora-nastolatka UI z checkboxami jest
 * czytelniejszy niż pole tagów (charakter projektu — prostota dla redaktora).
 *
 * Funkcja jest nazwana, bo wywołuje ją też hook aktywacji w permalink.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'hajlajty_match_register_taxonomies' );

function hajlajty_match_register_taxonomies() {
	$taxonomies = array(
		'druzyna'   => array(
			'singular'       => 'Drużyna',
			'plural'         => 'Drużyny',
			'graphql_single' => 'druzyna',
			'graphql_plural' => 'druzyny',
			'slug'           => 'druzyna',
		),
		'rozgrywki' => array(
			'singular'       => 'Rozgrywki',
			'plural'         => 'Rozgrywki',
			'graphql_single' => 'rozgrywka',
			'graphql_plural' => 'rozgrywki',
			'slug'           => 'rozgrywki',
		),
		'sezon'     => array(
			'singular'       => 'Sezon',
			'plural'         => 'Sezony',
			'graphql_single' => 'sezon',
			'graphql_plural' => 'sezony',
			'slug'           => 'sezon',
		),
		'kanal'     => array(
			'singular'       => 'Kanał',
			'plural'         => 'Kanały',
			'graphql_single' => 'kanal',
			'graphql_plural' => 'kanaly',
			'slug'           => 'kanal',
		),
	);

	foreach ( $taxonomies as $taxonomy => $cfg ) {
		$labels = array(
			'name'          => $cfg['plural'],
			'singular_name' => $cfg['singular'],
			'menu_name'     => $cfg['plural'],
			'all_items'     => 'Wszystkie: ' . $cfg['plural'],
			'edit_item'     => 'Edytuj: ' . $cfg['singular'],
			'view_item'     => 'Zobacz: ' . $cfg['singular'],
			'update_item'   => 'Zapisz: ' . $cfg['singular'],
			'add_new_item'  => 'Dodaj: ' . $cfg['singular'],
			'new_item_name' => 'Nazwa: ' . $cfg['singular'],
			'search_items'  => 'Szukaj: ' . $cfg['plural'],
			'not_found'     => 'Nie znaleziono',
		);

		register_taxonomy(
			$taxonomy,
			'mecz',
			array(
				'labels'              => $labels,
				'public'              => true,
				'hierarchical'        => true,
				'show_admin_column'   => true,
				'show_in_rest'        => true,
				'show_in_graphql'     => true,
				'graphql_single_name' => $cfg['graphql_single'],
				'graphql_plural_name' => $cfg['graphql_plural'],
				'rewrite'             => array(
					'slug'       => $cfg['slug'],
					'with_front' => false,
				),
			)
		);
	}
}
