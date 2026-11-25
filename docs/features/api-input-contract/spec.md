# API-INPUT-CONTRACT: kontrakt wejścia API (etap 1 i etap 2)

Epik #779 ma sprawić, że złe dane wejściowe dają zawsze 4xx z polem naruszenia i polskim komunikatem, a pilnuje tego
wspólny test. Etap 1 (w kodzie) obejmuje wejścia poza polami treści JSON: odpowiedzi ogólne frameworka, parametry
zapytań, nagłówek `Idempotency-Key` i `detail` trzech wyjątków. Etap 2 (w kodzie; sekcja „Etap 2: reguła tekstu” na
końcu pliku) obejmuje treść tekstów: same białe i niewidoczne znaki, znaki sterujące i odczyt zapisanych wierszy.

## Etap 1: odpowiedź na błędne wejście poza treścią JSON

Issues: #779 (epik), #761, #441, część #413 (polskie komunikaty frameworka), część #583 (polskie `detail` konfliktów
wersji), część #644 (identyczne naruszenia `productId` list panelu).
Zakres: `apps/api/src/Shared`, `apps/api/src/Catalog`, `apps/api/src/Cart`, `apps/api/src/Inventory`,
`apps/api/src/Ordering`, `apps/api/src/Payment`, `apps/api/src/Pricing`, `apps/api/config`, `apps/api/translations`,
`apps/api/composer.json`, `apps/api/composer.lock`, `apps/api/symfony.lock`, `apps/api/tests`, `apps/api/CLAUDE.md`,
`docs/features/api-input-contract`.

**Dowody.** Próbę błędnych żądań do 72 operacji (rewizja `e250032a`) opisuje raport
`docs/reports/api-input-contract-probe-2026-11-21.md`. Zero 5xx w tej próbie nie jest gwarancją całego API.

## Decyzje właściciela

- **D1.** Etap 1 to kontrakt odpowiedzi na błędne wejście poza treścią JSON; reguły tekstów (białe i niewidoczne znaki)
  są etapem 2.
- **D2.** Nowa zależność `symfony/translation` z domyślnym językiem `pl`.
- **D3.** Z #583 w etapie 1 są tylko polskie `detail` obu konfliktów wersji; osobne `type` problemów i zmiana panelu
  zostają na później.
- **D4.** Angielskie `detail` wyjątków projektu, których nie wymienia sekcja D, zostają poza etapem 1.
- Identyczne duplikaty naruszeń są usuwane; różnych naruszeń jednego pola etap nie ogranicza do jednego.
- #440 zostaje otwarte do sprawdzenia warunków opisanych w zgłoszeniu.

## Zachowanie

API ma 72 operacje (30 w `openapi.json`, 42 w `openapi-admin.json`), 63 parametry zapytań na 19 operacjach
i `Idempotency-Key` na 5 operacjach. Ścieżki niżej są bez prefiksu `/api/v1`.
Kryteria dotyczą `detail` i `violations[].title`; `title` problemu (fraza statusu HTTP) nie jest częścią kontraktu
języka.

### A. Odpowiedzi ogólne (`detail`, bez `violations`)

| Przypadek | Operacje | Status | `detail` |
|---|---|---|---|
| Metoda spoza trasy | wszystkie | 405 | „Metoda nie jest dozwolona.” |
| Brak tokenu | wszystkie wymagające tokenu (`/admin/...`, `/me/...`) | 401 | „Wymagane jest uwierzytelnienie.” |
| Identyfikator w ścieżce w formacie, którego trasa nie przyjmuje | operacje z parametrem ścieżki, które dają ogólne 404 (38) | 404 | „Nie znaleziono zasobu.” |
| `Content-Type` inny niż JSON, poprawny token | operacje z treścią JSON poza webhookiem (28) | 415 | „Nieobsługiwany typ treści.” |
| Zepsuty JSON, niepoprawny UTF-8 w treści, zagnieżdżenie ponad limit dekodera | operacje z treścią JSON poza webhookiem (28) i webhook (29 razem) | 400 | „Żądanie jest nieprawidłowe.” |

Pozostałe stałe teksty `ProblemDetailsSubscriber`: 403 „Brak dostępu.”, 406 „Żądana reprezentacja nie jest dostępna.”,
409 „Żądanie jest sprzeczne z bieżącym stanem.”, 422 „Żądanie jest nieprawidłowe.”, 429 „Zbyt wiele żądań.”, tekst
zapasowy „Nie można przetworzyć żądania.”.

### B. Parametry zapytań (status 400, `propertyPath` = nazwa parametru, o ile wiersz nie mówi inaczej)

Skróty komunikatów:

| Skrót | Komunikat |
|---|---|
| T | „Ta wartość ma nieprawidłowy typ.” |
| pusta | „Ta wartość nie powinna być pusta.” |
| UUID | „Ta wartość nie jest prawidłowym UUID.” |
| krótka(n) | „Ta wartość jest zbyt krótka. Powinna mieć n lub więcej znaków.” |
| długa(n) | „Ta wartość jest zbyt długa. Powinna mieć n lub mniej znaków.” |
| znaki | „Ta wartość nie pasuje do oczekiwanego zestawu znaków UTF-8.” |
| opcja | „Ta wartość powinna być jedną z podanych opcji.” |
| zakres(max) | „Ta wartość powinna być pomiędzy 1 a max.” |

Teksty poza T pochodzą z polskiego katalogu walidatora frameworka (`validators.pl.xlf` w wersji z `composer.lock`).
Tytuł T jest wpisem projektu w `apps/api/translations`.

**B1. Parametry z komunikatem frameworka albo własnym tekstem według tabeli**

| Parametr | Operacje | Przypadek → komunikat |
|---|---|---|
| `category` | `GET /products`, `GET /admin/products`, `GET /admin/stock`, `GET /admin/prices` | `category[]=x` → T; pusty → pusta; `abc` → UUID |
| `q` | te same cztery | `q[]=x` → T; 1 znak → krótka(2); 101 znaków → długa(100); niepoprawny UTF-8 → znaki; na trzech listach panelu `ab%00` → „Szukana fraza zawiera niedozwolone znaki.” |
| `priceMin`, `priceMax` | `GET /products` | `[]` → T; pusty → pusta; `abc`, `-1`, `1.5` → „Cena musi być liczbą całkowitą groszy, 0 albo więcej.”; `priceMin` większe niż `priceMax` → przy `priceMin` „Cena minimalna nie może być wyższa niż maksymalna.” |
| `available` | `GET /products` | `[]` → T; `false` → opcja |
| `sort` | `GET /products` | `[]` → T; nieznana wartość → opcja; `relevance` bez `q` → „To sortowanie wymaga szukanej frazy.” |
| `page` | `GET /products` | `abc`, `1.5`, pusty, `[]` → T; `0`, `-1` → „Ta wartość powinna być dodatnia.” |
| `perPage` | `GET /products` (max 48), `GET /me/orders` (50), `GET /admin/products`, `GET /admin/stock`, `GET /admin/orders`, `GET /admin/prices`, `GET /admin/variants/{variantId}/stock-history`, `GET /admin/variants/{variantId}/price-history` (100) | `abc`, `[]` (na `GET /products` także `1.5`) → T; `0`, max+1 → zakres(max) |
| `perPage`, `status`, `after` | `GET /admin/promotions` | `[]` (dla `perPage` także `abc`) → T; pozostałe przypadki mają własne polskie komunikaty |
| `option` | `GET /products` | `option[]=x` → „Podaj opcje jako powtórzony klucz option, nie option[].”; bez dwukropka → „Opcja musi mieć postać oś:wartość.”; niepoprawny UTF-8 → „Opcja zawiera niedozwolone znaki.”; oś ponad 30 albo wartość ponad 40 znaków → „Oś opcji ma od 1 do 30 znaków, a wartość od 1 do 40.”; 51 opcji → „Można podać najwyżej 50 opcji.”; opcje 6 osi → „Można podać opcje najwyżej 5 różnych osi.” |
| `status` | `GET /admin/products` | `[]` → T; nieznany → „Status produktu musi być jednym z: draft, published.” |
| `status` | `GET /admin/orders` | `[]` → T; nieznany → „Status zamówienia jest nieprawidłowy.” |
| `after` | `GET /me/orders`, `GET /admin/products`, `GET /admin/stock`, `GET /admin/orders`, `GET /admin/prices`, `GET /admin/variants/{variantId}/stock-history`, `GET /admin/variants/{variantId}/price-history` | `[]` → T; pusty → krótka(1); 2049 znaków → długa(2048); niepoprawny UTF-8 → znaki; `abc` → „Kursor stronicowania jest nieprawidłowy.” |
| `productId` | `GET /product-offers` | brak parametru → przy `productId` „Ten zbiór powinien zawierać 1 lub więcej elementów.”; pusty element → przy `productId[0]` pusta; `abc` → przy `productId[0]` UUID |
| `version` | `DELETE /admin/promotions/{id}` | `version[]=x` → naruszenie `version` „Wersja musi być dodatnią liczbą całkowitą.” |
| `productId` | `GET /admin/stock`, `GET /admin/prices` | nil UUID → jedno naruszenie „Identyfikator produktu musi być poprawnym UUID.” |

**B2. Parametry z własnym komunikatem.** Test obejmuje je przypadkiem złego rodzaju (`p[]=x`), z dokładnym tekstem.

| Parametr | Operacja | Komunikat |
|---|---|---|
| `discountCode` | `GET /guest-carts/{cartId}`, `GET /me/cart` | „Kod rabatowy musi być tekstem.” |
| `path` | `GET /categories/redirect` | „Adres kategorii to od 1 do 4 slugów rozdzielonych ukośnikiem.” |
| `chunk` | `GET /sitemap/products` | „Klucz porcji jest niepoprawny.” |
| `withCounts` | `GET /admin/categories` | „Parametr withCounts przyjmuje tylko wartość true.” |
| `version` | `DELETE /admin/categories/{id}` | „Wersja musi być dodatnią liczbą całkowitą.” |
| `sort` | `GET /admin/products`, `GET /admin/stock`, `GET /admin/prices` | „Sortowanie może mieć tylko wartość name, -name, -updatedAt albo updatedAt.” |
| `images` | `GET /admin/products` | „Filtr zdjęć może mieć tylko wartość none.” |
| `level` | `GET /admin/stock` | „Poziom stanu musi być jednym z: out_of_stock, low_stock.” |
| `productId` | `GET /admin/stock`, `GET /admin/prices` | „Identyfikator produktu musi być poprawnym UUID.” |
| `q` | `GET /admin/customers` | „Szukana fraza to fragment e-maila albo imienia i nazwiska, od 3 do 100 znaków bez znaków sterujących.” |
| `sort` | `GET /admin/customers` | „Sortowanie może mieć tylko wartość -lastOrderAt, name, -orderCount albo -paidGross.” |
| `after` | `GET /admin/customers` | „Kursor strony jest nieprawidłowy.” |
| `perPage` | `GET /admin/customers` | „Liczba wyników na stronie to liczba całkowita od 1 do 100.” |
| `refund` | `GET /admin/orders` | „Filtr zwrotów może mieć tylko wartość pending albo overdue.” |
| `q` | `GET /admin/orders` | „Szukana fraza to numer zamówienia albo tekst od 3 do 100 znaków bez znaków sterujących.” |
| `placedFrom`, `placedTo` | `GET /admin/orders` | „Podaj datę w formacie RRRR-MM-DD, od 2000 do 2999 roku.” |
| `sort` | `GET /admin/orders` | „Sortowanie może mieć tylko wartość -placedAt, placedAt, -total albo total.” |
| `customerId` | `GET /admin/orders` | „Identyfikator klienta ma od 1 do 255 znaków bez znaków sterujących.” |
| `status` | `GET /admin/discount-codes` | „Status kodu musi być jednym z: disabled, planned, active, expired.” |
| `q` | `GET /admin/discount-codes` | „Początek kodu musi mieć od 1 do 32 znaków.” |
| `after` | `GET /admin/discount-codes` | „Kursor stronicowania jest nieprawidłowy.” |
| `perPage` | `GET /admin/discount-codes` | „Liczba kodów na stronie musi być od 1 do 100.” |

Próba z raportu nie doszła do `GET /me/cart` i `GET /me/orders` (brak tokenu klienta); ich wiersze wynikają z kodu
(wspólna obsługa `discountCode`, `CustomerOrderListQuery`) i pierwszy raz sprawdza je test kontraktu.

### C. Nagłówek `Idempotency-Key` (status 400, `propertyPath` = `Idempotency-Key`)

Operacje: `POST /orders`, `POST /me/orders`, `POST /orders/{orderId}/payments`, `POST /me/orders/{orderId}/payments`,
`POST /admin/variants/{variantId}/stock/receipts`.

| Przypadek | Komunikat |
|---|---|
| Brak nagłówka | „Nagłówek Idempotency-Key jest wymagany.” |
| Pusty, 101 znaków, znak spoza ASCII | „Nieprawidłowy klucz żądania.” |

### D. `detail` trzech wyjątków

| Przypadek | Status | `detail` |
|---|---|---|
| Korekta stanu z nieaktualną wersją (`StockModifiedConcurrently`) | 409 | „Stan został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie.” |
| Zmiana ceny z nieaktualną wersją (`PriceModifiedConcurrently`) | 409 | „Cena została w międzyczasie zmieniona. Odśwież ją i spróbuj ponownie.” |
| Nieznany produkt w operacjach panelu (`ProductNotFound`) | 404 | „Produkt nie istnieje.” |

`type` tych problemów zostaje `about:blank`.

## Rozwiązanie

1. **Tłumaczenia frameworka.** `symfony/translation` z domyślnym językiem `pl`: komunikaty walidatora bez własnego
   tekstu i błąd typu z `#[MapQueryString]` i `#[MapRequestPayload]` przechodzą przez translator. Katalog projektu
   (`apps/api/translations`, domena `validators`) nadpisuje jeden wpis: błąd typu ma tekst T, bez nazwy typu PHP.
   Własne polskie komunikaty nie mają wpisów w katalogu, więc translator ich nie zmienia.
2. **Teksty własne.** Komunikaty z tabel A–D są w kodzie, który je zwraca: `ProblemDetailsSubscriber` (sekcja A),
   stałe klas zapytań (`ListProductsQuery`, `BackofficeProductsQuery`, `BackofficeStockQuery`, `BackofficePricesQuery`,
   `BackofficeOrderListQuery`, `BackofficeCustomerListQuery`, `StockHistoryQuery`, `PriceHistoryQuery`,
   `PromotionListQuery`, `DiscountCodeListQuery`: m.in. teksty kursorów i szukanej frazy), `OptionFilter`, kontrolery
   czytające parametr same (`BackofficeCategoryTreeController`, `SitemapProductsController`,
   `CategoryRedirectController`, `DeletePromotionController`), trzy miejsca odczytu nagłówka (`OrderPlacement`,
   `PaymentEndpoints`, `ReceiveStockController`), `InvalidReceiptKey`, `StockModifiedConcurrently`,
   `PriceModifiedConcurrently`, `ProductNotFound` (sekcja D). Wyjątki Application (`Invalid*Cursor`,
   `InvalidBackofficeProductSearch`) mają komunikaty po angielsku, które nie trafiają do klienta: odpowiedź budują
   klasy zapytań i kontrolery.
3. **Identyczne duplikaty.** `ProblemDetailsSubscriber` pomija naruszenie o tym samym `propertyPath` i `title` co
   wcześniejsze w tej odpowiedzi. Różne naruszenia jednego pola zostają.
4. **`version` promocji.** `DeletePromotionController` czyta parametr tak, że tablica (`version[]=x`) daje naruszenie
   `version` bez `detail`, nie wyjątek frameworka.
5. **Strażnik klas żądań (#441).** Test przechodzi po klasach z `#[RequiredKeysBody]` znalezionych w kontrolerach
   z routera i wymaga parametrów konstruktora bez typu albo `mixed`.
6. **`page` i `perPage` listy produktów.** W `ListProductsQuery` to właściwości ustawiane po konstruktorze, nie jego
   parametry: wartość, której denormalizator nie przyjmuje jako liczby całkowitej (`1.5`), daje T i zostawia wartość
   domyślną, zamiast trafić do parametru `int` konstruktora, który PHP obcina z ostrzeżeniem.

## Wdrożenie i klienci

Schematy `openapi.json` i `openapi-admin.json` (opis 400 usuwania promocji mówi o `violations`), typy klientów TS,
sklep, panel i E2E nie zależą od tekstów z tabel A–D ani od liczby naruszeń. Zadanie, które zmienia klasę zapytania
albo kontroler, uruchamia `make -C apps/api openapi` i `make api-client`; różnica, jeśli wystąpi, wchodzi do tego
samego commita. Zmiana tekstów i kodu API nie wymaga migracji, zmian compose ani chartu.

## Testy

### Test kontraktu wejścia

Jedna klasa testu funkcjonalnego w `apps/api/tests/Functional/Shared/InputContract` z tabelą przypadków, która
odwzorowuje tabele B1, B2 i C wiersz po wierszu. Tabela jest podzielona na pliki według modułu właściciela operacji.

- **Wiersz tabeli:** operacja, rodzaj tokenu, nazwa fikstury, badane wejście, wartość poprawna, wartość błędna,
  oczekiwany zbiór par (`propertyPath`, `title`), treść żądania i pole `headers` (inne nagłówki obu żądań, np.
  `Order-Access-Token` zamówienia gościa).
- **Dwa wykonania na wiersz, każde jako osobny test:** żądanie z wartością poprawną daje 2xx; żądanie z wartością
  błędną daje 400 z dokładnie oczekiwanym zbiorem naruszeń. Każde wykonanie buduje stan tą samą fiksturą od zera
  (transakcja testu jest wycofywana po każdym teście, `DAMA\DoctrineTestBundle`), a klucz idempotencji jest nowy
  w każdym wykonaniu. Kontrola dodatnia nie usuwa więc zasobu, nie zmienia wersji i nie zużywa klucza przed próbą
  błędną, a 400 próby błędnej dotyczy badanego wejścia, nie stanu po wcześniejszej operacji.
- **Fikstury** (`InputContractFixtures`) składają się wyłącznie z istniejących elementów: `TestTokenFactory`
  (pracownik, klient), fabryki Foundry (`ProductFactory`, `CategoryFactory`, `DiscountCodeFactory`), `CartFixture`,
  `OrderingFixture`, `StockFixture`, pomocniki `OrderEndpointTestTrait`, `PaymentEndpointTestTrait`,
  `StockEndpointTestTrait`, agregat `Promotion::plan` oraz repozytoria Doctrine kategorii, produktów, kodów rabatowych,
  promocji i przekierowań kategorii, którymi zapisują stan.
- **Granice mechanizmu:** nie generuje wartości ze schematów, nie mutuje treści JSON i nie jest biblioteką dla innych
  testów; zawiera tylko przypadki z tej specyfikacji.
- **Router i OpenAPI** służą do list operacji przypadków 405 i 401 (wzorem `AdminRouteAccessTest`) i do strażnika
  zamkniętej listy (`ClosedInputListTest`, logika w `ClosedInputList`, jedna lista wierszy w `InputRows`): parametr
  zapytania albo nagłówek `Idempotency-Key` opisany w OpenAPI bez wiersza w tabeli przerywa test (dopasowanie po
  metodzie, ścieżce w postaci z OpenAPI i nazwie wejścia), tak samo trasa `/api/v1` bez wpisu w jednym z dwóch
  dokumentów. Strażnik nie generuje przypadków. Pozostałe nagłówki z OpenAPI (`Order-Access-Token`, podpisy webhooka)
  są poza tabelą.
- **Sekcja A:** 404, 415 i 400 dla operacji z tabeli A, z poprawnym tokenem i istniejącym zasobem tam, gdzie błąd ma
  wystąpić za autoryzacją.

### Pozostałe testy

- Test jednostkowy `ProblemDetailsSubscriber`: wszystkie stałe teksty, pominięcie identycznego duplikatu, zachowanie
  dwóch różnych naruszeń jednego pola.
- Strażnik klas żądań z próbą obejścia: klasa testowa z parametrem `int` go łamie.
- Sekcja D w istniejących klasach testów modułów: 409 korekty stanu, 409 zmiany ceny, 404 produktu, z dokładnym
  `detail`.

### TDD

Każde zadanie najpierw dopisuje swoje wiersze tabeli i testy, uruchamia je i zapisuje wynik RED, potem wprowadza
zmianę i zapisuje wynik GREEN. Dotyczy to także tłumaczeń. Strażnik klas żądań i strażnik zamkniętej listy pilnują
stanu zgodnego z tabelą: ich RED pokazuje próba obejścia w teście (zmieniona kopia dokumentu albo tabeli).

## Dokumentacja razem z implementacją

- `apps/api/CLAUDE.md`, sekcja „API”: domyślny język komunikatów walidatora (`pl`, katalog frameworka, nadpisany błąd
  typu) i zasada, że nowy parametr zapytania albo nagłówek żądania dostaje wiersz w tabeli testu kontraktu.
- Ta specyfikacja opisuje stan obecny; zmiana zachowania wejścia aktualizuje ją w tym samym commicie co kod.

## Poza etapem 1

- Reguły pól treści JSON (422) i przypadki per pole (#644).
- Treść tekstów: same białe i niewidoczne znaki (#305, #443, #641, #632 F4, `q` list panelu, nazwa metody dostawy):
  etap 2.
- #632 F3: dwa różne naruszenia `q` przy `productId` zostają, oba po polsku.
- Pozostałe angielskie `detail` wyjątków projektu (około 77 komunikatów w klasach wyjątków).
- Osobne `type` problemów obu konfliktów korekty stanu i zmiana panelu (#583).
- `title` problemu, opisy OpenAPI i komunikaty komend konsoli zostają po angielsku.
- Upload zdjęcia, weryfikacja podpisu webhooka, `Order-Access-Token` (zły token to celowo 404), `page` ponad zakres
  liczby całkowitej.
- #440 (odczyt wierszy zapisanych przed ostrzejszymi regułami): etap 2. Fala 2 z #779 (#642, #643, #662, #702, #760).

## Ryzyka i założenia

- Teksty katalogu frameworka mogą się zmienić przy aktualizacji Symfony; test kontraktu to wykryje i oczekiwania
  trzeba wtedy poprawić.
- Tabela przypadków jest w jednym katalogu i sięga po fikstury kilku modułów; alternatywa (przypadki w testach
  modułów) wymagałaby osobnego rejestru dla strażnika zamkniętej listy.
- Założenia techniczne: `title` problemu zostaje frazą statusu HTTP; błąd typu ma jeden ogólny tekst bez nazwy typu.

## Etap 2: reguła tekstu

Jedna reguła dla tekstów wpisywanych przez ludzi: co jest tekstem pustym, które znaki są niedozwolone i co jest
przycinane. Obowiązuje we wszystkich polach tekstowych treści JSON i we frazach wyszukiwania, a pilnuje jej wspólny
test z zamkniętą listą pól.

Issues: #305, #440, #443, #641, #632 (F4), część #644 (pola adresu zamówienia, notatka przyjęcia stanu, `q`).
Zakres: `apps/api/src/Shared`, `apps/api/src/Catalog`, `apps/api/src/Delivery`, `apps/api/src/Inventory`,
`apps/api/src/Ordering`, `apps/api/src/Pricing`, `apps/api/tests`, `apps/api/openapi.json`,
`apps/api/openapi-admin.json`, `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts`,
`apps/api/CLAUDE.md`, `docs/features/api-input-contract` i zdania o tekstach w specyfikacjach `catalog-admin`,
`search`, `admin-features`, `delivery`, `inventory`, `checkout`, `order-management`, `promotions`, `quality-fixes`.

**Dowody.** Rewizja `db954a20`, lokalny stos z danymi demo. Raport
`docs/reports/api-input-contract-probe-2026-11-24.md`: ponowna próba etapu 1 (6191 żądań do 72 operacji: zero 5xx,
zero tekstów po angielsku, każdy błąd jako `application/problem+json`) i próba tekstów (727 żądań: 26 tekstów
w 27 wejściach, pojedyncze reprodukcje).
Wynik próby tekstów:

| Klasa tekstu | Wynik na `db954a20` |
|---|---|
| Same białe znaki Unicode (NBSP, U+3000) | Catalog i Delivery odrzucają albo zapisują brak wartości. Zamówienie zapisuje `fullName`, `street` i `city` równe NBSP (201). Notatka korekty stanu z powodem „inne” i notatka przyjęcia zapisują NBSP. `q` listy sklepu i trzech list panelu (produkty, stany, ceny) daje 200. |
| Sam znak niewidoczny (U+200B, U+FEFF, U+00AD, U+2060, U+200E, U+202E, U+200D, U+3164) | Przyjęty w każdym polu bez formatu poza nazwą promocji: nazwa kategorii, produktu i metody dostawy, tekst alternatywny, oś, wartość wariantu, opisy, dane zamówienia, notatki magazynu i frazy `q` sześciu list (lista kodów rabatowych odrzuca wszystkie poza U+3164). |
| Znak sterujący w środku | U+009F (C1) przyjęty w każdym polu bez formatu poza nazwą promocji. Notatka magazynu przyjmuje też U+0085, U+2028 i U+2029. `q` listy sklepu przyjmuje każdy znak sterujący, także NUL; `q` list produktów, stanów i cen odrzuca tylko NUL, `q` listy kodów rabatowych żadnego. |
| Brzegi | U+200B na brzegach zostaje w zapisanej wartości wszędzie poza promocją i kodem. Zamówienie i notatki magazynu zapisują też NBSP z brzegów. Kod pocztowy, telefon i e-mail między NBSP to 422, między spacjami ASCII 201. `q` z trzech spacji ASCII na listach produktów, stanów i cen panelu to 200, na liście sklepu 400. |
| Tekst do zachowania | Nazwa promocji zakończona flagą Anglii traci znaki tagów (#305). Pozostałe pola zachowują flagę, serce z U+FE0F i emoji z U+200D. |

Próba nie doszła do `POST /me/orders` (brak tokenu klienta) ani do notatek zmiany statusu i wykonania zwrotu (wymagają
opłaconego zamówienia); sprawdza je dopiero test kontraktu tekstów.

Odczyt zapisanych wierszy na `db954a20`: zamówienie z `full_name` zmienionym w bazie na tekst z tabulatorem
odpowiadało 422 na `GET /admin/orders/{orderId}`, więc nie dało się go opłacić, wygasić ani zmienić (eksperyment na
lokalnym stosie).

### Decyzje

Właściciel zaakceptował 2026-11-24 zakres etapu i decyzje E1–E7.

- **E1. Zakres etapu.** Reguła tekstu we wszystkich polach tekstowych treści JSON i frazach `q`, wspólny test
  z zamkniętą listą pól i odczyt wierszy zapisanych przed etapem (#440). Reszta #779 zostaje poza etapem („Poza
  etapem 2”).
- **E2. Treść reguły** (T1–T5 niżej): tekst pusty to tekst bez znaku widocznego; niedozwolone są wszystkie znaki
  sterujące (także C1) i separatory linii; z brzegów znikają białe znaki Unicode i cztery znaki niewidoczne; w środku
  tekstu nic nie jest usuwane, więc emoji i flagi zostają.
- **E3. Jedna implementacja w `Shared\Domain`.** Reguła to funkcje bez stanu w `App\Shared\Domain\Text`, których używają
  value objecty i klasy żądań każdego modułu. Doprecyzowuje zasadę „brak wspólnych value objectów między contextami”
  (`apps/api/CLAUDE.md`): value objecty zostają własne, wspólna jest tylko higiena tekstu, która nie jest pojęciem
  żadnego contextu. Alternatywa: osobne wzorce w pięciu modułach (klasy pomocnicze Catalog i Delivery, `ControlCharacters`
  ze `StaffNote`, `StockNote`, `Promotion::validName`); próba pokazuje pięć różnych wyników tych kopii.
- **E4. Dane zamówienia (#443, p. 1).** `fullName`, `street` i `city` podlegają regule; `postalCode`, `phone` i `email`
  są przycinane według T2 przed sprawdzeniem formatu. Zmienia to zapis z `docs/features/checkout/spec.md`
  („przycinanie tych pól zostaje `trim()`”).
- **E5. Notatki magazynu (#443, p. 2).** Notatka korekty i przyjęcia podlega regule; tabulator i końce wiersza
  (U+0009, U+000A, U+000D) zostają dozwolone, bo pole w panelu jest wielowierszowe.
- **E6. Frazy `q`.** Fraza pusta według T3 jest za krótka, znak niedozwolony w frazie to 400 przy `q`, także na liście
  sklepu. `q` z samych spacji na listach produktów, stanów i cen panelu przestaje dawać 200.
- **E7. Odczyt zapisanych wierszy (#440).** Repozytoria i odczyty odtwarzają teksty z bazy bez ponownej walidacji.
  Wiersz zapisany przed etapem, którego reguła nie przyjmuje, daje się odczytać i przetworzyć, a jego zapis z panelu
  wymaga poprawienia pola. Alternatywa: walidacja przy odczycie zostaje, a przed wdrożeniem właściciel uruchamia
  zapytanie kontrolne na serwerze; #440 zostaje wtedy otwarte, a zamówienie z niedozwolonym tekstem przestaje dać się
  opłacić i wygasić.

### Reguła

- **T1. Znaki niedozwolone.** Tekst, który nie jest poprawnym UTF-8, znaki sterujące (kategoria Unicode Cc:
  U+0000–U+001F, U+007F, U+0080–U+009F), separator linii U+2028 i separator akapitu U+2029. Szukane w wartości, jak
  przyszła, przed przycięciem. Krótki opis produktu dopuszcza U+000A; notatka magazynu dopuszcza U+0009, U+000A
  i U+000D.
- **T2. Brzegi.** Z początku i końca znikają białe znaki Unicode (`\s` i kategoria Z, m.in. spacja, U+00A0, U+2003,
  U+202F, U+3000, U+180E) oraz U+200B, U+2060, U+FEFF i U+00AD. W środku tekstu nic nie jest usuwane.
- **T3. Tekst pusty.** Tekst, w którym po przycięciu nie ma znaku widocznego: zostają najwyżej znaki kategorii Z, Cc
  i Cf i znaki o właściwości Unicode `Default_Ignorable_Code_Point` (m.in. U+200D, U+200E, U+202E, U+FE0F, U+3164). Pole
  wymagane odpowiada komunikatem braku wartości; pole opcjonalne zapisuje brak wartości. Znak prywatnego użytku (Co)
  i znak, którego wersja Unicode w PCRE jeszcze nie zna (Cn, np. nowe emoji), liczą się jako widoczne: tekst z samego
  takiego znaku nie jest pusty. Wyjątek: kod Cn z właściwością `Default_Ignorable_Code_Point` (np. U+2065, U+E0080)
  jest niewidoczny jak pozostałe znaki tej właściwości.
- **T4. Długość** liczy się po przycięciu.
- **T5. Zachowane.** Znak formatujący obok znaku widocznego zostaje: U+200D w emoji, U+FE0F po znaku, znaki tagów
  flagi (U+E0020–U+E007F), także na końcu tekstu.

Pole z formatem (`postalCode`, `phone`, `email`) sprawdza T1 na wartości, jak przyszła (komunikat formatu), potem
stosuje T2, potem swój format; tekst pusty według T3 to brak wartości.
Przypadki i zapisane wartości: „Pola z formatem: przypadki”.

### Pola treści JSON (status 422, jedno naruszenie przy polu)

Rodzaj: **W** wymagany, **O** opcjonalny, **F** z formatem. Ścieżki bez prefiksu `/api/v1`. Kolumna „Zły typ” dotyczy
liczby i listy w miejscu tekstu.

| Pole | Operacje | Rodzaj | Tekst pusty | Znak niedozwolony | Zły typ |
|---|---|---|---|---|---|
| `name` | `POST /admin/categories`, `PUT /admin/categories/{id}` | W | „Nazwa kategorii musi mieć od 1 do 100 znaków i nie może zawierać znaków sterujących.” | ten sam | „Nazwa kategorii musi być tekstem.” |
| `name` | `POST /admin/products`, `PUT /admin/products/{id}` | W | „Podaj nazwę produktu.” | „Nazwa produktu zawiera niedozwolone znaki.” | „Podaj nazwę produktu.” |
| `shortDescription` | te same | O, U+000A dozwolony | zapisany pusty opis | „Krótki opis zawiera niedozwolone znaki.” | „Krótki opis musi być tekstem.” |
| `images[n].alt` | te same | W | „Podaj tekst alternatywny zdjęcia.” | „Tekst alternatywny zawiera niedozwolone znaki.” | „Podaj tekst alternatywny zdjęcia.” |
| `variantAxes[n]` | te same | W | „Nazwa osi musi mieć od 1 do 30 znaków.” | „Nazwa osi zawiera niedozwolone znaki.” | „Nazwa osi musi mieć od 1 do 30 znaków.” |
| `variants[n].values[m]` | te same | W | „Wartość musi mieć od 1 do 40 znaków.” | „Wartość wariantu zawiera niedozwolone znaki.” | „Wartość musi mieć od 1 do 40 znaków.” |
| `name` | `POST /admin/delivery-methods`, `PUT /admin/delivery-methods/{id}` | W | „Nazwa jest wymagana.” | „Nazwa zawiera niedozwolone znaki.” | „Nazwa jest wymagana.” |
| `description` | te same | O | zapisany pusty opis | „Opis zawiera niedozwolone znaki.” | „Opis musi być tekstem.” |
| `note` | `POST /admin/variants/{variantId}/stock/corrections` | O; W przy `reason: other`; U+0009, U+000A, U+000D dozwolone | przy `other`: „Przy powodzie „inne” notatka jest wymagana.”; poza tym brak notatki | „Notatka nie może zawierać znaków sterujących (poza tabulatorem i końcem wiersza).” | „Notatka musi być tekstem.” |
| `note` | `POST /admin/variants/{variantId}/stock/receipts` | O; te same znaki dozwolone | brak notatki | ten sam co wyżej | „Notatka musi być tekstem.” |
| `fullName` | `POST /orders`, `POST /me/orders` | W | „Imię i nazwisko są wymagane.” | „Imię i nazwisko zawierają niedozwolone znaki.” | „Imię i nazwisko są wymagane.” |
| `street` | te same | W | „Ulica i numer są wymagane.” | „Ulica i numer zawierają niedozwolone znaki.” | „Ulica i numer są wymagane.” |
| `city` | te same | W | „Miejscowość jest wymagana.” | „Miejscowość zawiera niedozwolone znaki.” | „Miejscowość jest wymagana.” |
| `postalCode` | te same | F | „Kod pocztowy jest wymagany.” | „Podaj kod pocztowy w formacie 00-000.” | ten sam co przy znaku |
| `phone` | te same | F | „Numer telefonu jest wymagany.” | „Podaj 9-cyfrowy numer telefonu.” | ten sam co przy znaku |
| `email` | `POST /orders` | F | „Adres e-mail jest wymagany.” | „Podaj poprawny adres e-mail.” | ten sam co przy znaku |
| `note` | `POST /admin/orders/{orderId}/status-changes` | O; W przy `cancel` i `return` | przy `cancel` i `return`: „Notatka jest wymagana.”; poza tym brak notatki | „Notatka zawiera niedozwolone znaki.” | „Notatka musi być tekstem.” |
| `note` | `POST /admin/orders/{orderId}/refund-completion` | O | brak notatki | „Notatka zawiera niedozwolone znaki.” | „Notatka musi być tekstem.” |
| `name` | `POST /admin/promotions`, `PUT /admin/promotions/{id}` | W | „Nazwa promocji musi mieć od 1 do 100 znaków, bez znaków sterujących.” | ten sam | „Nazwa promocji musi być tekstem.” |

Wszystkie komunikaty tabeli istnieją w kodzie; etap nie zmienia ich treści ani liczby naruszeń pola. Tekst złożony
wyłącznie ze znaków sterujących (np. sam U+009F) należy do obu klas, „pusty” (T3) i „niedozwolony” (T1): pola
zamówienia (`fullName`, `street`, `city`) odpowiadają wtedy komunikatem braku wartości, jak przed etapem, a pozostałe
pola komunikatem znaku niedozwolonego; w obu przypadkach to 422 przy polu. Tekst z brzegami
(T2) jest przyjęty i zapisany bez nich. Tekst do zachowania (T5) jest zapisany bez zmian, także nazwa promocji
zakończona flagą (#305).

### Pola z formatem: przypadki

Pole z formatem sprawdza znaki niedozwolone (T1) w wartości, jak przyszła, i odpowiada na nie komunikatem formatu;
potem jest przycinane według T2 i sprawdzane swoim formatem. W środku wartości nic nie jest usuwane poza tym, co pomija
sam format. Tabele podają wysłaną wartość, status i wartość zapisaną w zamówieniu albo komunikat
naruszenia przy polu. „Między X” znaczy: znak X przed wartością i za nią.

**`phone`** (`POST /orders`, `POST /me/orders`). Format: 9 cyfr, opcjonalnie po `+48`; spacje ASCII i łączniki `-`
są pomijane. Zamówienie zapisuje `+48` i 9 cyfr.

| Wysłane | Wynik |
|---|---|
| `600100200`; `+48600100200`; `600 100 200`; `600-100-200`; `+48 600-100-200` | 201, zapisane `+48600100200` |
| `600100200` między U+00A0; między U+200B; po U+FEFF; `+48 600 100 200` między U+00A0 | 201, zapisane `+48600100200` |
| U+00A0; U+200B; U+3164 | 422 „Numer telefonu jest wymagany.” |
| `600` U+00A0 `100` U+00A0 `200`; `600` U+200B `100200`; `600` U+009F `100200`; `600100200` i U+2028 na końcu | 422 „Podaj 9-cyfrowy numer telefonu.” |
| `60010020`; `6001002000`; `+49600100200` | 422 „Podaj 9-cyfrowy numer telefonu.” |

**`postalCode`** (`POST /orders`, `POST /me/orders`). Format: `NN-NNN`.

| Wysłane | Wynik |
|---|---|
| `00-950` | 201, zapisane `00-950` |
| `00-950` między spacjami ASCII; między U+00A0; między U+200B; po U+FEFF | 201, zapisane `00-950` |
| U+00A0; U+200B; U+3164 | 422 „Kod pocztowy jest wymagany.” |
| `00-` U+00A0 `950`; `00-` U+009F `950`; `00-950` i U+2028 na końcu; `00950`; `00 950`; `00` U+2013 `950`; `00-9500` | 422 „Podaj kod pocztowy w formacie 00-000.” |

**`email`** (`POST /orders`). Format: adres, który przyjmuje walidator i mailer, najwyżej 254 znaki po przycięciu.
Wielkość liter zostaje.

| Wysłane | Wynik |
|---|---|
| `anna.nowak@example.com`; `Anna.Nowak@Example.COM` | 201, zapisane bez zmian |
| `anna.nowak@example.com` między spacjami ASCII; między U+00A0; między U+200B; po U+FEFF | 201, zapisane `anna.nowak@example.com` |
| adres 254 znaków między U+00A0 | 201, zapisany sam adres |
| adres 255 znaków | 422 „Podaj poprawny adres e-mail.” |
| U+00A0; U+200B; U+3164 | 422 „Adres e-mail jest wymagany.” |
| `anna` U+200B `nowak@example.com`; `anna nowak@example.com`; `anna.nowak@` U+00A0 `example.com`; `anna` U+009F `nowak@example.com`; `anna.nowak@example.com` i U+2028 na końcu | 422 „Podaj poprawny adres e-mail.” |

### Frazy wyszukiwania (status 400, naruszenie przy `q`)

| Operacja | Fraza pusta (T3) | Znak niedozwolony (T1) |
|---|---|---|
| `GET /products` | „Ta wartość jest zbyt krótka. Powinna mieć 2 lub więcej znaków.” | „Szukana fraza zawiera niedozwolone znaki.” |
| `GET /admin/products`, `GET /admin/stock`, `GET /admin/prices` | ten sam | ten sam |
| `GET /admin/customers` | „Szukana fraza to fragment e-maila albo imienia i nazwiska, od 3 do 100 znaków bez znaków sterujących.” | ten sam |
| `GET /admin/orders` | „Szukana fraza to numer zamówienia albo tekst od 3 do 100 znaków bez znaków sterujących.” | ten sam |
| `GET /admin/discount-codes` | „Początek kodu musi mieć od 1 do 32 znaków.” | ten sam |

Długość frazy liczy się po przycięciu T2, a lista szuka frazy przyciętej: fraza między U+200B albo między U+00A0
zwraca ten sam wynik co fraza czysta (te same pozycje w tej samej kolejności i ten sam licznik). Dotyczy wszystkich
siedmiu list, także listy kodów rabatowych, gdzie fraza jest początkiem kodu. Żadna lista nie przycina frazy drugi raz
inną regułą: na liście kodów fraza z znakiem formatującym obok liter, który T2 zostawia (np. U+200D, U+200E), nie jest
początkiem żadnego kodu i daje pustą listę ze statusem 200, jak każda fraza ze znakiem spoza alfabetu kodu. Fraza, która
nie jest poprawnym UTF-8, i `q[]=x` zostają jak w etapie 1 (tabela B1).

### Pola tekstowe poza regułą

Lista wyjątków strażnika (`TextFieldExceptions`); każde pole ma własny kontrakt w specyfikacji modułu i nie zmienia się
w etapie. Ścieżki bez prefiksu `/api/v1`.

| Pole | Operacje | Powód |
|---|---|---|
| `slug` | `POST /admin/categories`, `PUT /admin/categories/{id}`, `POST /admin/products`, `PUT /admin/products/{id}` | zamknięty alfabet albo zamknięta lista wartości |
| `taxClass` | `POST /admin/products`, `PUT /admin/products/{id}` | zamknięty alfabet albo zamknięta lista wartości |
| `images[n].key` | `POST /admin/products`, `PUT /admin/products/{id}` | zamknięty alfabet albo zamknięta lista wartości |
| `code` (kod rabatowy) | `POST /admin/discount-codes` | format z własnym przycinaniem (`discount-codes`) |
| `trackingNumber` | `POST /admin/orders/{orderId}/status-changes`, `PUT /admin/orders/{orderId}/tracking-number` | format z własnym przycinaniem (`order-management`) |
| `trackingUrlTemplate` | `POST /admin/delivery-methods`, `PUT /admin/delivery-methods/{id}` | format z własnym przycinaniem (`delivery`) |
| `discountCode` (treść zamówienia) | `POST /orders`, `POST /me/orders` | tekst pusty to złożenie bez kodu, a zły kod jest odrzuceniem kodu, nie błędem wejścia |

Poza strażnikiem pól tekstowych są też parametry zapytań inne niż `q` (`path`, `option`, `chunk`, `after`,
`customerId`, `discountCode` koszyka): nie są polami treści, a pilnuje ich strażnik etapu 1 (tabele B1 i B2).

### Odczyt zapisanych wierszy (E7)

Repozytoria i handlery odczytu odtwarzają teksty z wiersza nazwanym konstruktorem `stored()`, który nie waliduje i nie
przycina: `DoctrineOrderRepository` (`Contact::stored`, `EmailAddress::stored`, `ShippingAddress::stored`),
`DoctrineProductRepository` (`ProductName`, `ShortDescription`, `ProductImage`, `VariantAxes`, `VariantValues`),
`DoctrineCategoryRepository` (`CategoryName`), `DoctrineDeliveryMethodRepository` (`DeliveryMethodName`,
`DeliveryMethodDescription`), handlery odczytu Catalog i odczyt generatora danych demo zamówień
(`VariantValues::stored`). Walidują i przycinają tylko konstruktory zapisu (`of()`, `fromList()`, `fromString()`,
`inPoland()`), których używają żądania, seedy i generator danych demo.

Skutek:

- Wiersz z tekstem, którego reguła nie przyjmuje (z NBSP zamiast nazwiska, z U+200B jako nazwą, z U+009F albo
  tabulatorem w nazwie), jest zwracany przez listy, szczegóły i sklep bez zmiany, a zamówienie przechodzi opłacenie,
  wygaszenie i zmianę statusu.
- Zapis produktu, kategorii albo metody dostawy z panelu wysyła wszystkie pola, więc niepoprawiony tekst daje 422 przy
  swoim polu. Poprawka tekstu przechodzi także tam, gdzie tekst jest chroniony blokadą tożsamości wariantu po
  publikacji (nazwy osi i wartości wariantów): blokada porównuje teksty po przycięciu T2, a tekst odrzucany przez
  regułę przyjmuje tylko swoją poprawkę, czyli ten sam tekst bez znaków niedozwolonych
  (`docs/features/catalog-admin/spec.md`, K5; decyzja właściciela z 2026-11-24).
- Pricing i Inventory nie wymagają zmiany: `Promotion::reconstitute` nie waliduje nazwy, notatka magazynu nie jest
  czytana przez value object, a reguła kodu rabatowego się nie zmienia.

### Rozwiązanie

1. **`App\Shared\Domain\Text`** (czysty PHP, bez stanu): `hasForbiddenCharacters($text, $allowed)` (T1, z listą znaków
   dozwolonych pola), `normalize()` (T2 i T3: tekst pusty wraca jako `''`) i `length()` (T4: długość po normalizacji).
   Wzorce używają właściwości `\p{Cc}`, `\p{Cf}`, `\p{Z}` i `\p{DI}` z flagą `/u`. `\p{DI}`
   (`Default_Ignorable_Code_Point`) działa w PCRE2 10.44 z obrazu `api` (PHP 8.5.12); sprawdzone w kontenerze na
   znakach z tabeli dowodów.
2. **Value objecty i reguły modułów** wołają `Text` zamiast własnych wzorców: Catalog (`ProductName`,
   `ShortDescription`, `ProductImage`, `VariantAxes`, `VariantValues`, `CategoryName`; `IdentityText` dla blokady
   tożsamości wariantu), Delivery (`DeliveryMethodName`, `DeliveryMethodDescription`), Inventory (`StockNote`), Ordering
   (`StaffNote`, `Contact`, `ShippingAddress`, `EmailAddress`, `OrderSearchTerm`, `BackofficeCustomerSearchTerm`),
   Pricing (`Promotion::validName`). Klasy pomocnicze modułów, które po zmianie tylko przekazywały wywołanie, zniknęły.
   `ControlCharacters` (Ordering) zostaje tylko dla identyfikatorów kont (`sub` tokenu w filtrze i kursorze), nie dla
   tekstów wpisywanych przez ludzi.
3. **Klasy żądań i zapytań** pokazują naruszenie przy polu z komunikatem z tabeli, zanim żądanie dojdzie do domeny:
   `PlaceGuestOrderRequest` i `PlaceCustomerOrderRequest` (ograniczenie `TextCharacters` i `Text::normalize` jako
   `normalizer`), `ChangeOrderStatusRequest`, `CompleteRefundRequest`, `CorrectStockRequest` i `ReceiveStockRequest`
   (`StockNoteRules`), `ValidProductWriteValidator`, zapytania z `q` (`ListProductsQuery`, `BackofficeProductsQuery`,
   `BackofficeStockQuery`, `BackofficePricesQuery`, `DiscountCodeListQuery`). Kategorie (`CategoryBodyRules`), metody
   dostawy (`DeliveryMethodBodyRules`) i promocje stosują reguły domeny, nie ich kopie. `BackofficeOrderListQuery` i
   `BackofficeCustomerListQuery` sprawdzają `q` przez `OrderSearchTerm` i `BackofficeCustomerSearchTerm`.
4. **Odczyt** według „Odczyt zapisanych wierszy”.
5. **Bez zmian:** kształt żądań i odpowiedzi, komunikaty, `propertyPath`, liczba naruszeń pola, odcisk żądania
   złożenia (skład pól), reguły `expectedLines`. Jeden skutek uboczny reguły fraz: na listach stanów i cen `q` puste
   według T3 razem z `productId` daje dwa naruszenia przy `q` („zbyt krótka” i konflikt filtrów), jak dotąd `q=x`
   z `productId` (#632 F3); przed etapem `q` z samych spacji dawało tam jedno.

### Wdrożenie i klienci

- Bez migracji, zmian compose i chartu. Sklep, panel, E2E, `load/` i `infra/k8s/smoke/` nie zmieniają się: żądania
  i odpowiedzi mają ten sam kształt, a komunikaty etapu już istnieją. Sklep i panel przycinają pola przez `trim()`
  JavaScript (zna białe znaki Unicode), więc nowe 422 i 400 dotyczą tekstów, których formularze dziś nie wysyłają
  w zwykłym użyciu, albo znaków niewidocznych; odpowiedź trafia do pola jak każde inne naruszenie.
- Opisy pól tekstowych w `openapi.json` i `openapi-admin.json` (także `q`) mówią o regule tekstu. Wzorce `pattern`
  nazwy metody dostawy i notatek (magazynu, zmiany statusu, wykonania zwrotu) zniknęły: reguły nie da się zapisać
  wzorcem, a sprawdza ją API. Zadanie, które zmienia klasę żądania albo zapytania, uruchamia `make -C apps/api openapi`
  i `make api-client`; różnica wchodzi do tego samego commita.
- **Skutek przy wdrożeniu.** Powtórzenie złożenia zamówienia z tym samym `Idempotency-Key` i treścią, wysłane po
  wdrożeniu dla żądania sprzed wdrożenia, w którym `fullName`, `street` albo `city` miały na brzegach biały znak inny
  niż ASCII albo znak z T2, dostaje 409 zamiast 200: odcisk liczy się z wartości po normalizacji. Powtórzenie żądania
  sprzed wdrożenia z tekstem, który reguła teraz odrzuca (U+009F w `fullName`, `street` albo `city`; takie pole
  z samego U+00A0 albo U+200B), dostaje 422 przy polu zamiast 200, bo walidacja żądania poprzedza sprawdzenie
  `Idempotency-Key`; zamówienie z pierwszego żądania zostaje. Tak samo powtórzenie przyjęcia stanu sprzed wdrożenia
  z notatką, którą reguła teraz odrzuca (U+0085, U+009F, U+2028 albo U+2029), dostaje 422 przy `note`; przyjęcie
  z pierwszego żądania zostaje i nie powstaje drugi ruch. Powtórzenie przyjęcia z notatką, która różni się od
  zapisanej tylko znakami przycinanymi przez T2, dalej daje 200. Skutki dotyczą tylko okna wdrożenia i takich
  tekstów; na etapie demo bez działań.
- Wiersze zapisane przed etapem zostają, jak są (E7); etap niczego nie przepisuje w bazie.

### Testy

**Test jednostkowy reguły** (`TextTest`, `tests/Unit/Shared/Domain`): pełna lista znaków. Każdy znak z T1 w środku, na
początku i na końcu tekstu; znaki dozwolone pól wielowierszowych; niepoprawny UTF-8 (samotny bajt kontynuacji, ucięta
sekwencja, same złe bajty); każdy znak z T2 na brzegach i w środku; teksty puste z T3 (pojedyncze znaki i mieszanki);
teksty z samego znaku Co i Cn, które nie są puste; teksty z T5; długość po normalizacji. Wartości są we wspólnej klasie
`InputValues` (`tests/Support/InputConformance`), z której korzysta też test kontraktu.

**Test kontraktu tekstów** (`TextContractTest`) w `apps/api/tests/Functional/Shared/InputContract`, obok tabeli etapu 1:

- **Wiersz** (`TextRow`, fabryki `required()`, `optional()`, `format()` i `phrase()`): operacja, rodzaj tokenu,
  fikstura (`InputContractFixtures`), pole (ścieżka w treści JSON albo `q`), rodzaj (W, O, F, fraza), znaki dozwolone
  pola, komunikaty z tabel tej sekcji i sposób odczytu zapisanej wartości (obowiązkowy dla W, O i F). Dwa wiersze tej
  samej operacji, pola i rodzaju (notatka zmiany statusu przy `cancel` i przy `return`) różnią się wariantem, częścią
  nazwy wiersza, więc i nazwy każdego jego wykonania jako testu. Wiersze są w plikach według modułu właściciela operacji
  (`CatalogTextRows`, `DeliveryTextRows`, `InventoryTextRows`, `OrderingTextRows`, `PricingTextRows`), a jedną listę
  składa `TextRows`: 43 wiersze (23 W, 8 O, 5 F i 7 fraz), 40 par operacji i pola. Mechanizm wykonań (`TextExecution`)
  sprawdza `TextRowTest`.
- **Wykonania wiersza, każde jako osobny test na stanie zbudowanym od zera:**

  | Klasa | Wartości | Oczekiwane |
  |---|---|---|
  | pusty | U+00A0; U+200B; U+3164 | W: 422 z komunikatem „Tekst pusty”; O: 2xx i zapisany brak wartości; fraza: 400 |
  | niedozwolony | U+0000, U+009F i U+2028 w środku tekstu; U+2028 na końcu tekstu | 422 (fraza: 400) z komunikatem „Znak niedozwolony”; znak na końcu nie może zniknąć przez przycięcie (T1: wartość, jak przyszła) |
  | dozwolony w polu | U+000A w krótkim opisie; U+0009, U+000A i U+000D w notatce magazynu | 2xx, zapisane bez zmian |
  | brzegi | tekst między U+00A0; tekst między U+200B | 2xx, zapisany sam tekst; F: poprawna wartość między U+00A0 jest przyjęta |
  | zachowany | tekst zakończony flagą Anglii; tekst z emoji z U+200D; tekst z polskimi znakami | 2xx, zapisane bez zmian; kontrola dodatnia wiersza |
  | zły typ | liczba; lista | 422 z komunikatem „Zły typ” przy polu |

  Każde 4xx ma dokładnie jedno naruszenie o podanym `propertyPath` i `title`. Fraza nie ma klasy „zły typ” (`q[]=x`
  jest w tabeli etapu 1) ani odczytu zapisanej wartości.
- **Wiersz pola z formatem** (`phone`, `postalCode`, `email`) wykonuje każdy przypadek tabel „Pola z formatem:
  przypadki” (wysłana wartość, status, zapisana wartość albo komunikat) w miejsce klas „niedozwolony”, „brzegi”
  i „zachowany”; klasa „zły typ” zostaje. Wykonanie „poprawna wartość i U+2028 na końcu” mechanizm dodaje każdemu
  wierszowi pola z formatem sam, żeby wiersz nie mógł go pominąć.
- **Wiersz frazy, klasa „brzegi”:** fikstura zakłada rekord, który fraza znajduje (produkt, klient z zamówieniem,
  zamówienie, kod rabatowy). Test wysyła frazę czystą, frazę między U+200B i frazę między U+00A0 i wymaga, żeby
  odpowiedź na frazę czystą zawierała ten rekord, a obie pozostałe odpowiedzi miały te same pozycje w tej samej
  kolejności i ten sam licznik. Lista sklepu szuka w indeksie testowym, pozostałe w bazie.
- **Strażnik zamkniętej listy pól tekstowych** (`ClosedTextFieldListTest`, logika w `ClosedTextFieldList`, wyjątki
  w `TextFieldExceptions`): każda właściwość typu `string` w treści żądań obu dokumentów OpenAPI (także zagnieżdżona,
  w elementach list i z `null`) i każdy parametr zapytania `q` ma wiersz albo wpis na liście „Pola tekstowe poza regułą”
  z powodem. Poza strażnikiem są tylko właściwości z `enum` i z formatem z zamkniętej listy: `uuid`, `date-time`,
  `date`, `binary`. Każdy inny format, w tym `email`, podlega strażnikowi, więc `POST /orders` `email` musi mieć wiersz.
  Dokumenty opisują 55 takich pól (48 tekstów treści i 7 fraz `q`): 40 ma wiersz, 15 jest na liście wyjątków. Kilka
  wierszy tej samej operacji i pola nie przeszkadza. Przerywają test (na kopiach danych, każda próba osobnym testem):
  pole bez wiersza i bez wpisu (bez formatu, z formatem spoza zamkniętej listy, w liście obiektów, parametr `q`
  nowej listy), wiersz pola, którego dokumenty nie opisują, wpis bez powodu (powód pusty według reguły tekstu, także
  złożony z samych znaków niewidocznych, np. U+200B albo U+3164), wpis dla pola, którego dokumenty nie
  opisują, i tabela bez wiersza `POST /orders` `email` (test usuwa ten wiersz z kopii tabeli). Strażnik nie generuje
  przypadków i nie zmienia tabeli.
- **Granice:** mechanizm nie mutuje innych pól, nie generuje wartości ze schematów i zawiera tylko przypadki tej
  sekcji.

**Odczyt zapisanych wierszy:** test na moduł (Catalog i Ordering: `StoredTextsOutsideTheRuleTest`; Delivery:
`DeliveryMethodsEndpointTest`) zapisuje rekord z tekstem, którego reguła nie przyjmuje, z pominięciem domeny, i sprawdza
odczyt przez API (lista, szczegóły, dla produktu także sklep), dla zamówienia także opłacenie webhookiem i wygaszenie,
a dla produktu 422 przy polu przy zapisie bez poprawki.

**Testy modułów:** istniejące testy jednostkowe value objectów i `ForbiddenCharactersInRequestsTest` zostają
i dostają przypadki, których zadanie dotyczy; przypadek, który sprawdzał stare zachowanie (np. przyjęcie U+200B),
zmienia się razem z regułą.

**TDD.** Każde zadanie najpierw dopisuje swoje testy i wiersze, uruchamia je i zapisuje wynik RED (które wykonania
i jaką odpowiedzią), potem wprowadza zmianę i zapisuje wynik GREEN. Wykonania klas „zachowany” i „zły typ” oraz część
klasy „pusty” przechodzą przed zmianą: są regresją. Strażnik pokazuje RED próbą obejścia (zmieniona kopia dokumentu,
tabeli albo listy wyjątków).

### Kryteria akceptacji

1. Każde pole z tabeli „Pola treści JSON” i każda fraza z tabeli „Frazy wyszukiwania” odpowiada na każdą klasę tekstu
   statusem, polem i komunikatem z tej sekcji; zapisane wartości klas „brzegi” i „zachowany” są takie, jak podaje
   tabela wykonań.
2. `POST /api/v1/orders` z `fullName`, `street` albo `city` równym U+00A0 albo U+200B to 422 przy polu. Każdy
   przypadek tabel „Pola z formatem: przypadki” daje podany status i zapisaną wartość albo komunikat, w `POST /orders`
   i (telefon, kod pocztowy) w `POST /me/orders`.
3. Notatka korekty stanu z powodem „inne” równa U+00A0 to 422 przy `note`; notatka z U+0085, U+2028, U+2029 albo
   U+009F to 422; notatka z tabulatorem i końcem wiersza jest zapisana bez zmian.
4. Nazwa promocji zakończona flagą Anglii jest zapisana bez zmian; nazwa kategorii równa U+200B, U+FEFF, U+00AD,
   U+2060, U+200E albo U+202E to 422.
5. `GET /api/v1/products?q=` z dwóch U+00A0 i z `a%00b` to 400 przy `q`. Na każdej z siedmiu list fraza między U+200B
   i fraza między U+00A0 zwraca dla przygotowanego rekordu ten sam wynik co fraza czysta, także na liście kodów
   rabatowych.
6. Wiersz zapisany z tekstem spoza reguły daje się odczytać i przetworzyć według „Odczyt zapisanych wierszy”.
7. Strażnik zamkniętej listy pól tekstowych przechodzi na obu dokumentach OpenAPI, obejmuje `POST /orders` `email`
   mimo `format: email` i przerywa test w próbach obejścia (nowe pole tekstowe bez wiersza i bez wpisu, wiersz pola,
   którego dokumenty nie opisują, wpis bez powodu), w tym po usunięciu wiersza tego pola.
8. Żaden komunikat, `propertyPath` ani kształt żądania i odpowiedzi nie zmienia się poza tabelami tej sekcji;
   `make check` i `make test` przechodzą, a pliki OpenAPI i klienci TS są aktualne.
9. Deptrac przechodzi bez zmiany `deptrac.yaml` (warstwy Domain mają już dostęp do `Shared_Domain`).

### Dokumentacja razem z implementacją

- `apps/api/CLAUDE.md`: w „Architektura” zdanie o `Shared\Domain\Text` jako jedynym miejscu reguły tekstu (value
  objecty zostają własne); w „API” zasada, że nowe pole tekstowe treści żądania albo parametr `q` dostaje wiersz
  w tabeli testu kontraktu tekstów albo wpis z powodem na liście wyjątków; w „Architektura” zasada, że odczyt z bazy
  nie waliduje tekstów.
- Specyfikacje modułów: zdania, które po zmianie są nieprawdziwe (`checkout` o przycinaniu `trim()`, `quality-fixes`
  o kopiach wzorców w modułach, `catalog-admin`, `search`, `admin-features`, `delivery`, `inventory`,
  `order-management`, `promotions` o przycinaniu i znakach), w zadaniu, które zmienia dane zachowanie.
- Ta sekcja opisuje stan obecny; zmiana reguły albo tabeli tekstów aktualizuje ją w tym samym commicie co kod.

### Poza etapem 2

- Lista albo mapa wysłana jako obiekt JSON o kluczach „0”…„n” jest przyjmowana jak lista (#903). Odtworzone szerzej,
  niż opisuje zgłoszenie: `expectedLines` zamówienia, `variantIds` promocji i wszystkie listy produktu dają 201.
  Skutek jest taki sam jak dla listy; naprawa dotyczy dekodowania treści, nie reguły tekstu.
- Znaki kierunku tekstu (U+202A–U+202E, U+2066–U+2069) w środku tekstu zostają; nie zaobserwowano skutku w sklepie
  ani w panelu.
- Znaki, które Unicode klasyfikuje jako widoczne, a czcionki rysują pusto (np. U+2800).
- Pozostałe wejścia z #644 (`category`, `parentId`, `{id}` kategorii dla nil UUID), dwa różne naruszenia `q` przy
  `productId` (#632 F3), osobne `type` konfliktów korekty stanu (#583), angielskie `detail` pozostałych wyjątków
  projektu, fala 2 z #779 (#642, #643, #662, #702, #760).
- Niezgodność przycinania między frontem a API (#198, #336) i walidacja pustych pól w sklepie (#345): po tym etapie
  o tym, co jest puste, rozstrzyga reguła T3.

### Kolizje z innymi epikami

Stan na 2026-11-24: oba epiki są na `main`, nie ma otwartych PR.

- **Spójność koszyka i zamówienia (#894, `8fbb88ad`).** Dodał wymagane `expectedLines` do `PlaceGuestOrderRequest`
  i `PlaceCustomerOrderRequest`, typ 409 `cart-changed`, pole w odcisku żądania i zmienił fikstury testu kontraktu
  (`InputContractFixtures`, `OrderingCases`). Etap 2 zmienia w tych samych klasach tylko reguły pól tekstowych; nie
  zmienia `expectedLines`, składu odcisku ani `openapi.json` poza opisami. Skrypt próby etapu 1 nie zna
  `expectedLines`, więc jego żądania złożenia kończą się 422 przy tym polu; próba etapu 2 ma własny skrypt. Otwarte
  zgłoszenia po tamtym epiku (#898–#903) nie dotyczą pól tekstowych; #903 jest w „Poza etapem 2”.
- **ADMIN-UX-FIXES etap 2 (#896, `07be3bed`).** Zmienił tylko `apps/admin`, E2E i specyfikacje; formularze panelu
  czytają `violations[].propertyPath` i blokują pola na czas zapisu. Etap 2 nie zmienia `propertyPath`, liczby
  naruszeń ani `detail`, których te formularze i ich testy używają; `schema.d.ts` obu aplikacji zmieniły się tylko
  o opisy pól. #897 dotyczy samego panelu.
- **STORE-UX-FIXES.** Pozostała część #345 czeka na ten etap („o tym, które pole jest puste, rozstrzyga API”); etap
  jej nie realizuje.

### Ryzyka i założenia

- **Czas CI.** Test kontraktu tekstów to 616 wykonań funkcjonalnych (43 wiersze), lokalnie około 5,5 s (około 9 ms na
  test; 396 testów kontraktu etapu 1 trwa 3,3 s), test jednostkowy `Text` to 321 przypadków; wykonania z fiksturą
  zamówienia są droższe niż średnia. Część CI `checks part api functional` trwała 176 s, najdłuższa część `checks`
  718 s, najdłuższa część E2E 883 s (przebieg `main` dla `07be3bed`, przed etapem). Etap nie dodaje testów E2E, usług ani
  części macierzy. Wzrost czasu tej jednej części nie wydłuża przebiegu, dopóki jest krótsza od najdłuższej; faktyczny
  czas pokaże `ci-time` po epiku.
- **Zależność od PCRE.** Reguła wymaga `\p{DI}`; test jednostkowy przerywa się na obrazie, który jej nie zna.
- **Generator danych demo** używa value objectów Catalog i Ordering; słownik z tekstem spoza reguły przerwie jego
  testy (`demo-data`, `demo-data-orders`) i wymaga poprawki słownika w tym samym zadaniu.
- **Unikalność po normalizacji.** Nazwa metody dostawy zapisana przed etapem z U+200B na brzegu i ta sama nazwa
  wpisana po etapie są różnymi tekstami; etap tego nie wyrównuje.
- Podział plików wierszy według modułu jest taki jak w etapie 1.
