<?php
/**
 * Slice "standings-import" — import tabel grupowych z api-football `/standings`
 * do JEDNEGO pola na termie taksonomii „rozgrywki" (CLAUDE.md decyzja #3: dane
 * grupowe/ligowe, NIE per-mecz → nie `match_data`). Tor DANYCH, nie widoku:
 * render tabel to MVP-e (motyw), tu tylko fetch → transform → zapis.
 *
 * Osobny slice od `match-import` (inny cykl życia: standings odświeża się wolno,
 * po kolejkach; fixtures/live — co minutę). Współdzieli WYŁĄCZNIE infrastrukturę
 * HTTP/log z `match-import` (client.php: `hajlajty_import_request` + generyczny
 * klucz z wp-config/.env, `hajlajty_import_log`) — to realnie wspólna infra
 * api-football, nie logika fixtures. Resolucję termu „rozgrywki" slice trzyma
 * U SIEBIE (runner.php), żeby nie zależeć od runnera fixtures.
 *
 * Zapis (decyzja MVP-d): OSOBNE pole meta per sezon — `standings_<sezon>` na
 * termie rozgrywek. Każdy sezon to niezależny wiersz meta: import jednego sezonu
 * NIE rusza innych (bez read-modify-write, bez ryzyka nadpisania). Skaluje na
 * sezony (WŚ 2022/2026) i ligi (każda liga = swój term). Klucz dynamiczny NIE
 * jest rejestrowany przez `register_term_meta` (otwarty zbiór sezonów); ekspozycja
 * headless przyjdzie przy migracji jako custom resolver WPGraphQL `standings(season)`
 * (spójne z decyzją #6, która odracza pola GraphQL). MVP-e czyta server-side w PHP.
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
