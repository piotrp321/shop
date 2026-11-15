**Werdykt: passed.** Review DELTA taska SEARCH-4 na HEAD f49e38277ef1a7b4620bafbaeadde76d8737c035 (checkout `<home>/Development/claude/shop-SEARCH-4`, gałąź task/SEARCH-4, drzewo czyste, SHA się nie zmienił). Zakres: `git diff caa9b76..f49e382` oraz rozwiązanie konfliktów w merge'u caa9b76 (`git show --remerge-diff`). Nie mam ustaleń Critical ani Important. Są 3 ustalenia Minor.

Nic nie uruchamiałem: żadnych testów, `check` ani poleceń w Dockerze, bo `make test` odtwarza indeks testowy. Używałem tylko odczytu przez `git` i `gh`. Wyniki `check`, testów i openapi biorę z raportu orchestratora. Moje ustalenia pochodzą z analizy kodu, dziennika dowodów i PR.

## Trzy kontrole

**1. Istniejące testy: bez uwag.**
- Zmienione testy z bazy dostosowują się tylko do usunięcia `variants.axes` z dokumentu. Jest to konsekwencja decyzji użytkownika o mappingu.
- Pliki: `ProductIndexDefinitionTest`, `ProductIndexingTest` (Integration i Unit), `SearchIndexRebuildTest`, `SearchReindexFlowTest`, `IndexProductTest`, `ProductDocumentBuilderTest`, `ProductSearchIndexRebuilderTest`, `ProductSearchQueryTest`, `ProductsEndpointTest`.
- Oczekiwane źródła dokumentu zamiast `axes` mają teraz `pairs`.
- Żaden test nie został usunięty, pominięty ani osłabiony.
- Testy z bazy dotyczące granic dalej przechodzą: `testOptionWithRegexCharactersIsAPlainValue`, `testNumericAxisAndValueNamesWork`, `testSelectedAxesAreListedEvenBeyondTheTenthAxis`, `testSelectedValueBeyondTheThirtiethStillHasItsRealCount`.
- f49e382 nadaje kategorii OTHER stałą nazwę „Rower” (`ProductsEndpointTest.php:61`). Naprawia to niestabilną kolejność korzeni i nie osłabia asercji.

**2. Zakres: bez uwag.**
- Zmiany obejmują Catalog (Application/Infrastructure Search), testy api, `docs/features/search/spec.md`, `apps/api/CLAUDE.md` i `docs/features/walking-skeleton/spec.md`.
- Walking-skeleton spec jest poza listą „Docs” taska. Ma jednak podstawę: zmienił się mapping, a spec (linia 314) wymienia ten dokument jako opis mappingu.
- Merge caa9b76: według remerge-diff w 6 plikach testów sklepu doszło tylko `facets: EMPTY_FACETS` i import `EMPTY_FACETS`. Konflikty rozwiązano wersją z epiku, bez innych zmian.

**3. RED przed implementacją: bez nowego zgłoszenia.**
- `tdd.status: lost_verified_on_base`.
- W tej rundzie RED `ProductSearchFacetsTest` jest z 21:30:34 i 21:30:45 na head caa9b76. W `dirty` były tylko dwa nowe pliki testów, więc pliki produkcyjne były czyste. Wynik: 15 testów, 10 i 9 failures na asercjach.
- GREEN z 21:32:21 na tym samym teście: OK (15 testów, 236 asercji). RED jest więc prawdziwy.
- `ProductIndexDefinitionTest::testPairsOfAVariantAreMatchedTogetherNotAcrossVariants` nie ma RED. To test mappingu, czyli infrastruktury, więc według CLAUDE.md RED nie jest wymagany. Ujawnienie musi jednak trafić do opisu PR (patrz M2).

## Sprawdzone bez uwag

**Agregacja osi bez wyboru** (`ProductSearchQuery.php:136-151`)
- Struktura: `nested variants` → filtr wszystkich warunków → `nested variants.pairs` → `terms axis` (10) z `reverse_nested` i `terms value` (30).
- Oba poziomy mają `order` `[product desc, _key asc]`.
- `reverse_nested {}` wraca do korzenia, więc produkt liczy się raz.
- Warunki i para należą do tego samego dokumentu wariantu, więc nie ma kombinacji z różnych wariantów.
- `exclude` to tablica dokładnych nazw (nie regex), więc znaki specjalne i oś numeryczna (strval) są bezpieczne.
- Usunięty `axisPrefixRegex` nie ma już odpowiednika do psucia.

**Oś wybrana** (`ProductSearchQuery.php:153-175`)
- Warunki bez własnej osi → `nested pairs` → `filter term axis` → `top` (30, ten sam order) + `chosen` (`include` z dokładnymi wartościami).
- Filtr `axis` jest konieczny, bo `value` jest wspólne dla osi. Jest na miejscu.

**Odczyt** (`ElasticsearchProductSearch.php:82-133`)
- Oś wybrana dostaje liczbę `total`.
- Wybrana wartość bez produktów dostaje `??= 0`.
- Kolejność osi: liczba produktów malejąco, potem `strcmp`. To porządek bajtowy jak `_key` ES, więc cięcie w ES i ponowne sortowanie w PHP są spójne.
- Wybrana wartość ucięta limitem dochodzi po 30. Spec na to pozwala, lista może przekroczyć 30.
- `total` = 0: oś wybrana zostaje (scenariusz `absent axis`).
- Klucze numeryczne tablic (oś „2024”, wartość „42”) wracają przez `strval` / `(string)`, a dostęp `$axes["42"]` działa.
- Wybranych osi jest najwyżej 5 (walidacja), więc lista ma najwyżej 15 osi. Zgadza się to ze spec („ponad limit 10”) i z opisem OpenAPI.

**Model brute-force** (`FacetFixtures::expected`)
- Liczy wszystko w PHP po wariantach (`matches`), bez kopiowania struktury zapytania ES.
- Ze spec odtwarza tylko reguły wyniku: 10 osi, 30 wartości, dopisanie uciętej wybranej wartości, kolejność.
- `manyAxes` (480 par + 80 rzadkich wartości „Alfa”) odtwarza dawny błąd limitu 400, co potwierdza RED.
- Brak kombinacji z różnych wariantów i kilka osi z ceną i dostępnością są pokryte.

**Mapping i dokument**
- `pairs` to `nested` w `variants`, z `axis` i `value` jako keyword.
- `source()` buduje `pairs` z `options`. Brak separatora daje `SearchIndexError` przy zapisie.
- `document()` nie czyta `pairs`, co spec opisuje.
- Po `variants.axes` nie zostało nic w `src`, testach ani dokumentach (`git grep`).
- Zmiana definicji zmienia `definition_hash`, więc obowiązuje istniejący mechanizm `catalog:reindex` z przełączeniem aliasu.

**Dokumentacja**
- Spec, `apps/api/CLAUDE.md` i walking-skeleton opisują stan obecny, bez historii zmian.
- Nie ma już wzmianki o `MAX_FREE_VALUES` ani o 400.
- Ryzyka (rozmiar indeksu, dokładność przy jednym shardzie) są dopisane. `app.search.shards: 1` w `config/services.yaml:8` potwierdza założenie.

**Test wydajności**
- `markTestSkipped` bez `SEARCH_PERF` daje 1 skip i nie wydłuża `make test` ani CI.

## Ustalenia

**M1 (Minor): sortowanie osi po `reverse_nested` zamiast `doc_count` nie ma testu, który by je rozróżniał.**
- Miejsce: `apps/api/tests/Integration/Catalog/Search/FacetFixtures.php:65-78` i `ProductSearchQuery.php:23`.
- W `manyAxes` każda oś ma tyle samo dokumentów par co produktów (80 = 80).
- W `random()` jest tylko 10 osi, więc limit osi nigdy nie tnie.
- Gdyby `BY_PRODUCTS` na poziomie osi sortował po `_count` (liczba par), cięcie do 10 osi wybierałoby źle. Przykład: oś z jednym produktem i 50 wariantami wygrałaby z osią na 40 produktach po jednym wariancie. Testy prawdopodobnie by tego nie wykryły.
- Wniosek ze statycznej analizy, nie sprawdzałem mutacją.
- Propozycja: dodać do `manyAxes` oś z mniejszą liczbą produktów i wieloma wariantami na produkt. Jeśli `random()` przypadkiem nie trafia w remisy na granicy 30, przyda się też podobny przypadek dla wartości.

**M2 (Minor): brak zapisanego wyniku pomiaru wydajności, a opis PR jest nieaktualny.**
- Decyzja użytkownika wymaga weryfikacji wydajności. Dziennik dowodów nie ma żadnego uruchomienia `SEARCH_PERF` (grep: 0).
- Opis PR #184 nadal mówi, że punkt I2 (limit 400) czeka na decyzję użytkownika.
- Opis PR nie wspomina też:
  - nowego mappingu `pairs`,
  - `ProductSearchFacetsTest`,
  - testu mappingu bez RED,
  - liczb z pomiaru (`took` i liczba dokumentów Lucene).
- Spec podaje tylko „ok. 2,4 raza” więcej dokumentów.
- Do uzupełnienia przed zamknięciem taska: wynik pomiaru (komenda i mediany `took`) w raporcie i PR oraz ujawnienie testu bez RED.

**M3 (Minor, sugestia): instrukcja uruchomienia pomiaru omija Makefile.**
- Miejsce: `apps/api/tests/Integration/Catalog/Search/SearchFacetsPerformanceTest.php:18`.
- Docblock każe uruchamiać `docker compose exec -e SEARCH_PERF=1 api vendor/bin/phpunit ...` bezpośrednio, a CLAUDE.md wymaga komend przez Makefile. Obecny `make -C apps/api test` nie przekazuje zmiennych środowiska do kontenera.
- To narzędzie do ręcznego pomiaru, więc wpływ jest mały. Można rozważyć cel Makefile albo przekazanie `SEARCH_PERF` przez `PHPUNIT_ARGS` lub env.
- Drobiazg: `SEARCH_PERF=0` też uruchamia pomiar (`false === getenv`).

## Najważniejsze pliki
- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/Infrastructure/Search/ProductSearchQuery.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/Infrastructure/Search/ElasticsearchProductSearch.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/src/Catalog/Infrastructure/Search/ProductIndexDefinition.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/tests/Integration/Catalog/Search/FacetFixtures.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/tests/Integration/Catalog/Search/ProductSearchFacetsTest.php`
- `<home>/Development/claude/shop-SEARCH-4/apps/api/tests/Integration/Catalog/Search/SearchFacetsPerformanceTest.php`
- `<home>/Development/claude/shop-SEARCH-4/docs/features/search/spec.md`
- `<home>/.local/state/ao-search/tasks/SEARCH-4/delivery-state.json.evidence.jsonl`