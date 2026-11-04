# VARIANTS: warianty produktów

Epik dla danych demo z wariantami (sekcja „Dane demo” w `docs/vision.md`) i podstawy checkoutu. Produkt w Catalog ma
warianty (np. kolor i rozmiar), a cena (Pricing), stan (Inventory) i pozycja koszyka (Cart) dotyczą wariantu, nie
produktu. Działanie Pricing, Inventory i Cart opisują ich specyfikacje (`docs/features/pricing/spec.md`,
`docs/features/inventory/spec.md`, `docs/features/cart/spec.md`); ta specyfikacja opisuje warianty i zmiany w tych
funkcjach.

Issues: #89

Epik rusza po zmergowaniu CART-FIXES (#88) do `main`: opisuje koszyk po jego zmianach (`ProductOffer::purchasability`,
Web Locks w `src/api/client.ts`, `CartPage` po #81).

## Cel i zakres

- Produkt ma 1–100 wariantów. Wariant to jedna kombinacja wartości osi wariantów produktu (0–2 osie, np. „Kolor”,
  „Rozmiar”, „Smak”). Produkt bez osi ma jeden wariant domyślny.
- Klucz wariantu (`variantId`) zastępuje klucz produktu w Pricing (cena i historia cen), Inventory (stan, przyjęcia,
  korekty, rezerwacje, historia) i Cart (linie koszyka).
- Sklep: kafelek produktu na liście pokazuje cenę „od” i dostępność produktu liczone z wariantów możliwych do kupienia,
  pozwala wybrać wariant i dodać go do koszyka; koszyk pokazuje wariant linii.
- Panel: listy cen i stanów mają wiersz na wariant; zmiana ceny, przyjęcie, korekta i historie dotyczą wariantu.
- Dane demo: produkt = marka × model szablonu, a dotychczasowe „kolory” szablonu (kolory, smaki, oprawy, porcje) są
  wartościami osi wariantów, z rozmiarami tam, gdzie towar je ma. Ceny i stany demo per wariant.
- Migracja istniejących danych bez utraty: każdy istniejący produkt dostaje wariant domyślny o identyfikatorze równym
  identyfikatorowi produktu, więc istniejące ceny, historia cen, stany, rezerwacje i koszyki zostają ważne. Migracja
  i nowe dane demo to dwie osobne operacje (sekcja „Migracja a dane demo”).

Poza zakresem:
- strona produktu i wybór wariantu w docelowym wyglądzie (PRODUCT-PAGE #93, VISUAL-DESIGN #97);
- zamówienia (CHECKOUT #90): kontrakt rezerwacji dostaje klucz wariantu, zamówień w tym epiku nie ma;
- dodawanie i edycja produktów i wariantów w panelu (CATALOG-ADMIN #95); warianty powstają tylko w danych demo
  i w migracji;
- wyszukiwanie i filtry po wartościach wariantów (SEARCH #94); indeks Elasticsearch bez zmian;
- zdjęcia per wariant i galeria, kody SKU i EAN, cena „od” w Varnishu i ISR;
- usuwanie wariantu i zmiana osi istniejącego produktu.

## Decyzje użytkownika

| # | Decyzja |
|---|---|
| D1 | 0–2 osie wariantów o nazwach ustalonych w produkcie („Kolor”, „Rozmiar”, „Smak”, „Oprawa”…). |
| D2 | Dotychczasowe „kolory” szablonów demo są wartościami osi wariantu; 191 produktów demo, około 1100 wariantów. |
| D3 | Warianty są encjami agregatu `Product` (Catalog). |
| D4 | Produkt ma zawsze ≥ 1 wariant; produkt bez osi ma wariant domyślny. Ten sam klucz wariantu wszędzie. |
| D5 | Migracja: wariant domyślny o `id` produktu, przemianowanie kolumn; identyfikatory i powiązania istniejących cen, historii cen, stanów, rezerwacji, historii stanów i koszyków zostają. Dane demo przebudowuje osobno `make seed`. |
| D6 | Cena na kafelku: „od X zł” z najniższej ceny wariantów **możliwych do kupienia** (aktualna cena i dostępny stan); „X zł”, gdy te ceny są równe. |
| D7 | Dostępność na kafelku: najlepszy poziom spośród wariantów **możliwych do kupienia**; brak takich wariantów: „Niedostępny”. |
| D8 | Kafelek z ≥ 2 wariantami: lista bez wyboru domyślnego, warianty niemożliwe do kupienia z dopiskiem „niedostępny” i bez możliwości wyboru, „Do koszyka” aktywne po wyborze. |
| D9 | Limity koszyka: 50 różnych wariantów, 99 sztuk jednego wariantu. |
| D10 | Panel: wiersz na wariant pod nazwą swojego produktu, kolumna „Wariant”, stronicowanie po produktach. |
| D11 | Osie i wartości w `jsonb`; unikalność kombinacji wartości w produkcie pilnuje domena i unikalny indeks w bazie. |
| D12 | Komunikaty limitów koszyka: „…jednej pozycji”, „…50 różnych pozycji”. |
| E1 | Ofertę produktu listy (D6, D7) liczy zapytanie w Cart (`GetProductOffers`), z regułą zakupu z `ProductOffer`; bez nowego modułu i bez zmian w Deptrac. `/prices` i `/availability` znikają. |
| E2 | Zdjęcie jest zdjęciem produktu (poglądowym); wybór wariantu go nie zmienia. Rozjazd koloru na zdjęciach demo jest świadomy i zapisany w ryzykach. |
| E3 | Produkt bez wariantu możliwego do kupienia: kafelek „Niedostępny” bez ceny i bez przycisku. |

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Catalog | osie i warianty produktu, etykieta wariantu, zapytania o warianty dla Pricing, Inventory, Cart i listy produktów |
| Pricing | cena i historia ceny wariantu |
| Inventory | stan, rezerwacje i historia wariantu, poziom dostępności wariantu |
| Cart | linie koszyka per wariant; oferta produktów listy (warianty możliwe do kupienia, cena „od”, poziom produktu) |
| Sklep, panel | wyświetlanie wartości z API; żadnych obliczeń cen „od” ani poziomów |

- Kierunki zależności bez zmian: `Pricing_Infrastructure`, `Inventory_Infrastructure` i `Cart_Infrastructure` wołają
  zapytania `Catalog_Application` przez `query.bus`. Catalog nie zależy od żadnego z nich. `deptrac.yaml` bez zmian.
- Każdy context ma własny value object `VariantId` (UUID, małe litery). `ProductId` zostaje w Pricing i Inventory
  tylko w listach panelu (`DirectoryProduct`, wiersz produktu z wariantami) i w kontrakcie przyszłej zmiany klasy
  podatkowej produktu (Pricing); w Cart tylko w ofercie produktów listy i w `CartView.lines[].product`.

## Catalog

### Model domenowy

- `VariantId`: UUID.
- `VariantAxes`: lista 0–2 nazw osi w kolejności (np. `["Kolor", "Rozmiar"]`); nazwa 1–30 znaków po przycięciu,
  unikalna bez względu na wielkość liter (`InvalidVariantAxes`, `InvalidInput`).
- `VariantValues`: lista wartości, po jednej na oś, w kolejności osi; wartość 1–40 znaków po przycięciu.
- Encja `ProductVariant {id: VariantId, values: VariantValues}` w agregacie `Product`.
- `Product` dostaje `variantAxes` i `variants` (`ProductVariants`, kolejność = kolejność prezentacji). Niezmienniki
  (`InvalidProductVariants`, `InvalidInput`):
  - 1–100 wariantów;
  - liczba wartości każdego wariantu = liczba osi;
  - kombinacje wartości są unikalne w produkcie (porównanie po przycięciu, bez względu na wielkość liter):
    `DuplicateVariantValues` (`InvalidInput`);
  - bez osi: dokładnie jeden wariant (domyślny, pusta lista wartości);
  - identyfikatory wariantów są unikalne (w produkcie i, przez klucz główny tabeli, w całym katalogu).
- `Product::create` przyjmuje osie i warianty; `reconstitute` je odtwarza. Wariant nigdy nie zmienia produktu.
- Komenda `CreateProduct` (używa jej tylko seed, `DemoCatalogLoader`) dostaje `variantAxes: list<string>` i
  `variants: list<{id, values: list<string>}>`. Identyfikator wariantu zajęty przez inny produkt:
  `VariantAlreadyExists` (`Conflict`). `DoctrineProductRepository::save` mapuje naruszenie klucza głównego
  `catalog_product_variant` na ten wyjątek, tak jak dziś naruszenie unikalnego slugu na `SlugAlreadyTaken`.
- Etykieta wariantu (`label`): wartości złączone „, ” (np. „czarne, 42”); wariant domyślny ma pustą etykietę.
  Etykietę buduje jedna funkcja w Catalog (`ProductVariant::label()`), używana przez wszystkie zapytania.

### Persystencja

- `catalog_product.variant_axes jsonb NOT NULL` (lista nazw osi).
- `catalog_product_variant`: `id uuid` (PK), `product_id uuid` (FK → `catalog_product` `ON DELETE CASCADE`),
  `position int`, `option_values jsonb NOT NULL` (lista napisów, zapisanych po przycięciu przez domenę),
  `values_key text GENERATED ALWAYS AS (lower(option_values::text)) STORED`; unikalne `(product_id, position)`
  i `(product_id, values_key)`. Kolumnę `values_key` liczy PostgreSQL, więc baza odrzuca powtórzoną kombinację także
  przy zapisie z pominięciem domeny (kanoniczny tekst `jsonb` nie wymaga separatora). Rekord mapuje kolumnę jako
  generowaną (bez zapisu z PHP); `doctrine:schema:validate` i diff migracji bez różnic sprawdza zadanie. Test
  integracyjny sprawdza, że `lower()` PostgreSQL i porównanie w domenie zgadzają się dla polskich liter
  („Żółty” i „żółty”).
- Rekord `ProductVariantRecord` z relacją do `ProductRecord`, mapowanie w `DoctrineProductRepository`.
- Reset katalogu (`DbalCatalogReset`) czyści też warianty.
- Zdjęcia zostają na poziomie produktu (decyzja E2): wariant nie ma zdjęć, a wybór wariantu nie zmienia zdjęcia.
- Migracja (nowa): tabela i kolumna (`variant_axes = '[]'`), a dla każdego istniejącego produktu jeden wariant
  domyślny `id = catalog_product.id`, `position = 0`, `option_values = '[]'`. Dzięki temu `product_id` zapisane
  dziś w Pricing, Inventory i Cart jest identyfikatorem wariantu domyślnego tego produktu.

### Migracja a dane demo

To dwie osobne operacje.

1. **Migracje** (`make migrate`, `make init`, każde wdrożenie) działają na każdej bazie i niczego nie usuwają.
   Catalog dodaje każdemu istniejącemu produktowi wariant domyślny o `id` produktu. Pricing, Inventory i Cart
   przemianowują kolumnę `product_id` na `variant_id` bez zmiany wartości. Każda cena, wpis historii cen, stan,
   wpis historii stanów, linia rezerwacji, klucz przyjęcia i linia koszyka wskazuje po migracji wariant domyślny
   swojego dawnego produktu, z tym samym identyfikatorem. Kolejność migracji: Catalog przed pozostałymi (bez kluczy
   obcych między contextami, więc kolejność dotyczy tylko spójności danych). Test migracji na bazie z danymi sprzed
   zmiany sprawdza każdy z tych rekordów.
2. **Dane demo** (`make seed`) to świadomy reset, jak dziś: `catalog:seed-demo`, `pricing:seed-demo` i
   `inventory:seed-demo` czyszczą swoje tabele i ładują katalog z wariantami od nowa (w `prod` tylko z
   `--force-reset`). Nowe produkty demo mają nowe identyfikatory (slug bez koloru), bo jeden dawny produkt
   (np. „… czarne”) to teraz wiele wariantów nowego produktu i nie ma odwzorowania 1:1. Koszyki nie są czyszczone
   przez seed (jak dziś): linia wskazująca wariant, którego po resecie nie ma, ma stan `unavailable_product`.

### Zapytania (`query.bus`, DBAL, jedno zapytanie na wywołanie)

Nowe:
- `GetVariantSummaries(variantIds)` (1–100 id; inaczej `InvalidVariantSummariesQuery`) → w kolejności żądania, dla
  wariantów obecnych w bazie (powtórzone id raz): `{variantId, productId, name, label, image|null}`; zdjęcie główne
  produktu w rozmiarze `listing`, jak `GetProductSummaries`.
- `GetVariant(variantId)` → `{variantId, productId, taxClass}` albo `null`.
- `ListProductVariants(productIds)` (1–100 id) → `{productId, variantAxes, variants: [{variantId, label}]}`, warianty
  w kolejności pozycji, dla produktów obecnych w bazie. Używają go Pricing i Inventory (listy panelu) oraz Cart
  (oferta produktów listy).
- `ListVariantIdentities(after, limit)` → `{variantId, key, taxClass}` partiami po `variantId`, dla seeda i reconcile
  Pricing oraz seeda Inventory (porty po stronie Pricing i Inventory zostają osobne).
  `key` = `<slug produktu>/<slug etykiety>` (wariant domyślny: `<slug produktu>/standard`), używany przez wzorce
  danych demo. `key` buduje jedna funkcja Catalog, której używają expander danych demo i to zapytanie.

Bez zmian: `ListProducts` i `ProductListItem` (warianty kafelka pochodzą wyłącznie z oferty produktów listy w Cart;
indeks Elasticsearch bez zmian) oraz `SearchBackofficeProducts` (listy panelu stronicują po produktach, istniejący
kursor i indeks `(name, id)`).

Usuwane (jedynymi użytkownikami są adaptery przepinane w tym epiku): `GetProductSummaries`, `GetProductExists`,
`GetProductTaxClass`, `ListProductsForPricing`, `ListProductIdentities`.

### Dane demo (`fixtures/catalog.yaml`, `catalog:seed-demo`)

- Szablon dostaje `variantAxes` (0–2 nazwy) i listy wartości osi (`axisValues`, w kolejności osi); warianty to iloczyn
  wartości w kolejności pliku. Produkt = marka × model; nazwa i slug bez koloru (np. „Buty szosowe Tarvik Asfalt 200”).
- Identyfikatory: produkt UUID v5 `product:<slug>`, wariant UUID v5 `variant:<key>`. Expander odrzuca powtórzony
  `key` (np. etykiety „1,5” i „1.5”) tak jak dziś powtórzony slug.
- Osie demo:

| Typ | Osie | Wartości |
|---|---|---|
| Buty szosowe, trailowe, piłkarskie | Kolor, Rozmiar | kolory jak dziś × 40–46 |
| Kurtka biegowa, Koszulka biegowa, Kurtka narciarska, Pianka pływacka | Kolor, Rozmiar | kolory jak dziś × S, M, L, XL |
| Rower górski, Rower elektryczny | Kolor, Rozmiar ramy | kolory jak dziś × S, M, L |
| Kask rowerowy, Kask wielosportowy | Kolor, Rozmiar | kolory jak dziś × S, M, L |
| Narty zjazdowe | Kolor, Długość | kolory jak dziś × 160 cm, 170 cm, 180 cm |
| Narty skiturowe | Kolor, Długość | kolory jak dziś × 164 cm, 172 cm, 180 cm |
| Przewodnik | Oprawa | oprawa miękka, oprawa twarda |
| Mapa turystyczna | Wersja | składana, laminowana |
| Danie liofilizowane | Porcja | jednoosobowa, dwuosobowa |
| Baton, Żel, Odżywka, Elektrolity | Smak | smaki jak dziś |
| Hantle regulowane | brak osi | wariant domyślny |
| pozostałe | Kolor | kolory jak dziś |

- `fixtures/prices.yaml` i `fixtures/stock.yaml`: wzorce dopasowują `key` wariantu (`*` dopasowuje także `/`),
  zasada „pierwszy pasujący” bez zmian. Ceny różnią się między wariantami co najmniej dla Przewodnika (oprawa),
  Mapy (wersja) i Dania (porcja); w pozostałych produktach warianty mają cenę modelu. Stany: większość wariantów
  20–50, kilka 2–3, kilka 0. Wszystkie warianty produktu „Kajak turystyczny Vilmar Rzeka 2” mają 0 (używa go E2E,
  komentarz w `stock.yaml` jak dziś); co najmniej jeden produkt „Hantle regulowane” (wariant domyślny) ma stan > 0
  (używa go E2E zmiany ceny, komentarz w `stock.yaml`); co najmniej jeden inny produkt ma warianty o stanie 0 i > 0.
- `catalog:seed-demo` czeka na indeks jak dziś (liczba produktów).

## Pricing

- Agregat ceny i historia są per wariant: `VariantId` zastępuje `ProductId` w agregacie (`ProductPrice` →
  `VariantPrice`), `PriceSnapshot`, wpisach historii i portach. Obliczenia, zakres, wersja, źródła, reconcile bez zmian.
- Persystencja (nowa migracja): `pricing_product_price` → `pricing_variant_price`, kolumna `product_id` → `variant_id`
  (PK); `pricing_price_history.product_id` → `variant_id`, indeks `(variant_id, changed_at DESC, id)`. Wartości kolumn
  zostają (wariant domyślny ma `id` produktu).
- `SetVariantPrice(variantId, gross, expectedVersion|null, actorId, actorName)`: klasa podatkowa z Catalog
  `GetVariant` (klasa produktu wariantu). Nieznany wariant: `VariantNotFound` (404, „Wariant produktu nie
  istnieje.”). Reszta jak `SetProductPrice`.
- `GetCurrentPrices(variantIds)` (1–4800, czyli 48 produktów × 100 wariantów; jedno zapytanie `ANY`): jak dziś,
  kluczem jest wariant. Autorytatywny odczyt dla Cart i przyszłego Ordering.
- Publiczny `GET /api/v1/prices` jest usuwany (jedynym konsumentem była lista sklepu; zastępuje go oferta produktów
  w Cart).
- `ListBackofficePrices`: strona produktów z portu `ProductDirectory` (Catalog `SearchBackofficeProducts`, kursor po
  produktach jak dziś), ich warianty z etykietami jednym zapytaniem (Catalog `ListProductVariants`) i ceny wariantów
  jednym zapytaniem. Wynik: produkty strony, a w każdym jego warianty w kolejności pozycji.
- `GetPriceHistory(variantId, …)`: nieznany wariant 404; wariant bez ceny: pusta lista.
- `ReconcileTax`: przechodzi ceny wariantów; klasa z Catalog `ListVariantIdentities`.
- Kontrakt „Przyszła zmiana klasy podatkowej”: `reprice` wszystkich cen wariantów produktu w tej samej transakcji.
- Seed: `ListVariantIdentities`, wzorce po `key`; wariant bez wzorca kończy seed błędem bez zmian.

## Inventory

- `StockItem`, `Reservation` (linie), `StockMovement` i porty używają `VariantId` zamiast `ProductId`. Niezmienniki,
  limity, blokady, idempotencja i kryteria C1–C6 bez zmian; `lockMany` blokuje w kolejności rosnącego `variant_id`.
- Persystencja (nowa migracja): `product_id` → `variant_id` w `inventory_stock_item` (PK),
  `inventory_reservation_line` (PK `(reservation_key, variant_id)`) i `inventory_stock_movement` (indeks
  `(variant_id, occurred_at DESC, id)`); wartości zostają.
- Komendy `ReceiveStock`, `CorrectStock`, `CreateEmptyStock` przyjmują `variantId`; nieznany wariant (Catalog
  `GetVariant`): `VariantNotFound` (404). Klucz przyjęcia seeda: `seed:<variantId>`.
- `ReserveStock` (kontrakt dla Ordering): linie `{variantId, quantity}`, bez powtórzonego wariantu.
- `GetStockLevels(variantIds)` (1–4800; jedno zapytanie `ANY`): jak dziś, kluczem jest wariant; `level` z
  `AvailabilityLevel` (próg zostaje w Inventory).
- Publiczny `GET /api/v1/availability` jest usuwany (jak `/prices`).
- `ListBackofficeStock` (strona produktów z wariantami, jak w Pricing), `GetStockHistory(variantId, …)`.
- Seed: `ListVariantIdentities`, wzorce po `key`.

## Cart

- `CartLine {variantId, quantity, addedAt}`, linie unikalne po wariancie. Limity: 50 różnych wariantów, 99 sztuk
  jednego wariantu (stałe domeny bez zmian). Scalanie sumuje ilości tego samego wariantu. Reszta reguł bez zmian.
- Komunikaty limitów: `CartLineQuantityLimit` „Można dodać najwyżej 99 sztuk jednej pozycji.”, `CartLineLimit`
  „Koszyk może zawierać najwyżej 50 różnych pozycji.”.
- Persystencja (nowa migracja): `cart_line.product_id` → `variant_id`, unikalny `(cart_id, variant_id)`; wartości
  zostają, więc istniejące koszyki działają dalej.
- Porty: `ProductCatalog::summaries(variantIds)` (Catalog `GetVariantSummaries`), nowy
  `ProductCatalog::variantsOf(productIds)` (Catalog `ListProductVariants`), `CartPrices::current(variantIds)`,
  `StockAvailability::of(variantIds)` (z poziomem `level` z Inventory). Wariant nieznany Catalogowi: stan `unavailable_product`, dodanie: `ProductNotFound`
  (404, komunikat bez zmian).
- `CartView.lines[]`: `variantId` zamiast `productId`; `product: {productId, name, variantLabel, image} | null`.

### Oferta produktów listy (`GetProductOffers`, decyzje D6, D7, E1)

- Zapytanie `GetProductOffers(productIds)` (1–48) w `Cart_Application`: warianty z portu Catalog
  (`ListProductVariants`), ceny z `CartPrices` (Pricing `GetCurrentPrices`) i stany z `StockAvailability` (Inventory
  `GetStockLevels`, z poziomem), po jednym wywołaniu każdego portu dla wszystkich wariantów.
- Wariant jest możliwy do kupienia, gdy `ProductOffer::purchasability(1)` = `Purchasable` (aktualna cena i
  `available > 0`): ta sama reguła co przy dodaniu do koszyka, bez drugiej implementacji.
- Podsumowanie produktu liczy value object domeny Cart (`ProductOfferSummary`), jedyne miejsce reguły:
  - `fromGross` = najniższe brutto wariantów możliwych do kupienia, `hasPriceRange` = najwyższe ≠ najniższe;
  - `level` = najlepszy poziom (`in_stock` > `low_stock`) wariantów możliwych do kupienia;
  - brak wariantów możliwych do kupienia (także produkt nieznany Catalogowi): `fromGross = null`,
    `hasPriceRange = false`, `level = out_of_stock`.
- Wynik dla każdego żądanego id (powtórzone id raz, w kolejności żądania): `{productId, variantAxes, fromGross|null,
  hasPriceRange, currency: "PLN", level, variants: [{variantId, label, gross|null, level, purchasable}]}`, warianty
  w kolejności pozycji; `gross` tylko z aktualnej ceny, `currency` zawsze `PLN`. Produkt nieznany Catalogowi:
  `variantAxes: []`, `variants: []`. To jedyne źródło wariantów kafelka.
- Odczyt z bazy przez porty, bez blokad. Liczba sztuk nigdy nie jest zwracana.

## API

Błędy RFC 9457. Typy ścisłe i kody jak w specyfikacjach funkcji, chyba że opisano inaczej.

Sklep (`openapi.json`):
- `GET /api/v1/products`: bez zmian.
- `GET /api/v1/product-offers?productId=…` (nowy, `Cart_UI`, `PUBLIC_ACCESS` dla `GET`, `public, max-age=60`):
  1–48 id, brak, za dużo lub niepoprawny UUID: 400; `200 {items: [oferta z sekcji „Oferta produktów listy”]}` dla
  każdego żądanego id. Tablica `variants` służy wyborowi wariantu na kafelku; stronę produktu projektuje PRODUCT-PAGE.
- `GET /api/v1/prices` i `GET /api/v1/availability`: usunięte.
- Koszyk: `{productId}` w ścieżkach `items/{…}` zastępuje `{variantId}`; treść dodania `{variantId: uuid, quantity}`;
  `CartView` jak w sekcji „Cart”. Komunikaty `violations` dla `variantId` jak dziś dla `productId`, z „Identyfikator
  wariantu jest wymagany.” i „Identyfikator wariantu musi być UUID.”.

Panel (`openapi-admin.json`), uprawnienia bez zmian:

| Dziś | Po zmianie |
|---|---|
| `GET /api/v1/admin/prices` (wiersz produktu) | produkt z wariantami: `{productId, name, taxClass, applicableVatRate, variants: [{variantId, label, price}]}`, kursor po produktach |
| `PUT /api/v1/admin/products/{productId}/price` | `PUT /api/v1/admin/variants/{variantId}/price` |
| `GET /api/v1/admin/products/{productId}/price-history` | `GET /api/v1/admin/variants/{variantId}/price-history` |
| `GET /api/v1/admin/stock` (wiersz produktu) | produkt z wariantami: `{productId, name, variants: [{variantId, label, stock}]}`, kursor po produktach |
| `POST /api/v1/admin/products/{productId}/stock/receipts` | `POST /api/v1/admin/variants/{variantId}/stock/receipts` |
| `POST /api/v1/admin/products/{productId}/stock/corrections` | `POST /api/v1/admin/variants/{variantId}/stock/corrections` |
| `GET /api/v1/admin/products/{productId}/stock-history` | `GET /api/v1/admin/variants/{variantId}/stock-history` |

Nieznany wariant: 404. Stare ścieżki znikają (jedynym klientem jest panel z tego repo).

## Sklep (`apps/store`)

- `loadCatalog`: zamiast `GET /api/v1/prices` i `GET /api/v1/availability` jedno `GET /api/v1/product-offers` dla id
  produktów strony. Błąd tego wywołania nie psuje listy: kafelki bez ceny („Cena niedostępna”), bez etykiety
  dostępności, bez listy wariantów i bez przycisku; błąd w logu serwera.
- Kafelek:
  - cena: „od X zł”, gdy `hasPriceRange`, inaczej „X zł”; `fromGross = null`: bez ceny, etykieta „Niedostępny”
    (decyzja E3);
  - etykieta dostępności z `level` produktu;
  - zdjęcie produktu bez zmian przy wyborze wariantu (E2);
  - produkt z jednym wariantem możliwym do kupienia i bez innych wariantów: przycisk „Do koszyka” jak dziś, dodaje ten
    wariant;
  - produkt z ≥ 2 wariantami: lista „Wybierz wariant” (etykieta osi, np. „Kolor, Rozmiar”) z opcjami w kolejności
    wariantów; wariant z `purchasable = false` jest na liście z dopiskiem „niedostępny” i nie da się go wybrać;
    bez wyboru domyślnego; po wyborze kafelek pokazuje cenę i etykietę dostępności wybranego wariantu, a „Do koszyka”
    staje się aktywne i dodaje 1 sztukę wybranego wariantu;
  - produkt bez wariantu możliwego do kupienia (`fromGross = null`), także z ≥ 2 wariantami: „Niedostępny”, bez ceny,
    bez listy i bez przycisku (E3); lista z D8 dotyczy tylko produktów z co najmniej jednym wariantem możliwym do
    kupienia.
- Koszyk (`/cart`): pod nazwą linii etykieta wariantu (pusta dla wariantu domyślnego). BFF: `[productId]` w ścieżkach
  `/api/cart/items/…` → `[variantId]`, treść `POST` `{variantId, quantity}`; reszta zachowania bez zmian.
- HTML katalogu dalej nie zależy od sesji. Serwer renderuje nazwę, zdjęcie, cenę „od” i poziom produktu. Client
  Component obejmuje wybór wariantu, cenę i etykietę wybranego wariantu oraz przycisk; dostaje ofertę produktu
  (osie i `variants` z etykietami) przez propsy. Liczba wariantów, ich kolejność i etykiety pochodzą tylko z oferty.

## Panel (`apps/admin`)

- „Ceny” i „Stany magazynowe”: wiersz na wariant, warianty pod nazwą swojego produktu, kolumna „Wariant” (etykieta,
  pusta dla wariantu domyślnego); akcje i historia dotyczą wiersza wariantu. Wyszukiwanie, filtr kategorii
  i stronicowanie po produktach jak dziś.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Dodanie do koszyka nieznanego wariantu | 404 `ProductNotFound` |
| Wariant bez ceny albo niedostępny | 409 `ProductNotPurchasable`; w sklepie opcja „niedostępny” bez wyboru |
| Produkt bez wariantu możliwego do kupienia | kafelek „Niedostępny” bez ceny i bez przycisku |
| Powtórzona kombinacja wartości w produkcie | `DuplicateVariantValues`; zapis z pominięciem domeny odrzuca unikalny indeks |
| Błąd `GET /api/v1/product-offers` w sklepie | lista bez cen, etykiet, wyboru i przycisków |
| Dwa rozmiary tego samego produktu w koszyku | dwie linie; limit 99 dotyczy każdej osobno |
| Zmiana ceny lub przyjęcie dla nieznanego wariantu | 404 |
| Produkt w indeksie, a nieobecny w bazie | oferta bez wariantów: „Niedostępny”, bez ceny, listy i przycisku |
| Koszyk z linią sprzed migracji | linia wariantu domyślnego, działa bez zmian |

## Stan przejściowy między zadaniami epiku

Zadania epiku są mergowane do `epic/variants` po kolei i po każdym z nich przechodzą `check`, testy i `make e2e`.
Do czasu zadania, które dodaje ofertę produktów listy i zmienia sklep, dane demo mają jeden wariant domyślny na
produkt (`variant:<slug>/standard`, inny niż `id` produktu), a sklep używa `/prices` i `/availability`. Po przejściu
Pricing albo Inventory na klucz wariantu ich publiczny endpoint zachowuje kontrakt na `productId`: warianty produktu
z Catalog `ListProductVariants`; produkt z dokładnie jednym wariantem dostaje cenę albo poziom tego wariantu, produkt
z większą liczbą wariantów jest pominięty w `/prices` i ma `out_of_stock` w `/availability`. Ten tryb znika razem
z oboma endpointami w zadaniu oferty. Końcowy stan epiku nie ma stanu przejściowego.

## Kryteria akceptacji

1. Produkt ma 1–100 wariantów z 0–2 osiami; niezmienniki pilnuje domena Catalog (testy jednostkowe, TDD).
2. Cena, historia cen, stan, historia stanów, rezerwacja i linia koszyka są kluczowane wariantem; migracje zachowują
   identyfikatory i powiązania istniejących danych (test migracji na bazie z danymi sprzed zmiany: każdy rekord
   wskazuje wariant domyślny o `id` dawnego produktu).
3. `GET /api/v1/product-offers` zwraca cenę „od” i poziom produktu liczone wyłącznie z wariantów możliwych do kupienia
   (aktualna cena i dostępny stan) oraz ofertę każdego wariantu; liczy API, reguła zakupu ma jedną implementację.
4. Gość i klient wybierają wariant na kafelku, dodają go do koszyka, a koszyk pokazuje etykietę wariantu; dwa
   warianty jednego produktu to dwie linie.
5. Panel zmienia cenę i stan pojedynczego wariantu.
6. Po `make seed` każdy wariant demo ma cenę i rekord stanu; dane demo mają osie z tabeli i co najmniej jeden produkt
   z wariantem domyślnym.
7. Kryteria współbieżności C1–C6 (Inventory) i K1–K8 (Cart) przechodzą na wariantach.
8. `deptrac.yaml` bez zmian; Catalog nie zależy od Pricing, Inventory ani Cart.
9. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `check` i `test` sklepu i panelu oraz `make e2e`.
10. Kombinacja wartości osi jest unikalna w produkcie: domena i unikalny indeks `(product_id, values_key)` na kolumnie
    generowanej przez PostgreSQL.

## Testy

- Jednostkowe (TDD): niezmienniki `Product` z wariantami (osie 0–2, liczba wartości, unikalność kombinacji także po
  przycięciu i przy innej wielkości liter, 1–100, wariant domyślny), etykieta, `key`; `ProductOfferSummary`
  (pominięcie wariantów bez ceny, z ceną nieaktualną i z `available = 0`, równe i różne ceny, brak wariantów możliwych
  do kupienia, najlepszy poziom); Cart z kluczem wariantu (limity, scalanie dwóch wariantów jednego produktu).
- Integracyjne: mapowanie wariantów i kaskada; unikalny indeks `(product_id, values_key)` odrzuca bezpośredni SQL
  z kombinacją różniącą się tylko wielkością liter (także polskich); `VariantAlreadyExists` z naruszenia klucza
  głównego; `GetCurrentPrices` i `GetStockLevels` dla 4800 id jednym zapytaniem; `GetProductOffers` (jedno wywołanie
  każdego portu); zapytania Catalog (jedno zapytanie na wywołanie, 100 id); listy panelu z wariantami na wielu
  stronach; migracje na danych sprzed zmiany (cena, historia, stan, rezerwacja, linia koszyka
  wskazują wariant domyślny); seedy z wariantem bez wzorca.
- Współbieżność: istniejące C1–C6 i K1–K8 na wariantach.
- Testy jednostkowe, integracyjne i funkcjonalne używają wariantów o `id` różnym od `id` produktu (także dla wariantu
  domyślnego), żeby pomylenie `ProductId` z `VariantId` nie przechodziło niezauważone. Wyjątek: testy migracji.
- Funkcjonalne API: każdy zmieniony endpoint (200, 400/422, 401, 403, 404, 409 jak dziś) oraz nieznany wariant.
- Vitest: kafelek (jeden wariant, ≥ 2 warianty, „od”, wariant niedostępny, brak dostępności), linia koszyka z etykietą,
  BFF z `variantId`; panel: kolumna „Wariant”, akcje na wierszu wariantu.
- E2E (istniejące scenariusze na danych z wariantami):
  - `price-change.spec.ts` (scenariusz 3 z `docs/vision.md`): produkt z wariantem domyślnym (Hantle regulowane,
    wybrany po nazwie); jeden wiersz w panelu; kafelek pokazuje nową kwotę bez „od”;
  - `cart-guest.spec.ts`, `cart-merge.spec.ts`: przed „Do koszyka” test wybiera na kafelku pierwszy dostępny wariant;
    linie koszyka szukane po nazwie i etykiecie wariantu;
  - `stock-receipt.spec.ts`: „Kajak turystyczny Vilmar Rzeka 2” (wszystkie warianty 0, „Niedostępny”); przyjęcie dla
    wariantu „zielony” (wiersz po nazwie i etykiecie); kafelek pokazuje „Dostępny”;
  - test dwóch kart z CART-FIXES (#80): przed „Do koszyka” w każdej karcie wybór wariantu;
  - nowy: gość wybiera dwa rozmiary jednego produktu na kafelku, koszyk ma dwie linie z etykietami.

## Ryzyka i ograniczenia

- Liczba produktów demo spada z 454 do 191 (około 1100 wariantów); `docs/vision.md` mówi o „kilkuset produktach”.
- Oferta produktów listy to cztery zapytania (warianty w Catalog, ceny, stany; do 4800 wariantów na stronę) w miejsce
  dwóch dzisiejszych. Wydajność nie jest potwierdzona testami obciążeniowymi.
- Strona panelu ma `perPage` produktów, więc liczba wierszy zależy od liczby wariantów (do `perPage × 100`; w danych
  demo najwyżej 21 wariantów na produkt).
- Oferta jest odczytem z opóźnieniem cache do 60 s: wariant może zostać wykupiony po wyrenderowaniu listy; wiąże
  dodanie do koszyka (409) i rezerwacja w CHECKOUT.
- Zdjęcie produktu jest poglądowe (E2): w danych demo zdjęcia namiotu, śpiwora, plecaka trekkingowego i kurtki
  narciarskiej pokazują produkt w jednym kolorze, także przy wyborze innego. Zdjęcia wariantów to PRODUCT-PAGE albo
  CATALOG-ADMIN.
- Oferta produktów listy żyje w Cart, bo tam jest reguła zakupu i porty do Catalog, Pricing i Inventory; nazwa
  contextu nie mówi o liście produktów (E1).
- Etykieta wariantu jest wolnym tekstem z danych demo; tłumaczenia i słowniki wartości (np. rozmiarów) poza MVP.
- Wariant nie zmienia produktu, ale usuwania wariantu i zmiany osi nie ma; CATALOG-ADMIN musi ustalić, co dzieje się
  z ceną, stanem i liniami koszyka usuniętego wariantu.

## Dokumentacja do aktualizacji razem z implementacją

- `docs/features/pricing/spec.md`, `docs/features/inventory/spec.md`, `docs/features/cart/spec.md`: klucz wariantu,
  nowe zapytania i endpointy, usunięte `/prices` i `/availability`, oferta produktów w Cart, usunięcie ryzyk
  „cena/stan/koszyk per produkt”.
- `docs/features/walking-skeleton/spec.md` (opis `catalog.yaml`): osie i wartości wariantów zamiast kolorów
  w produkcie.
- `apps/api/CLAUDE.md`: seedy po `key` wariantu (klucz przyjęcia `seed:<variantId>`), `lockMany` po `variant_id`,
  `reconcile-tax` po wariantach (`ListVariantIdentities`), idempotencja przyjęć („dla innego wariantu”), linie koszyka
  zapisywane w miejscu po wariancie.
- `apps/store/CLAUDE.md`: `loadCatalog` z `GET /api/v1/product-offers` zamiast `/prices` i `/availability`,
  `/api/cart/items/[variantId]`.
- `apps/admin/CLAUDE.md`: listy cen i stanów z wierszami wariantów.
- `docs/vision.md`, sekcja „Dane demo”: około 200 produktów i około 1100 wariantów (decyzja D2).
- `docs/features/cart/spec.md`: komunikaty limitów (decyzja D12).
