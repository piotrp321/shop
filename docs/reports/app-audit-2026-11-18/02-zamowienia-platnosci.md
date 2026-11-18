# 02. Poprawność biznesowa zamówień, checkoutu i płatności

Audyt statyczny migawki `main` (`ef204620`). Ścieżki względem katalogu eksportu.

## Zakres i metoda

Obszar: `apps/api/src/Ordering`, `apps/api/src/Payment`, `apps/api/src/PaymentSimulator` i ich styki z koszykiem,
cenami, magazynem i Messengerem.

Przeczytane (około 95 plików):

- Specyfikacje: `docs/features/checkout/spec.md`, `docs/features/payment/spec.md`,
  `docs/features/order-management/spec.md` (model, komendy, persystencja, API), fragmenty
  `docs/features/discount-codes/spec.md` (zaokrąglenia, współbieżność), `docs/vision.md` (wymagania niefunkcjonalne,
  „Poza zakresem”), `apps/api/CLAUDE.md` (sekcje Ordering i Payment).
- Ordering, w całości: agregat `Order`, `OrderStatus`, `Refund`, value objecty kwot (`Money`, `PriceCopy`,
  `LineAmounts`, `OrderLine`, `OrderTotals`), `Contact`, `RequestFingerprint`, wszystkie handlery komend
  (`PlaceOrder`, `MarkOrderPaid`, `ExpireUnpaidOrder`, `ChangeOrderStatus`, `CompleteRefund`, `StaffOrderLock`),
  `GetOrderForPayment`, `GetOrderQueues`, handlery e-maili i `NotificationSender`, `DoctrineOrderRepository`,
  `DbalOrderNotifications`, `DbalOrderNumbers`, `DbalSalesHours`, `DbalCustomerSummaries`, `DbalBackofficeOrderReader`,
  adaptery szyn (Cart, Pricing, Inventory, Messenger), kontrolery złożenia i zmiany statusu.
- Payment, w całości: agregat `Payment`, `OrderToPay`, `Amount`, oba handlery komend, `GetPaymentStatus`,
  `DoctrinePaymentRepository`, port `PaymentProvider`, oba adaptery, `ProviderHttp`, `WebhookFields`, `CardAmount`,
  kontrolery (webhook, gość, wspólne `PaymentEndpoints`), adaptery do Ordering.
- PaymentSimulator: `Transaction`, `Transactions`, decyzja, anulowanie, dostawa webhooka, budowa i podpis webhooka,
  kontroler API przelewu, endpoint decyzji strony płatności.
- Styki: `ReserveStockHandler`, `ReservationTransitionHandler`, `lockMany` i reguły `StockItem` (Inventory),
  `LockCartForOrderHandler`, `ClearCartAfterOrderHandler` (Cart), `GetVariantSummariesHandler` (Catalog), outbox
  (`MessengerDomainEventPublisher`, `RelayDomainEventToBroker`, `RoutedAmqpTransport`).
- Konfiguracja: `apps/api/config/packages/messenger.yaml`, `rate_limiter.yaml`, `doctrine.yaml`, `sentry.yaml`,
  polecenie workera w `infra/docker-compose.yml` i `infra/k8s/charts/shop/templates/api.yaml`, lista alertów
  w `00-alerts.yaml`.
- Migracje: ograniczenia i indeksy tabel `ordering_*` i `payment_*` (przez `grep`, 16 plików).
- Sklep: `apps/store/src/checkout/bff.ts`, `PaymentConfirmation.tsx`, użycie `Idempotency-Key` w `CheckoutPage.tsx`.
- Testy: tylko lista plików i nazwy testów (`tests/Integration/Ordering`, `tests/Integration/Payment`), żeby sprawdzić,
  które scenariusze mają pokrycie.

Metoda: prześledzenie każdej ścieżki od kontrolera do zapisu, potem próba złamania jej scenariuszami z zadania
(awaria w połowie, powtórzenie, wyścig, zła kolejność). Każde znalezisko sprawdziłem w kodzie wywołującym,
w ograniczeniach bazy i w specyfikacji (czy to świadomy kompromis).

## Ocena ogólna obszaru

**8 / 10.**

Rdzeń jest zrobiony wyraźnie powyżej tego, czego wymaga sklep tej skali: jedna transakcja na złożenie, opłacenie
i anulowanie, outbox i opóźniona wiadomość anulowania zapisane razem z zamówieniem, idempotencja z odciskiem żądania,
blokady w ustalonej kolejności, deduplikacja webhooków pod blokadą wiersza płatności, kwoty wyłącznie w groszach jako
`int`, ograniczenia `CHECK` powielające niezmienniki agregatu. Nie znalazłem ścieżki, która tworzy dwa zamówienia,
opłaca anulowane zamówienie, zwalnia rezerwację opłaconego albo gubi zdarzenie. Scenariusze z zadania (podwójne
kliknięcie, ponowiony webhook, webhook poza kolejnością, webhook po anulowaniu, ponowione wiadomości) kończą się
poprawnie i mają testy, także współbieżności.

Punkty odjęte są za warstwę operacyjną, nie za model:

- anulowanie nieopłaconego zamówienia nie uzgadnia się z płatnością, a kolejność transportów jednego workera
  sprawia, że po przestoju anulowanie wyprzedza potwierdzenie płatności wykonanej w terminie;
- awaria RabbitMQ najpewniej zatrzymuje także transporty, które RabbitMQ nie używają;
- sytuacje „pieniądze są, zamówienia nie ma” kończą się wpisem w kolumnie i w logu, bez żadnego odbiorcy;
- nie istnieje integracja z prawdziwym operatorem (świadomie, `docs/vision.md:366`), a port jest skrojony pod
  zachowanie symulatora.

## Znaleziska

### 1. Anulowanie nieopłaconych wyprzedza potwierdzenie płatności wykonanej w terminie

- **Waga:** Medium (przy prawdziwym operatorze High)
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Ordering/Application/Command/ExpireUnpaidOrderHandler.php:65-73`: handler decyduje wyłącznie po
    stanie zamówienia i zegarze, o płatność nie pyta nikogo:
    ```php
    switch ($order->expireUnpaid($this->clock->now())) {
        case ExpiryResult::Cancelled:
            $this->reservations->release($order->id());
            $this->redemptions->release($order->id());
            $this->orders->save($order);
    ```
  - `apps/api/src/Ordering/Domain/Order.php:181-189`: reguła domeny mówi co innego niż skutek: o opłaceniu ma decydować
    czas wykonania u dostawcy (`$executedAt > $this->paymentDeadline`), a anulowane zamówienie zwraca
    `PaymentResult::OrderCancelled`.
  - `infra/docker-compose.yml:350` i `infra/k8s/charts/shop/templates/api.yaml:136`: jeden proces
    `messenger:consume outbox events scheduled payment_simulator promotions jobs`. Worker Symfony po obsłużeniu
    wiadomości wraca na początek listy, więc `scheduled` (anulowania) ma zawsze pierwszeństwo przed
    `payment_simulator` (dostawy webhooków).
  - `apps/api/config/packages/messenger.yaml:68-75`: dostawa webhooka ma 5 ponowień w około 2 minuty, potem `failed`.
    `apps/api/src/PaymentSimulator/DeliverSimulatedWebhookHandler.php:60-70`: 5xx i błąd sieci rzucają wyjątek, nic
    poza transportem dostawy nie ponawia.
- **Warunki i prawdopodobieństwo:**
  - (a) Worker stoi dłużej niż okno 5 minut wokół terminu (restart z błędem, wdrożenie, zapchana kolejka `events`).
    Klient płaci w 29. minucie, worker wraca w 36.: obie wiadomości są dostępne, anulowanie idzie pierwsze. Dla
    płatności wykonanej wcześniej potrzebny jest dłuższy przestój (do 35 minut). W zwykłej pracy rzadkie, przy
    wdrożeniu albo awarii realne.
  - (b) API nie odpowiada na webhook dłużej niż około 2 minuty (wdrożenie, przeciążenie, limit 429 przez 5 prób):
    dostawa trafia do `failed` i nie wraca sama. Zamówienie zostaje anulowane po 35 minutach.
- **Konsekwencja:** klient zapłacił w terminie, a dostaje e-mail „Nie otrzymaliśmy płatności w terminie”, zamówienie
  jest `cancelled`, towar wraca do sprzedaży, płatność zostaje `succeeded` z `refund_required_reason =
  order_cancelled` (znalezisko 3). W demo nie ma pieniędzy, więc szkoda to błędny stan i mylący e-mail. Przy prawdziwym
  operatorze to pobrane pieniądze bez zamówienia: jego ponowienia webhooków trwają godziny, więc okno 5 minut jest za
  krótkie także bez udziału workera.
- **Rekomendacja:**
  1. Najtaniej: zamienić kolejność na `… payment_simulator scheduled …`, a najlepiej wydzielić transporty Doctrine do
     osobnego procesu workera (patrz znalezisko 2).
  2. Docelowo: przed anulowaniem uzgodnić stan z Payment. Ordering nie zależy od Payment, więc port w Ordering
     („czy zamówienie ma płatność w toku”) z adapterem po stronie Payment albo odwrócenie: Payment zamyka swoje sesje
     i dopiero potem prosi Ordering o anulowanie. Do tego metoda portu `PaymentProvider` odpytująca dostawcę o stan
     sesji.
- **Pewność:** wysoka co do kodu handlera, konfiguracji i reguły domeny. Średnia co do kolejności obsługi transportów
  w workerze: znam ją z zachowania `Symfony\Component\Messenger\Worker`, ale `vendor` nie ma w eksporcie, a testu
  uruchomić nie mogłem. Specyfikacja (`docs/features/payment/spec.md:653-654`) wspomina tylko, że długa kolejka zdarzeń
  opóźnia webhooki symulatora; o pierwszeństwie anulowania i o `failed` dla dostawy w kontekście anulowania nie mówi.
  Test `testW3…` (`tests/Integration/Payment/Concurrency/WebhookConcurrencyTest.php:131`) sprawdza wyścig, nie kolejność
  po przestoju.

### 2. Awaria RabbitMQ zatrzymuje także anulowania, webhooki symulatora i granice promocji

- **Waga:** Medium
- **Klasa:** RYZYKO
- **Dowód:**
  - `infra/docker-compose.yml:350`, `infra/k8s/charts/shop/templates/api.yaml:136`: transport `events` (AMQP) stoi na
    liście przed `scheduled`, `payment_simulator`, `promotions` i `jobs`, wszystko w jednym procesie.
  - `apps/api/config/packages/messenger.yaml:40-41`: `events` to `amqp://…`; pozostałe cztery to `doctrine://default`.
  - `apps/api/src/Shared/Infrastructure/Messaging/RoutedAmqpTransport.php`: nadpisuje tylko `send()`, odbiór jest
    standardowy.
- **Warunki i prawdopodobieństwo:** RabbitMQ niedostępny (restart, brak dysku, awaria węzła). Odbiór z `events`
  kończy się wtedy wyjątkiem transportu, którego pętla workera nie łapie, więc proces kończy pracę i jest
  restartowany. W każdym przebiegu dochodzi najwyżej do `outbox`, potem znów pada na `events`. Na jednym VPS-ie
  z jedną instancją brokera to scenariusz prawdopodobny w skali miesięcy.
- **Konsekwencja:** przez cały czas awarii brokera nie wykonuje się nic z transportów Doctrine, choć od RabbitMQ nie
  zależą: nieopłacone zamówienia nie są anulowane i trzymają rezerwacje (towar niedostępny dla innych), płatności
  w symulatorze nie są potwierdzane (klient widzi „Czekamy na potwierdzenie płatności”), granice okresów promocji
  i zadania w tle czekają. Po powrocie brokera worker obsługuje transporty w kolejności ze znaleziska 1, więc
  zamówienia opłacone podczas awarii są anulowane. Sama kasa działa: złożenie i rozpoczęcie płatności nie używają
  RabbitMQ (to mocna strona).
- **Rekomendacja:** dwa procesy workera: jeden dla `outbox events`, drugi dla `payment_simulator scheduled promotions
  jobs` (w compose i w chartcie, w tym samym PR). Koszt to jeden kontener; zysk to niezależność anulowań i płatności
  od brokera, czyli to, co wybór transportu Doctrine miał dać.
- **Pewność:** średnia. Układ transportów i ich DSN-y są w kodzie; zachowanie workera przy niedostępnym brokerze
  wynika ze znajomości `AmqpReceiver` i `Worker`, nie z uruchomienia. Potwierdzi to test: zatrzymać RabbitMQ
  i sprawdzić, czy `ExpireUnpaidOrder` po terminie zostaje obsłużone. Specyfikacje opisują politykę ponowień `outbox`
  (`docs/features/walking-skeleton/spec.md:361-363`), tego skutku nie.

### 3. Płatności oznaczone do zwrotu nie mają żadnego odbiorcy

- **Waga:** Medium (świadomy kompromis, dopuszczalny tylko z symulatorem)
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/src/Payment/Application/Command/HandleProviderWebhookHandler.php:79-88`: jedyny skutek to pole i log:
    ```php
    $refund = $this->orders->markPaid(...)->refundReason();
    if (null !== $refund) {
        $payment->requireRefund($refund);
        $this->logger->error('Payment webhook: the payment cannot pay its order and requires a manual refund.', [
    ```
  - Wyszukanie `refund_required_reason|refundRequiredReason` w `apps/api/src`, `apps/admin/src`, `apps/store/src`:
    tylko agregat, rekord i repozytorium Payment. Nie ma zapytania, endpointu, ekranu, licznika na pulpicie
    (`GetOrderQueuesHandler.php:33-36` zna tylko zwroty Ordering) ani alertu (`00-alerts.yaml`: kopie zapasowe
    i klaster).
  - Klient w tej sytuacji dostaje e-mail o anulowaniu z powodu braku płatności (`SendOrderCancelled.php:47-50`,
    powód `payment_timeout`).
- **Świadoma decyzja:** tak, `docs/features/payment/spec.md:634-636` i `docs/features/order-management/spec.md:32-33`
  (#221). Błąd z logu trafia do Sentry (`config/packages/monolog.yaml`, handler od poziomu `error`), o ile DSN jest
  ustawiony.
- **Konsekwencja:** trzy przypadki (płatność po terminie, płatność po anulowaniu, druga płatność) kończą się stanem,
  o którym wie tylko Sentry i baza. Pracownik obsługi nie ma jak się o nim dowiedzieć z panelu, klient dostaje
  nieprawdziwą informację. Znaleziska 1 i 2 zwiększają liczbę takich przypadków.
- **Rekomendacja:** minimum przed jakimkolwiek prawdziwym operatorem: licznik i lista w panelu (#221) oraz osobna
  treść e-maila dla zamówienia anulowanego, które ma płatność `succeeded`. Do tego czasu wystarczy alert na ten wpis
  logu.
- **Pewność:** wysoka (brak odbiorcy potwierdzony wyszukaniem w trzech aplikacjach i w szablonach alertów).

### 4. Zamówienie `new` nie ma wyjścia, gdy jego jedyna wiadomość anulowania przepadnie

- **Waga:** Low
- **Klasa:** RYZYKO
- **Dowód:**
  - `apps/api/config/packages/messenger.yaml:59-64`: po 5 ponowieniach (około 2 minut) `ExpireUnpaidOrder` trafia do
    `failed`, „and the order stays new”.
  - `apps/api/src/Ordering/Domain/OrderStatus.php:32`: `self::New, self::Cancelled, self::Returned => []`, czyli
    pracownik nie może nic zrobić z zamówieniem `new` (decyzja P2).
  - `apps/api/src/Ordering/Application/Query/GetOrderQueuesHandler.php:34`: licznik „czeka na płatność” liczy wszystkie
    `new`, bez rozróżnienia tych po terminie.
  - Brak polecenia konsoli, zadania okresowego i alertu na kolejkę `failed`; `messenger:failed:retry` nie występuje
    w runbooku `infra/k8s/README.md` ani w celach `make`.
- **Warunki i prawdopodobieństwo:** trwały błąd w handlerze przez 2 minuty: błąd logiczny Inventory
  (`ReservationNotFound`, `ReservationStateConflict`), błąd w nowej wersji kodu, zamówienie sprzed migracji (bez
  wiadomości, `docs/features/payment/spec.md:177-178`) albo skutek znaleziska 6. Niskie, ale skutek jest trwały
  i niewidoczny.
- **Konsekwencja:** zamówienie zostaje `new` na zawsze, rezerwacja i użycie kodu rabatowego nie wracają, sprzedawca
  traci dostępny stan i nie dostaje sygnału. Specyfikacja to przyznaje (`payment/spec.md:651-652`), ale jedyną
  drogą naprawy jest konsola na serwerze.
- **Rekomendacja:** okresowe polecenie, które dla zamówień `new` z `payment_deadline + 5 min < now` wysyła
  `ExpireUnpaidOrder` (handler jest idempotentny, indeks `(status, placed_at DESC, id DESC)` istnieje), i licznik
  takich zamówień na pulpicie. To kilkadziesiąt linii i usuwa zależność od jednej wiadomości.
- **Pewność:** wysoka.

### 5. Płatność `pending` zostaje `pending` na zawsze po wygaśnięciu zamówienia

- **Waga:** Low
- **Klasa:** BŁĄD (stan bez wyjścia w maszynie stanów płatności)
- **Dowód:**
  - `apps/api/src/Payment/Domain/Payment.php:213-220`: `pending` kończy tylko `end()`, wołane z `fail()` i `cancel()`.
  - Jedyni wywołujący: webhook (`HandleProviderWebhookHandler.php:57`) i nowa próba płatności
    (`StartPaymentHandler.php:130-131`).
  - `ExpireUnpaidOrderHandler` nie zna Payment, a symulator nie wysyła zdarzenia o wygaśnięciu sesji
    (`apps/api/src/PaymentSimulator/SimulatedWebhooks.php`: webhook tylko dla `Paid` i `Rejected`;
    `Transaction::isClosed()` liczy wygaśnięcie z zegara, bez zmiany statusu).
- **Ścieżka:** klient składa zamówienie, przechodzi na stronę dostawcy, zamyka kartę. Po 35 minutach zamówienie jest
  `cancelled`, a wiersz `payment_payment` ma `status = 'pending'` i `completed_at IS NULL` bez końca.
- **Konsekwencja:** funkcjonalnie nic się nie psuje (nowej płatności i tak nie da się zacząć, `resumeUrl` jest
  `null`). Szkoda jest w danych: każde porzucone zamówienie zostawia „oczekującą” płatność, więc raport albo
  uzgodnienie z operatorem oparte na `pending` jest bezużyteczne, a częściowy indeks unikalny rośnie o każdy taki
  wiersz. Przy docelowej skali to większość wierszy `pending`.
- **Rekomendacja:** zamykać płatność `pending`, gdy jej zamówienie przestaje być płatne: w tym samym miejscu, które
  rozwiąże znalezisko 1 (Payment uzgadnia sesję przed anulowaniem), albo osobnym okresowym poleceniem Payment.
- **Pewność:** wysoka (sprawdzeni wszyscy wywołujący `fail`/`cancel` i treść webhooków symulatora).

### 6. Odczyt zamówienia przechodzi przez fabryki walidujące dane wejściowe

- **Waga:** Low
- **Klasa:** RYZYKO
- **Dowód:** `apps/api/src/Ordering/Infrastructure/Persistence/Doctrine/DoctrineOrderRepository.php:276-294`:
  ```php
  $lines[] = OrderLine::of(
  ...
  contact: Contact::of(EmailAddress::fromString($record->email), $record->fullName, $record->phone),
  shippingAddress: ShippingAddress::inPoland($record->street, $record->postalCode, $record->city),
  ```
  To te same fabryki, które walidują żądanie złożenia (`Contact::of` sprawdza długość, znaki sterujące i wzorzec
  telefonu, `apps/api/src/Ordering/Domain/Contact.php`). Dla porównania `Payment::reconstitute` celowo „checks nothing”
  (`apps/api/src/Payment/Domain/Payment.php:41-43`).
- **Warunki i prawdopodobieństwo:** zaostrzenie reguły walidacji bez migracji danych (inny format telefonu, krótsze
  pole, nowa klasa znaków niedozwolonych; reguła znaków sterujących została już raz dodana po powstaniu tabeli).
  Dziś zapisane dane spełniają reguły, więc to ryzyko przyszłej zmiany.
- **Konsekwencja:** zamówienie, którego zapisane dane nie przechodzą nowej reguły, przestaje dać się odczytać
  wszędzie, gdzie potrzebny jest agregat: w panelu, w historii klienta, w `MarkOrderPaid` (webhook kończy się błędem)
  i w `ExpireUnpaidOrder` (wiadomość idzie do `failed`, patrz znalezisko 4). Wyjątek to `InvalidOrderInput`, mapowany
  na 422, więc w panelu wygląda jak błąd żądania.
- **Rekomendacja:** odtwarzać value objecty danych kontaktowych i adresu fabrykami bez walidacji wejścia
  (`reconstitute`), jak robi to Payment. Niezmienniki rachunkowe (`net + vat = gross`) mogą zostać: pilnuje ich też
  `CHECK` w bazie.
- **Pewność:** wysoka co do ścieżki kodu; skutek zależy od przyszłej zmiany reguł.

### 7. Port płatności jest skrojony pod symulator

- **Waga:** Low
- **Klasa:** PREFERENCJA (prawdziwi operatorzy są poza zakresem, `docs/vision.md:366`)
- **Dowód:** `apps/api/src/Payment/Application/Port/PaymentProvider.php:35-55`: trzy operacje (`createSession`,
  `cancelSession`, `parseWebhook`). W kodzie są wyłącznie dwa adaptery demo
  (`Payment/Infrastructure/Provider/DemoCardProvider.php`, `DemoTransferProvider.php`), oba wołają
  `/payment-simulator/...` w tym samym API.
- **Czego zabraknie przy prawdziwym operatorze:**
  - odpytania o stan sesji (uzgodnienie, weryfikacja po powiadomieniu, którą część operatorów wymaga) i zwrotu
    pieniędzy przez API;
  - zdarzeń innych niż „opłacone” i „odrzucone” (wygaśnięcie, zwrot, chargeback): `WebhookOutcome` ma dwa przypadki;
  - danych kupującego i opisu w `createSession` (jest tylko numer zamówienia; `PaymentId` adaptery pomijają, więc
    dostawca nie dostaje klucza idempotencji);
  - `cancelSession` z trzema wynikami zakłada, że operator umie zamknąć sesję i powiedzieć, czy była opłacona; reguła
    P5 („nowa próba dopiero po zamknięciu poprzedniej”) na tym stoi;
  - `executedAt` jest wymagany i zaufany (`ProviderEvent`), a okno na webhook ma 5 minut (znalezisko 1);
  - nieznana płatność to 404, a inna kwota to 422 z wycofaniem całości, bez śladu w bazie
    (`HandleProviderWebhookHandler.php:50`, `66-76`): przy prawdziwych pieniądzach wpłata w złej kwocie zostaje tylko
    w logu;
  - wywołania HTTP dostawcy idą w transakcji z blokadą wiersza płatności (`StartPaymentHandler.php:91-99`, do 2 × 5 s);
    specyfikacja to przyznaje (`payment/spec.md:643-644`);
  - limit webhooków liczony na adres nadawcy (`rate_limiter.yaml`, 300 na minutę).
- **Co jest przygotowane dobrze:** rejestr adapterów po kodzie, weryfikacja podpisu na surowej treści przed
  parsowaniem, osobny wyjątek portu zamiast HTTP w adapterach, kwoty bez `float` (`CardAmount`), adres powrotu
  budowany po stronie API, dwa różne formaty API i podpisu, które wymusiły prawdziwą abstrakcję.
- **Rekomendacja:** nie rozbudowywać portu na zapas. Zapisać w specyfikacji listę powyżej jako warunki wejścia dla
  pierwszego prawdziwego operatora, z punktem pierwszym: odpytanie o stan i uzgodnienie przed anulowaniem.
- **Pewność:** wysoka.

## Mocne strony

- **Jedna transakcja na złożenie.** Koszyk, użycie kodu, rezerwacja, zamówienie, outbox, wiadomość anulowania i licznik
  klienta są zapisywane razem (`PlaceOrderHandler.php:99-145`). Zamówienie jest zapisywane po rezerwacji, więc stan „jest
  zamówienie, nie ma rezerwacji” nie może powstać. Zagnieżdżone komendy idą przez savepointy DBAL 4, a handler nie
  łapie ich wyjątków.
- **Anulowanie nie zależy od brokera ani od crona.** `ExpireUnpaidOrder` to wiersz w tej samej bazie, zapisany
  w transakcji złożenia (`MessengerUnpaidOrderExpiry.php:27`), a handler zawsze decyduje po aktualnym stanie
  (`Order.php:207-222`), więc powtórna dostawa nic nie zmienia.
- **Idempotencja złożenia jest przemyślana do końca.** Sprawdzenie przed blokadą koszyka i jeszcze raz pod nią
  (`PlaceOrderHandler.php:99-113`), odcisk obejmuje koszyk, dane i kwotę (`RequestFingerprint.php`), a indeks unikalny
  `ordering_order_idempotency_key_key` łapie wyścig z dwóch koszyków. Sam klucz nigdy nie zwraca cudzego zamówienia.
- **Opłacenie i anulowanie nie mogą się rozjechać.** Webhook zmienia płatność i zamówienie w jednej transakcji,
  anulowanie blokuje wiersz zamówienia, a opłacenie zapisuje z wersją (`DoctrineOrderRepository.php:117-136`).
  Przegrany dostaje konflikt, wycofuje także wiersz deduplikacji i jest ponawiany. Nie ma przejścia
  `cancelled → paid`.
- **Webhooki:** blokada wiersza płatności, potem `INSERT … ON CONFLICT DO NOTHING` zdarzenia
  (`DoctrinePaymentRepository.php:127-134`), więc równoległe dostawy tego samego zdarzenia dają jedną zmianę. Odmowa po
  sukcesie nic nie zmienia, sukces po odmowie albo po anulowaniu wygrywa (`Payment.php:90-103`, „pieniądze ważniejsze”).
  Podpis sprawdzany `hash_equals` przed odczytem treści, pusty sekret odrzuca wszystko.
- **Jedna oczekująca płatność na zamówienie** wymuszona częściowym indeksem unikalnym
  (`payment_payment_one_pending_per_order`), a `StartPayment` po blokadzie czyta zamówienie ponownie
  (`StartPaymentHandler.php:90-93`), co zamyka wyścig z webhookiem poprzedniej płatności.
- **Kwoty.** Wyłącznie `int` w groszach, bez `float` także w adapterze z kwotą tekstową (`CardAmount.php`). Sumy liczy
  jedno miejsce (`OrderTotals`), linia pilnuje `gross + discount = unit × quantity` (`OrderLine.php`), a baza ma
  `CHECK (total_net + total_vat = total_gross)`. Kwota płatności pochodzi z zamówienia i jest sprawdzana dwa razy:
  w `Payment::succeed` i w `Order::markPaid`. Rabat nie sprowadzi sumy do zera (kod procentowy do 90%, kwotowy
  z minimum większym od kwoty).
- **Migawka.** Pozycja zamówienia kopiuje nazwę, etykietę wariantu, cenę jednostkową netto, VAT, brutto, stawkę, klasę
  podatkową, promocję i udział w rabacie; zamówienie kopiuje kontakt, adres i kod. Późniejsza zmiana ceny albo nazwy
  nie zmienia zamówienia. Żądanie nie niesie cen, a `expectedTotalGross` służy tylko do porównania.
- **Niezmienniki w bazie.** Ograniczenia `CHECK` wiążą status z datami, płatnością, powodem anulowania i zwrotem
  (`migrations/Version20261107140000AddOrderLifecycle.php:24-32`), więc błąd w kodzie nie zapisze niespójnego stanu.
- **E-maile poza agregatem.** Zajęcie wysyłki jednym warunkowym `UPSERT` z czasem ważności
  (`DbalOrderNotifications.php:33-68`) daje jeden e-mail przy równoległych dostawach i nie podnosi wersji zamówienia,
  więc nie tworzy fałszywych konfliktów w panelu.
- **Kolejność blokad jest opisana i spójna** (koszyk, kod, stany rosnąco po `variant_id`; zamówienie przed Inventory),
  a testy współbieżności obejmują złożenie, kod rabatowy, zmianę statusu, płatność i webhook
  (`tests/Integration/*/Concurrency`).
- **Maszyna stanów zamówienia** to jedna tabela w czystym PHP (`OrderStatus.php:26-34`), a specyfikacja uczciwie
  wymienia ryzyka, które przyjmuje.

## Zbędna złożoność

- Niewiele. Dwóch udawanych dostawców z różnymi API, podpisami i formatami kwot to około 1300 linii symulatora
  i 500 linii adapterów. Dla sklepu to dużo kodu, który niczego nie sprzedaje, ale jest to wymaganie z `docs/vision.md`
  i to ono wymusiło sensowny port.
- Modele odczytu `ordering_sales_hour` (16 kubełków na godzinę) i `ordering_customer` z protokołem przebudowy pod
  blokadą są rozbudowane jak na obecny ruch. Dopisują się w transakcji webhooka i złożenia, więc ich błąd wycofałby
  opłacenie albo złożenie zamówienia. Uzasadnia je docelowa skala; warto pamiętać o tym sprzężeniu.

## Braki

- Integracja z prawdziwym operatorem płatności: nie istnieje (świadomie). Stan gotowości portu: znalezisko 7.
- Uzgodnienie płatności z dostawcą i jakikolwiek widok płatności w panelu (dostawca, transakcja, zwroty Payment).
- Okresowe sprzątanie zamówień `new` po terminie i płatności `pending` (znaleziska 4 i 5), alert na kolejkę `failed`.
- Anulowanie zamówienia `new` przez klienta albo pracownika: rezerwacja trzyma towar do 35 minut bez możliwości
  zwolnienia (decyzje P2 i D16, opisane w ryzykach).
- Dostawa i jej koszt, faktury, akceptacja regulaminu, zwroty częściowe: poza zakresem MVP, ale bez nich to nie jest
  jeszcze proces sprzedaży prawdziwego sklepu.
- Dwa rejestry zwrotów (Ordering dla anulowania i zwrotu towaru, Payment dla płatności, których zamówienie nie
  przyjęło) bez wspólnego widoku (#221).

## Czego nie zweryfikowano

- Niczego nie uruchamiałem. Zachowanie workera Symfony (kolejność transportów, zakończenie procesu przy niedostępnym
  RabbitMQ) opieram na znajomości biblioteki; `vendor` nie ma w eksporcie. Znaleziska 1 i 2 wymagają potwierdzenia
  testem awarii.
- Treści testów nie czytałem, tylko ich listę i nazwy. Nie oceniam, czy asercje są wystarczające.
- Wydajność ścieżki złożenia i płatności przy docelowej skali (blokady wiersza stanu popularnego wariantu, transporty
  Doctrine z opóźnieniem, wywołania HTTP w transakcji): tylko analiza kodu, bez pomiaru.
- Rzeczywista konfiguracja środowiska produkcyjnego (liczba replik workera, DSN Sentry, limity) poza wartościami
  domyślnymi chartu.
- Poza moim obszarem i tylko pobieżnie: kontrola dostępu do zamówień (`OrderVoter`, token gościa w bazie jawnym
  tekstem), limity żądań, ekrany panelu i sklepu poza użyciem `Idempotency-Key`.
- Strona płatności symulatora (`PaymentPageController`, `PaymentPageHtml`) poza endpointem decyzji.
