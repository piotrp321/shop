# SEARCH: wyszukiwarka i listing z filtrami

Punkt 4 „Zakresu MVP” z `docs/vision.md`: wyszukiwarka i listing kategorii z filtrami na Elasticsearch, mega menu
dla wielu działów, breadcrumby i SEO listingu.

Issues: #94. Model indeksu i zapytań: PERF-2 etap B programu wydajności
(`docs/features/performance-program/plan.md`, decyzje właściciela z 2026-11-26; pomiary modelu:
`docs/reports/perf-2-search-model-2026-11-25.md`), decyzje M1–M8.
Zakres: `apps/api/src/Catalog`, `infra/k8s/charts/shop`, `infra/k8s/environments`, `infra/k8s/README.md`, `load`, `apps/api/src/Cart/Infrastructure`, `apps/api/src/Pricing/Infrastructure`, `apps/api/src/Inventory/Infrastructure`, `apps/api/tests`, `apps/api/config`, `apps/api/fixtures/catalog.yaml`, `apps/api/openapi.json`, `apps/api/openapi-admin.json`, `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts`, `apps/store/src/catalog`, `apps/store/src/components/ui`, `apps/store/app`, `infra/docker-compose.yml`, `infra/elasticsearch`, `Makefile`, `.github/workflows/ci.yml`, `e2e/tests/catalog.spec.ts`, `e2e/tests/price-change.spec.ts`, `e2e/tests/stock-receipt.spec.ts`, `e2e/tests/cart-variants.spec.ts`, `e2e/tests/product-page.spec.ts`, `e2e/tests/search.spec.ts`, `e2e/tests/listing-filters.spec.ts`, `e2e/tests/site-header.spec.ts`, `e2e/support`, `docs/vision.md`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `docs/features/walking-skeleton/spec.md`, `docs/features/catalog-page-metadata/spec.md`, `docs/features/catalog-unknown-category/spec.md`, `docs/features/product-page/spec.md`, `docs/features/variants/spec.md`

Funkcja opiera się na stronie produktu (`docs/features/product-page/spec.md`): zdarzeniach `VariantPriceChanged`
i `StockLevelChanged`, Varnishu przed odczytami katalogu (`API_CATALOG_URL`), tagach `catalog` i `product-<id>`,
`storefront:cache:purge-all` na końcu `make seed` oraz stronie `/p/<slug>` z `breadcrumb`, do której prowadzi kafelek.

## Cel i zakres

- Wyszukiwanie pełnotekstowe produktów po polsku (odmiana, brak polskich znaków, literówki) na stronie `/szukaj`,
  z polem wyszukiwania w nagłówku każdej strony.
- Listing z filtrami i sortowaniem na `/` (wszystkie produkty), `/c/<ścieżka>` (kategoria) i `/szukaj`: podkategorie,
  wartości osi wariantów (np. Kolor, Rozmiar), zakres ceny, „Tylko dostępne”; liczby produktów przy wartościach.
- Indeks Elasticsearch zawiera ceny i dostępność wariantów, aktualizowane po zdarzeniach z Pricing i Inventory.
- Adresy kategorii `/c/<ścieżka slugów>` (slug kategorii w Catalog), przekierowanie starych `/?category=<id>`.
- Mega menu działów w nagłówku, breadcrumby na listingu (z JSON-LD `BreadcrumbList`), metadane, adresy kanoniczne
  i `noindex` dla kombinacji filtrów.

Poza zakresem:
- docelowy wygląd listingu i menu (wygląd sklepu: `docs/features/visual-design/spec.md`);
- podpowiedzi przy wpisywaniu (autocomplete), „czy chodziło ci o”, historia wyszukiwań, wyszukiwanie po kodach;
- marka jako osobne pole i filtr marki (Catalog nie ma pola marki; marka jest w nazwie produktu i działa w wyszukiwaniu);
- sortowanie po popularności i nowościach (brak danych);
- `sitemap.xml`, `robots.txt`, JSON-LD `ItemList` listingu;
- ISR stron listingu (lista renderuje się przy każdym żądaniu i czyta API przez Varnisha, S4);
- edycja kategorii i przekierowania po zmianie sluga kategorii (kategorie powstają tylko w danych demo; CATALOG-ADMIN #95);
- treść marketingowa strony głównej poza hero i kafelkami działów (hero i kafelki działów w stanie domyślnym `/`: `docs/features/visual-design/spec.md`, „Strona główna”).

## Decyzje

| # | Decyzja |
|---|---|
| S1 | Filtry: podkategorie, wartości osi wariantów (każda oś z wyników osobno), zakres ceny brutto (od–do, pełne złote), „Tylko dostępne”. Bez filtra marki. |
| S2 | Wartości różnych osi muszą należeć do jednego wariantu („czarne” i „42” = jest wariant czarny w rozmiarze 42); kilka wartości jednej osi to „lub”. „Tylko dostępne” i zakres ceny dotyczą tego samego wariantu i tylko wariantów możliwych do kupienia (cena, jaką pokazuje wybór wariantu na kafelku). Liczba przy wartości filtra to liczba wyników po jej dodaniu (przy osi z wyborem: po wybraniu tylko tej wartości tej osi). |
| S3 | Sortowanie: „Trafność” (domyślne przy wyszukiwaniu), „Nazwa A–Z” (domyślne na listingu), „Cena rosnąco”, „Cena malejąco” (po cenie „od” z kafelka, bez względu na filtry; produkty bez ceny na końcu). |
| S4 | Strony listingu renderowane przy każdym żądaniu (SSR) z odczytem przez Varnisha, bez ISR (filtry w parametrach URL). |
| S5 | SEO: indeksowane są tylko `/`, `/c/<ścieżka>` i ich kolejne strony bez filtrów, sortowania i frazy; każda inna kombinacja i cała `/szukaj` ma `noindex, follow` i kanoniczny adres listingu bez filtrów. |
| S6 | Adres kategorii to ścieżka slugów od korzenia: `/c/turystyka/biwak/namioty`. Slug jest unikalny wśród rodzeństwa, nie globalnie. `/?category=<id>` przekierowuje trwale na `/c/<ścieżka>`. |
| S7 | Mega menu: na szerokim ekranie pasek działów (kategorie główne), panel działu z kategoriami 2. poziomu i ich dziećmi (3. poziom) oraz linkiem „Wszystko z działu”; na wąskim ekranie przycisk „Menu” z rozwijaną listą trzech poziomów. Wszystkie linki są w HTML. Głębsze poziomy przez podkategorie na stronie kategorii. |
| S8 | Wyszukiwanie: fraza 2–100 znaków; szuka w nazwie (z marką), nazwach kategorii produktu i krótkim opisie; wszystkie słowa muszą pasować; tolerancja literówek; fraza krótsza niż 2 znaki: komunikat bez wyników. |
| S9 | Pola wyboru (osie, „Tylko dostępne”) i sortowanie działają od razu po zmianie; cena po Enter albo opuszczeniu pola; aktywne filtry jako lista do usunięcia pojedynczo i „Wyczyść filtry”; bez JavaScriptu formularz z przyciskiem „Pokaż wyniki”. |
| S10 | Drzewo kategorii do menu nie daje się pobrać: nagłówek bez menu (logo, wyszukiwarka, koszyk, konto), wpis w logu serwera, reszta strony działa (koszyk nie zależy od katalogu). Strona produktu w ISR może zostać zapisana bez menu do następnej rewalidacji. |
| S11 | Świeżość list: nowy produkt w wynikach albo zmiana kolejności i przynależności do filtrów po zmianie ceny lub stanu jest widoczna najpóźniej po 60 s (TTL odpowiedzi listy w Varnishu), bez szerokiego tagu list czyszczonego po każdej aktualizacji indeksu. Strona produktu jest świeża od razu (PRODUCT-PAGE). |
| A1 | Ceny i dostępność w indeksie: Catalog dostaje port `ProductOfferFacts`, którego adapter jest w `Cart_Infrastructure` i woła Cart `GetProductOffers` (ta sama reguła „możliwy do kupienia” i cena „od” co na kafelku). Catalog nie zależy od Pricing, Inventory ani Cart. |
| A2 | Polska analiza tekstu przez oficjalną wtyczkę Elasticsearch `analysis-stempel`: własny obraz `infra/elasticsearch/Dockerfile` (`FROM` oficjalnego obrazu + instalacja wtyczki). |
| A3 | Kategoria dostaje slug w domenie Catalog (`CategorySlug`, unikalny wśród rodzeństwa); ścieżkę slugów liczy odczyt drzewa, bez kolumny w bazie. Nazwa osi wariantu nie może zawierać `:` (reguła `VariantAxes`, potrzebna formatowi filtra). |
| M1 | Model R: warunki i fasety liczone z pól produktu (tokeny), `nested` jednego poziomu tylko tam, gdzie cena musi należeć do tego samego wariantu co opcja. Równoważność z warunkiem „ten sam wariant” (S2) wynika z dwóch reguł domeny: wariant ma najwyżej 2 osie (`VariantAxes::MAX_AXES`), a krotki opcji wariantów jednego produktu są unikalne (`ProductVariants`, `DuplicateVariantValues`). Budowa dokumentu odrzuca produkt, który ich nie spełnia (`SearchIndexError`), zamiast liczyć go błędnie. |
| M2 | Listing to jedno żądanie `_msearch`: wyniki strony oraz grupy agregacji pogrupowane według zestawu warunków (każda grupa `size: 0` z warunkami w `query`), zakres cen bez warunków opcji dwoma wyszukiwaniami „top 1”. Listing z frazą liczy dopasowanie tekstu raz (jedno żądanie). Błąd którejkolwiek części `_msearch` to błąd całej odpowiedzi (jak dziś: 503 albo 500), nigdy częściowe fasety. Odpowiedź `GET /api/v1/products` i sklep bez zmian. |
| M3 | Indeks ma 1 shard (`SEARCH_SHARDS` = 1 we wszystkich profilach) i sortowanie indeksu po `sort_path` (ścieżka głównej kategorii), więc produkty jednej kategorii leżą obok siebie. |
| M4 | `refresh_interval` z parametru `SEARCH_REFRESH_INTERVAL` (domyślnie `1s`; w `prod` `5s` warunkowo, do potwierdzenia pomiarem W16 razem z PERF-6). Ustawienie dynamiczne: `catalog:search-index:setup` nadaje je istniejącemu indeksowi, bez przebudowy. Nie wchodzi do skrótu definicji. |
| M5 | Przebudowa nie gubi zapisów (W19a): na czas przebudowy nowy indeks ma drugi alias `<alias>-rebuild`, a każdy pojedynczy zapis idzie jednym `_bulk` do obu aliasów z wersją `external_gte`. |
| M6 | Fasety korzystają z natywnego request cache Elasticsearcha (żądania `size: 0`, unieważniane przy odświeżeniu); bez cache aplikacyjnego i bez materializowanych liczników faset (decyzja o licznikach po PERF-0). |
| M7 | Zmiana definicji indeksu nie blokuje wdrożenia: `catalog:search-index:setup` przy innym `definition_hash` wypisuje ostrzeżenie z instrukcją `catalog:reindex` i kończy się powodzeniem. Pozostałe odmowy `setup` bez zmian, w tym przy zajętej blokadzie przebudowy. Na etapie demo przejście na nową definicję to okno serwisowe: wdrożenie, potem przebudowa; do jej końca listing nie działa poprawnie. Bez zgodności starej i nowej wersji i bez mechanizmu migracji. |
| M8 | Wymagania W8 i W9 bez zmian. Etap B nie buduje materializowanych faset; gdy pomiar na worf.pl pokaże, że model ich nie spełnia, etap kończy się raportem z wariantami i decyzją właściciela. |

## Przepływ danych

```
sklep (SSR /, /c/<ścieżka>, /szukaj) → Varnish (API_CATALOG_URL) → GET /api/v1/categories, GET /api/v1/products
                                                                    (q, filtry, sort, fasety) → Elasticsearch
ProductCreated, ProductChanged ─────────────→ Catalog IndexProduct ──┐
VariantPriceChanged → Pricing_Infrastructure ┬→ Catalog ProductIndexing::reindex ─→ dokument: Catalog (DBAL)
StockLevelChanged  → Inventory_Infrastructure┘                        + ProductOfferFacts (adapter Cart → GetProductOffers)
make seed: catalog → pricing → ordering → inventory → catalog:reindex → storefront:cache:purge-all
```

## Catalog: slug kategorii (A3, S6)

- `CategorySlug` (`Catalog_Domain`): format jak `Slug` produktu (małe litery `a-z`, cyfry, pojedyncze myślniki, bez
  myślnika na początku i końcu), 1–60 znaków. `Category::createRoot`, `createChild` i `reconstitute` przyjmują slug.
- Unikalność wśród rodzeństwa pilnuje handler `CreateCategory` (`CategorySlugTaken`, `Conflict`) i unikalne indeksy
  w bazie: `(parent_id, slug)` oraz częściowy `(slug) WHERE parent_id IS NULL`. Unikalność ścieżki wynika z nich.
- Kolumna `catalog_category.slug` jest `NOT NULL`; migracja wypełnia istniejące wiersze identyfikatorem kategorii
  (UUID spełnia format i jest unikalny). Dane demo (`fixtures/catalog.yaml`) mają `slug` w każdym węźle, a loader
  demo przekazuje go do `CreateCategory`.
- `CategoryNode` ma `slug` i `path` (slugi od korzenia połączone `/`, liczone w rekursji `GetCategoryTreeHandler`).
  Ten sam schemat zwracają `GET /api/v1/categories` (sklep, `openapi.json`) i `GET /api/v1/admin/categories`
  (panel, `openapi-admin.json`).
- `GET /api/v1/products/{slug}` (PRODUCT-PAGE): każdy element `breadcrumb` ma `path` (z `CategoryPath` i slugów
  przodków).
- Sklep rozwiązuje `/c/<ścieżka>` po drzewie z `GET /api/v1/categories`.

## Catalog: nazwa osi bez dwukropka (A3)

- `VariantAxes::fromList` odrzuca nazwę zawierającą `:` (`InvalidVariantAxes`), bo filtr osi w API ma format
  `<oś>:<wartość>`. Dane demo nie mają takich nazw.

## Catalog: indeks wyszukiwania

### Dokument

Pola dokumentu istotne dla wyszukiwania i filtrów (`ProductIndexDefinition`, `dynamic: strict`):

| Pole | Typ | Treść |
|---|---|---|
| `name` | `text` z analizatorem `polish_text`, podpola `name.sort` (`keyword` z normalizerem: małe litery, `asciifolding`) i `name.folded` | nazwa |
| `category_names` | `text` z `polish_text`, podpole `folded` | nazwy kategorii produktu i ich przodków |
| `short_description` | `text` z `polish_text`, podpole `folded` | krótki opis |
| `variants` | `nested` (jeden poziom) | jeden obiekt na wariant: `gross` (`long`, brak przy braku ceny), `purchasable` (`boolean`), `options` (`keyword`, pary `<oś>␟<wartość>`, separator U+001F), `axes` (`keyword`, `index: false`, osie wariantu). Czytane tylko przez warunki i agregacje, w których cena musi należeć do tego samego wariantu co opcja, i przez fasetę osi z kilkoma wartościami jednej osi |
| `facets` | `keyword` | tokeny produktu do warunków i faset jednym przejściem: `#<id>` każdej kategorii z `category_ancestor_ids`, `@<oś>` każdej osi wariantów, `<oś>␟<wartość>` każdej opcji wariantów, `$`, gdy produkt ma wariant możliwy do kupienia |
| `buy_facets` | `keyword` | to samo z samych wariantów możliwych do kupienia (bez `$`); pusta lista bez takiego wariantu |
| `pair_any`, `pair_buy` | `keyword` | dla wariantu z opcjami x i y (dwie osie) tokeny `x␞y`, `y␞x`, `x␞@<oś y>`, `y␞@<oś x>` (separator U+001E); `pair_buy` tylko z wariantów możliwych do kupienia |
| `purchasable` | `boolean` | produkt ma wariant możliwy do kupienia |
| `buy_prices` | `long`, bez `doc_values` | różne ceny wariantów możliwych do kupienia; tylko filtr zakresu ceny |
| `from_gross` | `long` | cena „od” (najniższa cena wariantów możliwych do kupienia); brak, gdy produkt nie ma takiego wariantu |
| `max_gross` | `long` | najwyższa cena wariantów możliwych do kupienia; brak jak wyżej |
| `sort_path` | `keyword`, nieindeksowane (`index: false`) | identyfikatory ścieżki głównej kategorii od korzenia połączone `/`; klucz sortowania indeksu (M3) |
| `status` | `keyword` | `published` albo `draft`; każde zapytanie sklepu dopasowuje tylko `published` |

Pozostałe pola (`id`, `version`, `slug`, `main_category_id`, `category_ids`, `category_ancestor_ids` z filtrem
kategorii, `main_image`) służą do filtra kategorii i do wyświetlenia listy.

- Tokeny i ich składnię buduje jedna klasa w `Catalog_Infrastructure` (`ProductFacetTokens`), z której korzystają
  zapis dokumentu (`ProductIndexDefinition::source`) i zapytania; `ProductDocument` dostaje ścieżkę głównej kategorii
  (`mainCategoryPath`) z migawki. Odczyt tokenu: zawiera U+001F → opcja; inaczej `#` → kategoria, `@` → oś, `$` →
  dostępność. Oś i wartość nie zawierają znaków sterujących (reguła tekstów `Text::hasForbiddenCharacters`, w tym
  U+001E i U+001F), więc separatory są jednoznaczne; `#`, `@`, `$` i znaki wyrażeń regularnych w nazwach osi
  i wartościach są dozwolone, a zapytania z prefiksem (`include` w `terms`) ucieczkują każdy znak poza literą i cyfrą.
- Budowa dokumentu kończy się `SearchIndexError` z identyfikatorem produktu (przebudowa kończy się błędem, alias
  zostaje na starym indeksie; wiadomość reindeksacji trafia do `failed`), gdy wariant ma więcej niż 2 opcje, dwa warianty produktu mają tę samą krotkę opcji albo oś lub wartość
  zawiera U+001E lub U+001F (dane zapisane przed regułą tekstów).

- Analizator `polish_text`: tokenizer standardowy, `lowercase`, `polish_stem` (wtyczka `analysis-stempel`, A2),
  `asciifolding`. Podpola `folded`: tokenizer standardowy, `lowercase`, `asciifolding` (bez stemmera):
  łapie frazy bez polskich znaków i formy, których stemmer nie sprowadza.
- `options`, `axes` i tokeny: te same pary oś–wartość, dokładnie jak w Catalog (bez zmiany wielkości liter); osie
  i wartości nie mają identyfikatorów, więc indeks używa ich nazw. Wariant domyślny (produkt bez osi) ma puste
  `options` i `axes`, a produkt bez osi nie ma tokenów `@` ani opcji. Pola pochodne (`axes`, tokeny, `purchasable`,
  `buy_prices`, `max_gross`) są zapisywane przy zapisie dokumentu, nie są czytane z powrotem. `sort_path` nie wynika
  z innych pól, więc `ProductDocument` go nosi (`mainCategoryPath`) i odczyt go zwraca.
- `gross`, `purchasable` i `from_gross` pochodzą z portu `ProductOfferFacts` (A1). Reguła „możliwy do kupienia”
  (`VariantOffer::isPurchasable`: cena istnieje i `available > 0`) i cena „od” (`ProductOfferSummary`) są tylko w Cart,
  więc indeks liczy je tak samo jak kafelek.
- Szkic (CATALOG-ADMIN) ma w indeksie dokument jak każdy produkt, ze statusem `draft` i wersją: trwały tombstone.
  Dokumentów się nie usuwa, więc wersja szkicu chroni przed spóźnionym zapisem starszego stanu bez limitu czasu (bez
  zależności od `index.gc_deletes`), a liczba dokumentów zostaje równa liczbie produktów (statystyki panelu). Fakty
  oferty szkicu są puste (Catalog ukrywa jego warianty przed ofertą sklepu), więc jego dokument nie ma cen.
- Zmiana definicji zmienia `definition_hash`, więc `catalog:search-index:setup` na istniejącym indeksie ostrzega
  i podaje instrukcję `catalog:reindex` (M7, „Ryzyka”).

### Port `ProductOfferFacts` (A1)

- `Catalog_Application`: `ProductOfferFacts::of(list<productId>): array<productId, ProductFacts>`,
  `ProductFacts {fromGross: ?int, variants: array<variantId, VariantFacts {gross: ?int, purchasable: bool}>}`. Produkt nieobecny
  w wyniku: brak cen i nic do kupienia; wariant Catalog nieobecny w faktach: bez ceny, niemożliwy do kupienia.
- Adapter w `Cart_Infrastructure` woła Cart `GetProductOffers` na `query.bus` paczkami po
  `GetProductOffers::MAX_PRODUCTS` (48). Deptrac: `Cart_Infrastructure`, `Pricing_Infrastructure`
  i `Inventory_Infrastructure` mogą zależeć od `Catalog_Application`; Catalog nie importuje Cart, Pricing ani Inventory
  w żadnej warstwie. W czasie działania powstaje cykl wywołań
  Catalog → Cart → Catalog przez szyny (świadomie przyjęty kompromis).

### Zapis i kolejność

- Usługa `ProductIndexing` (`Catalog_Application`): `reindex(productId)` czyta aktualny stan (DBAL Catalog +
  `ProductOfferFacts`) i zapisuje dokument każdego produktu, szkic ze statusem `draft`; produkt, którego nie ma
  w bazie, daje wynik „brak produktu”. Nie idzie
  przez `command.bus` (tylko czyta bazę, więc bez transakcji `doctrine_transaction`).
- Wersja dokumentu: znacznik czasu w mikrosekundach pobrany przed odczytem stanu, `version_type=external_gte`.
  Odczyt rozpoczęty później widzi co najmniej te same zatwierdzone dane, więc przy dwóch równoległych obsługach
  wygrywa nowszy odczyt; starszy zapis to wynik „przestarzały”. Dotyczy to też statusu: spóźniony zapis odczytu
  opublikowanego produktu po nowszym zapisie szkicu (i odwrotnie) jest przestarzały, a dokument zostaje w nowszym
  stanie; powtórzony zapis tej samej wersji jest przyjęty.
- `ProductSearchSource` czyta migawkę produktu, w tym status, warianty (`variantId`, wartości osi) i nazwy kategorii.
- Przebudowa (`catalog:reindex`) indeksuje wszystkie produkty, także szkice, i pobiera fakty ofert dla każdej paczki
  produktów; błąd faktów przerywa przebudowę.
- Przebudowa wypełnia nowy indeks z wyłączonym odświeżaniem (`refresh_interval` `-1`): indeks jest tworzony z tym
  ustawieniem, a `verify` ustawia interwał z `SEARCH_REFRESH_INTERVAL` (M4) przed jawnym `refresh`, sprawdzeniem
  zdrowia, liczby dokumentów i skrótu definicji, czyli przed przepięciem aliasu. Skrót definicji obejmuje mapping,
  analizę i sortowanie indeksu (ustawienie statyczne: zmiana wymaga przebudowy), a nie obejmuje shardów, replik ani
  interwału odświeżania. Indeks z
  `catalog:search-index:setup` dostaje interwał z parametru, a `setup` na istniejącym indeksie ustawia go, gdy jest
  inny.

#### Zapisy w trakcie przebudowy (W19a, M5)

- Przebudowa tworzy nowy indeks razem z aliasem `<alias>-rebuild` (jedno żądanie, przed odczytem pierwszej paczki)
  i zdejmuje go w tym samym żądaniu `_aliases`, które przepina `<alias>`. Nieudana przebudowa usuwa nowy indeks razem
  z aliasem. Po awarii procesu alias zostaje na osieroconym indeksie (wypisuje go `setup`), więc zapisy trafiają tam
  bez szkody; kolejna przebudowa najpierw zdejmuje `<alias>-rebuild` z każdego indeksu (najwyżej jedna przebudowa
  naraz: blokada przebudowy).
- `ProductIndexer` zapisuje dokument jednym `_bulk` z dwiema pozycjami `index` (`require_alias: true`, ta sama wersja
  `external_gte`): najpierw do `<alias>-rebuild`, potem do `<alias>`. Pozycja do `<alias>` ma dzisiejsze znaczenie (zapisany,
  przestarzały, błąd przejściowy albo trwały). Pozycja do `<alias>-rebuild`: 404 `index_not_found_exception` (aliasu
  nie ma, bo nie trwa przebudowa) i 409 (w nowym indeksie jest nowsza wersja) są pomijane; każdy inny błąd tej pozycji
  kończy zapis błędem przejściowym albo trwałym jak pozycja główna: przejściowy ponawia wiadomość (powtórzenie obu
  pozycji jest nieszkodliwe dzięki wersji), trwały kieruje ją od razu do `failed`. Zachowanie `_bulk` z `require_alias` w pozycji i brakującym aliasem (404 tylko tej
  pozycji, bez tworzenia indeksu) sprawdzone na Elasticsearch 9.4.0.
- Dlaczego nic nie ginie: alias powstaje przed odczytem pierwszej paczki, a `ProductIndexing` czyta bazę przed
  zapisem. Zmiana zatwierdzona, zanim alias powstał, jest w odczycie przebudowy; zmiana zatwierdzona później wywołuje
  reindeksację (zdarzenie po zatwierdzeniu), której zapis trafia do obu indeksów. Kolejność zapisu paczki i zapisu
  pojedynczego rozstrzyga wersja (jak w „Zapis i kolejność”), więc nowszy odczyt wygrywa w obu indeksach.
- Zapis pojedynczy w chwili przepięcia aliasu. Przepięcie (`_aliases`) jest atomowe, a `_bulk` rozwiązuje aliasy
  wszystkich pozycji w jednym stanie klastra, przed zapisem na shardach. Źródło (Elasticsearch 9.4.0, tag `v9.4.0`):
  `server/src/main/java/org/elasticsearch/action/bulk/BulkOperation.java`, `doRun()` (l. 189–196) pobiera stan klastra
  raz i przekazuje go do `groupRequestsByShards` (l. 293–322), które z jednego `ProjectMetadata` sprawdza
  `require_alias` (l. 316, 766–776) i rozwiązuje alias każdej pozycji; ponowienie przy blokadzie klastra (l. 723–764)
  przelicza całe żądanie od nowa. Pozycja z `require_alias` nigdy nie tworzy indeksu (`TransportBulkAction.java`,
  l. 323–330). Indeks usunięty po rozwiązaniu aliasu daje 404 `index_not_found_exception` z nazwą tego indeksu
  (`TransportReplicationAction.java`, l. 900–924), a brak aliasu z `require_alias` ten sam typ z nazwą aliasu, więc
  pozycja główna odróżnia błąd przejściowy od trwałego po nazwie w błędzie. Oba aliasy są więc zawsze rozwiązane
  w tym samym stanie, a kolejność pozycji w żądaniu nie ma wpływu na rozwiązanie aliasów (pozycja przebudowy jest
  pierwsza, ale poprawność od tego nie zależy). Przypadki:
  - Oba aliasy rozwiązane przed przepięciem: dokument trafia do nowego indeksu po jego nazwie, a do starego tylko
    przez pozycję główną.
  - Oba po przepięciu: pozycja przebudowy dostaje 404 i jest pominięta, a główna trafia do nowego indeksu.

  Przebudowa usuwa stary indeks po przepięciu. Pozycja główna rozwiązana do starego indeksu, który zniknął przed
  zapisem (`index_not_found_exception`), jest więc błędem przejściowym: wiadomość jest ponawiana i zapisuje aktualny
  stan przez `<alias>`. Nie trafia do `failed`.
- Paczki przebudowy zapisują do nowego indeksu po jego nazwie fizycznej, nie przez alias. Alias przebudowy służy
  tylko zapisom pojedynczym (`ProductIndexer`).
- Paczka przebudowy z pozycją przestarzałą (409: zapis pojedynczy był nowszy) jest poprawna; pozycje przejściowe są
  ponawiane jak dziś, a pozycja trwale odrzucona kończy przebudowę błędem (nowy indeks usunięty, alias bez zmian;
  ponowienie to kolejne uruchomienie przebudowy od początku).
- Wiadomość, która wyczerpała ponowienia w trakcie przebudowy, jest w `failed` (W13 wymaga 0); jej ponowienie
  (`failed-retry`) zapisuje aktualny stan do indeksu aktywnego.
- Katalog nie usuwa produktów (szkic to dokument `draft`), więc przebudowa nie ma usunięć do przeniesienia. Te same
  zasady obejmują reindeksację kategorii (paczki `ProductIndexing`).
- Zapis paczki (`ElasticsearchSearchIndexMaintenance::write`) ponawia to, co zawiodło przejściowo: pozycje odrzucone
  jako przejściowe (429, `es_rejected_execution_exception`, 502, 503, 504, czyli to, co `ElasticsearchErrorClassifier`
  uznaje za `SearchUnavailable`) oraz całe żądanie, które zakończyło się takim błędem albo przekroczeniem czasu.
  Prób jest najwyżej 5 (łącznie z pierwszą), a odstęp rośnie dwukrotnie: 1, 2, 4 i 8 s (`retryBaseDelayMs`, w testach
  podmieniany `sleeper`). Ponowienie obejmuje tylko pozycje, które jeszcze nie zostały zaindeksowane, a wersja
  `external_gte` sprawia, że powtórzenie dokumentu, który przekroczone żądanie zdążyło zapisać, jest nieszkodliwe.
  Pozycja przestarzała (409: zapis pojedynczy przez alias przebudowy był nowszy) jest poprawna i nie jest ponawiana.
  Pozycja trwale odrzucona nie jest ponawiana: przebudowa kończy się jak przy każdym innym błędzie. Po wyczerpaniu prób wynik zawiera pozycje przejściowe
  z informacją o liczbie prób (albo żądanie kończy się `SearchUnavailable`), a przebudowa kończy się porażką: nowy
  indeks jest usuwany, a alias zostaje na poprzednim. Każde ponowienie trafia do logu (`warning`). Zapis przez
  `ProductIndexer` (pojedyncze dokumenty) tego nie dotyczy.
- Każda zapisana paczka przebudowy loguje (`info`, „Search index rebuild progress.”) indeks, liczbę dokumentów
  zapisanych do tej pory i ich tempo (`documents_per_second`, średnie od początku wypełniania; `null`, gdy minęło
  0 s). Oba logi (postęp i ponowienia) idą kanałem Monologa `search_rebuild`, który w `prod` trafia na stderr jako JSON od
  `info`, poza buforem `fingers_crossed` (ten zachowuje tylko logi żądań zakończonych błędem, a `catalog:reindex` nie loguje
  błędu), więc są widoczne także przy udanej przebudowie.

### Wyzwalacze

- `ProductCreated` i integracyjne `ProductChanged` (edycja, publikacja, wycofanie, CATALOG-ADMIN) → `IndexProduct`
  (handler deleguje do `ProductIndexing`; „brak produktu” kończy się `failed`: katalog nie ma usuwania, więc to
  niespójność, a nie powód do ponowienia). Produkt utworzony jako szkic ma od `ProductCreated` dokument `draft`, po
  publikacji dokument `published`.
- `Pricing_Infrastructure`: `ReindexProductOnPriceChange` na `VariantPriceChanged` (transport `events`, niezależny od
  konsumenta unieważniania z PRODUCT-PAGE) → `productId` z Catalog `GetVariant` → `ProductIndexing::reindex`. Wariant nieznany
  Catalogowi albo „brak produktu”: wiadomość obsłużona. Błąd przejściowy (ES, oferta) → ponowienie, trwały → `failed`.
  `ReindexProductOnPromotionChange` na `PromotionPricesChanged` (transport `events`) tak samo, ale `productId` jest
  w zdarzeniu, bez `GetVariant`.
- `Inventory_Infrastructure`: `ReindexProductOnStockLevelChange` na `StockLevelChanged`, tak samo.
- Zdarzenia ze źródła `seed` te handlery potwierdzają bez reindeksacji: `make seed` po seedach katalogu, cen, zamówień
  i stanów uruchamia `catalog:reindex` (synchronicznie), a dopiero potem `storefront:cache:purge-all`. Indeks jest pełny przed końcem
  `make seed`, a ok. 2 tys. zdarzeń seeda nie zajmuje workera.
- Handler reindeksacji i konsument unieważniania są niezależne. Lista sklepu może przez kilka sekund po purge pokazać
  produkt w starym zestawie wyników filtra (indeks jeszcze nie zaktualizowany); cena i dostępność na kafelku pochodzą
  z oferty, nie z indeksu.

## Catalog: `GET /api/v1/products` (wyszukiwanie, filtry, fasety)

Operacja `listProducts` (ścieżka objęta Varnishem i tagami z PRODUCT-PAGE). Wszystkie parametry opcjonalne,
w formacie, który wygenerowany klient (`openapi-fetch`, domyślny serializer: tablica jako powtarzany parametr,
jak `productId` w `/api/v1/product-offers`) wysyła bez zmian:

| Parametr | Walidacja (400 z `violations`) | Znaczenie |
|---|---|---|
| `category` | UUID; nieznana kategoria: 404 | kategoria z podkategoriami |
| `q` | reguła tekstów (`docs/features/api-input-contract/spec.md`, T1–T4): 2–100 znaków po przycięciu białych znaków i znaków niewidocznych z końców; znak sterujący albo separator linii to „Szukana fraza zawiera niedozwolone znaki.” | fraza (S8), szukana po przycięciu |
| `option` | powtarzany `<oś>:<wartość>` (podział na pierwszym `:`), do 50 wpisów, do 5 różnych osi; oś 1–30, wartość 1–40 znaków; forma `option[]` jest odrzucana | wartości osi (S2) |
| `priceMin`, `priceMax` | napis z samych cyfr (do 12), grosze, `priceMin ≤ priceMax` | zakres ceny brutto (S2) |
| `available` | `true` | tylko produkty z wariantem możliwym do kupienia (S2) |
| `sort` | `relevance` (tylko z `q`), `name`, `price_asc`, `price_desc` | domyślnie `relevance` z `q`, inaczej `name` |
| `page`, `perPage` | `page` ≥ 1, `perPage` 1–48 (domyślnie 24) | strona wyników |

### Zapytanie

- `query`: status (filtr `term` `status: published`, zawsze), `category` (filtr `term` na `category_ancestor_ids`)
  i fraza. Status jest w `query`, więc wyniki, `total` i każda agregacja (fasety, `availableCount`, `price`) widzą
  tylko produkty opublikowane. Bez frazy same filtry. Fraza: `bool.should` z
  `minimum_should_match: 1`, w obu gałęziach wszystkie słowa muszą pasować, także gdy są w różnych polach:
  - dokładne: `multi_match` typu `cross_fields`, `operator: and` na `name^3`, `name.folded^3`, `category_names^2`,
    `category_names.folded^2`, `short_description`, `short_description.folded`;
  - literówki: `multi_match` typu `cross_fields` nie przyjmuje `fuzziness`, więc `multi_match` typu `most_fields` na
    tych samych podpolach `folded` z tymi samymi wagami, `operator: and`, `fuzziness: AUTO`, `prefix_length: 1`,
    `max_expansions: 20`, z `boost: 0.3`, więc dopasowania dokładne są wyżej niż przybliżone; tu `operator: and`
    działa w obrębie jednego pola, więc fraza z literówką trafia, gdy wszystkie jej słowa są w jednym polu.
- Ranking: nazwa > kategoria > opis (wagi pól), dokładne przed przybliżonymi (waga gałęzi).
- Koszt zapytania ograniczają: fraza do 100 znaków, `prefix_length` i `max_expansions` gałęzi literówek, limity
  `option` i okno wyników.
- Warunek wariantu (S2: wszystkie warunki dotyczą tego samego wariantu) to zapytanie na polach produktu (M1), „kup.”
  = przy `available` albo cenie:
  - bez osi: brak warunku, a z „kup.” `term` `purchasable`;
  - jedna oś (jedna albo kilka wartości): `terms` na `facets` (z „kup.”: `buy_facets`) z tokenami `<oś>␟<wartość>`;
  - dwie osie: `terms` na `pair_any` (z „kup.”: `pair_buy`) z tokenami `x␞y` każdej pary wybranych wartości;
  - trzy i więcej osi: brak wyników (wariant ma najwyżej 2 osie);
  - cena: `range` na `buy_prices`; razem z osią dodatkowo zapytanie `nested` na `variants` (opcje, `purchasable`
    i `gross` w zakresie w jednym wariancie), zawężone już warunkami na polach produktu.
- Listing bez frazy: warunek wariantu jest w `query` żądania wyników (bez agregacji w tym żądaniu); każda grupa faset
  ma własny zestaw warunków w swoim `query` (M2), więc `post_filter` nie jest potrzebny. Listing z frazą to jedno
  wyszukiwanie w `_msearch` (tekst dopasowany raz): warunek wariantu wyników w `post_filter`, każda grupa faset jako
  agregacja `filter` ze swoim zestawem warunków.
- Produkt bez wariantu możliwego do kupienia (`from_gross` brak) jest w wynikach bez `priceMin`, `priceMax`
  i `available`; którykolwiek z nich go wyklucza. Nie wchodzi do `facets.price`.
- Sortowanie: `relevance` → `_score`, potem `name.sort`, `id`; `name` → `name.sort`, `id`; `price_asc`/`price_desc` →
  `from_gross` (brakujące na końcu), potem `name.sort`, `id`.
- Okno wyników to 10 000 (`index.max_result_window`): strona zaczynająca się za oknem jest pusta z prawdziwym `total`.
  `total` jest dokładny i uwzględnia warunek wariantu.

### Fasety

Odpowiedź `ProductPage` ma pole `facets`, liczone w tym samym `_msearch` co wyniki (M2). Każda faseta widzi status,
frazę i kategorię oraz warunek wariantu bez swojego warunku. Faseta z tym samym zestawem warunków należy do jednej
grupy, czyli jednego wyszukiwania `size: 0` z tymi warunkami w `query`. Wyszukiwania faset nie zależą od strony ani
sortowania, więc trafiają do natywnego request cache (M6); listing bez frazy liczy fasety bez dopasowania tekstu:

| Warunki faset | Jak liczone |
|---|---|
| bez osi i ceny (z dostępnością albo bez) | jedno `terms` po `facets` (z dostępnością: `buy_facets`): kategorie (`#`), osie (`@`), wartości i `availableCount` (`$`) naraz |
| jedna wartość jednej osi, bez ceny | `terms` po `pair_any` (`pair_buy`) z prefiksem `x␞`: osie (`@`) i wartości innych osi wariantów z tą wartością |
| dwie osie bez ceny | wartości osi wybranych z `pair_*`; innych osi nie ma (wariant ma najwyżej 2) |
| oś wybrana bez ceny i bez innej osi | to samo wyszukiwanie co faseta listingu bez osi (ten sam wpis cache) |
| cena, kilka wartości jednej osi przy osiach bez wyboru | `nested` na `variants` zawężone polami produktu, `terms` na `variants.axes` i `variants.options` z `reverse_nested` (produkt raz) |
| `categories` przy warunkach osi albo ceny | `filters` po `category_ancestor_ids` (jeden `term` na kategorię fasety) w grupie z pełnym warunkiem wariantu |
| `availableCount` przy warunkach osi albo ceny | liczba trafień wyszukiwania z warunkiem wariantu i dostępnością (`size: 0`, dokładny `total`); przy `available` albo cenie równa `total` |
| `price` bez warunków osi | dwa wyszukiwania „top 1” sortowane po `from_gross` rosnąco i `max_gross` malejąco wśród produktów z `purchasable` |
| `price` z warunkami osi | `nested` (cena i opcja w tym samym wariancie), `min` i `max` z `variants.gross` |

- `terms` po polach tokenów i po `variants.axes` i `variants.options` zwracają wszystkie kubełki, gdy jest ich do
  10 000 (jeden shard, więc liczby są dokładne). Limity 10 osi i 30 wartości stosuje API przy budowie odpowiedzi,
  z tą samą kolejnością: liczba produktów malejąco, potem nazwa.
- Grupa z większą liczbą różnych tokenów (`sum_other_doc_count` > 0) nie kończy się błędem ani uciętymi fasetami.
  API liczy ją dokładnie dalszymi wyszukiwaniami z warunkami tej grupy, tymi samymi regułami co model sprzed etapu B
  (`ProductSearchQuery`: 10 osi, 30 wartości, bez limitu różnych wartości) i znaczeniem liczb niżej:
  - osie bez wyboru: `terms` po tokenach osi (`@`, w `pair_*` z prefiksem pary `x␞@`) o rozmiarze 10, z `exclude`
    osi wybranych, więc oś wybrana nie zajmuje miejsca osi bez wyboru;
  - wartości każdej zwróconej osi: `terms` po tokenach z prefiksem `<oś>␟` (w `pair_*`: `x␞<oś>␟`) o rozmiarze 30;
  - oś wybrana: w swojej grupie (bez warunku tej osi) wartości z prefiksem o rozmiarze 30 oraz wybrane wartości
    przez `include` z listą dokładnych tokenów; wybrana wartość bez kubełka ma liczbę 0;
  - kategorie fasety i `$`: `include` z listą dokładnych tokenów;
  - grupy `nested` (cena): każda liczba i kolejność kubełków to liczba produktów z `reverse_nested`
    (`order` po niej, jak `BY_PRODUCTS`), nigdy liczba wariantów.

  Prefiks i lista dokładnych tokenów nie interpretują nazw osi ani wartości: prefiks to wyrażenie regularne
  Lucene z każdym znakiem nazwy zapisanym dosłownie (ucieczka) i separatorem `␟` na końcu, więc oś „Kolor” nie łapie
  „Kolor ramy”. Kolejność `terms` jest ta sama co wyżej (liczba produktów malejąco, potem `_key`), więc odpowiedź
  jest taka sama jak przy jednym kroku. Liczba żądań wynika z poprawności, nie odwrotnie: wartości osi zależą od
  zwróconych osi, więc osie (z kategoriami i `$`) i wartości to dwa kolejne `_msearch` po pierwszym; każde łączy
  wszystkie grupy z przepełnieniem. Dodatkowe żądania są tylko w listingach z przepełnieniem. Rozmiar 10 000 jest
  parametrem budowy zapytania, który testy zmniejszają, żeby przejść tę ścieżkę.
- Liczby kubełków tokenów to liczby produktów (token jest raz w dokumencie produktu), więc `reverse_nested` jest
  potrzebny tylko w agregacjach `nested`.

Znaczenie liczb (bez zmian względem S2):

- `categories`: dzieci kategorii z filtra (bez filtra: kategorie główne), które mają pasujące produkty: `{id, name,
  slug, path, count}`, w kolejności drzewa. Liczone z pełnym warunkiem wariantu.
- `options`: osie występujące w wynikach, `{axis, values: [{value, count}]}`. Liczba przy wartości v osi X to liczba
  produktów, które mają wariant z wartością v spełniający wszystkie pozostałe warunki wariantu (inne osie, cena,
  dostępność), czyli `total` po dodaniu v do filtra (dla osi z wyborem: po wybraniu tylko v tej osi, bo liczba jest
  liczona bez warunku własnej osi). Bez globalnego limitu par oś–wartość; limity dotyczą tylko
  wyniku: 10 osi bez wyboru i 30 wartości na oś. Warunki i wartość należą do tego samego wariantu (nie ma kombinacji
  z różnych wariantów).
  - Osie bez wyboru: 10 osi z największą liczbą produktów, każda z 30 wartościami o największej liczbie produktów,
    przy równej liczbie po nazwie.
  - Oś wybrana (z `option`): wartości liczone bez warunku tej osi. Wybrana wartość jest zawsze w liście, także
    z liczbą 0; ucięta limitem dochodzi po pozostałych, więc lista może przekroczyć 30.
  - Kolejność osi: liczba produktów w wynikach (z wszystkimi warunkami) malejąco, potem nazwa; oś wybrana ma ich
    `total`. Oś wybrana jest zawsze w liście (także przy `total` = 0), ponad limit 10 osi bez wyboru; oś bez wartości
    się nie pojawia.
- `price`: `{min, max}` z `gross` wariantów możliwych do kupienia, które spełniają warunki osi (bez warunku ceny);
  `null`, gdy takich nie ma.
- `availableCount`: liczba produktów z wariantem możliwym do kupienia spełniającym warunki osi i ceny, czyli `total`
  po dodaniu `available=true`.

Nagłówki: `Cache-Control` i `xkey` jak w PRODUCT-PAGE (`public, max-age=60`, bez `s-maxage`; `catalog`
i `product-<id>` produktów strony). Varnish trzyma odpowiedź listy po pełnym URL najwyżej 60 s. Unieważnianie (S11):
- purge `product-<id>` z PRODUCT-PAGE usuwa listy, na których produkt już jest (nowa cena i dostępność na kafelku
  przychodzą z oferty);
- listy, na których produktu nie było (nowy produkt, nowe dopasowanie filtra), i kolejność po zmianie ceny odświeża
  TTL: najpóźniej 60 s plus czas reindeksacji; bez tagu list czyszczonego po zapisie w ES;
- `catalog` (pełne unieważnienie po `make seed`) obejmuje wszystkie listy.

OpenAPI (`openapi.json`) opisuje parametry, `facets` i błędy; klient sklepu jest z niego wygenerowany. Test
funkcjonalny składa adres w formacie tego klienta (powtarzany `option`).

## Sklep (`apps/store`)

### Trasy i dane

- `/` (wszystkie produkty), `/c/[...path]` (kategoria), `/szukaj` (wyniki dla `q`): jeden komponent listingu, SSR
  przy każdym żądaniu (S4), odczyty przez `API_CATALOG_URL`.
- Kolejność odczytów: drzewo kategorii (jeden odczyt na żądanie, `React.cache`, wspólny dla layoutu z mega menu,
  `generateMetadata` i strony), potem `GET /api/v1/products` (na `/c/` z id kategorii z drzewa; na `/` równolegle
  z drzewem), potem oferty (`GET /api/v1/product-offers`). Jedno wejście to jedno żądanie na endpoint.
- Odczyty listingu nie trafiają do data cache Next.js (`cache: "no-store"`, bez `next.revalidate` i tagów); jedynym
  cache list jest Varnish. Odczyt drzewa w layoucie na stronie produktu (ISR) ma tag `catalog` jak inne odczyty tej
  strony.
- `/c/[...path]`: kategoria wskazana ścieżką w drzewie; brak → `notFound()` (404, `noindex`).
- `/?category=<id>`: istniejąca kategoria → trwałe przekierowanie (`permanentRedirect`, 308) na `/c/<ścieżka>`
  z pozostałymi parametrami; nieznana kategoria → 404, niepoprawny id (400 API) → „Nieprawidłowy filtr”.
- `/szukaj` bez `q` albo z frazą krótszą niż 2 znaki po przycięciu: „Wpisz co najmniej 2 znaki.” bez wywołania listy
  produktów; fraza dłuższa niż 100 znaków jest przycinana do 100. Parametr `kategoria=<ścieżka>` zawęża wyniki do
  kategorii (sklep zamienia ścieżkę na `category` API); nieznana ścieżka → „Nieprawidłowy filtr”.
- `q` na `/` i `/c/` jest pomijany (nie trafia do API ani do adresów linków).
- Parametry URL sklepu: `q`, `sort` (`trafnosc`, `nazwa`, `cena-rosnaco`, `cena-malejaco`), `cena-od`, `cena-do`
  (pełne złote; sklep przekazuje `<liczba>00` jako grosze tylko dla napisu z samych cyfr, każdą inną wartość
  bez zmian, więc API odpowiada 400), `dostepne=1`, `o=<oś>:<wartość>` (powtarzany, jak w API), `kategoria` (tylko
  `/szukaj`), `page`. Kategoria na `/` i `/c/` wyłącznie w ścieżce. Nieznana wartość `sort` albo `dostepne` →
  „Nieprawidłowy filtr”; 400 API → „Nieprawidłowy filtr”. Każda zmiana filtra lub sortowania usuwa `page`.
- „Nieprawidłowy filtr” ma link „Zobacz cały katalog” (`/`) i link powrotu do tego samego listingu. Cena, która nie
  jest napisem z samych cyfr, to jedyna przyczyna widoczna dla sklepu: gdy reszta parametrów przechodzi sprawdzenie
  sklepu (znany `sort`, `dostepne=1`), link „Pokaż wyniki bez błędnej ceny” usuwa tylko tę cenę (pozostałe filtry,
  sortowanie i fraza zostają). W każdym innym przypadku „Pokaż wyniki bez filtrów” prowadzi do listingu bez filtrów
  i sortowania (na `/szukaj` z frazą); na `/` tego linku nie ma, bo jest nim „Zobacz cały katalog”.

### Widok listingu (komponenty shadcn/ui; wygląd: `docs/features/visual-design/spec.md`)

- Breadcrumby: „Wszystkie produkty” › kategorie ścieżki (linki `/c/<ścieżka>`; ostatni element bez linku); na
  `/szukaj`: „Wszystkie produkty” › „Wyniki wyszukiwania” (z `kategoria` ten element jest linkiem, po nim ścieżka
  kategorii). Na `/` breadcrumbów nie ma.
- `h1`: nazwa kategorii, „Wszystkie produkty” albo `Wyniki dla „<fraza>”`; obok liczba produktów z odmianą
  („1 produkt”, „2–4 produkty” i 22–24 itd., pozostałe „produktów”). Wyjątek: `/` w stanie domyślnym (bez filtrów,
  z domyślnym sortowaniem, `sort` brak albo `nazwa`, i `page` brak albo 1) ma nad listą hero z `h1` i kafelki działów; „Wszystkie produkty” jest wtedy `h2`,
  a nazwy produktów `h3` (`docs/features/visual-design/spec.md`, „Strona główna”).
- Panel filtrów (po lewej na szerokim ekranie, rozwijany przyciskiem „Filtry” na wąskim): „Kategorie” (faseta
  `categories` jako linki z liczbą, zachowujące filtry: na `/` i `/c/` do `/c/<ścieżka>`, na `/szukaj` z `kategoria`),
  każda oś z `options` (pola wyboru z liczbą), „Cena (zł)” (pola „Cena od” i „Cena do” z klawiaturą numeryczną,
  pod nimi „Ceny od … do …” z `facets.price`), „Tylko dostępne (<liczba>)” (pole wyboru z `availableCount`).
- Nad wynikami: aktywne filtry jako linki usuwające pojedynczy filtr (na `/szukaj` także „Kategoria: …”) i „Wyczyść
  filtry” (zostawia frazę i sortowanie); lista „Sortuj” („Trafność” tylko przy frazie; domyślne sortowanie nie trafia do
  adresu).
- Zmiana nawiguje do nowego adresu według S9 (Client Component); bez JavaScriptu formularz `GET` z przyciskiem
  „Pokaż wyniki” (w `noscript`, razem z panelem rozwiniętym na każdej szerokości).
- Siatka kafelków i paginacja; paginacja zachowuje wszystkie parametry.
- Brak wyników: „Nie znaleźliśmy produktów.” i, gdy są filtry, przycisk „Wyczyść filtry”, a przy frazie link
  „Zobacz wszystkie produkty”.
- Front niczego nie liczy poza zamianą złotych na grosze w parametrze ceny: liczby, zakresy i dostępność z API.

### Nagłówek: wyszukiwarka i mega menu (S7, S10)

- Pole wyszukiwania w nagłówku każdej strony: formularz `GET /szukaj` z polem `q` (etykieta „Szukaj produktów”,
  `type="search"`, przycisk „Szukaj”), na `/szukaj` wypełnione bieżącą frazą (w HTML z serwera, także bez JavaScriptu). Od szerokości `sm` logo, pole, koszyk i konto
  są w jednym wierszu; poniżej pole ma własny wiersz na pełną szerokość pod logo, koszykiem i kontem (nagłówek ma
  `flex-wrap`), żeby strona nie przewijała się w poziomie (`e2e/tests/site-header.spec.ts`).
- Mega menu (`nav` „Działy”) z drzewa kategorii (`GET /api/v1/categories` przez `API_CATALOG_URL`; na stronie
  produktu odczyt ma tag `catalog`, więc ISR rewaliduje się z resztą katalogu):
  - szeroki ekran (od `md`): przyciski działów; panel działu (link „Wszystko z działu” i kategorie poziomów 2–3)
    otwiera się po kliknięciu, Enter/Spacja i najechaniu myszą, zamyka Escape, kliknięcie poza menu, wyjście fokusu
    z menu, kliknięcie linku i przejście na inny dział; `aria-expanded`, fokus wraca na przycisk po Escape;
  - wąski ekran: przycisk „Menu” z rozwijaną listą działów i ich podkategorii (poziomy 2–3);
  - wszystkie linki są w HTML także przy zamkniętym menu (SEO), JavaScript tylko otwiera i zamyka.
- Błąd odczytu drzewa w layoucie (S10): nagłówek bez menu, `console.error` na serwerze, strona renderuje się dalej.
  Strona, która i tak potrzebuje drzewa (`/c/…`, `/szukaj` z `kategoria`, `/?category=`), kończy się wtedy stroną błędu
  (`app/error.tsx`).

### Metadane i SEO (S5)

- `/c/<ścieżka>`: tytuł `<nazwa kategorii>[ – strona n] | Worf`, opis „Produkty z kategorii <nazwa> w sklepie Worf.”,
  kanoniczny `<STORE_URL>/c/<ścieżka>` (`?page=n` przy n > 1). `/`: tytuł `Wszystkie produkty[ – strona n] | Worf`,
  kanoniczny `<STORE_URL>/` (`?page=n` przy n > 1).
- Którykolwiek z parametrów `sort`, `cena-od`, `cena-do`, `dostepne`, `o` → `robots: noindex, follow` i kanoniczny
  listingu bez nich i bez `page`.
- `/szukaj`: `noindex, follow`, bez adresu kanonicznego, tytuł `Wyniki dla „<fraza>”[ – strona n] | Worf` (bez frazy
  albo z za krótką `Wyszukiwanie | Worf`). Wyjątek: „Nieprawidłowy filtr” ma samo `noindex`.
- Pusta strona poza zakresem (`page` większe niż liczba stron): pusta lista z prawdziwym `total`,
  `noindex`. Niepoprawny numer strony (`0`, `abc`, ujemny): API 400 → „Nieprawidłowy filtr” z `noindex`.
- JSON-LD `BreadcrumbList` na `/c/<ścieżka>` (bezwzględne adresy; `<` zakodowane jak na stronie produktu).
- Strona produktu (PRODUCT-PAGE): linki ścieżki i `BreadcrumbList` wskazują `/c/<ścieżka>`.

## Infrastruktura

- `infra/elasticsearch/Dockerfile`: `FROM docker.elastic.co/elasticsearch/elasticsearch:9.4.0` i
  `elasticsearch-plugin install --batch analysis-stempel`. Usługa `elasticsearch` jest budowana z tego pliku (`build`),
  w CI też. Wtyczkę pokazuje `elasticsearch-plugin list` w kontenerze, a `_analyze` z `polish_stem` daje ten sam token
  dla „kurtki” i „kurtka” (`ProductIndexDefinitionTest`).
- `make seed`: `catalog:seed-demo`, `pricing:seed-demo`, `ordering:seed-demo`, `inventory:seed-demo`,
  `catalog:reindex`, `storefront:cache:purge-all`.
- Liczba shardów nowego indeksu to zmienna `SEARCH_SHARDS` (parametr `app.search.shards`): w compose z `.env` worktree
  (bez niej 1), w chartcie z `elasticsearch.shards` (1 we wszystkich profilach, M3). Dostają ją wszystkie kontenery
  z kodem API. Indeks, który istnieje, zachowuje swoją liczbę shardów (hash definicji jej nie obejmuje, więc `setup` go
  nie odrzuca); nową dostaje dopiero indeks z następnej przebudowy. Wartość mniejsza od 1 kończy budowę definicji
  błędem z nazwą zmiennej, pustą i nieliczbową odrzuca kontener, a schemat chartu przyjmuje tylko liczbę całkowitą od
  1 do 1024. Jeden shard: shardy na jednym węźle kosztowały w etapie A 1,6–1,75 razy więcej CPU, a czas pojedynczego
  zapytania skraca równoległe przeszukiwanie segmentów (domyślne w ES 9, zostaje włączone).
- `SEARCH_REFRESH_INTERVAL` (parametr `app.search.refresh_interval`, M4): w compose z `.env` worktree (bez niej `1s`),
  w chartcie z `elasticsearch.refreshInterval` (domyślnie `1s`, w `prod` `5s` z komentarzem o warunkowej decyzji
  i PERF-6). Zmiana wartości działa po wdrożeniu (`setup` w Jobie `migrate`), bez przebudowy. Dozwolony format to
  liczba z jednostką `ms` albo `s`; inną wartość odrzuca kontener (i schemat chartu).
- Request cache: ustawienia domyślne Elasticsearcha (włączony dla `size: 0`, rozmiar 1% sterty). Pomiar akceptacyjny
  podaje trafienia i wyrugowania (`_nodes/stats/indices/request_cache`); rozmiar zmienia się tylko po wykazanych
  wyrugowaniach.
- Pamięć: etap B nie zmienia sterty ani limitów. Gorące dane modelu R przy 10 mln to ok. 4,5 GB (etap A, górna
  granica), a `prod` ma limit 4 GiB przy stercie 2 GiB, czyli 1–1,5 GiB na cache plików; docelowe wartości ustala
  PERF-1 (D2, D3: według szacunku ok. 8 GiB limitu przy stercie 2 GiB). Pomiar akceptacyjny etapu B biegnie przy
  wartościach PERF-1 wdrożonych na worf.pl i podaje limit, stertę, rozmiar indeksu i odczyty z dysku w stanie
  ustalonym.
- Przebudowa indeksu na klastrze: `make -C infra/k8s reindex KUBE_DIR=<katalog>`, tylko po zgodzie użytkownika
  w rozmowie, która nazywa operację i host. Cel odmawia bez zmian, gdy w przestrzeni nazw działa Job (poza Jobami
  CronJobów `rate-limit-check`, `queue-check` i `order-expiry`), gdy blokadę `demo-data-lock` trzyma `generate` albo
  `open`, albo gdy nie da się odczytać indeksu aliasu `products`; wypisuje bieżący indeks i liczbę shardów nowego
  i pyta o słowo `tak`. Po nim bierze blokadę `demo-data-lock` na czas przebiegu (`generate` i `open` wtedy
  odmawiają), sprawdza Joby jeszcze raz (Job, który wystartował w czasie oczekiwania na odpowiedź, to odmowa), tworzy
  Job z zawieszonego CronJoba `reindex` (`catalog:reindex` z obrazem, środowiskiem i sekretami wdrożonej rewizji),
  czeka na niego i sprawdza, że alias wskazuje nowy indeks o liczbie shardów z `SEARCH_SHARDS`. Sklep działa w trakcie
  na starym indeksie; nieudany przebieg zostawia alias bez zmian. Wdrożenie rozpoczęte w trakcie przebudowy kończy
  się nieudanym Jobem `migrate` (`catalog:search-index:setup` odmawia przy zajętej blokadzie przebudowy), który po
  końcu przebudowy ponawia `rerun`. Zmiany zapisane w trakcie przebudowy trafiają do nowego indeksu („Zapisy
  w trakcie przebudowy”), więc przebudowa pod normalnym ruchem jest dopuszczalna. Produkt utworzony w jej trakcie
  z identyfikatorem poniżej kursora odczytu paczek albo po odczycie ostatniej paczki kończy ją jednak błędem
  weryfikacji liczby dokumentów (alias bez zmian, ponowienie; #979). Nieudaną przebudowę ponawia się
  kolejnym uruchomieniem celu (zaczyna od początku, alias do tego czasu na starym indeksie).
- Zmiana definicji indeksu na klastrze (M7) to okno serwisowe, za zgodą użytkownika na każdy krok: wdrożenie
  (`setup` w Jobie `migrate` ostrzega o nieaktualnym indeksie i wdrożenie przechodzi), potem
  `make -C infra/k8s reindex`. Między wdrożeniem a przepięciem aliasu nowa wersja czyta indeks starej definicji: listing
  nie pokazuje poprawnych wyników ani faset, a zapis dokumentu nowej definicji do starego indeksu (`dynamic: strict`)
  jest trwale odrzucany, więc wiadomości reindeksacji z tego czasu trafiają do `failed`; do nowego indeksu trafiają przez
  alias przebudowy. Po przebudowie: `make -C infra/k8s failed-retry`, kontrola `smoke`. Stara i nowa wersja nie są ze
  sobą zgodne i nie muszą być. Wycofanie: revert zmiany na `main`, wdrożenie i znów `reindex` (ta sama procedura
  w drugą stronę). Działa, bo wersja, do której się wraca, też ostrzega zamiast odmawiać: zachowanie `setup` z M7
  trafia na `main` i na worf.pl osobną, wcześniejszą zmianą niż model. Synchronizacja poprzedniego snapshotu
  `deploy-images` sprzed M7 zatrzymałaby się na Jobie `migrate`. Kroki są w runbooku.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Nieznana ścieżka `/c/…` | 404 z `noindex` |
| Niepoprawny filtr, `relevance` bez frazy, `priceMin > priceMax`, `option` bez `:` | API 400; sklep „Nieprawidłowy filtr” z `noindex` |
| Nieznana ścieżka w `kategoria` na `/szukaj` | „Nieprawidłowy filtr” z `noindex` |
| Fraza < 2 znaki na `/szukaj` | komunikat, bez wywołania listy |
| Elasticsearch niedostępny | API 503; sklep strona błędu (`app/error.tsx`) |
| Drzewo kategorii niedostępne | nagłówek bez menu (S10); listing: strona błędu; koszyk działa |
| Błąd oferty przy reindeksacji (`GetProductOffers`) | wyjątek, ponowienie wiadomości; przy przebudowie przerwanie przebudowy |
| Wariant ze zdarzenia nieznany Catalogowi | wiadomość obsłużona bez reindeksacji |
| Zdarzenie ze źródła `seed` | obsłużone bez reindeksacji (indeks przebudowuje `make seed`) |
| Wybrana wartość osi bez wyników | lista pusta, wartość widoczna z liczbą 0, „Wyczyść filtry” |
| Błąd jednej części `_msearch` (przejściowy albo trwały) | cała odpowiedź 503 albo 500, bez częściowych faset |
| Więcej różnych tokenów w listingu niż rozmiar `terms` | dalsze wyszukiwania liczą grupę dokładnie, z regułami osi i wartości wybranych; nigdy ucięte fasety ani błąd |
| Indeks starej definicji po wdrożeniu (M7) | `setup` ostrzega; do przebudowy listing niepoprawny, zapisy reindeksacji w `failed` |
| Produkt łamiący regułę modelu (ponad 2 opcje w wariancie, powtórzona krotka, U+001E/U+001F w osi albo wartości) | `SearchIndexError` z identyfikatorem produktu: przebudowa kończy się błędem, wiadomość reindeksacji w `failed` |
| Zapis do aliasu przebudowy nieudany | przejściowo: wiadomość ponawiana, po wyczerpaniu prób w `failed`; trwale: wiadomość od razu w `failed` (jak błąd trwały pozycji głównej) |

## Kryteria akceptacji

1. Fraza z nagłówka prowadzi do `/szukaj` z wynikami; „asfalt” znajduje „Buty szosowe Tarvik Asfalt 200”, a kliknięcie
   kafelka otwiera `/p/<slug>`, skąd wariant trafia do koszyka; „kurtki” znajduje kurtki (odmiana), „spiwor” śpiwory
   (bez polskich znaków), „plecal” plecaki (literówka); dwa słowa, z których jedno nigdzie nie pasuje, nie dają wyników.
2. `/c/bieganie/buty` pokazuje produkty kategorii i podkategorii, breadcrumby i fasety; nieznana ścieżka daje 404;
   `/?category=<id>` przekierowuje trwale na `/c/<ścieżka>`.
3. Filtr „Kolor: czarne” + „Rozmiar: 42” zwraca tylko produkty z wariantem czarnym w rozmiarze 42; liczba przy każdej
   wartości równa się `total` po jej dodaniu (w osi z wyborem: po wybraniu tylko tej wartości); zakres ceny
   i „Tylko dostępne” dotyczą tego samego wariantu.
4. Sortowanie po cenie rosnąco daje kafelki w kolejności ceny „od”, produkty bez ceny na końcu.
5. Zmiana ceny w panelu zmienia przynależność produktu do filtra ceny w ciągu 90 s.
6. Mega menu otwiera się myszą i klawiaturą, zamyka Escape; linki wszystkich działów i poziomów 2–3 są w HTML;
   niedostępne drzewo kategorii nie psuje koszyka.
7. Listing bez filtrów ma kanoniczny `/c/<ścieżka>` i jest indeksowany; z filtrem albo sortem ma `noindex, follow`
   i kanoniczny bez parametrów; `/szukaj` zawsze `noindex, follow`, bez kanonicznego; `/c/` ma JSON-LD `BreadcrumbList`.
8. Catalog nie zależy od Pricing, Inventory ani Cart (Deptrac).
9. Obraz Elasticsearch ma wtyczkę `analysis-stempel` (`elasticsearch-plugin list` w kontenerze).
10. Model R daje te same wyniki co model sprzed PERF-2 B (liczba trafień, fasety z liczbami, zakres cen, pierwsza
    strona) w teście z wyrocznią w PHP (`Testy`) na ręcznie zbudowanym katalogu przypadków brzegowych modelu wariantów
    oraz w porównaniu odpowiedzi `GET /api/v1/products` przed i po zmianie na lokalnym stosie `medium` dla mieszanki
    adresów PERF-0 (ten sam stan danych, 0 różnic; kolejność „Trafność” porównywana przy tej samej liczbie shardów).
    Także przy przepełnieniu `terms` (ścieżka przepełnienia faset), bez błędu i bez ucięcia.
11. Zapis wykonany w trakcie przebudowy jest w nowym indeksie po przepięciu aliasu (W19a), także gdy paczka
    przebudowy dostaje pozycję przestarzałą albo przejściowo odrzuconą i gdy przepięcie z usunięciem starego indeksu
    wypada między odczytem bazy a `_bulk`; źródło rozwiązywania aliasów w `_bulk` jest wpisane w specyfikacji.
12. Zmiana `SEARCH_REFRESH_INTERVAL` działa na istniejącym indeksie po `setup`, bez przebudowy; indeks ma 1 shard
    i sortowanie po `sort_path`.
13. Kryteria pomiarowe etapu B z `docs/features/performance-program/plan.md` (PERF-2, „Kryteria etapu B”, 1–3 i 5)
    zmierzone na worf.pl przy 10 mln produktów, wartościach pamięci z PERF-1 i `refresh_interval` 5 s; wynik w raporcie
    w `docs/reports/` z porównaniem do linii bazowej PERF-0. Niespełnione W8 albo W9 kończą etap raportem z wariantami
    i decyzją właściciela, bez obniżania wymagań i bez liczników faset (M8).

## Testy

- Jednostkowe: `CategorySlug` (format, granice), `Category` ze slugiem, `CreateCategory` z zajętym slugiem (rodzeństwo
  i korzenie), `VariantAxes` z `:`; budowa dokumentu (warianty, `options`, `from_gross`, nazwy kategorii,
  produkt i wariant bez faktów ofert); `ProductIndexing` („brak produktu”, błędy); handlery w Pricing/Inventory
  (wariant nieznany, źródło `seed`, błąd przejściowy); walidacja parametrów listy; `path` w `GetCategoryTreeHandler`.
- Integracyjne: migracja slugów i unikalne indeksy (rodzeństwo, korzenie); adapter `ProductOfferFacts` (paczki po 48,
  produkt bez wariantów); ES: analizator (`_analyze`), fraza (odmiana, bez znaków, literówka, dwa słowa z jednym
  niepasującym także przy literówce, słowo bez polskich znaków razem ze słowem tylko w opisie,
  ranking: trafienie w nazwie przed trafieniem w opisie, dokładne przed literówką), filtr wariantu (para
  wartości na jednym wariancie, nie na dwóch), cena i dostępność na tym samym wariancie, sortowania, fasety (liczba
  przy wartości = `total` po jej dodaniu, kilka wartości jednej osi, wybrana wartość z 0, `price`, `availableCount`,
  kategorie; ponad 10 osi i 30 wartości z rzadkimi wartościami na osi z pierwszej dziesiątki, kilka wybranych osi
  z ceną i dostępnością, brak kombinacji z różnych wariantów, porównanie całej odpowiedzi faset z modelem policzonym
  w PHP na wygenerowanym zbiorze setek produktów; pomiar `took` agregacji pod `SEARCH_PERF=1`, poza zwykłym
  `make test`), wersja `external_gte` (starszy odczyt przestarzały), przebudowa z faktami ofert, ponawianie zapisów
  przebudowy (transport wstrzykujący 429 pozycji, 503 i przekroczenia czasu do prawdziwego Elasticsearcha: sukces po
  kilku błędach, wyczerpanie 5 prób i błąd trwały kończą przebudowę z nowym indeksem usuniętym i aliasem bez zmian),
  `refresh_interval` `-1` w trakcie wypełniania i z `SEARCH_REFRESH_INTERVAL` po udanej przebudowie, log postępu (test
  jednostkowy).
- Zgodność modelu R (kryterium 10). Wyrocznia: istniejący model faset liczony w PHP wprost z definicji S2 (bez
  Elasticsearcha), rozszerzony o liczbę trafień, zakres cen i pierwszą stronę. Katalog testowy budowany ręcznie,
  a nie z generatora danych demo, z każdym przypadkiem brzegowym modelu wariantów: produkt bez osi (wariant
  domyślny), jedna i dwie osie, dwie opcje w tym samym wariancie wobec tych samych opcji w różnych wariantach, kilka
  wartości jednej osi z wartością drugiej, opcja łączona z dostępnością (wariant z opcją niedostępny, inny dostępny),
  cena łączona z opcją i dostępnością (także cena w zakresie tylko w wariancie bez opcji), wariant bez ceny, ta sama
  wartość na różnych osiach, wartości i osie ze znakami `#`, `@`, `$`, `␟`-podobnymi znakami Unicode i znakami
  wyrażeń regularnych (`.`, `*`, `(`, `\`), polskie znaki i wielkość liter, szkic, produkt w kilku kategoriach, oraz
  zmiana opcji, ceny i dostępności istniejącego produktu (ponowny zapis i porównanie). Zestaw kryteriów wyliczany
  wyczerpująco na tym katalogu: każdy podzbiór osi (0–3) z jedną i dwiema wartościami, z ceną i bez, z dostępnością
  i bez, z kategorią i bez, z frazą i bez; porównanie całej odpowiedzi. Cały zestaw biegnie drugi raz z rozmiarem
  `terms` zmniejszonym tak, że każda grupa faset przechodzi ścieżkę przepełnienia, z tym samym wynikiem wyroczni.
  Katalog ma do tego: więcej niż 10 osi i 30 wartości na osi; remisy liczby produktów na granicy 10. osi
  i 30. wartości (kolejność po nazwie); oś wybraną z liczbą, która mieści ją w pierwszej dziesiątce (nie zabiera
  miejsca osi bez wyboru), i oś wybraną spoza dziesiątki; wybraną wartość spoza 30 i wybraną wartość z liczbą 0;
  grupę z ceną, w której jeden produkt ma wiele wariantów z tą samą wartością, a inna wartość jest w większej liczbie
  produktów po jednym wariancie (kolejność po wariantach różni się od kolejności po produktach); oś będącą prefiksem
  innej osi („Kolor”, „Kolor ramy”) i znaki wyrażeń regularnych w nazwach osi i wartości przy przepełnieniu; grupę
  jednej wartości jednej osi (tokeny `pair_*`) z przepełnieniem. Dodatkowo test reguł domeny, na których stoi
  model: `VariantAxes` odrzuca trzecią oś, `ProductVariants` powtórzoną krotkę (także różniącą się wielkością liter),
  `Text` znaki U+001E i U+001F; budowa dokumentu odrzuca dane łamiące te reguły.
- Porównanie przed i po na `medium` (kryterium 10, druga część): odpowiedzi API dla mieszanki adresów PERF-0 zapisane
  na rewizji sprzed zmiany i porównane z odpowiedziami po przebudowie na tych samych danych (skrypt w katalogu raportu
  etapu B, bez nowego narzędzia w `load/`).
- W19a (kryterium 11), integracyjne na prawdziwym Elasticsearchu: zapis pojedynczy w trakcie wypełniania (przed
  i po paczce z tym produktem) jest w nowym indeksie po przepięciu; pozycja przestarzała w paczce nie przerywa
  przebudowy; zapis bez trwającej przebudowy (404 aliasu przebudowy pominięty); błąd przejściowy i trwały pozycji
  aliasu przebudowy kończy zapis błędem; nieudana przebudowa usuwa nowy indeks z aliasem, a kolejna zdejmuje alias
  osierocony. Przepięcie w trakcie zapisu: zapis z odczytem bazy przed przepięciem i `_bulk` po nim (przepięcie
  i usunięcie starego indeksu wstrzyknięte między odczyt a zapis) jest w nowym indeksie, a 404 pozycji głównej
  ponawia wiadomość bez `failed`. Próba współbieżna: zapisy pojedyncze w pętli równolegle z kilkoma przebudowami
  małego indeksu, po każdej każdy zapisany produkt ma w aktywnym indeksie swoją ostatnią wersję; `setup` przy innym skrócie definicji ostrzega i kończy się kodem 0.
- Funkcjonalne API: `GET /api/v1/products` z każdym parametrem (adres w formacie klienta) i błędami 400 (każdy
  niepoprawny parametr, `relevance` bez `q`), `facets` w odpowiedzi, nagłówki `Cache-Control` i `xkey`; `GET /api/v1/categories`
  i `GET /api/v1/admin/categories` ze `slug` i `path`; `breadcrumb` z `path` w szczegółach produktu.
- Vitest: parametry URL ↔ zapytanie API (złote → grosze, wartości niecyfrowe bez zmian, usuwanie `page`, `q` pomijane
  na `/` i `/c/`), metadane i `noindex`/kanoniczne dla każdej kombinacji, `BreadcrumbList`, rozwiązywanie ścieżki
  `/c/` i `kategoria`, przekierowanie `/?category=`, odmiana „produkt”, panel filtrów (pola wyboru nawigują od razu,
  cena po Enter/opuszczeniu, chipy, „Wyczyść filtry”), mega menu (klawiatura, Escape, `aria-expanded`, błąd drzewa:
  nagłówek bez menu), `/szukaj` z krótką frazą bez wywołania API, liczba żądań na wejście (`page.requests.test.tsx`).
- E2E: `search.spec.ts` (fraza z nagłówka → wyniki → strona produktu → dodanie do koszyka; przypadki z kryterium 1),
  `listing-filters.spec.ts` (mega menu → kategoria, breadcrumby, para filtrów, cena, „Tylko dostępne”, sortowanie,
  czyszczenie, `noindex` i kanoniczny w HTML, zmiana ceny w panelu zmienia wynik filtra ceny w ciągu 90 s);
  `site-header.spec.ts` (nagłówek mieści się na ekranie 375 px, także na `/szukaj`); nawigacja przez mega menu albo
  `/c/` w `catalog.spec.ts`, `price-change.spec.ts`, `stock-receipt.spec.ts`, `cart-variants.spec.ts`,
  `product-page.spec.ts` i pomocnikach `e2e/support`.

## Weryfikacja wydajności (PERF-2 etap B)

Kryteria pomiarowe są w planie programu (PERF-2, „Kryteria etapu B”); tu narzędzia i zasady pomiaru.

- Odczyty: narzędzie PERF-0 (`load/baseline.py`: tryby `single`, `open`, `burst`) z mieszanką adresów zapisaną
  z wynikiem, ta sama przed i po. Zasoby i CPU usług z Grafana Cloud (metryki kontenerów), request cache z
  `_nodes/stats`. Bez nowego narzędzia pomiaru odczytów.
- Zapisy (kryterium 3): scenariusz k6 w `load/` (rozszerzenie istniejącej biblioteki `load/lib`), uruchamiany jak
  pozostałe scenariusze przez `load/k6.sh` (lokalnie i wobec worf.pl), bez zmian w `make -C infra/k8s load`. Wysyła w modelu otwartym zadaną liczbę zmian
  na sekundę przez API panelu (`PUT /api/v1/admin/variants/{variantId}/price`, `POST …/stock/receipts`), więc przechodzi
  pełną ścieżkę: transakcja, historia, zdarzenie, reindeksacja. Uwierzytelnienie: token odświeżania sesji panelu
  (publiczny klient `admin`) podany w zmiennej środowiska, odświeżany przez skrypt; bez nowego konta i klienta
  Keycloaka. Świeżość (W16) mierzy próbka zmian ceny i zmian dostępności: czas od odpowiedzi zapisu do chwili, gdy
  zapytanie wariantu (niżej) widzi nowy stan.
  - Zmiana ceny: wariant z nową ceną jest w wynikach. Próbka ceny to osobne warianty próby, na których kolejna zmiana
    rusza dopiero po zobaczeniu poprzedniej. Ceny ±1 gr się powtarzają, więc bez tego wcześniejsza, jeszcze widoczna
    cena dałaby fałszywie krótki czas.
  - Zmiana dostępności: pierwsze przyjęcie u wariantu bez stanu, po którym wariant z ceną początkową jest
    w wynikach.
- Zapytanie wariantu: `GET /api/v1/products` z pominięciem Varnisha, z kategorią produktu, wartością każdej osi
  wariantu (`option=<oś>:<wartość>`) i `priceMin` = `priceMax` = sprawdzana cena, przeglądane do ostatniej strony.
  Produkt jest w wynikach wtedy i tylko wtedy, gdy ten konkretny wariant jest w indeksie możliwy do kupienia za tę
  cenę: warunki wariantu dotyczą jednego wariantu, zakres ceny tylko wariantów możliwych do kupienia (S2), a kombinacja
  wartości osi jest w produkcie unikalna (`ProductVariants`; produkt bez osi ma jeden wariant). Okno wyników to
  10 000. Do próby wchodzą więc tylko warianty, których zapytanie przy każdej możliwej cenie (początkowa, ±1 gr) ma
  `total` w oknie, a produkt nieznaleziony przy `total` ponad oknem to błąd przebiegu, nigdy zgodność ani niezgodność.
  Warianty, których osi nie da się podać w `option` (dwukropek w nazwie osi), nie wchodzą do próby.
- Skutki zapisów są trwałe i scenariusz ich nie cofa: każda zmiana zostaje w historii cen i ruchów magazynowych,
  w zdarzeniach, w wersjach agregatów i dokumentów indeksu. Ograniczenie skutków zamiast kompensacji: zmiany tylko na
  wybranej na początku przebiegu próbie wariantów z ceną w opublikowanych produktach (lista zapisana z wynikiem), cena
  w granicach ±1 gr od wartości z początku przebiegu, stan tylko przyjęciami (nigdy nie odbiera dostępności ani nie
  dotyka rezerwacji zamówień). Część próby (udział z parametru scenariusza) to warianty bez dostępnego stanu na
  początku przebiegu: indeks przechowuje tylko, czy wariant można kupić, więc tylko przyjęcie u takiego wariantu
  zmienia dokument i daje się sprawdzić; brak takich wariantów przy udziale większym od zera kończy przebieg błędem.
  Zmiany ceny dotyczą tylko wariantów możliwych do kupienia na początku przebiegu, przyjęcia wszystkich wariantów próby.
  Przerwany przebieg zostawia warianty próby z ceną różną od początkowej o najwyżej 1 gr i ze stanem wyższym,
  a warianty bez stanu, które dostały przyjęcie, możliwe do kupienia; wynik przebiegu podaje listę wariantów i liczbę
  wykonanych zmian. Na worf.pl wyłącznie za zgodą właściciela na konkretny przebieg; najpierw lokalnie.
- Po każdym pomiarze z zapisami: zgodność indeksu z bazą dla zmienionych wariantów i 0 wiadomości w `failed` (W13).
  Zgodność sprawdza koniec scenariusza zapytaniami wariantu, bez nowego polecenia i celu. Oczekiwany stan każdego
  wariantu próby scenariusz liczy z własnych potwierdzonych zapisów: cena ostatnia zapisana albo początkowa,
  dostępność początkowa albo „tak” po przyjęciu. Po ustaniu zapisów kontrola powtarza zapytania wariantów, aż każde
  zgadza się z oczekiwanym stanem, najdłużej do limitu czasu z parametru scenariusza. Wariant niezgodny po limicie
  to błąd przebiegu. Zapytanie wariantu z oczekiwaną ceną ma znaleźć produkt wtedy i tylko wtedy, gdy wariant ma być
  możliwy do kupienia.
  - Samo odczekanie `refresh_interval` nie wystarcza, bo kolejka reindeksacji może mieć zaległość. Pusta kolejka nie
    jest jednak potrzebna: reindeksacja czyta bazę w chwili przetwarzania, a wersja `external_gte` odrzuca starszy
    odczyt. Gdy indeks pokazuje końcowy stan wariantu, wiadomości jeszcze w kolejce zapiszą ten sam albo nowszy stan
    i go nie cofną.
  - Po kontroli liczbę wiadomości w `failed` sprawdza istniejący cel `make -C infra/k8s failed`, a lokalnie
    `messenger:failed:show` w kontenerze API.
  - Wariant z próby cen sprawdza ostatnią cenę, a wariant bez stanu na początku to, czy przyjęcie uczyniło go
    dostępnym.
  - Kontrola zakłada, że w trakcie przebiegu nikt poza scenariuszem nie zmienia wariantów próby. Zamówienie klienta
    może dać fałszywą niezgodność.
  - Wariant, którego zapis nie dostał odpowiedzi, jest sprawdzany względem każdego stanu, jaki ten zapis mógł
    zostawić; wynik podaje liczbę takich wariantów.
  - Każda niezgodność (wariant, stan oczekiwany i znaleziony) to błąd przebiegu.
  - Zapisy zmieniają tylko cenę i stan wariantu, a indeks przechowuje z nich cenę i to, czy wariant można kupić,
    więc tylko te pola dokumentu mogą się rozjechać.
- Zimny request cache: request cache Elasticsearcha opróżniany przed przebiegiem. Model R liczy fasety żądaniami
  `size: 0`, które ES buforuje domyślnie, a obecny model ich nie buforuje. Na worf.pl robi to nowy cel `infra/k8s`,
  który dodaje etap B: tylko `POST /<alias indeksu produktów>/_cache/clear?request=true`, wyłącznie dla indeksu
  produktów, bez zmiany danych i bez innych pamięci podręcznych. Lista adresów pochodzi z `load/baseline.py urls`,
  wraca z wynikiem, a trafienia cache HTTP wyklucza `report`. Poza tym celem etap B nie dodaje infrastruktury
  pomiarowej.
- Raport rozróżnia dwa stany: zimny request cache (opróżniony celem powyżej, cache plików systemu ciepły) i zimny
  cache plików systemu (pierwszy przebieg po starcie Elasticsearcha albo po przebudowie indeksu). Cache plików nie
  jest opróżniany żadnym narzędziem; jego stan raport podaje z tego, co poprzedziło przebieg, a wynik bez znanego stanu
  oznacza jako taki. Wyniki obu stanów nie są łączone w jedną wartość.

## Kolejność zmian i dokumentacja (PERF-2 etap B)

1. M7 (`setup` ostrzega przy innym skrócie definicji) osobnym PR do `main`, wdrożony na worf.pl przed modelem
   (warunek wycofania, „Infrastruktura”); razem z nim `docs/features/walking-skeleton/spec.md` (opis `setup`).
2. Epik: model R (M1–M6), W19a, testy z kryteriów 10–12, scenariusz zapisów w `load/`; w tym samym PR
   `docs/features/walking-skeleton/spec.md` („Ograniczenia tej przebudowy” bez punktu o zapisach w trakcie
   przebudowy), `infra/k8s/README.md` (okno serwisowe zmiany definicji, wycofanie, przebudowa pod ruchem dozwolona),
   wartości chartu (`elasticsearch.shards` 1 w `prod`, `elasticsearch.refreshInterval`), `load/README.md` i „Status”
   w `docs/features/performance-program/plan.md`.
3. Po merge'u, każdy krok za zgodą właściciela: wdrożenie i przebudowa na worf.pl (okno serwisowe), pomiary
   z kryterium 13, raport w `docs/reports/`. Pomiar akceptacyjny czeka na profil `server` (10 mln) i pamięć z PERF-1
   na worf.pl; pomiary lokalne i każdy pomiar na worf.pl są uzgadniane z sesją PERF-0.

## Ryzyka i ograniczenia

- Zmiana definicji indeksu (M7): lokalny stos z indeksem z poprzedniej definicji startuje z ostrzeżeniem `setup`,
  a listing nie działa poprawnie do `catalog:reindex` (jednorazowo `docker compose exec api bin/console
  catalog:reindex`). Świeże stosy (CI) nie są dotknięte. Na worf.pl to okno serwisowe („Infrastruktura”).
- Model R opiera się na regułach domeny (najwyżej 2 osie, unikalne krotki opcji); ich zmiana, np. trzecia oś
  wariantu, wymaga zmiany modelu indeksu. Pilnują tego testy reguł i odmowa budowy dokumentu (M1).
- Dokładne fasety liczone przy każdym żądaniu kosztują liniowo w liczbie pasujących produktów (cały katalog 10 mln:
  ok. 2 s CPU serwera według etapu A); etap A szacuje, że sam model nie spełni W8 i W9 (M8). Listingi z filtrem ceny
  i opcją na całym katalogu zostają złączeniem `nested`.
- Listing z ponad 10 000 różnych tokenów (kategorie, osie i wartości razem) liczy fasety w trzech kolejnych
  `_msearch`, czyli wolniej. Dane `server` mają ok. 400 tokenów (180 kategorii, 21 osi, 208 wartości).
- Fasety w request cache są ważne do odświeżenia indeksu; przy ciągłych zapisach żyją tyle co `refresh_interval`
  (M4, warunkowo 5 s).
- Wartości osi są wolnym tekstem: różne formy („czarne”, „czarna”) i różna wielkość liter tworzą osobne wartości fasety.
- Cena „od” na kafelku i sortowanie po cenie dotyczą całego produktu, a filtr i podpowiedzi ceny wariantów
  spełniających filtry: przy „Cena 100–150 zł” kafelek może pokazać „od 90 zł” (tańszy wariant poza filtrem osi).
- Fraza łącząca słowo bez polskich znaków z formą odmienioną innego słowa może nie dać wyniku (`cross_fields` grupuje
  pola po analizatorze); typowe przypadki są w testach.
- Reindeksacja po każdej zmianie ceny i poziomu dostępności to jedno zapytanie oferty i jeden zapis ES na produkt;
  przy dużym ruchu rezerwacji (CHECKOUT) to obciążenie workera. Bez kolejkowania i łączenia zmian.
- Przynależność do filtrów ceny i dostępności jest ewentualnie spójna (reindeksacja + do 60 s TTL Varnisha).
- Dokumenty szkiców zostają w indeksie (tombstone): indeks rośnie o szkice, a każde nowe zapytanie sklepu musi mieć
  filtr `published` (bez niego pokazałoby szkice; pilnują tego testy listy i faset).
- Wersja dokumentu z zegara: przy kilku workerach na różnych hostach przesunięcie zegarów może przepuścić starszy
  odczyt; skutek naprawia następna zmiana albo przebudowa.
- Fasety są dokładne, bo indeks ma jeden shard (M3): przy kilku shardach `terms` zwracałby z każdego shardu tylko
  `shard_size` kubełków, a kolejność „Trafność” zależałaby od statystyk słów każdego shardu. Przejście worf.pl
  z 4 shardów na 1 może więc zmienić kolejność wyników frazy; liczby trafień i fasety się nie zmieniają.
- Produkt ma najwyżej 100 wariantów, czyli do 100 obiektów zagnieżdżonych, daleko od limitu
  `index.mapping.nested_objects.limit` (10 000).
- Obraz Elasticsearch budowany lokalnie pobiera wtyczkę z sieci przy pierwszym buildzie.
