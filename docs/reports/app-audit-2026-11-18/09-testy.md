# 09 – Testy backendu, E2E i obciążeniowe

Audyt statyczny migawki `main` (`ef204620`). Ścieżki są względne wobec katalogu eksportu.

## Zakres i metoda

Przeczytane (w całości albo w istotnej części) i przeszukane:

- `apps/api/tests`: 464 pliki `*Test.php` (Unit 233, Integration 163, Functional 68) plus 126 plików pomocniczych
  (fabryki, fixtury, adaptery in-memory, uprzęże współbieżności). Policzone skryptem tekstowym: 3729 metod testowych
  (Unit 1910, Integration 958, Functional 861) i 576 dostawców danych. Kod testów to ok. 98 tys. linii przy 61 tys.
  linii `src`.
- Próbka czytana ręcznie: 47 plików testów API z każdego modułu i poziomu, m.in. `PlaceOrderConcurrencyTest`,
  `WebhookConcurrencyTest`, `WebhookFlowTest`, `WebhookEndpointTest`, `PlaceOrderTest`, `PlaceOrderHandlerTest`,
  `HandleProviderWebhookHandlerTest`, `BasketQuoteTest`, `MoneyTest`, `PromotionTest`, `StockItemTest`,
  `ReservationConcurrencyTest`, `DiscountCodePlacementConcurrencyTest`, `OutboxRelayTest`, `EventFlowTest`,
  `AdminRouteAccessTest`, `GuestOrderEndpointTest`, `CustomerOrderHistoryEndpointTest`, `CustomerPaymentEndpointTest`,
  `GuestPaymentEndpointTest`, `OrderSearchPlanTest`, `OrderingSchemaTest`, `CartLinesByVariantMigrationTest`,
  `ProbesTest`, `DashboardSalesEndpointTest`, uprzęże `ConcurrentCommands` i `ParallelProcesses`, cechy
  `OrderEndpointTestTrait` i `CartEndpointTestTrait`. Dla pozostałych plików: listy nazw testów (wszystkie testy
  współbieżności, komunikatów, zamówień, płatności, koszyka, cen, kodów, magazynu) i przeszukiwanie wzorców.
- `e2e`: konfiguracja Playwrighta, `entrypoint.sh`, `CLAUDE.md`, 9 specyfikacji w całości albo w dużej części
  (`checkout-guest`, `checkout-customer`, `payment-resume`, `order-management`, `promotions`, `product-page-freshness`,
  fragmenty `admin-design`, `admin-screens-discount-codes`), 7 plików `support/`, tytuły testów 13 kolejnych
  specyfikacji, wzorce stabilności we wszystkich 36.
- `load`: wszystkie skrypty i `lib/`, `README.md`, nagłówek `infra/k8s/scripts/load.sh`, sekcja testów obciążeniowych
  `docs/features/hardening/spec.md`, „Wymagania niefunkcjonalne” `docs/vision.md`.
- CI: `.github/workflows/ci.yml` w całości, `apps/api/phpunit.dist.xml`, `apps/api/Makefile`, `make/*.mk`,
  `apps/api/composer.json`, `deptrac.yaml`, `phpstan.dist.neon`, `config/packages/test/*`, sekcja `when@test`
  w `config/services.yaml`, sekcja „Testy” w `apps/api/CLAUDE.md`.

Heurystyki liczone skryptem (tylko odczyt tekstu): testy bez asercji, testy z asercją wyłącznie na kodzie HTTP, użycie
mocków, `sleep`, zegara systemowego, losowości, surowych `INSERT`, globalnych `COUNT(*)`.

## Ocena ogólna obszaru: 8,5 / 10

To jest zestaw testów znacznie powyżej tego, co zwykle widać w projektach tej wielkości, i ocenę obniżają głównie
rzeczy z poziomu „meta” (brak pomiaru, brak bramek wydajności), a nie jakość samych testów.

Co uzasadnia wysoką ocenę:

- Piramida ma zdrowy kształt: 51% metod to testy jednostkowe na adapterach in-memory, 26% integracyjne na prawdziwym
  PostgreSQL, Elasticsearch, RabbitMQ, Redisie, Garage, Keycloaku i Mailpicie, 23% funkcjonalne przez HTTP, do tego
  350 testów Playwrighta na pełnym stosie.
- Mocków prawie nie ma: `createMock`/`createStub` występuje w 13 z 464 plików, zawsze na granicy infrastruktury
  (dekorator cache, klient HTTP, połączenie w ścieżce błędu). Własne klasy domenowe nie są mockowane nigdzie.
- Współbieżność, idempotencja i awarie są testowane naprawdę: 17 plików testów forkuje procesy z osobnymi połączeniami
  i startem na barierze z blokady doradczej (kolejne testy blokad używają dwóch połączeń w jednym procesie), awarie w połowie transakcji wymusza trigger PostgreSQL, a powtórne
  dostarczenie wiadomości i webhooka ma test na każdym poziomie.
- Asercje dotyczą wartości biznesowych. Ze 3729 metod tylko 39 (1%) sprawdza wyłącznie kod HTTP i w każdym z tych
  przypadków kod jest przedmiotem testu (uprawnienia, wartości graniczne). Nie ma ani jednego testu bez asercji.
- `phpunit.dist.xml` kończy błędem przy deprecjacji, ostrzeżeniu i notice, PHPStan na poziomie `max` obejmuje także
  `tests`, bez baseline.

Co obniża ocenę: nikt nie mierzy pokrycia ani siły asercji (brak coverage i testów mutacyjnych), testy obciążeniowe
i Lighthouse niczego nie egzekwują w zakresie wydajności, zgodność odpowiedzi z OpenAPI jest sprawdzana tylko na
poziomie nazw kluczy, a kilka testów zależy od czasu zegarowego z wąskim marginesem.

Nie znalazłem żadnego znaleziska klasy Critical ani High.

## Piramida i poziomy

| Poziom | Pliki testów | Metody | Na czym działa |
|---|---|---|---|
| Unit | 233 | 1910 | czysty PHP, adaptery in-memory i stałe zegary; jeden wyjątek z kernelem (`MessengerRoutingTest`) |
| Integration | 163 | 958 | 148 klas na `KernelTestCase`/`MessagingKernelTestCase`, baza `shop_test` w transakcji DAMA albo z commitem (współbieżność) |
| Functional | 68 | 861 | `WebTestCase`, prawdziwa baza, tokeny RS256 podpisane kluczem generowanym na przebieg (`tests/bootstrap.php:16-19`) |
| E2E | 36 specyfikacji | 350 (według `e2e/CLAUDE.md`) | pełny stos, build produkcyjny sklepu w CI, 3 części |
| Obciążeniowe | 4 skrypty k6 | – | `load-smoke` w CI, pomiar ręcznie na k3d |

Rozkład na moduły jest proporcjonalny do rozmiaru kodu (np. Ordering: 11,5 tys. linii, 324 + 193 + 162 testy;
Pricing: 13,2 tys. linii, 451 + 117 + 122). Jedyny moduł bez testów jednostkowych to `Customer` (5 plików, 196 linii),
pokryty 11 testami funkcjonalnymi.

## Pokrycie scenariuszy biznesowych

| Obszar | Pokryte (przykładowy dowód) | Czego brakuje |
|---|---|---|
| Checkout | Pełne złożenie gościa i klienta, kopie cen i nazw, 409 dla każdej przyczyny, ignorowanie cen z treści żądania, walidacja każdego pola (`GuestOrderEndpointTest`, `CustomerOrderEndpointTest`, `PlaceOrderHandlerTest`); atomowość przy awarii zapisu zamówienia, outboxa i wiadomości wygaśnięcia (`PlaceOrderTest.php:121,136,151`) | E2E dla ścieżek błędu w kasie: brak towaru, zmiana kwoty, 429 |
| Płatności i webhooki | Podpis (brak, zły, cudzy, zmieniona treść, stary znacznik czasu), zła kwota, nieznany dostawca i płatność, powtórka, konflikt wersji jako 503 z pełnym rollbackiem (`WebhookEndpointTest.php:76-183`); płatność przed terminem odebrana po nim, po terminie, dla anulowanego zamówienia (`WebhookFlowTest.php:97-140`) | Znacznik czasu podpisu z przyszłości; waluta inna niż PLN tylko na poziomie domeny (`OrderPaymentTest.php:146`) |
| Anulowanie i zwrot | Przejścia agregatu, role dla każdego przejścia, notatka, stara wersja, zwrot do wykonania i jego zakończenie (`ChangeOrderStatusEndpointTest`, `CompleteRefundEndpointTest`, `OrderLifecycleTest`); wyścig wysyłki z anulowaniem (`ChangeOrderStatusConcurrencyTest.php:79`) | E2E dostarczenia i zwrotu w panelu (jest wysyłka i anulowanie ze zwrotem pieniędzy) |
| Rezerwacje i nadsprzedaż | 20 rezerwacji na 7 sztuk, przeciwna kolejność wariantów bez zakleszczenia, ta sama rezerwacja 10 razy, zwolnienie kontra zatwierdzenie (`ReservationConcurrencyTest.php:40-109`); 10 gości na 3 ostatnie sztuki (`PlaceOrderConcurrencyTest.php:79`); korekta w dół równolegle z rezerwacją (`StockChangeConcurrencyTest.php:111`) | – |
| Limity kodów rabatowych | Ostatnie wolne użycie przy dwóch równoległych zamówieniach, wyłączenie kodu w trakcie, brak zakleszczenia z wygaśnięciem (`DiscountCodePlacementConcurrencyTest.php:84-165`); zwolnienie użycia po wygaśnięciu zamówienia (`DiscountCodeOrderEndpointTest.php:171`) | Limit na klienta nie istnieje w produkcie, więc nie ma czego testować |
| Łączenie promocji z kodem | Kod 10% liczony od cen po promocji 20%, z kwotami i kopią promocji na linii (`DiscountCodeOrderEndpointTest.php:212-232`), zapis i odczyt takiego zamówienia (`DoctrineOrderRepositoryTest.php:98`); nakładanie się promocji przy równoległym zapisie (`PromotionConcurrencyTest.php:60`) | Test jednostkowy alokacji kodu na linii promocyjnej: `BasketQuoteTest.php:211` sprawdza promocję bez kodu, połączenie pokrywa tylko test funkcjonalny |
| Zaokrąglenia | Alokacja rabatu metodą największych reszt z wartościami policzonymi niezależnie, remisy, linia za 1 grosz, koszyk maksymalny poza zakresem 64 bitów (`BasketQuoteTest.php:46-209`) | – |
| Łączenie koszyków | Sumowanie do 99, limit 50 linii, wygasłe i scalone koszyki (`CartMergeTest`, 19 testów); 8 scenariuszy równoległych K1–K8 (`CartConcurrencyTest`) | – |
| Autoryzacja i IDOR | Każda trasa panelu z routera bez roli, z tokenem klienta i bez tokena (`AdminRouteAccessTest.php:70-131`); cudze zamówienie, zamówienie gościa, nieznane i nie-UUID dają tę samą treść 404 (`CustomerOrderHistoryEndpointTest.php:205-218`); płatność cudzego zamówienia (`CustomerPaymentEndpointTest.php:59-74`, `GuestPaymentEndpointTest.php:109-125`) | Macierz ról nie jest wymuszana dla nowych tras (znalezisko 8) |
| Walidacja wejścia | Naruszenia po polsku dla każdego pola, białe znaki Unicode, najdłuższe wartości, zły JSON, zły typ treści (`OrderRequestViolationCases`, `CartRequestViolationCases`, `Support/InputConformance`) | – |

Odpowiedź na pytania szczegółowe:

- **Prawdziwe testy współbieżności**: tak. `ConcurrentCommands` i `ParallelProcesses` forkują uczestników
  (`pcntl_fork`), każdy uruchamia własny kernel i połączenie, wszyscy startują po zwolnieniu blokady doradczej
  (`ParallelProcesses.php:52-72,122`). Każdy scenariusz ma 3–5 rund i sprawdza niezmiennik w bazie (rezerwacje = suma
  aktywnych rezerwacji, zamówienie ma swoją rezerwację).
- **Idempotencja**: tak, na trzech poziomach: ten sam webhook 10 razy równolegle (`WebhookConcurrencyTest.php:95`),
  to samo zdarzenie z brokera dwa razy (`EventFlowTest.php:75`, `SendOrderConfirmationFlowTest.php:74`), ten sam
  `Idempotency-Key` 10 razy (`PlaceOrderConcurrencyTest.php:103`, `StartPaymentConcurrencyTest.php:73`).
- **Awarie**: tak. Wyjątek w połowie transakcji przez trigger (`PlaceOrderTest.php:121-163`,
  `WebhookFlowTest.php:73-95`, `ReservationCommandsTest.php:86,102`), broker wyłączony przez 30 ponowień
  (`OutboxRelayTest.php:45`), worker zabity przed potwierdzeniem (`OutboxRelayTest.php:125`,
  `ExpireUnpaidOrderTest.php:71`), niedostępny Keycloak, SMTP, Redis i magazyn zdjęć (`KeycloakOutageTest`,
  `SmtpTimeoutWithinTheLeaseTest`, `RedisRateLimitTest`, `FlysystemProductImageStorageOutageTest`).

## Znaleziska

### 1. Nikt nie mierzy pokrycia ani siły asercji

- **Waga**: Medium. **Klasa**: RYZYKO.
- **Dowód**: `apps/api/composer.json:67-78` (zależności deweloperskie to DAMA, Deptrac, CS Fixer, PHPStan, PHPUnit,
  browser-kit, Foundry; nie ma Infection ani sterownika pokrycia), wyszukiwanie `pcov|xdebug|coverage|infection`
  w `apps/api/Dockerfile`, `Makefile`, `make/*.mk` i `.github/workflows/*.yml` nie daje żadnego trafienia.
  `phpunit.dist.xml:41-45` deklaruje `<source>`, ale żaden cel nie generuje raportu.
- **Konsekwencja**: Jakość 3729 testów zna tylko ten, kto je przeczyta. Zestaw powstaje głównie automatycznie, więc
  przybywa go szybko, a jedynym strażnikiem tego, czy nowa gałąź kodu ma test i czy test zauważyłby błąd, jest
  przegląd. Dla operatora oznacza to, że regresja w rzadko wykonywanej gałęzi (np. obsługa wyjątku w adapterze) może
  przejść CI. Prawdopodobieństwo: średnie, rośnie z rozmiarem kodu.
- **Rekomendacja**: Proporcjonalnie: raport pokrycia (pcov) z samej suity `unit`, publikowany w podsumowaniu kroku CI
  bez progu, oraz testy mutacyjne ograniczone do `src/*/Domain` (pieniądze, alokacja rabatu, stany, cykl zamówienia)
  uruchamiane na żądanie albo raz na dobę, nie przy każdym PR.
- **Pewność**: wysoka co do faktu. Sprawdziłem `composer.json`, Dockerfile, wszystkie pliki make i oba workflow.
  Nie twierdzę, że pokrycie jest niskie: próbka wskazuje na wysokie.

### 2. Testy obciążeniowe i Lighthouse niczego nie egzekwują w zakresie wydajności

- **Waga**: Medium. **Klasa**: RYZYKO (świadoma decyzja, oceniam jej skutek).
- **Dowód**: `load/steady.js:27-32`:
  ```js
  const thresholds = {};
  reportOn(thresholds, ['scenario:read_steady', ...]);
  thresholds['dropped_iterations{scenario:read_steady}'] = [];
  thresholds['orders_placed{scenario:checkout}'] = [];
  ```
  `load/lib/summary.js:3-4`: „An empty list judges nothing”. `infra/k8s/scripts/load.sh:347`: „The steady run has no
  thresholds: any exit code but 0 is a run that did not happen”. W `saturate.js:36-37` progi służą do wykrycia
  nasycenia, a wyjście 99 jest „normalnym końcem”. `load/smoke.js:14-20` ma progi tylko poprawności (1 użytkownik,
  1 iteracja). `e2e/lighthouse/lighthouserc.cjs:24-28`: tylko SEO jest `error`, Performance, LCP, CLS i TBT to `warn`.
- **Konsekwencja**: Przebieg stały z 30% odpowiedzi 5xx kończy się kodem 0 i trafia do raportu jako liczba do
  przeczytania. Żadna zmiana, która dwukrotnie spowalnia stronę produktu albo wyszukiwarkę, nie zrobi czerwonego CI.
  Scenariusze nie dotykają też skali docelowej z `docs/vision.md:228-229` (10 mln produktów, 60 tys. użytkowników):
  dane to mały katalog demo, każda iteracja to nowy gość z nowym adresem, nie ma klientów zalogowanych, historii
  zamówień, panelu ani równoległych webhooków, a checkout jest ograniczony do ćwierci wątków API, bo symulator
  płatności działa w tym samym kontenerze (`load/README.md`, „Ograniczenia”). Wizja mówi to uczciwie
  (`docs/vision.md:230-234`), więc nie jest to błąd, ale wymagania wydajnościowe pozostają niezweryfikowane.
- **Rekomendacja**: Zostawić pomiar jako pomiar, a dodać dwie tanie bramki: w `steady.js` próg `server_errors`
  (np. `rate<0.01`) i `checks`, żeby przebieg z błędami nie był „udany”; w CI budżet względny dla `load-smoke` albo
  Lighthouse (np. TBT i LCP jako `error` z luźnym progiem), żeby łapać regresje o rząd wielkości. Test skali docelowej
  wymaga wygenerowanego katalogu i osobnej decyzji.
- **Pewność**: wysoka. Przeczytałem wszystkie skrypty k6, nagłówek `load.sh`, specyfikację HARDENING (D13–D18)
  i wizję. Raportu `docs/reports/load-test-2026-11-10.md` nie ma w eksporcie, więc nie oceniam jego treści.

### 3. Zgodność odpowiedzi z OpenAPI jest sprawdzana tylko na poziomie nazw kluczy, w kilkunastu kopiach ręcznego walidatora

- **Waga**: Medium. **Klasa**: RYZYKO.
- **Dowód**: `apps/api/tests/Functional/Cart/CartEndpointTestTrait.php:205-225`:
  ```php
  self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($body), $name);
  foreach ($schema['properties'] as $property => $definition) {
      ...
      if (null === $ref || !\is_array($value) || [] === $value) { continue; }
  ```
  Ten sam pomysł jest zaimplementowany osobno w 17 miejscach (`assertKeysMatchSchema` w 11 plikach, m.in.
  `ProductsEndpointTest.php:722`, `PromotionsEndpointTest.php:929`, `StockEndpointTestTrait.php:152`;
  `assertMatchesSchema` w `BackofficeOrderEndpointTestTrait.php:204` i `CustomerOrderHistoryEndpointTest.php:327`;
  `assertMatchesNodeSchema` w `CategoriesEndpointTest.php:141`). Żadna kopia nie sprawdza `type`, `nullable`, `enum`,
  `format` ani `required`. W `src` jest 257 atrybutów `OA\Property` z ręcznie wpisanym `type`, `enum`, `nullable`
  albo `format`, czyli deklaracji, których generator nie wyprowadza z typów PHP.
- **Konsekwencja**: Klienci TypeScript sklepu i panelu powstają z `openapi.json`. Jeśli ręczna deklaracja rozjedzie
  się z odpowiedzią (pole opisane jako nienullowalne zwraca `null`, enum bez nowej wartości), testy API przejdą,
  typy frontu będą kłamać, a błąd wyjdzie dopiero w przeglądarce klienta albo pracownika. Częściowo chronią przed tym
  asercje `assertSame` na wartościach i jeden ręczny test enumu (`OrderEndpointTestTrait.php:109-133`), ale tylko na
  ścieżkach, które ktoś wypisał. CI sprawdza świeżość eksportu (`ci.yml:319-321`), nie zgodność odpowiedzi.
  Prawdopodobieństwo: niskie do średniego, bo większość typów pochodzi z typów PHP.
- **Rekomendacja**: Jedna wspólna asercja w `tests/Support` zamiast 17 kopii, oparta na walidatorze JSON Schema
  (nowa zależność deweloperska, do decyzji), która sprawdza całą odpowiedź łącznie z typami, `nullable` i `enum`,
  oraz kod statusu względem `responses` operacji.
- **Pewność**: wysoka co do zakresu sprawdzenia (przeczytałem trzy kopie w całości i nagłówki pozostałych), średnia
  co do tego, czy rozjazd dziś istnieje: nie porównywałem 257 deklaracji z odpowiedziami.

### 4. Asercje na czasie zegarowym z wąskim marginesem i stałe `sleep` przy zerowej liczbie ponowień

- **Waga**: Low. **Klasa**: RYZYKO.
- **Dowód**: `apps/api/tests/Integration/Shared/Health/ProbesTest.php:117-118`:
  ```php
  self::assertGreaterThan(0.9, $elapsed);
  self::assertLessThan(1.5, $elapsed);
  ```
  To samo w `DashboardTimingTest.php:88-89,113,132`. `tests/Unit/Shared/Security/AccessTokenSignatureSpeedTest.php:26`:
  `LIMIT_SECONDS = 0.1` na podpis RSA w suicie `unit`, która w CI biegnie obok PHPStana i testów frontów
  (`ci.yml`, część `apps`). Stałe `usleep(300_000)` przed asercją negatywną w `EventFlowTest.php:90`,
  `SendOrderConfirmationFlowTest.php:87`, `SendOrderStatusEmailsFlowTest.php:169`.
- **Konsekwencja**: Na współdzielonym runnerze z uruchomionym Elasticsearchem, Keycloakiem i RabbitMQ pół sekundy
  zapasu na sondę z limitem 1 s może nie wystarczyć, a CI nie ma ponowień (słusznie), więc sporadyczna czerwień
  blokuje merge do czasu ręcznego ponowienia. Odwrotny skutek mają stałe `sleep`: gdy ponowienie spóźni się ponad
  300 ms, asercja „nic nie wróciło do kolejki” przejdzie, choć nie powinna. Prawdopodobieństwo: niskie, autorzy
  bronią się najlepszym z trzech pomiarów (`AccessTokenSignatureSpeedTest.php:21-22`).
- **Rekomendacja**: Górne granice poszerzyć do wielokrotności limitu (test ma dowieść, że limit istnieje, nie że
  maszyna jest szybka), a stałe `sleep` zastąpić odpytywaniem z terminem, tak jak robi to już `awaitEventsInQueue`.
  Test szybkości podpisu zamienić na sprawdzenie `extension_loaded('gmp')`.
- **Pewność**: średnia. Statycznie nie da się stwierdzić, czy te testy rzeczywiście bywają czerwone.

### 5. Wspólny stan bazy testowej: testy współbieżności commitują, a inne testy liczą wszystkie wiersze tabeli

- **Waga**: Low. **Klasa**: RYZYKO.
- **Dowód**: `apps/api/tests/Functional/Ordering/OrderEndpointTestTrait.php:94-97`:
  ```php
  private function orders(): int
  {
      return OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order');
  }
  ```
  68 takich globalnych `COUNT(*)` w testach. Testy współbieżności zapisują wiersze na stałe i sprzątają je w
  `tearDown` po identyfikatorach (`PlaceOrderConcurrencyTest.php:63-77`). `apps/api/Makefile:36-38` (`init-test`)
  tylko migruje bazę i odtwarza indeks, niczego nie czyści.
- **Konsekwencja**: Przebieg przerwany w trakcie testu współbieżności (Ctrl-C, limit czasu, błąd krytyczny w rodzicu)
  zostawia w `shop_test` zatwierdzone zamówienia, rezerwacje i koszyki. Od tej chwili lokalnie padają niezwiązane
  testy funkcjonalne, np. `assertSame(1, $this->orders())`, aż ktoś ręcznie wyczyści bazę. W CI problemu nie ma, bo
  każda część ma świeżą bazę. Dotyczy programisty i agenta pracującego lokalnie.
- **Rekomendacja**: Cel `make -C apps/api reset-test` (drop i create `shop_test`) wywoływany przez `init-test`, albo
  asercje liczące wiersze zawężone do danych testu.
- **Pewność**: wysoka co do mechanizmu, średnia co do częstości.

### 6. Test „schemat z migracji = mapowanie” jest filtrowany po prefiksie tabeli i nie obejmuje Cart ani symulatora płatności

- **Waga**: Low. **Klasa**: RYZYKO.
- **Dowód**: `apps/api/tests/Integration/Ordering/Persistence/OrderingSchemaTest.php:22-25`:
  ```php
  $updates = array_values(array_filter(
      new SchemaTool($entityManager)->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()),
      static fn (string $sql): bool => str_contains($sql, 'ordering_'),
  ));
  ```
  Takie testy istnieją dla `catalog_`, `inventory_`, `ordering_`, `pricing_`, `payment_payment`/`payment_webhook_event`
  i `app_backfill` (7 plików używa `SchemaTool`). Mapowane tabele `cart_cart`, `cart_line`
  (`src/Cart/Infrastructure/Persistence/Doctrine/Record/CartRecord.php:18`, `CartLineRecord.php:16`)
  i `payment_simulator_transaction` (`src/PaymentSimulator/Transaction.php:18`) nie mają żadnego.
- **Konsekwencja**: Zmiana rekordu koszyka bez migracji (inna długość kolumny, nullowalność, indeks) nie zostanie
  wykryta dedykowanym testem. Rozjazd, który psuje zapytania, wyłapią testy integracyjne Cart; rozjazd cichy (brakujący
  indeks, luźniejsze ograniczenie) dotrze na serwer.
- **Rekomendacja**: Jeden test w `Shared` bez filtra prefiksu, z jawną listą wyjątków (indeksy funkcyjne, sekwencja
  numerów, `messenger_messages`), zamiast siedmiu filtrowanych.
- **Pewność**: wysoka. Przeszukałem `tests` i `src` pod `SchemaTool`, `SchemaValidator`, `schema:validate`.

### 7. Dane testowe głównie surowym SQL, wbrew własnej zasadzie o fabrykach

- **Waga**: Low. **Klasa**: PREFERENCJA (odstępstwo od zasady projektu, bez wykazanej szkody).
- **Dowód**: `apps/api/CLAUDE.md:1201`: „Dane testowe przez fabryki (Foundry), a nie tablice budowane ręcznie w każdym
  teście”. Fabryk Foundry jest 6 (`tests/Factory`), wszystkie dla Catalog i Pricing. `INSERT INTO` występuje w 86
  plikach testów: `catalog_category` w 16 plikach, `catalog_product` w 13, `pricing_promotion` w 13,
  `inventory_stock_item` w 12, `ordering_order` w 10 (np. `Functional/Backoffice/DashboardQueuesEndpointTest.php:96`
  wypisuje 22 kolumny zamówienia).
- **Konsekwencja**: Nowa kolumna `NOT NULL` bez wartości domyślnej w `ordering_order` wymaga poprawki w 10 plikach.
  Część surowego SQL jest uzasadniona: testy współbieżności piszą na osobnym, commitującym połączeniu, a testy
  migracji i planów zapytań muszą operować na tabeli. Reszta to powielanie.
- **Rekomendacja**: Zaktualizować zasadę do stanu faktycznego (fixtury SQL w `*Fixture.php` dla modułów bez mapowania
  domeny) i przenieść rozproszone `INSERT` do istniejących `OrderingFixture`, `StockFixture`, `CatalogFixture`.
- **Pewność**: wysoka.

### 8. Macierz ról panelu nie jest wymuszana dla nowych tras

- **Waga**: Low. **Klasa**: RYZYKO.
- **Dowód**: `apps/api/tests/Functional/Backoffice/AdminRouteAccessTest.php:16-19`: test bierze trasy z routera
  i sprawdza, że każda wymaga `ROLE_STAFF`. Uprawnienia per rola (`#[IsGranted]` na 29 kontrolerach: wszystkich zapisach
  i wybranych odczytach) mają testy pisane ręcznie dla każdego endpointu (np. `WriteProductEndpointsTest.php:78`,
  `CompleteRefundEndpointTest.php:91`). Dziesięć kontrolerów odczytu panelu nie ma `IsGranted`
  (m.in. `BackofficeStockController`, `BackofficePricesController`, `ListDiscountCodesController`), co testy
  potwierdzają jako zamierzone (`testEveryStaffRoleCanReadTheStock`, `...ThePrices`). `CategoryVoter`, `StockVoter`
  i `PromotionVoter` nie mają testu jednostkowego, choć pozostałe votery mają.
- **Konsekwencja**: Nowy endpoint zapisu w panelu dodany bez `#[IsGranted]` przejdzie `AdminRouteAccessTest`, bo
  wystarczy mu dowolna rola pracownika. Magazynier mógłby wtedy np. zmieniać dane, do których nie ma prawa, a jedyną
  ochroną byłaby pamięć autora o teście 403.
- **Rekomendacja**: Rozszerzyć test tras o regułę: każda trasa panelu z metodą inną niż GET/HEAD musi odpowiedzieć
  403 przynajmniej jednej z czterech ról (albo być na jawnej liście wyjątków).
- **Pewność**: wysoka. Przejrzałem listę kontrolerów panelu z liczbą sprawdzeń uprawnień i `security.yaml`.

### 9. E2E: zależność od danych seeda, zużywanie stanów między przebiegami i brak ścieżek błędu w kasie

- **Waga**: Low. **Klasa**: RYZYKO.
- **Dowód**: `e2e/tests/checkout-guest.spec.ts:7-8`: „Every variant of it has 50 units (fixtures/stock.yaml): each run
  reserves 2, and the reservation of a paid order stays until it is shipped ... so the demo data last many runs before
  `make seed`”. To samo w `checkout-customer.spec.ts:7` i `payment-resume.spec.ts:5`. Testy używają stałych nazw
  produktów demo i wspólnych kont (`support/accounts.ts`). `e2e/playwright.config.ts:10-11`: `workers: 1`,
  `retries: 0`. W `e2e/tests` nie ma scenariusza braku towaru w kasie, zmiany kwoty zamówienia, odpowiedzi 429 ani
  dostarczenia i zwrotu towaru w panelu (wyszukiwanie po komunikatach i identyfikatorach akcji trafia tylko w zamknięcie
  zwrotu pieniędzy, `order-management.spec.ts:135`, i w niedostępne warianty na kafelku listy).
- **Konsekwencja**: Lokalnie zestaw nie jest powtarzalny w nieskończoność: po kilkunastu przebiegach bez `make seed`
  kończą się sztuki produktów kasy. W CI każda część ma świeży seed, więc tam problemu nie ma. Ścieżki błędu kasy są
  dobrze pokryte w API, ale to, co widzi klient po 409 (komunikat, stan koszyka, możliwość ponowienia), nie ma testu
  na pełnym stosie w moim zakresie (testów jednostkowych sklepu nie oceniałem).
- **Rekomendacja**: Jeden test E2E „ostatnia sztuka kupiona przez kogoś innego między koszykiem a kasą”, z produktem
  i stanem założonym przez API panelu, tak jak robią to już `promotions.spec.ts` i `discount-codes.spec.ts`.
- **Pewność**: wysoka co do faktów, średnia co do wagi.

### 10. Suita `unit` zawiera testy, które jednostkowe nie są; konfiguracja PHPUnit nie wykrywa zależności od kolejności

- **Waga**: Low. **Klasa**: PREFERENCJA.
- **Dowód**: `tests/Unit/Shared/Messaging/MessengerRoutingTest.php:34` (`extends KernelTestCase`),
  `tests/Unit/Bin/SeedDemoScriptTest.php` (uruchamia skrypt `sh` w katalogu tymczasowym),
  `tests/Unit/Shared/Messaging/EventsRetryStrategyTest.php:36-43` (parsuje YAML konfiguracji),
  `AccessTokenSignatureSpeedTest` (pomiar czasu). `apps/api/phpunit.dist.xml:5-10` nie ustawia `failOnRisky` ani
  `executionOrder="random"`.
- **Konsekwencja**: Suita, która według `apps/api/CLAUDE.md:1197-1198` ma trwać sekundy i nie zależeć od środowiska,
  ma cztery testy zależne od powłoki, plików konfiguracji i szybkości maszyny. Bez losowej kolejności ukryta
  zależność między testami wyjdzie dopiero przy zmianie nazwy pliku.
- **Rekomendacja**: Przenieść te cztery pliki do `Integration` albo nowej, małej suity; rozważyć `failOnRisky="true"`.
- **Pewność**: wysoka.

### 11. Inventory nie ma portu zegara, więc jego testy nie mogą sprawdzić czasu

- **Waga**: Low. **Klasa**: PREFERENCJA.
- **Dowód**: `apps/api/src/Inventory/Domain/Stock/StockItem.php:57` i `:218` (`new \DateTimeImmutable()` w domenie),
  podczas gdy Cart, Catalog, Ordering, Payment i Pricing mają `Application/Port/Clock.php`. Skutek w teście
  `tests/Unit/Inventory/Domain/StockItemTest.php:88`:
  ```php
  self::assertGreaterThanOrEqual($before, $movement->occurredAt());
  ```
- **Konsekwencja**: Asercja przejdzie dla dowolnego czasu nie wcześniejszego niż początek testu, więc błąd strefy
  albo pomylony znacznik w historii stanów nie zostanie złapany. W pozostałych modułach testy porównują dokładną
  chwilę z zegara testowego.
- **Rekomendacja**: Przy najbliższej zmianie Inventory przekazywać chwilę z handlera, jak w innych modułach.
- **Pewność**: wysoka.

## Pięć najlepszych testów

1. `apps/api/tests/Integration/Ordering/Concurrency/PlaceOrderConcurrencyTest.php:79`
   (`testO1TenGuestsBuyingTheLastThreeUnitsGetExactlyThreeOrders`). Dziesięć procesów, trzy sztuki, pięć rund;
   sprawdza dokładny rozkład wyników, stan magazynu, koszyki przegranych i niezmiennik „rezerwacje = suma aktywnych
   rezerwacji”. Tak testuje się nadsprzedaż.
2. `apps/api/tests/Integration/Payment/WebhookFlowTest.php:73`
   (`testAFailureOfMarkOrderPaidRollsTheWebhookBackAndTheRetriedDeliveryPaysTheOrder`). Trigger PostgreSQL wymusza
   awarię w zagnieżdżonej komendzie; test dowodzi, że wycofała się także deduplikacja webhooka, więc ponowienie
   jest obsłużone od nowa i opłaca zamówienie dokładnie raz.
3. `apps/api/tests/Unit/Pricing/Domain/BasketQuoteTest.php:171` (`testTheMaximumBasketIsComputedExactly`). Koszyk
   50 × 99 × 999 999,99 zł z kodem 90%: iloczyn pośredni przekracza 64 bity, a wartości oczekiwane policzono
   niezależnie. Razem z testami remisów i linii za 1 grosz (`:81-169`) zamyka temat zaokrągleń.
4. `apps/api/tests/Functional/Backoffice/AdminRouteAccessTest.php:70`
   (`testABackofficeTokenWithoutAStaffRoleIsForbiddenEverywhereButMe`). Lista tras pochodzi z routera, więc nowa trasa
   panelu jest sprawdzana bez zmiany testu; osobny test (`:38`) pilnuje, żeby lista nie była pusta, a kolejny (`:107-128`)
   próbuje obejść wyjątek `/me` ukośnikiem, kodowaniem i inną metodą.
5. `apps/api/tests/Integration/Shared/Messaging/OutboxRelayTest.php:45`
   (`testEventStaysInTheOutboxWhileTheBrokerIsDownAndIsRelayedOnceItIsBack`). Prawdziwy RabbitMQ, 30 ponowień
   z rosnącym opóźnieniem, przejście do `failed` i odzyskanie po powrocie brokera; sąsiednie testy sprawdzają nack,
   brak trasy i śmierć workera przed potwierdzeniem.

Wyróżnienie: `Integration/Ordering/Persistence/OrderSearchPlanTest.php:51` sprawdza plan zapytania na 100 tys.
zamówień w tabeli tymczasowej, bez sterowania planistą.

## Pięć najsłabszych testów

1. `apps/api/tests/Integration/Catalog/Search/SearchFacetsPerformanceTest.php:33,72`. Zawsze pomijany bez
   `SEARCH_PERF=1`, a po uruchomieniu kończy się `addToAssertionCount(1)`: wypisuje liczby i nie może się nie udać.
   To narzędzie pomiarowe w przebraniu testu.
2. `apps/api/tests/Unit/Shared/Security/AccessTokenSignatureSpeedTest.php:26,49-50`. Sprawdza środowisko
   (rozszerzenie `gmp`), nie kod, przez pomiar czasu z limitem 0,1 s w suicie jednostkowej.
3. `apps/api/tests/Unit/Pricing/Domain/TaxClassRateContractTest.php:24`.
   `assertGreaterThanOrEqual(0, $rate->basisPoints())` jest prawdziwe dla każdej stawki; test może paść tylko wyjątkiem
   z `rateFor`. Drugi test w tym pliku (`:28`) sprawdza to samo lepiej.
4. `apps/api/tests/Unit/Shared/Messaging/EventsRetryStrategyTest.php:17-22`. Czyta `messenger.yaml` i porównuje
   z tymi samymi trzema liczbami wpisanymi w teście: wykrywa każdą zmianę konfiguracji, żadnego zachowania.
   Podobnie `tests/Unit/Bin/SeedDemoScriptTest.php:54` powtarza kolejność komend skryptu.
5. `apps/api/tests/Unit/Catalog/Domain/ProductNotFoundTest.php:13`. Sprawdza, że wyjątek dziedziczy po `NotFound`
   i ma stały tekst; to samo dowodzą testy funkcjonalne 404.

Żaden z nich nie szkodzi. To najsłabsze testy w zestawie, w którym słabych jest bardzo mało.

## Mocne strony

- **Prawdziwe usługi w testach integracyjnych.** 148 klas integracyjnych uruchamia kernel na `shop_test`; testy
  wyszukiwania tworzą tymczasowy alias Elasticsearcha (`UsesTemporarySearchAlias`), testy komunikatów używają vhosta
  `test` RabbitMQ i API zarządzania brokera (`OutboxRelayTest.php:76-123`), maile są odczytywane z Mailpita.
- **Uprząż współbieżności ma własne testy.** `Integration/Inventory/Concurrency/ConcurrentCommandsTest.php:22-54`
  sprawdza, że wyjątek uczestnika dociera do rodzica, że uczestnik bez wyniku psuje przebieg i że błąd krytyczny
  dziecka nie zamyka połączeń rodzica.
- **Determinizm czasu w domenie.** Pięć modułów ma port `Clock`, testy jednostkowe używają stałych zegarów
  (`FixedOrderingClock`, `FixedPricingClock` i inne), a testy funkcjonalne, które zależą od godziny, ustawiają zegar
  (`DashboardSalesEndpointTest.php:55,63,86`). Brak wywołań sieci zewnętrznej w testach.
- **Testy migracji danych.** 14 plików testów uruchamia prawdziwe `down()` i `up()` migracji na danych
  (`CartLinesByVariantMigrationTest.php:46-83`), a `ConcurrentIndexMigrationsTest` sprawdza ponowienie po nieudanym
  `CREATE INDEX CONCURRENTLY`.
- **Testy planów zapytań.** `OrderSearchPlanTest`, `OrderQueuesPlanTest`, `SalesRebuildPlanTest`,
  `StockLevelProductsPlanTest`, `ProductsWithoutImagesPlanTest` dowodzą użycia indeksu na dużych danych, co wprost
  wspiera wymaganie skali z wizji.
- **Brak wycieku danych w błędach jest asercją.** `OrderEndpointTestTrait.php:140-149` sprawdza, że 409 za użyty
  klucz nie zawiera numeru, identyfikatora ani e-maila cudzego zamówienia.
- **E2E pokrywa pełny zakup z płatnością i panel.** Gość płaci przelewem i dostaje oba maile
  (`checkout-guest.spec.ts`), klient ma odrzuconą kartę i płaci ponownie (`checkout-customer.spec.ts`), wraca do tej
  samej sesji płatności (`payment-resume.spec.ts`), magazynier wysyła, a wsparcie anuluje z notatką i zamyka zwrot
  (`order-management.spec.ts:18,79`). Selektory to w 95% role, etykiety i `data-testid` (1390 wywołań `getBy*` wobec
  70 selektorów CSS), a twardych `waitForTimeout` jest 9, wszystkie uzasadnione asercją negatywną albo oknem TOTP.
- **CI uruchamia wszystko, co istnieje.** Trzy suity API w osobnych częściach, E2E na buildzie produkcyjnym w trzech
  częściach bez ponowień, świeżość kontraktu (`ci.yml:316-321`), `load-smoke` i Lighthouse po E2E, ślady Playwrighta
  po błędzie. Wymagane checki są czerwone także przy anulowaniu części (`ci.yml`, joby `checks` i `e2e`).

## Zbędna złożoność

- **Dwie niemal identyczne uprzęże forkujące** (`Integration/Inventory/Concurrency/ConcurrentCommands.php`, 227 linii,
  i `Integration/Ordering/Concurrency/ParallelProcesses.php`, 198 linii) plus trzecia wersja w
  `StartPaymentConcurrencyTest`. Komentarz w drugiej mówi wprost, że to ta sama bariera „dla dowolnego kodu”.
  Wystarczyłaby `ParallelProcesses` z cienką nakładką dla komend.
- **Siedemnaście kopii walidatora kluczy schematu** (znalezisko 3).
- **Testy sprzężone z kolejnością wywołań przez dziennik** (`PlaceOrderHandlerTest.php:193-200`,
  `HandleProviderWebhookHandlerTest.php:66-70`): asercja na liście
  `['find', 'lock', 'find', 'prices', 'catalog', 'number', 'clear', 'reserve', 'add', 'publish', 'schedule']`.
  Dla kolejności blokad (koszyk, kod, stany) jest to uzasadnione specyfikacją; dla pozostałych kroków utrwala
  implementację i będzie pękać przy refaktoryzacji bez zmiany zachowania.
- **Testy jednorazowych migracji** wykonują `down()` historycznej migracji na bieżącym schemacie. Po wdrożeniu
  migracji ich wartość spada do zera, a każda późniejsza zmiana tej samej tabeli może wymagać ich poprawiania.
- **350 testów E2E przy `workers: 1`**: większość to dostępność, reflow i fokus ekranów panelu
  (`admin-design`, `admin-screens-*`, `visual-*`). Są wartościowe, ale to one wyznaczają czas CI i wymagają ręcznego
  równoważenia części (`e2e/CLAUDE.md`, `E2E_SHARD_WEIGHTS`).

## Braki

- Pomiar pokrycia i testy mutacyjne domeny (znalezisko 1).
- Bramka wydajności w CI i test na danych zbliżonych do skali docelowej (znalezisko 2).
- Pełna walidacja odpowiedzi względem OpenAPI: typy, `nullable`, `enum`, kody statusów (znalezisko 3).
- Test schematu dla `cart_*` i `payment_simulator_transaction` (znalezisko 6).
- Automatyczna reguła „trasa zapisu panelu ma ograniczenie roli” (znalezisko 8).
- E2E ścieżek błędu kasy i przepływu dostarczenie → zwrot w panelu (znalezisko 9).
- Scenariusze obciążeniowe dla klienta zalogowanego, historii zamówień i panelu.
- Testy jednostkowe `CategoryVoter`, `StockVoter`, `PromotionVoter` (pozostałe votery je mają).

## Czego nie zweryfikowano

- Niczego nie uruchamiałem: nie wiem, czy zestaw jest zielony, ile trwa ani które testy bywają niestabilne.
  Ocena niestabilności (znalezisko 4) opiera się wyłącznie na kodzie.
- Rzeczywistego pokrycia linii i gałęzi (brak narzędzia w repo, zakaz uruchamiania).
- Treści raportu z pomiaru obciążenia: `docs/reports/` nie ma w eksporcie.
- Czy 257 ręcznych deklaracji OpenAPI zgadza się dziś z odpowiedziami.
- Testów jednostkowych sklepu i panelu (poza zakresem), więc nie wiem, czy ścieżki błędu kasy są pokryte po stronie
  frontu.
- Pełnej treści ok. 410 plików testów API: dla nich znam nazwy testów i wyniki heurystyk, nie każdą asercję.
- Liczby 350 testów E2E i czasu CI: pochodzą z `e2e/CLAUDE.md`, statycznie policzyłem 244 wywołania `test(` przed
  rozwinięciem pętli parametryzujących.
