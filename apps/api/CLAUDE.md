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
- dane demo ładuje `make seed` z głównego `Makefile`, czyli `bin/seed-demo` (skrypt `sh`, `set -eu`; konsolę woła względem
  własnego katalogu, test: `tests/Unit/Bin/SeedDemoScriptTest.php`). Uruchamia po kolei: `catalog:seed-demo` (katalog), potem
  `pricing:seed-demo`, który czyści ceny i ich historię i ustawia cenę (źródło `seed`) każdemu wariantowi demo według
  wzorców kluczy wariantów z `fixtures/prices.yaml` (klucz z Catalog `ListVariantIdentities`:
  `<slug produktu>/<slug etykiety>`, wariant domyślny `<slug produktu>/standard`; `*` dopasowuje też `/`; pierwszy
  pasujący wzorzec w kolejności pliku; wariant bez wzorca kończy seed błędem bez zmian w danych); potem
  `payment-simulator:seed-demo`, który w jednej transakcji opróżnia (`TRUNCATE`) tabelę transakcji symulatora płatności
  i usuwa jego kolejkę `payment_simulator` (`DELETE FROM messenger_messages WHERE queue_name = 'payment_simulator'`;
  `outbox`, `scheduled`, `events` i `failed` zostają; błąd bazy wycofuje obie zmiany i kończy komendę wyjątkiem); potem
  `payment:seed-demo`, który opróżnia płatności i obsłużone zdarzenia webhooków jednym
  `TRUNCATE payment_payment, payment_webhook_event`; potem `ordering:seed-demo`, który usuwa wszystkie zamówienia, ich
  linie, historię statusów i powiadomienia jednym `TRUNCATE` (sekwencja numerów zostaje, luki są dopuszczalne) i kolejkę `scheduled` ich wiadomości `ExpireUnpaidOrder` (`DELETE FROM messenger_messages
  WHERE queue_name = 'scheduled'`; `outbox` i `failed` zostają); na końcu `inventory:seed-demo`, który czyści stany,
  rezerwacje i historię stanów i ustawia stan fizyczny każdemu wariantowi demo według wzorców kluczy wariantów z `fixtures/stock.yaml` (ten sam
  klucz z `ListVariantIdentities`, ta sama składnia i zasada pierwszego pasującego wzorca; stan > 0 to przyjęcie ze
  źródłem `seed` i kluczem `seed:<variantId>`, stan 0 to rekord stanu bez wpisu historii; wariant bez wzorca kończy
  seed błędem bez zmian w danych), więc po seedzie nie ma zamówień ani ich rezerwacji. Wszystkie komendy `*:seed-demo`
  (jest ich sześć) w `prod` wymagają `--force-reset`, więc `bin/seed-demo` dodaje go do nich tylko przy `APP_ENV=prod`.
  Produkty demo są opublikowane (`firstPublishedAt` = czas seeda, bez pracownika). Potem woła `catalog:reindex`, który
  przebudowuje indeks wyszukiwania z cenami i dostępnością, a na końcu `storefront:cache:purge-all` (zdarzenia cen
  i stanów ze źródła `seed` niczego nie reindeksują ani nie unieważniają). Błąd dowolnego kroku przerywa skrypt
  (i `make seed`) z niezerowym kodem, kolejne kroki się nie wykonują
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
  Z `--apply` przelicza je (`reprice`: to samo brutto, źródło `tax_reconciliation`), każdą w osobnej transakcji
  (pętla `PriceReconciler`, wspólna z przeliczeniem po zmianie klasy produktu);
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
    z Ordering; Ordering zapisuje przez rekordy Doctrine według ogólnej zasady, a `OrderRepository::get` czyta
    zamówienie z bazy z odświeżeniem rekordu z identity map, więc kolejny odczyt w tej samej transakcji widzi zmiany
    zatwierdzone w międzyczasie;
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
    `GetOrderForPayment(orderId, OrderAccess)` (`query.bus`) zwraca `OrderForPayment` (status jako `statusForPayment`:
    `new`, `paid` albo `cancelled`; wysłane, dostarczone i zwrócone są `paid`, więc Payment nie zna statusów panelu)
    albo `null` przy każdym braku
    dostępu (nieznane zamówienie, id spoza UUID, zły token porównywany `hash_equals`, cudze zamówienie, inny rodzaj
    właściciela); komenda `MarkOrderPaid` (`command.bus`, zagnieżdżona w transakcji webhooka) zwraca
    `MarkOrderPaidResult`, a nieznane zamówienie i konflikt wersji to `OrderToPayNotFound` i `OrderChangedConcurrently`.
  - Anulowanie nieopłaconego: `PlaceOrder` w swojej transakcji wysyła `ExpireUnpaidOrder(orderId)` z `DelayStamp` na
    termin + 5 min (port `UnpaidOrderExpiry`; powtórzenie złożenia nic nie wysyła). Handler w transakcji `command.bus`
    zaczyna od blokady zamówienia (`OrderRepository::lock`, jak komendy pracownika), przy anulowaniu najpierw zwalnia rezerwację (port `StockReservations::release`, Inventory `ReleaseReservation`,
    zagnieżdżony dispatch), potem zapisuje zamówienie z `OrderCancelled` w outboxie; wiadomość za wczesna (zegar,
    zaokrąglenie `available_at`) wraca na termin + 5 min + 1 s, opłacone albo anulowane zamówienie nic nie zmienia,
    nieznane (po `make seed`) to wpis w logu. Konflikt wersji i błędy Inventory (`ReservationNotFound`,
    `ReservationStateConflict`) idą dalej: ponowienia transportu, potem `failed` (zamówienie zostaje `new`).
  - E-maile „Płatność przyjęta” (`SendPaymentReceived` po `OrderPaid`) i „Zamówienie … anulowane”
    (`SendOrderCancelled` po `OrderCancelled`) działają jak `SendOrderConfirmation` (poniżej), każdy z własnym rodzajem
    powiadomienia (`payment_received`, `cancelled`); port `OrderStatusMailer`, adapter
    `SymfonyOrderStatusMailer`, treści `PaymentReceivedText` i `OrderCancelledText`. `SendOrderCancelled` wybiera
    treść według `cancellationReason` zamówienia: `payment_timeout` jak wyżej, `staff` „Anulowaliśmy Twoje zamówienie.
    Zwrócimy płatność.” (`OrderCancelledByStaffText`). E-mail z potwierdzeniem podaje termin płatności w strefie
    `Europe/Warsaw`.
- Cykl zamówienia po opłaceniu (Ordering, `docs/features/order-management/spec.md`): statusy `shipped`, `delivered`,
  `returned` i anulowanie opłaconego przez pracownika (`cancellationReason` `staff`). Przejścia pracownika to metody
  agregatu `Order` (`ship`, `deliver`, `cancel`, `registerReturn`; bez Symfony Workflow), a tabela dozwolonych przejść
  jest tylko w `OrderStatus::allowedStaffTransitions()` (enum `StaffTransition`); inne przejście to
  `OrderTransitionNotAllowed` (409, `TypedProblem`). Notatka pracownika (`StaffNote`): przycięta, 1–500 znaków, bez
  znaków sterujących (także nowej linii), wymagana przy anulowaniu i zwrocie, pusta przy pozostałych to brak notatki.
  `markPaid` traktuje `shipped`, `delivered` i `returned` jak `paid` (`alreadyPaid`, `paidByAnotherPayment`).
  - Zwrot pieniędzy (`Refund`, value object w zamówieniu): `cancel` i `registerReturn` zakładają zwrot brutto zamówienia
    z terminem `Order::REFUND_TIME` (14 dni); `completeRefund` oznacza go jako wykonany (data, pracownik, notatka), drugi
    raz albo bez zwrotu `RefundNotPending` (409). Sam przelew jest ręczny, poza systemem; wykonanie nie zmienia statusu.
  - Historia statusów (`OrderStatusChange` z aktorem `StatusActor`: `system`, `customer`, `staff(staffId, username)`)
    jest częścią agregatu, wpisy tylko dopisywane: złożenie `null → new` (klient), opłacenie i wygaśnięcie (system),
    przejścia z panelu (pracownik z notatką). Zapis w tabeli podrzędnej `ordering_order_status_change`
    (PK `(order_id, position)`, FK bez `ON DELETE CASCADE`). `DoctrineOrderRepository::save` najpierw zapisuje
    zamówienie (`UPDATE` z wersją), a nowe wpisy dopisuje osobnym `flush`: Doctrine wstawia przed aktualizacją, więc
    przegrany wyścig kończyłby się naruszeniem klucza historii zamiast konfliktem wersji.
  - CHECK-i `ordering_order` (migracja): `paid_at` razem z `payment_id`, dokładnie dla zamówienia opłaconego (`paid`
    i dalej oraz anulowanego przez pracownika); `shipped_at` od `shipped`, `delivered_at` tylko przy `delivered` i
    `returned`, `returned_at` dokładnie przy `returned`; zwrot (`refund_amount`, `refund_due_at`) dokładnie przy
    `returned` i anulowaniu przez pracownika, jego wykonanie (data, pracownik, notatka) tylko przy zwrocie.
  - Komendy pracownika (`command.bus`, jedna transakcja, wynik `BackofficeOrderView` zapisanego zamówienia):
    `ChangeOrderStatus(orderId, transition, expectedVersion, note, staffId, staffUsername)` i
    `CompleteRefund(orderId, expectedVersion, note, staffId, staffUsername)`. Każda zaczyna od
    `OrderRepository::lock` (`PESSIMISTIC_WRITE`; rekord już w identity map jest odświeżany pod blokadą, jak koszyk),
    potem porównuje `expectedVersion` z wersją odczytaną pod blokadą (różna: `OrderModifiedConcurrently`, 409, także
    przy powtórzeniu udanej zmiany); nieznane zamówienie i id spoza UUID to `OrderNotFound`. Wysyłka zatwierdza
    rezerwację (port `StockReservations::commit`, adapter `CommandBusStockReservations` → Inventory
    `CommitReservation`), anulowanie ją zwalnia (`release`), obie przed zapisem zamówienia (zagnieżdżony dispatch);
    błędy Inventory (`ReservationNotFound`, `ReservationStateConflict`) to błędy logiczne (500, wycofanie całości).
    Zapis zamówienia z historią i zdarzeniem (`OrderShipped`, `OrderCancelled`, `OrderReturned`) w outboxie.
  - Kolejność blokad: wiersz zamówienia, potem rezerwacja i stany (Inventory). Tak samo `ExpireUnpaidOrder`, więc
    równoległe zmiany z tą samą wersją idą po kolei (druga to 409, nigdy `ReservationStateConflict`), a wygaśnięcie
    i zmiana pracownika nie zakleszczają się. Webhook (`MarkOrderPaid`) nie dotyka Inventory i zostaje przy wersji.
  - E-maile do klienta tak samo jak e-maile płatności (jeden na zdarzenie, `NotificationSender`): „Zamówienie … wysłane”
    z adresem (`SendOrderShipped` po `OrderShipped`, rodzaj `shipped`, `OrderShippedText`) i „Zamówienie … wróciło do
    nas” (`SendReturnReceived` po `OrderReturned`, rodzaj `return_received`, `ReturnReceivedText`). Dostarczenie
    i wykonanie zwrotu pieniędzy są bez e-maila; notatka pracownika ani termin zwrotu nie trafiają do e-maila.
  - Panel (`openapi-admin.json`, tag `Ordering`): `GET /api/v1/admin/orders` (lista), `GET /api/v1/admin/orders/{orderId}`
    (szczegóły), `POST …/{orderId}/status-changes` (`{transition, version, note}`) i `POST …/{orderId}/refund-completion`
    (`{version, note}`); każda odpowiedź `private, no-store` (`OrderResponseCacheSubscriber` obejmuje `admin/orders`).
    Reguła ról (P6) jest tylko w `OrderVoter` (`Ordering_Infrastructure`, role z `AuthenticatedStaffMember`):
    `VIEW_ORDERS` (`admin`, `support`, `warehouse`), `CHANGE_ORDER_STATUS` z przejściem `StaffTransition` jako
    subject (`ship`, `deliver`: `admin`, `warehouse`; `cancel`, `return`: `admin`, `support`; bez przejścia voter
    wstrzymuje się, czyli odmowa) i `COMPLETE_REFUND` (`admin`, `support`); `marketing` nie ma dostępu. Kontrolery
    znają tylko nazwy atrybutów (`BackofficeOrderAttributes`) i pytają `isGranted`; Deptrac pozwala `Ordering_UI`
    i `Ordering_Infrastructure` tylko na `Backoffice_Application`.
  - Kolejność sprawdzeń zmiany statusu: 401, 403 `VIEW_ORDERS` (atrybut, przed treścią), 400/415/422 treści (typy
    ścisłe jak korekta stanu, wszystkie klucze wymagane; reguły notatki `StaffNote` z ich komunikatami przy polu
    `note`, także notatka wymagana przy `cancel` i `return`), 403 `CHANGE_ORDER_STATUS` dla przejścia z treści,
    404, 409. Wykonanie zwrotu: 403 `COMPLETE_REFUND` (atrybut) przed treścią, potem 404 i 409. 409 panelu mają
    schemat `BackofficeOrderConflictProblem` (`order-transition-not-allowed`, `refund-not-pending`, `about:blank` dla
    wersji). Szczegóły, zmiana statusu i wykonanie zwrotu odpowiadają `BackofficeOrder` (`BackofficeOrderResponder`):
    `allowedTransitions` to przejścia statusu (`statusTransitions` widoku) przepuszczone przez voter, a
    `canCompleteRefund` = zwrot do wykonania i `COMPLETE_REFUND`.
  - Zapytania panelu (`query.bus`, bez blokad): `GetBackofficeOrder(orderId)` czyta zamówienie przez
    `OrderRepository::get` i zwraca ten sam `BackofficeOrderView` co komendy pracownika (`overdue` z zegara; nieznane
    i id spoza UUID: `OrderNotFound`). `ListBackofficeOrders(status, pendingRefunds, q, after, perPage)` czyta przez
    port `BackofficeOrderReader` (adapter DBAL `DbalBackofficeOrderReader`, liczba sztuk z sumy linii): `q` po
    przycięciu to dokładny numer, gdy pasuje do `^[Rr]-[0-9]+$`, inaczej dokładny e-mail (`lower(email) =
    lower(:email)`), gdy ma `@`, inaczej pusta lista bez odczytu; sort `placed_at DESC, id DESC`, a przy
    `refund=pending` tylko zwroty do wykonania po `refund_due_at ASC, id ASC` (łączy się ze `status` i `q`).
    Kursor keyset `BackofficeOrderCursor` (base64url JSON sortu, chwili w UTC z mikrosekundami i id); zły kursor
    i kursor drugiego sortu to 400 przy `after`. Parametry: `q` 1–254 znaki po przycięciu bez NUL, `perPage` 1–100
    (domyślnie 25), inaczej 400. Indeksy listy (migracja): `(placed_at DESC, id DESC)`,
    `(status, placed_at DESC, id DESC)`, `(lower(email))` (wyrażenie, mapowanie go nie zna, więc narzędzia schematu
    go pomijają) i częściowy `(refund_due_at, id) WHERE refund_amount IS NOT NULL AND refund_completed_at IS NULL`
    (warunek w mapowaniu tak, jak zwraca go PostgreSQL); `OrderingSchemaTest` pilnuje zgodności mapowania z migracją.
  - Historia klienta w sklepie (`openapi.json`, tag `Ordering`; token `shop`, `CustomerOrderHistoryController`):
    `GET /api/v1/me/orders?after=&perPage=` i `GET /api/v1/me/orders/{orderId}`; każda odpowiedź `private, no-store`
    (`OrderResponseCacheSubscriber` obejmuje `me/orders`), brak tokenu albo token panelu to 401. Klient to `sub` z tokenu
    (`AuthenticatedCustomer::id()`), nigdy parametr. `ListCustomerOrders(customerId, after, perPage)` czyta przez port
    `CustomerOrderReader` (adapter DBAL `DbalCustomerOrderReader`, tylko `owner_type = 'customer'` i `customer_id`, liczba
    sztuk z sumy linii): sort `placed_at DESC, id DESC`, kursor keyset ten sam co listy panelu (`BackofficeOrderCursor`,
    sort po złożeniu; zły kursor i kursor zwrotów to 400 przy `after`), `perPage` 1–50 (domyślnie 10), inaczej 400.
    Indeks (migracja): częściowy `(customer_id, placed_at DESC, id DESC) WHERE owner_type = 'customer'`, w mapowaniu tak,
    jak zwraca go PostgreSQL (`OrderingSchemaTest`). Element listy: `orderId, number, placedAt, status, totalGross, currency,
    itemCount, paymentDeadline, paymentDeadlinePassed`. `GetCustomerOrder(orderId, customerId)` czyta zamówienie przez
    `OrderRepository::get` i sprawdza `Order::grantsAccess(AccessClaim::customer(...))`: nieznane, cudze, gościa i id spoza
    UUID dają jeden wynik (null), a kontroler odpowiada tym samym 404 `OrderNotFound`. `CustomerOrderView` ma pozycje,
    sumy, adres, `placedAt`, `paidAt`, `shippedAt`, `deliveredAt`, `cancelledAt`, `returnedAt`, `paymentDeadline`
    i `paymentDeadlinePassed`, bez historii, notatek, danych pracowników, zwrotu, płatności i danych kontaktowych.
    `paymentDeadlinePassed` liczy API z zegara: tylko zamówienie `new` od chwili terminu (`now >= paymentDeadline`, jak
    `Order::isPayable`), dla innych statusów zawsze `false`.
- Payment (`src/Payment`, `docs/features/payment/spec.md`): agregat `Payment` to jedna próba opłacenia zamówienia u jednego
  dostawcy (`pending` → `succeeded` | `failed` | `cancelled`; `succeed` także z `failed` i `cancelled`, bo pieniądze ważą
  więcej niż wcześniejsza odmowa; `requireRefund` tylko dla `succeeded`, pierwszy powód zostaje), bez pola wersji:
  istniejącą płatność zmieniają tylko operacje pod blokadą jej wiersza. Zapis płatności przez rekordy Doctrine według
  ogólnej zasady (`payment_payment`), bez kluczy obcych do Ordering. Wyjątek: obsłużone zdarzenie webhooka
  (`payment_webhook_event`, PK `(provider, event_id)`, na zawsze) zapisuje `DoctrinePaymentRepository::recordWebhookEvent`
  przez DBAL `INSERT … ON CONFLICT DO NOTHING` (rekord tylko definiuje schemat): powtórzenie nie jest naruszeniem
  unikalności, które zamknęłoby `EntityManager`, a wiersz wycofanej transakcji nie zostaje w identity map jako
  „już obsłużony”.
  - Payment sięga do Ordering tylko przez dwa swoje porty: `PayableOrders` (adapter `QueryBusPayableOrders`: Ordering
    `GetOrderForPayment` na `query.bus`, wynik przetłumaczony na `OrderToPay` z domeny Payment) i `OrderPayments` (adapter
    `CommandBusOrderPayments`: Ordering `MarkOrderPaid` zagnieżdżony na `command.bus` w transakcji webhooka, wynik
    przetłumaczony na `OrderPaymentResult`, `OrderChangedConcurrently` na `OrderBusy`), i ma własne value objecty
    (`OrderId`, `Amount`, `Currency`). Deptrac: tylko `Payment_Infrastructure → Ordering_Application`; Ordering ani żaden
    inny context nie zależy od Payment.
  - Port `PaymentProvider` (`code`, `createSession`, `cancelSession`, `parseWebhook`) z rejestrem `PaymentProviders` (tag
    `app.payment_provider`) i dwoma adapterami HTTP do symulatora (`DemoTransferProvider`: Bearer, grosze, RFC 3339;
    `DemoCardProvider`: Basic `merchant`, kwota `"123.45"` przez `CardAmount`, czas uniksowy) na `app.payment_http_client`
    pod `PAYMENT_PROVIDER_BASE_URL`. Każde wywołanie ma 5 s (`timeout` i `max_duration`); błąd sieci, przekroczenie
    czasu, inny status albo nieczytelna odpowiedź to `PaymentProviderUnavailable` (marker `Unavailable`, 503).
    `parseWebhook(headers, rawBody, now)` najpierw sprawdza podpis (`hash_equals`, sekrety
    `PAYMENT_DEMO_*_WEBHOOK_SECRET`): `demo-transfer` `X-Signature` = hex HMAC-SHA256 surowej treści, `demo-card`
    `Card-Signature: t=<unix>,v1=<hex HMAC-SHA256("<t>.<treść>")>` (jeden z kilku `v1`) z tolerancją 300 s w obie strony;
    potem czyta treść ściśle (`WebhookFields`, bez rzutowania typów; kwota `demo-card` przez `CardAmount`, bez floatów;
    `paidAt` RFC 3339 ze strefą, `completedAt` sekundy uniksowe) do `ProviderEvent {eventId, providerPaymentId, outcome
    succeeded|failed, amount, executedAt}` (`executedAt` wymagany przy sukcesie) albo `null` dla innego typu zdarzenia
    `demo-card`. Każdy błąd to `InvalidWebhook` (`Payment_Application`) z przyczyną do logu, bez sekretu i treści.
  - `StartPayment` (`command.bus`, jedna transakcja): dostęp do zamówienia przed powtórzeniem (sam klucz nie daje
    płatności; brak dostępu to `OrderNotFound` 404 „Nie znaleźliśmy zamówienia.”), powtórzenie po `Idempotency-Key` i
    odcisku (zamówienie i dostawca; inny odcisk to 409), stan zamówienia (opłacone, anulowane, po terminie: 409),
    oczekująca płatność zamówienia blokowana `SELECT … FOR UPDATE` z odświeżeniem rekordu z identity map i anulowana
    u swojego dostawcy (anulowana → `cancel`, odrzucona → `fail`, wykonana → 409 `PaymentInProgress` bez zmian, błąd →
    503 bez zmian), potem stan zamówienia jest sprawdzany ponownie na świeżym odczycie (webhook, który trzymał
    blokadę, mógł zatwierdzić zamówienie jako opłacone: 409), potem nowa płatność zapisana przed wywołaniem dostawcy i sesja do terminu płatności zamówienia
    (adres powrotu `STORE_URL` + `/checkout/confirmation` buduje API). Wywołania HTTP są w transakcji: każdy błąd
    wycofuje całość. Równoległe rozpoczęcia odrzuca unikalny indeks `idempotency_key` albo częściowy unikalny indeks
    `(order_id) WHERE status = 'pending'` (w mapowaniu warunek zapisany tak, jak zwraca go PostgreSQL, żeby schemat
    mapowania był równy zmigrowanemu): naruszenie to `PaymentInProgress` „Płatność za to zamówienie jest w toku.”.
  - `GetPaymentStatus` (`query.bus`): ta sama reguła dostępu, `canStartPayment` = zamówienie płatne, najnowsza płatność
    z `resumeUrl` tylko dla `pending` i płatnego zamówienia.
  - Webhook `POST /api/v1/payments/webhooks/{provider}` (publiczny, `security.yaml` `PUBLIC_ACCESS`; `WebhookController`):
    podpis i treść sprawdza adapter dostawcy, jak mapowanie treści żądania; nieznany dostawca 404, `InvalidWebhook` 400 ze
    stałym ogólnym `detail` (przyczyna w logu `warning`), zdarzenie innego typu 204 bez komendy, inaczej
    `HandleProviderWebhook(provider, event)` i 204. Komenda (`command.bus`, jedna transakcja): blokada płatności po
    `(provider, providerPaymentId)` (`lockByProviderPayment`, `PESSIMISTIC_WRITE` z odświeżeniem rekordu, więc równoległe
    dostawy idą po kolei; nieznana to `PaymentNotFound` 404 „Nie znaleźliśmy płatności.”), deduplikacja (zdarzenie już
    obsłużone: 204 bez zmian), `fail` albo `succeed` (inna kwota: `PaymentAmountMismatch` 422 i log `error`). Płatność,
    która właśnie przeszła w `succeeded`, opłaca zamówienie przez port `OrderPayments` (adapter `CommandBusOrderPayments`:
    Ordering `MarkOrderPaid` zagnieżdżony na `command.bus`, w tej samej transakcji, więc płatność i status zamówienia
    zmieniają się razem). Wynik `paid` i `alreadyPaid` nic nie dodaje; przy `orderCancelled`, `paidAfterDeadline` i
    `paidByAnotherPayment` płatność zostaje `succeeded` z oznaczeniem zwrotu (`requireRefund`: `order_cancelled`,
    `paid_after_deadline`, `duplicate`; `OrderPaymentResult::refundReason()`), handler loguje `error` „requires a manual
    refund”, a odpowiedź to 204 (dostawa kończy się bez ponowień). Zwrot jest ręczny, poza systemem: płatności do zwrotu
    to `payment_payment.refund_required_reason IS NOT NULL`. Konflikt wersji zamówienia (`OrderChangedConcurrently`, np.
    równoległe `ExpireUnpaidOrder`) adapter tłumaczy na `OrderBusy` (marker `Unavailable`,
    503), inne błędy Ordering przekazuje bez zmian (500); w obu przypadkach wycofana jest cała transakcja, także wiersz
    deduplikacji, a symulator ponawia dostawę.
  - Endpointy: `GET /api/v1/payment-providers` (publiczny), gość `POST /api/v1/orders/{id}/payments` i
    `GET /api/v1/orders/{id}/payment` z nagłówkiem `Order-Access-Token` (brak to 404, sesja niczego nie zmienia),
    klient to samo pod `/api/v1/me/orders/{id}/...`, oraz webhook dostawców (wyżej). Każda odpowiedź `private, no-store`
    (`PaymentResponseCacheSubscriber`).
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
    czasy RFC 3339 (także z ułamkiem sekundy), `POST …/demo-transfer/api/transactions` → 201 `{transactionId, paymentUrl}` i `…/{id}/cancel` →
    200 `{status: "CANCELLED"}` albo 409 `{status: "PAID" | "REJECTED"}`; `demo-card` HTTP Basic (`merchant`, klucz), kwota
    `"123.45"`, czas uniksowy (do 9999-12-31T23:59:59Z, większy to 400), `POST …/demo-card/v1/checkout-sessions` → 200 `{id, url}` i `…/{id}/expire` → 200
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
  w zmiennych środowiska: `MAILER_DSN` (`smtp://mailpit:1025?timeout=10`) i `MAILER_FROM`
  (`Rytm <zamowienia@rytm.localhost>`), ustawianych w `infra/docker-compose.yml` dla `api` i `worker` (testy i CI biorą
  je z serwisu `api`; `phpunit.dist.xml` ma te same wartości domyślne). Opcję `timeout` DSN SMTP (sekundy, > 0) dodaje
  `SmtpTimeoutTransportFactory` (`Shared\Infrastructure\Mail`, dekorator `mailer.transport_factory.smtp`): limit
  każdego połączenia, odczytu i zapisu gniazda zamiast `default_socket_timeout` (60 s), więc wysyłka (około 7 wymian)
  kończy się dużo przed końcem 5-minutowego zajęcia wysyłki; pilnuje tego `SmtpTimeoutWithinTheLeaseTest`. Treść składa `OrderConfirmationText` (Ordering Application), a port
  `OrderConfirmationMailer` ma adapter `SymfonyOrderConfirmationMailer` w Ordering Infrastructure.
- Znaczniki wysłanych e-maili Ordering są poza agregatem `Order`, więc wysłanie e-maila nie zmienia zamówienia ani
  jego wersji (`docs/features/order-management/spec.md`, „Znaczniki wysyłki poza agregatem”). Tabela
  `ordering_order_notification` (PK `(order_id, kind)`, FK do `ordering_order` bez `ON DELETE CASCADE`; `kind`:
  `confirmation`, `payment_received`, `cancelled`, `shipped`, `return_received` jak enum `NotificationKind`;
  `claimed_until`, `sent_at`). Port `OrderNotifications` (`claim`, `markSent`, `release`) z adapterem
  `DbalOrderNotifications`: wyjątek od zapisu przez rekordy ORM, jak `payment_webhook_event` (rekord
  `OrderNotificationRecord` tylko definiuje schemat). `claim(orderId, kind, now)` to jeden warunkowy
  `INSERT … ON CONFLICT … DO UPDATE … WHERE … RETURNING`: zajmuje wysyłkę do `now` + 5 min (lease), gdy e-mail nie
  wyszedł i nikt nie trzyma niewygasłego zajęcia; wynik `claimed`, `alreadySent` albo `inProgress(until)`. Równoległe
  pierwsze zajęcie czeka na wiersz drugiego zamiast naruszenia unikalności, które zamknęłoby `EntityManager`.
  Handlery (`event.bus`, bez transakcji, każdy krok zatwierdzany od razu) idą przez `NotificationSender::sendOnce`:
  najpierw odczyt zamówienia (nieznane: `UnrecoverableMessageHandlingException`, nic nie jest zajmowane), potem
  zajęcie; `alreadySent` nic nie robi; `inProgress` to `RecoverableMessageHandlingException` z opóźnieniem do końca
  lease + 1 s (ponawiane bez limitu prób transportu); `claimed` wysyła i zapisuje `markSent`, a błąd wysyłki zwalnia
  zajęcie (`release`) i idzie dalej do ponowień transportu `events`. Dwie równoległe dostawy tego samego zdarzenia
  wysyłają jeden e-mail. Drugi e-mail jest możliwy, gdy wysyłka się udała, a znacznik nie został zapisany: awaria
  procesu albo bazy przed `markSent` (po wygaśnięciu lease) albo błąd SMTP zgłoszony po przyjęciu wiadomości
  (`release` i ponowienie); także gdy wysyłka trwa dłużej niż lease (limit czasu SMTP jest dużo krótszy).
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
- Agregaty edytowane równolegle (zamówienie, stan magazynowy, cena, produkt) mają optimistic locking (pole wersji):
  edycja z widoku sprawdza wersję, na której się opiera. Wyjątek: atomowe operacje na stanie magazynowym (przyjęcie,
  rezerwacja, zwolnienie, zatwierdzenie) nie sprawdzają wersji z widoku, tylko blokują wiersze (`SELECT … FOR UPDATE`
  w `StockItemRepository::lockMany`, w kolejności rosnącego `variant_id`; przyjęcie i korekta wariantu bez stanu
  zakładają wiersz przez `lockOrCreate`, `INSERT … ON CONFLICT DO NOTHING` przed blokadą) i czytają stan od nowa po
  uzyskaniu blokady; zapis i tak podnosi wersję. Korekta z panelu blokuje wiersz i dodatkowo sprawdza wersję z widoku.
- Produkt (Catalog) ma status `draft` albo `published` (`ProductStatus`) i `firstPublishedAt` (pierwsza publikacja,
  nigdy nie zerowana). Zapisuje się wyłącznie komendami `command.bus`: `CreateProduct` (status z komendy: panel
  tworzy szkic, seed opublikowany produkt bez pracownika; pusty slug wylicza `Slug::slugify(name)`), `EditProduct`,
  `PublishProduct`, `UnpublishProduct`. Te trzy porównują wersję z widoku (`expectedVersion`) z wersją wczytanego
  produktu przed budową wariantów, odczytem stanów i każdą regułą domeny (`ProductModifiedConcurrently`, 409
  `product-modified`), a `ProductRepository::save(product, expectedVersion, actorId, at)` porównuje ją jeszcze raz
  z rekordem pod `#[ORM\Version]`. Zapis z widoku zawsze podnosi wersję (także bez zmiany danych), zapis bez widoku
  (seed) tylko przy zmianie; `updated_at` i `updated_by` (`sub` pracownika) zmieniają się tylko przy zapisie, który
  podnosi wersję. Publikacja opublikowanego i wycofanie szkicu nic nie zapisują. Reguły pilnuje agregat: publikacja
  szkicu wymaga zdjęcia, zapis opublikowanego produktu ze zdjęciami nie usuwa ostatniego (`ProductNotPublishable`),
  slug jest stały od pierwszej publikacji (`SlugLocked`), po pierwszej publikacji warianty zachowują tożsamość
  (`VariantIdentityLocked`), a wariantu ze stanem nie da się usunąć (`VariantInUse`). Fakty stanu daje port
  `VariantStockFacts` (`Catalog_Application`) z adapterem `QueryBusVariantStockFacts` w `Inventory_Infrastructure`
  (Inventory `GetStockLevels` na `query.bus`, odczyt bez blokady; stan fizyczny > 0 albo zarezerwowany > 0), więc
  Catalog nie zależy od Inventory w żadnej warstwie. Reguły i ich błędy opisuje `docs/features/catalog-admin/spec.md`.
- Szkic jest ukryty w odczytach sklepu w Catalog: `GET /api/v1/products/{slug}` szkicu to 404 jak nieznany slug,
  `GetVariantSummaries` (koszyk, zamówienie) i `ListProductVariants` bez `includeDrafts` (oferta sklepu) pomijają
  warianty szkiców jak nieznane, a zapytania indeksu dopasowują tylko `status: published`. Cart i Ordering nie znają
  statusu: linia z wariantem szkicu ma stan `unavailable_product`, a złożenie zamówienia z nią to 409
  `CartNotOrderable`. Szkice widzą `GetVariant`, `ListVariantIdentities`, `SearchBackofficeProducts`
  i `ListProductVariants(productIds, includeDrafts: true)` (listy cen i stanów panelu, adaptery
  `QueryBusProductDirectory` w `Pricing_Infrastructure` i `Inventory_Infrastructure`). `ProductVariantList` ma klasę
  podatkową produktu (`taxClass`).
- Koszyk nie ma optimistic locking ani wersji z widoku: zmiany szereguje blokada wiersza koszyka, dodanie zwiększa
  ilość, a zmiana ilości z nieaktualnej karty nadpisuje nowszą (ostatni zapis wygrywa).
- Zdarzenia domenowe zapisywane w transactional outbox w tej samej transakcji co agregat, potem wysyłane do RabbitMQ.
  Kto je publikuje: handler komendy (`releaseEvents()` agregatu i `DomainEventPublisher` w `command.bus`; Catalog
  `ProductCreated`, Ordering `OrderPlaced`, `OrderPaid`, `OrderCancelled`, `OrderShipped`, `OrderReturned`), gdy jedyną
  drogą zapisu jest ta komenda.
  Gdy agregat ma wiele ścieżek zapisu, także poza `command.bus` (Pricing: panel, seed, `pricing:reconcile-tax`; Inventory: panel, seed, port
  rezerwacji w zagnieżdżonym dispatchu), publikuje je `save` repozytorium w transakcji zapisu (`VariantPriceChanged`
  w `DoctrineVariantPriceRepository`, `StockLevelChanged` w `DoctrineStockItemRepository`), więc żadna ścieżka go nie
  pominie, a odrzucony albo wycofany zapis nie zostawia zdarzenia.
  Catalog publikuje też `ProductChanged` z handlera komendy, jak `ProductCreated`; Pricing ma ścieżkę zapisu także
  przy przeliczeniu po zmianie klasy podatkowej produktu.
- Zdarzenie, które ma subskrybować inny context, jest zdarzeniem integracyjnym: publicznym kontraktem modułu w jego
  warstwie Application, jak zapytania (Deptrac nie pozwala sięgać do Domain innego contextu). Jedyne dziś:
  `Catalog\Application\Event\ProductChanged` `{productId, slug, occurredAt}` (`slug` po zmianie). Agregat `Product`
  zapisuje wewnętrzne `Catalog\Domain\Product\ProductChanged` przy każdej edycji, publikacji i wycofaniu, a handler
  komendy tłumaczy je na kontrakt (`ProductChanged::translate`) przed publikacją przez outbox. Seed tworzy produkty
  bez `ProductChanged` (tylko `ProductCreated`). Subskrybent czyta to, czego potrzebuje, zapytaniami Catalog; zmiana
  pól kontraktu dotyka wszystkich subskrybentów. Subskrybenci (transport `events`): `IndexProduct` (reindeksacja),
  `InvalidateStorefrontOnProductChange` (`Catalog_Infrastructure`, unieważnienie sklepu) i `RepriceOnTaxClassChange`
  (`Pricing_Infrastructure`, przeliczenie cen po zmianie klasy podatkowej).
- Przeliczenie cen po zmianie klasy (`RepriceOnTaxClassChange` → `RepriceProductPrices`, `Pricing_Application`): klasa
  i warianty produktu z Catalog `ListProductVariants([productId], includeDrafts: true)` (port `ProductDirectory::taxClassOf`,
  adapter `QueryBusProductDirectory`), nigdy ze zdarzenia, więc zmiany A → B → A w dowolnej kolejności i powtórzone
  zdarzenia kończą się cenami w klasie bieżącej. Każda cena z klasą inną niż klasa produktu idzie przez `PriceReconciler`
  (ta sama pętla co `pricing:reconcile-tax --apply`: `reprice` z tym samym brutto, źródło `tax_reconciliation`, osobna
  transakcja na cenę, jedno ponowienie po konflikcie wersji); cena w klasie produktu nie jest zapisywana (także
  z nieaktualną stawką). Po zapisach klasa jest czytana ponownie: inna to `TaxClassChangedWhileRepricing` (ponowienie
  wiadomości). Klasa bez stawki: `UnrecoverableMessageHandlingException` z komunikatem (`failed`), ceny bez zmian; drugi
  konflikt wersji: `PriceModifiedConcurrently` po zapisie pozostałych cen (ponowienie); produkt nieznany Catalogowi:
  wiadomość obsłużona bez zmian.
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
  reszta API tylko z realmu `shop` (klienci, `Customer`). Sprawdzany jest wystawca tokena. Token konta demo
  na stosie lokalnym do ręcznych żądań: `.claude/skills/integration/kc_login.py` (opis w skillu `/integration`).
- Nie twórz wspólnej klasy `User` dla klientów i pracowników. To osobne byty.
- Uprawnienia w voterach. Pracownik: na podstawie ról z tokena (`admin`, `support`, `warehouse`, `marketing`).
  Klient: tylko własne dane (właściciel zasobu = `sub` z tokena).
- Zmiany z panelu zapisują identyfikator pracownika (`sub` z tokena realmu `backoffice`).
- Błędy w formacie RFC 9457 (problem details). `type` to domyślnie `about:blank`. Wyjątek projektu, którego przyczynę
  klient ma rozróżniać maszynowo, implementuje `App\Shared\Domain\Exception\TypedProblem` (`problemType()`) i trzyma
  wartość w stałej `TYPE`; subscriber wpisuje ją do odpowiedzi, a Shared nie zna contextów. Format wartości:
  `tag:piotrp321.github.io,2026:shop/problems/<przyczyna>` (identyfikator, nie adres). Dziś mają je pięć 409 Ordering
  sklepu (schemat OpenAPI `OrderConflictProblem` wylicza ich wartości w `enum`), dwa 409 komend pracownika Ordering
  (`order-transition-not-allowed`, `refund-not-pending`; schemat `BackofficeOrderConflictProblem` w obszarze `admin`) i cztery 409 Payment: `order-already-paid`,
  `payment-deadline-passed`, `payment-in-progress`, `payment-idempotency-key-reused` (schemat `PaymentConflictProblem`).
  Nowy typ dopisz do `enum` schematu swojego contextu.
- `detail` w błędzie ma tylko wyjątek projektu: implementuje znacznik z `App\Shared\Domain\Exception`
  (`NotFound` → 404, `Conflict` → 409, `InvalidInput` → 422, goły `ProblemException` → 422). Wyjątek: znacznik
  `Unavailable` (zależność nieosiągalna albo przegrany wyścig, który ponowienie rozwiązuje) to 503 bez `detail`, jak
  każdy 5xx: klient może tylko spróbować później, a komunikat wyjątku zostaje w logu. Domena nie zna statusów HTTP,
  mapowanie jest w `Shared\Infrastructure\Http`. Komunikat bez danych wewnętrznych i wejścia użytkownika
  (dotyczy też komunikatów naruszeń własnych constraintów). Wyjątki frameworka dostają stały ogólny opis.
  Subscriber rozpakowuje tylko `HandlerFailedException` z jednym wyjątkiem, `previous` poza tym ignoruje.
- Sześć 409 produktu (`slug-taken`, `slug-locked`, `product-modified`, `product-not-publishable`, `variant-locked`,
  `variant-in-use`) ma typy w tym formacie i schemat `ProductConflictProblem` w `openapi-admin.json`.
- Operacje nieidempotentne (złożenie zamówienia, rozpoczęcie płatności, przyjęcie magazynowe) wymagają nagłówka
  `Idempotency-Key`. Brak albo zły format klucza to 400 z naruszeniem nagłówka. Przyjęcie z tym samym kluczem i tą samą
  treścią (wariant, ilość, notatka) nic nie zmienia i zwraca 200 z bieżącym stanem zamiast 201; ten sam klucz z inną
  treścią albo dla innego wariantu to 409. Klucze przyjęć są w historii stanów na zawsze (unikalny indeks).
  Złożenie zamówienia (`POST /api/v1/orders` gościa, `POST /api/v1/me/orders` klienta) z tym samym kluczem, z tego
  samego koszyka (id koszyka gościa z treści albo `sub` z tokena) i z tą samą treścią niczego nie zmienia i zwraca 200
  z tym samym zamówieniem zamiast 201, także gdy koszyk gościa w międzyczasie wygasł albo został scalony; ten sam klucz
  z innego koszyka, od innego klienta albo z inną treścią to 409 `IdempotencyKeyReused` bez żadnych danych zamówienia,
  które go użyło. Klucze zamówień są w `ordering_order` na zawsze (unikalny indeks).
  Rozpoczęcie płatności z tym samym kluczem dla tego samego zamówienia i dostawcy zwraca 200 z tą płatnością w bieżącym
  stanie zamiast 201; ten sam klucz dla innego zamówienia albo dostawcy to 409 `PaymentIdempotencyKeyReused`, a bez
  dostępu do zamówienia (inny token, inny klient) 404. Klucze płatności są w `payment_payment` na zawsze.
- Rate limiting (Symfony RateLimiter na Redis) na logowaniu, checkout i webhookach.
- Webhooki zewnętrzne: weryfikacja podpisu i deduplikacja po identyfikatorze zdarzenia.

## Infrastruktura

- Varnish (`infra/varnish/default.vcl`) stoi tylko przed publicznymi odczytami `GET` katalogu: `/api/v1/products`,
  `/api/v1/products/*`, `/api/v1/categories`, `/api/v1/product-offers`. Czyta przez niego wyłącznie serwer sklepu
  (`API_CATALOG_URL`); inne ścieżki, żądania z `Authorization` albo `Cookie` i metody inne niż `GET`/`HEAD` Varnish
  przepuszcza do API bez cache. TTL bierze tylko z `Cache-Control` odpowiedzi; bez nagłówka, z `private` albo
  `no-store` nic nie trzyma. Endpointy z autoryzacją, koszyk i checkout zawsze `private, no-store`.
- Tagi cache (xkey) bez FOSHttpCacheBundle. Słownik: `catalog` (każdy odczyt katalogu), `product-<id>` (UUID małymi
  literami) dla każdego produktu, którego dane zawiera odpowiedź, i `product-slug-<slug>` (404 szczegółów produktu).
  Nazwy buduje wyłącznie `CacheTags` (`Shared\Infrastructure\Http\Cache`: stała `CATALOG`, `product(id)`, inny napis
  niż UUID to wyjątek, `productSlug(slug)`, slug spoza formatu `Slug` produktu to wyjątek), a nagłówek
  `xkey: <tag> <tag>` (spacje) ustawia w kontrolerze `CacheTagger::tag()`. Varnish usuwa `xkey` przed wysłaniem
  odpowiedzi. Dziś: lista produktów (`catalog` i `product-<id>` każdego produktu strony) i drzewo kategorii
  (`catalog`) z `Cache-Control: public, max-age=60`; oferty (`catalog` i `product-<id>` każdego żądanego id) z `public,
  max-age=60, s-maxage=3600`; szczegóły produktu `GET /api/v1/products/{slug}` (`ProductDetailsController`): 200 z `public,
  max-age=60, s-maxage=3600` i tagami `catalog` `product-<id>`, 404 „Produkt nie istnieje.” (szkic, nieznany slug,
  slug niezgodny z formatem `Slug`) z `public, max-age=60` i tagami `catalog` `product-slug-<slug>` (slug niezgodny
  z formatem: tylko `catalog`), więc publikacja produktu usuwa zapamiętane 404 jego sluga. Zdjęcia szczegółów są w rozmiarze
  `product` (`ImageSize`), a `GetProductBySlug` czyta wprost z tabel Catalog przez port `ProductDetailsSource`.
- Trasa publicznego odczytu ma domyślną wartość `PublicReadErrorCacheSubscriber::ROUTE_DEFAULT`
  (`defaults: [... => true]` w `#[Route]`): jej 400, 401 (także z firewalla) i 503 dostają `private, no-store` bez tagów.
- Unieważnianie: `StorefrontCache` (`Shared\Infrastructure\Http\Cache`, implementacja `HttpStorefrontCache` na
  kliencie HTTP Symfony; `invalidateTags(tags)` dla tagów z `CacheTags`, pusta lista albo tag spoza ich alfabetu to
  wyjątek bez żadnego żądania, i `invalidateProduct(id)` dla `product-<id>`) robi twardy `PURGE` do `VARNISH_URL`
  z `xkey-purge` (wszystkie tagi) i `X-Purge-Token` (`VARNISH_PURGE_SECRET`), a dopiero po nim
  `POST <STORE_INTERNAL_URL>/api/revalidate` z `{tags}` i
  `Authorization: Bearer <STORE_REVALIDATE_SECRET>`; własne limity czasu (Varnish 5 s, sklep 30 s, zamiast domyślnych
  2 s i 5 s klienta). Każdy błąd (status inny niż 200, sieć, timeout) to `StorefrontCacheFailure`, a błąd purge
  zatrzymuje rewalidację. Wołają go konsumenci zdarzeń na transporcie `events`: `InvalidateStorefrontOnPriceChange`
  (`Pricing_Infrastructure`, `VariantPriceChanged`) i `InvalidateStorefrontOnStockLevelChange`
  (`Inventory_Infrastructure`, `StockLevelChanged`): produkt z Catalog `GetVariant` (`query.bus`), `invalidateProduct`.
  Wariant nieznany Catalogowi i źródło `seed` to wiadomość obsłużona bez unieważnienia. `InvalidateStorefrontOnProductChange`
  (`Catalog_Infrastructure`, integracyjne `ProductChanged`): `invalidateTags([product-<id>, product-slug-<slug>])` bez
  odczytu bazy (id albo slug w złym formacie to `failed`). Błąd `StorefrontCache` idzie dalej (ponowienia transportu
  `events`, potem `failed`). Bez zdarzenia (np. koniec okresu stawki VAT) odpowiedź
  żyje do `s-maxage`.
- Nowy publiczny endpoint katalogu = ścieżka na liście w VCL, jawny `Cache-Control`, tagi w odpowiedzi, znacznik
  trasy i unieważnianie po zmianie danych (zdarzenie i konsument wołający `StorefrontCache`). Bez tego zmiana danych
  nie będzie widoczna w sklepie przed końcem `max-age`/`s-maxage`.

- Indeks wyszukiwania (Elasticsearch, alias `products`) należy do Catalog. Dokument (`ProductIndexDefinition`,
  `dynamic: strict`): dane produktu, `name`, `category_names` (nazwy kategorii produktu i ich przodków)
  i `short_description` z analizatorem `polish_text` (`lowercase`, `polish_stem`, `asciifolding`) i podpolem `folded`
  (bez stemmera), `variants` (`nested`: `gross` brutto w groszach, brak bez ceny; `purchasable`; `options` jako
  `<oś>` U+001F `<wartość>` jak w Catalog, po nich dopasowuje się warunek wariantu; `pairs`: `nested` w wariancie,
  obiekt `{axis, value}` na parę, po nich liczą się fasety osi), `from_gross` (cena „od”, brak bez wariantu możliwego do kupienia)
  i `status` (`keyword`, `published` albo `draft`: szkic zostaje w indeksie jako trwały tombstone z wersją, a każde
  zapytanie sklepu ma `term status: published` w `query`, więc wyniki, `total` i agregacje widzą tylko opublikowane).
  Ceny i dostępność daje port `ProductOfferFacts` (`Catalog_Application`) z adapterem `QueryBusProductOfferFacts`
  w `Cart_Infrastructure` (Cart `GetProductOffers` na `query.bus`, paczki po 48): reguła „możliwy do kupienia” i cena
  „od” są tylko w Cart. Produkt bez faktów nie ma cen ani nic do kupienia. Catalog nie importuje Cart, Pricing ani
  Inventory.
- Zapis produktu do indeksu robi `ProductIndexing::reindex` (`Catalog_Application`, poza `command.bus`): znacznik
  czasu w mikrosekundach pobrany przed odczytem (DBAL Catalog i `ProductOfferFacts`) jest wersją dokumentu
  z `version_type=external_gte`, więc zapis starszego odczytu po nowszym to wynik „przestarzały” (także spóźniony zapis
  opublikowanego stanu po nowszym zapisie szkicu). Zapisuje każdy produkt, szkic jako dokument `draft`; przebudowa
  indeksuje wszystkie. Wyzwalacze na transporcie `events`: `IndexProduct` (`ProductCreated` i integracyjne
  `ProductChanged`; brak produktu to `failed`), `ReindexProductOnPriceChange`
  (`Pricing_Infrastructure`, `VariantPriceChanged`) i `ReindexProductOnStockLevelChange` (`Inventory_Infrastructure`,
  `StockLevelChanged`): produkt z Catalog `GetVariant`, wariant nieznany, brak produktu i źródło `seed` to wiadomość
  obsłużona bez zapisu; `SearchUnavailable` i `OfferFactsUnavailable` są ponawiane, `SearchIndexError` idzie do
  `failed`. Przebudowa (`catalog:reindex`) pobiera fakty ofert każdej paczki; ich błąd przerywa przebudowę, a zmiany
  cen i stanów obsłużone w jej trakcie trafiają do starego indeksu (przebudowa nie jest online).

- `GET /api/v1/products` (`listProducts`) szuka i filtruje w indeksie (`ElasticsearchProductSearch`, zapytanie składa
  `ProductSearchQuery`). Parametry, każdy niepoprawny to 400 z `violations`: `category` (UUID, z podkategoriami; nieznana
  to 404), `q` (fraza, po przycięciu 2–100 znaków), `option` (powtarzany klucz `<oś>:<wartość>`, podział na pierwszym
  `:`, do 50 wpisów, do 5 różnych osi, oś 1–30 i wartość 1–40 znaków; formę `option[]` odrzuca; PHP zostawia przy
  powtórzonym kluczu tylko ostatnią wartość, więc `OptionFilter` czyta surowy `QUERY_STRING`, jak `productId` w ofertach),
  `priceMin` i `priceMax` (grosze, liczby całkowite ≥ 0, `priceMin ≤ priceMax`; w DTO to napisy z `Regex`, bo denormalizer
  zapytania ucinałby `10.5` do `10`), `available` (tylko `true`), `sort` (`relevance` tylko z `q` i domyślne z `q`, `name`
  domyślne bez `q`, `price_asc`, `price_desc`), `page`, `perPage` (jak dotąd). Fraza: wszystkie słowa muszą pasować
  (nazwa, nazwy kategorii, krótki opis; odmiana, brak polskich znaków, literówki w gałęzi o wadze 0,3). Warunki na
  wariantach (`option`, `priceMin`/`priceMax`, `available`) dotyczą jednego wariantu (jedno `nested` w `post_filter`);
  cena i dostępność liczą się tylko dla wariantów możliwych do kupienia. Kilka wartości jednej osi to „lub”, różne osie
  „i”. Sortowanie po cenie idzie po `from_gross` (produkty bez ceny na końcu), remisy po `name.sort` i `id`.
  Odpowiedź ma `facets`: `categories` (dzieci kategorii z filtra albo kategorie główne, z pełnym warunkiem wariantu, w
  kolejności drzewa), `options` (osie do 10, wartości do 30 na oś; liczba przy wartości to liczba produktów z wariantem
  o tej wartości spełniającym pozostałe warunki, osi wybranej bez jej własnego warunku, więc dla osi bez wyboru jest to
  `total` po dodaniu wartości; wybrana wartość zostaje z 0), `price` (min i max brutto wariantów możliwych do kupienia
  spełniających warunki osi, bez warunku ceny; `null` bez takich), `availableCount` (`total` po dodaniu `available=true`).
  Nagłówki bez zmian (`public, max-age=60`, `xkey`). Osie bez wyboru liczy jedna agregacja: `nested` `variants` →
  filtr warunków wariantu → `nested` `variants.pairs` → `terms` osi (10) → `terms` wartości (30), oba sortowane po liczbie
  produktów z `reverse_nested`, potem po nazwie (bez globalnego limitu par); osie wybrane mają osobne agregacje.
  Kolejność osi: liczba produktów w wynikach malejąco, potem nazwa; oś wybrana zawsze jest w liście.

- Pliki (zdjęcia produktów) przez Flysystem na Garage, prywatny bucket. Nigdy na lokalny dysk kontenera.
- Upload: walidacja typu po zawartości (MIME), limit rozmiaru i wymiarów. `POST /api/v1/admin/product-images`
  (`multipart/form-data`, pole `file`, jeden plik na żądanie) przyjmuje JPEG, PNG, WebP, AVIF i HEIC do 64 MB i 50 MP,
  zapisuje oryginał pod kluczem z treści (`originals/<sha256>.<rozszerzenie>`, ten sam plik to ten sam klucz) i odpowiada
  kluczem oraz podglądem `thumbnail`; oryginałów nie usuwa nic (także po usunięciu zdjęcia z produktu albo uploadzie bez
  zapisu). Limity PHP w `docker/php/conf.d/app.ini`: `upload_max_filesize = 64M`, `post_max_size = 70M` (Caddy nie ma
  własnego limitu ciała) oraz `display_errors = Off`, `log_errors = On` (obraz nie ma `php.ini`, a ostrzeżenie PHP o
  żądaniu ponad `post_max_size` pojawia się przy starcie żądania: wypisane do odpowiedzi wysłałoby status 200
  i nagłówki przed 422). Plik ponad `upload_max_filesize` (błąd uploadu PHP) i żądanie z `Content-Length` ponad
  `post_max_size` (PHP odrzuca wtedy całe ciało, więc kontroler sprawdza nagłówek przed szukaniem pliku) to 422
  „Plik jest za duży.”; komunikaty `detail` odrzuceń (typ, rozdzielczość, wymiary, brak pliku) są po polsku. Garage
  niedostępny przy uploadzie i przy zapisie produktu (`ProductImageStorage::exists` sprawdza każdy klucz zdjęcia)
  to 503 bez `detail`. Limity PHP nie działają w kernelu testów funkcjonalnych, więc testy symulują ich skutek (błąd
  uploadu, nagłówek), a prawdziwe limity sprawdza E2E na działającym stosie.
- Rozmiary obrazów (`ImageSize`): `listing`, `product` i `thumbnail` (1:1, szerokości 96 i 192, WebP, `fill`; miniatury
  list i podglądy formularza produktu w panelu). Nowy rozmiar dopisuje się tylko tam.
- Produkty w panelu (`/api/v1/admin`, voter `ProductVoter`: `VIEW_PRODUCTS` każdy pracownik, `MANAGE_PRODUCTS` `admin`
  i `marketing`, Catalog UI): `GET /products` (`q`, `category`, `status`, `after`, `perPage`; zapytanie
  `ListBackofficeProducts` składa stronę z `SearchBackofficeProducts` i podsumowań z `BackofficeProductSource::summaries`),
  `GET /products/{id}` (`ProductEditView` z `version`, czytany z DBAL, także szkic), `POST /products` (szkic),
  `PUT /products/{id}`, `POST /products/{id}/publish` i `/unpublish` (wszystkie z `version`), `GET /tax-classes`
  (nazwy po polsku w `ListTaxClassesHandler`). Ciało zapisu waliduje jedno ograniczenie klasy `ValidProductWrite`
  (`Catalog_UI`): każda reguła z `docs/features/catalog-admin/spec.md` ma ścieżkę pola (`variants[3].values[1]`) i polski
  komunikat; reguły domeny stosuje, nie kopiuje (`Slug`, `ImageKey`, `VariantValues::comparisonKey()`). Reguły domeny, które
  wskazują miejsce w ciele (`ImageNotFound`, `UnknownVariant`, `UnknownCategory`, `SlugRequired`), `ProductWriteFailures`
  zamienia na 422 z naruszeniem pola; błędy z typem (409) opisuje schemat `ProductConflictProblem`. Odpowiedzi zapisu to
  widok edycji odczytany po zapisie, `private, no-store`.
- Adresy obrazów: jeden serwis buduje podpisane (HMAC) adresy imgproxy dla zamkniętej listy rozmiarów.
  Odpowiedzi API zwracają gotowe adresy, fronty niczego nie podpisują.
- Logi przez Monolog w JSON. Correlation ID z nagłówka HTTP przekazywany w stemplu Messengera do konsumentów.
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
- Nigdy nie edytuj migracji, która jest już w repo. Utwórz nową.
- Wyszukiwanie produktów po nazwie w panelu (`SearchBackofficeProducts`) używa rozszerzenia PostgreSQL `pg_trgm`
  (indeks GIN `gin_trgm_ops` na `catalog_product.name`, tworzony migracją) i stronicuje kursorem keyset po `(name, id)`.
  Indeks GIN obsługuje frazy od 3 znaków; fraza 2-znakowa (minimum w API) nie ma trigramów, więc idzie skanem indeksu
  `(name, id)` z filtrem `ILIKE`.
  Rozszerzenie musi być dostępne w bazie, każda baza przechodzi przez migracje.
- Profilowanie: SPX (php-spx) jest tylko w obrazie deweloperskim (target `dev` w Dockerfile), nigdy w obrazie produkcyjnym.
  Przy problemie z wydajnością najpierw zmierz w SPX, potem optymalizuj.
- Obraz Docker (`apps/api/Dockerfile`), etapy po kolei: `runtime` (FrankenPHP, rozszerzenia PHP, `app.ini`, Caddyfile),
  `base` z `runtime` (Composer, git, unzip, entrypoint `composer install` przy zmianie `composer.lock`), `build`
  z `base` (`composer install --no-dev --classmap-authoritative`, kod aplikacji, rozgrzany cache `prod`), `prod`
  z `runtime` (kod i `vendor` z `build`, `docker/php/conf.d/prod.ini` z opcache bez sprawdzania znaczników czasu,
  `APP_ENV=prod`, `APP_DEBUG=0`, bez Composera, gita, SPX, pakietów dev i entrypointu instalującego zależności;
  `fixtures/` jest w obrazie, `tests/` nie) i `dev` z `base` jako ostatni etap (compose i CI budują `target: dev`).
  Produkcyjny obraz buduje się `docker build --target prod apps/api` bez stosu i bez sekretów: cache `prod` rozgrzewa
  komenda z atrapami `SHOP_DB_*` ustawionymi tylko dla niej (Doctrine czyta parametry połączenia, ale się nie łączy,
  a w cache zostają odwołania do zmiennych środowiska, nie ich wartości). Konfigurację obraz czyta ze zmiennych
  środowiska przy starcie. Ten sam obraz uruchamia FrankenPHP, workera i komendy konsoli (np. `bin/seed-demo`).
  Zmiana w `.dockerignore` albo nowy katalog z kodem potrzebnym w `prod` wymaga sprawdzenia, że obraz `prod` nadal
  się buduje i odpowiada na `/health`.

## Zgodność wersji

Wdrożenie wymienia pody po kolei (`maxUnavailable: 0`), a migracje uruchamia Job przed startem nowej wersji, więc przez
chwilę poprzednia wersja kodu działa na nowym schemacie, a nowa i poprzednia wersja workera wymieniają wiadomości.

- Migracja jest zgodna wstecz z poprzednią wersją kodu: poprzednia wersja musi działać na schemacie po migracji. Zmianę
  niezgodną (usunięcie lub zmiana nazwy kolumny albo tabeli, nowe ograniczenie `NOT NULL` bez wartości domyślnej,
  zmiana typu) rozbij na dwie wersje: najpierw dodaj nowe i zacznij je zapisywać, w kolejnej wersji usuń stare.
  Schemat jest zgodny wstecz tylko z poprzednią wersją: cofnięcie o więcej wersji wymaga kopii zapasowej.
- Wiadomości Messengera (zdarzenia w `outbox` i `events`, komendy w `scheduled` i `payment_simulator`) są zgodne w obie
  strony między kolejnymi wersjami: nowa wersja czyta wiadomości zapisane przez poprzednią (kolejka może je jeszcze
  mieć) i poprzednia czyta wiadomości nowej (stary worker działa do końca wymiany). Pola dodawaj jako opcjonalne
  z wartością domyślną, nie usuwaj i nie zmieniaj znaczenia pól w jednej wersji, nie zmieniaj nazw klas wiadomości
  i nazw transportów.

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
