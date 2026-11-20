# CLAUDE.md – apps/api

Symfony 8, PHP 8.5, Doctrine ORM, PostgreSQL. DDD + hexagonal.
Zasady wspólne dla całego repo są w głównym `CLAUDE.md`, opis domeny w `docs/vision.md`. Działanie funkcji (endpointy,
pola, przebiegi, komendy) opisują specyfikacje w `docs/features/<funkcja>/spec.md` i kod.

## Komendy

Uruchamiaj z katalogu głównego przez `make -C apps/api <cel>`:
- `check` – PHPStan, Deptrac, PHP CS Fixer
- `test` – wszystkie testy, `test-unit` – tylko jednostkowe (bez Postgresa, Elasticsearch i RabbitMQ); dodatkowe
  argumenty PHPUnit w `PHPUNIT_ARGS`, np. `make -C apps/api test-unit PHPUNIT_ARGS='--filter CartTest'`.
  CI uruchamia suity `unit`, `integration` i `functional` z `phpunit.dist.xml` po nazwie, w osobnych częściach
  `checks`: nowa suita wymaga też nowej części w macierzy `checks-part` w `ci.yml`, inaczej CI jej nie uruchomi
- `init` – migracje bazy deweloperskiej i `catalog:search-index:setup` (alias `products` i indeks, jeśli ich nie ma);
  idempotentny, wywołuje go `make up` po starcie usług
- `init-test` – migracje bazy `shop_test` i indeks testowy `test_products` tworzony od nowa
  (`catalog:search-index:setup --recreate`); wywołuje go `test` przed testami
- `migrate`, `migration` – uruchomienie i wygenerowanie migracji
- `openapi` – eksport specyfikacji do `openapi.json` (sklep: endpointy publiczne i klienta)
  oraz `openapi-admin.json` (panel: `/api/v1/admin`). Nieudany eksport nie zmienia plików. CI sprawdza, że commitowane
  pliki są aktualne
- Komendy konsoli bez celu make: `make -C apps/api shell` albo `docker compose exec api bin/console`.

Dane demo:
- `make seed` (główny `Makefile`) uruchamia `bin/seed-demo`: seedy `*:seed-demo` wszystkich modułów, potem
  `catalog:reindex`, `storefront:cache:purge-all` i `app:backfill --force`. Błąd dowolnego kroku przerywa skrypt
  z niezerowym kodem. Każda komenda `*:seed-demo` w `prod` wymaga `--force-reset` (`bin/seed-demo` dodaje go tylko przy
  `APP_ENV=prod`). Seed sprawdza pliki `fixtures/*.yaml` przed resetem: błąd w pliku kończy seed bez zmian w danych.
  Kolejność i zawartość seedów: `bin/seed-demo` i specyfikacje modułów.
- Testy E2E nie realizują ani nie zmieniają kodów rabatowych demo (`fixtures/discount-codes.yaml`), tworzą własne przez
  API panelu; lista kodów demo nie może być pusta (`admin-design.spec.ts` otwiera formularz pierwszego kodu z listy).
  Promocje demo (`fixtures/promotions.yaml`) omijają produkty E2E i kategorię Bieganie > Buty biegowe (pilnuje tego
  `RealDemoPromotionsTest`, lista wykluczeń w teście, razem z produktem `promotions.spec.ts`).
- `make seed-scale` (`PROFILE`, `ANCHOR`, `PROCESSES`, `DEMO_PHOTOS_ENV_FILE`; `bin/generate-demo`) i
  `make upload-demo-photos DIR=apps/api/var/demo-photos` (`bin/upload-demo-photos`, na hoście): generator danych demo
  i wysyłka puli zdjęć, opis w `docs/features/demo-data/spec.md`. Po `make seed-scale` `make seed` przywraca dane E2E.
  Katalog zdjęć do wysyłki (i `--manifest=`) musi leżeć w `apps/api`, bo kontener `api` widzi tylko ten katalog
  (`apps/api/var/` jest ignorowany przez git).
  W `prod` `bin/generate-demo` bez `--force-reset` odmawia, bo zaczyna od `bin/seed-demo`.
- Poświadczenia puli zdjęć (`DEMO_PHOTOS_*`) nie należą do repo (`.env` ma je puste): `make seed-scale`
  i `bin/upload-demo-photos` czytają je jako dane (bez `source` i `eval`) z pliku użytkownika poza repo i przekazuje kontenerowi samą nazwą (`docker compose exec -e NAME`,
  `docker run -e NAME`), więc wartość nie trafia do linii komendy, wyjścia make ani `make -n`. Komunikaty błędów
  podają nazwy zmiennych, nigdy wartości, i nie powtarzają komunikatu wyjątku klienta S3 (zawiera adres i bucket),
  tylko nazwę klasy wyjątku. Generator dostaje tylko token odczytu, wysyłka tylko token zapisu. Obraz `rclone` jest
  wyłącznie przypięty `@sha256:` (`images.rclone` w `infra/k8s/charts/shop/values.yaml`).

Narzędzia uruchamiane świadomie (nie wywołuje ich `make init`, `make up` ani `make seed`): `pricing:reconcile-tax
[--apply]` (np. po zmianie stawki w `PolishVatRates`; `docs/features/pricing/spec.md`) i `cart:purge-expired`
(ręcznie albo z crona, w MVP bez harmonogramu; `docs/features/cart/spec.md`). `storefront:cache:purge-all` unieważnia
cały katalog w Varnishu i w sklepie. `ordering:read-models:rebuild` i `inventory:stock-records:sync` są krokami
`app:backfill` (niżej).

`app:backfill [--force]` (`Shared_UI`; w klastrze Job `backfill` po rolloucie `api`, `worker` i `worker-commands`, lokalnie koniec
`bin/seed-demo`) wypełnia dane, dla których migracje dodały tylko schemat (`docs/features/admin-features/spec.md`,
„Wdrożenie: schemat w migracjach, wypełnienie po rolloucie”). Nowy krok implementuje `Shared\Infrastructure\Backfill\BackfillStep` w warstwie Infrastructure modułu
(rejestracja tagiem z interfejsu, kolejność `#[AsTaggedItem(priority: …)]` malejąco), jest idempotentny, działa
partiami w osobnych transakcjach i jest poprawny przy ruchu. Ukończony krok nie uruchomi się drugi raz bez `--force`,
więc nowy model odczytu dostaje własny krok, a nie zmianę istniejącego.

Komenda konsoli nie połyka `\Throwable`: po komunikacie dla operatora przekazuje wyjątek dalej (`throw new
\RuntimeException(…, $kodWyjścia, $e)`) z kodem wyjścia z dokumentacji komendy, bo konsola bierze kod z `getCode()`.
Dzięki temu błąd jest w logu konsoli i w Sentry (listener konsoli zgłasza tylko wyjątki nieobsłużone). Test ścieżki błędu
używa `ApplicationTester` (`setAutoExit(false)`), bo `CommandTester` wyjątku nie łapie.

## Architektura

- Kod jest podzielony na bounded contexty: `src/<Context>/{Domain,Application,Infrastructure,UI}`.
- Domain nie zależy od Symfony, Doctrine ani żadnego frameworka. Tylko czysty PHP.
- Domena nie jest mapowana przez Doctrine. Persystencja przez rekordy w `<Context>/Infrastructure/Persistence/Doctrine`
  mapowane atrybutami, z relacjami między rekordami. Repozytoria mapują agregat ↔ rekord, a agregat odtwarzają
  przez `reconstitute()`, które nie zapisuje zdarzeń.
- Warstwa Application: handlery komend i zapytań na szynach Symfony Messenger, porty jako interfejsy.
- UI: kontrolery HTTP i komendy konsoli. Tylko tłumaczą wejście na komendę/zapytanie i wynik na odpowiedź.
- Context nigdy nie importuje Domain innego contextu. Używaj zdarzeń domenowych albo portu w warstwie aplikacji.
- Brak wspólnych value objectów między contextami. Każdy context ma własne (np. `Price` w Pricing i `Amount` w Payment).
- Deptrac pilnuje tych granic. Jeśli Deptrac zgłasza błąd, popraw projekt, nie edytuj `deptrac.yaml`.

### Pułapki Doctrine (powód wyjątków niżej)

- Rollback do savepointu przy zagnieżdżonym dispatchu nie czyści `UnitOfWork`, więc `flush` wywołującego zapisałby
  wycofane zmiany.
- Błąd bazy (także naruszenie unikalności) zamyka wspólny `EntityManager`, więc po nim transakcja nie może pisać dalej.
  Naruszenie, które kończy operację błędem domeny, można złapać po `flush` i przetłumaczyć (np.
  `PriceModifiedConcurrently`, `IdempotencyKeyReused`). Gdy po założeniu wiersza transakcja ma działać dalej (blokada,
  zapis), wiersz zakłada `INSERT … ON CONFLICT DO NOTHING` jako jawny wyjątek z listy niżej.
- `flush` wstawia nowe wiersze przed `UPDATE` z wersją: wiersze podrzędne, które zależą od wygranej wersji (np.
  historia statusów zamówienia), dopisuj osobnym `flush` po zapisie wersjonowanym, inaczej przegrany wyścig kończy się
  naruszeniem klucza zamiast konfliktem wersji.
- `find()` z `PESSIMISTIC_WRITE` na encji już obecnej w identity map nie odświeża jej stanu (ryzyko decyzji na starych
  danych, np. sprzedaży ponad stan): rekord odczytany pod blokadą odświeżaj (`refresh()`), a odczyt, który ma widzieć
  zmiany zatwierdzone w międzyczasie, czyta rekord z odświeżeniem.
- Kaskada `refresh` Doctrine czyta (i blokuje) dzieci przed rodzicem: odświeżaj rodzica pod blokadą, dopiero potem
  dzieci, żeby kolejność blokad była taka jak w innych transakcjach.

### Jawne wyjątki od persystencji przez rekordy ORM

Każdy wyjątek jest opisany w specyfikacji funkcji; nowy wyjątek dopisz tutaj z powodem.
- Blokada doradcza transakcji przez DBAL (`pg_advisory_xact_lock`, `pg_try_advisory_xact_lock`) do szeregowania jest dozwolonym wyjątkiem od persystencji przez rekordy ORM w każdym module; liczenie i zapis idą przez repozytorium.
- Inventory: stan, rezerwacje, historię i rekordy stanów wariantów z produktem zapisuje i czyta przez DBAL na
  połączeniu Doctrine, w transakcji `command.bus` (jawne `SELECT … FOR UPDATE`, `INSERT … ON CONFLICT DO NOTHING`,
  `UPDATE` z wersją). Rekordy Doctrine Inventory są tylko definicją schematu dla `doctrine:schema` i migracji,
  repozytoria ich nie używają. Powody: trzy pierwsze pułapki wyżej, a `ON CONFLICT` i tak wymaga SQL
  (`docs/features/inventory/spec.md`).
- Cart: zapisuje przez rekordy, ale każda komenda zaczyna od blokady wiersza koszyka przez ORM (`PESSIMISTIC_WRITE`,
  rekord z identity map odświeżany pod blokadą, linie po nim). Koszyk klienta zakłada `INSERT … ON CONFLICT DO NOTHING`
  przed blokadą. `PurgeExpiredCarts` usuwa partię jednym `DELETE` przez DBAL z `FOR UPDATE SKIP LOCKED`. Dwie blokady
  naraz bierze tylko scalenie (`MergeGuestCart`), zawsze w kolejności: koszyk gościa, potem koszyk klienta. Odczyty
  przez DBAL, bez blokady i bez transakcji (`docs/features/cart/spec.md`, „Blokady i jawne wyjątki od zasad”).
- Pricing, promocje: DBAL tylko do blokady doradczej transakcji (`pg_advisory_xact_lock`; `PlanPromotion`,
  `RevisePromotion`, `DeletePromotion` na wyłączność, `SetVariantPrice` współdzielona) i dwóch zapytań między
  promocjami. Handler czyta zegar dopiero po blokadzie (`docs/features/pricing/spec.md`, „Promocje”).
- Pricing, użycie kodu rabatowego: `RedeemDiscountCode` (wyłącznie zagnieżdżona w `PlaceOrder`) zapisuje przez rekord,
  nie przez DBAL, bo handler złożenia nigdy nie łapie jej wyjątku, więc odrzucenie wycofuje całą transakcję. Rekord kodu
  jest zawsze czytany od nowa, kod z limitem jeszcze raz pod blokadą `FOR NO KEY UPDATE` (nie `FOR UPDATE`: klucz obcy
  użycia bierze `FOR KEY SHARE` na wierszu kodu, z którym `FOR UPDATE` by się wykluczało). Decyzje A3, D7
  w `docs/features/discount-codes/spec.md`.
- Catalog, drzewo kategorii: komendy drzewa zaczynają od blokady doradczej transakcji (`PostgresCategoryTreeLock`),
  dopiero potem czytają całe drzewo z odświeżeniem rekordów. Jedyny zapis DBAL: zmiana zmaterializowanej `path`
  poddrzewa jednym `UPDATE … WHERE path LIKE :old || '%'`, po którym rekordy poddrzewa z identity map są odświeżane,
  żeby `flush` nie zapisał starych ścieżek. `DeleteCategory` blokuje wiersz kategorii (`FOR UPDATE`) przed liczeniem
  produktów, a zapis produktu bierze `FOR KEY SHARE` na wierszach swoich kategorii przed zapisem
  (`CategoryRepository::lockForAssignment`), więc nigdy nie kończy się 500 z klucza obcego
  (`docs/features/admin-features/spec.md`, sekcja 4, P9, P10).
- Ordering, sprzedaż godzinowa (`ordering_sales_hour`) i klienci panelu (`ordering_customer`): zapis i odczyt przez DBAL
  (rekordy tylko definiują schemat). Dopisanie przyrostowe to jeden `INSERT … ON CONFLICT … DO UPDATE` w transakcji
  `MarkOrderPaid` albo `PlaceOrder`, więc wycofana transakcja wycofuje też licznik. Godzinę i kubełek liczą wyłącznie
  wyrażenia `SalesSql`, wspólne dla zapisu i przebudowy. Przebudowa zakłada brakujące wiersze, blokuje je
  (`FOR UPDATE`) i dopiero potem liczy, więc żadne zamówienie nie ginie ani nie liczy się dwa razy (testy
  `SalesRebuildConcurrencyTest`, `CustomerSummariesConcurrencyTest`). Decyzje A2, A4 w
  `docs/features/admin-features/spec.md`.
- Ordering, limit nieopłaconych zamówień: `PostgresUnpaidOrderLock` (`pg_advisory_xact_lock(int, int)`, pierwszy klucz
  `0x4F41` dla adresu i `0x4F49` dla tożsamości, drugi `crc32` klucza) szereguje złożenia jednego adresu i jednej
  tożsamości; roszczenia (`ordering_unpaid_order_claim`) liczy i zapisuje rekord przez `DoctrineUnpaidOrderClaims`
  (`docs/features/abuse-limits/spec.md`, D7, D8).
- Payment, rozpoczęcie płatności: `PostgresPaymentStartLock` (`pg_try_advisory_xact_lock(int, int)`, pierwszy klucz
  `0x5041`, drugi `crc32` id zamówienia) bez czekania; zajęta blokada to 409 `PaymentInProgress`
  (`docs/features/abuse-limits/spec.md`, D9).
- Payment: obsłużone zdarzenie webhooka (`payment_webhook_event`) przez DBAL `INSERT … ON CONFLICT DO NOTHING`:
  powtórzenie nie jest naruszeniem unikalności, a wiersz wycofanej transakcji nie zostaje w identity map jako
  „już obsłużony” (`docs/features/payment/spec.md`).
- Ordering, znaczniki wysłanych e-maili (`ordering_order_notification`, `DbalOrderNotifications`): poza agregatem
  `Order`, więc wysłanie e-maila nie zmienia zamówienia ani jego wersji; zajęcie wysyłki to jeden warunkowy
  `INSERT … ON CONFLICT … DO UPDATE … RETURNING` z lease (`docs/features/order-management/spec.md`, „Znaczniki wysyłki
  poza agregatem”).
- Ordering, numer zamówienia: `nextval('ordering_order_number_seq')` przez DBAL; sekwencja nie jest częścią transakcji
  (wycofane złożenie zostawia lukę). Schemat Doctrine dostaje sekwencję od listenera `OrderNumberSequenceSchema`, więc
  diff migracji jej nie usuwa.
- `DemoData` zapisuje tabele przez `COPY FROM STDIN` i SQL (`CopyWriter`, w transakcji wywołującego, do tabel
  z indeksami z migracji, bez zmiany schematu), a konta w Keycloak (wyłączone i bez poświadczeń) przez REST administratora (`partialImport`), nie przez
  realmy z repo ani Job `keycloak-config`, i zmienia tylko konta grupy `demo-data-generated`. Nie publikuje zdarzeń
  ani nie planuje wiadomości, więc nie obowiązuje go zasada „zdarzenie w tej samej transakcji co agregat”; indeks
  i cache sklepu odświeża koniec `bin/generate-demo`. Dotyczy tylko danych demo (`docs/features/demo-data/spec.md`).

### Transakcje między contextami

- Ordering składa zamówienie (`PlaceOrder`) w jednej transakcji `command.bus`: komendy Cart, Pricing i Inventory idą
  przez porty Ordering jako zagnieżdżony dispatch na tym samym połączeniu (savepoint), a wycena i nazwy przez
  `query.bus` (`docs/features/checkout/spec.md`, „Zasady transakcji”). Zasady:
  - handler nigdy nie łapie wyjątku zagnieżdżonej komendy, żeby pisać dalej: każdy błąd wycofuje całą transakcję
    (koszyk, użycie kodu, rezerwację, zamówienie, outbox). Adaptery tłumaczą tylko niedobór Inventory
    i odrzucenie kodu Pricing na wyjątki Ordering i rzucają je dalej, inne wyjątki przekazują bez zmian;
  - zamówienie zapisuje się na końcu (po rezerwacji), więc `flush` komend zagnieżdżonych nie zapisuje niczego
    z Ordering;
  - kolejność blokad: wiersz koszyka, blokada doradcza adresu, blokada doradcza tożsamości (limit nieopłaconych
    zamówień), potem wiersz kodu rabatowego (tylko kod z limitem), potem wiersze stanów (rosnąco po `variant_id`).
    Blokady adresu i tożsamości bierze tylko złożenie, zawsze w tej kolejności i pod nimi liczy roszczenia. Inne komendy Cart blokują tylko koszyki, Inventory tylko stany i rezerwacje, zmiana kodu
    tylko wiersz kodu, a zwolnienie użycia nie blokuje kodu, więc nie ma cyklu;
  - odcisk żądania złożenia się nie zmienia dla żądań, które już istnieją: nowe pole dopisuje się do odcisku tylko
    wtedy, gdy jest w żądaniu.
- Komendy pracownika na zamówieniu i `ExpireUnpaidOrder` blokują najpierw wiersz zamówienia, potem rezerwację i stany
  (Inventory), więc równoległe zmiany idą po kolei i nie zakleszczają się. Webhook (`MarkOrderPaid`) nie dotyka
  Inventory i zostaje przy wersji (`docs/features/order-management/spec.md`, `docs/features/payment/spec.md`).
- Przejścia statusów zamówienia to metody agregatu `Order`, bez Symfony Workflow; tabela dozwolonych przejść
  pracownika jest tylko w `OrderStatus::allowedStaffTransitions()`.
- Payment (jedyny context zależny od Ordering) sięga do Ordering tylko przez swoje porty z adapterami na `query.bus`
  i `command.bus` i ma własne value objecty. Deptrac: tylko `Payment_Infrastructure → Ordering_Application`; Ordering
  ani żaden inny context nie zależy od Payment. Payment nie zna statusów panelu.
- Wywołania HTTP do dostawcy płatności są w transakcji, ale rollback cofa tylko stan lokalny, nie skutek u dostawcy.
  Nieodwracalne wywołanie (założenie albo anulowanie sesji, zwrot) następuje po wszystkich sprawdzeniach, które mogą
  odrzucić żądanie, pod tą samą blokadą, albo ma kompensację.

### Moduły poza contextami

- `PaymentSimulator` (`src/PaymentSimulator`, `docs/features/payment/spec.md`) udaje zewnętrznych dostawców płatności
  poza `/api/v1` (`/payment-simulator/...`, bez firewalla, poza dokumentami OpenAPI): płaski moduł z jedną warstwą
  Deptrac z dostępem tylko do `Native`, `Vendor` i `Shared_Infrastructure`; żaden context nie zależy od niego, on od
  żadnego. Ze sklepem rozmawia wyłącznie przez HTTP, jak z prawdziwym dostawcą, przez serwis `app.payment_http_client`.
  Symulator działa w kontenerze API, więc wywołanie go z tego samego kontenera zajmuje drugi wątek FrankenPHP (ryzyko
  samoblokady, spec „Ryzyka”): przed testami obciążeniowymi płatności symulator przechodzi do osobnej usługi.
- Klucze API i sekrety webhooków symulatora generuje `infra/scripts/init-env.sh` do `.env.local` (test:
  `infra/scripts/test-init-env.sh`, `make init-env-test`, w CI).
- `DemoData` (`src/DemoData`, `docs/features/demo-data/spec.md`, P1 i P1a): płaski moduł z jedną warstwą Deptrac, która
  zależy tylko od `Native`, `Vendor`, `Shared_Infrastructure` i warstw Domain Catalog, Pricing i Ordering (reguły
  i value objecty wartości, które zapisuje); żaden context ani inny moduł nie zależy od `DemoData`, a on nie używa
  agregatu ani repozytorium (Deptrac i `DemoDataBoundaryTest`). Reguły spoza Domain, które generator powtarza, pilnują
  testy kontraktu (`CatalogStepsTest`).
- Determinizm `DemoData`: liczby pseudolosowe tylko ze strumieni `Prng` z ziarna (nigdy `mt_rand` ani zegar),
  identyfikatory z `DemoUuid`, czasy od `--anchor`. Tokeny dostępu i klucze idempotencji są losowe, nigdy z ziarna.
  Zmiana losowania osoby albo konta podbija `GENERATOR_VERSION`. Nowy krok generatora dopisuje swoje tabele do sum
  kontrolnych testów i kontroli `demo-data:verify`.
- Opcja profilu komend `demo-data:*` to `--demo-profile`, nie `--profile`: tę opcję rezerwuje konsola Symfony.
- Pula zdjęć demo ma własnego klienta HTTP (`app.demo_photos_http_client`, bez limitu całego żądania), bo wspólny
  `http_client` ma 5 s na całe żądanie, co przerwałoby pobranie archiwum. Ani klient HTTP, ani klient S3 puli nie ma
  loggera, bo adres żądania zawiera endpoint i bucket (`DemoPhotosStorageWiringTest`).

## Przepisy Fleksa

- Przepis `symfony/framework-bundle` tworzy `AGENTS.md`, a `symfony.lock` go wymienia. Plik jest w `.gitignore`
  (nie da się go zacommitować przez pomyłkę), a zasady dla agenta są w tym `CLAUDE.md`. Po `composer require`,
  `composer update` i `composer recipes:update` usuń odtworzony `AGENTS.md` i przejrzyj resztę zmian przepisów.

## Reguły modelu

- Pieniądze zawsze jako liczba całkowita w groszach plus waluta, nigdy float. Zaokrąglanie VAT jawnie, w jednym miejscu.
- Agregaty edytowane równolegle (zamówienie, stan magazynowy, cena, produkt) mają optimistic locking (pole wersji):
  edycja z widoku sprawdza wersję, na której się opiera. Wyjątek: atomowe operacje na stanie magazynowym (przyjęcie,
  rezerwacja, zwolnienie, zatwierdzenie) nie sprawdzają wersji z widoku, tylko blokują wiersze (`SELECT … FOR UPDATE`
  w `StockItemRepository::lockMany`, w kolejności rosnącego `variant_id`; przyjęcie i korekta wariantu bez stanu
  zakładają wiersz przez `lockOrCreate`, `INSERT … ON CONFLICT DO NOTHING` przed blokadą) i czytają stan od nowa po
  uzyskaniu blokady; zapis i tak podnosi wersję. Korekta z panelu blokuje wiersz i dodatkowo sprawdza wersję z widoku.
  Komendy pracownika na zamówieniu blokują wiersz i porównują wersję z widoku z wersją odczytaną pod blokadą.
- Produkt (Catalog) zapisuje się wyłącznie komendami `command.bus`. Komendy z widoku porównują `expectedVersion`
  z wersją wczytanego produktu przed każdą regułą domeny, a repozytorium jeszcze raz z rekordem pod `#[ORM\Version]`.
  Reguły i ich błędy: `docs/features/catalog-admin/spec.md`.
- Catalog nie zależy od Inventory, Cart ani Pricing w żadnej warstwie: fakty stanu i oferty dają porty
  `Catalog_Application` (`VariantStockFacts`, `ProductOfferFacts`) z adapterami w `Inventory_Infrastructure`
  i `Cart_Infrastructure`. Reguła „możliwy do kupienia” i cena „od” są tylko w Cart.
- Szkic produktu jest ukryty we wszystkich odczytach sklepu w Catalog; Cart i Ordering nie znają statusu produktu.
  Które zapytania widzą szkice: `docs/features/catalog-admin/spec.md`.
- Koszyk nie ma optimistic locking ani wersji z widoku: zmiany szereguje blokada wiersza koszyka, dodanie zwiększa
  ilość, a zmiana ilości z nieaktualnej karty nadpisuje nowszą (ostatni zapis wygrywa).
- Płatność (Payment) nie ma pola wersji: istniejącą płatność zmieniają tylko operacje pod blokadą jej wiersza.
- Zdarzenia domenowe zapisywane w transactional outbox w tej samej transakcji co agregat, potem wysyłane do RabbitMQ.
  Kto je publikuje: handler komendy (`releaseEvents()` agregatu i `DomainEventPublisher` w `command.bus`), gdy jedyną
  drogą zapisu jest ta komenda. Gdy agregat ma wiele ścieżek zapisu, także poza `command.bus` (Pricing: panel, seed,
  `pricing:reconcile-tax`, przeliczenie po zmianie klasy; Inventory: panel, seed, port rezerwacji w zagnieżdżonym
  dispatchu), publikuje je `save` repozytorium w transakcji zapisu, więc żadna ścieżka go nie pominie, a odrzucony albo
  wycofany zapis nie zostawia zdarzenia.
- Zdarzenie, które ma subskrybować inny context, jest zdarzeniem integracyjnym: publicznym kontraktem modułu w jego
  warstwie Application, jak zapytania (Deptrac nie pozwala sięgać do Domain innego contextu). Handler komendy tłumaczy
  zdarzenia domenowe na kontrakty przed publikacją przez outbox (`IntegrationEvents::translate` w Catalog). Subskrybent
  czyta to, czego potrzebuje, zapytaniami modułu właściciela; zmiana pól kontraktu dotyka wszystkich subskrybentów.
- Subskrybent, który przelicza dane według stanu innego modułu (np. `RepriceOnTaxClassChange`), czyta ten stan
  zapytaniem, nigdy ze zdarzenia, więc zdarzenia w dowolnej kolejności i powtórzone kończą się stanem bieżącym.
- Odbiorcy zdarzeń muszą być idempotentni: to samo zdarzenie może przyjść więcej niż raz.
- Zamówienie przechowuje kopię cen, rabatów i adresu z chwili złożenia, nie referencje.
- Elasticsearch to tylko model odczytu. Nigdy nie podejmuj na jego podstawie decyzji biznesowych.

## API

- REST, JSON, wersjonowane pod `/api/v1`.
- Wejście przez DTO z Symfony Validator. Domena dodatkowo pilnuje swoich niezmienników. Złe parametry zapytania
  (`#[MapQueryString]`) to 400, zła treść żądania (`#[MapRequestPayload]`, tylko JSON, typy bez rzutowania) to 422;
  oba z listą `violations`.
- Parametry, których błąd typu (`q[]=…`) ma dawać ten sam polski komunikat co zła wartość, są w DTO typu `mixed`
  i sprawdzane w callbackach (np. `sort` list panelu, `q` zamówień, klientów i kodów, `level`, `images`,
  `productId`).
- Treść żądań panelu, w których wszystkie klucze są wymagane, czyta `#[RequiredKeysBody]` (`Shared\UI\Http`,
  `RequiredKeysBodyResolver`) zamiast `#[MapRequestPayload]` z `REQUIRE_ALL_PROPERTIES`: argumentem kontrolera jest klasa
  żądania, a jej parametry konstruktora to klucze. Brak dowolnego klucza, także takiego, który dopuszcza `null`, to
  jedno naruszenie „To pole jest wymagane.” przy kluczu; klasa żądania wtedy nie powstaje. Każde pole klasy żądania ma
  `Assert\Sequentially`, żeby dawało najwyżej jedno polskie naruszenie. Treść jest czytana późno, po `#[IsGranted]`,
  więc 403 atrybutu poprzedza 400, 415 i 422 treści (`docs/features/quality-fixes/spec.md`). Wyjątek: kody rabatowe
  (`DiscountCodeBodyReader`), bo kontroler czyta tam treść sam, po sprawdzeniu 404, i metody dostawy
  (`#[ExistingDeliveryMethod]`: `DeliveryMethodExistenceListener` między `#[IsGranted]` a odczytem treści daje 404 przed
  400, 415 i 422, `docs/features/delivery/spec.md`, W1).
- Dokumentacja OpenAPI z kodu (NelmioApiDocBundle). Każdy endpoint ma opisane żądanie, odpowiedzi i błędy.
- Autoryzacja: JWT z Keycloak, walidowany w authenticatorze Symfony. Dwa firewalle:
  `/api/v1/admin/...` przyjmuje tylko tokeny z realmu `backoffice` (pracownicy, `Staff`),
  reszta API tylko z realmu `shop` (klienci, `Customer`). Sprawdzany jest wystawca tokena. Token konta demo
  na stosie lokalnym do ręcznych żądań: `.claude/skills/integration/kc_login.py` (opis w skillu `/integration`).
- Nie twórz wspólnej klasy `User` dla klientów i pracowników. To osobne byty.
- Uprawnienia w voterach. Pracownik: na podstawie ról z tokena (`admin`, `support`, `warehouse`, `marketing`; jedyna
  lista: `StaffUser::STAFF_ROLES`). `ROLE_STAFF` ma tylko pracownik z co najmniej jedną z nich, a `access_control`
  wymaga go na każdej trasie `/api/v1/admin` poza `GET`/`HEAD /api/v1/admin/me`, więc konto bez roli dostaje 403
  wszędzie poza `/me`. Pilnuje tego `AdminRouteAccessTest`, który bierze trasy panelu z routera, więc nowa trasa bez
  `ROLE_STAFF` łamie go bez zmiany testu. Ograniczenie ról trasy jest tylko w voterze modułu; kontroler zna tylko
  nazwę atrybutu i pyta `isGranted` (trasa dla każdego pracownika polega na `ROLE_STAFF`). Klient: tylko własne dane
  (właściciel zasobu = `sub` z tokena, nigdy parametr). Cudzy i nieznany zasób klienta dają ten sam wynik (404), tokeny
  dostępu porównuje `hash_equals`.
- Zmiany z panelu zapisują identyfikator pracownika (`sub` z tokena realmu `backoffice`).
- Błędy w formacie RFC 9457 (problem details). `type` to domyślnie `about:blank`. Wyjątek projektu, którego przyczynę
  klient ma rozróżniać maszynowo, implementuje `App\Shared\Domain\Exception\TypedProblem` (`problemType()`) i trzyma
  wartość w stałej `TYPE`; subscriber wpisuje ją do odpowiedzi, a Shared nie zna contextów. Format wartości:
  `tag:piotrp321.github.io,2026:shop/problems/<przyczyna>` (identyfikator, nie adres). Nowy typ dopisz do `enum`
  schematu konfliktu swojego contextu w OpenAPI (np. `OrderConflictProblem`, `PaymentConflictProblem`,
  `PricingConflictProblem`). Typy odrzucenia kodu rabatowego i ich polskie `detail` daje wyłącznie
  `DiscountCodeRejection` (Pricing Domain).
- Wyjątek, który niesie dane dla klienta (np. listę kolidujących wariantów), implementuje
  `App\Shared\Domain\Exception\ExtendedProblem` (`problemExtensions()`): subscriber dopisuje te pola do dokumentu
  problemu, nigdy w miejsce `type`, `title`, `status`, `detail`, `instance` ani `violations`, i nie przy 5xx.
- `detail` w błędzie ma tylko wyjątek projektu: implementuje znacznik z `App\Shared\Domain\Exception`
  (`NotFound` → 404, `Conflict` → 409, `InvalidInput` → 422, goły `ProblemException` → 422). Wyjątek: znacznik
  `Unavailable` (zależność nieosiągalna albo przegrany wyścig, który ponowienie rozwiązuje) to 503 bez `detail`, jak
  każdy 5xx: klient może tylko spróbować później, a komunikat wyjątku zostaje w logu. Domena nie zna statusów HTTP,
  mapowanie jest w `Shared\Infrastructure\Http`: jedyne `ExceptionStatus::of()`, z którego biorą status
  `ProblemDetailsSubscriber` i zgłaszanie błędów do Sentry. Komunikat bez danych wewnętrznych i wejścia użytkownika
  (dotyczy też komunikatów naruszeń własnych constraintów). Wyjątki frameworka dostają stały ogólny opis.
  Subscriber rozpakowuje tylko `HandlerFailedException` z jednym wyjątkiem, `previous` poza tym ignoruje.
- Operacje nieidempotentne (złożenie zamówienia, rozpoczęcie płatności, przyjęcie magazynowe) wymagają nagłówka
  `Idempotency-Key`. Brak albo zły format klucza to 400 z naruszeniem nagłówka. Dostęp do zasobu jest sprawdzany przed
  powtórzeniem: sam klucz nigdy nie zwraca zasobu (bez dostępu 404 jak nieznany). Powtórzenie z tym samym kluczem i tą
  samą treścią niczego nie zmienia i zwraca 200 z bieżącym wynikiem zamiast 201; ten sam klucz z inną treścią albo
  użyty dla innego koszyka, zamówienia lub dostawcy (przy dostępie do zasobu żądania) to 409 bez danych zasobu, który
  go użył. Klucze są zapisane na
  zawsze (unikalny indeks). Szczegóły: specyfikacje `inventory`, `checkout`, `payment`.
- Rate limiting (`docs/features/hardening/spec.md`, D1–D8, `docs/features/abuse-limits/spec.md`, D1–D3,
  `config/packages/rate_limiter.yaml`): każdy limiter w `prod` i `dev` ma `lock_factory: rate_limiter.lock_factory`
  (`symfony/lock` w Redisie liczników, `config/packages/lock.yaml`), `when@test` bez blokady. Trasa wskazuje limiter
  domyślną wartością `RateLimit::ROUTE_DEFAULT`, a klucz `RateLimit::KEY_ROUTE_DEFAULT`; nowa trasa z limitem ma 429
  w OpenAPI. Redis niedostępny: żądanie przechodzi bez limitu (fail open, D7). Adres klienta bierze wyłącznie
  `ClientIp::of(Request)`.
  Kontener testów ma `APP_DEBUG=0`, więc po zmianie konfiguracji lokalnie usuń `var/cache/test*`.
- Każda nowa publiczna trasa zapisu bez logowania dostaje limiter (`RateLimit::ROUTE_DEFAULT`, wartości `prod`, `dev` i `test`) i test prób obejścia.
- Nagłówki bezpieczeństwa API ustawia `SecurityHeadersSubscriber` na każdej odpowiedzi; HSTS należy do ingressu
  (`docs/features/hardening/spec.md`, D10).
- Webhooki zewnętrzne: weryfikacja podpisu i deduplikacja po identyfikatorze zdarzenia.

## Infrastruktura

- Varnish (`infra/varnish/default.vcl`) stoi tylko przed publicznymi odczytami `GET` katalogu z listy ścieżek w VCL.
  Czyta przez niego wyłącznie serwer sklepu (`API_CATALOG_URL`). TTL bierze tylko z `Cache-Control` odpowiedzi; bez
  nagłówka, z `private` albo `no-store` nic nie trzyma. Endpointy z autoryzacją, koszyk i checkout zawsze
  `private, no-store`.
- Tagi cache (xkey) bez FOSHttpCacheBundle. Nazwy buduje wyłącznie `CacheTags` (`Shared\Infrastructure\Http\Cache`),
  a nagłówek `xkey` ustawia w kontrolerze `CacheTagger::tag()`. Słownik tagów i nagłówki odpowiedzi katalogu:
  `docs/features/product-page/spec.md`, `docs/features/catalog-admin/spec.md`, `docs/features/seo/spec.md`.
- Trasa publicznego odczytu ma domyślną wartość `PublicReadErrorCacheSubscriber::ROUTE_DEFAULT`
  (`defaults: [... => true]` w `#[Route]`): jej 400, 401 (także z firewalla) i 503 dostają `private, no-store` bez tagów.
- Nowy publiczny endpoint katalogu = ścieżka na liście w VCL, jawny `Cache-Control`, tagi w odpowiedzi, znacznik
  trasy i unieważnianie po zmianie danych (zdarzenie i konsument wołający `StorefrontCache`). Bez tego zmiana danych
  nie będzie widoczna w sklepie przed końcem `max-age`/`s-maxage`.
- Indeks wyszukiwania (Elasticsearch, alias `products`) należy do Catalog (`docs/features/search/spec.md`). Szkic
  zostaje w indeksie jako dokument `draft`, a każde zapytanie sklepu filtruje `status: published`. Zapis dokumentu robi
  tylko `ProductIndexing::reindex` z wersją zewnętrzną (`external_gte`, znacznik czasu pobrany przed odczytem), więc
  zapis starszego odczytu po nowszym nic nie zmienia. Catalog nie importuje Cart, Pricing ani Inventory.
- Pliki (zdjęcia produktów) przez Flysystem na Garage, prywatny bucket. Nigdy na lokalny dysk kontenera.
- Upload: walidacja typu po zawartości (MIME), limit rozmiaru i wymiarów (`docs/features/catalog-admin/spec.md`).
  Oryginałów nie usuwa nic. Limity PHP są w `docker/php/conf.d/app.ini`, razem z `display_errors = Off`
  i `log_errors = On`: obraz nie ma `php.ini`, a ostrzeżenie PHP o żądaniu ponad `post_max_size` wypisane do odpowiedzi
  wysłałoby status 200 i nagłówki przed 422. Limity PHP nie działają w kernelu testów funkcjonalnych, więc testy
  symulują ich skutek (błąd uploadu, nagłówek), a prawdziwe limity sprawdza E2E na działającym stosie.
- Rozmiary obrazów są tylko w `ImageSize`; nowy rozmiar dopisuje się tylko tam.
- Adresy obrazów: jeden serwis buduje podpisane (HMAC) adresy imgproxy dla zamkniętej listy rozmiarów.
  Odpowiedzi API zwracają gotowe adresy, fronty niczego nie podpisują.
- E-maile idą przez Symfony Mailer synchronicznie, bez Messengera: `framework.mailer.message_bus: false` (inaczej
  `MailerInterface::send()` wysłałby `SendEmailMessage` na domyślną szynę `command.bus`, a błąd SMTP nie wróciłby do
  handlera i nie uruchomił ponowień transportu `events`). Opcja `timeout` DSN SMTP (`SmtpTimeoutTransportFactory`)
  ogranicza każde połączenie, odczyt i zapis gniazda, więc wysyłka kończy się dużo przed końcem lease wysyłki
  (`SmtpTimeoutWithinTheLeaseTest`). Handler e-maila zamówienia wysyła przez `NotificationSender::sendOnce` (jeden
  e-mail na zdarzenie; `docs/features/order-management/spec.md`).
- Logi przez Monolog w JSON. Correlation ID: `docs/features/hardening/spec.md` (D9); źródłem prawdy jest kod w `src/Shared/Infrastructure/Observability`.
- Sentry (`docs/features/sentry/spec.md`, `config/packages/sentry.yaml`):
  - Jeden kanał na źródło, jeden błąd to jedno zdarzenie: HTTP zgłasza `ServerErrorReporter` tylko dla statusu ≥ 500
    z `ExceptionStatus`, konsolę listener konsoli bundla, workera listener Messengera bundla tylko po ostatniej próbie,
    jawne logi aplikacji od `error` handler `sentry` w `monolog.yaml`. Nowe zgłaszanie do Sentry idzie jednym z tych
    kanałów, nie obok.
  - Nowy format danych klienta w logach albo wyjątkach wymaga wzorca w `SentryEventFilter::MASKS` i w Alloy
    (`infra/k8s/argocd/apps/monitoring.yaml`, korpus `infra/k8s/tests/log-corpus`) w tym samym PR. Test filtra ma
    kopię wierszy korpusu `api.log` i `postgres.log` (kontener api widzi tylko `apps/api`): zmiana korpusu zmienia też
    kopię.
  - Api nie przekazuje trace'a dalej: wywołania wychodzące nie niosą `sentry-trace` ani `baggage`
    (`trace_propagation_targets: []`).
- Transporty Messengera na Doctrine (`scheduled`, `payment_simulator`, `promotions`, `jobs`; ta sama tabela
  `messenger_messages` i to samo połączenie co `outbox`) trzymają wiadomości zapisane w transakcji wywołującego, więc
  przestój workera tylko opóźnia ich obsługę. W `test` są w pamięci (jak `outbox`), w `test_messaging` prawdziwe.
  Dwa workery z jednego obrazu (compose i chart, komenda i healthcheck albo sonda z tej samej listy): `worker` konsumuje
  `outbox events`, `worker-commands` `payment_simulator scheduled promotions jobs` po kolei, bez RabbitMQ (webhooki
  symulatora przed anulowaniami), więc długa kolejka wcześniejszego transportu opóźnia następne w tym samym workerze
  (`docs/features/async-reliability/spec.md`, W1). Każdy transport poza `failed` konsumuje któryś z workerów (test
  chartu). Granice promocji mają osobną
  kolejkę `promotions`, bo `ordering:seed-demo` czyści całe `scheduled`. Nowy transport: compose i chart w tym samym PR
  (`infra/k8s/CLAUDE.md`).
- **Wzorzec łańcucha partii** (transport `jobs`) to sposób zadań wywołanych zmianą w domenie i wykonywanych przez
  workera (komendy uruchamiane świadomie, jak `catalog:reindex`, `cart:purge-expired` i `pricing:reconcile-tax`, zostają
  przy swoich wzorcach): odbiorca zdarzenia w jednej transakcji `command.bus` zakłada wiersz przebiegu (`next_batch` 0,
  `cursor` pusty) i wysyła wiadomość pierwszej partii `{runId, batch}`; handler partii w jednej transakcji `command.bus`
  najpierw blokuje wiersz przebiegu (`SELECT … FOR UPDATE`), partia inna niż `next_batch` albo przebieg zakończony
  (albo nieznany) to koniec bez skutku, potem robi partię po `cursor`, zapisuje nowy `cursor` i `next_batch + 1`
  i wysyła wiadomość następnej partii w tej samej transakcji; pusta partia ustawia `finished_at`. Druga kopia partii
  czeka na blokadę i widzi nowy `next_batch`, więc partia jest zrobiona raz i następna wiadomość jest jedna
  (`CategoryReindexConcurrencyTest`, dwa procesy). Partia trwa sekundy, daleko poniżej `redeliver_timeout`. Przebieg,
  którego wiadomość trafiła do `failed`, zostaje bez `finished_at` i nikt go nie wznawia, a skutek naprawia narzędzie
  zadania (dla reindeksacji `catalog:reindex`).
- Nigdy nie edytuj migracji, która jest już w repo. Utwórz nową.
- Migracje zmieniają tylko schemat: nowe tabele, kolumny z wartością domyślną albo `NULL`, indeksy. Nie aktualizują
  danych dużych tabel i nie wołają kodu aplikacji; dane wypełnia po rolloucie krok `app:backfill` (sekcja „Komendy”). Indeks na
  dużej tabeli (`ordering_order`, `catalog_product`, `inventory_stock_item`) powstaje w osobnej migracji
  nietransakcyjnej (`isTransactional(): false`, bo `CONCURRENTLY` nie działa w transakcji; `all_or_nothing` jest
  wyłączone): `up()` sprawdza w `pg_index.indisvalid`, czy indeks o tej nazwie jest `INVALID` (zostawia go nieudane albo
  przerwane `CREATE INDEX CONCURRENTLY`), i tylko wtedy dodaje `DROP INDEX CONCURRENTLY IF EXISTS`, potem zawsze
  `CREATE INDEX CONCURRENTLY IF NOT EXISTS`. Poprawny indeks zostaje, więc ponowiony Job `migrate` przechodzi. Pozostałe
  zmiany schematu idą w zwykłej, transakcyjnej migracji obok. Wzór: `Version20261115120100IndexStockLevels`, test:
  `StockLevelIndexMigrationTest`.
- Ograniczenie CHECK na dużej tabeli migracja dodaje jako `NOT VALID` (bez skanu tabeli pod blokadą `ACCESS EXCLUSIVE`
  migracji; nowe i zmieniane wiersze są sprawdzane od razu), a osobna, późniejsza migracja robi `VALIDATE CONSTRAINT`
  (skan pod `SHARE UPDATE EXCLUSIVE`, odczyty i zapisy trwają). Wzór: `Version20261121100000AddOrderDelivery`
  i `Version20261121100100ValidateOrderDeliveryChecks`, test: `OrderDeliveryMigrationTest`.
- Mapowanie jest równe schematowi po migracjach: indeks częściowy ma w `#[ORM\Index]` warunek zapisany tak, jak zwraca
  go PostgreSQL; indeksy na wyrażeniach (mapowanie ich nie zna) narzędzia schematu pomijają. Zgodności pilnują testy
  schematu modułów (np. `OrderingSchemaTest`).
- Listy panelu (`docs/features/admin-features/spec.md`, „Sortowanie” i „Liczniki wyników”):
  - stronicowanie kursorem keyset, który niesie klucz sortu, więc kursor innego sortu to 400 przy `after`; `sort`
    z zamkniętą listą kluczy, a połączenia filtrów i sortów, których żaden indeks nie obsługuje, to 400 z naruszeniem
    pola `sort`;
  - nowy klucz sortu to nowy indeks w migracji nietransakcyjnej (reguła wyżej), wpis `#[ORM\Index]` w rekordzie
    (mapowanie równe schematowi) i test planu pierwszej strony na `RecordingConnection` (`tests/Support`; wyłączone
    `enable_seqscan` i `enable_sort`, plan ma używać indeksu bez węzła `Sort`); id rozstrzyga remisy w kierunku sortu,
    więc oba kierunki czytają ten sam indeks;
  - licznik wyników to `BoundedCounter` (`Shared\Infrastructure\Persistence`, najwyżej 10 001 wierszy): nowa lista
    dopisuje metodę `count(...)` do portu odczytu, składa warunki jednym helperem dla strony i licznika (jak `filter()`
    w `DbalBackofficeProductSource`), woła licznik tylko na pierwszej stronie (`after === null`), która ma następną;
    strona bez następnej daje `BoundedCount(count(items), false)` bez drugiego skanu. Liczniki nie zależą od sortu;
  - kolejki pulpitu liczą `BoundedCount` tymi samymi warunkami co lista, do której prowadzi link, więc idą po indeksach
    list (testy planu bez `Seq Scan`); uprawnienie kolejki sprawdza voter modułu, który ją posiada, a kolejka bez
    dostępu nie jest liczona i jest `null`. Nowa kolejka dopisuje się tym samym wzorcem (port Backoffice, zapytanie
    w module, pole `null` według votera);
  - liczba produktów nigdy nie jest `count(*)`: to szacunek `pg_class.reltuples`, więc po masowym wstawieniu produktów
    trzeba `ANALYZE catalog_product`.
- Wyszukiwanie produktów po nazwie w panelu używa rozszerzenia PostgreSQL `pg_trgm` (indeks GIN tworzony migracją).
  Rozszerzenie musi być dostępne w bazie, każda baza przechodzi przez migracje.
- Profilowanie: SPX (php-spx) jest tylko w obrazie deweloperskim (target `dev` w Dockerfile), nigdy w obrazie produkcyjnym.
  Przy problemie z wydajnością najpierw zmierz w SPX, potem optymalizuj.
- Obraz Docker (`apps/api/Dockerfile`): etap `prod` bez Composera, gita, SPX, pakietów dev i entrypointu instalującego
  zależności, z `fixtures/` i bez `tests/`; `dev` jest ostatnim etapem (compose i CI budują `target: dev`). Produkcyjny
  obraz buduje się `docker build --target prod apps/api` bez stosu i bez sekretów (cache `prod` rozgrzewa komenda
  z atrapami `SHOP_DB_*` ustawionymi tylko dla niej; w cache zostają odwołania do zmiennych środowiska, nie ich
  wartości). Konfigurację obraz czyta ze zmiennych środowiska przy starcie. Ten sam obraz uruchamia FrankenPHP, workera
  i komendy konsoli. Zmiana w `.dockerignore` albo nowy katalog z kodem potrzebnym w `prod` wymaga sprawdzenia, że
  obraz `prod` nadal się buduje i odpowiada na `/health`.

## Zgodność wersji

Wdrożenie wymienia pody po kolei (`maxUnavailable: 0`), a migracje uruchamia Job przed startem nowej wersji, więc przez
chwilę poprzednia wersja kodu działa na nowym schemacie, a nowa i poprzednia wersja workera wymieniają wiadomości.

- Migracja jest zgodna wstecz z poprzednią wersją kodu: poprzednia wersja musi działać na schemacie po migracji. Zmianę
  niezgodną (usunięcie lub zmiana nazwy kolumny albo tabeli, nowe ograniczenie `NOT NULL` bez wartości domyślnej,
  zmiana typu) rozbij na dwie wersje: najpierw dodaj nowe i zacznij je zapisywać, w kolejnej wersji usuń stare.
  Schemat jest zgodny wstecz tylko z poprzednią wersją: cofnięcie o więcej wersji wymaga kopii zapasowej.
- Wiadomości Messengera (zdarzenia w `outbox` i `events`, komendy w `scheduled`, `payment_simulator`, `promotions` i `jobs`)
  są zgodne w obie strony między kolejnymi wersjami: nowa wersja czyta wiadomości zapisane przez poprzednią (kolejka
  może je jeszcze mieć) i poprzednia czyta wiadomości nowej (stary worker działa do końca wymiany). Transporty Doctrine
  używają serializera PHP, a `unserialize` nie woła konstruktora: w klasie `final readonly` dodane pole zostaje
  niezainicjalizowane, a pole, którego klasa nie ma, kończy dekodowanie błędem. Dlatego pól klasy wiadomości ani
  zdarzenia domenowego nie dodajemy, nie usuwamy i nie zmieniamy: nowy kształt to nowa klasa, którą wersja z jej
  handlerem tylko obsługuje, a nadawać zaczyna dopiero kolejna wersja (poprzednia nie zdekoduje wiadomości nieznanej
  jej klasy). Nie zmieniaj nazw klas wiadomości, stempli i transportów. Zasada „pola dodawaj jako opcjonalne” jest
  prawdziwa tylko dla JSON (`events`), nie jest zgodą na zmianę pól.
- `MessageVersionCompatibilityTest` pilnuje tego na zapisach w `tests/Fixtures/MessageSerialization`: każda klasa
  z `routing` w `messenger.yaml` i każda implementacja `DomainEvent` musi mieć zapis i dekodować się z zainicjalizowanymi
  polami. Nowa klasa wiadomości lub zdarzenia dostaje zapis w tym samym PR (sposób: komentarz testu). Zapisy się
  dopisuje, nigdy nie nadpisuje; zapis klasy usuwa się dopiero razem z jej handlerem, w wersji po tej, która przestała
  ją nadawać.

## Testy

- Logika domeny i aplikacji: testy jednostkowe z adapterami in-memory. Mają być szybkie (sekundy),
  bo uruchamiasz je przy każdej zmianie.
- Infrastruktura (Doctrine, Elasticsearch, RabbitMQ, Garage): testy integracyjne na prawdziwych usługach.
- Każdy endpoint: co najmniej jeden test funkcjonalny, w tym przypadek bez autoryzacji i z błędnymi danymi.
- Dane testowe przez fabryki (Foundry), a nie tablice budowane ręcznie w każdym teście.
- Poprawka błędu zaczyna się od testu, który ten błąd odtwarza i nie przechodzi.
- Handler, który po sprawdzeniu woła port zewnętrzny (dostawca płatności, mailer, kolejka), ma test deterministycznego
  przeplotu: konkurent zatwierdza między sprawdzeniem a blokadą (wzór `StartPaymentReplayRaceTest`), a asercja dotyczy
  wywołań fałszywego portu, nie tylko stanu bazy.
- Testy współbieżności forkują uczestników (`ConcurrentCommands`, `ParallelProcesses`, webhook w
  `StartPaymentConcurrencyTest`). Rodzic woła przed forkiem `gc_collect_cycles()`: niezebrane cykle (np. połączenie AMQP po
  teście z kernelem `test_messaging`) dziecko odziedziczyłoby, a `Kernel::shutdown()` w jego procesie uruchomiłby ich
  destruktory na gnieździe wspólnym z rodzicem i czekał ~30 s na odpowiedź brokera.
- Kernel `test_messaging` ma krótsze ponowienia transportu `events`: pierwsze opóźnienie 2 ms zamiast 1 s i `max_delay`
  600 ms zamiast 300 s (liczba prób, mnożnik i proporcja opóźnień jak w produkcji, pilnuje tego
  `EventsRetryStrategyTest`), a testy czekające na ponowienie śpią 300 ms.
  Worker w testach pyta o wiadomości co 50 ms (`--sleep`). Testy, które dowodzą limitów czasu z produkcji (sonda 1 s w
  `ProbesTest`, timeouty DSN Redisa, limity w `DashboardTimingTest` i podobne), czekają ich prawdziwy czas; test wolnego
  sklepu (`HttpStorefrontCacheTest`) używa klienta z pomniejszonymi limitami. Test, który czeka na powrót ponowienia do
  kolejki, odpytuje ją co 20 ms (`awaitEventsInQueue`, do 3 s) zamiast spać.
- Wywołania HTTP do symulatora płatności w `test` idą przez `KernelForwardingHttpClient` (`tests/Support`: osobna
  instancja kernela z własnym `EntityManager`, więc `flush` i błąd symulatora nie dotykają `UnitOfWork` wywołującego);
  testy błędów dostawcy podmieniają serwis na klienta ze stałymi odpowiedziami.
- Testy generatora danych demo biegną na `shop_test` w transakcji testu, bez `bin/seed-demo` i bez czyszczenia cache
  sklepu (worker i Varnish nie obsługują `shop_test`). Testy kont na Keycloaku stosu używają tymczasowych realmów
  (`KeycloakTestRealms`, `demo-data-test-*`, usuwane po teście), nigdy realmów `shop` i `backoffice`, i ziarna losowanego
  na test (id kont są globalne w Keycloaku).

## Czego nie robić

- Nie umieszczaj logiki biznesowej w kontrolerach, listenerach Doctrine ani w handlerach wiadomości.
  Handler orkiestruje, decyzje podejmuje domena.
- Nie wołaj innego contextu przez jego repozytorium ani encje.
