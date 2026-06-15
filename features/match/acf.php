<?php
/**
 * Pola ACF meczu — grupa „Skrót wideo", rejestrowana KODEM
 * (acf_add_local_field_group), nie klikana w adminie: wersjonowalne i
 * migracja-safe (potwierdzona decyzja z planu).
 *
 *  - skrot_url          — link do skrótu na YouTube. JEGO obecność = mecz
 *                         „ma wideo" (decyzja #9); status_wideo NIE istnieje.
 *  - skrot_duration     — czas trwania MM:SS. Faza 1 tylko DEFINIUJE pole;
 *                         ręczne wpisywanie do czasu slice'a YouTube Data API
 *                         (Faza 5, D1.5).
 *  - skrot_published_at — data publikacji skrótu.
 *
 * Kanał (nadawca skrótu) to TAKSONOMIA (taxonomies.php), NIE pole ACF.
 * Dane meczowe (oś czasu/składy/statystyki) to meta `match_data` (decyzja #3),
 * też NIE ACF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'acf/init', 'hajlajty_match_register_acf_fields' );

function hajlajty_match_register_acf_fields() {
	// ACF PRO jest zależnością wtyczki, ale nie zakładamy jej na ślepo —
	// brak funkcji = po prostu nie rejestrujemy grupy (zero fatal error).
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group(
		array(
			'key'    => 'group_mecz_skrot',
			'title'  => 'Skrót wideo',
			'fields' => array(
				array(
					'key'          => 'field_skrot_url',
					'label'        => 'Link do skrótu (YouTube)',
					'name'         => 'skrot_url',
					'type'         => 'url',
					'instructions' => 'Wklej pełny link do filmu na YouTube. Wypełnione pole = mecz „ma wideo".',
				),
				array(
					'key'          => 'field_skrot_duration',
					'label'        => 'Czas trwania (MM:SS)',
					'name'         => 'skrot_duration',
					'type'         => 'text',
					'instructions' => 'Na razie wpisz ręcznie, np. 08:32. Docelowo automatycznie z YouTube Data API (Faza 5).',
					'placeholder'  => 'MM:SS',
				),
				array(
					'key'            => 'field_skrot_published_at',
					'label'          => 'Data publikacji skrótu',
					'name'           => 'skrot_published_at',
					'type'           => 'date_time_picker',
					'display_format' => 'Y-m-d H:i',
					'return_format'  => 'Y-m-d H:i:s',
				),
			),
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'mecz',
					),
				),
			),
			// Headless-ready: pola w REST teraz, flagi GraphQL na później
			// (działają, gdy dołoży się WPGraphQL for ACF — wtyczki nie
			// instalujemy w Fazie 1).
			'show_in_rest'       => 1,
			'show_in_graphql'    => 1,
			'graphql_field_name' => 'skrot',
		)
	);
}
