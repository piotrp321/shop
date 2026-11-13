# ORDER-MANAGEMENT: obsługa zamówień w panelu i historia klienta

Punkt 7 „Zakresu MVP” z `docs/vision.md` (zamówienia ze statusami: nowe → opłacone → wysłane → dostarczone, plus
anulowanie i zwrot) i ścieżka E2E nr 2 (logowanie klienta przez Keycloak → historia zamówień).

Issues: #92
Zakres: `apps/api/src/Ordering`, `apps/api/src/Payment/Infrastructure/Ordering`, `apps/api/tests/Unit/Ordering`, `apps/api/tests/Integration/Ordering`, `apps/api/tests/Functional/Ordering`, `apps/api/tests/Integration/Payment`, `apps/api/tests/Functional/Payment`, `apps/api/deptrac.yaml`, `apps/api/config/packages/nelmio_api_doc.yaml`, `apps/api/config/packages/test_messaging/messenger.yaml`, `apps/api/phpunit.dist.xml`, `infra/docker-compose.yml`, `apps/api/openapi.json`, `apps/api/openapi-admin.json`, `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts`, `apps/admin/src/orders`, `apps/admin/src/router/index.ts`, `apps/admin/src/layout/AppMenu.vue`, `apps/admin/tests/AppMenu.test.ts`, `apps/admin/tests/router.test.ts`, `apps/store/src/account`, `apps/store/app/konto`, `apps/store/app/api/account`, `apps/store/src/api/client.ts`, `apps/store/src/api/client.test.ts`, `apps/store/src/checkout/PaymentConfirmation.tsx`, `apps/store/src/checkout/PaymentConfirmation.test.tsx`, `e2e/support`, `docs/vision.md`, `apps/api/CLAUDE.md`, `apps/admin/CLAUDE.md`, `apps/store/CLAUDE.md`, `docs/features/payment/spec.md`, `docs/features/checkout/spec.md`

**Zależność od PAYMENT (#91) i CHECKOUT (#90).** Zamówienie (`Order`, Ordering) ma statusy `new`, `paid`,
`cancelled`, wersję (optimistic locking), rezerwację stanów o kluczu `orderId` i przejścia jako metody agregatu
(`markPaid`, `expireUnpaid`). Inventory ma `CommitReservation(reservationKey)` (aktywna → zatwierdzona: `physical`
i `reserved` maleją; już zatwierdzona → bez zmian; zwolniona → `ReservationStateConflict`) i
`ReleaseReservation(reservationKey)` (`docs/features/inventory/spec.md`). Ordering zatwierdza i zwalnia rezerwację
przez port `StockReservations` (`commit`, `release`).

## Cel i zakres

- Pracownik panelu przegląda zamówienia (lista z filtrem statusu, filtrem zwrotów do wykonania i wyszukiwaniem,
  szczegóły z historią statusów) i zmienia ich status: wysłane, dostarczone, anulowanie, zwrot. Każda zmiana wskazuje
  wersję zamówienia z widoku (optimistic locking) i zapisuje pracownika z tokena.
- Wysyłka zatwierdza rezerwację stanów (towar schodzi z magazynu), anulowanie przed wysyłką ją zwalnia.
- Anulowanie opłaconego zamówienia i zwrot towaru zakładają zwrot pieniędzy do wykonania z terminem; pracownik
  oznacza go jako wykonany (sam zwrot jest ręczny, poza systemem).
- Klient zalogowany widzi w sklepie historię swoich zamówień i szczegóły każdego z nich.
- Klient dostaje e-maile o wysyłce, anulowaniu przez pracownika i przyjęciu zwrotu.
- Znaczniki wysłanych e-maili wychodzą z agregatu `Order` (bez podnoszenia wersji) i chronią przed równoległym
  wysłaniem tej samej wiadomości.

Poza zakresem:
- integracje z kurierami, numer przesyłki ze śledzeniem, faktury (`docs/vision.md`, „Poza zakresem”);
- zwrot pieniędzy u dostawcy płatności (dostawcy są udawani); system tylko śledzi zwrot ręczny (P3);
- zwroty płatności oznaczane przez Payment (`payment_payment.refund_required_reason`: po terminie, po anulowaniu,
  podwójne) w panelu i wspólny przegląd wszystkich zwrotów: #221;
- zwroty częściowe (części pozycji) i edycja pozycji, adresu albo danych kontaktowych zamówienia;
- anulowanie zamówienia przez klienta w sklepie; anulowanie zamówienia `new` z panelu (P2);
- historia zamówień gościa (zamówienie gościa nie jest wiązane z kontem, CHECKOUT P10, #118);
- płatność zamówienia z historii (P8);
- zamówienia w danych demo (P11).

## Decyzje

Decyzje użytkownika:

| # | Decyzja |
|---|---|
| P1 | Zwrot to zwrot towaru całego zamówienia, rejestrowany przez pracownika po odebraniu paczki: z `delivered` i z `shipped` (paczka wróciła niedoręczona). Bez zwrotów częściowych. |
| P2 | Pracownik anuluje tylko zamówienie `paid` (przed wysyłką). `new` wygasa samo po terminie płatności: anulowanie `new` z panelu zostawiałoby otwartą sesję u dostawcy, której Ordering nie zamknie (Payment zależy od Ordering, nie odwrotnie). Wysłanego nie da się anulować; wraca jako zwrot (P1). |
| P3 | Anulowanie przez pracownika i zwrot zakładają zwrot pieniędzy w zamówieniu: kwota (brutto zamówienia), status „do wykonania” → „wykonany”, termin (chwila przejścia + 14 dni), data wykonania, pracownik i opcjonalna notatka. Pracownik oznacza zwrot jako wykonany; samo przekazanie pieniędzy jest ręczne, poza systemem. Lista zamówień ma filtr „Zwroty do wykonania” (od najbliższego terminu, z oznaczeniem „Po terminie”). Zwroty oznaczane przez Payment zostają poza panelem (#221). Właścicielem zwrotów wywołanych przez pracownika (anulowanie, zwrot towaru) jest Ordering; Payment pozostaje właścicielem zwrotów płatności, których Ordering nie zna (po terminie, po anulowaniu, podwójne). #221 łączy oba źródła w jednym widoku panelu albo świadomie przenosi własność, nie buduje trzeciego rejestru. |
| P4 | Zwrócony towar nie wraca na stan automatycznie: magazyn robi przyjęcie w ekranie stanów, jeśli towar nadaje się do sprzedaży. |
| P5 | E-mail do klienta: wysłane, anulowane przez pracownika, zwrot przyjęty. Dostarczone i wykonanie zwrotu pieniędzy bez e-maila. E-maile nie podają terminu zwrotu pieniędzy (termin pilnuje panel). |
| P6 | Podgląd zamówień: `admin`, `support`, `warehouse`. Wysłane i dostarczone: `admin`, `warehouse`. Anulowanie, zwrot i wykonanie zwrotu pieniędzy: `admin`, `support`. `marketing` nie ma dostępu do zamówień. |
| P7 | Notatka przy zmianie: wymagana przy anulowaniu i zwrocie (1–500 znaków, jedna linia, tylko w panelu), opcjonalna przy wysyłce, dostarczeniu i wykonaniu zwrotu pieniędzy. |
| P8 | Bez płatności z historii klienta (jak w PAYMENT): historia pokazuje „Czeka na płatność do 14:35” albo „Termin płatności minął”. |
| P9 | Wyszukiwanie w panelu: dokładny numer (`R-100001`, bez względu na wielkość liter) albo dokładny e-mail (bez względu na wielkość liter); filtr statusu; od najnowszych; stronicowanie po kursorach. Bez wyszukiwania fragmentu. |
| P10 | Historia klienta: lista od najnowszych, po 10 z „Pokaż więcej” (numer, data, status, kwota, liczba sztuk); szczegóły: pozycje, sumy, adres, daty kolejnych statusów. |
| P12 | Strona potwierdzenia płatności w sklepie dla anulowanego zamówienia mówi neutralnie „Zamówienie zostało anulowane.” (A3 daje `cancelled` także po anulowaniu przez pracownika); przyczynę podaje e-mail. Payment nie poznaje przyczyny anulowania. |
| P13 | Szczegóły zamówienia w panelu pokazują `paymentId` opłacającej płatności (sekcja „Zwrot pieniędzy” i dane płatności), żeby pracownik wiedział, którą płatność zwraca. Dostawca i transakcja: #221. |
| P14 | E-mail po zwrocie nie sugeruje, że klient odesłał towar (zwrot z `shipped` to paczka niedoręczona): „Zamówienie R-100001 wróciło do nas. Zwrócimy płatność.”. |
| P11 | Bez zamówień w danych demo: panel po `make seed` jest pusty, E2E tworzy własne zamówienia. |
| A1 | Przejścia jako metody agregatu `Order` z tabelą dozwolonych przejść w czystym PHP, bez `symfony/workflow`: domena nie zależy od frameworka, a Workflow wymagałby publicznego ustawiania statusu albo powielenia reguł poza domeną. `docs/vision.md` zmienia opis Ordering. |
| A2 | Historia statusów jest częścią agregatu `Order` (wpisy tylko dopisywane) i zapisuje się razem z zamówieniem w tabeli podrzędnej. |
| A3 | Payment nie poznaje nowych statusów. `OrderForPayment` zamiast `status` ma pole `statusForPayment` (`new`, `paid`, `cancelled`): status zamówienia z punktu widzenia płatności, z mapowaniem w Ordering (`shipped`, `delivered`, `returned` → `paid`). Payment zmienia tylko odczyt nazwy pola w adapterze. |
| A4 | Wysłanie e-maila nie podnosi wersji zamówienia: znaczniki wysyłki są w osobnej tabeli powiadomień poza agregatem, z zajęciem wysyłki na czas (lease), które chroni przed równoległym wysłaniem tej samej wiadomości; testy obejmują ponowienia. |

## Model (Ordering Domain)

### Statusy i przejścia

| Status | Etykieta (panel i sklep) |
|---|---|
| `new` | Czeka na płatność |
| `paid` | Opłacone |
| `shipped` | Wysłane |
| `delivered` | Dostarczone |
| `cancelled` | Anulowane |
| `returned` | Zwrócone |

| Przejście | Z | Do | Kto | Skutek |
|---|---|---|---|---|
| opłacenie (`markPaid`) | `new` | `paid` | webhook płatności | — |
| wygaśnięcie (`expireUnpaid`) | `new` | `cancelled` (`payment_timeout`) | system | zwolnienie rezerwacji, potem zwolnienie użycia kodu rabatowego |
| `ship` | `paid` | `shipped` | pracownik | zatwierdzenie rezerwacji (`CommitReservation`), `OrderShipped` |
| `deliver` | `shipped` | `delivered` | pracownik | — |
| `cancel` | `paid` | `cancelled` (`staff`) | pracownik | zwolnienie rezerwacji, zwrot pieniędzy do wykonania, `OrderCancelled`; użycie kodu zostaje |
| `return` | `shipped`, `delivered` | `returned` | pracownik | zwrot pieniędzy do wykonania, `OrderReturned`; użycie kodu zostaje |

- Użycie kodu rabatowego (`docs/features/discount-codes/spec.md`, D4, Q1, A4) zwalnia tylko wygaśnięcie:
  `ExpireUnpaidOrderHandler` po zwolnieniu rezerwacji woła port `DiscountCodeRedemptions::release(orderId)` (Pricing
  `ReleaseDiscountCodeUsage`, zagnieżdżony dispatch, idempotentny), także dla zamówienia bez kodu (bez skutku), potem
  zapisuje zamówienie. Anulowanie opłaconego zamówienia przez pracownika i zwrot towaru nie zwalniają użycia (zwrot
  pieniędzy jest już po rabacie); `ChangeOrderStatusHandler` nie zna tego portu.

- Enum `StaffTransition` (`ship`, `deliver`, `cancel`, `return`) i `OrderStatus::allowedStaffTransitions()` to jedyne
  miejsce reguły przejść. Przejście spoza tabeli: `OrderTransitionNotAllowed` (409, `TypedProblem`
  `order-transition-not-allowed`, „Tej zmiany nie można wykonać w obecnym statusie zamówienia.”).
- `markPaid` traktuje `shipped`, `delivered` i `returned` jak `paid`: ten sam `paymentId` → `alreadyPaid`, inny →
  `paidByAnotherPayment`. `expireUnpaid` dla każdego statusu poza `new` → `notApplicable`.
- `CancellationReason`: `payment_timeout`, `staff`.
- Nowe pola: `shippedAt`, `deliveredAt`, `returnedAt` (null do przejścia; zwrot z `shipped` zostawia `deliveredAt`
  null), `refund` (P3, niżej). `paidAt` i `paymentId` zostają po anulowaniu i zwrocie. Kto wykonał przejście, mówi
  historia (bez osobnych pól).
- Agregat nie ma pól znaczników e-maili: są w tabeli powiadomień (A4, „E-maile”).
- Metody pracownika: `ship(StaffActor, ?note, now)`, `deliver(StaffActor, ?note, now)`, `cancel(StaffActor, note,
  now)`, `registerReturn(StaffActor, note, now)`, `completeRefund(StaffActor, ?note, now)`. Notatka (`StaffNote`):
  przycięta z białych znaków Unicode (`\s` i `\p{Z}`, także NBSP; `trim()` zna tylko ASCII), 1–500 znaków po
  przycięciu, bez znaków sterujących `\x00-\x1F` i `\x7F` (także nowej linii), U+0085, U+2028 i U+2029, i tylko
  poprawny UTF-8 (niepoprawny tekst to znak niedozwolony); znaki te są szukane w notatce w takiej postaci, w jakiej
  przyszła. Wymagana przy `cancel` i `registerReturn` (P7); notatka z samych białych znaków (także NBSP) to brak
  notatki: przy `cancel` i `registerReturn` „Notatka jest wymagana.”, przy pozostałych `null`; naruszenie to
  `InvalidOrderInput` (422). Żądania `ChangeOrderStatusRequest` i `CompleteRefundRequest` stosują tę samą regułę (limit
  długości liczony po tym samym przycięciu, „Notatka zawiera niedozwolone znaki.” przy `note`).

### Zwrot pieniędzy (P3)

- Value object `Refund {amount: Money, dueAt, completedAt: ?, completedBy: ?StaffActor, note: ?string}`; status
  „do wykonania” (`completedAt` null) albo „wykonany”.
- `cancel` i `registerReturn` zakładają `Refund` z `amount = totals.gross`, `dueAt = now + 14 dni`
  (`Order::REFUND_TIME = '+14 days'`). Zamówienie ma najwyżej jeden zwrot (każde z tych przejść kończy cykl).
- `completeRefund`: zwrot do wykonania → wykonany (`completedAt = now`, pracownik, notatka); brak zwrotu albo już
  wykonany: `RefundNotPending` (409, `TypedProblem` `refund-not-pending`, „To zamówienie nie ma zwrotu do
  wykonania.”). Wykonanie nie zmienia statusu zamówienia i nie dopisuje wpisu historii statusów.
- `refundOverdue(now)`: zwrot do wykonania i `now > dueAt`; liczy API.

### Historia statusów (A2)

- `OrderStatusChange {from: ?OrderStatus, to, occurredAt, actor: StatusActor, note: ?string}`; `StatusActor` to
  `system`, `customer` (złożenie, także gość) albo `staff(staffId, username)`. Złożenie zapisuje wpis `null → new`,
  opłacenie `new → paid` (`system`), wygaśnięcie `new → cancelled` (`system`), przejścia z panelu aktora `staff`
  z notatką.

### Wersja i blokady

- Komendy pracownika najpierw blokują wiersz zamówienia (`OrderRepository::lock`, `PESSIMISTIC_WRITE` z odświeżeniem
  rekordu z identity map, jak koszyk), potem porównują `expectedVersion` z żądania z wersją odczytaną pod blokadą;
  różna → `OrderModifiedConcurrently` (409). Powtórzenie udanej zmiany z tą samą wersją jest więc 409
  (jak korekta stanu). Blokada przed Inventory sprawia, że równoległe zmiany z tą samą wersją (np. `ship`
  i `cancel`) idą po kolei, a druga kończy się 409, nigdy `ReservationStateConflict` z Inventory.
- Kolejność blokad: wiersz zamówienia, potem rezerwacja i stany (Inventory). `ExpireUnpaidOrderHandler` też zaczyna
  od `lock`, więc wygaśnięcie i zmiana z panelu nie zakleszczają się. Zwolnienie użycia kodu zmienia tylko wiersz
  użycia (bez blokady kodu), więc wygaśnięcie nie tworzy cyklu ze złożeniem (koszyk → kod → stany). Webhook (`MarkOrderPaid`) nie
  dotyka Inventory i zostaje przy wersji.
- Pracownik zmienia tylko zamówienia opłacone (`paid` i dalej), więc nie konkuruje z `markPaid` ani
  z `ExpireUnpaidOrder` (te zmieniają tylko `new`). Kolejny webhook dla wysłanego, dostarczonego, zwróconego albo
  anulowanego przez pracownika zamówienia nie zmienia go (`alreadyPaid`, `paidByAnotherPayment`, `orderCancelled`;
  Payment oznacza płatność do zwrotu: `payment_payment.refund_required_reason`).
- E-maile nie zmieniają zamówienia (A4), więc nie dają fałszywych 409.

## Komendy i zapytania (Ordering Application)

- `ChangeOrderStatus(orderId, transition, expectedVersion, note, staffId, staffUsername)` (`command.bus`, jedna
  transakcja) → `BackofficeOrderView`:
  - nieznane zamówienie albo id spoza UUID: `OrderNotFound` (404);
  - blokada, wersja, metoda agregatu;
  - `ship`: port `StockReservations::commit(orderId)` (adapter `CommandBusStockReservations` →
    Inventory `CommitReservation`, zagnieżdżony dispatch) przed zapisem zamówienia;
  - `cancel`: `StockReservations::release(orderId)` przed zapisem;
  - błędy Inventory (`ReservationNotFound`, `ReservationStateConflict`) to błędy logiczne: 500, wycofanie całości;
  - zapis zamówienia z historią i zdarzeniem w outboxie.
- `CompleteRefund(orderId, expectedVersion, note, staffId, staffUsername)` (`command.bus`): blokada, wersja,
  `completeRefund`, zapis → `BackofficeOrderView`.
- `ListBackofficeOrders(status?, refund?, q?, after?, perPage)` (`query.bus`, DBAL, bez blokad):
  - `q` (przycięte, 1–254 znaki, inaczej 400): numer, gdy pasuje do `^[Rr]-[0-9]+$`, inaczej e-mail, gdy zawiera
    `@`; porównanie dokładne bez względu na wielkość liter (P9); inna wartość `q` to pusta lista;
  - bez `refund`: sort `placed_at DESC, id DESC`; `refund=pending`: tylko zwroty do wykonania, sort
    `refund_due_at ASC, id ASC` (łączy się ze `status` i `q`);
  - kursor keyset (base64url JSON pól sortowania), `perPage` 1–100 (domyślnie 25), zły kursor 400;
  - element: `orderId, number, placedAt, status, email, fullName, totalGross, currency, itemCount, refund:
    {amount, dueAt, completedAt, overdue} | null`.
- `GetBackofficeOrder(orderId)` → `BackofficeOrderView`: kontakt, adres, pozycje (z `grossBeforeDiscount`,
  `discountGross`, wartością po rabacie i kopią promocji `promotion {name, percentOff, regularUnitGross} | null`),
  kopia kodu `discount {code, kind, value, amountGross} | null`, sumy po rabacie, daty statusów,
  `paymentDeadline`, `cancellationReason`, `paidAt`, `paymentId` (P13), `version`, `history` (od najstarszego), `refund` (kwota, termin,
  `overdue`, wykonanie: data, pracownik, notatka), `statusTransitions` (`allowedStaffTransitions()` statusu).
- Reguła ról (P6) jest tylko w voterze `OrderVoter` (Ordering Infrastructure, wzorzec `StockVoter`). Kontrolery
  szczegółów, zmiany statusu i wykonania zwrotu zwracają `allowedTransitions` = `statusTransitions` przefiltrowane
  przez `isGranted(CHANGE_ORDER_STATUS, transition)` oraz `canCompleteRefund` = zwrot do wykonania
  i `isGranted(COMPLETE_REFUND)`; panel nie liczy reguł.
- `ListCustomerOrders(customerId, after?, perPage)` → elementy `orderId, number, placedAt, status, totalGross,
  currency, itemCount, paymentDeadline, paymentDeadlinePassed`; sort `placed_at DESC, id DESC`, kursor jak wyżej,
  `perPage` 1–50 (domyślnie 10). `paymentDeadlinePassed` liczy API (zegar).
- `GetCustomerOrder(orderId, customerId)` → `CustomerOrderView` albo null (nieznane, cudze, gościa, id spoza UUID:
  jeden wynik). Pozycje z `grossBeforeDiscount`, `discountGross` i wartością po rabacie, `discount | null` jak w panelu,
  sumy po rabacie; bez kopii promocji (Q5). Bez historii, notatek, danych pracowników i zwrotu; daty: `placedAt`, `paidAt`, `shippedAt`,
  `deliveredAt`, `cancelledAt`, `returnedAt`, oraz `paymentDeadline` i `paymentDeadlinePassed`.
- `GetOrderForPayment`: pole `statusForPayment` (A3).

## E-maile (P5, A4)

### Treści

Klasy treści w `Ordering_Application` (jak `PaymentReceivedText`), testowane jednostkowo. Notatka pracownika nie
trafia do e-maila.

- `OrderShipped` → handler `SendOrderShipped`: „Zamówienie R-100001 wysłane”: powitanie, numer, „Zamówienie zostało
  wysłane na adres:”, adres.
- `OrderCancelled` → `SendOrderCancelled` wybiera treść według `cancellationReason` zamówienia:
  `payment_timeout` → „Nie otrzymaliśmy płatności w terminie, więc anulowaliśmy zamówienie.”; `staff` → „Zamówienie R-100001 anulowane”: „Anulowaliśmy Twoje zamówienie. Zwrócimy
  płatność.”.
- `OrderReturned` → `SendReturnReceived`: „Zamówienie R-100001 wróciło do nas”: „Zamówienie R-100001 wróciło do nas.
  Zwrócimy płatność.” (P14).

### Znaczniki wysyłki poza agregatem

- Tabela `ordering_order_notification (order_id, kind, claimed_until NULL, sent_at NULL)`, PK `(order_id, kind)`,
  `kind`: `confirmation`, `payment_received`, `cancelled`, `shipped`, `return_received`. Port
  `OrderNotifications` (Ordering Application), adapter DBAL (jawny wyjątek od zapisu przez rekordy ORM, jak
  `payment_webhook_event`: warunkowy `INSERT … ON CONFLICT … DO UPDATE … WHERE … RETURNING` daje atomowe zajęcie bez
  naruszenia unikalności, które zamknęłoby `EntityManager`, i nie dotyka wersji zamówienia). Rekord Doctrine tylko
  definiuje schemat.
- Handler (`event.bus`, bez transakcji, każdy krok zatwierdzany od razu):
  1. `claim(orderId, kind, now)` zajmuje wysyłkę do `now + 5 min` (lease), gdy `sent_at IS NULL` i nikt jej nie
     trzyma (`claimed_until IS NULL OR claimed_until <= now`). Wynik: `claimed`, `alreadySent`, `inProgress(until)`.
  2. `alreadySent`: nic. `inProgress`: `RecoverableMessageHandlingException` z opóźnieniem ponowienia do `until`
     + 1 s (ponawiane zawsze, bez limitu prób transportu), więc wiadomość wraca, gdy zajmujący skończy albo jego
     lease wygaśnie.
  3. `claimed`: odczyt zamówienia, wysyłka, `markSent(orderId, kind, now)` (`sent_at`, `claimed_until` null).
     Błąd wysyłki: `release(orderId, kind)` (`claimed_until` null) i wyjątek dalej, transport ponawia (bez zmian
     w polityce `events`).
- Skutek: dwie równoległe dostawy tego samego zdarzenia wysyłają jeden e-mail. Awaria procesu między wysyłką a
  `markSent` daje drugi e-mail po wygaśnięciu lease (co najmniej raz); limit czasu SMTP jest krótszy niż
  lease (`MAILER_DSN` z `timeout`, `SmtpTimeoutTransportFactory`).
- Migracja przenosi istniejące znaczniki (`confirmation_sent_at`, `payment_notification_sent_at`,
  `cancellation_notification_sent_at`) do tabeli i usuwa te kolumny z `ordering_order`. Handlery
  `SendOrderConfirmation`, `SendPaymentReceived`, `SendOrderCancelled` przechodzą na port; nie zapisują zamówienia.
- Testy:
  - jednostkowe handlerów z portem w pamięci: już wysłane (nic), zajęte (wyjątek z opóźnieniem do końca lease), błąd
    wysyłki (zwolnienie i wyjątek; ponowienie wysyła raz), wygasły lease (wysyłka), zamówienie nieznane (błąd trwały, wiadomość trafia do `failed`);
  - integracyjne adaptera na prawdziwej bazie: równoległe `claim` z kilku połączeń → dokładnie jedno `claimed`;
    `claim` po `markSent` → `alreadySent`; po `release` i po wygaśnięciu lease → `claimed`;
  - integracyjny ponowień Messengera (`test_messaging`, transport z polityką `events`): zajęta wysyłka wraca
    z opóźnieniem do końca lease i po zwolnieniu kończy się jednym e-mailem; błąd SMTP ponawia i kończy się jednym
    e-mailem.

## Persystencja

- `ordering_order`: `shipped_at`, `delivered_at`, `returned_at`, `refund_amount bigint NULL`, `refund_due_at NULL`,
  `refund_completed_at NULL`, `refund_completed_by_staff_id NULL`, `refund_completed_by_username NULL`,
  `refund_note varchar(500) NULL`; `cancellation_reason` przyjmuje `staff`; bez kolumn znaczników e-maili (A4).
- Ograniczenia CHECK na `ordering_order` (opłacone zamówienie może być wysłane, zwrócone albo anulowane przez
  pracownika, a `paid_at` i `payment_id` zostają):
  - `(paid_at IS NULL) = (payment_id IS NULL)`;
  - `paid_at IS NOT NULL` ⇔ (`status IN ('paid','shipped','delivered','returned')` albo `cancellation_reason =
    'staff'`);
  - `shipped_at IS NOT NULL` ⇔ `status IN ('shipped','delivered','returned')`; `delivered_at IS NOT NULL` ⇒ `status
    IN ('delivered','returned')`; `returned_at IS NOT NULL` ⇔ `status = 'returned'`;
  - `refund_amount IS NOT NULL` ⇔ `refund_due_at IS NOT NULL` ⇔ (`status = 'returned'` albo
    `cancellation_reason = 'staff'`); `refund_completed_at`, `refund_completed_by_staff_id`,
    `refund_completed_by_username` razem null albo razem ustawione, tylko przy `refund_amount`; `refund_note` tylko
    przy wykonanym zwrocie.
- Indeksy: `(placed_at DESC, id DESC)`, `(status, placed_at DESC, id DESC)`, `(lower(email))`,
  `(customer_id, placed_at DESC, id DESC) WHERE owner_type = 'customer'`,
  `(refund_due_at, id) WHERE refund_amount IS NOT NULL AND refund_completed_at IS NULL`.
- `ordering_order_status_change`: `order_id` (FK do `ordering_order`), `position`, `from_status NULL`, `to_status`,
  `occurred_at`, `actor_type`, `staff_id NULL`, `staff_username NULL`, `note varchar(500) NULL`; PK
  `(order_id, position)`. Migracja dopisuje historię istniejących zamówień z ich kolumn (`placed_at`, `paid_at`,
  `cancelled_at`).
- `DbalOrderingReset` czyści też `ordering_order_status_change` i `ordering_order_notification`.

## API

Błędy RFC 9457. Odpowiedzi `private, no-store` (`OrderResponseCacheSubscriber` obejmuje nowe ścieżki). 409 panelu
mają osobny schemat `BackofficeOrderConflictProblem` w obszarze `admin` (`nelmio_api_doc.yaml`) z `enum` typów
`order-transition-not-allowed`, `refund-not-pending` i `about:blank` (konflikt wersji); `OrderConflictProblem`
sklepu się nie zmienia.

### Panel (`apps/api/openapi-admin.json`)

| Endpoint | Uprawnienie (`OrderVoter`) | Sukces | Błędy |
|---|---|---|---|
| `GET /api/v1/admin/orders?status=&refund=&q=&after=&perPage=` | `VIEW_ORDERS` | 200 `{items, nextCursor}` | 400 parametry, 401, 403, 503 |
| `GET /api/v1/admin/orders/{orderId}` | `VIEW_ORDERS` | 200 `BackofficeOrder` | 401, 403, 404, 503 |
| `POST /api/v1/admin/orders/{orderId}/status-changes`, body `{transition, version, note}` | `VIEW_ORDERS` i `CHANGE_ORDER_STATUS` dla przejścia | 200 `BackofficeOrder` | 400 zły JSON, 401, 403, 404, 409 (przejście, wersja), 415, 422, 503 |
| `POST /api/v1/admin/orders/{orderId}/refund-completion`, body `{version, note}` | `COMPLETE_REFUND` | 200 `BackofficeOrder` | 400, 401, 403, 404, 409 (`refund-not-pending`, wersja), 415, 422, 503 |

- Typy ścisłe (jak korekta stanu): `transition` spoza enum, `version` nie liczba całkowita ≥ 1, `note` nie string
  ani null: 422.
- Kolejność sprawdzeń zmiany statusu: 401, 403 `VIEW_ORDERS` (atrybut kontrolera), 400/415/422 treści, 403 roli
  dla przejścia z treści (`CHANGE_ORDER_STATUS`), 404 zamówienia, 409. Wykonanie zwrotu: 403 `COMPLETE_REFUND`
  przed treścią, potem 404, 409.
- Deptrac: `Ordering_UI → Backoffice_Application` (`AuthenticatedStaffMember`) i
  `Ordering_Infrastructure → Backoffice_Application` (voter), jak Inventory.

### Sklep (`apps/api/openapi.json`)

| Endpoint | Sukces | Błędy |
|---|---|---|
| `GET /api/v1/me/orders?after=&perPage=` | 200 `{items, nextCursor}` | 400, 401, 503 |
| `GET /api/v1/me/orders/{orderId}` | 200 `CustomerOrder` | 401, 404 (nieznane, cudze, gościa, zły UUID), 503 |

- `OrderView` (odpowiedź złożenia) ma `status` z pełnym enum.

## Panel (`apps/admin`)

- Menu „Zamówienia” (role z P6, z `/me`), trasa `/orders` (`OrdersView`): tabela (numer, data, klient, e-mail,
  status jako `Tag`, kwota), filtr statusu, przełącznik „Zwroty do wykonania” (kolumny kwoty i terminu zwrotu,
  `Tag` „Po terminie” według `overdue`), pole wyszukiwania (numer albo e-mail, po Enter), stronicowanie po kursorach
  ze stosem kursorów jak `usePriceList`. Błąd listy: komunikat z „Spróbuj ponownie”; 403 (zabezpieczenie, gdy API
  odmówi mimo strażnika trasy): „Nie masz dostępu do zamówień.”. Pusta lista bez filtrów i wyszukiwania: „Brak
  zamówień.”; z filtrem albo wyszukiwaniem: „Nie znaleziono zamówień.”.
- Strażnik trasy: `/orders` i `/orders/:orderId` otwierają tylko `admin`, `support` i `warehouse` (role z `/me`,
  które strażnik w razie potrzeby sam pobiera). Pozostałe role trafiają na pulpit (`history.state.denied`), który
  pokazuje „Nie masz dostępu do tej strony.” (`role="alert"`) i czyści znacznik, więc przeładowanie komunikatu nie
  powtarza. Gdy `/me` się nie wczyta, strażnik przepuszcza trasę, a dostęp sprawdza API (403 widoku). Menu ukrywa
  pozycje tak samo, ale to strażnik i API egzekwują dostęp.
- Filtry z adresu: lista czyta `status` (znany status) i `refund=pending` przy wejściu oraz przy każdej zmianie
  `route.query` przy otwartej liście (pozycja menu „Zwroty do wykonania” na `/orders`): ustawia oba filtry według
  adresu i wraca na pierwszą stronę, wyszukiwanie zostaje. Nieznana, pusta albo powtórzona wartość jest pomijana.
  Zmiany filtrów w liście nie trafiają do adresu.
- `/orders/:orderId` (`OrderDetailView`): kontakt, adres, pozycje, sumy, termin płatności dla `new`, historia
  statusów (data, zmiana, aktor, notatka), sekcja „Zwrot pieniędzy” (kwota, termin z „Po terminie”, wykonanie:
  data, pracownik, notatka) i przyciski z `allowedTransitions` („Oznacz jako wysłane”, „Oznacz jako dostarczone”,
  „Anuluj zamówienie”, „Przyjmij zwrot”) oraz „Zwrot pieniędzy wykonany” przy `canCompleteRefund`. Dialog
  potwierdzenia z jednowierszowym polem notatki (wymagane przy anulowaniu i zwrocie). Wysyła `version` widoku.
- 409 (wersja, przejście, zwrot): odświeżenie szczegółów i komunikat „Zamówienie zostało w międzyczasie zmienione –
  sprawdź jego stan.”; 422 przy polu notatki; 403 komunikat w dialogu. 404 szczegółów: „Nie znaleźliśmy
  zamówienia.” z linkiem do listy; inny błąd odczytu: komunikat z „Spróbuj ponownie”.
- Kod w `src/orders/` (`useOrderList`, `useOrderDetail`, `format.ts` z etykietami statusów i aktorów).

## Sklep (`apps/store`)

- „Moje zamówienia” w `AccountMenu` dla zalogowanego klienta.
- `/konto/zamowienia` i `/konto/zamowienia/[orderId]`: renderowane po stronie klienta, `noindex`, BFF
  `app/api/account/orders` i `app/api/account/orders/[orderId]` (`withAuthDeps`, `freshAccessToken`,
  `API_INTERNAL_URL`, `private, no-store`). Brak sesji przy wejściu i 401 z BFF w trakcie pracy (np. „Pokaż więcej”
  po wygaśnięciu sesji): przekierowanie na `/auth/login?returnTo=<bieżąca ścieżka>` (`sanitizeReturnTo`); 503 BFF
  to ogólny błąd niżej.
- Strona potwierdzenia płatności (`PaymentConfirmation`) dla `cancelled`: „Zamówienie zostało anulowane.” (P12).
- Lista: numer, data (`Europe/Warsaw`), status, kwota z API, liczba sztuk, „Pokaż więcej”; pusta: „Nie masz jeszcze
  zamówień.”. Szczegóły: pozycje, sumy, adres, daty statusów; `new` z „Czeka na płatność do 14:35”, po terminie
  (`paymentDeadlinePassed`) „Termin płatności minął” (P8). 404: „Nie znaleźliśmy zamówienia.”. Inny błąd listy albo
  szczegółów: „Nie udało się wczytać zamówień. Spróbuj ponownie.” z przyciskiem; nieudane „Pokaż więcej” zostawia
  wczytane pozycje i pozwala ponowić.
- Kod w `src/account/`; wywołania przeglądarki przez `src/api/client.ts`.

## Kryteria akceptacji

1. `warehouse` oznacza opłacone zamówienie jako wysłane: `shipped`, rezerwacja zatwierdzona (`physical`
   i `reserved` wariantów maleją), wpis historii z pracownikiem, e-mail „wysłane” w Mailpit.
2. `support` anuluje zamówienie `paid` z notatką: `cancelled` (`staff`), rezerwacja zwolniona, zwrot pieniędzy do
   wykonania z terminem +14 dni, e-mail „anulowane” (bez e-maila o braku płatności).
3. `support` przyjmuje zwrot zamówienia `shipped` i `delivered`: `returned`, zwrot pieniędzy do wykonania, e-mail
   „zwrot przyjęty”, stany magazynowe bez zmian.
4. `support` oznacza zwrot pieniędzy jako wykonany: data i pracownik w szczegółach; zamówienie znika z filtra
   „Zwroty do wykonania”; ponowienie: 409. Zwrot po terminie ma `overdue`.
5. Przejście spoza tabeli (np. `ship` dla `new`, `cancel` dla `new` albo `shipped`, `return` dla `paid`): 409
   `order-transition-not-allowed`, bez zmian.
6. Nieaktualna wersja: 409, bez zmian. Równoległe żądania z tą samą wersją (także `ship` z `cancel`, test na
   prawdziwej bazie): jedno 200, pozostałe 409, rezerwacja zgodna ze zwycięzcą, bez 500.
7. Wygaśnięcie i webhook opłacenia zmieniają tylko zamówienia `new`; webhook dla wysłanego albo anulowanego przez pracownika
   zamówienia nie zmienia statusu. `GetPaymentStatus` dla wysłanego, dostarczonego i zwróconego widzi `paid` (A3).
8. Role: `marketing` 403 na każdym endpointcie zamówień; `warehouse` 403 na `cancel`, `return` i wykonaniu zwrotu;
   `support` 403 na `ship` i `deliver`; token klienta nie przechodzi przez firewall panelu.
9. Klient widzi tylko swoje zamówienia (cudze i gościa: 404), od najnowszych, ze stronicowaniem; szczegóły mają
   pozycje, sumy, adres i daty statusów (P10).
12. Panel: wyszukiwanie po numerze i e-mailu bez względu na wielkość liter, filtr statusu, filtr „Zwroty do
    wykonania” od najbliższego terminu (także w połączeniu ze statusem), zły kursor i `q` ponad 254 znaki: 400.
13. Migracja: istniejące zamówienia mają historię z `placed_at`, `paid_at`, `cancelled_at`, a ich znaczniki e-maili
    są w tabeli powiadomień (żaden e-mail nie wychodzi drugi raz).
10. E-mail nie zmienia wersji zamówienia; dwie równoległe dostawy tego samego zdarzenia wysyłają jeden e-mail;
    ponowienia jak w „E-maile”.
11. E2E: logowanie klienta → złożenie i opłacenie → „Moje zamówienia” pokazuje „Opłacone” → pracownik wysyła je
    w panelu → historia klienta pokazuje „Wysłane”, Mailpit ma e-mail „wysłane”.
