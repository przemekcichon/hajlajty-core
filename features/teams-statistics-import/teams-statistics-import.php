<?php
/**
 * Slice "teams-statistics-import" — import statystyk drużyny z api-football
 * `/teams/statistics` do JEDNEGO pola meta na termie taksonomii „druzyna"
 * (CLAUDE.md decyzja #3: dane zbiorcze drużyny per liga×sezon → JSON blob, NIE
 * `match_data`, NIE rozbicie na dziesiątki meta). Tor DANYCH z ZAPISEM, nie
 * widoku: render Profilu kraju to MVP-g (motyw), tu tylko fetch → transform →
 * zapis. MVP-f to PRODUCENT, MVP-g — KONSUMENT.
 *
 * Osobny slice od `standings-import` i `match-import` (inny cykl życia i inny
 * nośnik: statystyki wiszą na termie „druzyna", nie na rozgrywkach ani na meczu;
 * odświeżają się wolno, po kolejkach). Współdzieli WYŁĄCZNIE infrastrukturę
 * HTTP/log z `match-import` (client.php: `hajlajty_import_request` +
 * `hajlajty_import_log`, klucz z wp-config/.env) — to realnie wspólna infra
 * api-football. Resolucję termu „druzyna" slice trzyma U SIEBIE (runner.php).
 *
 * Zapis (decyzja MVP-f): OSOBNE pole meta per (liga, sezon) —
 * `team_stats_<league_id>_<season>` na termie „druzyna". Klucz MUSI namespace'ować
 * po league_id ORAZ sezonie, bo term „druzyna" jest LIGO-AGNOSTYCZNY (ta sama
 * reprezentacja gra w wielu rozgrywkach/sezonach: WŚ teraz, kluby w Fazie 5) —
 * inaczej kolizja. To RÓŻNICA wobec standings (`standings_<sezon>` na termie
 * rozgrywki, gdzie liga jest implicytnie termem). Każda para to niezależny wiersz
 * meta: import jednej (drużyna×liga×sezon) nadpisuje WYŁĄCZNIE swój klucz, bez
 * read-modify-write. Klucz dynamiczny (otwarty zbiór par liga×sezon) NIE jest
 * rejestrowany przez `register_term_meta`; ekspozycja headless przyjdzie przy
 * migracji jako custom resolver WPGraphQL (spójne z decyzją #6, jak standings).
 * MVP-g czyta server-side w PHP.
 *
 * Bootstrap slice'a (vertical slice): ładuje pozostałe pliki katalogu; każdy sam
 * podpina swoje (cli.php rejestruje komendę tylko pod WP-CLI, cron.php — event).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( glob( __DIR__ . '/*.php' ) as $part ) {
	if ( $part !== __FILE__ ) {
		require_once $part;
	}
}
