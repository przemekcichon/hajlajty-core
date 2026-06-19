<?php
/**
 * WP-CLI: `wp hajlajty backfill-status` — jednorazowe wypełnienie PŁASKIEJ meta
 * `status` dla meczów zaimportowanych ZANIM import zaczął ją zapisywać (3e-i).
 *
 * Źródło = `match_data.status.short` (już w bazie) → BEZ zapytań do api-football
 * (zero kosztu budżetu API). To migracja danych, nie import: czyta to, co import
 * już zapisał, i kopiuje surowy kod statusu do płaskiego klucza filtra list.
 *
 * Po tym backfillu listy 3e-i mogą filtrować `status IN (kody live)` na poziomie
 * SQL. Nowe/odświeżane mecze dostają `status` wprost z importu (cli.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

WP_CLI::add_command( 'hajlajty backfill-status', 'hajlajty_backfill_status_command' );

/**
 * Kopiuje `match_data.status.short` → płaska meta `status` dla wszystkich meczów.
 *
 * ## EXAMPLES
 *
 *     wp hajlajty backfill-status
 *
 * @when after_wp_load
 *
 * @param array $args       Nieużywane.
 * @param array $assoc_args Nieużywane.
 */
function hajlajty_backfill_status_command( $args, $assoc_args ) {
	$ids = get_posts(
		array(
			'post_type'      => 'mecz',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	if ( empty( $ids ) ) {
		WP_CLI::warning( 'Brak meczów do uzupełnienia.' );
		return;
	}

	$updated = 0;
	$skipped = 0;

	foreach ( $ids as $id ) {
		$raw  = get_post_meta( $id, 'match_data', true );
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$short = ( is_array( $data ) && isset( $data['status']['short'] ) )
			? (string) $data['status']['short']
			: '';

		if ( '' === $short ) {
			WP_CLI::log( sprintf( 'mecz #%d: brak status.short w match_data — pomijam.', $id ) );
			$skipped++;
			continue;
		}

		update_post_meta( $id, 'status', $short );
		$updated++;
	}

	WP_CLI::success(
		sprintf( 'Backfill statusu zakończony: uzupełniono %d, pominięto %d.', $updated, $skipped )
	);
}
