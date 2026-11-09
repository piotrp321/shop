# Cart: koszyk gościa i zalogowanego klienta (MVP)

Punkt 6 MVP z `docs/vision.md`. Złożenie zamówienia z koszyka opisuje `docs/features/checkout/spec.md`.

## Cel i zakres

Klient sklepu (gość albo zalogowany w realmie `shop`) dodaje wariant produktu do koszyka z kafelka na liście, zmienia ilości,
usuwa pozycje i widzi sumy policzone przez API. Koszyk przeżywa odświeżenie i ponowne wejście. Po zalogowaniu koszyk
gościa łączy się z koszykiem klienta, a klient widzi ten sam koszyk na każdym urządzeniu.

Cena wariantu w koszyku i w ofercie listy to cena skuteczna z Pricing (w aktywnej promocji cena promocyjna,
`docs/features/promotions/spec.md`); koszyk nie oznacza promocji. Ceny i sumy linii możliwych do kupienia pochodzą
z jednej wyceny Pricing (`QuoteBasket`, ta sama co przy złożeniu zamówienia), a odczyt koszyka przyjmuje kod rabatowy
do podglądu w kasie (`docs/features/discount-codes/spec.md`); kod nie jest zapisywany w koszyku. Poza zakresem:
rezerwacja stanu (robi ją złożenie zamówienia w Ordering), płatności, koszty dostawy, strona produktu, panel
administracyjny, rate limiting tworzenia koszyków.

## Decyzje biznesowe

| # | Decyzja |
|---|---|
| 1 | Pozycja koszyka to wariant produktu (epik VARIANTS): dwa warianty jednego produktu (np. dwa rozmiary) to dwie pozycje. |
| 2 | Koszyki gości i klientów są w PostgreSQL (nie w Redisie). |
| 3 | Scalanie przy logowaniu sumuje ilości. Suma ponad limit ilości jest przycinana, linie gościa ponad limit liczby pozycji są pomijane, a klient widzi komunikat. Scalenie nigdy nie kończy się błędem z powodu ceny ani dostępności. |
| 4 | Najwyżej 50 różnych pozycji (wariantów), najwyżej 99 sztuk jednej pozycji. |
| 5 | Koszyk gościa wygasa po 30 dniach od ostatniej zmiany, koszyk klienta po 180 dniach. Wygasły koszyk zachowuje się jak pusty, fizycznie usuwa go `cart:purge-expired`. |
| 6 | Pozycje nigdy nie znikają same. Pozycja bez ceny, niedostępna, z ilością większą niż dostępna albo z wariantem usuniętym z katalogu ma etykietę i przycisk „Usuń”. Suma obejmuje tylko pozycje możliwe do kupienia. |
| 7 | Dostępność ogranicza ilość przy dodaniu i zwiększeniu ilości, bez rezerwacji i bez ujawniania liczby sztuk. Wiąże dopiero rezerwacja przy złożeniu zamówienia (Ordering). |
| 8 | Sumy: wartość brutto przed kodem, rabat kodu, brutto do zapłaty i liczba sztuk, bez VAT. Licznik sztuk w nagłówku. |
| 9 | Koszyk pokazuje zawsze cenę aktualną, bez informacji o zmianie ceny. Cenę zamraża zamówienie. |
| 10 | Po wylogowaniu przeglądarka pokazuje pusty koszyk gościa; koszyk klienta zostaje na koncie. |

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Cart | koszyki, pozycje, limity, scalanie, wygasanie, widok koszyka z sumami, oferta produktów listy (warianty możliwe do kupienia, cena „od”, poziom produktu) |
| Catalog | `GetVariantSummaries` (produkt, nazwa produktu, etykieta wariantu i zdjęcie produktu dla linii koszyka), `ListProductVariants` (osie i warianty produktów listy dla oferty) |
| Pricing | `GetCurrentPrices` (autorytatywna cena aktualna wariantu: kupowalność i oferta listy), `QuoteBasket` (wycena linii możliwych do kupienia z kodem rabatowym) |
| Inventory | `GetStockLevels` (dostępna liczba sztuk wariantu i jej poziom, tylko w procesie API) |
| Customer | `AuthenticatedCustomer::id()` (`sub` z tokena realmu `shop`) |
| Ordering | składa zamówienie z koszyka: blokuje go i czyta jego linie (`LockCartForOrder`), po złożeniu opróżnia (`ClearCartAfterOrder`) |
| Sklep | BFF koszyka i wyświetlanie wartości z API; żadnych obliczeń |

Reguły Deptrac (od Cart zależy tylko Ordering: `Ordering_Infrastructure → Cart_Application`; Cart nie zależy od Ordering
w żadnej warstwie):

| Warstwa | Może zależeć od |
|---|---|
| `Cart_Domain` | `Native`, `Shared_Domain` |
| `Cart_Application` | `Native`, `Cart_Domain`, `Shared_Domain`, `Vendor` |
| `Cart_Infrastructure` | `Native`, `Cart_Application`, `Cart_Domain`, `Shared_Domain`, `Shared_Infrastructure`, `Vendor`, `Catalog_Application`, `Pricing_Application`, `Inventory_Application` |
| `Cart_UI` | `Native`, `Cart_Application`, `Cart_Domain`, `Shared_Domain`, `Shared_Infrastructure`, `Vendor`, `Customer_Application` |

- Adaptery portów Cart w `Cart_Infrastructure` wołają zapytania innych contextów przez `query.bus`, jak
  `Pricing\Infrastructure\Catalog\QueryBusProductDirectory`.
- `Cart_UI → Customer_Application` wyłącznie przez kontrakt `AuthenticatedCustomer` (jak
  `Pricing_UI → Backoffice_Application`). `Cart_Domain` i `Cart_Application` nie znają Customer.
- Domena Cart ma własne value objecty i nie importuje kodu innych contextów.
- Cart w MVP nie publikuje ani nie konsumuje zdarzeń domenowych.
- Ordering woła dwie komendy Cart przez `command.bus` w swojej transakcji (zagnieżdżony dispatch, adapter
  `Ordering\Infrastructure\Cart\CommandBusOrderCart`). Komendy są w `Cart_Application` i nie znają Ordering.

## Model domenowy

### Value objecty

- `CartId`: UUID; nowy jest losowym UUID v4. Identyfikator koszyka gościa jest jednocześnie kluczem dostępu do niego.
- `CartOwner`: `guest` albo `customer(customerId)`, gdzie `customerId` to `sub` z tokena (1–255 znaków).
- `VariantId`: UUID wariantu (zapisywany małymi literami), klucz linii koszyka.
- `ProductId`: UUID produktu (małymi literami), tylko w ofercie produktów listy i w produkcie linii (`VariantSummary`).
- `Quantity`: liczba całkowita 1–99 (`InvalidQuantity`, `InvalidInput`).
- `Money`: nieujemne grosze (`int`) i `Currency` (tylko `PLN`). Bez `float`.
- `ProductOffer {hasCurrentPrice: bool, available: int}`: stan wariantu z portów, podany domenie przez handler;
  `available` nie jest ujemne. Jedyne miejsce reguły „produkt do kupienia”: `purchasability(quantity)` zwraca
  `Purchasability` (enum domeny) w kolejności `NoPrice` (brak ceny), `OutOfStock` (`available = 0`),
  `InsufficientStock` (`quantity > available`), `Purchasable`. Używają go `Cart` (dodanie i zwiększenie), widok
  koszyka oraz oferta produktów listy (`VariantOffer`).
- `CartLine {variantId, quantity, addedAt}`; `addedAt` ustala kolejność linii i nie zmienia się wraz z ilością.
- `MergeOutcome {result: merged | already_merged | nothing_to_merge, truncated: bool}`.

### Agregat `Cart`

Pola: `id`, `owner`, `status` (`open` | `merged`; `merged` tylko dla gościa), `mergedInto` (null), `mergedAt` (null),
linie unikalne po `variantId` w kolejności `addedAt`, `createdAt`, `updatedAt`, `expiresAt`. Nowy koszyk powstaje przez
`createForGuest` i `createForCustomer`, zapisany jest odtwarzany przez `reconstitute()`.

Stałe domeny (jedyne miejsce tych reguł): `MAX_LINES = 50`, `MAX_QUANTITY = 99`, czas życia gościa 30 dni, klienta
180 dni.

| Metoda | Działanie | Błędy |
|---|---|---|
| `add(variantId, quantity, offer, now)` | brak linii: nowa linia; linia istnieje: ilość += quantity | wynik > 99: `CartLineQuantityLimit` (409); nowa linia przy 50 liniach: `CartLineLimit` (409); `!hasCurrentPrice` albo `available = 0`: `ProductNotPurchasable` (409); wynik > `available`: `InsufficientStock` (409) |
| `changeQuantity(variantId, quantity, offer, now)` | ustawia ilość (wartość bezwzględna). Zmniejszenie i ta sama ilość są zawsze dozwolone. Zwiększenie wymaga `hasCurrentPrice`, `available > 0` i `quantity ≤ available` | brak linii: `CartLineNotFound` (404); błędy zwiększenia jak przy `add` |
| `remove(variantId, now)` | usuwa linię; brak linii: sukces bez zmian (bez `touch`) | |
| `mergeFrom(guest, now): MergeOutcome` | na koszyku klienta, opis w sekcji „Scalanie” | koszyk gościa: błąd logiczny |
| `outcomeWithoutMerge(now)` | na koszyku, który ma zostać scalony: `already_merged` dla `merged` (także wygasłego), `nothing_to_merge` dla wygasłego gościa i dla koszyka klienta, `null` gdy można scalać | |
| `markMerged(customerCartId, now)` | na koszyku gościa: usuwa linie, `status = merged`, `mergedInto`, `mergedAt`, `updatedAt = now`; `expiresAt` zostaje (scalony koszyk czeka na czyszczenie) | koszyk nie jest gościa albo nie jest `open`: błąd logiczny |
| `clearIfExpired(now)` | koszyk klienta po `expiresAt`: usuwa wszystkie linie (koszyka gościa nigdy) | |
| `clearAfterOrder(now)` | po złożeniu zamówienia z koszyka (Ordering): `openForChange`, usuwa wszystkie linie i `touch(now)`; koszyk gościa zostaje `open` | `GuestCartMerged`, `CartNotFound` |
| `openForChange(now)` | początek każdej zmiany, idempotentne: gość sprawdza dostępność (poniżej), klient `clearIfExpired` | `GuestCartMerged`, `CartNotFound` |
| `needsOfferToChange(variantId, quantity)` | czy zmiana ilości jest zwiększeniem istniejącej linii, więc potrzebuje `ProductOffer` | |
| `linesAt(now)`, `itemCountAt(now)` | linie i liczba sztuk tak, jak widzi je odczyt: wygasły koszyk klienta jest pusty | gość `merged`: `GuestCartMerged`, wygasły: `CartNotFound` |
| `touch(now)` | `expiresAt = now + 30 dni` (gość) albo `now + 180 dni` (klient), `updatedAt = now` | |
| `isExpired(now)` | `expiresAt < now` | |

- Każda zmiana koszyka gościa najpierw sprawdza `status = open`. Koszyk `merged` daje `GuestCartMerged`
  (`NotFound`, 404). Po scaleniu żaden request nie zmieni już koszyka gościa.
- Każda komenda na koszyku klienta zaczyna od `clearIfExpired(now)`, a każda udana zmiana kończy się `touch(now)`.
- Wygasły koszyk gościa jest dla komend i odczytów nieistniejący (`CartNotFound`, 404).
- `InsufficientStock` nie zawiera liczby sztuk; `detail`: „Nie mamy tylu sztuk tego produktu.”.
- Komunikaty `detail` (po polsku, bez danych wejściowych): `InvalidQuantity` „Ilość musi wynosić od 1 do 99 szt.”,
  `CartLineQuantityLimit` „Można dodać najwyżej 99 sztuk
  jednej pozycji.”, `CartLineLimit` „Koszyk może zawierać najwyżej 50 różnych pozycji.”, `ProductNotPurchasable`
  „Ten produkt jest chwilowo niedostępny w sprzedaży.”, `CartLineNotFound` „Tej pozycji nie ma w koszyku.”,
  `CartNotFound` i `GuestCartMerged` „Koszyk nie jest już dostępny.”, `ProductNotFound` „Produkt nie jest już
  dostępny.”.

### Scalanie (`mergeFrom`)

1. Koszyk gościa, którego nie można scalić (`outcomeWithoutMerge`), nie zmienia żadnego z koszyków; wynik to
   `already_merged` albo `nothing_to_merge`.
2. `clearIfExpired(now)` na koszyku klienta: wygasły koszyk klienta traci swoje stare pozycje przed scaleniem,
   więc do pustego koszyka trafiają tylko pozycje gościa.
3. Linie gościa w kolejności `addedAt`:
   - wariant jest już w koszyku klienta: ilość = min(suma, 99); przycięcie ustawia `truncated`;
   - wariantu nie ma i koszyk ma mniej niż 50 linii: nowa linia z ilością gościa (zachowuje `addedAt` gościa);
   - wariantu nie ma i koszyk ma 50 linii: linia pominięta, `truncated`.

   Po pętli linie koszyka klienta są sortowane po `addedAt` (stabilnie), bo linia gościa może być starsza od linii klienta.
4. Cena i dostępność nie są sprawdzane (decyzja 3); nadmiar względem dostępności widać w stanie linii.
5. `guest.markMerged(customer.id, now)`, `customer.touch(now)`.
6. Wynik `MergeOutcome {result: merged, truncated}`. Pusty koszyk gościa też jest oznaczany jako `merged`
   (`truncated = false`).

### Widok koszyka (`CartView`)

Budowany w warstwie aplikacji z koszyka i portów (`ProductCatalog`, `CartPrices`, `StockAvailability`), po jednym
wywołaniu każdego portu dla wszystkich linii (`ProductDataLoader`; bez linii porty nie są wołane). Sumy i mapowanie
stanów linii są w jednej klasie (`CartViewBuilder`), testowanej jednostkowo. Sama reguła zakupu (cena, potem stan) jest
tylko w `ProductOffer::purchasability` (`ProductData::offerFor`); widok rozstrzyga wyłącznie `unavailable_product`.
Linia ma `variantId` i `product {productId, name, variantLabel, image}` z `GetVariantSummaries` (etykieta wariantu pusta
dla wariantu domyślnego).

Stan linii, w kolejności priorytetu:

| `state` | Warunek |
|---|---|
| `unavailable_product` | wariant nieznany Catalogowi (np. po resecie danych demo, linia sprzed migracji, której produktu już nie ma, wariant usunięty w panelu) albo wariant szkicu (produkt nieopublikowany albo wycofany z publikacji: `GetVariantSummaries` pomija go jak nieznany) |
| `no_price` | brak ceny w `GetCurrentPrices` (brak ceny, nieaktualna stawka VAT albo cena w walucie innej niż PLN; promocja tego nie zmienia), także linia możliwa do kupienia według `GetCurrentPrices`, której wycena nie wyceniła (cena usunięta między odczytami); gdy wycena pominie wszystkie takie linie, sumy są 0 i `discount = null` jak bez linii kupowalnych |
| `out_of_stock` | `available = 0` (także produkt bez rekordu stanu) |
| `insufficient_stock` | `quantity > available` |
| `purchasable` | pozostałe |

- Kupowalność (cena, potem stan) ustala `ProductOffer::purchasability()` z cenami z `CartPrices`. Linie `purchasable`
  wycenia jedną wyceną port `CartQuote` (Pricing `QuoteBasket`, D9 w `docs/features/discount-codes/spec.md`) z kodem
  odczytu: to dwa odczyty cen na widok (świadomie: kupowalność zależy od stanu, a rabat od zbioru linii kupowalnych);
  na granicy promocji mogą się różnić, ale zamówienie bierze wycenę z chwili złożenia.
- `unitPrice`: dla `purchasable` cena skuteczna jednostkowa z wyceny, dla pozostałych cena brutto z `CartPrices` (gdy
  jest). `lineTotal` = wartość linii przed kodem z wyceny (`grossBeforeDiscount`, cena jednostkowa × ilość) tylko dla
  `purchasable`; widok nie pokazuje rabatu linii, więc linie i wiersz kodu sumują się do `totals.gross`.
- `totals` z wyceny: `subtotalGross` (suma `lineTotal`), `discountGross` (rabat kodu, 0 bez kodu), `gross` (do
  zapłaty, `subtotalGross − discountGross`), `currency = PLN`. Bez linii `purchasable` (pusty koszyk albo same
  wykluczone) nie ma wyceny: sumy 0 i `discount = null`, także gdy przyszedł kod. Koszyk z liniami wykluczonymi
  i kupowalnymi dostaje wycenę kodu z linii kupowalnych.
- `totals.itemCount` = suma ilości wszystkich linii (licznik w nagłówku).
- `discount`: kod odczytu zastosowany `{status: applied, code, amountGross, kind, value}` albo odrzucony `{status:
  rejected, code, reason, detail, minimumOrderGross | null}` (`reason`: `invalid`, `used_up`, `below_minimum`; `detail`
  po polsku z Pricing, ten sam co w 409 złożenia), `null` bez kodu. Komendy (dodanie, zmiana, usunięcie, scalenie)
  odpowiadają widokiem bez kodu.
- `hasExcludedLines` = istnieje linia inna niż `purchasable`.
- Kwoty w groszach; maksimum 50 × 99 × 99 999 999 mieści się w `int`.

### Oferta produktów listy (`GetProductOffers`)

Kafelek produktu na liście sklepu pokazuje cenę „od” i dostępność produktu liczone z jego wariantów możliwych do kupienia
oraz pozwala wybrać wariant (decyzje D6–D8 i E1–E3 w `docs/features/variants/spec.md`). Liczy to Cart, bo tu jest reguła
zakupu i porty do Catalog, Pricing i Inventory.

- Zapytanie `GetProductOffers(productIds, withLowestPriorPrice)` (1–48 id; inaczej `InvalidProductOffersQuery`,
  `InvalidInput`) w `Cart_Application`: warianty z `ProductCatalog::variantsOf` (Catalog `ListProductVariants`), ceny
  skuteczne z `CartPrices` (Pricing `GetCurrentPrices` z tą samą flagą) i stany z poziomem z `StockAvailability`
  (Inventory `GetStockLevels`), po jednym wywołaniu każdego portu dla wszystkich wariantów strony (bez wariantów ceny
  i stany nie są pytane). Odczyt bez blokad. Flaga `withLowestPriorPrice` (cena Omnibus w promocjach) jest `true` dla
  endpointu ofert i `false` w `QueryBusProductOfferFacts` (indeks wyszukiwania dostaje cenę skuteczną bez Omnibusa;
  mapowanie indeksu bez zmian).
- Wariant jest możliwy do kupienia, gdy `ProductOffer::purchasability(1)` = `Purchasable` (aktualna cena i
  `available > 0`; `VariantOffer::isPurchasable`): ta sama reguła co przy dodaniu do koszyka, bez drugiej implementacji.
- Podsumowanie produktu liczy value object domeny `ProductOfferSummary`, jedyne miejsce tej reguły: `fromGross` =
  najniższe brutto (skuteczne) wariantów możliwych do kupienia, `fromPromotion` = promocja (`VariantPromotion`)
  wariantu, który daje `fromGross` (przy równych cenach pierwszy w kolejności pozycji; `null`, gdy ten wariant nie jest
  w promocji), `hasPriceRange` = ich najwyższe brutto ≠ najniższe, `level` = najlepszy poziom (`in_stock` > `low_stock`)
  wśród nich; bez wariantu możliwego do kupienia (także produkt nieznany Catalogowi): `fromGross = null`,
  `fromPromotion = null`, `hasPriceRange = false`, `level = out_of_stock`. Próg poziomu zostaje w Inventory.
- Wynik: oferta dla każdego żądanego id (powtórzone id raz, w kolejności żądania) `{productId, variantAxes, fromGross|null,
  fromPromotion: {lowestPriorGross|null}|null, hasPriceRange, currency: "PLN", level, variants: [{variantId, label,
  gross|null, regularGross|null, promotion: {endsAt, lowestPriorGross|null}|null, level, purchasable}]}`, warianty
  w kolejności pozycji. `gross` to cena skuteczna (w promocji promocyjna), `regularGross` cena katalogowa brutto, oba
  tylko z aktualnej ceny; `promotion` to promocja aktywna wariantu (`endsAt` w UTC, RFC 3339, okres prawostronnie
  otwarty), `lowestPriorGross` jej cena Omnibus z Pricing (`null`: wariant bez ceny przed startem promocji). Produkt
  nieznany Catalogowi: `variantAxes: []`, `variants: []`. Liczba sztuk nigdy nie jest zwracana.

## Persystencja i transakcje

Tabele:
- `cart_cart`: `id uuid` (PK), `owner_type` (`guest` | `customer`), `customer_id varchar(255) NULL`, `status`
  (`open` | `merged`), `merged_into uuid NULL`, `merged_at timestamptz NULL`, `created_at`, `updated_at`, `expires_at`
  (wszystkie czasy `timestamptz`).
  - Indeks unikalny `customer_id WHERE customer_id IS NOT NULL` (jeden koszyk na klienta).
  - Indeks `expires_at` (czyszczenie).
  - `CHECK ((owner_type = 'customer') = (customer_id IS NOT NULL))`,
    `CHECK (status = 'open' OR owner_type = 'guest')`.
- `cart_line`: `id uuid` (PK zastępczy, UUID v7), `cart_id uuid` (FK → `cart_cart` `ON DELETE CASCADE`), `variant_id uuid`,
  `quantity int` (`CHECK (quantity BETWEEN 1 AND 99)`), `added_at`; unikalny indeks `(cart_id, variant_id)`
  (`cart_line_cart_variant_key`) oraz indeks
  `cart_id` (`cart_line_cart_id_idx`). Indeks `cart_id` jest potrzebny mimo unikalnego: Doctrine DBAL dodaje własny
  indeks na kolumnach klucza obcego (`cart_id`, z `ManyToOne` w `CartLineRecord`), chyba że istnieje indeks o dokładnie
  tych samych kolumnach, a unikalny `(cart_id, variant_id)` się nie liczy (inna liczba kolumn). Bez nazwanego
  `#[ORM\Index]` Doctrine wygenerowałby indeks `IDX_…` na `cart_id` i schemat rozjechałby się z mapowaniem.
  Migracje: `Version20261103120000CreateCartTables`, `Version20261105120000KeyCartLinesByVariant` (przemianowanie
  `product_id` na `variant_id` i indeksu unikalnego bez zmiany wartości: migracja Catalog dała każdemu produktowi
  wariant domyślny o `id` produktu, więc linia sprzed zmiany wskazuje wariant domyślny swojego produktu, a istniejące
  koszyki działają dalej).
- Bez kluczy obcych do tabel Catalog. Kaskada przy usuwaniu linii jest dozwolona, bo koszyki są krótkotrwałe
  (w odróżnieniu od rezerwacji Inventory).

Zapis i odczyt:
- Rekordy Doctrine z atrybutami w `Cart/Infrastructure/Persistence/Doctrine/Record` (`CartRecord`, `CartLineRecord`;
  mapowanie `Cart` w `config/packages/doctrine.yaml`), mapowanie agregat ↔ rekord w `DoctrineCartRepository`
  (`lock`, `lockForCustomer`, `lockOrCreateForCustomer`, `add`, `save`), `reconstitute()`. Zapis kończy transakcja
  `command.bus` (`doctrine_transaction`). Linie są zapisywane w miejscu po wariancie, więc usunięcie i ponowne dodanie
  wariantu w jednej zmianie nie trafia na unikalny indeks.
- Odczyty (`GetCart`, `GetCartSummary`) czytają przez połączenie (DBAL, `DbalCartReader`: jedno zapytanie z
  `LEFT JOIN` linii), bez blokady i bez transakcji, jak `DoctrineProductPriceRepository::findMany`.

Blokady i jawne wyjątki od zasad (zapisywane też w `apps/api/CLAUDE.md`):
- **Blokada wiersza koszyka.** Każda komenda zmieniająca koszyk zaczyna od blokady jego wiersza:
  `EntityManager::find(CartRecord, id, LockMode::PESSIMISTIC_WRITE)`. Gdy rekord jest już w identity map, najpierw
  `SELECT 1 … FOR UPDATE` sprawdza, czy wiersz jeszcze istnieje (brak: rekord jest odłączany, wynik to `null`), potem
  `EntityManager::refresh(record, LockMode::PESSIMISTIC_WRITE)` i `refresh` każdej linii, już po blokadzie (kaskada
  `refresh` Doctrine czytałaby linie przed wierszem koszyka, w odwrotnej kolejności niż inne transakcje), więc stan jest
  czytany po uzyskaniu blokady. Blokada szereguje zmiany z dwóch kart i scalenie bez 409 i bez ponawiania; jeden
  wiersz na koszyk, więc rywalizacja jest pomijalna. To wyjątek od reguły „optimistic locking dla edycji z widoku”:
  koszyk nie ma wersji z widoku, zmiana ilości z nieaktualnej karty nadpisuje nowszą (ostatni zapis wygrywa),
  a dodanie zwiększa ilość.
- **Założenie koszyka klienta.** `lockOrCreateForCustomer(customerId)` wykonuje przez DBAL
  `INSERT INTO cart_cart … ON CONFLICT (customer_id) WHERE customer_id IS NOT NULL DO NOTHING`, odczytuje id
  koszyka klienta (`SELECT id … WHERE customer_id`) i blokuje go przez ORM jak wyżej. Bez tego dwa równoległe pierwsze dodania kończą się naruszeniem
  unikalności, które zamyka `EntityManager` (ten sam powód co w Inventory). Linie nie potrzebują upsertu: szereguje
  je blokada koszyka.
- Koszyk gościa powstaje tylko przez `CreateGuestCart` (nowy losowy id, bez wyścigu).
- Poziom izolacji: READ COMMITTED.

### Scalanie (`MergeGuestCart`)

Jedna transakcja `command.bus`:
1. Blokada koszyka gościa (`FOR UPDATE` jak wyżej) i odczyt statusu po uzyskaniu blokady.
2. Brak koszyka (także `guestCartId`, które nie jest UUID), koszyk wygasły albo `guestCartId` wskazujące koszyk klienta:
   wynik `nothing_to_merge`, bez zmian. Status `merged` (także wygasły): wynik `already_merged`, bez zmian. Widok koszyka
   klienta w obu przypadkach to odczyt bez blokady (jak `GetCart`; klient bez koszyka: pusty widok z `id = null`, nic
   nie jest zakładane), więc taka transakcja trzyma najwyżej blokadę wiersza wskazanego jako koszyk gościa.
3. `lockOrCreateForCustomer(customerId)`.
4. `customer.mergeFrom(guest, now)` (w tym wyczyszczenie wygasłego koszyka klienta), zapis obu koszyków.
5. Jeden commit. Każdy błąd wycofuje całość: koszyk gościa zostaje `open`, koszyk klienta bez zmian. Oznaczenie
   `merged` istnieje tylko po udanym scaleniu.

- Kolejność blokad zawsze: gość, potem klient. Dwie blokady naraz bierze tylko scalenie, więc nie ma zakleszczeń
  (dwa scalenia różnych gości do jednego klienta i dwa scalenia jednego gościa do różnych klientów blokują w tej
  samej kolejności).
- Zmiana koszyka gościa (dodanie, zmiana, usunięcie) blokuje ten sam wiersz i sprawdza status: żądanie zatwierdzone
  przed scaleniem trafia do scalenia, żądanie po scaleniu dostaje 404 bez zmian. Linie gościa są usuwane przy
  scaleniu, więc podwójne przeniesienie jest niemożliwe także przy powtórzonym scaleniu.

### Czyszczenie (`cart:purge-expired`)

- Usuwa koszyki z `expires_at < now` (gości w każdym statusie i klientów) partiami po 1000 id po indeksie
  `expires_at` (opcja `--batch-size`), każda partia w osobnej transakcji; linie usuwa kaskada. `command.bus` otacza
  handler jedną transakcją, więc `PurgeExpiredCarts(batchSize)` usuwa jedną partię i zwraca jej liczbę, a komenda
  konsoli wysyła ją w pętli, aż partia będzie pusta, i wypisuje każdą partię oraz sumę. Partię usuwa jedno
  polecenie DBAL (port `ExpiredCarts`, `DbalExpiredCarts`): `WITH batch AS MATERIALIZED (SELECT id … ORDER BY
  expires_at, id LIMIT … FOR UPDATE SKIP LOCKED) DELETE … USING batch`.
- Współbieżność: komendy zmieniające koszyk blokują jego wiersz i odnawiają `expires_at` klienta (`touch`), więc
  partia wybiera id przez `FOR UPDATE SKIP LOCKED` (zablokowany koszyk jest pomijany, nie czekamy) i powtarza warunek
  `expires_at < now` w `DELETE`: odnowiony koszyk nie jest usuwany. Odwrotna kolejność: koszyk klienta usunięty
  przez czyszczenie między `INSERT … ON CONFLICT DO NOTHING` (który go pominął) a blokadą w `lockOrCreateForCustomer`
  nie kończy komendy klienta błędem. Blokada nie znajduje wiersza, więc `lockOrCreateForCustomer` raz powtarza
  `INSERT` i blokadę (po zatwierdzeniu czyszczenia `INSERT` w READ COMMITTED dodaje nowy wiersz), a pierwsza zmiana
  wygasłego koszyka klienta udaje się i zaczyna od pustego koszyka. Drugie chybienie to `LogicException`.
- Uruchamiane świadomie (ręcznie albo z crona). Nie wywołują go `make init`, `make up` ani `make seed`.
- Kod wyjścia: 0 – sukces (także nic do usunięcia), 1 – rozmiar partii nie jest dodatnią liczbą całkowitą do 9 cyfr
  (bez zmian), 2 – przerwane błędem (np. niedostępna baza); usunięte wcześniej partie zostają usunięte.

## Warstwa aplikacji

Komendy (`command.bus`), każda zwraca `CartView`, chyba że opisano inaczej:
- `CreateGuestCart()` → pusty `CartView` z nowym `id`.
- `AddCartItem(cartRef, variantId, quantity)`, `ChangeCartItemQuantity(cartRef, variantId, quantity)`,
  `RemoveCartItem(cartRef, variantId)`, gdzie `cartRef` (`CartRef`) to `guest(cartId)` albo `customer(customerId)`.
  - Koszyk gościa (`LockedCarts`): blokada; brak, id niebędące UUID, wygasły, `merged` albo id koszyka klienta: 404.
    Koszyk klienta: `lockOrCreateForCustomer` (`RemoveCartItem` klienta bez koszyka: pusty widok, bez zakładania koszyka).
  - `AddCartItem` i zwiększenie w `ChangeCartItemQuantity`: wariant nieznany Catalogowi (`ProductCatalog`; także
    identyfikator produktu zamiast wariantu) albo `variantId` niebędące UUID (dodanie) to `ProductNotFound` (404);
    `ProductOffer` z `CartPrices` i `StockAvailability`. Zmniejszenie nie potrzebuje oferty ani znajomości wariantu przez
    Catalog. `variantId` niebędące UUID w zmianie ilości to `CartLineNotFound`, w usunięciu: sukces bez zmian.
- `MergeGuestCart(customerId, guestCartId)` → `MergeResult {cart: CartView, result, truncated}`.
- `LockCartForOrder(cartRef)` → `list<CartLineForOrder {variantId, quantity}>` w kolejności `addedAt`, dla Ordering:
  blokada jak w innych komendach (`LockedCarts`, koszyk klienta przez `lockForCustomer`, bez zakładania koszyka),
  `openForChange(now)` i linie z `linesAt(now)`. Koszyk gościa nieznany, id niebędące UUID, wygasły albo id koszyka
  klienta: `CartNotFound` (404); `merged`: `GuestCartMerged` (404). Klient bez koszyka albo z wygasłym koszykiem: brak
  linii. Niczego nie zapisuje: wygasły koszyk klienta jest tylko czytany jako pusty. Blokada trwa do końca transakcji
  Ordering, więc zmiany koszyka i inne złożenia z niego czekają.
- `ClearCartAfterOrder(cartRef)` → nic, dla Ordering po złożeniu zamówienia: blokada jak w każdej komendzie (w transakcji,
  która już zablokowała koszyk przez `LockCartForOrder`, to odświeżenie rekordu pod tą samą blokadą),
  `Cart::clearAfterOrder(now)` (usuwa wszystkie linie i wywołuje `touch(now)`; koszyk gościa zostaje `open`) i zapis.
  Klient bez koszyka: bez zmian. Wycofanie transakcji Ordering (np. przy niedoborze stanu) przywraca linie.
- `PurgeExpiredCarts(batchSize)` → liczba koszyków usuniętych w jednej partii.

Zapytania (`query.bus`):
- `GetCart(cartRef, discountCode | null)` → `CartView`. Klient bez rekordu koszyka: pusty widok z `id = null`; wygasły
  koszyk klienta: pusty widok z `id` koszyka; nic nie jest zakładane. Koszyk gościa nieznany, wygasły albo `merged`: 404.
  Kod (pusty albo biały to brak kodu) idzie do wyceny bez zmian; jego format i rodzaje odrzucenia ocenia Pricing.
- `GetCartSummary(cartRef)` → `CartSummary {itemCount}`, jeden odczyt koszyka, bez portów. Te same reguły 404 i pustego koszyka.

Porty (`Cart_Application/Port`) i adaptery (`Cart_Infrastructure`):

| Port | Adapter | Zapytanie |
|---|---|---|
| `ProductCatalog::summaries(variantIds)` → `VariantSummary {variantId, productId, name, label, image}` | `QueryBusProductCatalog` | Catalog `GetVariantSummaries` |
| `ProductCatalog::variantsOf(productIds)` → `ProductVariants {productId, variantAxes, variants: [{variantId, label}]}` | `QueryBusProductCatalog` | Catalog `ListProductVariants` |
| `CartPrices::current(variantIds, withLowestPriorPrice = false)` → `CartPrice {variantId, gross: Money, regularGross: Money, promotion: VariantPromotion {endsAt, lowestPriorGross: Money\|null}\|null}` | `QueryBusCartPrices` | Pricing `GetCurrentPrices` |
| `CartQuote::quote(lines {variantId, quantity}, discountCode)` → `CartQuoteResult {lines: {unitGross, grossBeforeDiscount} po wariancie, subtotalGross, discountGross, gross, discount}` (1–50 linii `purchasable`) | `QueryBusCartQuote` | Pricing `QuoteBasket` |
| `StockAvailability::of(variantIds)` → `VariantAvailability {variantId, available, level}` | `QueryBusStockAvailability` | Inventory `GetStockLevels` |
| `CartReader::find(id)`, `findForCustomer(customerId)` | `DbalCartReader` | odczyt DBAL |
| `ExpiredCarts::purgeBatch(now, limit)` | `DbalExpiredCarts` | usunięcie partii DBAL |
| `Clock::now()` | `SystemClock` | |

Adaptery zapytań nie wołają magistrali bez id i przekazują przyczynę błędu opakowanego przez magistralę (np. utracone
połączenie). `QueryBusCartPrices` pomija cenę w walucie innej niż `PLN` (jak brak ceny), `QueryBusStockAvailability`
obcina ujemną dostępność do 0.

Zapytanie Catalog `GetVariantSummaries(variantIds)` (1–100 id) → `{variantId, productId, name, label, image|null}` w
kolejności żądania, dla wariantów obecnych w bazie (powtórzone id odpowiada raz); jedno zapytanie DBAL po tabelach
Catalog (nie Elasticsearch), zdjęcie główne produktu w istniejącym rozmiarze `listing`. Opis zapytań Catalog o warianty
jest w `docs/features/variants/spec.md`.

## API

Sklep (`apps/api/openapi.json`). Błędy w formacie RFC 9457, `detail` z wyjątku domeny. Wszystkie odpowiedzi pod
`/api/v1/guest-carts` i `/api/v1/me/cart` (także błędy, 401 zapory i 404 ścieżki z id niebędącym UUID) mają
`Cache-Control: private, no-store` (`CartResponseCacheSubscriber`).

`security.yaml`: `^/api/v1/guest-carts(/|$)` → `PUBLIC_ACCESS` dla wszystkich metod (przed regułą `ROLE_CUSTOMER`);
`/api/v1/me/cart…` objęte istniejącą regułą `ROLE_CUSTOMER`.

Oferta produktów listy: `GET /api/v1/product-offers?productId=…&productId=…` (`ProductOffersController` w `Cart_UI`,
`PUBLIC_ACCESS` dla `GET` w `security.yaml`, `Cache-Control: public, max-age=60, s-maxage=3600` i `xkey: catalog product-<id> …` dla
każdego żądanego id (UUID małymi literami, powtórzone id raz); token wysłany i niepoprawny: 401; 400, 401 i 503 mają
`private, no-store` bez tagów). Sklep czyta ofertę przez Varnish (`API_CATALOG_URL`).
Identyfikatory jako powtórzony klucz `productId` (forma `productId[]` nie jest kontraktem). 1–48 id; brak, ponad 48
albo id niebędące UUID: 400 z `violations`. 200 `{items: [oferta z sekcji „Oferta produktów listy”]}`, jedna pozycja na
każde żądane id. Varnish trzyma odpowiedź do purge tagu produktu po zdarzeniu zmiany ceny albo poziomu dostępności
(`docs/features/product-page/spec.md`, „Unieważnianie”), najwyżej `s-maxage` (1 h). Nie decyduje więc o zakupie:
dodanie do koszyka sprawdza cenę i stan od nowa.

Gość (bez tokena; token wysłany mimo to i niepoprawny daje 401, a gdy nie da się pobrać kluczy wystawcy tokenu: 503):

| Endpoint | Sukces | Błędy |
|---|---|---|
| `POST /api/v1/guest-carts` | 201 `CartView` (pusty), nagłówek `Location`; ciało nie jest czytane | |
| `GET /api/v1/guest-carts/{cartId}?discountCode=` | 200 `CartView` (z podglądem kodu) | 400 (kod nie jest tekstem, nie jest UTF-8 albo ma ponad 64 znaki), 404 |
| `GET /api/v1/guest-carts/{cartId}/summary` | 200 `{itemCount}` | 404 |
| `POST /api/v1/guest-carts/{cartId}/items`, body `{variantId: uuid, quantity: int}` | 200 `CartView` | 400 treść nie jest poprawnym JSON-em; 404 koszyk albo wariant; 409 `CartLineQuantityLimit`, `CartLineLimit`, `ProductNotPurchasable`, `InsufficientStock`; 415 treść inna niż JSON; 422 (z `violations`, komunikaty w punkcie „Komunikaty 422” niżej) |
| `PUT /api/v1/guest-carts/{cartId}/items/{variantId}`, body `{quantity: int}` | 200 `CartView` | jak wyżej oraz 404 `CartLineNotFound` |
| `DELETE /api/v1/guest-carts/{cartId}/items/{variantId}` | 200 `CartView` (brak linii: bez zmian) | 404 koszyk |

Klient (token realmu `shop`; bez tokena albo z tokenem realmu `backoffice`: 401; gdy nie da się pobrać kluczy
wystawcy tokenu: 503):

| Endpoint | Sukces | Błędy |
|---|---|---|
| `GET /api/v1/me/cart?discountCode=` | 200 `CartView` (bez koszyka: pusty, `id: null`; z podglądem kodu) | 400 (jak wyżej), 401 |
| `GET /api/v1/me/cart/summary` | 200 `{itemCount}` | 401 |
| `POST /api/v1/me/cart/items` | 200 `CartView` | 401 oraz jak u gościa (404 tylko wariant) |
| `PUT /api/v1/me/cart/items/{variantId}` | 200 `CartView` | 401 oraz jak u gościa (404 wariant albo linia) |
| `DELETE /api/v1/me/cart/items/{variantId}` | 200 `CartView` | 401 |
| `POST /api/v1/me/cart/merge`, body `{guestCartId: uuid}` | 200 `{cart: CartView, result: "merged" \| "already_merged" \| "nothing_to_merge", truncated: bool}` | 400, 401, 415, 422 (z `violations`, jak niżej) |

- Identyfikator w ścieżce (`cartId`, `variantId`), który nie jest UUID (w dowolnej wielkości liter): 404, bo trasa nie
  pasuje. Typy ścisłe, bez rzutowania (liczba w napisie, ułamek: 422).
- Komunikaty 422 (`violations[*].title`, `propertyPath` to nazwa pola). Wszystkie po polsku, bez danych wejściowych,
  a jedno pole daje najwyżej jedno naruszenie (ograniczenia pola sprawdzane po kolei, `Assert\Sequentially`). Brak
  klucza serializer zamienia na `null` (pola DTO są `mixed`, bez `REQUIRE_ALL_PROPERTIES`), a wymaga go
  `Assert\NotNull`; pola nadal są `required` w OpenAPI, bez `nullable` i `default`:

  | Pole (żądanie) | Przypadek | Komunikat |
  |---|---|---|
  | `variantId` (dodanie) | brak klucza albo `null` | Identyfikator wariantu jest wymagany. |
  | `variantId` | nie jest napisem albo jest niepustym napisem, który nie jest UUID | Identyfikator wariantu musi być UUID. |
  | `quantity` (dodanie, zmiana) | brak klucza albo `null` | Ilość jest wymagana. |
  | `quantity` | nie jest liczbą całkowitą (napis, ułamek, `2.0`, wartość logiczna, tablica) | Ilość musi być liczbą całkowitą. |
  | `quantity` | spoza 1–99 | Ilość musi wynosić od 1 do 99 szt. |
  | `guestCartId` (scalenie) | brak klucza (także puste ciało) albo `null` | Identyfikator koszyka jest wymagany. |
  | `guestCartId` | nie jest napisem albo jest niepustym napisem, który nie jest UUID | Identyfikator koszyka musi być UUID. |

  Pusty napis `""` nie jest naruszeniem (`Assert\Uuid` pomija pusty napis). `variantId: ""` przy dodaniu daje 404, jak wariant
  niedostępny, a `guestCartId: ""` przy scaleniu daje 200 z `result: "nothing_to_merge"`, jak koszyk gościa, którego nie ma.

- Scalony koszyk gościa odpowiada 404 jak nieznany: BFF reaguje na oba tak samo, więc format błędów
  (`type: about:blank`) się nie zmienia.
- Klient zawsze działa na swoim koszyku (`sub` z tokena); identyfikatora koszyka klienta nie ma w ścieżce.

`CartView`:

```
{
  id: uuid | null,
  lines: [{
    variantId: uuid,
    product: { productId: uuid, name: string, variantLabel: string, image: ProductImage | null } | null,
    quantity: int,
    unitPrice: { gross: int, currency: "PLN" } | null,
    lineTotal: { gross: int, currency: "PLN" } | null,
    state: "purchasable" | "no_price" | "out_of_stock" | "insufficient_stock" | "unavailable_product"
  }],
  totals: { subtotalGross: int, discountGross: int, gross: int, currency: "PLN", itemCount: int },
  hasExcludedLines: bool,
  discount: { status: "applied", code: string, amountGross: int, kind: "percent" | "amount", value: int }
          | { status: "rejected", code: string, reason: "invalid" | "used_up" | "below_minimum", detail: string,
              minimumOrderGross: int | null }
          | null
}
```

Parametr `discountCode` (`CartQuery`, `#[MapQueryString]`, 400 z `violations` przy `discountCode`): nie-napis (np.
`discountCode[]=`) „Kod rabatowy musi być tekstem.”, ponad 64 znaki „Kod rabatowy może mieć najwyżej 64 znaki.”, bajty
spoza UTF-8 „Kod rabatowy zawiera niedozwolone znaki.”. Pusty albo z samych białych znaków to brak kodu; inny tekst
(także znak sterujący albo spoza `[A-Za-z0-9-]`) to kod odrzucony jako `invalid` z `code` po przycięciu i zamianie liter
ASCII na wielkie.

Linie w kolejności `addedAt`. `product` jest `null` dla `unavailable_product`. Liczba dostępnych sztuk nigdy nie jest
zwracana.

## Sklep (`apps/store`)

### BFF

- Ciasteczko `store_cart`: identyfikator koszyka gościa z podpisem HMAC (`signValue` z `src/auth/cookies.ts`, klucz
  `STORE_SESSION_SECRET`), `HttpOnly`, `SameSite=Lax`, `Path=/`, `Secure` poza lokalnym sklepem, `Max-Age` 30 dni
  odnawiane przy każdym udanym zapisie. Podpis nie szyfruje wartości: identyfikator jest czytelny dla każdego, kto ma
  ciasteczko (np. w narzędziach przeglądarki). Podpis odrzuca wartości, których nie wydał sklep, zanim trafią do API,
  a `HttpOnly` chroni przed odczytem z JavaScriptu. Ważność koszyka rozstrzyga API (`expires_at`).
- Ciasteczko `store_cart_notice`: znacznik `merge_truncated`, podpisane, `HttpOnly`, `SameSite=Lax`, `Path=/`, `Secure`
  jak wyżej, `Max-Age` 5 minut.
- Route handlery w `app/api/cart/` (odpowiedzi `Cache-Control: private, no-store`), owinięte w `withCartDeps`
  (zależności: sesje, `freshAccessToken`, klient API; przy ich braku 503), na wzór `withAuthDeps`; logika w
  `runCartCommand` (`src/cart/bff.ts`):
  - `GET /api/cart` → `CartView`;
  - `GET /api/cart/summary` → `{itemCount, notice: "merge_truncated" | null}`; komunikat zwracany raz, ciasteczko
    `store_cart_notice` usuwane;
  - `POST /api/cart/items` `{variantId, quantity}`;
  - `PATCH /api/cart/items/[variantId]` `{quantity}`;
  - `DELETE /api/cart/items/[variantId]`.
- Żądania zmieniające (`POST`, `PATCH`, `DELETE`) wymagają nagłówka `Origin` równego originowi `STORE_URL` i (`POST`,
  `PATCH`) `Content-Type: application/json`; inaczej 403 (ochrona przed CSRF), bez wywołania API. Ciało `POST` bez
  `variantId` (napis) i `quantity` (liczba) albo ciało `PATCH` bez `quantity` (liczba) to 422 „Nieprawidłowe dane.”,
  a `variantId` w ścieżce `PATCH` i `DELETE`, które nie jest UUID, to 404 „Tej pozycji nie ma w koszyku.” (oba bez
  wywołania API).
- Wybór koszyka:
  1. Sesja istnieje: koszyk klienta (token z `freshAccessToken`). Jeśli jest też `store_cart`, najpierw
     `POST /me/cart/merge` (`mergeGuestCart` w `src/cart/merge.ts`); każda odpowiedź 200 (`merged`, `already_merged`,
     `nothing_to_merge`) usuwa `store_cart`, a `truncated` przy `merged` ustawia `store_cart_notice`. Błąd scalenia
     zostawia ciasteczko, trafia do logu (`logFailure`) i operacja idzie dalej na koszyku klienta; 401 scalenia kończy
     sesję (jak 401 każdego wywołania API), ciasteczko zostaje. Koszyk gościa, który zniknął (wygasły, scalony), załatwia
     samo scalenie: odpowiada `nothing_to_merge` albo `already_merged` i ciasteczko jest czyszczone.
  2. Bez sesji: koszyk gościa z `store_cart`. Pierwsze dodanie bez ciasteczka: `POST /guest-carts`, ustawienie
     `store_cart`, potem dodanie. Odczyt i usunięcie bez ciasteczka: pusty koszyk bez wywołania API (`summary`:
     `itemCount = 0`, `notice = null`); zmiana ilości bez ciasteczka: 404 „Tej pozycji nie ma w koszyku.”.
  3. `store_cart` bez poprawnego podpisu albo z podpisaną wartością, która nie jest UUID: ciasteczko usuwane, jak brak
     ciasteczka (przy sesji bez scalania).
  4. 404 koszyka gościa (nieznany, wygasły, scalony) bez sesji. BFF nie zgaduje po tekście `detail`. Odczyt i usunięcie:
     404 oznacza koszyk, który zniknął, więc `store_cart` jest usuwane, a odpowiedzią jest pusty koszyk. Dodanie i
     zmiana ilości pytają o `summary` koszyka: 404 oznacza koszyk, który zniknął (dodanie usuwa `store_cart`, zakłada
     nowy koszyk gościa i powtarza się raz; zmiana usuwa `store_cart` i zwraca 404 „Tej pozycji nie ma w koszyku.”),
     w przeciwnym razie 404 (produkt, pozycja) jest przekazywany.
- Sesja: token, którego nie da się odświeżyć, albo 401 API kończy sesję (usunięcie z Redisa i ciasteczka) i daje 401;
  chwilowy błąd Keycloaka, Redisa albo API daje 503 i zostawia sesję (jak `/auth/session`).
- `/auth/callback`: po utworzeniu sesji, gdy jest `store_cart`, woła `POST /me/cart/merge` (jak w punkcie 1, tym samym
  `mergeGuestCart` i `applyGuestCartMerge`, tokenem z właśnie uzyskanych tokenów). Błąd scalenia nie psuje logowania;
  scalenie powtórzy pierwsze żądanie koszyka.
- Kody BFF dla przeglądarki: 200 z `CartView` (`summary`: `{itemCount, notice}`); błędy API 404, 409 i 422 przekazywane
  z `detail`; 401 sesji; 403 `Origin` lub `Content-Type`; 503 niedostępne zależności lub API (także 400 i 415 API, które
  byłyby błędem BFF).
- `src/api/client.ts` dostaje funkcje przeglądarki wołające `/api/cart/*` (`loadCart`, `loadCartSummary`, `addCartItem`,
  `changeCartItemQuantity`, `removeCartItem`; jedyne miejsce `fetch`). Zapisy idą jeden po drugim we wszystkich kartach
  sklepu w przeglądarce, żeby równoczesne pierwsze dodania gościa nie założyły kilku koszyków:
  - kolejka na poziomie modułu (`queueWrite`) ustala kolejność zapisów karty: następny startuje po zakończeniu
    poprzedniego, także po błędzie;
  - każdy zapis z kolejki, gdy przeglądarka ma `navigator.locks` (Web Locks API, tylko w bezpiecznym kontekście: HTTPS
    albo `localhost`), trzyma blokadę `store-cart-write` (`navigator.locks.request`, tryb `exclusive`) od wysłania
    żądania do końca odpowiedzi. Ciasteczko `store_cart` z odpowiedzi pierwszego dodania jest już zapisane, gdy rusza
    zapis z innej karty, więc ten trafia do tego samego koszyka. Zapis, który się nie udał (błąd HTTP, błąd sieci),
    zwalnia blokadę i nie wstrzymuje następnego. Odrzucenie samego `request` (np. `SecurityError`) daje wynik `failed`
    bez wysłania żądania, a kolejka idzie dalej; funkcje zapisu nigdy nie odrzucają obietnicy;
  - bez `navigator.locks` zapisy idą przez samą kolejkę karty (ograniczenie w „Ryzykach i ograniczeniach”);
  - odczyty (`loadCart`, `loadCartSummary`) nie czekają ani w kolejce, ani na blokadę.

### UI

- `ProductTile` na liście (Server Component: nazwa, zdjęcie, cena „od X zł” przy `hasPriceRange`, inaczej „X zł”, i
  etykieta poziomu produktu z oferty z `GET /api/v1/product-offers`, którą ładuje `loadCatalog` jednym wywołaniem dla
  strony). Produkt bez wariantu możliwego do kupienia (`fromGross = null`, także z ≥ 2 wariantami albo nieznany
  Catalogowi): „Niedostępny” bez ceny, listy i przycisku. Błąd wywołania ofert: kafelki z „Cena niedostępna”, bez etykiety,
  listy i przycisku, błąd w logu serwera. Zdjęcie nie zmienia się przy wyborze wariantu.
- Część kliencka kafelka (`TileOffer`, dostaje ofertę przez propsy): produkt z jednym wariantem ma od razu przycisk
  `AddToCartButton` („Do koszyka”), który dodaje ten wariant; produkt z ≥ 2 wariantami ma jedną aktywną akcję „Wybierz
  wariant”, bez listy rozwijanej i bez nieaktywnego „Do koszyka”. Po niej akcja znika, kafelek pokazuje warianty oferty
  w jej kolejności (grupa radio opisana nazwami osi, np. „Kolor, Rozmiar”, bez wyboru domyślnego; jedna oś: przyciski
  w rzędzie, dwie osie lub więcej: lista pełnych etykiet), a fokus przechodzi na pierwszy wariant możliwy do wybrania.
  Wariant z `purchasable = false` ma dopisek „niedostępny” i nie da się go wybrać. Rozwinięcie i wybór są ogłaszane
  w obszarze `aria-live`. Po wyborze (można go zmienić) kafelek pokazuje cenę i etykietę poziomu wybranego wariantu oraz
  aktywny „Do koszyka”, który dodaje 1 sztukę wybranego wariantu. Na czas żądania przycisk jest zablokowany. HTML
  katalogu nie zależy od sesji.
- `CartBadge` w nagłówku: link „Koszyk” z liczbą sztuk z `/api/cart/summary`; po każdej zmianie koszyka licznik
  aktualizuje się z `itemCount` odpowiedzi (zdarzenie w przeglądarce, `src/cart/events.ts`). Do czasu wczytania i przy
  błędzie licznik jest ukryty, link zostaje.
- Strona `/cart`: `robots: noindex, nofollow`, treść (`CartPage`) jako Client Component ładujący `/api/cart`. Linie:
  zdjęcie (`ProductPhoto`), nazwa, pod nią etykieta wariantu (pusta dla wariantu domyślnego), cena jednostkowa, ilość
  (pole 1–99 opisane nazwą i etykietą wariantu, zatwierdzane po opuszczeniu pola lub Enterem;
  wartość niebędąca liczbą całkowitą 1–99 albo bez zmiany wraca do bieżącej ilości bez żądania), wartość linii, „Usuń”;
  podsumowanie: wartość brutto i liczba sztuk; pod nim przycisk „Przejdź do kasy” (link do `/checkout`,
  `docs/features/checkout/spec.md`). Przy `hasExcludedLines` przycisk jest nieaktywny, a pod nim tekst „Usuń albo popraw
  oznaczone pozycje, żeby złożyć zamówienie.”. Pusty koszyk: „Twój koszyk jest pusty” i link „Zobacz produkty”, bez
  przycisku.
- Kwoty przez `formatPrice`. Front niczego nie liczy: każdy widok pochodzi z ostatniej odpowiedzi API.

| Sytuacja | UI |
|---|---|
| dodanie OK | komunikat „Dodano do koszyka”, licznik z `itemCount` |
| 409 przy dodaniu albo zmianie ilości | `detail` z API przy kafelku albo linii; linia pokazuje poprzednią ilość z ostatniego widoku |
| 404 produktu przy dodaniu | „Produkt nie jest już dostępny” |
| 404 linii przy zmianie albo usunięciu | strona wczytuje koszyk ponownie i rozstrzyga po nim (404 nie dowodzi, że linii nie ma: zmiana ilości linii `unavailable_product` też dostaje 404). Linii nie ma w koszyku: „Pozycja została już usunięta”. Linia jest w koszyku: przy linii `detail` z 404 (dla `unavailable_product` „Produkt nie jest już dostępny.”), a bez `detail` „Nie udało się zapisać zmian. Spróbuj ponownie.”; pole ilości pokazuje ilość z wczytanego koszyka, „Usuń” zostaje. Wczytanie dało 401: „Twoja sesja wygasła. Zaloguj się ponownie.”; wczytanie nie udało się inaczej: „Nie udało się zapisać zmian. Spróbuj ponownie.” przy linii. W obu ostatnich przypadkach pole ilości pokazuje ilość z ostatniego widoku |
| 403, 422, 503, 5xx, błąd sieci | „Nie udało się zapisać zmian. Spróbuj ponownie.” (bez automatycznego ponowienia) |
| 401 (sesja wygasła), także przy wczytaniu `/cart` | „Twoja sesja wygasła. Zaloguj się ponownie.” z linkiem „Zaloguj się” (`/auth/login?returnTo=…`) |
| błąd wczytania koszyka | „Nie udało się wczytać koszyka” i przycisk „Spróbuj ponownie” |
| `hasExcludedLines` | przy sumie: „Część produktów nie jest wliczona”; „Przejdź do kasy” nieaktywny z tekstem „Usuń albo popraw oznaczone pozycje, żeby złożyć zamówienie.” |
| `notice: merge_truncated` | „Część produktów z koszyka gościa nie zmieściła się w koszyku” (`CartBadge`: osobny wiersz nagłówka pod pozostałymi, na pełną szerokość, w przepływie strony) |
| `no_price` | „Cena niedostępna” |
| `out_of_stock` | „Niedostępny” |
| `insufficient_stock` | „Dostępna ilość jest mniejsza niż w koszyku” |
| `unavailable_product` | „Produkt nie jest już dostępny”, bez nazwy i zdjęcia |

Każda linia z etykietą ma „Usuń”; zmniejszenie ilości jest zawsze możliwe.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| 100. sztuka wariantu albo 51. pozycja | 409, koszyk bez zmian |
| dwa warianty (np. rozmiary) jednego produktu | dwie linie; limit 99 sztuk dotyczy każdej osobno |
| koszyk z linią sprzed migracji na warianty | linia wariantu domyślnego swojego produktu, działa bez zmian |
| dodanie wariantu bez ceny albo niedostępnego | 409 `ProductNotPurchasable`; w sklepie opcja „niedostępny” bez wyboru, a produkt bez wariantu możliwego do kupienia nie ma przycisku |
| błąd `GET /api/v1/product-offers` w sklepie | lista bez cen, etykiet, wyboru wariantu i przycisków |
| ilość większa niż dostępna | 409 bez liczby sztuk |
| zmiana ilości z nieaktualnej karty | ostatni zapis wygrywa |
| równoległe dodania z dwóch kart | wszystkie zapisane w jednym koszyku, bez utraty zmian (zapisy kart czekają na siebie przez Web Locks API; bez niego pierwsze dodania gościa mogą założyć dwa koszyki, zob. „Ryzyka i ograniczenia”) |
| zmiana koszyka gościa po scaleniu | 404, bez zmian; BFF usuwa `store_cart`: dodanie zakłada nowy koszyk gościa, odczyt i usunięcie dają pusty koszyk, zmiana 404 pozycji |
| ponowne lub równoległe scalenie tego samego gościa | `already_merged`, pozycje przeniesione raz |
| scalenie z wygasłym koszykiem klienta | stare pozycje klienta usunięte, zostają pozycje gościa |
| błąd w trakcie scalenia | całość wycofana, gość `open`, klient bez zmian; ponowienie przy następnym żądaniu |
| wygasły koszyk gościa | 404, sklep pokazuje pusty koszyk |
| produkt usunięty z katalogu, wariant usunięty w panelu albo produkt wycofany z publikacji (szkic) | linia `unavailable_product`; dodanie wariantu szkicu do koszyka 404, złożenie zamówienia z taką linią 409 `CartNotOrderable`, oferta szkicu jak oferta nieznanego produktu |
| zmiana ceny | koszyk pokazuje cenę aktualną |
| żądanie zmieniające bez poprawnego `Origin` | 403 BFF |

## Kryteria akceptacji

1. Gość i zalogowany klient dodają produkt z kafelka, zmieniają ilość i usuwają pozycje; sumy, stany linii i licznik
   pochodzą z API.
2. Limity 50 pozycji i 99 sztuk; dostępność ogranicza dodanie i zwiększenie ilości bez rezerwacji i bez ujawniania
   liczby sztuk.
3. Koszyk przeżywa odświeżenie i ponowne wejście; klient widzi ten sam koszyk na innym urządzeniu.
4. Scalenie sumuje ilości z przycięciem do limitów, jest idempotentne i odporne na równoległe scalenia i równoległe
   zmiany koszyka gościa. Koszyk gościa jest oznaczony `merged` tylko po udanym scaleniu i potem nie da się go zmienić.
   Wygasły koszyk klienta jest czyszczony przed scaleniem.
5. Koszyki wygasają po 30 (gość) i 180 dniach (klient) od ostatniej zmiany; `cart:purge-expired` usuwa wygasłe.
6. Odpowiedzi koszyka mają `private, no-store`, strona `/cart` ma `noindex`.
7. Od Cart zależy tylko Ordering (`Ordering_Infrastructure → Cart_Application`); Deptrac odrzuca zależności spoza
   reguł Cart.
8. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check`,
   `make -C apps/store test` oraz `make e2e`.

## Testy

### Jednostkowe (TDD, adaptery in-memory; `apps/api/tests/Unit/Cart`)

- `Cart`: `add` (nowa linia, zwiększenie), granice ilości 1, 99, 100 i liczby linii 50, 51; dwa warianty jednego
  produktu to dwie linie z osobnym limitem 99; odrzucenie przez `ProductOffer`
  (brak ceny, `available = 0`, wynik > `available` → `ProductNotPurchasable` / `InsufficientStock`); `changeQuantity`
  (zmniejszenie zawsze, zwiększenie z kontrolą, brak linii); `remove` idempotentne; zmiana koszyka `merged` odrzucona;
  `touch` i `isExpired` dla gościa i klienta; `clearIfExpired`.
- `ProductOffer::purchasability` (`ProductOfferTest`): brak ceny przed `available = 0`, granice `quantity = available`
  i `available + 1`.
- `mergeFrom`: suma, przycięcie do 99 (`truncated`), pominięcie linii ponad 50 (`truncated`), kolejność `addedAt`,
  pusty gość, gość już `merged` (`already_merged`), linie gościa usunięte i status `merged`; **wygasły koszyk klienta
  z pozycjami: po scaleniu zawiera wyłącznie pozycje gościa**.
- `CartViewBuilder` (z `FakeCartQuote`): priorytet stanów, `unitPrice`, `lineTotal` (wartość przed rabatem) i sumy
  linii `purchasable` z wyceny (inne niż z `CartPrices`), wycena tylko linii `purchasable` w kolejności koszyka z kodem,
  linie wykluczone z `lineTotal = null`, bez linii `purchasable` brak wyceny, sumy 0 i `discount = null` także z kodem,
  linia pominięta przez wycenę jako `no_price` (wszystkie pominięte: sumy 0 i `discount = null` także z kodem), `itemCount` ze wszystkich linii, `hasExcludedLines`, pusty koszyk.
- `GetCart` przekazuje kod do wyceny; komendy wyceniają bez kodu.
- Handlery: 404 dla gościa nieznanego, wygasłego i scalonego; `ProductNotFound`; `nothing_to_merge` dla nieznanego,
  wygasłego i klienckiego `guestCartId`; `GetCart` klienta bez koszyka nic nie zakłada.
- `Cart::clearAfterOrder` (`CartClearAfterOrderTest`) i komendy dla Ordering (`CartOrderCommandsTest`):
  `LockCartForOrder` (linie w kolejności dodania bez zapisu, gość `merged`, wygasły, nieznany i id koszyka klienta,
  wygasły koszyk klienta i klient bez koszyka bez linii), `ClearCartAfterOrder` (gość zostaje `open` z nowym
  `expiresAt`, klient, klient bez koszyka bez zmian, gość `merged`).

- `ProductOfferSummary` (TDD): pominięcie wariantów bez ceny, z ceną nieaktualną i z `available = 0`, równe i różne
  ceny, brak wariantów możliwych do kupienia (`fromGross = null`, `out_of_stock`), najlepszy poziom, `fromPromotion`
  wariantu ceny „od” (także remis cen i tańszy wariant w promocji, którego nie da się kupić).
- `GetProductOffersHandler`: oferta na każde żądane id w kolejności żądania, produkt nieznany, jedno wywołanie
  każdego portu, 0, 49 i niepoprawne id, wariant w promocji (`regularGross`, `promotion`, `fromPromotion`) i flaga
  `withLowestPriorPrice` przekazana do `CartPrices`; `QueryBusProductOfferFacts` pyta bez flagi.

### Integracyjne (PostgreSQL)

W `apps/api/tests/Integration`:

- `Cart/Persistence/DoctrineCartRepositoryTest`: mapowanie rekordów, `CHECK` i indeks unikalny `customer_id`
  (bezpośredni SQL łamiący reguły jest odrzucany), kaskada linii, `lockOrCreateForCustomer` (`ON CONFLICT`), blokada z
  `refresh` rekordu obecnego w identity map (także usuniętego w międzyczasie) i ponowienie po usunięciu koszyka przez
  czyszczenie.
- `Cart/QueryBusAdaptersTest`: adaptery Catalog, Pricing (także cena skuteczna, cena katalogowa i promocja z ceną
  Omnibus tylko z flagą; wycena `QueryBusCartQuote` z kodem zastosowanym, odrzuconym i pustym, linia bez ceny pominięta)
  i Inventory na wariantach.
- `Cart/ProductOfferFactsTest`: fakty indeksu z ceną skuteczną wariantu w promocji, bez zapytań Omnibusa.
- `Cart/GetProductOffersTest`: 48 produktów po 100 wariantów z wariantem w promocji to sześć zapytań (warianty, ceny,
  promocje aktywne, historia i promocje okna Omnibusa, stany).
- `Cart/Persistence/CartLinesByVariantMigrationTest`: migracja na danych sprzed zmiany (linia zachowuje `id`, ilość
  i koszyk, wskazuje wariant domyślny swojego produktu), unikalność `(cart_id, variant_id)`, `down`.
- `Cart/MergeGuestCartTest`: wymuszony błąd w trakcie scalenia wycofuje całość (gość `open`, klient bez zmian, także
  przy pierwszym scaleniu klienta bez koszyka); **scalenie z wygasłym koszykiem klienta zapisanym w bazie: stare linie
  usunięte, zapisane tylko linie gościa, `expires_at` odnowione**.
- `Cart/PurgeExpiredCartsTest`: `cart:purge-expired` usuwa tylko wygasłe (z liniami), partiami; kod 0; rozmiar partii
  poniżej 1: kod 1 bez zmian; błąd w późniejszej partii albo niedostępna baza: kod 2, wcześniejsze partie zostają
  usunięte; koszyk zablokowany przez równoległą zmianę jest pomijany i zostaje po odnowieniu.

### Współbieżność (integracyjne, PostgreSQL)

`Cart/Concurrency/CartConcurrencyTest`. Jak C1–C6 w Inventory (`ConcurrentCommands`): każdy uczestnik to osobny proces
(`pcntl_fork`) z własnym jądrem i połączeniem, wspólny start przez barierę na advisory locku, wywołania handlerów na
`command.bus`, każdy scenariusz w 5 rundach. Po każdej rundzie: żaden koszyk nie ma ponad 50 linii ani linii ponad 99
sztuk, koszyk `merged` nie ma linii.

| ID | Scenariusz | Oczekiwany wynik |
|---|---|---|
| K1 | 10 równoległych scaleń tego samego gościa do tego samego klienta | 1 × `merged`, 9 × `already_merged`; ilości przeniesione dokładnie raz |
| K2 | scalenie równolegle z 10 dodaniami po 1 szt. do koszyka gościa | każde dodanie zakończone sukcesem jest w koszyku klienta, pozostałe dostały 404; ilość u klienta = liczba sukcesów (+ stan początkowy); po `merged_at` brak zmian koszyka gościa |
| K3 | 20 równoległych dodań do koszyka klienta (10 tego samego wariantu, 10 różnych) | ilość wspólnego wariantu = 10, wszystkie różne warianty zapisane |
| K4 | 2 równoległe pierwsze dodania klienta bez koszyka | jeden wiersz koszyka klienta, obie linie, żadnego błędu |
| K5 | ten sam gość scalany równolegle do 2 różnych klientów | dokładnie jeden klient dostaje linie, drugi `already_merged` |
| K6 | 2 różne koszyki gościa scalane równolegle do tego samego klienta | oba `merged`, ilości zsumowane, brak zakleszczeń |
| K7 | równoległe dodania różnych produktów do koszyka z 48 liniami | dokładnie 2 sukcesy, reszta `CartLineLimit` |
| K8 | 2 klientów z koszykami scala równolegle nawzajem id swoich koszyków | oba `nothing_to_merge`, brak zakleszczeń, koszyki bez zmian |

### Funkcjonalne API

`apps/api/tests/Functional/Cart` (`GuestCartEndpointTest`, `CustomerCartEndpointTest`, `CustomerCartMergeEndpointTest`).
Każdy endpoint: 200/201, 400 (zły JSON), 401 (`/me/cart…` bez tokena i z tokenem `backoffice`), 404 (nie-UUID,
nieznany, wygasły i scalony koszyk gościa, nieznany wariant i id produktu zamiast wariantu, brak linii), 409 (każda przyczyna), 415, 422 (każdy przypadek tabeli komunikatów: brak klucza, `null`, zły typ, zły UUID, ilość spoza 1–99; asercja na dokładną listę `violations`), `Cache-Control: private, no-store`; `merge`: każdy `result` i `truncated`.
`ProductOffersEndpointTest`: oferty bez tokena (cena „od”, zakres, poziom, warianty z etykietami, cena nieaktualna i brak
stanu pominięte), kolejność i powtórzone id, produkt nieznany i id wariantu zamiast produktu, 48 id, 400 (brak, 49,
niepoprawny UUID, forma `[]`), 401 przy złym tokenie, `public, max-age=60, s-maxage=3600`, brak liczby sztuk w odpowiedzi,
warianty w promocji (`gross` promocyjne, `regularGross`, `promotion` z `endsAt` i ceną Omnibus, także `null` bez ceny
przed startem, `fromPromotion`). `GuestCartEndpointTest`: linia wariantu w promocji ma cenę promocyjną bez oznaczenia
promocji.
`CartDiscountCodeEndpointTest`: koszyk gościa i klienta z kodem zastosowanym (procentowym i kwotowym na granicy
minimum), każdy powód odrzucenia z `detail` i `minimumOrderGross` (nieznany, wyłączony, przed początkiem, wygasły, zły
format, znak sterujący, 64 znaki, wykorzystany, poniżej minimum), kod pusty i z samych białych znaków jak brak kodu,
400 z polskim naruszeniem (65 znaków, także polskich liter, tablica, bajty spoza UTF-8), koszyk bez linii możliwych do
kupienia bez wyceny, odpowiedź komendy bez kodu. Pozostałe testy koszyka sprawdzają nowe pola sum i `discount: null`.

### Architektura

Deptrac (`apps/api/deptrac.yaml`, `make -C apps/api check`) z regułami Cart z sekcji „Bounded contexty i zależności”.
Zakazane (brak reguły, Deptrac zgłasza błąd): `Catalog_*`, `Pricing_*`, `Inventory_*`, `Customer_*` → `Cart_*`;
`Ordering_*` → `Cart_*` poza `Ordering_Infrastructure → Cart_Application`; `Cart_*` → `Ordering_*`;
`Cart_Domain` → inne contexty i `Vendor`; `Cart_Application` → inne contexty; `Cart_Infrastructure` →
`Catalog_Domain`, `Pricing_Domain`, `Inventory_Domain`, `*_Infrastructure` innych contextów; `Cart_UI` →
`Customer_Infrastructure`.

### Vitest (`apps/store`)

- BFF (`app/api/cart/**/route.test.ts`, `routes-unavailable.test.ts`, `src/cart/cookies.test.ts`): brak ciasteczka, gość, sesja, scalenie przed operacją (sukces usuwa `store_cart`, błąd go zostawia,
  `truncated` ustawia `store_cart_notice`), niepoprawny podpis, 404 gościa z jednym ponowieniem, 401 i 503 sesji,
  403 przy złym `Origin` i `Content-Type`, `no-store`, jednorazowy `notice`.
- `/auth/callback` (`app/auth/callback/route.test.ts`): scalenie przy `store_cart` (sukces, błąd, `truncated`); logowanie kończy się sukcesem także przy
  błędzie scalenia.
- `ProductTile` (`ProductTile.test.tsx`): jeden wariant (cena, poziom, przycisk dodaje wariant), ≥ 2 warianty (lista bez
  wyboru domyślnego, opcja niedostępna wyłączona, cena i etykieta wybranego wariantu, przycisk aktywny po wyborze), cena
  „od”, produkt niedostępny bez ceny, listy i przycisku, błąd wywołania ofert; `load-catalog.test.ts`: jedno wywołanie
  ofert dla strony i lista bez ofert przy błędzie.
- `AddToCartButton` (`AddToCartButton.test.tsx`): blokada na czas żądania, sukces, 409 z `detail`, 404, błąd sieci.
- `CartPage` (`CartPage.test.tsx`, `app/cart/page.test.tsx`): każdy stan linii i etykieta, sumy, `hasExcludedLines`, przycisk „Przejdź do kasy” (aktywny, nieaktywny, brak przy pustym koszyku), pusty koszyk, zmiana ilości z błędem 409
  (poprzednia ilość), 404 linii (odświeżenie: linia zniknęła, linia nadal jest z `detail` albo bez, wczytanie z 401 i z innym błędem), 401, błąd wczytania z ponowieniem.
- `CartBadge` (`CartBadge.test.tsx`, `events.test.ts`): liczba sztuk, aktualizacja po zmianie, ukrycie przy błędzie,
  komunikat scalenia.
- `src/api/client.test.ts`: wywołania `/api/cart/*`, kolejka zapisów z dublerem `navigator.locks` i bez niego (każdy
  test ustawia `navigator` przez `vi.stubGlobal`, bo Node ma własne `navigator.locks`); z dublerem każdy zapis biegnie
  wewnątrz `request` z jedną nazwą blokady i czeka, gdy blokadę trzyma inna karta; odczyty nie czekają ani w kolejce,
  ani na blokadę; błąd HTTP i błąd sieci nie wstrzymują następnego zapisu; odrzucony `request` daje `failed` bez
  wywołania `fetch`.

### E2E (Playwright)

- `cart-guest.spec.ts`: gość dodaje z listy produkt demo `in_stock` (gdy kafelek ma wybór, pierwszy dostępny wariant; linie
  koszyka szukane po nazwie i etykiecie wariantu), licznik pokazuje 1, na `/cart` (po odświeżeniu
  koszyk zostaje) zmienia ilość na 3, wartość linii i suma są równe 3 × cena z kafelka, usuwa pozycję, koszyk jest
  pusty także po odświeżeniu. Dwie karty jednego kontekstu przeglądarki bez `store_cart` klikają równocześnie
  „Do koszyka” na dwóch różnych produktach (z wyborem wariantu, gdy kafelek go ma); po odświeżeniu licznik pokazuje 2, a `/cart` ma oba produkty.
- `cart-merge.spec.ts` (wybór pierwszego dostępnego wariantu, gdy kafelek ma wybór): Jan loguje się, opróżnia swój koszyk w UI, dodaje B i wylogowuje się; jako gość dodaje A i B;
  po zalogowaniu licznik pokazuje 3, ciasteczko `store_cart` znika, a koszyk ma A × 1 i B × 2; drugi kontekst
  przeglądarki zalogowany jako Jan widzi ten sam koszyk.

## Ryzyka i ograniczenia

- Tworzenie koszyka gościa jest publiczne, bez rate limitu; tabelę można zaśmiecić pustymi koszykami do czasu ich
  wygaśnięcia.
- Identyfikator koszyka gościa jest w ścieżce URL API, więc trafia do logów API. Koszyk nie zawiera danych osobowych.
- Licznik w nagłówku to jedno zapytanie do PostgreSQL na odsłonę strony, widok koszyka z linią możliwą do kupienia to
  koszyk, Catalog, Pricing (ceny i promocje), Inventory i wycena Pricing (ceny, promocje, kod i liczba użyć kodu
  z limitem). Bez rate limitingu kody można zgadywać przez podgląd koszyka. Wydajność przy 60 tys. aktywnych użytkowników nie jest potwierdzona testami
  obciążeniowymi.
- Dodanie powtórzone po zgubionej odpowiedzi może zwiększyć ilość dwa razy (bez `Idempotency-Key`), a zmiana ilości
  z nieaktualnej karty nadpisuje nowszą. Obie konsekwencje są świadome.
- Gwarancja jednego koszyka przy równoczesnych pierwszych dodaniach z kilku kart działa tylko w przeglądarkach
  z Web Locks API w bezpiecznym kontekście (HTTPS albo `localhost`). Bez niego zostaje kolejka w obrębie karty:
  równoczesne pierwsze dodania gościa z kilku kart mogą założyć kilka koszyków, a przeglądarka zachowa ciasteczko
  ostatniego, więc pozycje z pozostałych znikną z widoku.
- Blokada szereguje zapisy wszystkich kart przeglądarki: zawieszone żądanie jednej karty wstrzymuje zapisy pozostałych
  do jego zakończenia. Zamknięcie karty w trakcie zapisu zwalnia blokadę.
- Odczyty nie biorą blokady. Przy nieaktualnym `store_cart` (koszyk wygasły albo scalony) dodanie w jednej karcie
  zakłada nowy koszyk i ustawia nowe ciasteczko, a równoległy odczyt (`/api/cart`, `/api/cart/summary`) w innej karcie
  ze starym ciasteczkiem dostaje 404 i może je usunąć, jeśli jego odpowiedź przyjdzie później.
- Dostępność w koszyku nie jest rezerwacją: wiąże dopiero rezerwacja przy zamówieniu.
- `cart:purge-expired` nie ma harmonogramu w MVP: do czasu jego uruchomienia (ręcznie albo z crona) wygasłe koszyki
  zostają w bazie, choć zachowują się jak nieistniejące lub puste.
