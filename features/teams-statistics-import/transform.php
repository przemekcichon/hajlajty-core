<?php
/**
 * Transformacja odpowiedzi api-football `/teams/statistics` → przycięty CURATED
 * subset statystyk drużyny. Czysta funkcja danych: bez zapisu do bazy, bez HTTP
 * (analogicznie do transform.php slice'a standings-import). Klucze API czytane
 * VERBATIM (case-sensitive), zero koercji wartości (raw int/string/null jak z
 * API) — JEDYNY wyjątek to derywacja sum kartek (patrz niżej).
 *
 * UWAGA — kształt `response`: dla `/teams/statistics` `response` to POJEDYNCZY
 * OBIEKT (nie tablica jak w `/standings`, gdzie czytaliśmy `response[0]`). Klient
 * `hajlajty_import_request` zdejmuje kopertę get/parameters/errors/results/paging
 * i zwraca samo `response` — tu więc wprost `$response['form']`,
 * `$response['fixtures']['played']['total']` itd.
 *
 * Przycięcie (decyzja MVP-f, data-inventory §10 + design „Profil Belgia"). #3:
 * JSON ma nie puchnąć; #8: nie zapisujemy na zapas. ZOSTAWIAMY tylko to, co
 * widget statystyk PB realnie wyrenderuje:
 *   - `form`            — string liter W/D/L (forma);
 *   - `fixtures`        — tylko `.total` z played/wins/draws/loses;
 *   - `goals` for/against — `.total.total` (suma) + `.average.total` (STRING!);
 *   - `clean_sheet` / `failed_to_score` — `.total`;
 *   - `cards`           — SUMA `.total` żółtych/czerwonych po przedziałach minut.
 * ODRZUCAMY (nieużywane przez MVP-g, szum): `biggest`, `penalty`, `lineups`
 * (formacje, NIE kadra imienna), `goals.*.minute`/`under_over`, rozbicia
 * `home/away` (render liczy `total`), `cards` per-przedział (tylko sumy),
 * `league`/`team` logo/flag/name (drużynę i flagę render bierze z termu „druzyna"
 * po api_id / fifa_code, nie z payloadu).
 *
 * LUKA (NIE źródło MVP-f, zaznaczona w PR): widget PB pokazuje „Posiadanie piłki"
 * — to stat PER-MECZ, którego `/teams/statistics` NIE ma. MVP-g albo usunie ten
 * wiersz, albo weźmie go skądinąd; NIE wymyślamy tu tego pola.
 *
 * Nullable: próbka bywa „z 1 meczu" — wartości potrafią być 0/null. Transform
 * NIE koercuje (`?? null`); render robi fallback (jak standings).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buduje curated subset statystyk drużyny z odpowiedzi `/teams/statistics`.
 * `$league_id`/`$season` wstrzykiwane (są też w kluczu meta) — kontekst pomocny
 * przy debugu i przyszłym headless; trzymamy parametry zapytania, nie
 * `response.league.id`, bo to one definiują, gdzie zapis wylądował.
 *
 * @param array      $response  Tablica `response` z `hajlajty_import_request('teams/statistics', …)`.
 * @param int        $league_id api-football `league.id` (kontekst + klucz meta).
 * @param int|string $season    Sezon (kontekst + klucz meta), np. „2026".
 * @return array Curated subset (pusty, gdy `response` puste/niepoprawne).
 */
function hajlajty_team_stats_transform( $response, $league_id, $season ) {
	if ( ! is_array( $response ) || empty( $response ) ) {
		return array();
	}

	$fixtures = is_array( $response['fixtures'] ?? null ) ? $response['fixtures'] : array();
	$goals    = is_array( $response['goals'] ?? null ) ? $response['goals'] : array();
	$cards    = is_array( $response['cards'] ?? null ) ? $response['cards'] : array();

	return array(
		'league_id'       => (int) $league_id,
		'season'          => (string) $season,
		'form'            => $response['form'] ?? null,
		'fixtures'        => array(
			'played' => $fixtures['played']['total'] ?? null,
			'wins'   => $fixtures['wins']['total'] ?? null,
			'draws'  => $fixtures['draws']['total'] ?? null,
			'loses'  => $fixtures['loses']['total'] ?? null, // API: „loses" (sic), VERBATIM.
		),
		'goals'           => array(
			'for'     => array(
				'total'   => $goals['for']['total']['total'] ?? null,
				'average' => $goals['for']['average']['total'] ?? null, // STRING (np. „2.6") — zero koercji.
			),
			'against' => array(
				'total'   => $goals['against']['total']['total'] ?? null,
				'average' => $goals['against']['average']['total'] ?? null,
			),
		),
		'clean_sheet'     => $response['clean_sheet']['total'] ?? null,
		'failed_to_score' => $response['failed_to_score']['total'] ?? null,
		'cards'           => array(
			'yellow' => hajlajty_team_stats_sum_card_minutes( $cards['yellow'] ?? null ),
			'red'    => hajlajty_team_stats_sum_card_minutes( $cards['red'] ?? null ),
		),
	);
}

/**
 * Derywacja (JEDYNE odstępstwo od „verbatim"): sumuje `.total` po przedziałach
 * minut jednego koloru kartek. API daje `cards.yellow` jako mapę przedziałów
 * („0 - 15" → { total, percentage }, …); widget PB chce jedną liczbę. Sumujemy
 * tylko numeryczne `.total` (null/„brak" = 0). Brak danych → 0 (drużyna bez kartek
 * realnie ma 0 — to nie ten sam przypadek co null pojedynczego pola passthrough).
 *
 * @param mixed $minute_buckets `cards.yellow` lub `cards.red` (mapa przedziałów).
 * @return int Suma kartek danego koloru.
 */
function hajlajty_team_stats_sum_card_minutes( $minute_buckets ) {
	if ( ! is_array( $minute_buckets ) ) {
		return 0;
	}

	$sum = 0;
	foreach ( $minute_buckets as $bucket ) {
		$total = is_array( $bucket ) ? ( $bucket['total'] ?? null ) : null;
		if ( is_numeric( $total ) ) {
			$sum += (int) $total;
		}
	}
	return $sum;
}
