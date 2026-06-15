<?php
/**
 * Cienki klient HTTP api-football (v3). Tylko realia API — bez abstrakcji na
 * zapas (decyzja #8). Host bezpośredni API-Sports + nagłówek x-apisports-key.
 *
 * Odporność wymagana w tej fazie:
 *  - brak klucza w wp-config → czytelny błąd, nie cichy fail;
 *  - timeout (15 s), żeby nie wisieć (ważne pod przyszły cron);
 *  - api-football zwraca błędy W CIELE (pole `errors`, często HTTP 200) — sprawdzamy;
 *  - log limitu zapytań (nagłówki) + ostrzeżenie przy zbliżaniu się do limitu;
 *  - puste `response: []` (zły fixture / brak danych) = NIE błąd, łagodnie [].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bazowy host API (bezpośredni API-Sports — zgodny z próbkami w docs/). */
function hajlajty_import_api_host() {
	return 'https://v3.football.api-sports.io';
}

/**
 * Klucz API ze stałej HAJLAJTY_APIFOOTBALL_KEY (wp-config) lub env (fallback).
 * Zwraca string albo WP_Error z instrukcją — nigdy pusty klucz po cichu.
 *
 * @return string|WP_Error
 */
function hajlajty_import_api_key() {
	if ( defined( 'HAJLAJTY_APIFOOTBALL_KEY' ) && '' !== trim( (string) HAJLAJTY_APIFOOTBALL_KEY ) ) {
		return (string) HAJLAJTY_APIFOOTBALL_KEY;
	}

	$env = getenv( 'HAJLAJTY_APIFOOTBALL_KEY' );
	if ( false !== $env && '' !== trim( (string) $env ) ) {
		return (string) $env;
	}

	return new WP_Error(
		'hajlajty_import_no_key',
		"Brak klucza API. Dodaj w wp-config.php: define( 'HAJLAJTY_APIFOOTBALL_KEY', 'twoj_klucz' );"
	);
}

/**
 * Wykonuje GET do endpointu api-football i zwraca tablicę `response`.
 *
 * @param string $endpoint Np. 'fixtures', 'fixtures/events'.
 * @param array  $query    Parametry zapytania (wartości liczbowe).
 * @return array|WP_Error  Tablica `response` (może być pusta) albo WP_Error.
 */
function hajlajty_import_request( $endpoint, $query = array() ) {
	$key = hajlajty_import_api_key();
	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$url = hajlajty_import_api_host() . '/' . ltrim( $endpoint, '/' );
	if ( ! empty( $query ) ) {
		$url = add_query_arg( $query, $url );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'x-apisports-key' => $key,
				'Accept'          => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'hajlajty_import_http',
			sprintf( 'Błąd połączenia z api-football (%s): %s', $endpoint, $response->get_error_message() )
		);
	}

	hajlajty_import_log_rate_limit( $response, $endpoint );

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error(
			'hajlajty_import_http_status',
			sprintf( 'api-football zwróciło HTTP %d dla %s.', $code, $endpoint )
		);
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error( 'hajlajty_import_json', sprintf( 'Niepoprawny JSON z %s.', $endpoint ) );
	}

	// Błędy w ciele (często z HTTP 200): `errors` niepuste = przerwij.
	if ( ! empty( $data['errors'] ) ) {
		$messages = is_array( $data['errors'] )
			? implode( '; ', array_map( 'strval', (array) $data['errors'] ) )
			: (string) $data['errors'];
		return new WP_Error(
			'hajlajty_import_api_errors',
			sprintf( 'api-football błąd (%s): %s', $endpoint, $messages )
		);
	}

	// Puste `response` to poprawny wynik (np. zły fixture / brak danych).
	return ( isset( $data['response'] ) && is_array( $data['response'] ) ) ? $data['response'] : array();
}

/**
 * Loguje stan limitu zapytań z nagłówków odpowiedzi i ostrzega przy zbliżaniu
 * się do końca puli. Brak nagłówków (plan bez limitu) = cisza.
 */
function hajlajty_import_log_rate_limit( $response, $endpoint ) {
	$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-requests-remaining' );
	$limit     = wp_remote_retrieve_header( $response, 'x-ratelimit-requests-limit' );

	if ( '' === $remaining && '' === $limit ) {
		return;
	}

	hajlajty_import_log(
		sprintf(
			'Limit api-football: pozostało %s z %s (po %s).',
			'' !== $remaining ? $remaining : '?',
			'' !== $limit ? $limit : '?',
			$endpoint
		)
	);

	if ( '' !== $remaining && is_numeric( $remaining ) && (int) $remaining <= 5 ) {
		hajlajty_import_log(
			sprintf( 'UWAGA: zbliżasz się do limitu api-football (pozostało %d zapytań).', (int) $remaining ),
			'warning'
		);
	}
}

/**
 * Lekki logger: pod WP-CLI używa jego kanałów, poza CLI pisze do error_log
 * (przyszły cron). Nie abstrakcja na zapas — to jedyny sposób, by klient mówił
 * o limicie/sekcjach i pod CLI, i pod cronem.
 */
function hajlajty_import_log( $message, $level = 'log' ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		if ( 'warning' === $level ) {
			WP_CLI::warning( $message );
		} else {
			WP_CLI::log( $message );
		}
		return;
	}
	error_log( '[hajlajty-import] ' . $message );
}

/**
 * Jak hajlajty_import_request, ale błąd sekcji opcjonalnej (events/lineups/
 * statistics) NIE przerywa importu meczu — loguje ostrzeżenie i zwraca [].
 */
function hajlajty_import_request_or_empty( $endpoint, $query ) {
	$result = hajlajty_import_request( $endpoint, $query );
	if ( is_wp_error( $result ) ) {
		hajlajty_import_log( sprintf( '%s: %s (pomijam tę sekcję)', $endpoint, $result->get_error_message() ), 'warning' );
		return array();
	}
	return $result;
}
