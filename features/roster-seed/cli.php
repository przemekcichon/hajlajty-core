<?php
/**
 * WP-CLI: `wp hajlajty seed` — idempotentny zasiew termów taksonomii „druzyna"
 * i „rozgrywki" z plików CSV.
 *
 * Zasady (CLAUDE.md „Lokalizacja nazw", plan D2.2):
 *  - Resolucja po STABILNYM ID (api_id / league_id) z term meta — NIGDY po nazwie.
 *    To gwarantuje idempotencję: re-run nie tworzy duplikatów, a zmiana polskiej
 *    nazwy aktualizuje istniejący term (nie tworzy nowego).
 *  - PL nazwa = nazwa termu. api_id/fifa_code/league_id → term meta (klucze i
 *    sanitacja zdefiniowane w slice „match", term-meta.php — single source).
 *  - Nazwa EN (kolumna name_en) to ściągawka: parser ją IGNORUJE, nie zapisuje.
 *  - Walidacja: pusty/zerowy api_id|league_id lub pusty fifa_code → wiersz
 *    ODRZUCONY z jasnym komunikatem (term bez stabilnego ID nie zresolwowałby
 *    się przy imporcie → ciche porażki). Czytelny błąd to też właściwa ścieżka
 *    dla redaktora-nastolatka (projekt edukacyjny).
 *  - Parser czyta po NAGŁÓWKACH (kolejność kolumn dowolna), pomija puste linie
 *    i komentarze (#).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Komenda istnieje tylko w kontekście WP-CLI. Reszta slice'a (gdyby powstała)
// ładuje się normalnie — tu po prostu nie rejestrujemy komendy poza CLI.
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

WP_CLI::add_command( 'hajlajty seed', 'hajlajty_seed_command' );

/**
 * Zasiewa termy „druzyna" i „rozgrywki" z CSV (idempotentnie, po api_id/league_id).
 *
 * ## OPTIONS
 *
 * [--file=<path>]
 * : Ścieżka do pojedynczego pliku CSV (względna do bieżącego katalogu lub
 *   bezwzględna). Domyślnie: wszystkie data/*.csv tego slice'a. Typ pliku
 *   (drużyny vs rozgrywki) wykrywany po nagłówkach.
 *
 * [--dry-run]
 * : Tylko wypisz, co powstanie / zmieni się / zostanie odrzucone — bez zapisu.
 *
 * ## EXAMPLES
 *
 *     wp hajlajty seed --dry-run
 *     wp hajlajty seed
 *     wp hajlajty seed --file=wp-content/plugins/hajlajty-core/features/roster-seed/data/teams.csv
 *
 * @when after_wp_load
 *
 * @param array $args       Argumenty pozycyjne (nieużywane).
 * @param array $assoc_args Flagi: file, dry-run.
 */
function hajlajty_seed_command( $args, $assoc_args ) {
	$dry_run = isset( $assoc_args['dry-run'] );

	if ( isset( $assoc_args['file'] ) ) {
		$files = array( $assoc_args['file'] );
	} else {
		$files = glob( __DIR__ . '/data/*.csv' );
	}

	if ( empty( $files ) ) {
		WP_CLI::error( 'Brak plików CSV do przetworzenia (sprawdź data/ albo podaj --file).' );
	}

	if ( $dry_run ) {
		WP_CLI::log( '== TRYB --dry-run: nic nie zapisuję, tylko listuję ==' );
	}

	$totals = array(
		'created'   => 0,
		'updated'   => 0,
		'unchanged' => 0,
		'rejected'  => 0,
	);

	foreach ( $files as $file ) {
		$stats = hajlajty_seed_process_file( $file, $dry_run );
		if ( is_wp_error( $stats ) ) {
			WP_CLI::warning( sprintf( '%s: %s', basename( $file ), $stats->get_error_message() ) );
			continue;
		}
		foreach ( $totals as $key => $_unused ) {
			$totals[ $key ] += $stats[ $key ];
		}
	}

	$summary = sprintf(
		'Podsumowanie: utworzono %d, zaktualizowano %d, bez zmian %d, odrzucono %d.',
		$totals['created'],
		$totals['updated'],
		$totals['unchanged'],
		$totals['rejected']
	);

	if ( $totals['rejected'] > 0 ) {
		WP_CLI::warning( $summary . ' Popraw odrzucone wiersze i uruchom ponownie.' );
	} else {
		WP_CLI::success( $summary );
	}
}

/**
 * Przetwarza jeden plik CSV: parsuje, wykrywa taksonomię po nagłówkach,
 * upsertuje każdy poprawny wiersz. Zwraca statystyki albo WP_Error dla całego
 * pliku (nieczytelny / brak nagłówków / nieznany zestaw kolumn).
 *
 * @return array|WP_Error
 */
function hajlajty_seed_process_file( $path, $dry_run ) {
	if ( ! is_readable( $path ) ) {
		return new WP_Error( 'hajlajty_seed_unreadable', 'plik nie istnieje lub brak dostępu.' );
	}

	$parsed = hajlajty_seed_parse_csv( $path );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}
	if ( empty( $parsed['headers'] ) ) {
		return new WP_Error( 'hajlajty_seed_empty', 'brak nagłówków lub danych.' );
	}

	$kind = hajlajty_seed_detect_kind( $parsed['headers'] );
	if ( null === $kind ) {
		return new WP_Error(
			'hajlajty_seed_unknown_columns',
			'nieznany zestaw kolumn (oczekiwane: nazwa_pl+api_id+fifa_code dla drużyn lub nazwa_pl+league_id dla rozgrywek).'
		);
	}

	WP_CLI::log(
		sprintf( '— %s → taksonomia „%s" (%d wierszy danych)', basename( $path ), $kind, count( $parsed['data'] ) )
	);

	$stats = array(
		'created'   => 0,
		'updated'   => 0,
		'unchanged' => 0,
		'rejected'  => 0,
	);

	foreach ( $parsed['data'] as $row ) {
		$result = ( 'druzyna' === $kind )
			? hajlajty_seed_handle_team_row( $row, $dry_run )
			: hajlajty_seed_handle_league_row( $row, $dry_run );

		$stats[ $result ]++;
	}

	return $stats;
}

/**
 * Parsuje CSV do nagłówków + wierszy danych (mapowanych po nagłówkach).
 * Pomija puste linie i komentarze (pierwsza komórka zaczyna się od '#').
 * Zdejmuje BOM UTF-8 z pierwszej kolumny nagłówka (Excel).
 *
 * @return array{headers: string[], data: array<int, array{line:int, values:array}>}|WP_Error
 */
function hajlajty_seed_parse_csv( $path ) {
	$handle = fopen( $path, 'r' );
	if ( false === $handle ) {
		return new WP_Error( 'hajlajty_seed_open', 'nie udało się otworzyć pliku.' );
	}

	$headers = array();
	$data    = array();
	$line_no = 0;

	while ( false !== ( $record = fgetcsv( $handle, 0, ',' ) ) ) {
		$line_no++;

		// Pusta linia: fgetcsv zwraca array(null) dla wiersza bez treści.
		if ( null === $record || ( 1 === count( $record ) && ( null === $record[0] || '' === trim( (string) $record[0] ) ) ) ) {
			continue;
		}

		// Komentarz: pierwsza komórka (po lewym trimie) zaczyna się od '#'.
		$first = isset( $record[0] ) ? ltrim( (string) $record[0] ) : '';
		if ( '' !== $first && '#' === $first[0] ) {
			continue;
		}

		if ( empty( $headers ) ) {
			// Pierwszy nie-komentarz = nagłówki. Zdejmij BOM z pierwszej kolumny.
			$record[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $record[0] );
			$headers   = array_map( 'trim', $record );
			continue;
		}

		// Wiersz danych → mapuj po nagłówkach (kolejność kolumn dowolna).
		$values = array();
		foreach ( $headers as $i => $key ) {
			$values[ $key ] = isset( $record[ $i ] ) ? (string) $record[ $i ] : '';
		}
		$data[] = array(
			'line'   => $line_no,
			'values' => $values,
		);
	}

	fclose( $handle );

	return array(
		'headers' => $headers,
		'data'    => $data,
	);
}

/**
 * Wykrywa docelową taksonomię po zestawie nagłówków. null = nieznany plik.
 */
function hajlajty_seed_detect_kind( $headers ) {
	$set = array_flip( $headers );

	if ( isset( $set['nazwa_pl'], $set['api_id'], $set['fifa_code'] ) ) {
		return 'druzyna';
	}
	if ( isset( $set['nazwa_pl'], $set['league_id'] ) ) {
		return 'rozgrywki';
	}
	return null;
}

/**
 * Waliduje i upsertuje wiersz drużyny. Zwraca created|updated|unchanged|rejected.
 */
function hajlajty_seed_handle_team_row( $row, $dry_run ) {
	$line   = $row['line'];
	$values = $row['values'];

	$name = isset( $values['nazwa_pl'] ) ? trim( $values['nazwa_pl'] ) : '';
	// fifa_code: sanitacja kanoniczna ze slice'a „match" (litery, WIELKIE).
	$fifa_code = isset( $values['fifa_code'] ) ? hajlajty_match_sanitize_fifa_code( $values['fifa_code'] ) : '';
	$api_id    = isset( $values['api_id'] ) ? absint( $values['api_id'] ) : 0;

	if ( '' === $name ) {
		WP_CLI::warning( sprintf( 'wiersz %d: pusta nazwa_pl — pomijam.', $line ) );
		return 'rejected';
	}
	if ( $api_id < 1 ) {
		WP_CLI::warning(
			sprintf( 'wiersz %d (%s): api_id puste lub 0 — pomijam (term bez team.id nie zresolwuje się przy imporcie).', $line, $name )
		);
		return 'rejected';
	}
	if ( '' === $fifa_code ) {
		WP_CLI::warning( sprintf( 'wiersz %d (%s): fifa_code pusty lub bez liter — pomijam.', $line, $name ) );
		return 'rejected';
	}

	return hajlajty_seed_upsert_term( 'druzyna', 'api_id', $api_id, $name, array( 'fifa_code' => $fifa_code ), $dry_run );
}

/**
 * Waliduje i upsertuje wiersz rozgrywek. Zwraca created|updated|unchanged|rejected.
 */
function hajlajty_seed_handle_league_row( $row, $dry_run ) {
	$line   = $row['line'];
	$values = $row['values'];

	$name      = isset( $values['nazwa_pl'] ) ? trim( $values['nazwa_pl'] ) : '';
	$league_id = isset( $values['league_id'] ) ? absint( $values['league_id'] ) : 0;

	if ( '' === $name ) {
		WP_CLI::warning( sprintf( 'wiersz %d: pusta nazwa_pl — pomijam.', $line ) );
		return 'rejected';
	}
	if ( $league_id < 1 ) {
		WP_CLI::warning( sprintf( 'wiersz %d (%s): league_id puste lub 0 — pomijam.', $line, $name ) );
		return 'rejected';
	}

	return hajlajty_seed_upsert_term( 'rozgrywki', 'league_id', $league_id, $name, array(), $dry_run );
}

/**
 * Znajduje term po term meta (stabilne ID). Zwraca obiekt WP_Term albo null.
 */
function hajlajty_seed_find_term_by_meta( $taxonomy, $meta_key, $meta_value ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 1,
			'meta_query' => array(
				array(
					'key'   => $meta_key,
					'value' => (string) $meta_value,
				),
			),
		)
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return null;
	}
	return $terms[0];
}

/**
 * Idempotentny upsert termu po stabilnym ID (NIGDY po nazwie).
 *
 *  - Term istnieje (po $id_key) → aktualizuj nazwę i dodatkowe meta, jeśli się
 *    zmieniły. Zmiana nazwy termu jest bezpieczna: slug meczu jest zamrożony
 *    przy insert i nie regeneruje się (decyzja #7).
 *  - Term nie istnieje → utwórz. Jeśli istnieje już term o tej NAZWIE bez
 *    naszego ID (np. utworzony ręcznie w Fazie 1), ADOPTUJ go i ostempluj
 *    stabilnym ID, zamiast tworzyć duplikat.
 *
 * @return string created|updated|unchanged|rejected
 */
function hajlajty_seed_upsert_term( $taxonomy, $id_key, $id_value, $name, $extra_meta, $dry_run ) {
	$prefix   = $dry_run ? '[dry-run] ' : '';
	$existing = hajlajty_seed_find_term_by_meta( $taxonomy, $id_key, $id_value );

	if ( $existing ) {
		$changed = false;

		if ( $existing->name !== $name ) {
			if ( ! $dry_run ) {
				wp_update_term( $existing->term_id, $taxonomy, array( 'name' => $name ) );
			}
			$changed = true;
		}

		foreach ( $extra_meta as $meta_key => $meta_value ) {
			if ( (string) get_term_meta( $existing->term_id, $meta_key, true ) !== (string) $meta_value ) {
				if ( ! $dry_run ) {
					update_term_meta( $existing->term_id, $meta_key, $meta_value );
				}
				$changed = true;
			}
		}

		if ( $changed ) {
			WP_CLI::log( sprintf( '%szaktualizuję „%s" (%s=%s)', $prefix, $name, $id_key, $id_value ) );
			return 'updated';
		}
		WP_CLI::log( sprintf( '%sbez zmian „%s" (%s=%s)', $prefix, $name, $id_key, $id_value ) );
		return 'unchanged';
	}

	if ( $dry_run ) {
		WP_CLI::log( sprintf( '%sutworzę „%s" (%s=%s)', $prefix, $name, $id_key, $id_value ) );
		return 'created';
	}

	$inserted = wp_insert_term( $name, $taxonomy );

	if ( is_wp_error( $inserted ) ) {
		// Term o tej nazwie już istnieje bez naszego ID → adoptuj i ostempluj.
		if ( 'term_exists' === $inserted->get_error_code() ) {
			$term_id = (int) $inserted->get_error_data();
			update_term_meta( $term_id, $id_key, $id_value );
			foreach ( $extra_meta as $meta_key => $meta_value ) {
				update_term_meta( $term_id, $meta_key, $meta_value );
			}
			WP_CLI::log( sprintf( 'adoptuję istniejący „%s" i stempluję %s=%s', $name, $id_key, $id_value ) );
			return 'updated';
		}

		WP_CLI::warning( sprintf( '„%s": %s — pomijam.', $name, $inserted->get_error_message() ) );
		return 'rejected';
	}

	$term_id = (int) $inserted['term_id'];
	update_term_meta( $term_id, $id_key, $id_value );
	foreach ( $extra_meta as $meta_key => $meta_value ) {
		update_term_meta( $term_id, $meta_key, $meta_value );
	}
	WP_CLI::log( sprintf( 'utworzono „%s" (%s=%s)', $name, $id_key, $id_value ) );
	return 'created';
}
