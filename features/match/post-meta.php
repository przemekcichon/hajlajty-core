<?php
/**
 * Atrybuty encji meczu rejestrowane przez slice „match": `fixture_id` i
 * `match_data`. Należą do modelu meczu (slice match jest jego właścicielem),
 * mimo że WYPEŁNIA je slice importu (Faza 2) — rejestracja meta to część
 * definicji encji, nie logiki importu. Trzymanie ich tu, obok CPT/taksonomii,
 * jest spójne z CLAUDE.md („slice 'match' jest właścicielem swoich rzeczy").
 *
 *  - fixture_id — api-football `fixture.id`. Klucz DEDUPLIKACJI importu
 *                 (jest → update, brak → insert). NIE wchodzi do slugu
 *                 (decyzja #7), żyje wyłącznie jako meta.
 *  - match_data — JEDNO pole z przyciętym payloadem api-football jako JSON-string
 *                 (decyzja #3): rdzeń meczu, oś czasu, składy, statystyki.
 *                 Szablony robią json_decode i renderują (Faza 3).
 *  - kickoff    — termin rozegrania meczu (UTC, `Y-m-d H:i:s`). PŁASKIE meta
 *                 (grupa 3 doprecyzowania #3): klucz SORTUJĄCY listy meczów na
 *                 poziomie SQL — MySQL nie sortuje po wartości wewnątrz JSON-a.
 *                 Świadoma dwurola z `match_data.kickoff` (surowy ISO do renderu):
 *                 ten sam moment, dwie role (payload vs klucz sortu), nie zakazana
 *                 duplikacja danych filtrowalnych. Dana z importu, NIE redakcyjna
 *                 (więc meta, nie pole ACF). post_date pozostaje czasem PUBLIKACJI
 *                 wpisu, nie terminem meczu (wariant B — patrz slice importu).
 *
 * show_in_rest => true: headless-ready (decyzja #6). `match_data` wystawiamy jako
 * surowy string JSON — bez schematu obiektu i BEZ warstwy pod GraphQL
 * (register_post_meta wystarcza; zakaz register_graphql_field w tej fazie).
 * Pisze je import przez update_post_meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'hajlajty_match_register_post_meta' );

function hajlajty_match_register_post_meta() {
	register_post_meta(
		'mecz',
		'fixture_id',
		array(
			'type'         => 'integer',
			'single'       => true,
			'show_in_rest' => true,
			'description'  => 'api-football fixture.id — klucz deduplikacji importu.',
		)
	);

	register_post_meta(
		'mecz',
		'match_data',
		array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
			'description'  => 'Przycięty payload api-football jako JSON (decyzja #3).',
		)
	);

	register_post_meta(
		'mecz',
		'kickoff',
		array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
			'description'  => 'Termin meczu (UTC, Y-m-d H:i:s) — płaski klucz sortujący (grupa 3, doprecyzowanie #3).',
		)
	);
}
