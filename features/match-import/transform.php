<?php
/**
 * Transformacja odpowiedzi api-football → tablica `match_data` (Sekcja C
 * mapowania). Czysta funkcja danych: bez zapisu do bazy, bez HTTP. Kształt
 * RAW/przycięty blisko API (decyzja #3), z dwoma świadomymi odstępstwami:
 * `statistics` jako obiekt kluczowany po `type` i strony jako `home`/`away`.
 *
 * Zasady (potwierdzone):
 *  - statistics: WSZYSTKIE typy zwrócone przez API, keyed by `type` (klucz
 *    VERBATIM, mieszany case zostaje), wartości SUROWE bez koercji
 *    ("66%" string, 7 int, null, "0.57" string). Bez tłumaczenia na PL —
 *    selekcja + etykiety + format = render (Faza 3).
 *  - events[].side = home/away wyliczone z team.id; player_id ZOSTAJE.
 *  - subst: player=wchodzący, assist=schodzący (potwierdzone empirycznie).
 *    Transform przepisuje SUROWO, niczego nie relabeluje (relabel = render).
 *    Dla subst zostaje assist_id (łącznik schodzącego ze składem); przy
 *    bramkach/kartkach assist.id wycinany (mapowanie sekcja B).
 *  - lineups: id zawodnika ZOSTAJE (łącznik events↔skład).
 *  - events/lineups/statistics OPCJONALNE (zapowiedź NS ich nie ma).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buduje tablicę match_data z elementu fixtures + opcjonalnych sekcji.
 *
 * @param array $fixture    Jeden element odpowiedzi `fixtures`.
 * @param array $events     Odpowiedź `fixtures/events` (lista) lub [].
 * @param array $lineups    Odpowiedź `fixtures/lineups` (2 elementy) lub [].
 * @param array $statistics Odpowiedź `fixtures/statistics` (2 elementy) lub [].
 * @return array
 */
function hajlajty_import_build_match_data( $fixture, $events = array(), $lineups = array(), $statistics = array() ) {
	$home_id = (int) ( $fixture['teams']['home']['id'] ?? 0 );
	$away_id = (int) ( $fixture['teams']['away']['id'] ?? 0 );

	$data = array(
		'fixture_id' => (int) ( $fixture['fixture']['id'] ?? 0 ),
		'kickoff'    => $fixture['fixture']['date'] ?? null,
		'round'      => $fixture['league']['round'] ?? null,
		'status'     => array(
			'short'   => $fixture['fixture']['status']['short'] ?? null,
			'elapsed' => $fixture['fixture']['status']['elapsed'] ?? null,
			'extra'   => $fixture['fixture']['status']['extra'] ?? null,
		),
		'goals'      => array(
			'home' => $fixture['goals']['home'] ?? null,
			'away' => $fixture['goals']['away'] ?? null,
		),
		'score'      => array(
			'halftime'  => $fixture['score']['halftime'] ?? array( 'home' => null, 'away' => null ),
			'fulltime'  => $fixture['score']['fulltime'] ?? array( 'home' => null, 'away' => null ),
			'extratime' => $fixture['score']['extratime'] ?? array( 'home' => null, 'away' => null ),
			'penalty'   => $fixture['score']['penalty'] ?? array( 'home' => null, 'away' => null ),
		),
		'teams'      => array(
			'home' => array( 'api_id' => $home_id ),
			'away' => array( 'api_id' => $away_id ),
		),
	);

	$mapped_events = hajlajty_import_map_events( $events, $home_id );
	if ( ! empty( $mapped_events ) ) {
		$data['events'] = $mapped_events;
	}

	$mapped_lineups = hajlajty_import_map_lineups( $lineups, $home_id );
	if ( ! empty( $mapped_lineups ) ) {
		$data['lineups'] = $mapped_lineups;
	}

	$mapped_stats = hajlajty_import_map_statistics( $statistics, $home_id );
	if ( ! empty( $mapped_stats ) ) {
		$data['statistics'] = $mapped_stats;
	}

	return $data;
}

/** Oś czasu: side z team.id, player_id zostaje; subst surowo (+assist_id). */
function hajlajty_import_map_events( $events, $home_id ) {
	if ( empty( $events ) || ! is_array( $events ) ) {
		return array();
	}

	$out = array();
	foreach ( $events as $ev ) {
		$side = ( (int) ( $ev['team']['id'] ?? 0 ) === $home_id ) ? 'home' : 'away';

		$event = array(
			'minute'    => $ev['time']['elapsed'] ?? null,
			'extra'     => $ev['time']['extra'] ?? null,
			'side'      => $side,
			'type'      => $ev['type'] ?? null,
			'detail'    => $ev['detail'] ?? null,
			'player'    => $ev['player']['name'] ?? null,
			'player_id' => $ev['player']['id'] ?? null,
			'assist'    => $ev['assist']['name'] ?? null,
		);

		// subst: assist = schodzący (player = wchodzący) — potwierdzone empirycznie.
		// assist_id zostaje TYLKO tu (łącznik schodzącego ze składem); relabel = render.
		if ( isset( $ev['type'] ) && 'subst' === $ev['type'] ) {
			$event['assist_id'] = $ev['assist']['id'] ?? null;
		}

		$out[] = $event;
	}

	return $out;
}

/**
 * Składy keyed by side; id/name/number/pos/grid zostają.
 *
 * Faza 3bi: zachowujemy też `colors` (barwy koszulek — render maluje boisko) i
 * `coach.name` (główka składu). `colors` trzymamy w kształcie z API, bez koercji
 * (player.primary/number/border + goalkeeper.*); z `coach` bierzemy TYLKO `name`
 * (id/photo nieużywane — render po nazwie). Reszta wycięcia (team.logo/name,
 * player.* poza 5 polami) BEZ ZMIAN — sekcja B mapowania.
 */
function hajlajty_import_map_lineups( $lineups, $home_id ) {
	if ( empty( $lineups ) || ! is_array( $lineups ) ) {
		return array();
	}

	$out = array();
	foreach ( $lineups as $team_lineup ) {
		$side = ( (int) ( $team_lineup['team']['id'] ?? 0 ) === $home_id ) ? 'home' : 'away';

		$out[ $side ] = array(
			'formation'   => $team_lineup['formation'] ?? null,
			'colors'      => $team_lineup['team']['colors'] ?? null, // kształt z API, zero koercji.
			'coach'       => array( 'name' => $team_lineup['coach']['name'] ?? null ),
			'startXI'     => hajlajty_import_map_players( $team_lineup['startXI'] ?? array() ),
			'substitutes' => hajlajty_import_map_players( $team_lineup['substitutes'] ?? array() ),
		);
	}

	return $out;
}

/** Mapuje listę {player:{...}} do płaskich rekordów zawodnika. */
function hajlajty_import_map_players( $list ) {
	if ( empty( $list ) || ! is_array( $list ) ) {
		return array();
	}

	$out = array();
	foreach ( $list as $entry ) {
		$player = $entry['player'] ?? array();
		$out[]  = array(
			'id'     => $player['id'] ?? null,
			'name'   => $player['name'] ?? null,
			'number' => $player['number'] ?? null,
			'pos'    => $player['pos'] ?? null,
			'grid'   => $player['grid'] ?? null,
		);
	}

	return $out;
}

/**
 * Statystyki keyed by side → obiekt keyed by `type` (VERBATIM, wszystkie typy,
 * wartości surowe). Tablica par {type,value} z API → obiekt (szablon nie szuka
 * liniowo). Zero koercji wartości.
 */
function hajlajty_import_map_statistics( $statistics, $home_id ) {
	if ( empty( $statistics ) || ! is_array( $statistics ) ) {
		return array();
	}

	$out = array();
	foreach ( $statistics as $team_stats ) {
		$side  = ( (int) ( $team_stats['team']['id'] ?? 0 ) === $home_id ) ? 'home' : 'away';
		$keyed = array();

		foreach ( ( $team_stats['statistics'] ?? array() ) as $pair ) {
			if ( ! isset( $pair['type'] ) ) {
				continue;
			}
			$keyed[ $pair['type'] ] = $pair['value'] ?? null; // VERBATIM.
		}

		$out[ $side ] = $keyed;
	}

	return $out;
}

/**
 * Data meczu RRRR-MM-DD do slugu. api-football zwraca `fixture.date` w UTC
 * (timezone="UTC") — bierzemy część dat z ISO; fallback: parsowanie do UTC.
 */
function hajlajty_import_kickoff_date( $iso ) {
	if ( is_string( $iso ) && preg_match( '/^(\d{4}-\d{2}-\d{2})/', $iso, $m ) ) {
		return $m[1];
	}
	$ts = strtotime( (string) $iso );
	return $ts ? gmdate( 'Y-m-d', $ts ) : '';
}
