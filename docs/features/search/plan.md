# PERF-2 etap B – plan zadań

Specyfikacja: `docs/features/search/spec.md` (decyzje M1–M8, „Weryfikacja wydajności (PERF-2 etap B)”, „Kolejność
zmian i dokumentacja (PERF-2 etap B)”). Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma jedną
gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we własnym
worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją. Zadania nie
mają własnych PR, z jednym wyjątkiem ze specyfikacji („Kolejność zmian”, punkt 1): PERF-2B-1 idzie osobnym PR do
`main`, który merguje właściciel, a gałąź epiku powstaje z `main` po tym merge'u. Zadania jednej fali z listą
`parallel_with` mogą iść naraz. Kroki po merge'u epiku (wdrożenie PERF-2B-1 i modelu, przebudowa na worf.pl, pomiary
z kryterium 13, raport) są w specyfikacji, „Kolejność zmian”, punkt 3, każdy za zgodą właściciela. Pomiary lokalne i na
worf.pl uzgadnia sesja prowadząca z sesją PERF-0. Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | PERF-2B-1, PERF-2B-2, PERF-2B-3 | |
| 2 | PERF-2B-4 | |
| 3 | PERF-2B-5, PERF-2B-6 | |
| 4 | PERF-2B-7 | |
| 5 | PERF-2B-8 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PERF-2B",
  "spec": "docs/features/search/spec.md",
  "tasks": [
    {
      "id": "PERF-2B-1",
      "title": "setup ostrzega przy innym skrócie definicji indeksu (M7)",
      "scope": "Catalog (`catalog:search-index:setup`, `ElasticsearchSearchIndexMaintenance`): spec M7, „Infrastruktura” (zmiana definicji na klastrze, wycofanie), „Scenariusze błędów”. Osobny PR do `main` przed epikiem („Kolejność zmian”, punkt 1), wdrażany na worf.pl przed modelem R. Nie zmienia definicji indeksu ani przebudowy. Dokumentacja: opis `setup` w `docs/features/walking-skeleton/spec.md` i zdanie o `setup` w `docs/features/catalog-admin/spec.md`.",
      "acceptance_criteria": [
        "`catalog:search-index:setup` na istniejącym indeksie z innym `definition_hash` wypisuje ostrzeżenie z instrukcją `catalog:reindex` i kończy się kodem 0; testy tego przypadku, które dziś oczekują odmowy, zmieniają się razem z zachowaniem.",
        "Pozostałe odmowy `setup` bez zmian, w tym przy zajętej blokadzie przebudowy (test integracyjny).",
        "`docs/features/walking-skeleton/spec.md` i `docs/features/catalog-admin/spec.md` opisują ostrzeżenie zamiast błędu."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "apps/api/src/Catalog/Infrastructure/Search/ElasticsearchSearchIndexMaintenance.php",
        "apps/api/src/Catalog/UI/Console/SetupSearchIndexCommand.php",
        "apps/api/src/Catalog/Application/Search/ActivationResult.php",
        "apps/api/tests/Integration/Catalog/Search/SearchIndexSetupTest.php",
        "docs/features/walking-skeleton/spec.md",
        "docs/features/catalog-admin/spec.md"
      ],
      "parallel_with": [
        "PERF-2B-2",
        "PERF-2B-3"
      ]
    },
    {
      "id": "PERF-2B-2",
      "title": "Scenariusz zapisów k6 w load/ ze świeżością i kontrolą zgodności",
      "scope": "`load/`: nowy scenariusz k6 i rozszerzenie `load/lib` (spec „Weryfikacja wydajności”, punkty o zapisach, skutkach i zgodności; kryterium 3 planu programu). Zapisy przez API panelu (`PUT /api/v1/admin/variants/{variantId}/price`, `POST …/stock/receipts`), uwierzytelnienie tokenem odświeżania sesji panelu (publiczny klient `admin`), co zadanie sprawdza najpierw na lokalnym stosie. Uruchamianie przez `load/k6.sh`; bez zmian w `make -C infra/k8s load`, bez nowego konta i klienta Keycloaka. `load/baseline.py` należy do PERF-0 i zostaje bez zmian. Bez uruchomienia na worf.pl.",
      "acceptance_criteria": [
        "Scenariusz wysyła w modelu otwartym zadaną liczbę zmian na sekundę na próbie wariantów z ceną w opublikowanych produktach wybranej na początku przebiegu: zmiany ceny o ±1 gr od wartości z początku przebiegu tylko u wariantów wtedy możliwych do kupienia, przyjęcia stanu u wszystkich wariantów próby; część próby (udział z parametru) to warianty bez dostępnego stanu na początku, a ich brak przy udziale większym od zera kończy przebieg błędem; nigdy nie odbiera dostępności ani nie dotyka rezerwacji; warianty z dwukropkiem w nazwie osi nie wchodzą do próby.",
        "Wynik przebiegu zawiera listę wariantów próby i liczbę wykonanych zmian, także po przerwaniu przebiegu; `load/README.md` opisuje trwałe skutki (historia cen i ruchów, zdarzenia, wersje, warianty bez stanu możliwe do kupienia po przyjęciu) i stan po przerwaniu.",
        "Zapytanie wariantu: `GET /api/v1/products` z pominięciem Varnisha, z kategorią produktu, wartością każdej osi wariantu (`option`) i `priceMin` = `priceMax`, przeglądane do ostatniej strony; produkt jest w wynikach wtedy i tylko wtedy, gdy ten wariant jest w indeksie możliwy do kupienia za tę cenę. Do próby wchodzą tylko warianty, których zapytanie przy każdej możliwej cenie ma `total` w oknie 10 000; produkt nieznaleziony przy `total` ponad oknem to błąd przebiegu.",
        "Świeżość (W16) dla zmian ceny i dostępności: czas od odpowiedzi zapisu do chwili, gdy zapytanie wariantu widzi nowy stan; próbka ceny na osobnych wariantach, na których kolejna zmiana rusza po zobaczeniu poprzedniej (ceny ±1 gr się powtarzają); próbka dostępności z pierwszego przyjęcia u wariantu bez stanu.",
        "Koniec scenariusza sprawdza zgodność: oczekiwany stan każdego wariantu próby liczony z potwierdzonych zapisów (cena ostatnia zapisana albo początkowa, dostępność początkowa albo „tak” po przyjęciu); po ustaniu zapisów kontrola powtarza zapytania wariantów, aż każde zgadza się z oczekiwanym stanem (zaległość kolejki reindeksacji), najdłużej do limitu czasu z parametru; zapytanie z oczekiwaną ceną znajduje produkt wtedy i tylko wtedy, gdy wariant ma być możliwy do kupienia; wariant z zapisem bez odpowiedzi sprawdzany względem każdego stanu, który ten zapis mógł zostawić, a ich liczba jest w wyniku; niezgodność po limicie (wariant, stan oczekiwany i znaleziony) to błąd przebiegu; `load/README.md` podaje sprawdzenie `failed` po przebiegu (`make -C infra/k8s failed`, lokalnie `messenger:failed:show`).",
        "Kontrola zgodności wykrywa nieaktualny indeks: przebieg lokalny z zatrzymanym workerem reindeksacji (zapisy bez reindeksacji) kończy się niezgodnością zarówno dla zmienionej ceny, jak i dla wariantu, który przyjęcie uczyniło dostępnym.",
        "Ścieżki błędu sprawdzone uruchomieniem: brak albo nieważny token, niedostępne API, pusta próba; każda kończy się błędem z komunikatem."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "load/writes.js",
        "load/lib/",
        "load/README.md"
      ],
      "parallel_with": [
        "PERF-2B-1",
        "PERF-2B-3",
        "PERF-2B-4",
        "PERF-2B-5",
        "PERF-2B-6",
        "PERF-2B-7"
      ]
    },
    {
      "id": "PERF-2B-3",
      "title": "Cel infra/k8s czyszczący request cache indeksu produktów",
      "scope": "`infra/k8s`: jeden cel `Makefile` ze skryptem (spec „Weryfikacja wydajności”, „Zimny request cache”). Wywołuje tylko `POST /<alias indeksu produktów>/_cache/clear?request=true` w klastrze przez SSH, jak inne cele z `HOST`. Bez innej infrastruktury pomiarowej. Klasyfikacja celu w `infra/k8s/CLAUDE.md` („Cele Makefile”): tylko po zgodzie użytkownika, bo jest częścią pomiaru na worf.pl, który wymaga zgody.",
      "acceptance_criteria": [
        "Cel czyści wyłącznie request cache indeksu za aliasem produktów, bez zmiany danych i innych pamięci podręcznych; opis celu i kodów wyjścia jest przy celu w `Makefile`.",
        "Ścieżki błędu sprawdzone testem albo uruchomieniem: brak `HOST`, nieosiągalny host, brak aliasu, błąd Elasticsearcha; każda kończy się kodem różnym od 0 z komunikatem.",
        "`infra/k8s/CLAUDE.md` wymienia cel wśród uruchamianych tylko po zgodzie; `make -C infra/k8s check` przechodzi."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "infra/k8s/Makefile",
        "infra/k8s/scripts/search-cache-clear.sh",
        "infra/k8s/tests/test_search_cache_clear.py",
        "infra/k8s/CLAUDE.md"
      ],
      "parallel_with": [
        "PERF-2B-1",
        "PERF-2B-2",
        "PERF-2B-4",
        "PERF-2B-6",
        "PERF-2B-7"
      ]
    },
    {
      "id": "PERF-2B-4",
      "title": "Ustawienia indeksu: 1 shard, sortowanie po sort_path, refresh_interval z parametru (M3, M4)",
      "scope": "Catalog i konfiguracja (spec M3, M4, „Dokument” (`sort_path`), „Zapis i kolejność” (interwał przy przebudowie i `setup`), „Infrastruktura” (`SEARCH_SHARDS`, `SEARCH_REFRESH_INTERVAL`)). `ProductDocument` dostaje `mainCategoryPath` z migawki. Compose i chart w tym samym zadaniu (zmienna środowiska, `elasticsearch.shards` 1 w `prod`, `elasticsearch.refreshInterval` z wartością `5s` w `prod` i komentarzem o warunkowej decyzji i PERF-6). Bez tokenów faset i zapytań.",
      "acceptance_criteria": [
        "Nowy indeks ma 1 shard we wszystkich profilach i sortowanie indeksu po `sort_path` (ścieżka identyfikatorów głównej kategorii, `index: false`); skrót definicji obejmuje sortowanie, a nie obejmuje shardów, replik ani interwału odświeżania.",
        "`SEARCH_REFRESH_INTERVAL` (`app.search.refresh_interval`, domyślnie `1s`): przebudowa tworzy indeks z `-1` i ustawia interwał z parametru w `verify` przed `refresh`; `setup` tworzy indeks z interwałem z parametru i ustawia go na istniejącym indeksie, gdy jest inny, bez przebudowy (kryterium 12).",
        "Wartość spoza formatu liczba z `ms` albo `s` odrzuca kontener i schemat chartu; zmienna jest w compose i w każdym kontenerze z kodem API w chartcie; `make -C infra/k8s check` przechodzi, a test wartości shardów `prod` zmienia się z 4 na 1.",
        "Dokumentacja: `docs/features/walking-skeleton/spec.md` (skrót definicji z sortowaniem, interwał) i `docs/features/deploy/spec.md`, gdzie podaje liczbę shardów."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-2B-1"
      ],
      "paths": [
        "apps/api/config/services.yaml",
        "apps/api/src/Catalog/Application/Search/ProductDocument.php",
        "apps/api/src/Catalog/Application/Search/ProductDocumentBuilder.php",
        "apps/api/src/Catalog/Application/Search/ProductSearchSnapshot.php",
        "apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DbalProductSearchSource.php",
        "apps/api/src/Catalog/Infrastructure/Search/ProductIndexDefinition.php",
        "apps/api/src/Catalog/Infrastructure/Search/ElasticsearchSearchIndexMaintenance.php",
        "apps/api/tests/Integration/Catalog/Search/",
        "apps/api/tests/Integration/Catalog/Persistence/DbalProductSearchSourceTest.php",
        "apps/api/tests/Unit/Catalog/Search/",
        "infra/docker-compose.yml",
        "infra/k8s/charts/shop/templates/api.yaml",
        "infra/k8s/charts/shop/values.yaml",
        "infra/k8s/charts/shop/values.schema.json",
        "infra/k8s/environments/prod/values.yaml",
        "infra/k8s/tests/test_reindex.py",
        "docs/features/walking-skeleton/spec.md",
        "docs/features/deploy/spec.md"
      ],
      "parallel_with": [
        "PERF-2B-2",
        "PERF-2B-3"
      ]
    },
    {
      "id": "PERF-2B-5",
      "title": "Zapisy w trakcie przebudowy przez alias przebudowy (W19a, M5)",
      "scope": "Catalog: przebudowa i `ProductIndexer` (spec M5, „Zapisy w trakcie przebudowy (W19a, M5)”, „Scenariusze błędów”, kryterium 11). Alias `<alias>-rebuild` tworzony z nowym indeksem przed pierwszą paczką i zdejmowany przy przepięciu; zapis pojedynczy jednym `_bulk` do obu aliasów; paczki po nazwie fizycznej. Dokumentacja: „Ograniczenia tej przebudowy” w `docs/features/walking-skeleton/spec.md`, runbook `infra/k8s/README.md` (przebudowa pod ruchem dozwolona, okno serwisowe zmiany definicji, wycofanie) i zdanie o `reindex` w `infra/k8s/CLAUDE.md`.",
      "acceptance_criteria": [
        "Zapis pojedynczy w trakcie wypełniania (przed i po paczce z tym produktem) jest w nowym indeksie po przepięciu aliasu, także gdy paczka dostaje pozycję przestarzałą (409) albo przejściowo odrzuconą (test integracyjny na prawdziwym Elasticsearchu).",
        "Pozycja aliasu przebudowy: 404 `index_not_found_exception` i 409 pomijane; każdy inny błąd kończy zapis błędem przejściowym albo trwałym jak pozycja główna (test).",
        "Przepięcie w trakcie zapisu pojedynczego (spec „Zapis pojedynczy w chwili przepięcia aliasu”): pozycja aliasu przebudowy jest pierwsza w `_bulk`; zadanie potwierdza w kodzie Elasticsearch 9.4.0, że pozycje `_bulk` rozwiązują aliasy w jednym stanie klastra albo w kolejności żądania, i wpisuje źródło do spec (gdy żadne nie zachodzi: stop i pytanie do właściciela); test z przepięciem i usunięciem starego indeksu wstrzykniętym między odczyt bazy a `_bulk`: dokument w nowym indeksie, 404 pozycji głównej ponawia wiadomość bez `failed`; próba współbieżna: zapisy pojedyncze równolegle z kilkoma przebudowami małego indeksu, po każdej każdy produkt ma w aktywnym indeksie ostatnią wersję.",
        "Pozycja przestarzała (409) w paczce przebudowy jest poprawna i nie jest ponawiana; „Zapis paczki” w spec nie traktuje jej już jako błędu przebudowy (test).",
        "Nieudana przebudowa usuwa nowy indeks razem z aliasem przebudowy; kolejna przebudowa najpierw zdejmuje osierocony alias; paczka z 409 nie przerywa przebudowy, pozycja trwale odrzucona kończy ją błędem.",
        "Dokumentacja wymieniona w zakresie opisuje nowe zachowanie; zdanie, że zmiana zapisana w trakcie budowy może nie trafić do nowego indeksu, znika z `infra/k8s/CLAUDE.md`, runbooka i `docs/features/walking-skeleton/spec.md`."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "PERF-2B-4"
      ],
      "paths": [
        "apps/api/src/Catalog/Infrastructure/Search/ElasticsearchSearchIndexMaintenance.php",
        "apps/api/src/Catalog/Infrastructure/Search/ElasticsearchProductIndexer.php",
        "apps/api/src/Catalog/Application/Search/ProductSearchIndexRebuilder.php",
        "apps/api/src/Catalog/Application/Search/BulkResult.php",
        "apps/api/src/Catalog/Application/Search/RebuildReport.php",
        "apps/api/src/Catalog/Application/Port/SearchIndexMaintenance.php",
        "apps/api/src/Catalog/Application/Port/ProductIndexer.php",
        "apps/api/tests/Integration/Catalog/Search/SearchIndexRebuildTest.php",
        "apps/api/tests/Integration/Catalog/Search/SearchIndexMaintenanceTest.php",
        "apps/api/tests/Integration/Catalog/Search/SearchIndexWriteRetryTest.php",
        "apps/api/tests/Integration/Catalog/Search/ScriptedBulkFaultsHttpClient.php",
        "apps/api/tests/Integration/Catalog/Search/ProductIndexerTest.php",
        "apps/api/tests/Integration/Catalog/Search/RebuildAliasWritesTest.php",
        "apps/api/tests/Integration/Catalog/Search/UsesTemporarySearchAlias.php",
        "apps/api/tests/Integration/Shared/Messaging/SearchReindexFlowTest.php",
        "apps/api/tests/Support/InMemory/",
        "docs/features/walking-skeleton/spec.md",
        "infra/k8s/README.md",
        "infra/k8s/CLAUDE.md"
      ],
      "parallel_with": [
        "PERF-2B-2",
        "PERF-2B-6"
      ]
    },
    {
      "id": "PERF-2B-6",
      "title": "Tokeny faset w dokumencie i reguły domeny modelu R (M1)",
      "scope": "Catalog: zapis dokumentu (spec M1, „Dokument”: pola `facets`, `buy_facets`, `pair_any`, `pair_buy`, `purchasable`, `buy_prices`, `max_gross`, `variants.axes`; `ProductFacetTokens`; odmowa budowy dokumentu). Zapytania bez zmian (PERF-2B-7); dotychczasowe pola zostają, więc listing działa jak dziś. Testy reguł domeny, na których stoi model (`VariantAxes`, `ProductVariants`, `Text`).",
      "acceptance_criteria": [
        "Dokument ma pola i tokeny z tabeli „Dokument” w składni ze specyfikacji (`#`, `@`, `<oś>␟<wartość>`, `$`, pary z U+001E); produkt bez osi nie ma tokenów `@` ani opcji; `buy_facets` i `pair_buy` tylko z wariantów możliwych do kupienia; szkic bez cen.",
        "Budowa dokumentu kończy się `SearchIndexError` z identyfikatorem produktu przy wariancie z więcej niż 2 opcjami, powtórzonej krotce opcji albo U+001E/U+001F w osi lub wartości (test jednostkowy).",
        "Testy reguł domeny: `VariantAxes` odrzuca trzecią oś, `ProductVariants` powtórzoną krotkę (także różniącą się wielkością liter), `Text` znaki U+001E i U+001F.",
        "Tokeny i ich składnię buduje tylko `ProductFacetTokens`; ucieczka znaków dla zapytań z prefiksem obejmuje każdy znak poza literą i cyfrą (test z `#`, `@`, `$`, `.`, `*`, `(`, `\\`)."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-2B-4"
      ],
      "paths": [
        "apps/api/src/Catalog/Infrastructure/Search/ProductIndexDefinition.php",
        "apps/api/src/Catalog/Infrastructure/Search/ProductFacetTokens.php",
        "apps/api/src/Catalog/Application/Search/ProductDocumentBuilder.php",
        "apps/api/src/Catalog/Application/Search/VariantDocument.php",
        "apps/api/tests/Unit/Catalog/Search/",
        "apps/api/tests/Unit/Catalog/Domain/VariantAxesTest.php",
        "apps/api/tests/Unit/Catalog/Domain/ProductVariantsTest.php",
        "apps/api/tests/Unit/Shared/Domain/TextTest.php",
        "apps/api/tests/Integration/Catalog/Search/ProductIndexDefinitionTest.php",
        "apps/api/tests/Integration/Catalog/Search/ProductIndexingTest.php"
      ],
      "parallel_with": [
        "PERF-2B-2",
        "PERF-2B-3",
        "PERF-2B-5"
      ]
    },
    {
      "id": "PERF-2B-7",
      "title": "Zapytania i fasety modelu R w jednym _msearch z wyrocznią zgodności (M1, M2, M6)",
      "scope": "Catalog: `ProductSearchQuery`, `ElasticsearchProductSearch` (spec „Zapytanie”, „Fasety”, M2, M6, „Scenariusze błędów” o `_msearch` i rozmiarze `terms`, drugi krok faset przy przepełnieniu). Odpowiedź `GET /api/v1/products`, OpenAPI i sklep bez zmian. Wyrocznia w PHP na ręcznie zbudowanym katalogu przypadków brzegowych (spec „Testy”, „Zgodność modelu R”). Pomiar `took` pod `SEARCH_PERF=1` zostaje poza `make test`.",
      "acceptance_criteria": [
        "Listing to jedno `_msearch`: wyniki strony i grupy faset według zestawu warunków (`size: 0`), zakres cen bez warunków osi dwoma wyszukiwaniami „top 1”; błąd którejkolwiek części daje 503 albo 500 całej odpowiedzi; grupa z `sum_other_doc_count` > 0 jest liczona dokładnie drugim `_msearch` (osie z rozmiarem 10, wartości każdej osi z prefiksem i rozmiarem 30, kategorie i `$` przez `include`), nigdy błąd ani ucięcie.",
        "Warunek wariantu na polach produktu według reguł spec (bez osi, jedna oś, dwie osie, trzy i więcej: brak wyników; cena przez `buy_prices` i z osią `nested` na `variants`).",
        "Wyrocznia (kryterium 10, część pierwsza): na katalogu ze wszystkimi przypadkami brzegowymi ze spec „Testy” i wyczerpującym zestawie kryteriów (podzbiory osi 0–3 z jedną i dwiema wartościami, z ceną i bez, z dostępnością i bez, z kategorią i bez, z frazą i bez) cała odpowiedź (liczba trafień, fasety z liczbami, zakres cen, pierwsza strona) równa się modelowi w PHP, także po zmianie opcji, ceny i dostępności istniejącego produktu; cały zestaw drugi raz z rozmiarem `terms` zmniejszonym parametrem budowy zapytania tak, że każda grupa przechodzi drugi krok, z tym samym wynikiem.",
        "Istniejące testy listy, faset i endpointu przechodzą bez zmiany oczekiwanych wyników; zmieniają się tylko testy, które sprawdzają kształt żądania do Elasticsearcha."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-2B-6"
      ],
      "paths": [
        "apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php",
        "apps/api/src/Catalog/Infrastructure/Search/ElasticsearchProductSearch.php",
        "apps/api/tests/Integration/Catalog/Search/",
        "apps/api/tests/Functional/Catalog/ProductsEndpointTest.php"
      ],
      "parallel_with": [
        "PERF-2B-2",
        "PERF-2B-3"
      ]
    },
    {
      "id": "PERF-2B-8",
      "title": "Weryfikacja lokalna na medium: porównanie przed i po, przebieg zapisów, Status",
      "scope": "Zadanie zamykające epik: weryfikuje stan gałęzi epiku ze wszystkimi zadaniami PERF-2B-1…7 (bezpośrednio zależy od 2B-2, 2B-3, 2B-5 i 2B-7, a przez nie od pozostałych), bo porównanie biegnie po przebudowie z W19a, przebieg zapisów przechodzi przez indeksowanie z podwójnym zapisem, a „Status” ogłasza gotowość do pomiaru na worf.pl, który wymaga celu z 2B-3. Kryterium 10 (część druga) i lokalna próba narzędzi pomiaru (spec „Testy”, „Porównanie przed i po na medium”; „Weryfikacja wydajności”: najpierw lokalnie). Skrypt porównania w katalogu raportu etapu B w `docs/reports/` (bez nowego narzędzia w `load/`; `.git/info/exclude` ukrywa `reports/`, więc nowe pliki dodaje się `git add -f`). Lokalny stos `medium` uzgadniany z sesją PERF-0; generowanie danych `medium`, jeśli potrzebne, tylko po zgodzie właściciela. Aktualizacja „Statusu” PERF-2 w `docs/features/performance-program/plan.md`. Bez pomiarów na worf.pl.",
      "acceptance_criteria": [
        "Weryfikacja biegnie na rewizji gałęzi epiku zawierającej zmergowane PERF-2B-1…PERF-2B-7; rewizja jest w raporcie.",
        "Odpowiedzi `GET /api/v1/products` dla mieszanki adresów `load/baseline.py urls` zapisane na rewizji `main` sprzed epiku i po przebudowie na rewizji epiku, na tym samym stanie danych i przy 1 shardzie: 0 różnic (liczba trafień, fasety, zakres cen, pierwsza strona); wynik z listą adresów i rewizjami w raporcie.",
        "Przebieg scenariusza zapisów (PERF-2B-2) na lokalnym stosie kończy się zgodnością próby i 0 wiadomości w `failed`; wynik podaje stan request cache i cache plików systemu osobno.",
        "„Status” PERF-2 w `docs/features/performance-program/plan.md` mówi, że etap B jest zaimplementowany i czeka na pomiar na worf.pl."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PERF-2B-2",
        "PERF-2B-3",
        "PERF-2B-5",
        "PERF-2B-7"
      ],
      "paths": [
        "docs/reports/",
        "docs/features/performance-program/plan.md"
      ],
      "parallel_with": []
    }
  ]
}
```
