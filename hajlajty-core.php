<?php
/**
 * Plugin Name:       Hajlajty Core
 * Description:        Rdzeń serwisu hajlajty.pl: CPT mecz, taksonomie i import danych z api-football.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Przemek Cichoń
 * Text Domain:       hajlajty-core
 */

// Brak bezpośredniego dostępu.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cienki bootstrap. Żadnej logiki biznesowej tutaj — tylko ładowanie slice'ów
 * z katalogu features/ (vertical slice). Patrz hajlajty-meta/CLAUDE.md.
 */

// Ścieżka do głównego pliku wtyczki — slice'y używają jej np. do
// register_activation_hook(), żeby nie hardkodować ścieżki u siebie.
define( 'HAJLAJTY_CORE_FILE', __FILE__ );
define( 'HAJLAJTY_CORE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Autoloader slice'ów. Dla każdego katalogu w features/ ładuje jego punkt
 * wejścia o nazwie zgodnej z katalogiem (np. features/match/match.php).
 * Slice jest właścicielem tego, co ładuje — to JEGO bootstrap dociąga własne
 * pliki (cpt.php, taxonomies.php itd.). Tu, w bootstrapie wtyczki, żadnej
 * logiki biznesowej: tylko require punktów wejścia.
 */
foreach ( glob( HAJLAJTY_CORE_DIR . 'features/*', GLOB_ONLYDIR ) as $slice_dir ) {
	$entry = $slice_dir . '/' . basename( $slice_dir ) . '.php';
	if ( is_readable( $entry ) ) {
		require_once $entry;
	}
}
