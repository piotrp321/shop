# CLAUDE.md – apps/api

Symfony 8, PHP 8.5, Doctrine ORM, PostgreSQL. DDD + hexagonal.
Zasady wspólne dla całego repo są w głównym `CLAUDE.md`, opis domeny w `docs/vision.md`.

## Komendy

Uruchamiaj z katalogu głównego przez `make -C apps/api <cel>`:
- `check` – PHPStan, Deptrac, PHP CS Fixer
- `test` – wszystkie testy, `test-unit` – tylko jednostkowe (bez Postgresa, Elasticsearch i RabbitMQ); dodatkowe
  argumenty PHPUnit w `PHPUNIT_ARGS`, np. `make -C apps/api test-unit PHPUNIT_ARGS='--filter CartTest'`
- `init` – migracje bazy deweloperskiej i `catalog:search-index:setup` (alias `products` i indeks, jeśli ich nie ma);
  idempotentny, wywołuje go `make up` po starcie usług
- `init-test` – migracje bazy `shop_test` i indeks testowy `test_products` tworzony od nowa
  (`catalog:search-index:setup --recreate`); wywołuje go `test` przed testami
- `migrate`, `migration` – uruchomienie i wygenerowanie migracji
- dane demo ładuje `make seed` z głównego `Makefile`: najpierw `catalog:seed-demo` (katalog), potem
  `pricing:seed-demo`, który czyści ceny i ich historię i ustawia cenę (źródło `seed`) każdemu wariantowi demo według
  wzorców kluczy wariantów z `fixtures/prices.yaml` (klucz z Catalog `ListVariantIdentities`:
  `<slug produktu>/<slug etykiety>`, wariant domyślny `<slug produktu>/standard`; `*` dopasowuje też `/`; pierwszy
  pasujący wzorzec w kolejności pliku; wariant bez wzorca kończy seed błędem bez zmian w danych); potem
  `payment-simulator:seed-demo`, który w jednej transakcji opróżnia (`TRUNCATE`) tabelę transakcji symulatora płatności
  i usuwa jego kolejkę `payment_simulator` (`DELETE FROM messenger_messages WHERE queue_name = 'payment_simulator'`;
  `outbox`, `scheduled`, `events` i `failed` zostają; błąd bazy wycofuje obie zmiany i kończy komendę wyjątkiem); potem
  `ordering:seed-demo`, który usuwa wszystkie zamówienia i ich linie jednym `TRUNCATE` (sekwencja numerów zostaje,
  luki są dopuszczalne) i kolejkę `scheduled` ich wiadomości `ExpireUnpaidOrder` (`DELETE FROM messenger_messages
  WHERE queue_name = 'scheduled'`; `outbox` i `failed` zostają); na końcu `inventory:seed-demo`, który czyści stany,
  rezerwacje i historię stanów i ustawia stan fizyczny każdemu wariantowi demo według wzorców kluczy wariantów z `fixtures/stock.yaml` (ten sam
  klucz z `ListVariantIdentities`, ta sama składnia i zasada pierwszego pasującego wzorca; stan > 0 to przyjęcie ze
  źródłem `seed` i kluczem `seed:<variantId>`, stan 0 to rekord stanu bez wpisu historii; wariant bez wzorca kończy
  seed błędem bez zmian w danych), więc po seedzie nie ma zamówień ani ich rezerwacji. Wszystkie pięć komend
  w `prod` wymagają `--force-reset`. Na końcu `make seed` woła `storefront:cache:purge-all` (zdarzenia cen i stanów
  ze źródła `seed` niczego nie unieważniają)
- `storefront:cache:purge-all` – unieważnia cały katalog w obu cache sklepu: purge tagu `catalog` w Varnishu, potem
  rewalidacja tagu `catalog` w sklepie. Każdą warstwę próbuje do 5 razy co 5 s (parametr
  `app.storefront_cache.retry_delay`; testy: 0), po nieudanym purge nie rewaliduje. Kod wyjścia: 0 – gotowe, 2 – warstwa
  nie odpowiedziała 200 po ostatniej próbie (np. niedostępny Varnish albo sklep), z komunikatem bez sekretów
- `pricing:reconcile-tax [--apply]` (przez `make -C apps/api shell` albo `docker compose exec api bin/console`) –
  narzędzie uruchamiane świadomie, np. po zmianie stawki w `PolishVatRates`; nie wywołuje go `make init`, `make up`
  ani `make seed`. Przechodzi wszystkie ceny wariantów partiami po `variant_id` razem z wariantami Catalog
  (`ListVariantIdentities`) i wypisuje w jednej linii każdą cenę ze stawką inną niż bieżąca stawka jej klasy albo
  z klasą inną niż klasa produktu wariantu w Catalog (cena wariantu, którego nie ma w Catalog, zachowuje swoją
  klasę).
  Z `--apply` przelicza je (`reprice`: to samo brutto, źródło `tax_reconciliation`), każdą w osobnej transakcji;
  zapis odrzucony przez równoległą zmianę ceny ponawia raz na cenie odczytanej od nowa (świeży EntityManager), drugi
  konflikt zostaje w raporcie. Kod wyjścia: 0 – wszystko spójne albo naprawione, 1 – zostały rozbieżności (bez
  `--apply` znalezione; z `--apply` nienaprawione: dwa konflikty albo klasa bez stawki), 2 – przerwane błędem
  (np. niedostępna baza), naprawione wcześniej ceny zostają zmienione
- `cart:purge-expired [--batch-size=1000]` (przez `make -C apps/api shell` albo `docker compose exec api bin/console`) –
  narzędzie uruchamiane świadomie (ręcznie albo z crona); nie wywołuje go `make init`, `make up` ani `make seed`,
  a w MVP nie ma harmonogramu. Usuwa koszyki z `expires_at` w przeszłości (gości w każdym statusie i klientów) razem
  z liniami (kaskada FK), partiami po `--batch-size` (domyślnie 1000) id po indeksie `expires_at`. Każda partia to
  osobna komenda `PurgeExpiredCarts` na `command.bus`, czyli osobna transakcja, powtarzana do partii pustej;
  koszyk zablokowany przez równoległą zmianę jest pomijany (`FOR UPDATE SKIP LOCKED`), a odnowiony nie jest usuwany.
  Wypisuje każdą partię i sumę. Kod wyjścia: 0 – gotowe (także gdy nic nie wygasło), 1 – rozmiar partii nie jest
  dodatnią liczbą całkowitą (bez zmian), 2 – przerwane błędem (np. niedostępna baza), usunięte wcześniej partie
  zostają usunięte
- `openapi` – eksport specyfikacji do `openapi.json` (sklep: endpointy publiczne i klienta)
  oraz `openapi-admin.json` (panel: `/api/v1/admin`). Zrzut idzie przez pliki tymczasowe, więc nieudany eksport
  kończy się błędem i nie zmienia ani nie obcina plików. CI sprawdza, że commitowane pliki są aktualne

## Architektura

- Kod jest podzielony na bounded contexty: `src/<Context>/{Domain,Application,Infrastructure,UI}`.
- Domain nie zależy od Symfony, Doctrine ani żadnego frameworka. Tylko czysty PHP.
- Domena nie jest mapowana przez Doctrine. Persystencja przez rekordy w `<Context>/Infrastructure/Persistence/Doctrine`
  mapowane atrybutami, z relacjami między rekordami. Repozytoria mapują agregat ↔ rekord, a agregat odtwarzają
  przez `reconstitute()`, które nie zapisuje zdarzeń.
- Wyjątek: Inventory. Stan, rezerwacje i historię zapisuje i czyta przez DBAL na połączeniu Doctrine, w transakcji
  `command.bus` (jawne `SELECT … FOR UPDATE`, `INSERT … ON CONFLICT DO NOTHING`, `UPDATE` z wersją). Rekordy Doctrine
  Inventory są tylko definicją schematu dla `doctrine:schema` i migracji, repozytoria ich nie używają. Powody:
  rollback do savepointu przy zagnieżdżonym dispatchu nie czyści `UnitOfWork`, więc `flush` wywołującego zapisałby
  wycofane zmiany; błąd bazy zamyka wspólny `EntityManager`; `find()` z `PESSIMISTIC_WRITE` na encji już obecnej
  w identity map nie odświeża jej stanu (ryzyko sprzedaży ponad stan); `ON CONFLICT` i tak wymaga SQL.
- Wyjątek: Cart. Zapisuje przez rekordy Doctrine, ale każda komenda zaczyna od blokady wiersza koszyka przez ORM
  (`find()` z `PESSIMISTIC_WRITE`). Rekord już obecny w identity map jest odświeżany pod blokadą
  (`refresh()` z `PESSIMISTIC_WRITE`), a po nim jego linie: kaskada `refresh` Doctrine czytałaby (i blokowała) linie
  przed wierszem koszyka, w odwrotnej kolejności niż inne transakcje. Koszyk klienta zakłada
  `INSERT … ON CONFLICT DO NOTHING` przed blokadą (`lockOrCreateForCustomer`), bo naruszenie unikalności przy dwóch
  równoległych pierwszych dodaniach zamknęłoby `EntityManager`; gdy koszyk zniknął między `INSERT` a blokadą
  (usunął go `cart:purge-expired`), `lockOrCreateForCustomer` ponawia `INSERT` i blokadę raz. Wyjątek od blokady przez
  ORM: `PurgeExpiredCarts` usuwa partię jednym `DELETE` przez DBAL z `FOR UPDATE SKIP LOCKED` (bez ORM i repozytorium).
  Dwie blokady naraz bierze tylko scalenie
  (`MergeGuestCart`), zawsze w kolejności: koszyk gościa, potem koszyk klienta, i zapisuje oba w jednej transakcji,
  więc nie ma zakleszczeń, a koszyk gościa jest `merged` tylko po udanym przeniesieniu linii. Scalenie bez
  przeniesienia (`nothing_to_merge`, `already_merged`) czyta koszyk klienta bez blokady, więc trzyma tylko blokadę
  wiersza wskazanego jako koszyk gościa (może to być koszyk innego klienta). Odczyty (`GetCart`, `GetCartSummary`) idą przez DBAL,
  bez blokady i bez transakcji. Linie są zapisywane w miejscu po wariancie, więc usunięcie i ponowne dodanie wariantu
  w jednej zmianie nie trafia na unikalny indeks `(cart_id, variant_id)`.
- Ordering składa zamówienie (`PlaceOrder`) w jednej transakcji `command.bus`: komendy Cart (`LockCartForOrder`,
  `ClearCartAfterOrder`) i Inventory (`ReserveStock` z kluczem `orderId`) idą przez porty Ordering jako zagnieżdżony
  dispatch na tym samym połączeniu (savepoint), a ceny (Pricing `GetCurrentPrices`) i nazwy (Catalog
  `GetVariantSummaries`) przez `query.bus`. Zasady:
  - handler nigdy nie łapie wyjątku zagnieżdżonej komendy, żeby pisać dalej: każdy błąd wycofuje całą transakcję
    (koszyk, rezerwację, zamówienie, outbox). Adapter tłumaczy tylko niedobór Inventory (`InsufficientStock`) na
    `InsufficientStockForOrder` i rzuca go dalej, inne wyjątki przekazuje bez zmian;
  - zamówienie zapisuje się na końcu (po rezerwacji), więc `flush` komend zagnieżdżonych nie zapisuje niczego
    z Ordering; Ordering zapisuje przez rekordy Doctrine według ogólnej zasady;
  - kolejność blokad: wiersz koszyka (`LockCartForOrder`), potem wiersze stanów (`lockMany` Inventory, rosnąco po
    `variant_id`). Inne komendy Cart blokują tylko koszyki, Inventory tylko stany i rezerwacje, więc nie ma cyklu;
  - idempotencja: zamówienie z tym samym `Idempotency-Key` i tym samym odciskiem żądania (koszyk i treść) jest
    zwracane jako powtórzenie przed blokadą koszyka i jeszcze raz pod nią; inny odcisk to `IdempotencyKeyReused` (409),
    także z unikalnego indeksu `idempotency_key` przy równoległym złożeniu z innego koszyka;
  - numer zamówienia (`R-` i wartość od 100001) bierze `nextval('ordering_order_number_seq')` przez DBAL. Sekwencja
    nie jest częścią transakcji: wycofane złożenie zostawia lukę. Schemat Doctrine dostaje sekwencję od listenera
    `OrderNumberSequenceSchema` (mapowanie nie wyraża sekwencji poza id), więc diff migracji jej nie usuwa.
- Cykl płatności zamówienia (Ordering, `docs/features/payment/spec.md`): zamówienie ma termin płatności (`placedAt`
  + 30 min), a zamówienie gościa losowy `accessToken` (32 bajty w base64url, 43 znaki, generuje `PlaceOrder`; zwracany
  w `OrderView`, klient ma `null`). Przejścia `new → paid` i `new → cancelled` to metody agregatu `Order` (bez Symfony
  Workflow) i szereguje je wersja zamówienia: przegrany zapis kończy się konfliktem wersji i ponowieniem, więc nie ma
  przejścia `cancelled → paid` ani zwolnienia rezerwacji opłaconego zamówienia.
  - `markPaid` decyduje po czasie wykonania płatności u dostawcy (`executedAt ≤ paymentDeadline` opłaca), nie po czasie
    odebrania webhooka; zwraca enum domenowy (także `paidAfterDeadline`, `alreadyPaid`, `paidByAnotherPayment`,
    `orderCancelled` bez zmian), zła kwota albo waluta to `InvalidOrder`.
  - Dla Payment (jedyny context zależny od Ordering) wszystko jest w `Ordering_Application`: zapytanie
    `GetOrderForPayment(orderId, OrderAccess)` (`query.bus`) zwraca `OrderForPayment` albo `null` przy każdym braku
    dostępu (nieznane zamówienie, id spoza UUID, zły token porównywany `hash_equals`, cudze zamówienie, inny rodzaj
    właściciela); komenda `MarkOrderPaid` (`command.bus`, zagnieżdżona w transakcji webhooka) zwraca
    `MarkOrderPaidResult`, a nieznane zamówienie i konflikt wersji to `OrderToPayNotFound` i `OrderChangedConcurrently`.
  - Anulowanie nieopłaconego: `PlaceOrder` w swojej transakcji wysyła `ExpireUnpaidOrder(orderId)` z `DelayStamp` na
    termin + 5 min (port `UnpaidOrderExpiry`; powtórzenie złożenia nic nie wysyła). Handler w transakcji `command.bus`
    przy anulowaniu najpierw zwalnia rezerwację (port `StockReservations::release`, Inventory `ReleaseReservation`,
    zagnieżdżony dispatch), potem zapisuje zamówienie z `OrderCancelled` w outboxie; wiadomość za wczesna (zegar,
    zaokrąglenie `available_at`) wraca na termin + 5 min + 1 s, opłacone albo anulowane zamówienie nic nie zmienia,
    nieznane (po `make seed`) to wpis w logu. Konflikt wersji i błędy Inventory (`ReservationNotFound`,
    `ReservationStateConflict`) idą dalej: ponowienia transportu, potem `failed` (zamówienie zostaje `new`).
  - E-maile „Płatność przyjęta” (`SendPaymentReceived` po `OrderPaid`) i „Zamówienie … anulowane”
    (`SendOrderCancelled` po `OrderCancelled`) działają jak `SendOrderConfirmation` (poniżej), każdy z własnym znacznikiem
    (`paymentNotificationSentAt`, `cancellationNotificationSentAt`); port `OrderStatusMailer`, adapter
    `SymfonyOrderStatusMailer`, treści `PaymentReceivedText` i `OrderCancelledText`. E-mail z potwierdzeniem podaje
    termin płatności w strefie `Europe/Warsaw`.
- PaymentSimulator (`src/PaymentSimulator`, `docs/features/payment/spec.md`) udaje dwóch zewnętrznych dostawców płatności
  (`demo-transfer`, `demo-card`) w tym samym API, ale poza `/api/v1` (`/payment-simulator/...`, bez firewalla, poza
  dokumentami OpenAPI) i poza contextami: płaski moduł (kontrolery, encja Doctrine, komendy z handlerami, podpisy), jedna
  warstwa Deptrac `PaymentSimulator` z dostępem tylko do `Native`, `Vendor` i `Shared_Infrastructure`; żaden context nie
  zależy od niego, on od żadnego. Z sklepem rozmawia wyłącznie przez HTTP (api ↔ worker ↔ przeglądarka), jak z prawdziwym
  dostawcą.
  - Tabela `payment_simulator_transaction` (`Transaction`, UUID v4, `provider`, `reference`, kwota w groszach, adresy
    powrotu, `expires_at`, `status` `open` | `paid` | `rejected` | `cancelled`, `decided_at` = czas wykonania płatności,
    który webhook podaje jako `paidAt` / `completedAt`); CHECK-i w migracji pilnują statusów, kształtu adresów dostawcy
    i `decided_at` dokładnie dla `paid` i `rejected`.
  - API dostawców (z `PAYMENT_DEMO_*_API_KEY`, `hash_equals`, zły klucz to 401): `demo-transfer` Bearer, kwota w groszach,
    czasy RFC 3339, `POST …/demo-transfer/api/transactions` → 201 `{transactionId, paymentUrl}` i `…/{id}/cancel` →
    200 `{status: "CANCELLED"}` albo 409 `{status: "PAID" | "REJECTED"}`; `demo-card` HTTP Basic (`merchant`, klucz), kwota
    `"123.45"`, czas uniksowy, `POST …/demo-card/v1/checkout-sessions` → 200 `{id, url}` i `…/{id}/expire` → 200
    `{status: "expired"}` albo 409 `{status: "complete" | "failed"}`. Zła treść to 400 `{error, field}`, nieznane id 404.
    Adres strony płatności buduje `PAYMENT_SIMULATOR_PUBLIC_URL`.
  - Decyzja klienta (`DecideTransaction`) i anulowanie (`CancelTransaction`) to komendy na `command.bus`, każda blokuje
    wiersz transakcji (`SELECT … FOR UPDATE` przez ORM; encja już w identity map jest odczytywana pod blokadą przez
    `refresh()`), więc idą po kolei i transakcja kończy w jednym stanie: anulowana nigdy nie zostanie opłacona,
    opłaconej nie da się anulować, druga decyzja nic nie zmienia. Zastosowana decyzja zapisuje status, `decided_at` i
    wiadomość `DeliverSimulatedWebhook` (transport `payment_simulator`) w jednej transakcji.
  - Strona płatności `GET|POST /payment-simulator/{provider}/pay/{id}`: HTML bez JS i bez nowych zależności (wszystkie
    wartości escapowane), napis „To symulacja…”, przyciski „Zapłać” i „Odrzuć płatność”, link „Wróć do sklepu”;
    zamknięta (rozstrzygnięta, anulowana, po `expires_at`) pokazuje „Ta płatność jest już zamknięta.” bez przycisków;
    nieznane id to HTML 404. Formularz niesie token CSRF (HMAC-SHA256 id transakcji sekretem webhooków dostawcy);
    zły albo brak tokenu to 403 bez zmian, poprawny `POST` daje 303 na adres powrotu (`demo-card`: `successUrl` po
    płatności, `cancelUrl` w pozostałych przypadkach).
  - `DeliverSimulatedWebhook` (handler w workerze): podpisany `POST` na `PAYMENT_WEBHOOK_BASE_URL` +
    `/api/v1/payments/webhooks/<dostawca>`. `demo-transfer`: `X-Signature` = hex HMAC-SHA256 surowej treści;
    `demo-card`: `Card-Signature: t=<unix>,v1=<hex HMAC-SHA256("<t>.<treść>")>` z `t` z chwili każdej próby. Identyfikator
    zdarzenia (`evt_<id transakcji bez myślników>`) jest stały, więc ponowienie trafia na deduplikację sklepu. 2xx i 4xx
    kończą dostawę (4xx z logiem). Żądanie czeka na sklep najwyżej 5 s (`timeout`), żeby milczący sklep nie blokował
    workera, który obsługuje kolejki po kolei. 5xx i inny status rzucają `WebhookDeliveryFailed`, a błąd sieci (także
    przekroczenie czasu) wyjątek `TransportExceptionInterface` klienta HTTP, który handler przepuszcza; w obu
    przypadkach transport ponawia. Transakcja, której już nie ma (po `make seed`), kończy dostawę bez żądania.
  - Klucze API i sekrety webhooków (`PAYMENT_DEMO_TRANSFER_API_KEY`, `…_WEBHOOK_SECRET`, `PAYMENT_DEMO_CARD_API_KEY`,
    `…_WEBHOOK_SECRET`) generuje `infra/scripts/init-env.sh` do `.env.local`; brakujące dopisuje do istniejącego pliku
    (jedyne klucze, które skrypt dopisuje; brak jakiegokolwiek innego kończy się błędem i odtworzeniem stosu). Adresy
    `PAYMENT_PROVIDER_BASE_URL`, `PAYMENT_SIMULATOR_PUBLIC_URL` i `PAYMENT_WEBHOOK_BASE_URL` (nie sekrety) są w
    `infra/docker-compose.yml` dla `api` i `worker` oraz w `phpunit.dist.xml`. Skrypt testuje
    `infra/scripts/test-init-env.sh` (`make init-env-test`, w CI).
  - Wszystkie wywołania HTTP między sklepem a symulatorem idą przez serwis `app.payment_http_client` (w produkcji
    `http_client`). W środowisku `test` to `KernelForwardingHttpClient` (`tests/Support`): `MockHttpClient`, który
    przekazuje żądanie do osobnej instancji kernela (własny kontener i `EntityManager`; to samo połączenie DAMA i baza
    `shop_test`), więc `flush` i błąd symulatora nie dotykają `UnitOfWork` wywołującego; testy błędów dostawcy podmieniają
    serwis na klienta ze stałymi odpowiedziami. Wektor podpisów do wspólnych testów symulatora i adapterów Payment to
    `WebhookSignatureVector` (`tests/Support`).
  - Symulator działa w kontenerze API, więc wywołanie go przez HTTP z tego samego kontenera zajmuje drugi wątek
    FrankenPHP: przy tylu równoległych wywołaniach, ile wątków, grozi samoblokada (opis w spec, „Ryzyka”); przed testami
    obciążeniowymi płatności symulator przechodzi do osobnej usługi.
- E-mail z potwierdzeniem zamówienia wysyła handler zdarzenia `SendOrderConfirmation` (`event.bus`, transport `events`,
  po `OrderPlaced` z outboxa). Wysyłka idzie przez Symfony Mailer (`symfony/mailer`) synchronicznie, bez Messengera:
  `framework.mailer.message_bus: false` (inaczej `MailerInterface::send()` wysłałby `SendEmailMessage` na domyślną
  szynę `command.bus`, a błąd SMTP nie wróciłby do handlera i nie uruchomił ponowień transportu `events`). Konfiguracja
  w zmiennych środowiska: `MAILER_DSN` (`smtp://mailpit:1025`) i `MAILER_FROM` (`Rytm <zamowienia@rytm.localhost>`),
  ustawianych w `infra/docker-compose.yml` dla `api` i `worker` (testy i CI biorą je z serwisu `api`; `phpunit.dist.xml`
  ma te same wartości domyślne). Treść składa `OrderConfirmationText` (Ordering Application), a port
  `OrderConfirmationMailer` ma adapter `SymfonyOrderConfirmationMailer` w Ordering Infrastructure. Handler najpierw
  wysyła, potem zapisuje `confirmationSentAt` z wersją, bez transakcji wokół SMTP: awaria między jednym a drugim albo
  równoległe dostarczenie tego samego zdarzenia może dać drugi e-mail (konflikt wersji przy zapisie znacznika kończy
  się wyjątkiem i ponowieniem, które widzi znacznik i nic nie wysyła).
- Warstwa Application: handlery komend i zapytań na szynach Symfony Messenger, porty jako interfejsy.
- UI: kontrolery HTTP i komendy konsoli. Tylko tłumaczą wejście na komendę/zapytanie i wynik na odpowiedź.
- Context nigdy nie importuje Domain innego contextu. Używaj zdarzeń domenowych albo portu w warstwie aplikacji.
- Brak wspólnych value objectów między contextami. Każdy context ma własne (np. `Price` w Pricing i `Amount` w Payment).
- Deptrac pilnuje tych granic. Jeśli Deptrac zgłasza błąd, popraw projekt, nie edytuj `deptrac.yaml`.

## Przepisy Fleksa

- Przepis `symfony/framework-bundle` tworzy `AGENTS.md`, a `symfony.lock` go wymienia. Plik jest w `.gitignore`
  (nie da się go zacommitować przez pomyłkę), a zasady dla agenta są w tym `CLAUDE.md`. Po `composer require`,
  `composer update` i `composer recipes:update` usuń odtworzony `AGENTS.md` i przejrzyj resztę zmian przepisów.

## Reguły modelu

- Pieniądze zawsze jako liczba całkowita w groszach plus waluta, nigdy float. Zaokrąglanie VAT jawnie, w jednym miejscu.
- Agregaty edytowane równolegle (zamówienie, stan magazynowy, cena) mają optimistic locking (pole wersji): edycja
  z widoku sprawdza wersję, na której się opiera. Wyjątek: atomowe operacje na stanie magazynowym (przyjęcie,
  rezerwacja, zwolnienie, zatwierdzenie) nie sprawdzają wersji z widoku, tylko blokują wiersze (`SELECT … FOR UPDATE`
  w `StockItemRepository::lockMany`, w kolejności rosnącego `variant_id`; przyjęcie i korekta wariantu bez stanu
  zakładają wiersz przez `lockOrCreate`, `INSERT … ON CONFLICT DO NOTHING` przed blokadą) i czytają stan od nowa po
  uzyskaniu blokady; zapis i tak podnosi wersję. Korekta z panelu blokuje wiersz i dodatkowo sprawdza wersję z widoku.
- Koszyk nie ma optimistic locking ani wersji z widoku: zmiany szereguje blokada wiersza koszyka, dodanie zwiększa
  ilość, a zmiana ilości z nieaktualnej karty nadpisuje nowszą (ostatni zapis wygrywa).
- Zdarzenia domenowe zapisywane w transactional outbox w tej samej transakcji co agregat, potem wysyłane do RabbitMQ.
  Kto je publikuje: handler komendy (`releaseEvents()` agregatu i `DomainEventPublisher` w `command.bus`; Catalog
  `ProductCreated`, Ordering `OrderPlaced`, `OrderPaid`, `OrderCancelled`), gdy jedyną drogą zapisu jest ta komenda.
  Gdy agregat ma wiele ścieżek zapisu, także poza `command.bus` (Pricing: panel, seed, `pricing:reconcile-tax`; Inventory: panel, seed, port
  rezerwacji w zagnieżdżonym dispatchu), publikuje je `save` repozytorium w transakcji zapisu (`VariantPriceChanged`
  w `DoctrineVariantPriceRepository`, `StockLevelChanged` w `DoctrineStockItemRepository`), więc żadna ścieżka go nie
  pominie, a odrzucony albo wycofany zapis nie zostawia zdarzenia.
- Odbiorcy zdarzeń muszą być idempotentni: to samo zdarzenie może przyjść więcej niż raz.
- Zamówienie przechowuje kopię cen, rabatów i adresu z chwili złożenia, nie referencje.
- Elasticsearch to tylko model odczytu. Nigdy nie podejmuj na jego podstawie decyzji biznesowych.

## API

- REST, JSON, wersjonowane pod `/api/v1`.
- Wejście przez DTO z Symfony Validator. Domena dodatkowo pilnuje swoich niezmienników. Złe parametry zapytania
  (`#[MapQueryString]`) to 400, zła treść żądania (`#[MapRequestPayload]`, tylko JSON, typy bez rzutowania) to 422;
  oba z listą `violations`.
- Dokumentacja OpenAPI z kodu (NelmioApiDocBundle). Każdy endpoint ma opisane żądanie, odpowiedzi i błędy.
- Autoryzacja: JWT z Keycloak, walidowany w authenticatorze Symfony. Dwa firewalle:
  `/api/v1/admin/...` przyjmuje tylko tokeny z realmu `backoffice` (pracownicy, `Staff`),
  reszta API tylko z realmu `shop` (klienci, `Customer`). Sprawdzany jest wystawca tokena.
- Nie twórz wspólnej klasy `User` dla klientów i pracowników. To osobne byty.
- Uprawnienia w voterach. Pracownik: na podstawie ról z tokena (`admin`, `support`, `warehouse`, `marketing`).
  Klient: tylko własne dane (właściciel zasobu = `sub` z tokena).
- Zmiany z panelu zapisują identyfikator pracownika (`sub` z tokena realmu `backoffice`).
- Błędy w formacie RFC 9457 (problem details). `type` to domyślnie `about:blank`. Wyjątek projektu, którego przyczynę
  klient ma rozróżniać maszynowo, implementuje `App\Shared\Domain\Exception\TypedProblem` (`problemType()`) i trzyma
  wartość w stałej `TYPE`; subscriber wpisuje ją do odpowiedzi, a Shared nie zna contextów. Format wartości:
  `tag:piotrp321.github.io,2026:shop/problems/<przyczyna>` (identyfikator, nie adres). Dziś mają je pięć 409 Ordering
  (schemat OpenAPI `OrderConflictProblem` wylicza ich wartości w `enum`; nowy typ dopisz też tam).
- `detail` w błędzie ma tylko wyjątek projektu: implementuje znacznik z `App\Shared\Domain\Exception`
  (`NotFound` → 404, `Conflict` → 409, `InvalidInput` → 422, goły `ProblemException` → 422). Wyjątek: znacznik
  `Unavailable` (zależność nieosiągalna albo przegrany wyścig, który ponowienie rozwiązuje) to 503 bez `detail`, jak
  każdy 5xx: klient może tylko spróbować później, a komunikat wyjątku zostaje w logu. Domena nie zna statusów HTTP,
  mapowanie jest w `Shared\Infrastructure\Http`. Komunikat bez danych wewnętrznych i wejścia użytkownika
  (dotyczy też komunikatów naruszeń własnych constraintów). Wyjątki frameworka dostają stały ogólny opis.
  Subscriber rozpakowuje tylko `HandlerFailedException` z jednym wyjątkiem, `previous` poza tym ignoruje.
- Operacje nieidempotentne (złożenie zamówienia, rozpoczęcie płatności, przyjęcie magazynowe) wymagają nagłówka
  `Idempotency-Key`. Brak albo zły format klucza to 400 z naruszeniem nagłówka. Przyjęcie z tym samym kluczem i tą samą
  treścią (wariant, ilość, notatka) nic nie zmienia i zwraca 200 z bieżącym stanem zamiast 201; ten sam klucz z inną
  treścią albo dla innego wariantu to 409. Klucze przyjęć są w historii stanów na zawsze (unikalny indeks).
  Złożenie zamówienia (`POST /api/v1/orders` gościa, `POST /api/v1/me/orders` klienta) z tym samym kluczem, z tego
  samego koszyka (id koszyka gościa z treści albo `sub` z tokena) i z tą samą treścią niczego nie zmienia i zwraca 200
  z tym samym zamówieniem zamiast 201, także gdy koszyk gościa w międzyczasie wygasł albo został scalony; ten sam klucz
  z innego koszyka, od innego klienta albo z inną treścią to 409 `IdempotencyKeyReused` bez żadnych danych zamówienia,
  które go użyło. Klucze zamówień są w `ordering_order` na zawsze (unikalny indeks).
- Rate limiting (Symfony RateLimiter na Redis) na logowaniu, checkout i webhookach.
- Webhooki zewnętrzne: weryfikacja podpisu i deduplikacja po identyfikatorze zdarzenia.

## Infrastruktura

- Varnish (`infra/varnish/default.vcl`) stoi tylko przed publicznymi odczytami `GET` katalogu: `/api/v1/products`,
  `/api/v1/products/*`, `/api/v1/categories`, `/api/v1/product-offers`. Czyta przez niego wyłącznie serwer sklepu
  (`API_CATALOG_URL`); inne ścieżki, żądania z `Authorization` albo `Cookie` i metody inne niż `GET`/`HEAD` Varnish
  przepuszcza do API bez cache. TTL bierze tylko z `Cache-Control` odpowiedzi; bez nagłówka, z `private` albo
  `no-store` nic nie trzyma. Endpointy z autoryzacją, koszyk i checkout zawsze `private, no-store`.
- Tagi cache (xkey) bez FOSHttpCacheBundle. Słownik: `catalog` (każdy odczyt katalogu) i `product-<id>` (UUID małymi
  literami) dla każdego produktu, którego dane zawiera odpowiedź. Nazwy buduje wyłącznie `CacheTags`
  (`Shared\Infrastructure\Http\Cache`: stała `CATALOG`, `product(id)`, inny napis niż UUID to wyjątek), a nagłówek
  `xkey: <tag> <tag>` (spacje) ustawia w kontrolerze `CacheTagger::tag()`. Varnish usuwa `xkey` przed wysłaniem
  odpowiedzi. Dziś: lista produktów (`catalog` i `product-<id>` każdego produktu strony) i drzewo kategorii
  (`catalog`) z `Cache-Control: public, max-age=60`; oferty (`catalog` i `product-<id>` każdego żądanego id) z `public,
  max-age=60, s-maxage=3600`; szczegóły produktu `GET /api/v1/products/{slug}` (`ProductDetailsController`): 200 z `public,
  max-age=60, s-maxage=3600` i tagami `catalog` `product-<id>`, 404 „Produkt nie istnieje.” (też dla sluga
  niezgodnego z formatem `Slug`) z `public, max-age=60` i tagiem `catalog`. Zdjęcia szczegółów są w rozmiarze
  `product` (`ImageSize`), a `GetProductBySlug` czyta wprost z tabel Catalog przez port `ProductDetailsSource`.
- Trasa publicznego odczytu ma domyślną wartość `PublicReadErrorCacheSubscriber::ROUTE_DEFAULT`
  (`defaults: [... => true]` w `#[Route]`): jej 400, 401 (także z firewalla) i 503 dostają `private, no-store` bez tagów.
- Unieważnianie: `StorefrontCache` (`Shared\Infrastructure\Http\Cache`, implementacja `HttpStorefrontCache` na
  kliencie HTTP Symfony) robi twardy `PURGE` do `VARNISH_URL` z `xkey-purge` i `X-Purge-Token`
  (`VARNISH_PURGE_SECRET`), a dopiero po nim `POST <STORE_INTERNAL_URL>/api/revalidate` z `{tags}` i
  `Authorization: Bearer <STORE_REVALIDATE_SECRET>`; własne limity czasu (Varnish 5 s, sklep 30 s, zamiast domyślnych
  2 s i 5 s klienta). Każdy błąd (status inny niż 200, sieć, timeout) to `StorefrontCacheFailure`, a błąd purge
  zatrzymuje rewalidację. Wołają go konsumenci zdarzeń na transporcie `events`: `InvalidateStorefrontOnPriceChange`
  (`Pricing_Infrastructure`, `VariantPriceChanged`) i `InvalidateStorefrontOnStockLevelChange`
  (`Inventory_Infrastructure`, `StockLevelChanged`): produkt z Catalog `GetVariant` (`query.bus`), `invalidateProduct`.
  Wariant nieznany Catalogowi i źródło `seed` to wiadomość obsłużona bez unieważnienia; błąd `StorefrontCache` idzie
  dalej (ponowienia transportu `events`, potem `failed`). Bez zdarzenia (np. koniec okresu stawki VAT) odpowiedź
  żyje do `s-maxage`.
- Nowy publiczny endpoint katalogu = ścieżka na liście w VCL, jawny `Cache-Control`, tagi w odpowiedzi, znacznik
  trasy i unieważnianie po zmianie danych (zdarzenie i konsument wołający `StorefrontCache`). Bez tego zmiana danych
  nie będzie widoczna w sklepie przed końcem `max-age`/`s-maxage`.

- Transport Messengera `scheduled` (Doctrine,
  `doctrine://default?queue_name=scheduled&auto_setup=false&redeliver_timeout=300`, `use_notify: false`, ta sama tabela `messenger_messages` i to samo połączenie co `outbox`) trzyma komendy opóźnione
  `DelayStamp` (`available_at`, z dokładnością do sekundy, porównywane z czasem systemowym): dziś `ExpireUnpaidOrder`.
  Wiadomość powstaje w transakcji wywołującego, a restart albo przestój workera tylko opóźnia jej obsługę; wiadomość
  pobrana przez worker, który padł przed potwierdzeniem, wraca po 5 minutach. Ponowienia: 5 prób, 1 s, ×3, potem
  `failed`. Worker (`infra/docker-compose.yml`, komenda i healthcheck) konsumuje `outbox events scheduled
  payment_simulator` po kolei. W środowisku `test` transport jest w pamięci (jak `outbox`), w `test_messaging`
  prawdziwy (Doctrine na `shop_test`).
- Transport `payment_simulator` (Doctrine, `queue_name=payment_simulator`, ten sam DSN i opcje co `scheduled`:
  `redeliver_timeout=300`, `use_notify: false`, ta sama tabela i połączenie) trzyma wiadomości `DeliverSimulatedWebhook`
  symulatora płatności, zapisywane w transakcji decyzji klienta. Ponowienia: 5 prób po 1, 3, 9, 27 i 81 s (około
  2 minut), potem `failed` (`messenger:failed:retry`). Kolejka czeka w bazie, więc przestój workera tylko opóźnia
  webhooki. Długa kolejka `events` opóźnia je też, bo worker obsługuje transporty po kolei.
- Pliki (zdjęcia produktów) przez Flysystem na Garage, prywatny bucket. Nigdy na lokalny dysk kontenera.
- Upload: walidacja typu po zawartości (MIME), limit rozmiaru i wymiarów.
- Adresy obrazów: jeden serwis buduje podpisane (HMAC) adresy imgproxy dla zamkniętej listy rozmiarów.
  Odpowiedzi API zwracają gotowe adresy, fronty niczego nie podpisują.
- Logi przez Monolog w JSON. Correlation ID z nagłówka HTTP przekazywany w stemplu Messengera do konsumentów.
- Nigdy nie edytuj migracji, która jest już w repo. Utwórz nową.
- Wyszukiwanie produktów po nazwie w panelu (`SearchBackofficeProducts`) używa rozszerzenia PostgreSQL `pg_trgm`
  (indeks GIN `gin_trgm_ops` na `catalog_product.name`, tworzony migracją) i stronicuje kursorem keyset po `(name, id)`.
  Indeks GIN obsługuje frazy od 3 znaków; fraza 2-znakowa (minimum w API) nie ma trigramów, więc idzie skanem indeksu
  `(name, id)` z filtrem `ILIKE`.
  Rozszerzenie musi być dostępne w bazie, każda baza przechodzi przez migracje.
- Profilowanie: SPX (php-spx) jest tylko w obrazie deweloperskim (target `dev` w Dockerfile), nigdy w obrazie produkcyjnym.
  Przy problemie z wydajnością najpierw zmierz w SPX, potem optymalizuj.

## Testy

- Logika domeny i aplikacji: testy jednostkowe z adapterami in-memory. Mają być szybkie (sekundy),
  bo uruchamiasz je przy każdej zmianie.
- Infrastruktura (Doctrine, Elasticsearch, RabbitMQ, Garage): testy integracyjne na prawdziwych usługach.
- Każdy endpoint: co najmniej jeden test funkcjonalny, w tym przypadek bez autoryzacji i z błędnymi danymi.
- Dane testowe przez fabryki (Foundry), a nie tablice budowane ręcznie w każdym teście.
- Poprawka błędu zaczyna się od testu, który ten błąd odtwarza i nie przechodzi.

## Czego nie robić

- Nie umieszczaj logiki biznesowej w kontrolerach, listenerach Doctrine ani w handlerach wiadomości.
  Handler orkiestruje, decyzje podejmuje domena.
- Nie wołaj innego contextu przez jego repozytorium ani encje.
