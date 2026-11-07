# VARIANTS: warianty produktów

Warianty produktów: dane demo z wariantami (sekcja „Dane demo” w `docs/vision.md`) i podstawa checkoutu. Produkt
w Catalog ma warianty (np. kolor i rozmiar), a cena (Pricing), stan (Inventory) i pozycja koszyka (Cart) dotyczą
wariantu, nie produktu. Działanie Pricing, Inventory i Cart opisują ich specyfikacje (`docs/features/pricing/spec.md`,
`docs/features/inventory/spec.md`, `docs/features/cart/spec.md`); ta specyfikacja opisuje warianty oraz to, jak
wariant wchodzi do tych funkcji.

Issues: #89

## Cel i zakres

- Produkt ma 1–100 wariantów. Wariant to jedna kombinacja wartości osi wariantów produktu (0–2 osie, np. „Kolor”,
  „Rozmiar”, „Smak”). Produkt bez osi ma jeden wariant domyślny.
- Kluczem w Pricing (cena i historia cen), Inventory (stan, przyjęcia, korekty, rezerwacje, historia) i Cart (linie
  koszyka) jest klucz wariantu (`variantId`), nie produktu.
- Sklep: kafelek produktu na liście pokazuje cenę „od” i dostępność produktu liczone z wariantów możliwych do kupienia,
  pozwala wybrać wariant i dodać go do koszyka; koszyk pokazuje wariant linii.
- Panel: listy cen i stanów mają wiersz na wariant; zmiana ceny, przyjęcie, korekta i historie dotyczą wariantu.
- Dane demo: produkt = marka × model szablonu, a wartości osi wariantów (kolory, smaki, oprawy, porcje, rozmiary tam,
  gdzie towar je ma) wyznacza szablon. Ceny i stany demo per wariant.
- Migracje zachowują istniejące dane: każdy produkt istniejący w bazie ma wariant domyślny o identyfikatorze równym
  identyfikatorowi produktu, więc ceny, historia cen, stany, rezerwacje i koszyki zapisane przed migracjami
  pozostają ważne. Migracje i dane demo to dwie osobne operacje (sekcja „Migracja a dane demo”).

Poza zakresem:
- strona produktu i wybór wariantu w docelowym wyglądzie (PRODUCT-PAGE #93, VISUAL-DESIGN #97);
- zamówienia (CHECKOUT #90): kontrakt rezerwacji ma klucz wariantu, zamówień jeszcze nie ma;
- ekrany panelu do dodawania i edycji produktów i wariantów (CATALOG-ADMIN #95, `docs/features/catalog-admin/spec.md`);
- wyszukiwanie i filtry po wartościach wariantów (SEARCH #94); indeks Elasticsearch zawiera warianty z wartościami
  osi, cenami i dostępnością (`docs/features/search/spec.md`);
- zdjęcia per wariant i galeria, kody SKU i EAN, cena „od” w Varnishu i ISR.

## Decyzje użytkownika

| # | Decyzja |
|---|---|
| D1 | 0–2 osie wariantów o nazwach ustalonych w produkcie („Kolor”, „Rozmiar”, „Smak”, „Oprawa”…). |
| D2 | „Kolory” szablonów demo (a także smaki, oprawy, porcje) są wartościami osi wariantu; 191 produktów demo, 1012 wariantów. |
| D3 | Warianty są encjami agregatu `Product` (Catalog). |
| D4 | Produkt ma zawsze ≥ 1 wariant; produkt bez osi ma wariant domyślny. Ten sam klucz wariantu wszędzie. |
| D5 | Migracje: wariant domyślny o `id` produktu i zmiana nazw kolumn; identyfikatory i powiązania istniejących cen, historii cen, stanów, rezerwacji, historii stanów i koszyków zostają. Dane demo przebudowuje osobno `make seed`. |
| D6 | Cena na kafelku: „od X zł” z najniższej ceny wariantów **możliwych do kupienia** (aktualna cena i dostępny stan); „X zł”, gdy te ceny są równe. |
| D7 | Dostępność na kafelku: najlepszy poziom spośród wariantów **możliwych do kupienia**; brak takich wariantów: „Niedostępny”. |
| D8 | Kafelek z ≥ 2 wariantami: lista bez wyboru domyślnego, warianty niemożliwe do kupienia z dopiskiem „niedostępny” i bez możliwości wyboru, „Do koszyka” aktywne po wyborze. |
| D9 | Limity koszyka: 50 różnych wariantów, 99 sztuk jednego wariantu. |
| D10 | Panel: wiersz na wariant pod nazwą swojego produktu, kolumna „Wariant”, stronicowanie po produktach. |
| D11 | Osie i wartości w `jsonb`; unikalność kombinacji wartości w produkcie pilnuje domena i unikalny indeks w bazie. |
| D12 | Komunikaty limitów koszyka: „…jednej pozycji”, „…50 różnych pozycji”. |
| E1 | Ofertę produktu listy (D6, D7) liczy zapytanie w Cart (`GetProductOffers`), z regułą zakupu z `ProductOffer`; bez osobnego modułu. Publicznych `/prices` i `/availability` nie ma. |
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

- `Pricing_Infrastructure`, `Inventory_Infrastructure` i `Cart_Infrastructure` wołają zapytania `Catalog_Application`
  przez `query.bus`. Catalog nie zależy od żadnego z nich (pilnuje tego `deptrac.yaml`).
- Każdy context ma własny value object `VariantId` (UUID, małe litery). `ProductId` występuje w Pricing i Inventory
  tylko w listach panelu (`DirectoryProduct`, wiersz produktu z wariantami) i w kontrakcie przyszłej zmiany klasy
  podatkowej produktu (Pricing); w Cart tylko w ofercie produktów listy i w `CartView.lines[].product`.

## Catalog

### Model domenowy

- `VariantId`: UUID.
- `VariantAxes`: lista 0–2 nazw osi w kolejności (np. `["Kolor", "Rozmiar"]`); nazwa 1–30 znaków po przycięciu,
  bez dwukropka (`:` oddziela oś od wartości w filtrze osi), unikalna bez względu na wielkość liter
  (`InvalidVariantAxes`, `InvalidInput`).
- `VariantValues`: lista wartości, po jednej na oś, w kolejności osi; wartość 1–40 znaków po przycięciu
  (`InvalidVariantValues`, `InvalidInput`).
- Encja `ProductVariant {id: VariantId, values: VariantValues}` w agregacie `Product`.
- `Product` ma `variantAxes` i `variants` (`ProductVariants`, kolejność = kolejność prezentacji). Niezmienniki
  (`InvalidProductVariants`, `InvalidInput`):
  - 1–100 wariantów;
  - liczba wartości każdego wariantu = liczba osi;
  - kombinacje wartości są unikalne w produkcie (porównanie po przycięciu, bez względu na wielkość liter):
    `DuplicateVariantValues` (`InvalidInput`);
  - bez osi: dokładnie jeden wariant (domyślny, pusta lista wartości);
  - identyfikatory wariantów są unikalne (w produkcie i, przez klucz główny tabeli, w całym katalogu).
- `Product::create` przyjmuje osie i warianty; `reconstitute` je odtwarza; `Product::edit` zastępuje je w całości
  (sekcja „Edycja i usuwanie wariantów”). Wariant nigdy nie zmienia produktu.
- Komenda `CreateProduct` (seed, `DemoCatalogLoader`, i panel) ma `variantAxes: list<string>` i
  `variants: list<{id, values: list<string>}>`. Identyfikator wariantu zajęty przez inny produkt:
  `VariantAlreadyExists` (`Conflict`). `DoctrineProductRepository::save` mapuje naruszenie klucza głównego
  `catalog_product_variant` na ten wyjątek, tak jak naruszenie unikalnego slugu na `SlugAlreadyTaken`.
- Etykieta wariantu (`label`): wartości złączone „, ” (np. „czarne, 42”); wariant domyślny ma pustą etykietę.
  Etykietę buduje jedna funkcja w Catalog (`ProductVariant::label()`), używana przez wszystkie zapytania.

### Edycja i usuwanie wariantów

`EditProduct` (`docs/features/catalog-admin/spec.md`, decyzja K5) dostaje wiersze wariantów
`{variantId: string|null, values}`. Handler buduje z nich `ProductVariants`: wiersz z identyfikatorem wariantu tego
produktu zachowuje go, wiersz bez identyfikatora dostaje nowy UUIDv7, identyfikator spoza produktu to
`UnknownVariant` (`InvalidInput`, z pozycją wiersza). Niezmienniki `ProductVariants` są te same co przy tworzeniu.

- Dopóki produkt nie był nigdy opublikowany (`firstPublishedAt` puste), osie, wartości i lista wariantów zmieniają się
  dowolnie. Produkt bez osi, któremu dochodzą osie, zachowuje identyfikator wariantu domyślnego w wierszu, który go
  odeśle.
- Od pierwszej publikacji (także po wycofaniu) wariant zachowuje tożsamość: osie mają tę samą liczbę i kolejność,
  a każda nazwa ten sam klucz porównania (`mb_strtolower` po przycięciu); zachowany wariant ma ten sam
  `VariantValues::comparisonKey()` (zmiana wielkości liter i spacji na brzegach jest dozwolona, np. „czarne” →
  „Czarne”). Naruszenie to `VariantIdentityLocked` (`Conflict`, typ `variant-locked`) z zapisaną etykietą wariantu
  albo nazwą osi w `detail`. Nowy wariant można dodać zawsze; inna zmiana znaczenia to dodanie nowego wariantu
  i usunięcie starego.
- Usunięcie wariantu (wariantu nie ma w nowej liście; także przed publikacją): handler pyta port `VariantStockFacts`
  (`Catalog_Application`, adapter `QueryBusVariantStockFacts` w `Inventory_Infrastructure` nad `GetStockLevels`)
  o usuwane warianty ze stanem fizycznym > 0 albo zarezerwowanym > 0. Usunięcie takiego wariantu to `VariantInUse`
  (`Conflict`, typ `variant-in-use`) z etykietą pierwszego takiego wariantu. Fakty są czytane bez blokady wierszy
  Inventory.
- Usunięty wariant znika z `catalog_product_variant`. Jego cena, stan i historie zostają w Pricing i Inventory
  (niewidoczne w panelu, bo listy idą po wariantach Catalog), a linia koszyka z nim ma stan `unavailable_product`
  (wariant nieznany Catalogowi).

### Persystencja

- `catalog_product.variant_axes jsonb NOT NULL` (lista nazw osi).
- `catalog_product_variant`: `id uuid` (PK), `product_id uuid` (FK → `catalog_product` `ON DELETE CASCADE`),
  `position int`, `option_values jsonb NOT NULL` (lista napisów, zapisanych po przycięciu przez domenę),
  `values_key text GENERATED ALWAYS AS (lower(option_values::text)) STORED`; unikalne `(product_id, position)`
  i `(product_id, values_key)`. Kolumnę `values_key` liczy PostgreSQL, więc baza odrzuca powtórzoną kombinację także
  przy zapisie z pominięciem domeny (kanoniczny tekst `jsonb` nie wymaga separatora). Rekord mapuje kolumnę jako
  generowaną (bez zapisu z PHP); zgodność schematu z mapowaniem sprawdza `CatalogSchemaTest`. Test
  integracyjny sprawdza, że `lower()` PostgreSQL i porównanie w domenie zgadzają się dla polskich liter
  („Żółty” i „żółty”).
- Rekord `ProductVariantRecord` z relacją do `ProductRecord`, mapowanie w `DoctrineProductRepository`.
- Reset katalogu (`DbalCatalogReset`) czyści też warianty.
- Zdjęcia są na poziomie produktu (decyzja E2): wariant nie ma zdjęć, a wybór wariantu nie zmienia zdjęcia.
- Migracja `Version20261104120000CreateCatalogProductVariants`: tworzy tabelę i kolumnę, a dla każdego produktu
  istniejącego w bazie dodaje jeden wariant domyślny: `id = catalog_product.id`, `position = 0`,
  `option_values = '[]'` (`variant_axes = '[]'`). Dzięki temu `product_id` zapisane w Pricing, Inventory i Cart przed
  migracjami jest identyfikatorem wariantu domyślnego tego produktu.

### Migracja a dane demo

To dwie osobne operacje.

1. **Migracje** (`make migrate`, `make init`, każde wdrożenie) działają na każdej bazie i niczego nie usuwają.
   Catalog dodaje każdemu produktowi istniejącemu w bazie wariant domyślny o `id` produktu. Pricing, Inventory i Cart
   zmieniają nazwę kolumny `product_id` na `variant_id` bez zmiany wartości (Pricing także nazwę tabeli
   `pricing_product_price` na `pricing_variant_price`). Każda cena, wpis historii cen, stan, wpis historii stanów,
   linia rezerwacji, klucz przyjęcia i linia koszyka zapisane przed migracjami wskazuje po nich wariant domyślny
   swojego produktu, z tym samym identyfikatorem. Catalog jest migrowany przed pozostałymi (bez kluczy obcych między
   contextami, więc kolejność dotyczy tylko spójności danych). Testy migracji na bazie z danymi sprzed migracji
   (`ProductVariantsMigrationTest`, `VariantPricesMigrationTest`, `VariantStockMigrationTest`,
   `CartLinesByVariantMigrationTest`) sprawdzają każdy z tych rekordów.
2. **Dane demo** (`make seed`) to świadomy reset: `catalog:seed-demo`, `pricing:seed-demo` i `inventory:seed-demo`
   czyszczą swoje tabele i ładują katalog z wariantami od nowa (w `prod` tylko z `--force-reset`). Produkt demo
   (marka × model, slug bez koloru) ma inny identyfikator niż produkty demo sprzed wprowadzenia wariantów, bo
   jednemu takiemu produktowi (np. „… czarne”) odpowiada teraz wiele wariantów jednego produktu i nie ma odwzorowania
   1:1. Seed nie czyści koszyków: linia wskazująca wariant, którego po resecie nie ma, ma stan `unavailable_product`.

### Zapytania (`query.bus`, DBAL, jedno zapytanie na wywołanie)

- `GetVariantSummaries(variantIds)` (1–100 id; inaczej `InvalidVariantSummariesQuery`) → w kolejności żądania, dla
  wariantów obecnych w bazie (powtórzone id raz) produktów opublikowanych: `{variantId, productId, name, label,
  image|null}`; zdjęcie główne produktu w rozmiarze `listing`. Wariant szkicu jest pominięty jak nieznany.
- `GetVariant(variantId)` → `{variantId, productId, taxClass}` albo `null`; widzi szkice.
- `ListProductVariants(productIds, includeDrafts = false)` (1–100 id; inaczej `\InvalidArgumentException`) →
  `{productId, variantAxes, variants: [{variantId, label}], taxClass}`, warianty w kolejności pozycji, dla produktów
  obecnych w bazie; szkic tylko z `includeDrafts`. Używają go Pricing i Inventory (listy panelu, `includeDrafts: true`)
  oraz Cart (oferta produktów listy, bez szkiców).
- `ListVariantIdentities(after, limit)` (limit 1–1000, także warianty szkiców) → `{variantId, key, taxClass}` partiami po `variantId`, dla
  seeda i reconcile Pricing oraz seeda Inventory (porty po stronie Pricing i Inventory są osobne).
  `key` = `<slug produktu>/<slug etykiety>` (wariant domyślny: `<slug produktu>/standard`), używany przez wzorce
  danych demo. `key` buduje jedna funkcja Catalog (`VariantKey::of`), której używają expander danych demo i to
  zapytanie.

`ListProducts` i `ProductListItem` nie niosą wariantów (warianty kafelka pochodzą wyłącznie z oferty produktów listy
w Cart; indeks Elasticsearch ich nie zawiera). Listy panelu stronicują po produktach przez `SearchBackofficeProducts`
(kursor i indeks `(name, id)`).

### Dane demo (`fixtures/catalog.yaml`, `catalog:seed-demo`)

- Szablon ma `variantAxes` (0–2 nazwy) i listy wartości osi (`axisValues`, w kolejności osi); warianty to iloczyn
  wartości w kolejności pliku (pierwsza oś zmienia się najwolniej). Produkt = marka × model; nazwa i slug bez koloru
  (np. „Buty szosowe Tarvik Asfalt 200”). Dane demo to 191 produktów i 1012 wariantów, najwyżej 21 wariantów na produkt.
- Identyfikatory: produkt UUID v5 `product:<slug>`, wariant UUID v5 `variant:<key>`. Expander odrzuca powtórzony
  `key` (np. etykiety „1,5” i „1.5”) tak jak powtórzony slug.
- Osie demo:

| Typ | Osie | Wartości |
|---|---|---|
| Buty szosowe, trailowe, piłkarskie | Kolor, Rozmiar | kolory szablonu × 40–46 |
| Kurtka biegowa, Koszulka biegowa, Kurtka narciarska, Pianka pływacka | Kolor, Rozmiar | kolory szablonu × S, M, L, XL |
| Rower górski, Rower elektryczny | Kolor, Rozmiar ramy | kolory szablonu × S, M, L |
| Kask rowerowy, Kask wielosportowy | Kolor, Rozmiar | kolory szablonu × S, M, L |
| Narty zjazdowe | Kolor, Długość | kolory szablonu × 160 cm, 170 cm, 180 cm |
| Narty skiturowe | Kolor, Długość | kolory szablonu × 164 cm, 172 cm, 180 cm |
| Przewodnik | Oprawa | oprawa miękka, oprawa twarda |
| Mapa turystyczna | Wersja | składana, laminowana |
| Danie liofilizowane | Porcja | jednoosobowa, dwuosobowa |
| Baton, Żel, Odżywka, Elektrolity | Smak | smaki szablonu |
| Hantle regulowane | brak osi | wariant domyślny |
| pozostałe | Kolor | kolory szablonu |

- `fixtures/prices.yaml` i `fixtures/stock.yaml`: wzorce dopasowują `key` wariantu (`*` dopasowuje także `/`),
  obowiązuje zasada „pierwszy pasujący”. Ceny różnią się między wariantami co najmniej dla Przewodnika (oprawa),
  Mapy (wersja) i Dania (porcja); w pozostałych produktach warianty mają cenę modelu. Stany: większość wariantów
  20–50, kilka 2–3, kilka 0. Wszystkie warianty produktu „Kajak turystyczny Vilmar Rzeka 2” mają 0 (używa go E2E
  przyjęcia towaru, komentarz w `stock.yaml`); wariant domyślny każdego produktu „Hantle regulowane” ma stan > 0
  (używa go E2E zmiany ceny, komentarz w `stock.yaml`); „Buty szosowe Tarvik Asfalt 200” mają w kolorze „czarne”
  rozmiary 41 i 42 w magazynie (używa ich E2E dwóch rozmiarów); „Kajak turystyczny Dravik Rzeka 2” i „Narty
  skiturowe Stavro Grań 95” mają warianty o stanie 0 i > 0.
- `catalog:seed-demo` czeka, aż indeks zawiera wszystkie produkty (liczba produktów, nie wariantów).

## Pricing

- Agregat ceny i historia są per wariant: `VariantId` zamiast `ProductId` w agregacie `VariantPrice`,
  `PriceSnapshot`, wpisach historii i portach. Obliczenia, zakres, wersja, źródła i reconcile opisuje
  `docs/features/pricing/spec.md`.
- Persystencja (migracja `Version20261104130000KeyPricesByVariant`): `pricing_variant_price` (PK `variant_id`) i
  `pricing_price_history.variant_id`, indeks `(variant_id, changed_at DESC, id)`. Wartości kolumn sprzed migracji
  zostają (wariant domyślny ma `id` produktu).
- `SetVariantPrice` (`byStaff(variantId, gross, expectedVersion|null, actorId, actorName)`, `bySeed(variantId, gross)`):
  klasa podatkowa wariantu to klasa jego produktu, z Catalog `GetVariant`. Nieznany wariant: `VariantNotFound`
  (404, „Wariant produktu nie istnieje.”).
- `GetCurrentPrices(variantIds)` (1–4800, czyli 48 produktów × 100 wariantów; jedno zapytanie `ANY`): kluczem jest
  wariant. Autorytatywny odczyt dla Cart i przyszłego Ordering.
- `ListBackofficePrices`: strona produktów z portu `ProductDirectory` (Catalog `SearchBackofficeProducts`, kursor po
  produktach), ich warianty z etykietami jednym zapytaniem (Catalog `ListProductVariants`) i ceny wariantów jednym
  zapytaniem. Wynik: produkty strony, a w każdym jego warianty w kolejności pozycji.
- `GetPriceHistory(variantId, …)`: nieznany wariant 404; wariant bez ceny: pusta lista.
- `ReconcileTax`: przechodzi ceny wariantów; klasa z Catalog `ListVariantIdentities`.
- Przeliczenie po zmianie klasy podatkowej (`RepriceOnTaxClassChange` na integracyjnym `ProductChanged`): warianty
  i klasa produktu z Catalog `ListProductVariants([productId], includeDrafts: true)`, `reprice` każdej ceny z klasą
  inną niż klasa produktu, każdej w osobnej transakcji (`docs/features/pricing/spec.md`).
- Seed: `ListVariantIdentities`, wzorce po `key`; wariant bez wzorca kończy seed błędem bez zmian w danych.

## Inventory

- `StockItem`, `Reservation` (linie), `StockMovement` i porty używają `VariantId`. Niezmienniki, limity, blokady,
  idempotencja i kryteria C1–C6 opisuje `docs/features/inventory/spec.md`; `lockMany` blokuje w kolejności rosnącego
  `variant_id`.
- Persystencja (migracja `Version20261104140000KeyStockByVariant`): kolumna `variant_id` w `inventory_stock_item`
  (PK), `inventory_reservation_line` (PK `(reservation_key, variant_id)`) i `inventory_stock_movement` (indeks
  `(variant_id, occurred_at DESC, id)`); wartości sprzed migracji zostają.
- Komendy `ReceiveStock`, `CorrectStock`, `CreateEmptyStock` przyjmują `variantId`; nieznany wariant (Catalog
  `GetVariant`): `VariantNotFound` (404, „Wariant produktu nie istnieje.”). Klucz przyjęcia seeda: `seed:<variantId>`.
- `ReserveStock` (kontrakt dla Ordering): linie `{variantId, quantity}`, bez powtórzonego wariantu.
- `GetStockLevels(variantIds)` (1–4800; jedno zapytanie `ANY`): kluczem jest wariant; `level` z `AvailabilityLevel`
  (próg jest w Inventory).
- `ListBackofficeStock` (strona produktów z wariantami, jak w Pricing), `GetStockHistory(variantId, …)`.
- Seed: `ListVariantIdentities`, wzorce po `key`.

## Cart

- `CartLine {variantId, quantity, addedAt}`, linie unikalne po wariancie. Limity: 50 różnych wariantów, 99 sztuk
  jednego wariantu. Scalanie sumuje ilości tego samego wariantu. Pozostałe reguły koszyka opisuje
  `docs/features/cart/spec.md`.
- Komunikaty limitów: `CartLineQuantityLimit` „Można dodać najwyżej 99 sztuk jednej pozycji.”, `CartLineLimit`
  „Koszyk może zawierać najwyżej 50 różnych pozycji.”.
- Persystencja (migracja `Version20261105120000KeyCartLinesByVariant`): `cart_line.variant_id`, unikalny
  `(cart_id, variant_id)` (`cart_line_cart_variant_key`); wartości sprzed migracji zostają, więc istniejące koszyki
  działają dalej.
- Porty: `ProductCatalog::summaries(variantIds)` (Catalog `GetVariantSummaries`),
  `ProductCatalog::variantsOf(productIds)` (Catalog `ListProductVariants`), `CartPrices::current(variantIds)`
  (Pricing `GetCurrentPrices`), `StockAvailability::of(variantIds)` (Inventory `GetStockLevels`, z poziomem `level`).
  Wariant nieznany Catalogowi: stan linii `unavailable_product`, dodanie: `ProductNotFound` (404, „Produkt nie jest
  już dostępny.”).
- `CartView.lines[]`: `variantId`; `product: {productId, name, variantLabel, image} | null`.

### Oferta produktów listy (`GetProductOffers`, decyzje D6, D7, E1)

- Zapytanie `GetProductOffers(productIds)` (1–48) w `Cart_Application`: warianty z portu Catalog
  (`ListProductVariants`), ceny z `CartPrices` (Pricing `GetCurrentPrices`) i stany z `StockAvailability` (Inventory
  `GetStockLevels`, z poziomem), po jednym wywołaniu każdego portu dla wszystkich wariantów (bez wywołania cen i
  stanów, gdy żaden z produktów nie ma wariantów).
- Wariant jest możliwy do kupienia, gdy `ProductOffer::purchasability(1)` = `Purchasable` (aktualna cena i
  `available > 0`): `VariantOffer::isPurchasable()` woła tę samą regułę co dodanie do koszyka, bez drugiej
  implementacji.
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
- `GET /api/v1/products`: strona produktów bez wariantów (`ProductListItem`).
- `GET /api/v1/product-offers?productId=…&productId=…` (`Cart_UI`, `PUBLIC_ACCESS` dla `GET`, `public, max-age=60,
  s-maxage=3600`, `xkey: catalog product-<id> …` dla każdego żądanego id, UUID małymi literami; sklep czyta ją przez
  Varnish, który usuwa ją po zdarzeniu zmiany ceny albo poziomu dostępności produktu, `docs/features/product-page/spec.md`):
  1–48 id w powtórzonym kluczu `productId` (forma `productId[]` nie jest przyjmowana); brak id, za dużo albo
  niepoprawny UUID: 400; `200 {items: [oferta z sekcji „Oferta produktów listy”]}` dla każdego żądanego id.
  400, 401 i 503: `private, no-store` bez tagów.
  Tablica `variants` służy wyborowi wariantu na kafelku; stronę produktu projektuje PRODUCT-PAGE.
- Koszyk: `{variantId}` w ścieżkach `items/{variantId}`; treść dodania `{variantId: uuid, quantity}`; `CartView`
  jak w sekcji „Cart”. Komunikaty `violations` dla `variantId`: „Identyfikator wariantu jest wymagany.” i
  „Identyfikator wariantu musi być UUID.”.

Panel (`openapi-admin.json`), uprawnienia jak w specyfikacjach Pricing i Inventory:

| Endpoint | Treść |
|---|---|
| `GET /api/v1/admin/prices` | produkt z wariantami: `{productId, name, taxClass, applicableVatRate, variants: [{variantId, label, price}]}`, kursor po produktach |
| `PUT /api/v1/admin/variants/{variantId}/price` | zmiana ceny wariantu |
| `GET /api/v1/admin/variants/{variantId}/price-history` | historia cen wariantu |
| `GET /api/v1/admin/stock` | produkt z wariantami: `{productId, name, variants: [{variantId, label, stock}]}`, kursor po produktach |
| `POST /api/v1/admin/variants/{variantId}/stock/receipts` | przyjęcie dla wariantu |
| `POST /api/v1/admin/variants/{variantId}/stock/corrections` | korekta stanu wariantu |
| `GET /api/v1/admin/variants/{variantId}/stock-history` | historia stanu wariantu |

Nieznany wariant: 404.

## Sklep (`apps/store`)

- `loadCatalog`: kategorie i strona produktów, potem jedno `GET /api/v1/product-offers` dla id produktów strony.
  Błąd tego wywołania nie psuje listy: kafelki bez ceny („Cena niedostępna”), bez etykiety dostępności, bez listy
  wariantów i bez przycisku; błąd w logu serwera.
- Kafelek:
  - cena: „od X zł”, gdy `hasPriceRange`, inaczej „X zł”; `fromGross = null`: bez ceny, etykieta „Niedostępny”
    (decyzja E3);
  - etykieta dostępności z `level` produktu;
  - zdjęcie produktu nie zmienia się przy wyborze wariantu (E2);
  - produkt z jednym wariantem (domyślnym albo jedyną kombinacją): przycisk „Do koszyka” dodaje ten wariant;
  - produkt z ≥ 2 wariantami: lista „Wybierz wariant” (etykieta osi, np. „Kolor, Rozmiar”) z opcjami w kolejności
    wariantów; wariant z `purchasable = false` jest na liście z dopiskiem „niedostępny” i nie da się go wybrać;
    bez wyboru domyślnego; po wyborze kafelek pokazuje cenę i etykietę dostępności wybranego wariantu, a „Do koszyka”
    staje się aktywne i dodaje 1 sztukę wybranego wariantu;
  - produkt bez wariantu możliwego do kupienia (`fromGross = null`), także z ≥ 2 wariantami: „Niedostępny”, bez ceny,
    bez listy i bez przycisku (E3); lista z D8 dotyczy tylko produktów z co najmniej jednym wariantem możliwym do
    kupienia.
- Koszyk (`/cart`): pod nazwą linii etykieta wariantu (pusta dla wariantu domyślnego). BFF: `/api/cart/items/[variantId]`,
  treść `POST` `{variantId, quantity}`.
- HTML katalogu nie zależy od sesji. Serwer renderuje nazwę, zdjęcie, cenę „od” i poziom produktu (`ProductTile`).
  Client Component (`TileOffer`) obejmuje wybór wariantu, cenę i etykietę wybranego wariantu oraz przycisk; dostaje
  ofertę produktu (osie i `variants` z etykietami) przez propsy. Liczba wariantów, ich kolejność i etykiety pochodzą
  tylko z oferty.

## Panel (`apps/admin`)

- „Ceny” i „Stany magazynowe”: wiersz na wariant, warianty pod nazwą swojego produktu (nazwa tylko w pierwszym wierszu
  produktu), kolumna „Wariant” (etykieta, pusta dla wariantu domyślnego); akcje i historia dotyczą wiersza wariantu.
  Wyszukiwanie, filtr kategorii i stronicowanie po produktach.

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
| Koszyk z linią zapisaną przed migracją | linia wariantu domyślnego, działa dalej |

## Kryteria akceptacji

1. Produkt ma 1–100 wariantów z 0–2 osiami; niezmienniki pilnuje domena Catalog.
2. Cena, historia cen, stan, historia stanów, rezerwacja i linia koszyka są kluczowane wariantem; migracje zachowują
   identyfikatory i powiązania istniejących danych (każdy rekord zapisany przed migracjami wskazuje wariant domyślny
   o `id` swojego produktu).
3. `GET /api/v1/product-offers` zwraca cenę „od” i poziom produktu liczone wyłącznie z wariantów możliwych do kupienia
   (aktualna cena i dostępny stan) oraz ofertę każdego wariantu; liczy API, reguła zakupu ma jedną implementację.
4. Gość i klient wybierają wariant na kafelku, dodają go do koszyka, a koszyk pokazuje etykietę wariantu; dwa
   warianty jednego produktu to dwie linie.
5. Panel zmienia cenę i stan pojedynczego wariantu.
6. Po `make seed` każdy wariant demo ma cenę i rekord stanu; dane demo mają osie z tabeli i produkty z wariantem
   domyślnym (Hantle regulowane).
7. Kryteria współbieżności C1–C6 (Inventory) i K1–K8 (Cart) obowiązują na wariantach.
8. Catalog nie zależy od Pricing, Inventory ani Cart (Deptrac).
9. Kombinacja wartości osi jest unikalna w produkcie: domena i unikalny indeks `(product_id, values_key)` na kolumnie
   generowanej przez PostgreSQL.

## Testy

- Jednostkowe: niezmienniki `Product` z wariantami (osie 0–2, liczba wartości, unikalność kombinacji także po
  przycięciu i przy innej wielkości liter, 1–100, wariant domyślny), etykieta, `key`; `ProductOfferSummary`
  (pominięcie wariantów bez ceny, z ceną nieaktualną i z `available = 0`, równe i różne ceny, brak wariantów możliwych
  do kupienia, najlepszy poziom); Cart z kluczem wariantu (limity, scalanie dwóch wariantów jednego produktu).
- Integracyjne: mapowanie wariantów i kaskada; unikalny indeks `(product_id, values_key)` odrzuca bezpośredni SQL
  z kombinacją różniącą się tylko wielkością liter (także polskich); `VariantAlreadyExists` z naruszenia klucza
  głównego; `GetCurrentPrices` i `GetStockLevels` dla 4800 id jednym zapytaniem; `GetProductOffers` (jedno wywołanie
  każdego portu); zapytania Catalog (jedno zapytanie na wywołanie, 100 id); listy panelu z wariantami na wielu
  stronach; migracje na danych sprzed migracji (cena, historia, stan, rezerwacja, linia koszyka wskazują wariant
  domyślny); seedy z wariantem bez wzorca.
- Współbieżność: C1–C6 i K1–K8 na wariantach.
- Testy jednostkowe, integracyjne i funkcjonalne używają wariantów o `id` różnym od `id` produktu (także dla wariantu
  domyślnego), żeby pomylenie `ProductId` z `VariantId` nie przechodziło niezauważone. Wyjątek: testy migracji.
- Funkcjonalne API: każdy endpoint dotyczący wariantu (200, 400/422, 401, 403, 404, 409) oraz nieznany wariant.
- Vitest: kafelek (jeden wariant, ≥ 2 warianty, „od”, wariant niedostępny, brak dostępności), linia koszyka z etykietą,
  BFF z `variantId`; panel: kolumna „Wariant”, akcje na wierszu wariantu.
- E2E (`e2e/tests`, dane z wariantami):
  - `price-change.spec.ts` (scenariusz 3 z `docs/vision.md`): produkt z wariantem domyślnym („Hantle regulowane Lumora
    Para 10”); jeden wiersz w panelu; kafelek pokazuje nową kwotę bez „od”;
  - `cart-guest.spec.ts`, `cart-merge.spec.ts`: przed „Do koszyka” test wybiera na kafelku pierwszy dostępny wariant
    (`chooseFirstVariant` w `e2e/support/variants.ts`); linie koszyka szukane po nazwie i etykiecie wariantu;
    `cart-guest.spec.ts` ma też test dwóch kart gościa dodających dwa produkty naraz;
  - `stock-receipt.spec.ts`: „Kajak turystyczny Vilmar Rzeka 2” (wszystkie warianty 0, „Niedostępny”); przyjęcie dla
    wariantu „zielony” (wiersz po nazwie i etykiecie); kafelek pokazuje „Dostępny”;
  - `cart-variants.spec.ts`: gość wybiera dwa rozmiary jednego produktu („Buty szosowe Tarvik Asfalt 200”, „czarne,
    41” i „czarne, 42”) na kafelku, koszyk ma dwie linie z etykietami.

## Ryzyka i ograniczenia

- Oferta produktów listy to trzy odczyty (warianty w Catalog, ceny w Pricing, stany w Inventory; do 4800 wariantów na
  stronę). Wydajność nie jest potwierdzona testami obciążeniowymi.
- Strona panelu ma `perPage` produktów, więc liczba wierszy zależy od liczby wariantów (do `perPage × 100`; w danych
  demo najwyżej 21 wariantów na produkt).
- Oferta w Varnishu jest aktualna do obsłużenia zdarzenia zmiany ceny albo poziomu dostępności (sekundy: outbox,
  RabbitMQ, purge); wariant może zostać wykupiony po wyrenderowaniu listy, a utracone unieważnienie zostawia ofertę
  najwyżej do `s-maxage` (1 h). Wiąże dodanie do koszyka (409) i rezerwacja w CHECKOUT.
- Zdjęcie produktu jest poglądowe (E2): w danych demo zdjęcia namiotu, śpiwora, plecaka trekkingowego i kurtki
  narciarskiej pokazują produkt w jednym kolorze, także przy wyborze innego. Zdjęcia wariantów to PRODUCT-PAGE albo
  CATALOG-ADMIN.
- Oferta produktów listy żyje w Cart, bo tam jest reguła zakupu i porty do Catalog, Pricing i Inventory; nazwa
  contextu nie mówi o liście produktów (E1).
- Etykieta wariantu jest wolnym tekstem z danych demo; tłumaczenia i słowniki wartości (np. rozmiarów) poza MVP.
- Usunięty wariant zostawia cenę, stan i historie w Pricing i Inventory. Blokada usunięcia czyta stan bez blokady:
  przyjęcie albo rezerwacja zatwierdzona równolegle z usunięciem może zostawić stan usuniętego wariantu do ręcznego
  wyjaśnienia.
- Po publikacji literówki w wartości wariantu nie da się poprawić w miejscu (poza wielkością liter): trzeba dodać
  nowy wariant, skorygować stan starego do zera i go usunąć.
