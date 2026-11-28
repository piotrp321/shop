# PERF-7. Zapytania panelu i mapy strony – plan zadań

Specyfikacja: `docs/features/admin-queries/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami
(`docs/adr/agentic-software-house.md`, „Przebieg epiku”). Epik ma jedną gałąź (poza `epic/*`) z PR-em do `main`
otwartym od początku jako szkic; zadania realizuje subagent we własnym worktree, sesja orkiestrująca merguje gałęzie
zadań do gałęzi epiku. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem roboczym: po
ukończeniu epiku usuń go.

Start dopiero po raporcie PERF-0 i zatwierdzeniu W1–W20 (specyfikacja, „Wymagane przed startem”).

**Bramka po zadaniu 1.** Fala 2 rusza dopiero, gdy właściciel zatwierdzi w rozmowie warianty K, F, Z i M wybrane
w raporcie zadania 1 (specyfikacja, „Warianty”, „Decyzje”). PERF-7-6 nie zależy od wyboru wariantów i idzie w fali 1. Zadania 2–5 implementują wyłącznie zatwierdzony wariant;
zadanie, którego obszar zostaje bez zmian (np. Z0), odpada, a jego przypadki mierzy zadanie 7. PERF-7-5 jest w odwodzie (D5): odpada, jeśli Z0 spełni P2 i P4 na wspólnym profilu `server`.

**Pominięte zadanie.** Sesja orkiestrująca w tym samym commicie co zapis bramki: oznacza zadanie w „Statusie” jako
„odpadło (wariant …)”, usuwa jego identyfikator z `depends_on` i `parallel_with` pozostałych zadań, a zależności, które
szły przez nie, przenosi na jego zależności (np. bez PERF-7-2 zadanie PERF-7-3 zależy od PERF-7-1), i sprawdza plan
walidatorem planera przed zleceniem fali 2.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | PERF-7-1, PERF-7-6, bramka właściciela | PERF-7-6 zmergowane (0f44b652); PERF-7-1 zmergowane (bda1956b, raport `docs/reports/admin-queries-baseline-2026-11-27.md`); bramka zatwierdzona 2026-11-27 (spec, D5–D9); przyczyna 409 w P4 potwierdzona, scenariusz koszyka poprawiony i P4 przed zmianą powtórzone (5107da1e, e7d9c243); do domknięcia w PERF-7-1: pełny `make load-panel-check` na końcowej wersji |
| 2 | PERF-7-2, PERF-7-4 | PERF-7-2 zmergowane (7ec8414b); PERF-7-4 zmergowane (pełny `test` z Keycloakiem: CI gałęzi epiku); do PERF-7-7 na `server`: R1 (fraza częsta, sort `name`, szacunek ponad 2 s) i licznik frazy gęstej tylko w prefiksie porządku (przejście do 2 mln wierszy); PERF-7-5 w odwodzie (Z0): rusza tylko, gdy Z0 nie spełni P2 albo P4 na wspólnym profilu `server` |
| 3 | PERF-7-3 | zmergowane (01452133, przegląd: 1 runda poprawek); do decyzji właściciela: fraza częsta nieobecna w dziale (zasada 5, R2; `rowerowa` szacunek ok. 3,9 s na `server`); do PERF-7-7: koszt przejścia małej kategorii, dział + szkice/bez zdjęć (szacunek ok. 0,4–0,5 s), licznik działu „bez zdjęć” ma tylko asercję planu (bez granicy wierszy) |
| 4 | PERF-7-7 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit, zadanie odpadło) uzupełnia sesja orkiestrująca.

## TDD i pomiary w zadaniach

Zadania 2–6 dopisują najpierw testy (równoważność wyników, testy planów, gwarancje mapy strony, pager), uruchamiają
je i zapisują w raporcie komendę i wynik RED, potem zmiana i GREEN, `check` i testy dotkniętych aplikacji. Test
planu, który przechodzi przed zmianą, raport zaznacza jako regresję. Zadania 1 i 7 to pomiary: raport podaje
polecenia, wersję danych, konfigurację Postgresa i wyniki trzech przebiegów z rozrzutem. Lokalne pomiary nie
nakładają się na ciężkie pomiary PERF-2; każdy pomiar na worf.pl tylko za osobną zgodą właściciela.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PERF-7",
  "spec": "docs/features/admin-queries/spec.md",
  "tasks": [
    {
      "id": "PERF-7-1",
      "title": "Dowody: pomiar przed zmianą i wybór wariantów",
      "scope": "Scenariusz HTTP mieszanki panelu (k6 w `load/`, token administratora jak w E2E, mieszanka generowana z danych i zapisywana z wynikiem), zestaw zapytań macierzy (`EXPLAIN (ANALYZE, BUFFERS)`, na bazie plików `.sql` z `docs/reports/performance-audit-2026-11-25/results/admin` i `results/pg`, uzupełnionych tylko o przypadki macierzy, których audyt nie miał) i scenariusz P4 (specyfikacja, „Wpływ na sklep”). Pomiar przed zmianą na `small` i `medium`. Hipotezy H1 i H2. Plan zapytania dla każdego fragmentu zamówień i klientów z macierzy (czy pg_trgm wyciąga trygram). Prototypy SQL na kopii `medium` (tymczasowe indeksy i tabele, bez zmian kodu aplikacji): K0–K3, F1–F2, Z1, M1–M2; dla każdego koszt odczytu przypadków swojego obszaru z licznikiem pierwszej strony, zmienna kosztu i ekstrapolacja do `server`, koszt zapisu (WAL z różnicy `pg_current_wal_lsn()` i czas na zapis produktu, cykl zamówienia, zapis klienta), rozmiar. Dla K2 i K3 także przegląd miejsc zapisu z pominięciem repozytorium (`git grep`). Jeśli dostępne jest środowisko `server` (lokalnie albo worf.pl za zgodą właściciela, tylko odczyt), `EXPLAIN` przypadków granicznych także tam. Wybór według reguły specyfikacji: warianty obszaru prototypowane po kolei od najprostszego; pierwszy, który spełnia P2 i P8, kończy porównanie obszaru, bardziej złożonych się nie prototypuje.",
      "acceptance_criteria": [
        "Scenariusze uruchamia jedno polecenie opisane w `load/README.md`; mieszanka i wynik są zapisane razem.",
        "Raport w `docs/reports/` z tabelą macierzy (czas, bufory, wiersze, zmienna kosztu; `small` i `medium`), W7 mieszanki i P4 przed zmianą, trzy przebiegi z rozrzutem.",
        "Raport rozstrzyga H1, H2 i listę fragmentów bez trygramu wynikiem pomiaru.",
        "Dla każdego obszaru tabela wariantów z kosztem odczytu, kosztem zapisu, rozmiarem i ekstrapolacją do `server` (oznaczoną jako szacunek), wybrany wariant z uzasadnieniem i przypadki, których żaden wariant nie spełnia.",
        "Zadanie kończy się raportem i pytaniem do właściciela o zatwierdzenie wariantów; fala 2 nie rusza bez odpowiedzi."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "parallel_with": [
        "PERF-7-6"
      ],
      "paths": [
        "load/",
        "docs/reports/"
      ]
    },
    {
      "id": "PERF-7-2",
      "title": "Lista produktów: fraza według F1 + F2",
      "scope": "`DbalBackofficeProductSource` (klasy pomocnicze wyboru drogi, jeśli potrzebne, w `Persistence/Doctrine/BackofficeProductList/`) według D5 i D6: F1 (przejście indeksu sortowania z budżetem B rosnącym geometrycznie, gdy licznik GIN pokazuje wiele trafień, potem kandydaci z GIN, licznik pierwszej strony tą samą drogą) i F2 (funkcja `catalog_name_bigrams`, indeks GIN bigramów w migracji nietransakcyjnej `CONCURRENTLY`) dla fraz bez trygramu zawężającego wyszukiwanie; regułę wyboru drogi ustala test planu (`a.b`, `ab-cd`, `qx`, `#!#`, 2 znaki z trafieniami). Szkice i bez zdjęć bez kategorii: bez zmian (koszt ograniczony). Filtr kategorii zostaje w obecnej postaci do zadania 3. Komentarz o planach w `DbalBackofficeProductSource` opisuje stan po zmianie. Prototypy i liczby: raport PERF-7-1, sekcje 9.2 i 10.2 (R1).",
      "acceptance_criteria": [
        "Kryterium 1 specyfikacji dla fraz: równoważność z obecnym SQL (wiersze, kolejność, kursory, liczniki), także dla fraz z polskimi znakami, wielkością liter, `%`, `_` i `\\`, fraz bez trygramu z trafieniami i frazy częstej skorelowanej z sortem na kolejnych stronach.",
        "Kryterium 2 dla zmienionych dróg: testy planów na ok. 100 tys. wierszy, w tym fraza skupiona na końcu alfabetu, fraza gęsta, fraza częsta skorelowana z sortem (rosnący budżet) i 2-znakowa rzadka; asercje według zmiennej klasy (budżet, liczba trafień), nie stałego kosztu.",
        "Kandydaci z GIN bigramów są nadzbiorem wyniku `ILIKE` na korpusie z polskimi znakami i wielkimi literami; kryterium 8 (WAL i czas zapisu produktu przed i po) mierzy zbiorczo zadanie 7, zadanie podaje polecenie. Sterowanie planistą tylko według D10, z testami zachowania kodu produkcyjnego (wyjątek, transakcja wywołującego) i uzasadnieniem w komentarzu.",
        "Ekrany Ceny, Stany i wybór wariantów promocji: istniejące testy przechodzą bez zmian.",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-7-1"
      ],
      "parallel_with": [
        "PERF-7-4",
        "PERF-7-6"
      ],
      "paths": [
        "apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DbalBackofficeProductSource.php",
        "apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/BackofficeProductList/",
        "apps/api/migrations/",
        "apps/api/tests/"
      ]
    },
    {
      "id": "PERF-7-3",
      "title": "Lista produktów: filtr kategorii według K1",
      "scope": "Według D5 i D6: K1, czyli indeksy `(primary_category_id, name, id)` i `(primary_category_id, updated_at, id)` (migracja `CONCURRENTLY`), gałąź na kategorię poddrzewa i gałąź kategorii dodatkowych z usuwaniem duplikatów (`DISTINCT`) bez produktów, których kategoria główna jest w poddrzewie; częściowe btree po kategorii dla szkiców i bez zdjęć (`name` i `updated_at`). Dział + fraza średnia: najpierw test planu kandydatów frazy z GIN zawężonych do poddrzewa (wybór drogi po porównaniu rozmiaru poddrzewa z liczbą trafień); jeśli nie spełnia P2, zadanie zatrzymuje się z liczbami do decyzji właściciela. Licznik pierwszej strony i dalsze strony w tej samej strategii co zadanie 2. Bez kopii kluczy (K2, K3) i bez kopii kategorii głównej w `catalog_product_category` (odroczona do pomiaru na `server`). Prototypy: raport PERF-7-1, sekcje 9.1 i 10.2.",
      "acceptance_criteria": [
        "Kryterium 1 specyfikacji dla filtra kategorii, także z frazą, szkicami i bez zdjęć: równoważność z obecnym SQL, w tym produkt z kategorią główną i dodatkową w tym samym poddrzewie, produkt z kilkoma kategoriami dodatkowymi w poddrzewie (bez duplikatu na stronie i w liczniku) i kategoria pusta.",
        "Kryterium 2: testy planów na ok. 100 tys. wierszy dla małej kategorii, dużego liścia z typem na końcu alfabetu, działu, kategorii głównie dodatkowej, działu ze szkicami, działu bez zdjęć i działu z frazą średnią; asercje według zmiennej klasy wariantu.",
        "Zmiana nazwy i przeniesienie kategorii: lista po nich pokazuje produkty nowego poddrzewa (test).",
        "Kryterium 8 (WAL i czas zapisu produktu przed i po) i rozmiar nowych indeksów na `medium` mierzy zbiorczo zadanie 7, zadanie podaje polecenie.",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-7-2"
      ],
      "parallel_with": [
        "PERF-7-6"
      ],
      "paths": [
        "apps/api/src/Catalog/",
        "apps/api/src/DemoData/",
        "apps/api/migrations/",
        "apps/api/tests/"
      ]
    },
    {
      "id": "PERF-7-4",
      "title": "Granice mapy strony: gwarancje G1–G3 i wariant M",
      "scope": "`DbalSitemapProductSource::boundaries` według M1 (D5; budżet zimnego obliczenia granic 2000 ms), granice keysetem w jednej transakcji `REPEATABLE READ` tylko do odczytu, sprawdzenie każdej porcji w chwili cięcia (G2), unieważnienie wpisu granic w `cache.sitemap` po przepełnieniu porcji przy odczycie (G3), `LIMIT` porcji bez zmian (G1). Przy M2 migracja `SET STATISTICS` dla `catalog_product.id` i aktualizacja decyzji D1 w `docs/features/seo/spec.md`; opis w OpenAPI (`product-chunks`), eksport specyfikacji i klientów TS, jeśli opis mówi o dokładnej liczbie produktów porcji.",
      "acceptance_criteria": [
        "Kryterium 6 specyfikacji: porcja do `row_limit` także przy statystykach celowo błędnych (np. po masowym wstawieniu bez `ANALYZE`) i bez statystyk; cięcie z kilku zapytań w jednej transakcji `REPEATABLE READ`, test z równoległymi wstawieniami i usunięciami w trakcie cięcia; każdy produkt w dokładnie jednej porcji, pierwsza bez początku i ostatnia bez końca; przepełnienie po cięciu unieważnia granice, a następna mapa jest pełna.",
        "Czas zimnego obliczenia granic na `medium` przed i po: zadanie podaje polecenie, pomiar zbiorczo w zadaniu 7.",
        "`make -C apps/api check`, `make -C apps/api test` i `make -C apps/store check` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-7-1"
      ],
      "parallel_with": [
        "PERF-7-2",
        "PERF-7-6"
      ],
      "paths": [
        "apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DbalSitemapProductSource.php",
        "apps/api/src/Catalog/Infrastructure/CachingSitemapProductSource.php",
        "apps/api/migrations/",
        "apps/api/tests/",
        "apps/api/openapi.json",
        "apps/store/src/api/schema.d.ts",
        "docs/features/seo/spec.md"
      ]
    },
    {
      "id": "PERF-7-5",
      "title": "Wyszukiwanie zamówień i klientów: Z1 (w odwodzie)",
      "scope": "W odwodzie (D5): rusza tylko, gdy pomiar Z0 na wspólnym profilu `server` nie spełni P2 albo P4 dla zamówień lub klientów. Wtedy: indeksy GIN bigramów na wyrażeniach dzisiejszych indeksów trygramów zamówień i klientów (migracja `CONCURRENTLY`), kandydaci po bigramach tylko dla fragmentu bez trygramu zawężającego wyszukiwanie, warunek dokładny bez zmian. Semantyka `OrderSearchTerm` i `BackofficeCustomerSearchTerm` bez zmian.",
      "acceptance_criteria": [
        "Kryterium 1 specyfikacji dla zamówień i klientów: `#!#`, `---`, `a.b`, `ab-cd`, `ąęś`, `kowal`, numer `R-…` i pełny e-mail dają te same wyniki co obecny SQL; fragment 2-znakowy dalej daje 400.",
        "Test planu: fragment bez trygramu nie przechodzi całej tabeli.",
        "Kryterium 8: WAL i czas na cykl zamówienia i zapis klienta przed i po w raporcie zadania.",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-7-1"
      ],
      "parallel_with": [
        "PERF-7-6"
      ],
      "paths": [
        "apps/api/src/Ordering/",
        "apps/api/migrations/",
        "apps/api/tests/"
      ]
    },
    {
      "id": "PERF-7-6",
      "title": "Anulowanie nieaktualnych żądań list w panelu",
      "scope": "`useCursorPager`: `AbortController` na żądanie, przerwanie poprzedniego przy nowym zapytaniu, zmianie strony, ponowieniu i odmontowaniu; `PageRequest` z `signal`; każde `fetchPage` (produkty, ceny, stany, wybór wariantów promocji, zamówienia, klienci, zamówienia klienta, kody rabatowe, promocje) przekazuje `signal` do `openapi-fetch`. Przerwanie nie jest błędem. Specyfikacja: „Front panelu”; „Standard listy” w `docs/features/admin-design/spec.md` opisuje zachowanie po zmianie.",
      "acceptance_criteria": [
        "Kryterium 7 specyfikacji: testy pagera (przerwanie poprzedniego, brak komunikatu błędu po przerwaniu, przerwanie przy odmontowaniu, ponowienie po błędzie nadal działa) i test każdej listy, że `signal` trafia do żądania.",
        "Istniejące testy wyścigów pagera (`latest`) przechodzą bez zmian asercji.",
        "`make -C apps/admin check` i `make -C apps/admin test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "parallel_with": [
        "PERF-7-1",
        "PERF-7-2",
        "PERF-7-3",
        "PERF-7-4",
        "PERF-7-5"
      ],
      "paths": [
        "apps/admin/src/",
        "docs/features/admin-design/spec.md"
      ]
    },
    {
      "id": "PERF-7-7",
      "title": "Pomiar po zmianie na `medium` i `server`, raport epiku",
      "scope": "Scenariusze z zadania 1 na tej samej konfiguracji i danych: macierz na `small` i `medium`, W7 mieszanki, P4 z kryterium D7 i poprawionym scenariuszem koszyka, granice mapy strony, koszt zapisu (P8), rozmiary nowych indeksów. Weryfikacja warunkowej akceptacji P8 (D8): W20 (czas fazy produktów generatora przed i po na tej samej maszynie), przepustowość zapisu produktów, WAL, pamięć i odczyty dysku. Pomiar akceptacyjny na wspólnym lokalnym profilu `server` (D9): P1, P2, P3, P4 i czas z P5, także Z0 dla zamówień i klientów (decyzja o PERF-7-5) i kopia kategorii głównej dla działu z frazą częstą (D6); kosztowne pomiary zgrupowane w uzgodnionych oknach. worf.pl tylko za osobną zgodą właściciela. Status PERF-7 w `docs/features/performance-program/plan.md`.",
      "acceptance_criteria": [
        "Kryteria 3, 4, 5, 8 i 11 specyfikacji: raport w `docs/reports/` z tabelą przed i po, rozrzutem trzech przebiegów, poleceniami do powtórzenia i listą niespełnionych albo niepotwierdzonych wymagań z przyczyną (w tym R1–R5 z wynikiem), bez wyjątków od W7 (D6).",
        "Wynik W20, przepustowości zapisu produktów i zasobów z nowymi indeksami (D8) w raporcie; przy przekroczeniu decyzja właściciela z liczbami.",
        "Wymagania wydajnościowe są w raporcie i w planie programu oznaczone jako spełnione wyłącznie na podstawie pomiaru na `server`; bez niego jako niepotwierdzone.",
        "`make check` i testy `apps/api` i `apps/admin` przechodzą na gałęzi epiku."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-7-2",
        "PERF-7-3",
        "PERF-7-4",
        "PERF-7-5",
        "PERF-7-6"
      ],
      "parallel_with": [],
      "paths": [
        "load/",
        "docs/reports/",
        "docs/features/performance-program/plan.md"
      ]
    }
  ]
}
```

## Przegląd całej zmiany

Po fali 4 przegląd w świeżym kontekście: `architecture-reviewer` (zgodność z regułą „najprostszy wariant”, granice
modułu `Catalog`, zmienne kosztu) i `qa-adversarial` (równoważność wyników, kursory między drogami, gwarancje mapy
strony; przy K2 albo K3 także współbieżny zapis produktu a kopia kluczy). `ux-reviewer` tylko dla zmian zadania 6
(brak błędu po przerwaniu).
