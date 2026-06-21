<?php
/**
 * Transformacja odpowiedzi api-football `/standings` → tabela 12 grup A–L.
 * Czysta funkcja danych: bez zapisu do bazy, bez HTTP (analogicznie do
 * transform.php slice'a match-import). Wejście = tablica `response` zwrócona
 * przez `hajlajty_import_request` (koperta get/parameters/errors/results/paging
 * już zdjęta przez klienta). Klucze API czytane VERBATIM, zero koercji wartości.
 *
 * Wynik (kontrakt dla MVP-e): tablica kluczowana literą grupy →
 *   [ 'A' => [ wiersz, … ], …, 'L' => [ … ] ]
 * gdzie wiersz = przycięty rekord drużyny:
 *   { rank, team_id, points, played, win, draw, lose, gf, ga, diff, group, zone }
 *
 * Przycięcie (data-inventory §9): zostają pola tabeli grupowej. ODRZUCONE:
 *  - `team.logo` / `team.name` — render bierze flagę/nazwę z termu „druzyna" po
 *    `team.id` → `api_id` / `fifa_code` (jak na kartach meczu), nie z payloadu;
 *  - `home` / `away` — turniejowa tabela liczy `all`; rozbicie u/w niepotrzebne;
 *  - `form`, `status`, `update` — nieużywane przez widok TG.
 *
 * ANOMALIA „Group Stage" (FAKT z próbki): `standings` to TABLICA TABLIC; 13.
 * wewnętrzna tablica ma `group="Group Stage"` (zbiorczy ranking 12 drużyn) obok
 * 12 grup `"Group A".."Group L"`. Bierzemy WYŁĄCZNIE grupy pasujące do
 * `^Group ([A-L])$` — „Group Stage" odpada (litera nie wyłuska się).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Litera grupy „A".."L" z pola `group` (np. „Group A" → „A"). Zwraca null dla
 * „Group Stage" i wszystkiego, co nie jest pojedynczą grupą A–L — to filtr
 * anomalii w jednym miejscu.
 *
 * @param mixed $group Wartość `group` z wiersza standings.
 * @return string|null Litera A–L albo null.
 */
function hajlajty_standings_group_letter( $group ) {
	if ( is_string( $group ) && preg_match( '/^Group ([A-L])$/', $group, $m ) ) {
		return $m[1];
	}
	return null;
}

/**
 * Przycina jeden wiersz drużyny do pól tabeli grupowej. Zwraca null, gdy brak
 * `team.id` (bez niego render nie zresolwuje drużyny → wiersz bezużyteczny).
 * Wartości SUROWE (int/null jak z API), zero koercji.
 *
 * @param array  $row    Wiersz `standings[i][j]`.
 * @param string $letter Litera grupy (klucz tabeli; trafia też do wiersza).
 * @return array|null
 */
function hajlajty_standings_map_row( $row, $letter ) {
	$team_id = (int) ( $row['team']['id'] ?? 0 );
	if ( $team_id <= 0 ) {
		return null;
	}

	$all   = is_array( $row['all'] ?? null ) ? $row['all'] : array();
	$goals = is_array( $all['goals'] ?? null ) ? $all['goals'] : array();

	return array(
		'rank'    => $row['rank'] ?? null,
		'team_id' => $team_id,
		'points'  => $row['points'] ?? null,
		'played'  => $all['played'] ?? null,
		'win'     => $all['win'] ?? null,
		'draw'    => $all['draw'] ?? null,
		'lose'    => $all['lose'] ?? null,
		'gf'      => $goals['for'] ?? null,
		'ga'      => $goals['against'] ?? null,
		'diff'    => $row['goalsDiff'] ?? null,
		'group'   => $letter,
		'zone'    => $row['description'] ?? null, // np. „Round of 32"; null poza strefą.
	);
}

/**
 * Buduje tabelę grup A–L z odpowiedzi `/standings`. Pomija „Group Stage" i wiersze
 * bez `team.id`. Kolejność kluczy = kolejność z API (A…L).
 *
 * @param array $response Tablica `response` z `hajlajty_import_request('standings', …)`.
 * @return array<string,array<int,array>> Tabela kluczowana literą grupy (może być pusta).
 */
function hajlajty_standings_transform( $response ) {
	$standings = $response[0]['league']['standings'] ?? null;
	if ( ! is_array( $standings ) ) {
		return array();
	}

	$table = array();
	foreach ( $standings as $group_rows ) {
		if ( ! is_array( $group_rows ) ) {
			continue;
		}
		foreach ( $group_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$letter = hajlajty_standings_group_letter( $row['group'] ?? null );
			if ( null === $letter ) {
				continue; // „Group Stage" / nie-A-L.
			}
			$mapped = hajlajty_standings_map_row( $row, $letter );
			if ( null === $mapped ) {
				continue; // brak team.id.
			}
			$table[ $letter ][] = $mapped;
		}
	}

	return $table;
}

/**
 * Liczba WSZYSTKICH wierszy w odpowiedzi (z „Group Stage" włącznie) — do logu
 * „ile pominięto" (wejście − zapisane). Czysta funkcja.
 *
 * @param array $response Tablica `response`.
 * @return int
 */
function hajlajty_standings_count_input_rows( $response ) {
	$standings = $response[0]['league']['standings'] ?? null;
	if ( ! is_array( $standings ) ) {
		return 0;
	}
	$n = 0;
	foreach ( $standings as $group_rows ) {
		if ( is_array( $group_rows ) ) {
			$n += count( $group_rows );
		}
	}
	return $n;
}
