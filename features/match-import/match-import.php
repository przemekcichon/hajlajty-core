<?php
/**
 * Slice "match-import" — import meczów z api-football do CPT „mecz".
 * Mecze powstają WYŁĄCZNIE tędy (decyzja #10): NIGDY ręcznie. Redaktor
 * wzbogaca zaimportowany mecz tylko o `skrot_url` + taksonomię `kanal`.
 *
 * Zależność kierunkowa: seed (slice roster-seed) MUSI pójść pierwszy — import
 * resolwuje teams.{home,away}.id i league.id do termów po term meta (api_id /
 * league_id), a slug meczu potrzebuje polskiej nazwy drużyny z termu.
 *
 * Bootstrap slice'a: ładuje pozostałe pliki katalogu (client.php, transform.php,
 * cli.php). Każdy sam podpina swoje (cli.php rejestruje komendę tylko pod WP-CLI).
 *
 * POZA ZAKRESEM tej fazy (świadomie, plan): schedule.php / „co odświeżyć teraz" /
 * cron-window — wraca w fazie z prod. Tu tylko ręczne `wp hajlajty import`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( glob( __DIR__ . '/*.php' ) as $part ) {
	if ( $part !== __FILE__ ) {
		require_once $part;
	}
}
