<?php
/**
 * Slice "roster-seed" — katalog encji (drużyny, rozgrywki) jako termy taksonomii,
 * zasiewany z CSV przez WP-CLI. Osobny slice od importu: inny cykl życia
 * (roster tworzymy RAZ, import odpalamy wielokrotnie). Zależność kierunkowa:
 * seed MUSI pójść przed pierwszym importem — import resolwuje team.id/league.id
 * z fixture'a do termu po term meta (api_id/league_id), a slug meczu potrzebuje
 * polskiej nazwy drużyny z termu.
 *
 * To bootstrap slice'a: ładuje pozostałe pliki katalogu (każdy sam podpina
 * swoje hooki / rejestruje komendę). Pliki CSV w data/ to DANE seeda (źródło
 * prawdy), nie kod — nie są tu ładowane.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( glob( __DIR__ . '/*.php' ) as $part ) {
	if ( $part !== __FILE__ ) {
		require_once $part;
	}
}
