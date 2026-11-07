# SEARCH: wyszukiwarka i listing z filtrami

Punkt 4 „Zakresu MVP” z `docs/vision.md`: wyszukiwarka i listing kategorii z filtrami na Elasticsearch, mega menu
dla wielu działów, breadcrumby i SEO listingu.

Issues: #94
Zakres: `apps/api/src/Catalog`, `apps/api/src/Cart/Infrastructure`, `apps/api/src/Pricing/Infrastructure`, `apps/api/src/Inventory/Infrastructure`, `apps/api/tests`, `apps/api/config`, `apps/api/fixtures/catalog.yaml`, `apps/api/openapi.json`, `apps/api/openapi-admin.json`, `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts`, `apps/store/src/catalog`, `apps/store/src/components/ui`, `apps/store/app`, `infra/docker-compose.yml`, `infra/elasticsearch`, `Makefile`, `.github/workflows/ci.yml`, `e2e/tests/catalog.spec.ts`, `e2e/tests/price-change.spec.ts`, `e2e/tests/stock-receipt.spec.ts`, `e2e/tests/cart-variants.spec.ts`, `e2e/tests/product-page.spec.ts`, `e2e/tests/search.spec.ts`, `e2e/tests/listing-filters.spec.ts`, `e2e/tests/site-header.spec.ts`, `e2e/support`, `docs/vision.md`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `docs/features/walking-skeleton/spec.md`, `docs/features/catalog-page-metadata/spec.md`, `docs/features/catalog-unknown-category/spec.md`, `docs/features/product-page/spec.md`, `docs/features/variants/spec.md`

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
- docelowy wygląd listingu i menu (VISUAL-DESIGN #97);
- podpowiedzi przy wpisywaniu (autocomplete), „czy chodziło ci o”, historia wyszukiwań, wyszukiwanie po kodach;
- marka jako osobne pole i filtr marki (Catalog nie ma pola marki; marka jest w nazwie produktu i działa w wyszukiwaniu);
- sortowanie po popularności i nowościach (brak danych);
- `sitemap.xml`, `robots.txt`, JSON-LD `ItemList` listingu;
- ISR stron listingu (lista renderuje się przy każdym żądaniu i czyta API przez Varnisha, S4);
- edycja kategorii i przekierowania po zmianie sluga kategorii (kategorie powstają tylko w danych demo; CATALOG-ADMIN #95);
- strona główna z treścią marketingową (VISUAL-DESIGN #97).

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

## Przepływ danych

```
sklep (SSR /, /c/<ścieżka>, /szukaj) → Varnish (API_CATALOG_URL) → GET /api/v1/categories, GET /api/v1/products
                                                                    (q, filtry, sort, fasety) → Elasticsearch
ProductCreated ─────────────────────────────→ Catalog IndexProduct ──┐
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
| `variants` | `nested` | jeden obiekt na wariant: `gross` (`long`, brak przy braku ceny), `purchasable` (`boolean`), `options` (`keyword`, pary `<oś>␟<wartość>`, separator U+001F; na nich dopasowuje się warunek wariantu), `pairs` (`nested` w wariancie: obiekt `{axis, value}` (`keyword`) na każdą parę; po nich liczą się fasety osi) |
| `from_gross` | `long` | cena „od” (najniższa cena wariantów możliwych do kupienia); brak, gdy produkt nie ma takiego wariantu |

Pozostałe pola (`id`, `version`, `slug`, `main_category_id`, `category_ids`, `category_ancestor_ids` z filtrem
kategorii, `main_image`) służą do filtra kategorii i do wyświetlenia listy.

- Analizator `polish_text`: tokenizer standardowy, `lowercase`, `polish_stem` (wtyczka `analysis-stempel`, A2),
  `asciifolding`. Podpola `folded`: tokenizer standardowy, `lowercase`, `asciifolding` (bez stemmera):
  łapie frazy bez polskich znaków i formy, których stemmer nie sprowadza.
- `options` i `pairs` wariantu: te same pary oś–wartość, dokładnie jak w Catalog (bez zmiany wielkości liter); osie
  i wartości nie mają identyfikatorów, więc indeks używa ich nazw. Wariant domyślny (produkt bez osi) ma puste
  `options` i `pairs`. `pairs` jest zapisywane z `options` przy zapisie dokumentu, nie jest czytane z powrotem.
- `gross`, `purchasable` i `from_gross` pochodzą z portu `ProductOfferFacts` (A1). Reguła „możliwy do kupienia”
  (`VariantOffer::isPurchasable`: cena istnieje i `available > 0`) i cena „od” (`ProductOfferSummary`) są tylko w Cart,
  więc indeks liczy je tak samo jak kafelek.
- Zmiana definicji zmienia `definition_hash`, więc `catalog:search-index:setup` na istniejącym indeksie kończy się
  błędem z instrukcją `catalog:reindex` (patrz „Ryzyka”).

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
  `ProductOfferFacts`) i zapisuje dokument; produkt, którego nie ma w bazie, daje wynik „brak produktu”. Nie idzie
  przez `command.bus` (tylko czyta bazę, więc bez transakcji `doctrine_transaction`).
- Wersja dokumentu: znacznik czasu w mikrosekundach pobrany przed odczytem stanu, `version_type=external_gte`.
  Odczyt rozpoczęty później widzi co najmniej te same zatwierdzone dane, więc przy dwóch równoległych obsługach
  wygrywa nowszy odczyt; starszy zapis to wynik „przestarzały”.
- `ProductSearchSource` czyta migawkę produktu, w tym warianty (`variantId`, wartości osi) i nazwy kategorii.
- Przebudowa (`catalog:reindex`) pobiera fakty ofert dla każdej paczki produktów; błąd faktów przerywa przebudowę.

### Wyzwalacze

- `ProductCreated` → `IndexProduct` (handler deleguje do `ProductIndexing`; „brak produktu” kończy się `failed`:
  katalog nie ma usuwania, więc to niespójność, a nie powód do ponowienia).
- `Pricing_Infrastructure`: `ReindexProductOnPriceChange` na `VariantPriceChanged` (transport `events`, niezależny od
  konsumenta unieważniania z PRODUCT-PAGE) → `productId` z Catalog `GetVariant` → `ProductIndexing::reindex`. Wariant nieznany
  Catalogowi albo „brak produktu”: wiadomość obsłużona. Błąd przejściowy (ES, oferta) → ponowienie, trwały → `failed`.
- `Inventory_Infrastructure`: `ReindexProductOnStockLevelChange` na `StockLevelChanged`, tak samo.
- Zdarzenia ze źródła `seed` oba handlery potwierdzają bez reindeksacji: `make seed` po seedach katalogu, cen, zamówień
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
| `q` | po przycięciu 2–100 znaków | fraza (S8) |
| `option` | powtarzany `<oś>:<wartość>` (podział na pierwszym `:`), do 50 wpisów, do 5 różnych osi; oś 1–30, wartość 1–40 znaków; forma `option[]` jest odrzucana | wartości osi (S2) |
| `priceMin`, `priceMax` | napis z samych cyfr (do 12), grosze, `priceMin ≤ priceMax` | zakres ceny brutto (S2) |
| `available` | `true` | tylko produkty z wariantem możliwym do kupienia (S2) |
| `sort` | `relevance` (tylko z `q`), `name`, `price_asc`, `price_desc` | domyślnie `relevance` z `q`, inaczej `name` |
| `page`, `perPage` | `page` ≥ 1, `perPage` 1–48 (domyślnie 24) | strona wyników |

### Zapytanie

- `query`: `category` (filtr `term` na `category_ancestor_ids`) i fraza. Bez frazy `match_all`. Fraza: `bool.should` z
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
- Warunek wariantu (S2) to jedno zapytanie `nested` na `variants`, w którym wszystkie warunki dotyczą tego samego
  wariantu: dla każdej osi z `option` `terms` na `variants.options` (wartości tej osi); przy `priceMin`/`priceMax`
  `purchasable` i `gross` w zakresie; przy `available` `purchasable`. Bez żadnego z tych parametrów brak warunku.
- Warunek wariantu idzie do `post_filter`, a nie do `query`, żeby fasety mogły liczyć wyniki bez części warunków.
- Produkt bez wariantu możliwego do kupienia (`from_gross` brak) jest w wynikach bez `priceMin`, `priceMax`
  i `available`; którykolwiek z nich go wyklucza. Nie wchodzi do `facets.price`.
- Sortowanie: `relevance` → `_score`, potem `name.sort`, `id`; `name` → `name.sort`, `id`; `price_asc`/`price_desc` →
  `from_gross` (brakujące na końcu), potem `name.sort`, `id`.
- Okno wyników to 10 000 (`index.max_result_window`): strona zaczynająca się za oknem jest pusta z prawdziwym `total`.
  `total` jest dokładny i uwzględnia `post_filter`.

### Fasety

Odpowiedź `ProductPage` ma pole `facets`, liczone agregacjami w tej samej odpowiedzi ES. Agregacje widzą wyniki
`query` (fraza, kategoria); każda faseta dodaje warunek wariantu bez swojego warunku (gałąź `filter` na każdą
wybraną oś, cenę i `available`):

- `categories`: dzieci kategorii z filtra (bez filtra: kategorie główne), które mają pasujące produkty: `{id, name,
  slug, path, count}`, w kolejności drzewa. Liczone z pełnym warunkiem wariantu.
- `options`: osie występujące w wynikach, `{axis, values: [{value, count}]}`. Liczba przy wartości v osi X to liczba
  produktów, które mają wariant z wartością v spełniający wszystkie pozostałe warunki wariantu (inne osie, cena,
  dostępność), czyli `total` po dodaniu v do filtra (dla osi z wyborem: po wybraniu tylko v tej osi, bo liczba jest
  liczona bez warunku własnej osi). Bez globalnego limitu par oś–wartość; limity dotyczą tylko
  wyniku: 10 osi bez wyboru i 30 wartości na oś.
  - Osie bez wyboru: jedna agregacja `nested` na `variants` → filtr wszystkich warunków wariantu → `nested` na
    `variants.pairs` → `terms` na `axis` (10, bez osi wybranych) → w nim `terms` na `value` (30). Warunki i para
    należą do tego samego wariantu, więc nie ma kombinacji z różnych wariantów. Każdy `terms` sortuje po liczbie
    produktów z `reverse_nested` do korzenia (produkt raz, nie raz na obiekt zagnieżdżony), malejąco, potem po `_key`
    rosnąco.
  - Oś wybrana (z `option`): osobna agregacja z warunkami wariantu bez warunku tej osi → `nested` na `pairs` → filtr
    `axis` = oś → `terms` na `value` (30) i `terms` z wybranymi wartościami (żeby wybrana wartość ucięta limitem miała
    prawdziwą liczbę). Wybrana wartość jest zawsze w liście, także z liczbą 0; ucięta limitem dochodzi po pozostałych,
    więc lista może przekroczyć 30.
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
- „Nieprawidłowy filtr” ma link „Zobacz cały katalog” (`/`), a na `/c/` i `/szukaj` także „Pokaż wyniki bez filtrów”:
  ten sam listing bez filtrów i sortowania (na `/szukaj` z frazą), bo sklep nie wie, który parametr jest błędny.

### Widok listingu (tymczasowy motyw, komponenty shadcn/ui)

- Breadcrumby: „Wszystkie produkty” › kategorie ścieżki (linki `/c/<ścieżka>`; ostatni element bez linku); na
  `/szukaj`: „Wszystkie produkty” › „Wyniki wyszukiwania” (z `kategoria` ten element jest linkiem, po nim ścieżka
  kategorii). Na `/` breadcrumbów nie ma.
- `h1`: nazwa kategorii, „Wszystkie produkty” albo `Wyniki dla „<fraza>”`; obok liczba produktów z odmianą
  („1 produkt”, „2–4 produkty” i 22–24 itd., pozostałe „produktów”).
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

- `/c/<ścieżka>`: tytuł `<nazwa kategorii>[ – strona n] | Rytm`, opis „Produkty z kategorii <nazwa> w sklepie Rytm.”,
  kanoniczny `<STORE_URL>/c/<ścieżka>` (`?page=n` przy n > 1). `/`: tytuł `Wszystkie produkty[ – strona n] | Rytm`,
  kanoniczny `<STORE_URL>/` (`?page=n` przy n > 1).
- Którykolwiek z parametrów `sort`, `cena-od`, `cena-do`, `dostepne`, `o` → `robots: noindex, follow` i kanoniczny
  listingu bez nich i bez `page`.
- `/szukaj`: `noindex, follow`, bez adresu kanonicznego, tytuł `Wyniki dla „<fraza>”[ – strona n] | Rytm` (bez frazy
  albo z za krótką `Wyszukiwanie | Rytm`). Wyjątek: „Nieprawidłowy filtr” ma samo `noindex`.
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
  `make test`), wersja `external_gte` (starszy odczyt przestarzały), przebudowa z faktami ofert.
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

## Ryzyka i ograniczenia

- Zmiana definicji indeksu: lokalny stos z indeksem zbudowanym z poprzedniej definicji kończy `make up` błędem
  `catalog:search-index:setup` z instrukcją `catalog:reindex` (jednorazowo `docker compose exec api bin/console
  catalog:reindex`, potem `make up`). Świeże stosy (AO, CI) nie są dotknięte.
- Zapisy w trakcie przebudowy: zmiana ceny albo stanu obsłużona w czasie `catalog:reindex` trafia do starego indeksu
  i znika po przepięciu aliasu, aż do następnej zmiany produktu (przebudowa nie jest online).
- Wartości osi są wolnym tekstem: różne formy („czarne”, „czarna”) i różna wielkość liter tworzą osobne wartości fasety.
- Cena „od” na kafelku i sortowanie po cenie dotyczą całego produktu, a filtr i podpowiedzi ceny wariantów
  spełniających filtry: przy „Cena 100–150 zł” kafelek może pokazać „od 90 zł” (tańszy wariant poza filtrem osi).
- Fraza łącząca słowo bez polskich znaków z formą odmienioną innego słowa może nie dać wyniku (`cross_fields` grupuje
  pola po analizatorze); typowe przypadki są w testach.
- Reindeksacja po każdej zmianie ceny i poziomu dostępności to jedno zapytanie oferty i jeden zapis ES na produkt;
  przy dużym ruchu rezerwacji (CHECKOUT) to obciążenie workera. Bez kolejkowania i łączenia zmian.
- Przynależność do filtrów ceny i dostępności jest ewentualnie spójna (reindeksacja + do 60 s TTL Varnisha).
- Wersja dokumentu z zegara: przy kilku workerach na różnych hostach przesunięcie zegarów może przepuścić starszy
  odczyt; skutek naprawia następna zmiana albo przebudowa.
- Fasety na 10 mln produktów (agregacje `nested` z `reverse_nested`) nie są potwierdzone testami obciążeniowymi.
- `pairs` to obiekty `nested` w `nested`: każda para jest osobnym dokumentem Lucene, więc indeks ma więcej dokumentów
  (w danych demo ok. 2,4 raza) i jest większy. Produkt ma najwyżej 100 wariantów po 2 osie, czyli do 300 obiektów
  zagnieżdżonych, daleko od limitu `index.mapping.nested_objects.limit` (10 000).
- `terms` sortowane po liczbie z `reverse_nested` jest dokładne przy jednym shardzie (`app.search.shards`). Przy kilku
  shardach wymagałoby `shard_size` nie mniejszego niż liczba różnych osi i wartości na shardzie.
- Obraz Elasticsearch budowany lokalnie pobiera wtyczkę z sieci przy pierwszym buildzie.
