<?php
/**
 * Term meta drużyn i rozgrywek — stabilne ID z api-football zapisane na termach
 * (CLAUDE.md „Lokalizacja nazw"). Import łączy mecz z drużyną/rozgrywkami po
 * tych ID, NIGDY po nazwie EN.
 *
 *  - druzyna:   fifa_code (3 litery, WIELKIE — D1.3; do herbów/flag),
 *               api_id    (team.id z api-football — klucz łączenia).
 *  - rozgrywki: league_id (league.id z api-football — klucz łączenia).
 *
 * Meta jest register_term_meta z show_in_rest => true (headless-ready; WPGraphQL
 * dołoży term meta przy migracji). Plus edytowalne pola na ekranie terminu
 * w adminie (add/edit form + zapis), żeby redaktor mógł je wpisać ręcznie,
 * zanim ruszy seed/import (ścieżka „najpierw ręcznie, potem z AI/API").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pojedyncze źródło prawdy: które meta należą do której taksonomii (+ etykiety
 * i podpowiedzi do UI). Używane i przy rejestracji meta, i przy renderze pól,
 * i przy zapisie.
 */
function hajlajty_match_term_meta_fields() {
	return array(
		'druzyna'   => array(
			'fifa_code' => array(
				'label'    => 'Kod FIFA',
				'help'     => '3 litery, np. POL (zapis wielkimi literami). Do herbów/flag.',
				'type'     => 'string',
				'sanitize' => 'hajlajty_match_sanitize_fifa_code',
			),
			'api_id'    => array(
				'label'    => 'api-football: team.id',
				'help'     => 'Liczba. Klucz łączenia meczu z drużyną przy imporcie.',
				'type'     => 'integer',
				'sanitize' => 'absint',
			),
		),
		'rozgrywki' => array(
			'league_id' => array(
				'label'    => 'api-football: league.id',
				'help'     => 'Liczba. Identyfikator rozgrywek z api-football.',
				'type'     => 'integer',
				'sanitize' => 'absint',
			),
		),
	);
}

/** fifa_code: tylko litery, zawsze WIELKIMI (POL). */
function hajlajty_match_sanitize_fifa_code( $value ) {
	return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
}

/* -------------------------------------------------------------------------
 * Rejestracja meta (REST-ready)
 * ---------------------------------------------------------------------- */

add_action( 'init', 'hajlajty_match_register_term_meta' );

function hajlajty_match_register_term_meta() {
	foreach ( hajlajty_match_term_meta_fields() as $taxonomy => $fields ) {
		foreach ( $fields as $key => $field ) {
			register_term_meta(
				$taxonomy,
				$key,
				array(
					'type'              => $field['type'],
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $field['sanitize'],
					'description'       => $field['help'],
				)
			);
		}
	}
}

/* -------------------------------------------------------------------------
 * UI na ekranie terminu (add / edit) + zapis
 * ---------------------------------------------------------------------- */

foreach ( array_keys( hajlajty_match_term_meta_fields() ) as $hajlajty_tax ) {
	add_action( "{$hajlajty_tax}_add_form_fields", 'hajlajty_match_render_add_fields' );
	add_action( "{$hajlajty_tax}_edit_form_fields", 'hajlajty_match_render_edit_fields', 10, 2 );
	add_action( "created_{$hajlajty_tax}", 'hajlajty_match_save_term_fields' );
	add_action( "edited_{$hajlajty_tax}", 'hajlajty_match_save_term_fields' );
}
unset( $hajlajty_tax );

/** Formularz „Dodaj nowy termin" — $taxonomy podaje WP. */
function hajlajty_match_render_add_fields( $taxonomy ) {
	$fields = hajlajty_match_term_meta_fields();
	if ( empty( $fields[ $taxonomy ] ) ) {
		return;
	}
	wp_nonce_field( 'hajlajty_term_meta', 'hajlajty_term_meta_nonce' );
	foreach ( $fields[ $taxonomy ] as $key => $field ) {
		?>
		<div class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<input type="text" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" value="" />
			<p><?php echo esc_html( $field['help'] ); ?></p>
		</div>
		<?php
	}
}

/** Formularz „Edytuj termin" — WP podaje ($term, $taxonomy). */
function hajlajty_match_render_edit_fields( $term, $taxonomy ) {
	$fields = hajlajty_match_term_meta_fields();
	if ( empty( $fields[ $taxonomy ] ) ) {
		return;
	}
	wp_nonce_field( 'hajlajty_term_meta', 'hajlajty_term_meta_nonce' );
	foreach ( $fields[ $taxonomy ] as $key => $field ) {
		$value = get_term_meta( $term->term_id, $key, true );
		?>
		<tr class="form-field term-<?php echo esc_attr( $key ); ?>-wrap">
			<th scope="row">
				<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			</th>
			<td>
				<input type="text" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
			</td>
		</tr>
		<?php
	}
}

/**
 * Zapis pól. Taksonomię odczytujemy z nazwy filtra (created_/edited_{tax}),
 * bo te hooki nie przekazują slug-a taksonomii. Sanitacja idzie przez
 * sanitize_callback zarejestrowany w register_term_meta (update_term_meta).
 */
function hajlajty_match_save_term_fields( $term_id ) {
	$taxonomy = preg_replace( '/^(created|edited)_/', '', current_filter() );
	$fields   = hajlajty_match_term_meta_fields();
	if ( empty( $fields[ $taxonomy ] ) ) {
		return;
	}

	if (
		! isset( $_POST['hajlajty_term_meta_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['hajlajty_term_meta_nonce'] ), 'hajlajty_term_meta' )
	) {
		return;
	}

	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}

	foreach ( $fields[ $taxonomy ] as $key => $field ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		update_term_meta( $term_id, $key, wp_unslash( $_POST[ $key ] ) );
	}
}
