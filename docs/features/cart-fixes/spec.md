# CART-FIXES: poprawki po epiku CART

Epik poprawek po punkcie 6 „Zakresu MVP” (epik CART). Uwagi `Minor` z raportu `/integration` epiku CART, każda
w osobnym issue. Działanie koszyka opisuje `docs/features/cart/spec.md`; ta specyfikacja opisuje tylko zmiany.

Issues: #88, #80, #81, #82, #83, #84, #85

## Cel i zakres

- Koszyk nie gubi pozycji przy pierwszych dodaniach z kilku kart (#80).
- Strona koszyka nie twierdzi, że pozycja zniknęła, gdy jest w koszyku (#81).
- Wszystkie komunikaty walidacji żądań koszyka w API są po polsku (#82).
- Reguła „produkt do kupienia” ma jedną implementację (#84).
- Specyfikacja koszyka opisuje kod (#83, #85).

## Decyzje użytkownika

| Issue | Decyzja |
|---|---|
| #80 | Naprawić blokadą Web Locks API w sklepie, bez nowych zależności. Gwarancja jednego koszyka przy równoczesnych pierwszych dodaniach dotyczy tylko przeglądarek z Web Locks API w bezpiecznym kontekście; bez niej zostaje obecne ograniczenie (kolejka w obrębie karty). Test E2E równoległego pierwszego dodania z dwóch kart jest wymagany. |
| #81 | Poprawić komunikat; pole ilości linii `unavailable_product` zostaje aktywne. |
| #82 | Polskie komunikaty w trzech żądaniach Cart, także `MergeGuestCartRequest`. |
| #83 | Bez zadania: kryterium spełnione na `main`; końcowy PR epiku zamyka issue. |
| #84 | Reguła w value objecie `ProductOffer` (`purchasability`) z enumem domeny `Purchasability` w `Cart_Domain`. |
| #85 | Indeks zostaje (wymagany przez Doctrine DBAL dla obecnego mapowania), z technicznym uzasadnieniem w specyfikacji koszyka; bez przebudowy persystencji; końcowy PR epiku zamyka issue. |

Poza zakresem: nowe funkcje koszyka i checkout; komunikaty walidacji żądań poza Cart (Inventory, Pricing: ten sam
wzorzec bez `message`, osobne issue); zmiana mapowania relacji `cart_line` → `cart_cart`.

## #80: pierwsze dodania z kilku kart trafiają do jednego koszyka

**Problem.** Bez ciasteczka `store_cart` dwa równoczesne `POST /api/cart/items` z dwóch kart zakładają dwa koszyki
gościa (`runForGuest` w `apps/store/src/cart/bff.ts`), a przeglądarka zachowuje ostatnie ciasteczko. Kolejka
`queueWrite` w `apps/store/src/api/client.ts` szereguje zapisy tylko w obrębie jednej karty (zmienna modułu).
`docs/vision.md` i scenariusz „równoległe dodania z dwóch kart” w `docs/features/cart/spec.md` obiecują, że
wszystkie dodania są zapisane.

**Gwarancja.** Równoczesne pierwsze dodania z kilku kart trafiają do jednego koszyka tylko w przeglądarkach
z Web Locks API w bezpiecznym kontekście (HTTPS albo `localhost`). Bez Web Locks API zachowanie zostaje takie jak
dziś: zapisy są szeregowane tylko w obrębie karty, a równoczesne pierwsze dodania z kilku kart mogą założyć kilka
koszyków.

**Zachowanie.**
- Zapisy koszyka z przeglądarki (`addCartItem`, `changeCartItemQuantity`, `removeCartItem`) idą jeden po drugim we
  wszystkich kartach sklepu w tej przeglądarce: każdy zapis trzyma blokadę Web Locks API
  (`navigator.locks.request` z jedną nazwą blokady dla koszyka, tryb `exclusive`) od wysłania żądania do końca
  odpowiedzi. Ciasteczko z odpowiedzi pierwszego dodania jest już zapisane, gdy rusza zapis z drugiej karty, więc
  drugi zapis trafia do tego samego koszyka.
- Jedna ścieżka: kolejka w obrębie karty (`queueWrite`) zostaje i ustala kolejność zapisów karty, a zapis z kolejki
  bierze blokadę, gdy `navigator.locks` istnieje (inaczej biegnie bez niej). Zapis, który się nie udał (błąd HTTP,
  błąd sieci), zwalnia blokadę i nie wstrzymuje następnego. Odrzucenie samego `navigator.locks.request` (np.
  `SecurityError`) daje wynik `failed` bez wysłania żądania („Nie udało się zapisać zmian. Spróbuj ponownie.”);
  funkcje zapisu nigdy nie odrzucają obietnicy, jak dziś `callCart`.
- Odczyty (`loadCart`, `loadCartSummary`) nie czekają ani w kolejce, ani na blokadę.
- Gdy przeglądarka nie ma `navigator.locks` (kontekst bez HTTPS poza `localhost`, stara przeglądarka), działa sama
  kolejka w obrębie karty. Ograniczenie trafia do sekcji „Ryzyka i ograniczenia” w `docs/features/cart/spec.md`.
- Zamknięcie karty w trakcie zapisu zwalnia blokadę (zachowanie przeglądarki). Zawieszone żądanie wstrzymuje zapisy
  w pozostałych kartach do czasu jego zakończenia, jak dziś w obrębie jednej karty.
- BFF i API bez zmian. Bez nowych zależności.

**Kryteria akceptacji.**
1. Vitest (`src/api/client.test.ts`). Środowisko testów (`environment: "node"`, Node 24) ma własne
   `navigator.locks`, więc każdy test ustawia je jawnie (`vi.stubGlobal`), nie polega na środowisku:
   - z dublerem `navigator.locks`: każdy zapis biegnie wewnątrz `request` z tą samą nazwą blokady, odczyty nie;
     błąd zapisu nie blokuje następnego; odrzucenie `request` daje `failed` bez wywołania `fetch`;
   - bez `navigator.locks` (usunięte w teście): zapisy idą przez kolejkę w obrębie karty, dotychczasowe testy
     kolejki przechodzą w tym wariancie.
2. E2E (`e2e/tests/cart-guest.spec.ts` albo nowy plik, Chromium, `http://localhost`): dwie karty jednego kontekstu
   przeglądarki bez `store_cart`, równoczesne „Do koszyka” na dwóch różnych produktach, po odświeżeniu licznik
   pokazuje 2, a `/cart` ma oba produkty.
3. `docs/features/cart/spec.md` (sekcja „BFF”, opis kolejki w `src/api/client.ts`, scenariusz „równoległe dodania
   z dwóch kart” w „Scenariuszach błędów”, sekcja „E2E” oraz „Ryzyka i ograniczenia”: gwarancja tylko z Web Locks
   API, fallback z obecnym ograniczeniem, wyścig odczytu z nieaktualnym `store_cart` z sekcji „Ryzyka i ograniczenia”
   tej specyfikacji) i `apps/store/CLAUDE.md` (sekcja „Koszyk”, opis kolejki) opisują blokadę między kartami
   i fallback.

## #81: komunikat przy 404 zmiany ilości linii, która jest w koszyku

**Problem.** `change()` w `apps/store/src/cart/CartPage.tsx` po każdym `not_found` wczytuje koszyk i pokazuje
„Pozycja została już usunięta”. Zwiększenie ilości linii `unavailable_product` dostaje z API 404 `ProductNotFound`
(„Produkt nie jest już dostępny.”), a linia zostaje w koszyku.

**Zachowanie.** Po `not_found` zmiany ilości albo usunięcia strona wczytuje koszyk jak dziś i:
- linii tego produktu nie ma w wczytanym koszyku: „Pozycja została już usunięta” (bez zmian);
- linia jest w wczytanym koszyku: przy linii komunikat `detail` z odpowiedzi 404 (dla `unavailable_product`:
  „Produkt nie jest już dostępny.”; bez `detail`: „Nie udało się zapisać zmian. Spróbuj ponownie.”), bez „Pozycja
  została już usunięta”; pole ilości wraca do ilości z wczytanego widoku, także gdy jest taka sama jak przed zmianą;
  „Usuń” zostaje;
- wczytanie zwróciło 401: „Twoja sesja wygasła. Zaloguj się ponownie.” jak przy 401 zapisu, pole ilości wraca do
  ilości z ostatniego widoku;
- wczytanie się nie udało z innego powodu: przy linii „Nie udało się zapisać zmian. Spróbuj ponownie.”, pole ilości
  wraca do ilości z ostatniego widoku.

Pole ilości linii `unavailable_product` zostaje aktywne: zmniejszenie ilości jest zawsze możliwe
(`docs/features/cart/spec.md`, sekcja „UI”), a API przyjmuje je bez znajomości produktu przez Catalog.

**Kryteria akceptacji.**
1. `CartPage.test.tsx`: linia `unavailable_product`, zmiana ilości 1 → 2, odpowiedź `not_found` z `detail`,
   wczytany koszyk nadal ma linię: komunikat `detail` przy linii, brak „Pozycja została już usunięta”, pole ilości
   pokazuje 1, przycisk „Usuń” jest.
2. Istniejący test „reloads the cart and says the line is gone on a 404” przechodzi bez zmian.
3. `CartPage.test.tsx`: `not_found`, wczytanie koszyka kończy się błędem: komunikat zapisu przy linii; `not_found`,
   wczytanie daje `unauthorized`: komunikat o wygasłej sesji; linia w koszyku i `not_found` bez `detail`: komunikat
   zapisu przy linii.
4. Tabela sytuacji w sekcji „UI” `docs/features/cart/spec.md` (wiersz „404 linii przy zmianie”) opisuje te przypadki.

## #82: polskie komunikaty walidacji żądań koszyka

**Problem.** `AddCartItemRequest` i `ChangeCartItemQuantityRequest` (`apps/api/src/Cart/UI/Http/`) zwracają w
`violations` angielskie komunikaty: brak klucza daje „This value should be of type mixed.” (błąd denormalizacji
przy braku argumentu konstruktora, zanim zadziała `Assert\NotNull`), zły typ daje „This value should be of type
integer.” (`Assert\Type` bez `message`). `MergeGuestCartRequest` ma ten sam wzorzec.

**Zachowanie.** Każdy komunikat w `violations` odpowiedzi 422 tych żądań jest po polsku, bez danych wejściowych.
Kody odpowiedzi, `propertyPath` i komunikaty, które już są po polsku, bez zmian:

| Przypadek | Komunikat |
|---|---|
| brak `productId` albo `null` | „Identyfikator produktu jest wymagany.” |
| `productId` nie jest napisem | „Identyfikator produktu musi być UUID.” |
| `productId` nie jest UUID | „Identyfikator produktu musi być UUID.” (bez zmian) |
| brak `quantity` albo `null` | „Ilość jest wymagana.” |
| `quantity` nie jest liczbą całkowitą (napis, ułamek, `2.0`, wartość logiczna) | „Ilość musi być liczbą całkowitą.” |
| `quantity` spoza 1–99 | „Ilość musi wynosić od 1 do 99 szt.” (bez zmian) |
| brak `guestCartId` albo `null` | „Identyfikator koszyka jest wymagany.” |
| `guestCartId` nie jest napisem | „Identyfikator koszyka musi być UUID.” |
| `guestCartId` nie jest UUID | „Identyfikator koszyka musi być UUID.” (bez zmian) |

Jedno pole daje najwyżej jeden komunikat (np. liczba w `productId` nie daje drugiego naruszenia z `Assert\Uuid`).
Założenia techniczne:
- Brak klucza zamienia się w `null` jednym mechanizmem: z pięciu `#[MapRequestPayload]` Cart
  (`GuestCartController`, `CustomerCartController`) znika `serializationContext` z `REQUIRE_ALL_PROPERTIES`, więc
  serializer podaje `null` dla brakującego argumentu typu `mixed`, a `Assert\NotNull` z komunikatem odrzuca go.
  Bez wartości domyślnych w DTO. Mapowania Inventory i Pricing bez zmian.
- Ograniczenia pola są sprawdzane po kolei do pierwszego naruszenia (`Assert\Sequentially`).
- Docblocki trzech DTO opisują nowe działanie (klucz wymagany przez `Assert\NotNull`, nie przez serializer).
- Bez komponentu tłumaczeń i bez zmiany locale aplikacji.

**Kryteria akceptacji.**
1. Testy funkcjonalne (`apps/api/tests/Functional/Cart`) dla gościa i klienta: każdy przypadek z tabeli, dla każdego
   żądania, sprawdza `violations[*].title` (i `propertyPath`); żaden komunikat nie jest po angielsku.
2. Pozostałe testy koszyka przechodzą bez zmiany wartości oczekiwanych.
3. Kontrakt bez zmian: eksport `make -C apps/api openapi` nie zmienia `apps/api/openapi.json` (pola żądań nadal
   `required`, bez `nullable` i `default`). Różnica w eksporcie to błąd zadania.
4. `docs/features/cart/spec.md`, sekcja „API” (kody 422 w tabelach endpointów gościa), wymienia komunikaty `violations`
   z tabeli powyżej.

## #84: jedna implementacja reguły „produkt do kupienia”

**Problem.** Reguła jest zakodowana dwa razy: `Cart::assertPurchasable` (`apps/api/src/Cart/Domain/Cart.php`)
i `match` stanów w `CartViewBuilder` (`apps/api/src/Cart/Application/View/CartViewBuilder.php`). Dziś są zgodne.

**Rozwiązanie.** Reguła trafia do value objectu `ProductOffer` (`Cart_Domain`):
- `ProductOffer::purchasability(int $quantity): Purchasability`, gdzie `Purchasability` to nowy enum domeny
  z przypadkami w kolejności reguły: `NoPrice` (brak ceny), `OutOfStock` (`available = 0`), `InsufficientStock`
  (`quantity > available`), `Purchasable`.
- `Cart::assertPurchasable` używa go: `NoPrice` i `OutOfStock` → `ProductNotPurchasable`, `InsufficientStock` →
  `InsufficientStock`.
- `CartViewBuilder`: produkt nieznany Catalogowi → `unavailable_product` (warunek widoku, nie oferty, jak dziś
  przed pozostałymi); w pozostałych przypadkach stan linii wynika z `ProductData::offerFor($productId)->purchasability($quantity)`
  (`NoPrice` → `no_price`, `OutOfStock` → `out_of_stock`, `InsufficientStock` → `insufficient_stock`,
  `Purchasable` → `purchasable`).
- Zachowanie bez zmian: te same błędy domeny, kody HTTP, stany linii, sumy. Deptrac bez zmian
  (`Cart_Application` → `Cart_Domain` jest dozwolone).

**Kryteria akceptacji.**
1. Testy jednostkowe `ProductOffer::purchasability` (TDD): brak ceny ma pierwszeństwo przed `available = 0`, granice
   `quantity = available` i `available + 1`.
2. Istniejące testy `Cart` i `CartViewBuilder` przechodzą bez zmiany wartości oczekiwanych.
3. W kodzie Cart nie ma innego miejsca, które porównuje ilość z dostępnością albo sprawdza brak ceny dla stanu linii
   lub błędu zakupu.
4. `docs/features/cart/spec.md` (sekcje „Value objecty” i „Widok koszyka”) i docblock `CartViewBuilder` („The one
   place of the totals of a cart…”) wskazują `ProductOffer` jako jedyne miejsce reguły zakupu.

## #83: specyfikacja koszyka a trzy zachowania BFF i klienta

Sprawdzone na `main`: `docs/features/cart/spec.md` opisuje już wszystkie trzy zachowania z issue:
1. sonda `summary` po 404 dodania i zmiany ilości (sekcja „BFF”, punkt 4 „Wyboru koszyka”);
2. kolejkę zapisów w przeglądarce (sekcja „BFF”, ostatni punkt; kod jest w `src/api/client.ts`, nie w
   `src/cart/client.ts`, jak podaje issue);
3. 404 z BFF dla `productId` spoza UUID w ścieżce `PATCH` i `DELETE` (sekcja „BFF”, punkt o `Origin`).

Kryterium akceptacji issue jest spełnione bez zmiany. Zadanie nie powstaje; końcowy PR epiku zamyka issue.

## #85: indeks `cart_line_cart_id_idx`

Sprawdzone: indeks jest wymagany przez Doctrine DBAL (4.5.0, `Table::_addForeignKeyConstraint`). Dla klucza obcego
`cart_id` (`ManyToOne` w `CartLineRecord`) DBAL dodaje indeks na kolumnach klucza, chyba że istnieje indeks z tymi
samymi kolumnami (`Index::isFulfilledBy` wymaga tej samej liczby kolumn), więc unikalny `(cart_id, product_id)` go
nie zastępuje. Po usunięciu `#[ORM\Index]` Doctrine dodałby indeks `IDX_…` na `cart_id`, a migracja usuwająca
`cart_line_cart_id_idx` rozjechałaby schemat z mapowaniem. Kryterium issue („atrybut usunięty, migracja usuwa
indeks, schemat zgodny z bazą”) da się spełnić tylko po usunięciu relacji `ManyToOne` z mapowania (poza zakresem).

**Zachowanie.** Indeks zostaje, bez przebudowy persystencji. Sekcja „Persystencja i transakcje” w `docs/features/cart/spec.md` (opis `cart_line`)
podaje powód, a komentarz przy `#[ORM\Index]` w `CartLineRecord` opisuje go zgodnie z tym ustaleniem. Bez migracji.

**Kryteria akceptacji.**
1. `docs/features/cart/spec.md` wyjaśnia, dlaczego indeks `cart_id` istnieje obok unikalnego `(cart_id, product_id)`.
2. `make -C apps/api check` i `make -C apps/api test` przechodzą (schemat bez zmian).

## Kryteria akceptacji epiku

1. Kryteria każdej sekcji powyżej są spełnione.
2. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check`, `make -C apps/store test`
   oraz `make e2e`.
3. `docs/features/cart/spec.md` i `apps/store/CLAUDE.md` opisują stan po zmianach, w tym samym commicie co kod
   każdej zmiany.

## Ryzyka i ograniczenia

- Blokada między kartami (#80) działa tylko w przeglądarkach z Web Locks API i w bezpiecznym kontekście (HTTPS albo
  `localhost`). Bez niej zostaje kolejka w obrębie karty i ryzyko z #80.
- Blokada szereguje zapisy wszystkich kart przeglądarki: zawieszone żądanie jednej karty wstrzymuje zapisy pozostałych.
- Zostaje wyścig poza zakresem #80: przy nieaktualnym `store_cart` (koszyk wygasły albo scalony) dodanie w jednej
  karcie zakłada nowy koszyk i ustawia nowe ciasteczko, a równoległy odczyt (`/api/cart`, `/api/cart/summary`) w innej
  karcie ze starym ciasteczkiem dostaje 404 i może je usunąć, jeśli jego odpowiedź przyjdzie później. Odczyty nie
  biorą blokady. Ograniczenie trafia do „Ryzyk i ograniczeń” w `docs/features/cart/spec.md`.
- Test E2E z #80 przed poprawką nie zawsze kończy się błędem (wyścig), więc fazę RED tej poprawki pokazuje test Vitest.
