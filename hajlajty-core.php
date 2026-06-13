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
 * Cienki bootstrap. Żadnej logiki biznesowej tutaj — w przyszłości to miejsce
 * załaduje slice'y z katalogu features/ (vertical slice). Patrz hajlajty-meta/CLAUDE.md.
 */
