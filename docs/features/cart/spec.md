# Cart: koszyk gościa i zalogowanego klienta (MVP)

Punkt 6 MVP z `docs/vision.md`, bez checkoutu.

## Cel i zakres

Klient sklepu (gość albo zalogowany w realmie `shop`) dodaje produkt do koszyka z kafelka na liście, zmienia ilości,
usuwa pozycje i widzi sumy policzone przez API. Koszyk przeżywa odświeżenie i ponowne wejście. Po zalogowaniu koszyk
gościa łączy się z koszykiem klienta, a klient widzi ten sam koszyk na każdym urządzeniu.

Poza zakresem: checkout i zamówienia (epik ORDERING), rezerwacja stanu, płatności, promocje i kody rabatowe, koszty
dostawy, strona produktu, warianty, panel administracyjny, rate limiting tworzenia koszyków.

## Decyzje biznesowe

| # | Decyzja |
|---|---|
| 1 | Koszyk per produkt (bez wariantów). Warianty to osobny epik Catalog; koszyk migruje się najtaniej, bo jego dane są krótkotrwałe. |
| 2 | Koszyki gości i klientów są w PostgreSQL (nie w Redisie). |
| 3 | Scalanie przy logowaniu sumuje ilości. Suma ponad limit ilości jest przycinana, linie gościa ponad limit liczby pozycji są pomijane, a klient widzi komunikat. Scalenie nigdy nie kończy się błędem z powodu ceny ani dostępności. |
| 4 | Najwyżej 50 różnych pozycji, najwyżej 99 sztuk jednej pozycji. |
| 5 | Koszyk gościa wygasa po 30 dniach od ostatniej zmiany, koszyk klienta po 180 dniach. Wygasły koszyk zachowuje się jak pusty, fizycznie usuwa go `cart:purge-expired`. |
| 6 | Pozycje nigdy nie znikają same. Pozycja bez ceny, niedostępna, z ilością większą niż dostępna albo z produktem usuniętym z katalogu ma etykietę i przycisk „Usuń”. Suma obejmuje tylko pozycje możliwe do kupienia. |
| 7 | Dostępność ogranicza ilość przy dodaniu i zwiększeniu ilości, bez rezerwacji i bez ujawniania liczby sztuk. Wiąże dopiero rezerwacja w ORDERING. |
| 8 | Sumy: wartość brutto i liczba sztuk, bez VAT. Licznik sztuk w nagłówku. |
| 9 | Koszyk pokazuje zawsze cenę aktualną, bez informacji o zmianie ceny. Cenę zamraża zamówienie. |
| 10 | Po wylogowaniu przeglądarka pokazuje pusty koszyk gościa; koszyk klienta zostaje na koncie. |

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Cart | koszyki, pozycje, limity, scalanie, wygasanie, widok koszyka z sumami |
| Catalog | `GetProductSummaries` (nazwa i zdjęcie produktów) |
| Pricing | `GetCurrentPrices` (autorytatywna cena aktualna) |
| Inventory | `GetStockLevels` (dostępna liczba sztuk, tylko w procesie API) |
| Customer | `AuthenticatedCustomer::id()` (`sub` z tokena realmu `shop`) |
| Sklep | BFF koszyka i wyświetlanie wartości z API; żadnych obliczeń |

Reguły Deptrac (żaden inny context nie zależy od Cart w żadnej warstwie):

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

## Model domenowy

### Value objecty

- `CartId`: UUID; nowy jest losowym UUID v4. Identyfikator koszyka gościa jest jednocześnie kluczem dostępu do niego.
- `CartOwner`: `guest` albo `customer(customerId)`, gdzie `customerId` to `sub` z tokena (1–255 znaków).
- `ProductId`: UUID (zapisywany małymi literami).
- `Quantity`: liczba całkowita 1–99 (`InvalidQuantity`, `InvalidInput`).
- `Money`: nieujemne grosze (`int`) i `Currency` (tylko `PLN`). Bez `float`.
- `ProductOffer {hasCurrentPrice: bool, available: int}`: stan produktu z portów, podany domenie przez handler;
  `available` nie jest ujemne. Jedyne miejsce reguły „produkt do kupienia”: `purchasability(quantity)` zwraca
  `Purchasability` (enum domeny) w kolejności `NoPrice` (brak ceny), `OutOfStock` (`available = 0`),
  `InsufficientStock` (`quantity > available`), `Purchasable`. Używają go `Cart` (dodanie i zwiększenie) oraz
  widok koszyka.
- `CartLine {productId, quantity, addedAt}`; `addedAt` ustala kolejność linii i nie zmienia się wraz z ilością.
- `MergeOutcome {result: merged | already_merged | nothing_to_merge, truncated: bool}`.

### Agregat `Cart`

Pola: `id`, `owner`, `status` (`open` | `merged`; `merged` tylko dla gościa), `mergedInto` (null), `mergedAt` (null),
linie unikalne po `productId` w kolejności `addedAt`, `createdAt`, `updatedAt`, `expiresAt`. Nowy koszyk powstaje przez
`createForGuest` i `createForCustomer`, zapisany jest odtwarzany przez `reconstitute()`.

Stałe domeny (jedyne miejsce tych reguł): `MAX_LINES = 50`, `MAX_QUANTITY = 99`, czas życia gościa 30 dni, klienta
180 dni.

| Metoda | Działanie | Błędy |
|---|---|---|
| `add(productId, quantity, offer, now)` | brak linii: nowa linia; linia istnieje: ilość += quantity | wynik > 99: `CartLineQuantityLimit` (409); nowa linia przy 50 liniach: `CartLineLimit` (409); `!hasCurrentPrice` albo `available = 0`: `ProductNotPurchasable` (409); wynik > `available`: `InsufficientStock` (409) |
| `changeQuantity(productId, quantity, offer, now)` | ustawia ilość (wartość bezwzględna). Zmniejszenie i ta sama ilość są zawsze dozwolone. Zwiększenie wymaga `hasCurrentPrice`, `available > 0` i `quantity ≤ available` | brak linii: `CartLineNotFound` (404); błędy zwiększenia jak przy `add` |
| `remove(productId, now)` | usuwa linię; brak linii: sukces bez zmian (bez `touch`) | |
| `mergeFrom(guest, now): MergeOutcome` | na koszyku klienta, opis w sekcji „Scalanie” | koszyk gościa: błąd logiczny |
| `outcomeWithoutMerge(now)` | na koszyku, który ma zostać scalony: `already_merged` dla `merged` (także wygasłego), `nothing_to_merge` dla wygasłego gościa i dla koszyka klienta, `null` gdy można scalać | |
| `markMerged(customerCartId, now)` | na koszyku gościa: usuwa linie, `status = merged`, `mergedInto`, `mergedAt`, `updatedAt = now`; `expiresAt` zostaje (scalony koszyk czeka na czyszczenie) | koszyk nie jest gościa albo nie jest `open`: błąd logiczny |
| `clearIfExpired(now)` | koszyk klienta po `expiresAt`: usuwa wszystkie linie (koszyka gościa nigdy) | |
| `openForChange(now)` | początek każdej zmiany, idempotentne: gość sprawdza dostępność (poniżej), klient `clearIfExpired` | `GuestCartMerged`, `CartNotFound` |
| `needsOfferToChange(productId, quantity)` | czy zmiana ilości jest zwiększeniem istniejącej linii, więc potrzebuje `ProductOffer` | |
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
  jednego produktu.”, `CartLineLimit` „Koszyk może zawierać najwyżej 50 różnych produktów.”, `ProductNotPurchasable`
  „Ten produkt jest chwilowo niedostępny w sprzedaży.”, `CartLineNotFound` „Tej pozycji nie ma w koszyku.”,
  `CartNotFound` i `GuestCartMerged` „Koszyk nie jest już dostępny.”, `ProductNotFound` „Produkt nie jest już
  dostępny.”.

### Scalanie (`mergeFrom`)

1. Koszyk gościa, którego nie można scalić (`outcomeWithoutMerge`), nie zmienia żadnego z koszyków; wynik to
   `already_merged` albo `nothing_to_merge`.
2. `clearIfExpired(now)` na koszyku klienta: wygasły koszyk klienta traci swoje stare pozycje przed scaleniem,
   więc do pustego koszyka trafiają tylko pozycje gościa.
3. Linie gościa w kolejności `addedAt`:
   - produkt jest już w koszyku klienta: ilość = min(suma, 99); przycięcie ustawia `truncated`;
   - produktu nie ma i koszyk ma mniej niż 50 linii: nowa linia z ilością gościa (zachowuje `addedAt` gościa);
   - produktu nie ma i koszyk ma 50 linii: linia pominięta, `truncated`.

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

Stan linii, w kolejności priorytetu:

| `state` | Warunek |
|---|---|
| `unavailable_product` | produkt nieznany Catalogowi (np. po resecie danych demo) |
| `no_price` | brak ceny w `GetCurrentPrices` (brak ceny, nieaktualna stawka VAT albo cena w walucie innej niż PLN) |
| `out_of_stock` | `available = 0` (także produkt bez rekordu stanu) |
| `insufficient_stock` | `quantity > available` |
| `purchasable` | pozostałe |

- `unitPrice` = cena brutto z Pricing (gdy jest), `lineTotal` = `unitPrice × quantity` tylko dla `purchasable`.
- `totals.gross` = suma `lineTotal` linii `purchasable` (0 przy pustym koszyku), `totals.currency = PLN`.
- `totals.itemCount` = suma ilości wszystkich linii (licznik w nagłówku).
- `hasExcludedLines` = istnieje linia inna niż `purchasable`.
- Kwoty w groszach; maksimum 50 × 99 × 99 999 999 mieści się w `int`.

## Persystencja i transakcje

Tabele:
- `cart_cart`: `id uuid` (PK), `owner_type` (`guest` | `customer`), `customer_id varchar(255) NULL`, `status`
  (`open` | `merged`), `merged_into uuid NULL`, `merged_at timestamptz NULL`, `created_at`, `updated_at`, `expires_at`
  (wszystkie czasy `timestamptz`).
  - Indeks unikalny `customer_id WHERE customer_id IS NOT NULL` (jeden koszyk na klienta).
  - Indeks `expires_at` (czyszczenie).
  - `CHECK ((owner_type = 'customer') = (customer_id IS NOT NULL))`,
    `CHECK (status = 'open' OR owner_type = 'guest')`.
- `cart_line`: `id uuid` (PK zastępczy, UUID v7), `cart_id uuid` (FK → `cart_cart` `ON DELETE CASCADE`), `product_id uuid`,
  `quantity int` (`CHECK (quantity BETWEEN 1 AND 99)`), `added_at`; unikalny indeks `(cart_id, product_id)` oraz indeks
  `cart_id`. Migracja: `Version20261103120000CreateCartTables`.
- Bez kluczy obcych do tabel Catalog. Kaskada przy usuwaniu linii jest dozwolona, bo koszyki są krótkotrwałe
  (w odróżnieniu od rezerwacji Inventory).

Zapis i odczyt:
- Rekordy Doctrine z atrybutami w `Cart/Infrastructure/Persistence/Doctrine/Record` (`CartRecord`, `CartLineRecord`;
  mapowanie `Cart` w `config/packages/doctrine.yaml`), mapowanie agregat ↔ rekord w `DoctrineCartRepository`
  (`lock`, `lockForCustomer`, `lockOrCreateForCustomer`, `add`, `save`), `reconstitute()`. Zapis kończy transakcja
  `command.bus` (`doctrine_transaction`). Linie są zapisywane w miejscu po produkcie, więc usunięcie i ponowne dodanie
  produktu w jednej zmianie nie trafia na unikalny indeks.
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
- `AddCartItem(cartRef, productId, quantity)`, `ChangeCartItemQuantity(cartRef, productId, quantity)`,
  `RemoveCartItem(cartRef, productId)`, gdzie `cartRef` (`CartRef`) to `guest(cartId)` albo `customer(customerId)`.
  - Koszyk gościa (`LockedCarts`): blokada; brak, id niebędące UUID, wygasły, `merged` albo id koszyka klienta: 404.
    Koszyk klienta: `lockOrCreateForCustomer` (`RemoveCartItem` klienta bez koszyka: pusty widok, bez zakładania koszyka).
  - `AddCartItem` i zwiększenie w `ChangeCartItemQuantity`: produkt nieznany Catalogowi (`ProductCatalog`) albo `productId`
    niebędące UUID (dodanie) to `ProductNotFound` (404); `ProductOffer` z `CartPrices` i `StockAvailability`. Zmniejszenie
    nie potrzebuje oferty ani znajomości produktu przez Catalog. `productId` niebędące UUID w zmianie ilości to `CartLineNotFound`, w usunięciu: sukces bez zmian.
- `MergeGuestCart(customerId, guestCartId)` → `MergeResult {cart: CartView, result, truncated}`.
- `PurgeExpiredCarts(batchSize)` → liczba koszyków usuniętych w jednej partii.

Zapytania (`query.bus`):
- `GetCart(cartRef)` → `CartView`. Klient bez rekordu koszyka: pusty widok z `id = null`; wygasły koszyk klienta: pusty
  widok z `id` koszyka; nic nie jest zakładane. Koszyk gościa nieznany, wygasły albo `merged`: 404.
- `GetCartSummary(cartRef)` → `CartSummary {itemCount}`, jeden odczyt koszyka, bez portów. Te same reguły 404 i pustego koszyka.

Porty (`Cart_Application/Port`) i adaptery (`Cart_Infrastructure`):

| Port | Adapter | Zapytanie |
|---|---|---|
| `ProductCatalog::summaries(ids)` → `ProductSummary {id, name, image}` | `QueryBusProductCatalog` | Catalog `GetProductSummaries` |
| `CartPrices::current(ids)` → `CartPrice {productId, gross: Money}` | `QueryBusCartPrices` | Pricing `GetCurrentPrices` |
| `StockAvailability::of(ids)` → `ProductAvailability {productId, available}` | `QueryBusStockAvailability` | Inventory `GetStockLevels` |
| `CartReader::find(id)`, `findForCustomer(customerId)` | `DbalCartReader` | odczyt DBAL |
| `ExpiredCarts::purgeBatch(now, limit)` | `DbalExpiredCarts` | usunięcie partii DBAL |
| `Clock::now()` | `SystemClock` | |

Adaptery zapytań nie wołają magistrali bez id i przekazują przyczynę błędu opakowanego przez magistralę (np. utracone
połączenie). `QueryBusCartPrices` pomija cenę w walucie innej niż `PLN` (jak brak ceny), `QueryBusStockAvailability`
obcina ujemną dostępność do 0.

Zapytanie Catalog `GetProductSummaries(productIds)` (1–100 id; inaczej `InvalidProductSummariesQuery`) →
`list<ProductSummary {id, name, image: ImageView|null}>` w kolejności żądania, dla produktów obecnych w bazie
(powtórzone id odpowiada raz); jedno zapytanie DBAL `WHERE id = ANY(:ids)` po tabeli `catalog_product` (nie
Elasticsearch), zdjęcie główne (pierwsze z `images`) w istniejącym rozmiarze `listing` przez `ImageUrlGenerator`. Nowy
rozmiar zdjęcia nie jest dodawany.

## API

Sklep (`apps/api/openapi.json`). Błędy w formacie RFC 9457, `detail` z wyjątku domeny. Wszystkie odpowiedzi pod
`/api/v1/guest-carts` i `/api/v1/me/cart` (także błędy, 401 zapory i 404 ścieżki z id niebędącym UUID) mają
`Cache-Control: private, no-store` (`CartResponseCacheSubscriber`).

`security.yaml`: `^/api/v1/guest-carts(/|$)` → `PUBLIC_ACCESS` dla wszystkich metod (przed regułą `ROLE_CUSTOMER`);
`/api/v1/me/cart…` objęte istniejącą regułą `ROLE_CUSTOMER`.

Gość (bez tokena; token wysłany mimo to i niepoprawny daje 401, a gdy nie da się pobrać kluczy wystawcy tokenu: 503):

| Endpoint | Sukces | Błędy |
|---|---|---|
| `POST /api/v1/guest-carts` | 201 `CartView` (pusty), nagłówek `Location`; ciało nie jest czytane | |
| `GET /api/v1/guest-carts/{cartId}` | 200 `CartView` | 404 |
| `GET /api/v1/guest-carts/{cartId}/summary` | 200 `{itemCount}` | 404 |
| `POST /api/v1/guest-carts/{cartId}/items`, body `{productId: uuid, quantity: int}` | 200 `CartView` | 400 treść nie jest poprawnym JSON-em; 404 koszyk albo produkt; 409 `CartLineQuantityLimit`, `CartLineLimit`, `ProductNotPurchasable`, `InsufficientStock`; 415 treść inna niż JSON; 422 (z `violations`) `quantity` spoza 1–99, zły typ, brak klucza, zły UUID produktu |
| `PUT /api/v1/guest-carts/{cartId}/items/{productId}`, body `{quantity: int}` | 200 `CartView` | jak wyżej oraz 404 `CartLineNotFound` |
| `DELETE /api/v1/guest-carts/{cartId}/items/{productId}` | 200 `CartView` (brak linii: bez zmian) | 404 koszyk |

Klient (token realmu `shop`; bez tokena albo z tokenem realmu `backoffice`: 401; gdy nie da się pobrać kluczy
wystawcy tokenu: 503):

| Endpoint | Sukces | Błędy |
|---|---|---|
| `GET /api/v1/me/cart` | 200 `CartView` (bez koszyka: pusty, `id: null`) | 401 |
| `GET /api/v1/me/cart/summary` | 200 `{itemCount}` | 401 |
| `POST /api/v1/me/cart/items` | 200 `CartView` | 401 oraz jak u gościa (404 tylko produkt) |
| `PUT /api/v1/me/cart/items/{productId}` | 200 `CartView` | 401 oraz jak u gościa (404 produkt albo linia) |
| `DELETE /api/v1/me/cart/items/{productId}` | 200 `CartView` | 401 |
| `POST /api/v1/me/cart/merge`, body `{guestCartId: uuid}` | 200 `{cart: CartView, result: "merged" \| "already_merged" \| "nothing_to_merge", truncated: bool}` | 400, 401, 415, 422 (zły UUID, brak klucza) |

- Identyfikator w ścieżce (`cartId`, `productId`), który nie jest UUID (w dowolnej wielkości liter): 404, bo trasa nie
  pasuje. Typy ścisłe, bez rzutowania (liczba w napisie, ułamek: 422).
- Scalony koszyk gościa odpowiada 404 jak nieznany: BFF reaguje na oba tak samo, więc format błędów
  (`type: about:blank`) się nie zmienia.
- Klient zawsze działa na swoim koszyku (`sub` z tokena); identyfikatora koszyka klienta nie ma w ścieżce.

`CartView`:

```
{
  id: uuid | null,
  lines: [{
    productId: uuid,
    product: { name: string, image: ProductImage | null } | null,
    quantity: int,
    unitPrice: { gross: int, currency: "PLN" } | null,
    lineTotal: { gross: int, currency: "PLN" } | null,
    state: "purchasable" | "no_price" | "out_of_stock" | "insufficient_stock" | "unavailable_product"
  }],
  totals: { gross: int, currency: "PLN", itemCount: int },
  hasExcludedLines: bool
}
```

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
  - `POST /api/cart/items` `{productId, quantity}`;
  - `PATCH /api/cart/items/[productId]` `{quantity}`;
  - `DELETE /api/cart/items/[productId]`.
- Żądania zmieniające (`POST`, `PATCH`, `DELETE`) wymagają nagłówka `Origin` równego originowi `STORE_URL` i (`POST`,
  `PATCH`) `Content-Type: application/json`; inaczej 403 (ochrona przed CSRF), bez wywołania API. Ciało `POST` bez
  `productId` (napis) i `quantity` (liczba) albo ciało `PATCH` bez `quantity` (liczba) to 422 „Nieprawidłowe dane.”,
  a `productId` w ścieżce `PATCH` i `DELETE`, które nie jest UUID, to 404 „Tej pozycji nie ma w koszyku.” (oba bez
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
  `changeCartItemQuantity`, `removeCartItem`; jedyne miejsce `fetch`). Zapisy idą jeden po drugim w kolejce na poziomie
  modułu (następny startuje po zakończeniu poprzedniego, także po błędzie), żeby dwa pierwsze dodania gościa nie założyły
  dwóch koszyków; odczyty nie czekają w kolejce.

### UI

- `AddToCartButton` („Do koszyka”, Client Component) w `ProductTile`, widoczny tylko, gdy kafelek ma cenę i poziom
  dostępności nie jest `out_of_stock`; przy błędzie endpointu dostępności (brak etykiety) przycisk jest widoczny, a
  decyduje API. Dodaje 1 sztukę. Na czas żądania przycisk jest zablokowany. HTML katalogu nie zależy od sesji.
- `CartBadge` w nagłówku: link „Koszyk” z liczbą sztuk z `/api/cart/summary`; po każdej zmianie koszyka licznik
  aktualizuje się z `itemCount` odpowiedzi (zdarzenie w przeglądarce, `src/cart/events.ts`). Do czasu wczytania i przy
  błędzie licznik jest ukryty, link zostaje.
- Strona `/cart`: `robots: noindex, nofollow`, treść (`CartPage`) jako Client Component ładujący `/api/cart`. Linie:
  zdjęcie (`ProductPhoto`), nazwa, cena jednostkowa, ilość (pole 1–99, zatwierdzane po opuszczeniu pola lub Enterem;
  wartość niebędąca liczbą całkowitą 1–99 albo bez zmiany wraca do bieżącej ilości bez żądania), wartość linii, „Usuń”;
  podsumowanie: wartość brutto i liczba sztuk. Pusty koszyk: „Twój koszyk jest pusty” i link „Zobacz produkty”.
- Kwoty przez `formatPrice`. Front niczego nie liczy: każdy widok pochodzi z ostatniej odpowiedzi API.

| Sytuacja | UI |
|---|---|
| dodanie OK | komunikat „Dodano do koszyka”, licznik z `itemCount` |
| 409 przy dodaniu albo zmianie ilości | `detail` z API przy kafelku albo linii; linia pokazuje poprzednią ilość z ostatniego widoku |
| 404 produktu przy dodaniu | „Produkt nie jest już dostępny” |
| 404 linii przy zmianie | odświeżenie koszyka i „Pozycja została już usunięta” |
| 403, 422, 503, 5xx, błąd sieci | „Nie udało się zapisać zmian. Spróbuj ponownie.” (bez automatycznego ponowienia) |
| 401 (sesja wygasła), także przy wczytaniu `/cart` | „Twoja sesja wygasła. Zaloguj się ponownie.” z linkiem „Zaloguj się” (`/auth/login?returnTo=…`) |
| błąd wczytania koszyka | „Nie udało się wczytać koszyka” i przycisk „Spróbuj ponownie” |
| `hasExcludedLines` | przy sumie: „Część produktów nie jest wliczona” |
| `notice: merge_truncated` | „Część produktów z koszyka gościa nie zmieściła się w koszyku” (`CartBadge`, obok linku „Koszyk”) |
| `no_price` | „Cena niedostępna” |
| `out_of_stock` | „Niedostępny” |
| `insufficient_stock` | „Dostępna ilość jest mniejsza niż w koszyku” |
| `unavailable_product` | „Produkt nie jest już dostępny”, bez nazwy i zdjęcia |

Każda linia z etykietą ma „Usuń”; zmniejszenie ilości jest zawsze możliwe.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| 100. sztuka produktu albo 51. pozycja | 409, koszyk bez zmian |
| dodanie produktu bez ceny albo niedostępnego | 409 `ProductNotPurchasable`; w sklepie brak przycisku |
| ilość większa niż dostępna | 409 bez liczby sztuk |
| zmiana ilości z nieaktualnej karty | ostatni zapis wygrywa |
| równoległe dodania z dwóch kart | wszystkie zapisane, bez utraty zmian |
| zmiana koszyka gościa po scaleniu | 404, bez zmian; BFF usuwa `store_cart`: dodanie zakłada nowy koszyk gościa, odczyt i usunięcie dają pusty koszyk, zmiana 404 pozycji |
| ponowne lub równoległe scalenie tego samego gościa | `already_merged`, pozycje przeniesione raz |
| scalenie z wygasłym koszykiem klienta | stare pozycje klienta usunięte, zostają pozycje gościa |
| błąd w trakcie scalenia | całość wycofana, gość `open`, klient bez zmian; ponowienie przy następnym żądaniu |
| wygasły koszyk gościa | 404, sklep pokazuje pusty koszyk |
| produkt usunięty z katalogu | linia `unavailable_product` |
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
7. Żaden context nie zależy od Cart; Deptrac odrzuca zależności spoza reguł Cart.
8. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check`,
   `make -C apps/store test` oraz `make e2e`.

## Testy

### Jednostkowe (TDD, adaptery in-memory; `apps/api/tests/Unit/Cart`)

- `Cart`: `add` (nowa linia, zwiększenie), granice ilości 1, 99, 100 i liczby linii 50, 51; odrzucenie przez `ProductOffer`
  (brak ceny, `available = 0`, wynik > `available` → `ProductNotPurchasable` / `InsufficientStock`); `changeQuantity`
  (zmniejszenie zawsze, zwiększenie z kontrolą, brak linii); `remove` idempotentne; zmiana koszyka `merged` odrzucona;
  `touch` i `isExpired` dla gościa i klienta; `clearIfExpired`.
- `ProductOffer::purchasability` (`ProductOfferTest`): brak ceny przed `available = 0`, granice `quantity = available`
  i `available + 1`.
- `mergeFrom`: suma, przycięcie do 99 (`truncated`), pominięcie linii ponad 50 (`truncated`), kolejność `addedAt`,
  pusty gość, gość już `merged` (`already_merged`), linie gościa usunięte i status `merged`; **wygasły koszyk klienta
  z pozycjami: po scaleniu zawiera wyłącznie pozycje gościa**.
- `CartViewBuilder`: priorytet stanów, `lineTotal` i `totals.gross` tylko dla `purchasable`, `itemCount` ze wszystkich
  linii, `hasExcludedLines`, pusty koszyk.
- Handlery: 404 dla gościa nieznanego, wygasłego i scalonego; `ProductNotFound`; `nothing_to_merge` dla nieznanego,
  wygasłego i klienckiego `guestCartId`; `GetCart` klienta bez koszyka nic nie zakłada.

### Integracyjne (PostgreSQL)

W `apps/api/tests/Integration`:

- `Cart/Persistence/DoctrineCartRepositoryTest`: mapowanie rekordów, `CHECK` i indeks unikalny `customer_id`
  (bezpośredni SQL łamiący reguły jest odrzucany), kaskada linii, `lockOrCreateForCustomer` (`ON CONFLICT`), blokada z
  `refresh` rekordu obecnego w identity map (także usuniętego w międzyczasie) i ponowienie po usunięciu koszyka przez
  czyszczenie.
- `Catalog/Query/GetProductSummariesTest`: 100 id jednym zapytaniem; produkt nieobecny pominięty.
- `Cart/QueryBusAdaptersTest`: adaptery Catalog, Pricing i Inventory.
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
| K3 | 20 równoległych dodań do koszyka klienta (10 tego samego produktu, 10 różnych) | ilość wspólnego produktu = 10, wszystkie różne produkty zapisane |
| K4 | 2 równoległe pierwsze dodania klienta bez koszyka | jeden wiersz koszyka klienta, obie linie, żadnego błędu |
| K5 | ten sam gość scalany równolegle do 2 różnych klientów | dokładnie jeden klient dostaje linie, drugi `already_merged` |
| K6 | 2 różne koszyki gościa scalane równolegle do tego samego klienta | oba `merged`, ilości zsumowane, brak zakleszczeń |
| K7 | równoległe dodania różnych produktów do koszyka z 48 liniami | dokładnie 2 sukcesy, reszta `CartLineLimit` |
| K8 | 2 klientów z koszykami scala równolegle nawzajem id swoich koszyków | oba `nothing_to_merge`, brak zakleszczeń, koszyki bez zmian |

### Funkcjonalne API

`apps/api/tests/Functional/Cart` (`GuestCartEndpointTest`, `CustomerCartEndpointTest`, `CustomerCartMergeEndpointTest`).
Każdy endpoint: 200/201, 400 (zły JSON), 401 (`/me/cart…` bez tokena i z tokenem `backoffice`), 404 (nie-UUID,
nieznany, wygasły i scalony koszyk gościa, nieznany produkt, brak linii), 409 (każda przyczyna), 415, 422 (ilość 0
i 100, napis, ułamek, brak klucza), `Cache-Control: private, no-store`; `merge`: każdy `result` i `truncated`.

### Architektura

Deptrac (`apps/api/deptrac.yaml`, `make -C apps/api check`) z regułami Cart z sekcji „Bounded contexty i zależności”.
Zakazane (brak reguły, Deptrac zgłasza błąd): `Catalog_*`, `Pricing_*`, `Inventory_*`, `Customer_*` → `Cart_*`;
`Cart_Domain` → inne contexty i `Vendor`; `Cart_Application` → inne contexty; `Cart_Infrastructure` →
`Catalog_Domain`, `Pricing_Domain`, `Inventory_Domain`, `*_Infrastructure` innych contextów; `Cart_UI` →
`Customer_Infrastructure`.

### Vitest (`apps/store`)

- BFF (`app/api/cart/**/route.test.ts`, `routes-unavailable.test.ts`, `src/cart/cookies.test.ts`): brak ciasteczka, gość, sesja, scalenie przed operacją (sukces usuwa `store_cart`, błąd go zostawia,
  `truncated` ustawia `store_cart_notice`), niepoprawny podpis, 404 gościa z jednym ponowieniem, 401 i 503 sesji,
  403 przy złym `Origin` i `Content-Type`, `no-store`, jednorazowy `notice`.
- `/auth/callback` (`app/auth/callback/route.test.ts`): scalenie przy `store_cart` (sukces, błąd, `truncated`); logowanie kończy się sukcesem także przy
  błędzie scalenia.
- `ProductTile` (`ProductTile.test.tsx`): przycisk przy cenie i dostępności, brak przy braku ceny i `out_of_stock`, obecny przy braku etykiety.
- `AddToCartButton` (`AddToCartButton.test.tsx`): blokada na czas żądania, sukces, 409 z `detail`, 404, błąd sieci.
- `CartPage` (`CartPage.test.tsx`, `app/cart/page.test.tsx`): każdy stan linii i etykieta, sumy, `hasExcludedLines`, pusty koszyk, zmiana ilości z błędem 409
  (poprzednia ilość), 404 linii (odświeżenie), 401, błąd wczytania z ponowieniem.
- `CartBadge` (`CartBadge.test.tsx`, `events.test.ts`): liczba sztuk, aktualizacja po zmianie, ukrycie przy błędzie,
  komunikat scalenia.
- `src/api/client.test.ts`: wywołania `/api/cart/*`, kolejka zapisów (odczyty w niej nie czekają).

### E2E (Playwright)

- `cart-guest.spec.ts`: gość dodaje z listy produkt demo `in_stock`, licznik pokazuje 1, na `/cart` (po odświeżeniu
  koszyk zostaje) zmienia ilość na 3, wartość linii i suma są równe 3 × cena z kafelka, usuwa pozycję, koszyk jest
  pusty także po odświeżeniu.
- `cart-merge.spec.ts`: Jan loguje się, opróżnia swój koszyk w UI, dodaje B i wylogowuje się; jako gość dodaje A i B;
  po zalogowaniu licznik pokazuje 3, ciasteczko `store_cart` znika, a koszyk ma A × 1 i B × 2; drugi kontekst
  przeglądarki zalogowany jako Jan widzi ten sam koszyk.

## Ryzyka i ograniczenia

- Koszyk jest per produkt. Warianty w Catalog wymuszą klucz per wariant w Pricing, Inventory i Cart.
- Tworzenie koszyka gościa jest publiczne, bez rate limitu; tabelę można zaśmiecić pustymi koszykami do czasu ich
  wygaśnięcia.
- Identyfikator koszyka gościa jest w ścieżce URL API, więc trafia do logów API. Koszyk nie zawiera danych osobowych.
- Licznik w nagłówku to jedno zapytanie do PostgreSQL na odsłonę strony, widok koszyka to cztery zapytania (koszyk,
  Catalog, Pricing, Inventory). Wydajność przy 60 tys. aktywnych użytkowników nie jest potwierdzona testami
  obciążeniowymi.
- Dodanie powtórzone po zgubionej odpowiedzi może zwiększyć ilość dwa razy (bez `Idempotency-Key`), a zmiana ilości
  z nieaktualnej karty nadpisuje nowszą. Obie konsekwencje są świadome.
- Dostępność w koszyku nie jest rezerwacją: wiąże dopiero rezerwacja przy zamówieniu.
- `cart:purge-expired` nie ma harmonogramu w MVP: do czasu jego uruchomienia (ręcznie albo z crona) wygasłe koszyki
  zostają w bazie, choć zachowują się jak nieistniejące lub puste.
