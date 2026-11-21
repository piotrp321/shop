# API-INPUT-CONTRACT, etap 1: odpowiedź na błędne wejście poza treścią JSON

Epik #779 ma sprawić, że złe dane wejściowe dają zawsze 4xx z polem naruszenia i polskim komunikatem, a pilnuje tego
wspólny test. Etap 1 obejmuje wejścia poza polami treści JSON: odpowiedzi ogólne frameworka, parametry zapytań,
nagłówek `Idempotency-Key` i `detail` trzech wyjątków. Kolejne etapy są w sekcji „Poza etapem 1”.

Issues: #779 (epik), #761, #441, część #413 (polskie komunikaty frameworka), część #583 (polskie `detail` konfliktów
wersji), część #644 (identyczne naruszenia `productId` list panelu).
Zakres: `apps/api/src/Shared`, `apps/api/src/Catalog`, `apps/api/src/Cart`, `apps/api/src/Inventory`,
`apps/api/src/Ordering`, `apps/api/src/Payment`, `apps/api/src/Pricing`, `apps/api/config`, `apps/api/translations`,
`apps/api/composer.json`, `apps/api/composer.lock`, `apps/api/symfony.lock`, `apps/api/tests`, `apps/api/CLAUDE.md`,
`docs/features/api-input-contract`.

**Dowody.** Stan sprzed etapu opisuje raport `docs/reports/api-input-contract-probe-2026-11-21.md` (rewizja
`e250032a`): 6051 błędnych żądań do 72 operacji, 0 odpowiedzi 5xx, 30 różnych tekstów po angielsku, reprodukcje
pojedyncze i sprawdzenie tłumaczeń frameworka. Zero 5xx to wynik tej próby, nie gwarancja całego API.

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

Liczby operacji dotyczą rewizji `e250032a`: 72 operacje (30 w `openapi.json`, 42 w `openapi-admin.json`),
63 parametry zapytań na 19 operacjach, `Idempotency-Key` na 5 operacjach. Ścieżki niżej są bez prefiksu `/api/v1`.
Kryteria dotyczą `detail` i `violations[].title`; `title` problemu (fraza statusu HTTP) nie jest częścią kontraktu
języka.

### A. Odpowiedzi ogólne (`detail`, bez `violations`)

| Przypadek | Operacje | Status | `detail` |
|---|---|---|---|
| Metoda spoza trasy | wszystkie | 405 | „Metoda nie jest dozwolona.” |
| Brak tokenu | wszystkie wymagające tokenu (`/admin/...`, `/me/...`) | 401 | „Wymagane jest uwierzytelnienie.” |
| Identyfikator w ścieżce w formacie, którego trasa nie przyjmuje | operacje z parametrem ścieżki, które dają ogólne 404 (38) | 404 | „Nie znaleziono zasobu.” |
| `Content-Type` inny niż JSON, poprawny token | operacje z treścią JSON poza webhookiem (29) | 415 | „Nieobsługiwany typ treści.” |
| Zepsuty JSON, niepoprawny UTF-8 w treści, zagnieżdżenie ponad limit dekodera | operacje z treścią JSON (29) i webhook | 400 | „Żądanie jest nieprawidłowe.” |

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

**B1. Parametry, których odpowiedź się zmienia**

| Parametr | Operacje | Przypadek → komunikat |
|---|---|---|
| `category` | `GET /products`, `GET /admin/products`, `GET /admin/stock`, `GET /admin/prices` | `category[]=x` → T; pusty → pusta; `abc` → UUID |
| `q` | te same cztery | `q[]=x` → T; 1 znak → krótka(2); 101 znaków → długa(100); niepoprawny UTF-8 → znaki; na trzech listach panelu `ab%00` → „Szukana fraza zawiera niedozwolone znaki.” |
| `priceMin`, `priceMax` | `GET /products` | `[]` → T; pusty → pusta; `abc`, `-1`, `1.5` → „Cena musi być liczbą całkowitą groszy, 0 albo więcej.”; `priceMin` większe niż `priceMax` → przy `priceMin` „Cena minimalna nie może być wyższa niż maksymalna.” |
| `available` | `GET /products` | `[]` → T; `false` → opcja |
| `sort` | `GET /products` | `[]` → T; nieznana wartość → opcja; `relevance` bez `q` → „To sortowanie wymaga szukanej frazy.” |
| `page` | `GET /products` | `abc`, `1.5`, pusty, `[]` → T; `0`, `-1` → „Ta wartość powinna być dodatnia.” |
| `perPage` | `GET /products` (max 48), `GET /me/orders` (50), `GET /admin/products`, `GET /admin/stock`, `GET /admin/orders`, `GET /admin/prices`, `GET /admin/variants/{variantId}/stock-history`, `GET /admin/variants/{variantId}/price-history` (100) | `abc`, `[]` → T; `0`, max+1 → zakres(max) |
| `perPage`, `status`, `after` | `GET /admin/promotions` | `[]` (dla `perPage` także `abc`) → T; pozostałe przypadki mają już polskie komunikaty i się nie zmieniają |
| `option` | `GET /products` | `option[]=x` → „Podaj opcje jako powtórzony klucz option, nie option[].”; bez dwukropka → „Opcja musi mieć postać oś:wartość.”; niepoprawny UTF-8 → „Opcja zawiera niedozwolone znaki.”; oś ponad 30 albo wartość ponad 40 znaków → „Oś opcji ma od 1 do 30 znaków, a wartość od 1 do 40.”; 51 opcji → „Można podać najwyżej 50 opcji.”; opcje 6 osi → „Można podać opcje najwyżej 5 różnych osi.” |
| `status` | `GET /admin/products` | `[]` → T; nieznany → „Status produktu musi być jednym z: draft, published.” |
| `status` | `GET /admin/orders` | `[]` → T; nieznany → „Status zamówienia jest nieprawidłowy.” |
| `after` | `GET /me/orders`, `GET /admin/products`, `GET /admin/stock`, `GET /admin/orders`, `GET /admin/prices`, `GET /admin/variants/{variantId}/stock-history`, `GET /admin/variants/{variantId}/price-history` | `[]` → T; pusty → krótka(1); 2049 znaków → długa(2048); niepoprawny UTF-8 → znaki; `abc` → „Kursor stronicowania jest nieprawidłowy.” |
| `productId` | `GET /product-offers` | brak parametru → przy `productId` „Ten zbiór powinien zawierać 1 lub więcej elementów.”; pusty element → przy `productId[0]` pusta; `abc` → przy `productId[0]` UUID |
| `version` | `DELETE /admin/promotions/{id}` | `version[]=x` → naruszenie `version` „Wersja musi być dodatnią liczbą całkowitą.” |
| `productId` | `GET /admin/stock`, `GET /admin/prices` | nil UUID → jedno naruszenie „Identyfikator produktu musi być poprawnym UUID.” |

**B2. Parametry z komunikatem bez zmian.** Test obejmuje je przypadkiem złego rodzaju (`p[]=x`), z dokładnym tekstem.

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
2. **Teksty własne.** Komunikaty z tabel A–D są przepisane tam, gdzie są dziś: `ProblemDetailsSubscriber`, klasy
   zapytań (`ListProductsQuery`, `BackofficeProductsQuery`, `BackofficeStockQuery`, `BackofficePricesQuery`,
   `BackofficeOrderListQuery`), `OptionFilter`, wyjątki kursorów i wyszukiwania list, trzy miejsca odczytu nagłówka
   (`OrderPlacement`, `PaymentEndpoints`, `ReceiveStockController`), `InvalidReceiptKey`, `StockModifiedConcurrently`,
   `PriceModifiedConcurrently`, `ProductNotFound`.
3. **Identyczne duplikaty.** `ProblemDetailsSubscriber` pomija naruszenie o tym samym `propertyPath` i `title` co
   wcześniejsze w tej odpowiedzi. Różne naruszenia jednego pola zostają.
4. **`version` promocji.** `DeletePromotionController` czyta parametr tak, żeby tablica dała naruszenie `version`,
   nie wyjątek frameworka.
5. **Strażnik klas żądań (#441).** Test przechodzi po klasach z `#[RequiredKeysBody]` znalezionych w kontrolerach
   z routera i wymaga parametrów konstruktora bez typu albo `mixed`.

## Zmiany zachowania API

| Zmiana | Przed | Po |
|---|---|---|
| Teksty `detail` i `violations[].title` z sekcji A–D | angielskie | polskie; status i `propertyPath` bez zmian |
| `DELETE /admin/promotions/{id}?version[]=x` | 400 z `detail`, bez `violations` | 400 z naruszeniem `version`, bez `detail` |
| Identyczne naruszenia (to samo pole i ten sam tekst) w odpowiedzi 400 albo 422 | powtórzone | jedno; znany przypadek: `productId` list stanów i cen |
| Niepoprawny `Idempotency-Key` przyjęcia magazynowego | własny angielski tekst z listą znaków | „Nieprawidłowy klucz żądania.”, jak w zamówieniach i płatnościach |

Statusy HTTP się nie zmieniają.

**Bez zmian:** schematy `openapi.json` i `openapi-admin.json` (opis 400 usuwania promocji już mówi o `violations`),
typy klientów TS, sklep, panel i E2E: nie odwołują się do zmienianych tekstów ani do liczby naruszeń. Zadanie, które
zmienia klasę zapytania albo kontroler, uruchamia `make -C apps/api openapi` i `make api-client`; różnica, jeśli
wystąpi, wchodzi do tego samego commita.

**Wdrożenie.** Zmiana tekstów i kodu API, bez migracji, bez zmian compose i chartu.

## Testy

### Test kontraktu wejścia

Jedna klasa testu funkcjonalnego w `apps/api/tests/Functional/Shared/InputContract` z tabelą przypadków, która
odwzorowuje tabele B1, B2 i C wiersz po wierszu. Tabela jest podzielona na pliki według modułu właściciela operacji.

- **Wiersz tabeli:** operacja, rodzaj tokenu, nazwa fikstury, badane wejście, wartość poprawna, wartość błędna,
  oczekiwany zbiór par (`propertyPath`, `title`).
- **Dwa wykonania na wiersz, każde jako osobny test:** żądanie z wartością poprawną daje 2xx; żądanie z wartością
  błędną daje 400 z dokładnie oczekiwanym zbiorem naruszeń. Każde wykonanie buduje stan tą samą fiksturą od zera
  (transakcja testu jest wycofywana po każdym teście, `DAMA\DoctrineTestBundle`), a klucz idempotencji jest nowy
  w każdym wykonaniu. Kontrola dodatnia nie usuwa więc zasobu, nie zmienia wersji i nie zużywa klucza przed próbą
  błędną, a 400 próby błędnej dotyczy badanego wejścia, nie stanu po wcześniejszej operacji.
- **Fikstury:** wyłącznie istniejące: `TestTokenFactory` (pracownik, klient), fabryki Foundry (`ProductFactory`,
  `CategoryFactory`, `DiscountCodeFactory`), `CartFixture`, `OrderingFixture`, `StockFixture` i pomocniki
  `OrderEndpointTestTrait`, `PaymentEndpointTestTrait`, `StockEndpointTestTrait`.
- **Granice mechanizmu:** nie generuje wartości ze schematów, nie mutuje treści JSON i nie jest biblioteką dla innych
  testów; zawiera tylko przypadki z tej specyfikacji.
- **Router i OpenAPI** służą do list operacji przypadków 405 i 401 (wzorem `AdminRouteAccessTest`) i do strażnika
  zamkniętej listy: parametr zapytania albo nagłówek `Idempotency-Key` opisany w OpenAPI bez wiersza w tabeli przerywa
  test, tak samo trasa `/api/v1` bez wpisu w OpenAPI.
- **Sekcja A:** 404, 415 i 400 dla operacji z tabeli A, z poprawnym tokenem i istniejącym zasobem tam, gdzie błąd ma
  wystąpić za autoryzacją.

### Pozostałe testy

- Test jednostkowy `ProblemDetailsSubscriber`: wszystkie stałe teksty, pominięcie identycznego duplikatu, zachowanie
  dwóch różnych naruszeń jednego pola.
- Strażnik klas żądań z próbą obejścia: klasa testowa z parametrem `int` go łamie.
- Sekcja D w istniejących klasach testów modułów: 409 korekty stanu, 409 zmiany ceny, 404 produktu, z dokładnym
  `detail`.
- Istniejące asercje angielskich tekstów (około 30 linii w 19 plikach) zmieniają się razem z tekstami.

### TDD

Każde zadanie najpierw dopisuje swoje wiersze tabeli i testy, uruchamia je i zapisuje wynik RED, potem wprowadza
zmianę i zapisuje wynik GREEN. Dotyczy to także tłumaczeń. Strażnik klas żądań i strażnik zamkniętej listy pilnują
stanu, który jest już zgodny albo staje się zgodny po zadaniach epiku: ich RED pokazuje próba obejścia w teście.

## Dokumentacja razem z implementacją

- `apps/api/CLAUDE.md`, sekcja „API”: domyślny język komunikatów walidatora (`pl`, katalog frameworka, nadpisany błąd
  typu) i zasada, że nowy parametr zapytania albo nagłówek żądania dostaje wiersz w tabeli testu kontraktu.
- Ta specyfikacja po zakończeniu epiku opisuje stan obecny (bez odwołań do stanu sprzed etapu).

## Poza etapem 1

- Reguły pól treści JSON (422) i przypadki per pole (#644).
- Treść tekstów: same białe i niewidoczne znaki (#305, #443, #641, #632 F4, `q` list panelu, nazwa metody dostawy).
  `q` z samych NBSP dalej daje 200.
- #632 F3: dwa różne naruszenia `q` przy `productId` zostają, oba po polsku.
- Pozostałe angielskie `detail` wyjątków projektu (około 77 komunikatów w klasach wyjątków).
- Osobne `type` problemów obu konfliktów korekty stanu i zmiana panelu (#583).
- `title` problemu, opisy OpenAPI i komunikaty komend konsoli zostają po angielsku.
- Upload zdjęcia, weryfikacja podpisu webhooka, `Order-Access-Token` (zły token to celowo 404), `page` ponad zakres
  liczby całkowitej.
- #440 (odczyt wierszy zapisanych przed ostrzejszymi regułami) i fala 2 z #779 (#642, #643, #662, #702, #760).

## Ryzyka i założenia

- Teksty katalogu frameworka mogą się zmienić przy aktualizacji Symfony; test kontraktu to wykryje i oczekiwania
  trzeba wtedy poprawić.
- Przepis Fleksa dla `symfony/translation` dopisuje pliki konfiguracji; zadanie zostawia tylko potrzebne.
- Pomijanie identycznych duplikatów może zmienić liczbę naruszeń w istniejących asercjach; pokaże to pełny przebieg
  testów zadania.
- Tabela przypadków jest w jednym katalogu i sięga po fikstury kilku modułów; alternatywa (przypadki w testach
  modułów) wymagałaby osobnego rejestru dla strażnika zamkniętej listy.
- Założenia techniczne: `title` problemu zostaje frazą statusu HTTP; błąd typu ma jeden ogólny tekst bez nazwy typu.
