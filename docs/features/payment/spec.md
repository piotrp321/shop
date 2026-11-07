# PAYMENT: płatności przez udawanych dostawców

Punkt 8 „Zakresu MVP” z `docs/vision.md` (płatności: dwóch udawanych dostawców za jednym interfejsem, webhooki
z podpisem) oraz przejście „nowe → opłacone” z punktu 7. Domyka ścieżkę E2E nr 1 z `docs/vision.md`
(… → checkout gościa → płatność → potwierdzenie).

Issues: #91
Zakres: `apps/api/src/Payment`, `apps/api/src/PaymentSimulator`, `apps/api/src/Ordering`, `apps/api/src/Shared`, `apps/api/tests/Unit/Payment`, `apps/api/tests/Unit/PaymentSimulator`, `apps/api/tests/Unit/Ordering`, `apps/api/tests/Unit/Shared`, `apps/api/tests/Integration/Payment`, `apps/api/tests/Integration/PaymentSimulator`, `apps/api/tests/Integration/Ordering`, `apps/api/tests/Functional/Payment`, `apps/api/tests/Functional/PaymentSimulator`, `apps/api/tests/Functional/Ordering`, `apps/api/tests/Functional/Shared`, `apps/api/tests/Support`, `apps/api/config/packages/messenger.yaml`, `apps/api/config/packages/test/messenger.yaml`, `apps/api/config/packages/test_messaging/messenger.yaml`, `apps/api/config/packages/nelmio_api_doc.yaml`, `apps/api/config/packages/security.yaml`, `apps/api/config/services.yaml`, `apps/api/deptrac.yaml`, `apps/api/phpunit.dist.xml`, `apps/api/fixtures/stock.yaml`, `apps/api/openapi.json`, `apps/api/CLAUDE.md`, `apps/store/src/api/schema.d.ts`, `apps/store/src/api/client.ts`, `apps/store/src/api/client.test.ts`, `apps/store/src/checkout`, `apps/store/app/checkout`, `apps/store/app/api/checkout`, `apps/store/CLAUDE.md`, `infra/docker-compose.yml`, `infra/scripts/init-env.sh`, `Makefile`, `e2e/tests/checkout-guest.spec.ts`, `e2e/tests/checkout-customer.spec.ts`, `e2e/support`, `docs/features/checkout/spec.md`

**Zależność od CHECKOUT (#90).** Zamówienie (`Order`, Ordering) powstaje w statusie `new` z rezerwacją stanów
o kluczu `orderId` (Inventory `ReserveStock`). Inventory zwalnia ją przez `ReleaseReservation(reservationKey)`
(`ReservationTransitionHandler`: aktywna → zwolniona; już zwolniona → bez zmian; zatwierdzona →
`ReservationStateConflict`; nieznana → `ReservationNotFound`).

## Cel i zakres

- Klient (gość i zalogowany) wybiera w kasie jednego z dwóch udawanych dostawców płatności, po złożeniu zamówienia
  przechodzi na stronę płatności dostawcy (symulator), płaci albo odrzuca płatność i wraca do sklepu.
- Dwóch dostawców za jednym portem `PaymentProvider` w contexcie Payment. Dostawcy różnią się API
  (format kwoty, uwierzytelnienie, schemat podpisu i treść webhooka), adaptery tłumaczą je na model Payment.
- Webhooki dostawców: weryfikacja podpisu, deduplikacja po identyfikatorze zdarzenia, sprawdzenie kwoty.
- Udana płatność zmienia status zamówienia na `paid` i wysyła e-mail „płatność przyjęta”.
- Zamówienie nieopłacone w terminie jest anulowane (`cancelled`), a jego rezerwacja zwolniona.
- Nieudana albo przerwana płatność może być ponowiona (ten sam albo drugi dostawca) do terminu płatności.

Poza zakresem:
- prawdziwi dostawcy płatności (`docs/vision.md`, „Poza zakresem”), zwroty pieniędzy (także za płatność, która
  przyszła po anulowaniu albo drugi raz za to samo zamówienie: ryzyko, obsługa ręczna);
- statusy po opłaceniu (wysłane, dostarczone), anulowanie i zwrot z panelu, zatwierdzenie rezerwacji
  (`CommitReservation`) przy wysyłce, historia zamówień (ORDER-MANAGEMENT #92);
- płatność z historii zamówień albo z linku w e-mailu: gość płaci w sesji kasy (ciasteczko potwierdzenia),
  potem zamówienie wygasa;
- rate limiting webhooków (D9).

## Decyzje

Decyzje użytkownika:

| # | Decyzja |
|---|---|
| P1 | Termin płatności: 30 minut od złożenia zamówienia, pokazywany klientowi („Opłać do 14:35”). Sesja płatności u dostawcy wygasa w terminie. Dodatkowe 5 minut po terminie służą wyłącznie dostarczeniu webhooków; potem nieopłacone zamówienie jest anulowane. O opłaceniu decyduje czas wykonania płatności u dostawcy (podpisany, w treści webhooka), nie czas odebrania webhooka: płatność wykonana po terminie albo potwierdzona po anulowaniu nie opłaca zamówienia i jest trwale oznaczona do zwrotu. |
| P2 | Dostawca wybierany w kasie (wymagany wybór, bez domyślnego), przycisk „Zamawiam z obowiązkiem zapłaty” składa zamówienie i od razu przekierowuje do dostawcy. Ponowienie (nieudana, przerwana płatność) na stronie potwierdzenia z ponownym wyborem dostawcy. |
| P3 | Nazwy dostawców widoczne dla klienta: „Szybki przelew (demo)” (`demo-transfer`) i „Karta płatnicza (demo)” (`demo-card`). Strona symulatora mówi wprost, że to symulacja i nie pobiera pieniędzy. |
| P4 | E-maile: „Płatność przyjęta” po opłaceniu i „Zamówienie anulowane” po anulowaniu za brak płatności; e-mail z potwierdzeniem złożenia dostaje linię z terminem płatności. |
| P5 | Oczekująca płatność nie jest zastępowana po czasie. Klient może wrócić do niej („Wróć do płatności”, ta sama sesja u dostawcy). Nowa próba jest możliwa po potwierdzonym niepowodzeniu poprzedniej albo po jej skutecznym anulowaniu u dostawcy (anulowanie odrzucone, bo płatność już wykonana: nowej próby nie ma). Zamówienie ma najwyżej jedną oczekującą płatność. Wyjątkowa druga udana płatność jest zapisana jako udana, idempotentnie, z trwałym oznaczeniem do zwrotu (zwrot ręczny, poza zakresem). |
| P6 | W oknie 5 minut po terminie strona potwierdzenia mówi: „Minął termin płatności. Jeśli zapłaciłeś, czekamy na potwierdzenie; w przeciwnym razie zamówienie zostanie anulowane.”, bez przycisków płatności. |
| P7 | Powrót ze strony dostawcy bez decyzji: link „Wróć do sklepu” na stronie symulatora, a pusta kasa z ciasteczkiem potwierdzenia pokazuje link „Opłać zamówienie R-…” na stronę potwierdzenia. |
| A1 | Nowy bounded context Payment z agregatem `Payment` (jedna próba płatności zamówienia), zapis przez rekordy Doctrine według ogólnej zasady. Ordering pozostaje właścicielem statusu zamówienia. |
| A2 | Payment zależy od Ordering przez jawne porty w jednym kierunku: zapytanie `GetOrderForPayment` przy rozpoczęciu płatności i odczycie statusu oraz komenda `MarkOrderPaid` w transakcji webhooka (zagnieżdżony dispatch, jak `PlaceOrder` → Cart i Inventory). Bez zdarzeń między Payment i Ordering: opłacenie i zmiana statusu zamówienia są atomowe. Opłacenie i anulowanie szereguje wersja agregatu `Order` (optimistic locking, przegrany jest ponawiany): nie ma przejścia `cancelled → paid` ani zwolnienia rezerwacji opłaconego zamówienia. Potwierdzona płatność anulowanego zamówienia jest zapisana z oznaczeniem do zwrotu i kończy dostawę webhooka (bez ponowień). |
| A3 | Symulator dostawców jako osobny moduł `PaymentSimulator` w `apps/api` (poza `/api/v1`, bez zależności od contextów i odwrotnie). Payment rozmawia z nim wyłącznie przez HTTP (`symfony/http-client`, już w zależnościach), jak z zewnętrznym dostawcą. Symulator zostaje w kontenerze API mimo ryzyka samoblokady wątków przy wielu równoległych rozpoczęciach płatności (opisanego w „Ryzykach”); przed testami obciążeniowymi płatności przechodzi do osobnej usługi. |
| A4 | Anulowanie nieopłaconego zamówienia przez opóźnioną wiadomość `ExpireUnpaidOrder` (`DelayStamp`) na nowym transporcie Doctrine `scheduled`, zapisaną atomowo z zamówieniem w transakcji złożenia, na tym samym połączeniu. Handler jest idempotentny i zawsze sprawdza aktualny stan i termin zamówienia. Bez nowej zależności (Symfony Scheduler) i bez crona. Dostawy webhooków symulatora idą osobnym transportem Doctrine `payment_simulator`. |
| A5 | Gość ma dostęp do swojego zamówienia (rozpoczęcie płatności, odczyt statusu) przez losowy token dostępu zwracany przy złożeniu (`accessToken`), jak id koszyka gościa; klient zalogowany przez `sub` z tokena. Regułę dostępu sprawdza Ordering (`GetOrderForPayment` z danymi dostępu zwraca `null` przy braku dostępu); token nie opuszcza Ordering. |
| A6 | Przejścia statusu zamówienia (`new → paid`, `new → cancelled`) jako metody agregatu `Order`, bez Symfony Workflow (wchodzi w ORDER-MANAGEMENT, gdy przejść jest więcej). |
| T1 | Sekrety dostawców (klucze API i sekrety podpisu webhooków, po dwa na dostawcę) w `.env.local` z `infra/scripts/init-env.sh`, który dopisuje brakujące klucze z listy „dopisywanych” do istniejącego pliku (brak każdego innego klucza kończy się błędem i wymaga odtworzenia stosu). |
| D9 | Rate limiting webhooków odłożony do kroku 10 planu `docs/features/agentic-software-house/plan.md`, jak rate limiting składania zamówień (CHECKOUT T2). |

## Bounded contexty i zależności

| Context / moduł | Odpowiada za |
|---|---|
| Payment | agregat `Payment`, rozpoczęcie płatności, webhooki (podpis, deduplikacja, kwota), port `PaymentProvider` i dwa adaptery, status płatności dla sklepu |
| Ordering | statusy `paid` i `cancelled`, termin płatności, token dostępu gościa i reguła dostępu, `GetOrderForPayment`, `MarkOrderPaid`, `ExpireUnpaidOrder`, e-maile o płatności i anulowaniu |
| Inventory | `ReleaseReservation(orderId)` przy anulowaniu |
| PaymentSimulator (poza contextami) | dwa udawane API dostawców, strona płatności, wysyłka podpisanych webhooków z ponowieniami |
| Shared | marker `Unavailable` (503, ogólna odpowiedź bez `detail`) w `Shared\Domain\Exception` i jego mapowanie w `ProblemDetailsSubscriber` |
| Sklep | wybór dostawcy, przekierowanie, strona potwierdzenia ze statusem płatności, ponowienie; żadnych obliczeń |

Reguły Deptrac (warstwy `Payment_*` i `PaymentSimulator`; żaden context nie zależy od Payment ani od
PaymentSimulator, a PaymentSimulator od żadnego contextu):

| Warstwa | Może zależeć od |
|---|---|
| `Payment_Domain` | `Native`, `Shared_Domain` |
| `Payment_Application` | `Native`, `Payment_Domain`, `Shared_Domain`, `Vendor` |
| `Payment_Infrastructure` | `Native`, `Payment_Application`, `Payment_Domain`, `Shared_Domain`, `Shared_Infrastructure`, `Vendor`, `Ordering_Application` |
| `Payment_UI` | `Native`, `Payment_Application`, `Payment_Domain`, `Shared_Domain`, `Shared_Infrastructure`, `Vendor`, `Customer_Application` |
| `PaymentSimulator` (jedna warstwa, płaski moduł: atrapa systemu zewnętrznego, nie domena sklepu) | `Native`, `Vendor`, `Shared_Infrastructure` |

- Ordering nie zależy od Payment. Od Ordering zależy tylko Payment (komentarz w `deptrac.yaml`, jak Ordering → Cart).
- Wszystko, czego Payment używa z Ordering (zapytanie, komenda, ich wyniki i wyjątki), jest w `Ordering_Application`
  (jak `InsufficientStock` w `Inventory_Application`). Agregat zwraca własny enum domenowy, handler tłumaczy go na
  typ aplikacji.
- `Payment_UI → Customer_Application` wyłącznie przez `AuthenticatedCustomer` (jak `Cart_UI`, `Ordering_UI`).
- Payment ma własne value objecty (`OrderId`, `Amount`, `Currency` itd.), nie importuje kodu domenowego Ordering.

## Ordering

### Model

- `OrderStatus`: `new`, `paid`, `cancelled`.
- Pola `Order` cyklu płatności: `paymentDeadline` (`placedAt` + 30 min, P1), `accessToken` (gość: 32 losowe bajty w base64url,
  43 znaki; klient: `null`), `paidAt`, `paymentId` (id udanej płatności Payment, kopia), `cancelledAt`,
  `cancellationReason` (`payment_timeout`), `paymentNotificationSentAt`, `cancellationNotificationSentAt`.
- `Order::place(…)` przyjmuje `accessToken` (generuje handler: `random_bytes`, tylko gość) i liczy `paymentDeadline`.
- `grantsAccess(claim)` (`AccessClaim`): `guest(token)` — zamówienie gościa i `hash_equals` tokenu;
  `customer(customerId)` — zamówienie klienta o tym `customerId`. Każdy inny przypadek `false`.
- `markPaid(paymentId, amount, currency, executedAt, now): PaymentResult` (enum domenowy; `executedAt` to czas
  wykonania płatności u dostawcy):
  - `new` i `executedAt > paymentDeadline` → `paidAfterDeadline` bez zmian (P1; zamówienie zostanie anulowane
    po oknie dostarczenia);
  - `new`, `executedAt ≤ paymentDeadline`: `amount` (kwota i waluta) równe `totals.gross` → `paid`, `paidAt = now`, `paymentId`,
    zdarzenie `OrderPaid {orderId, occurredAt}`, wynik `paid`; inna kwota → `InvalidOrder` (błąd logiczny: Payment
    bierze kwotę i walutę z zamówienia). Webhook przychodzi najpóźniej w oknie 5 minut, bo potem zamówienie jest już
    `cancelled`;
  - `paid` tym samym `paymentId` → `alreadyPaid` bez zmian; innym `paymentId` → `paidByAnotherPayment` bez zmian
    (P5);
  - `cancelled` → `orderCancelled` bez zmian.
  Wynik zamiast wyjątku: handler webhooka zapisuje płatność także wtedy, gdy zamówienia nie da się opłacić.
- `expireUnpaid(now): ExpiryResult`: `new` i `now ≥ paymentDeadline + 5 min` → `cancelled`, `cancelledAt`,
  `cancellationReason = payment_timeout`, zdarzenie `OrderCancelled {orderId, reason, occurredAt}`, wynik
  `cancelled`; `new` przed tą chwilą → `tooEarly` bez zmian; `paid`, `cancelled` → `notApplicable` bez zmian.
- `isPayable(now)`: `new` i `now < paymentDeadline`.
- `markPaymentNotificationSent(now)`, `markCancellationNotificationSent(now)`: jak `markConfirmationSent`.

### Komendy i zapytania (Ordering Application)

- `GetOrderForPayment(orderId, access)` (`query.bus`), `access` = `guest(token)` albo `customer(customerId)` →
  `OrderForPayment {orderId, number, status, totalGross, currency, paymentDeadline, payable}` albo `null`, gdy
  zamówienia nie ma, `orderId` nie jest UUID albo `grantsAccess` daje `false` (jeden wynik dla wszystkich przypadków).
  Bez danych osobowych i bez tokenu. Zamówienie jest czytane z bazy także wtedy, gdy transakcja czytała je
  wcześniej (bez kopii z pamięci ORM), więc kolejny odczyt w tej samej transakcji widzi zmiany zatwierdzone
  w międzyczasie.
- `MarkOrderPaid(orderId, paymentId, amount, currency, executedAt)` (`command.bus`) → `MarkOrderPaidResult`
  (`Ordering_Application`: `paid`, `alreadyPaid`, `paidByAnotherPayment`, `paidAfterDeadline`, `orderCancelled`). Nieznane zamówienie: `OrderToPayNotFound`;
  konflikt wersji przy zapisie: `OrderChangedConcurrently` (oba wyjątki w `Ordering_Application`).
- `ExpireUnpaidOrder(orderId)` (`command.bus`, wiadomość asynchroniczna na transporcie `scheduled`):
  - wynik `cancelled`: w tej samej transakcji port `StockReservations::release(orderId)` (Inventory
    `ReleaseReservation`, zagnieżdżony dispatch; rezerwacja już zwolniona: bez zmian) i zapis zamówienia
    z `OrderCancelled` w outboxie;
  - wynik `tooEarly` (zaokrąglenie `available_at` do sekundy): ta sama wiadomość wysłana ponownie z `DelayStamp`
    do `paymentDeadline + 5 min + 1 s`;
  - `notApplicable`: nic;
  - nieznane zamówienie (np. po `make seed`): nic, wpis w logu;
  - konflikt wersji (równoległe opłacenie): wyjątek i ponowienie wiadomości, które widzi nowy status;
  - rezerwacja nieznana Inventory (`ReservationNotFound`) albo zatwierdzona (`ReservationStateConflict`): błąd
    logiczny, wyjątek, po ponowieniach transport `failed`.
- `PlaceOrder` w swojej transakcji wysyła `ExpireUnpaidOrder(orderId)` z `DelayStamp` do
  `paymentDeadline + 5 min` (transport Doctrine na tym samym połączeniu: wiadomość powstaje albo znika razem
  z zamówieniem). Powtórzenie złożenia nie wysyła drugiej wiadomości.

### E-maile

- `OrderPaid` → handler `SendPaymentReceived` (`event.bus`, jak `SendOrderConfirmation`, ten sam wzorzec znacznika
  i wersji): temat „Płatność przyjęta – zamówienie R-100001”, treść: powitanie, numer, kwota, „Zamówienie
  przekazujemy do realizacji.”.
- `OrderCancelled` → `SendOrderCancelled`: temat „Zamówienie R-100001 anulowane”, treść: powitanie, numer,
  „Nie otrzymaliśmy płatności w terminie, więc anulowaliśmy zamówienie.”.
- E-mail z potwierdzeniem złożenia kończy się zdaniem „Zamówienie czeka na płatność do 14:35 (dd.mm.rrrr). Bez
  płatności anulujemy je automatycznie.” (`Europe/Warsaw`).
- Treści składają klasy w `Ordering_Application` (jak `OrderConfirmationText`), testowane jednostkowo.

### API Ordering

- `OrderView` ma `paymentDeadline` (RFC 3339, UTC) i `accessToken` (string dla gościa, `null` dla klienta).
  `status` przyjmuje `new`, `paid`, `cancelled`. Powtórzenie złożenia (200) zwraca ten sam `accessToken`.

### Persystencja

- `ordering_order`: `payment_deadline timestamp`, `access_token varchar(43) NULL`, `paid_at NULL`,
  `payment_id uuid NULL`, `cancelled_at NULL`, `cancellation_reason varchar(30) NULL`,
  `payment_notification_sent_at NULL`, `cancellation_notification_sent_at NULL`;
  `CHECK ((owner_type = 'guest') = (access_token IS NOT NULL))`,
  `CHECK ((status = 'paid') = (paid_at IS NOT NULL AND payment_id IS NOT NULL))`,
  `CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL))`.
- Migracja wypełnia istniejące wiersze: `payment_deadline = placed_at + 30 min`; zamówienia gości dostają token
  `translate(rtrim(encode(sha256(gen_random_uuid()::text::bytea), 'base64'), '='), '+/', '-_')` (43 znaki base64url;
  `gen_random_uuid` i `sha256` są wbudowane w PostgreSQL 18, bez `pgcrypto`). Zamówienia sprzed migracji nie mają
  wiadomości `ExpireUnpaidOrder`; lokalnie czyści je `make seed` (środowiska demo jeszcze nie ma).

## Payment

### Value objecty

- `PaymentId` (UUID v7), `OrderId` (UUID), `ProviderCode` (`demo-transfer` | `demo-card`), `Amount` (grosze `int` > 0
  i `Currency` `PLN`), `ProviderPaymentId` (1–100 znaków `[A-Za-z0-9_-]`), `ProviderEventId` (1–100 znaków
  `[A-Za-z0-9_-]`), `IdempotencyKey` (format jak w Ordering), `PaymentStatus` (`pending`, `succeeded`, `failed`,
  `cancelled`), `RefundReason` (`order_cancelled`, `paid_after_deadline`, `duplicate`).

### Agregat `Payment`

Pola: `id`, `orderId`, `provider`, `amount`, `status`, `providerPaymentId` (null do odpowiedzi dostawcy),
`redirectUrl`, `idempotencyKey`, `requestFingerprint` (SHA-256 `orderId` i `provider`), `createdAt`,
`completedAt`, `executedAt` (czas wykonania u dostawcy, przy `succeeded`), `refundRequiredReason` (null albo
`RefundReason`). Bez pola wersji: istniejącą płatność zmieniają webhook i anulowanie przy nowej próbie, oba pod
blokadą wiersza płatności; `attachProviderSession` działa w transakcji, która płatność tworzy.

| Metoda | Działanie |
|---|---|
| `start(id, orderId, provider, amount, key, fingerprint, now)` | nowa płatność `pending` |
| `attachProviderSession(providerPaymentId, redirectUrl)` | raz; drugi raz z innymi danymi: `InvalidPayment` |
| `succeed(amount, executedAt, now): bool` | `pending`, `failed` albo `cancelled` → `succeeded` (pieniądze ważniejsze niż wcześniejsza odmowa albo anulowanie), `true`; `succeeded` → `false` bez zmian; kwota inna niż `amount`: `PaymentAmountMismatch` |
| `fail(now)` | `pending` → `failed`; inne → bez zmian |
| `cancel(now)` | `pending` → `cancelled` (sesja skutecznie anulowana u dostawcy); inne → bez zmian |
| `requireRefund(reason)` | tylko `succeeded`; ustawia powód raz (powtórzenie bez zmian) |
| `isReplayOf(fingerprint)` | to samo rozpoczęcie z tym samym kluczem |
| `resumeUrl(orderPayable)` | `redirectUrl`, gdy `pending` i zamówienie płatne (przed terminem); inaczej `null` |

### Port `PaymentProvider` i adaptery

```
interface PaymentProvider {
  code(): ProviderCode
  createSession(PaymentId, Amount, orderNumber, returnUrl, expiresAt): ProviderSession {providerPaymentId, redirectUrl}
  cancelSession(providerPaymentId): SessionCancellation (cancelled | alreadyPaid | alreadyRejected)
  parseWebhook(headers, rawBody, now): ProviderEvent {eventId, providerPaymentId, outcome: succeeded|failed, amount, executedAt?} | null  // null: zdarzenie innego typu
}
```

`executedAt` jest wymagany przy `succeeded` (brak: treść niepoprawna, 400).

`PaymentProviders` (rejestr adapterów po kodzie). `parseWebhook` weryfikuje podpis przed parsowaniem treści.

| | `demo-transfer` (Szybki przelew) | `demo-card` (Karta płatnicza) |
|---|---|---|
| Utworzenie | `POST /payment-simulator/demo-transfer/api/transactions`, `Authorization: Bearer <klucz API>`, JSON `{merchantRef, amount: <grosze int>, currency, description, returnUrl, expiresAt}` → `201 {transactionId, paymentUrl}` | `POST /payment-simulator/demo-card/v1/checkout-sessions`, HTTP Basic (`merchant`, klucz API), JSON `{reference, amount: "123.45", currency, successUrl, cancelUrl, expiresAt: <unix>}` → `200 {id, url}` |
| Anulowanie sesji | `POST /payment-simulator/demo-transfer/api/transactions/{transactionId}/cancel` (Bearer) → `200 {status: "CANCELLED"}`; transakcja zamknięta: `409 {status: "PAID" \| "REJECTED"}` | `POST /payment-simulator/demo-card/v1/checkout-sessions/{id}/expire` (Basic) → `200 {status: "expired"}`; sesja zamknięta: `409 {status: "complete" \| "failed"}` |
| Podpis webhooka | `X-Signature: <hex HMAC-SHA256(sekret, surowa treść)>` | `Card-Signature: t=<unix>,v1=<hex HMAC-SHA256(sekret, "<t>.<surowa treść>")>`, tolerancja 300 s |
| Treść webhooka | `{eventId, transactionId, merchantRef, status: "PAID" \| "REJECTED", amount, currency, paidAt: <RFC 3339, przy PAID>}` | `{id, type: "checkout.completed" \| "checkout.failed", data: {sessionId, reference, amount: "123.45", currency, completedAt: <unix, przy completed>}}` |
| Inne typy zdarzeń | — | ignorowane (204) |

- Adapter dostawcy B przelicza kwotę `"123.45"` ↔ grosze bez floatów (parsowanie łańcucha `^(0|[1-9][0-9]*)\.[0-9]{2}$`;
  `"1.5"`, `"-1.00"`, `"01.00"` to treść niepoprawna). `expiresAt` w API `demo-transfer` i `paidAt` to RFC 3339
  (UTC), w `demo-card` sekundy uniksowe.
- Porównanie podpisu `hash_equals`. Sekrety i klucze API z env (`PAYMENT_DEMO_TRANSFER_API_KEY`,
  `PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET`, `PAYMENT_DEMO_CARD_API_KEY`, `PAYMENT_DEMO_CARD_WEBHOOK_SECRET`; T1).
- Adresy (nie sekrety, w `infra/docker-compose.yml` dla `api` i `worker`, w `phpunit.dist.xml` dla testów):
  - `PAYMENT_PROVIDER_BASE_URL=http://api`: API symulatora wołane przez adaptery (sieć compose);
  - `PAYMENT_SIMULATOR_PUBLIC_URL=http://localhost:${API_PORT:-8000}`: adres strony płatności dla przeglądarki,
    z którego symulator buduje `paymentUrl` / `url`;
  - `PAYMENT_WEBHOOK_BASE_URL=http://api`: dokąd symulator (worker) wysyła webhooki
    (`<base>/api/v1/payments/webhooks/<provider>`).
- `returnUrl` (i `successUrl`, `cancelUrl`) buduje API: `STORE_URL` + `/checkout/confirmation`. Sklep nie podaje
  adresu powrotu (brak open redirect).
- Błąd HTTP dostawcy, przekroczenie czasu (5 s) albo zła odpowiedź: `PaymentProviderUnavailable` (marker
  `Unavailable`, 503).

### Rozpoczęcie płatności (`StartPayment`)

Komenda `StartPayment(orderId, access, provider, idempotencyKey)` (`access` jak w `GetOrderForPayment`); wynik
`StartedPayment {payment: PaymentView, replayed}`. Transakcja `command.bus`:

1. Port `PayableOrders::find(orderId, access)` (`GetOrderForPayment`): `null` → `OrderNotFound` (404, jeden komunikat
   dla nieznanego zamówienia, złego tokenu, cudzego zamówienia i zamówienia innego rodzaju właściciela). Dostęp
   jest sprawdzany przed powtórzeniem: sam klucz nigdy nie zwraca płatności (jak w CHECKOUT).
2. `PaymentRepository::findByIdempotencyKey`: płatność istnieje i `isReplayOf` → wynik z `replayed = true`;
   istnieje z innym odciskiem → `PaymentIdempotencyKeyReused` (409).
3. Zamówienie `paid`: `OrderAlreadyPaid` (409). `cancelled` albo po terminie: `PaymentDeadlinePassed` (409).
4. Oczekująca płatność zamówienia (najwyżej jedna): blokada jej wiersza (`PESSIMISTIC_WRITE`, jak w webhooku), potem
   `PaymentProvider::cancelSession` u jej dostawcy (P5):
   - `cancelled` → `Payment::cancel`, zapis, dalej krok 5;
   - `alreadyRejected` → `Payment::fail` (webhook odmowy przyjdzie albo przyszedł i trafi na deduplikację albo
     bez zmian), zapis, dalej krok 5;
   - `alreadyPaid` → `PaymentInProgress` (409, „Płatność została już wykonana. Czekamy na jej potwierdzenie.”),
     bez zmian; potwierdzenie przyniesie webhook;
   - błąd dostawcy → `PaymentProviderUnavailable` (503), bez zmian.
5. Stan zamówienia z kroku 3 jest sprawdzany ponownie na świeżym odczycie `PayableOrders::find` (po blokadzie
   z kroku 4): webhook poprzedniej płatności, który trzymał blokadę jej wiersza, mógł w tym czasie zatwierdzić
   płatność `succeeded` i zamówienie `paid`, a wtedy krok 4 nie znajduje oczekującej płatności. Zamówienie `paid`:
   `OrderAlreadyPaid` (409), `cancelled` albo po terminie: `PaymentDeadlinePassed` (409), bez zmian.
6. `Payment::start(…)`, zapis (`add`), potem `PaymentProvider::createSession(…, expiresAt = paymentDeadline)`
   i `attachProviderSession`, zapis. Wywołania HTTP są w transakcji (5 s limit każde): błąd dostawcy wycofuje całość
   (także lokalne anulowanie poprzedniej płatności). Sesja anulowana u dostawcy zostaje anulowana, a anulowanie
   u dostawcy jest idempotentne (anulowana sesja: znów `200`), więc ponowienie przechodzi krok 4 tak samo.
   Równoległe rozpoczęcia: unikalny indeks `idempotency_key` (ten sam klucz) i częściowy unikalny indeks „jedna
   płatność `pending` na zamówienie” (różne klucze, dwie karty) → `PaymentInProgress` (409); ponowienie z tym samym
   kluczem dostaje powtórzenie.

### Webhook (`HandleProviderWebhook`)

Weryfikacja podpisu i parsowanie to walidacja wejścia na granicy (jak `MapRequestPayload`), więc robi je kontroler
(`Payment_UI`), a komenda dostaje typowane zdarzenie:

- Kontroler bierze adapter z rejestru `PaymentProviders`: nieznany kod dostawcy → 404. Woła
  `parseWebhook(headers, rawBody, now)`: zły podpis, brak nagłówka, znacznik czasu spoza tolerancji albo zła treść
  → 400 (stały ogólny `detail`, bez rozróżnienia przyczyny w odpowiedzi; przyczyna w logu). Zdarzenie innego typu
  → 204 bez komendy. Port zgłasza błąd wyjątkiem `Payment_Application` (`InvalidWebhook`), który kontroler tłumaczy
  na 400; adaptery nie znają HTTP.
- Komenda `HandleProviderWebhook(provider, ProviderEvent {eventId, providerPaymentId, outcome, amount, executedAt})`, transakcja
  `command.bus`:

1. Blokada płatności po `(provider, providerPaymentId)` (`PESSIMISTIC_WRITE`, jak koszyk; równoległe dostawy tego
   samego zdarzenia czekają). Nieznana płatność → `PaymentNotFound` (404; dostawca nie ponawia).
2. Deduplikacja: `(provider, eventId)` jest w `payment_webhook_event` → 204 bez zmian. Inaczej zapis wiersza zdarzenia.
3. `succeed(amount, executedAt)` / `fail`. Kwota inna niż płatności: `PaymentAmountMismatch` (422, wycofanie, log
   błędu).
4. Przy `succeed`, które zmieniło status: port `OrderPayments::markPaid(orderId, paymentId, amount, executedAt)`
   (Ordering `MarkOrderPaid`, zagnieżdżony dispatch). Wynik:
   - `paid`, `alreadyPaid` → nic więcej;
   - `orderCancelled` → `requireRefund(order_cancelled)`; `paidAfterDeadline` → `requireRefund(paid_after_deadline)`;
     `paidByAnotherPayment` → `requireRefund(duplicate)`; do tego log błędu „wymaga ręcznego zwrotu” (P1, P5).
     Płatność zostaje `succeeded`, odpowiedź 204: dostawa kończy się bez ponowień.
   - `OrderChangedConcurrently` (np. równoległe `SendOrderConfirmation` albo `ExpireUnpaidOrder`) i każdy inny błąd
     `MarkOrderPaid`: adapter tłumaczy konflikt wersji na `OrderBusy` (marker `Unavailable`, 503), inne wyjątki
     przekazuje bez zmian (500); całość wycofana (także wiersz deduplikacji), dostawca ponawia.
5. Zapis płatności. Odpowiedź 204.

### Status płatności (`GetPaymentStatus`)

Zapytanie `GetPaymentStatus(orderId, access)` → `PaymentStatusView {orderNumber, orderStatus: new|paid|cancelled,
paymentDeadline, totalGross, currency, canStartPayment, latestPayment: {provider, status, resumeUrl} | null}`.
Dostęp przez `PayableOrders` jak w `StartPayment` krok 1. `canStartPayment` = zamówienie płatne (`payable`);
przy oczekującej płatności nowa próba najpierw anuluje ją u dostawcy (`StartPayment` krok 4). `resumeUrl` =
`Payment::resumeUrl(payable)`.

### Persystencja

- `payment_payment`: `id uuid` PK, `order_id uuid`, `provider varchar(20)`, `amount bigint` (`CHECK > 0`),
  `currency char(3)`, `status varchar(20)`, `provider_payment_id varchar(100) NULL`, `redirect_url varchar(500) NULL`,
  `idempotency_key varchar(100)` unikalny, `request_fingerprint char(64)`, `created_at`, `completed_at NULL`,
  `executed_at NULL`, `refund_required_reason varchar(30) NULL`; unikalne `(provider, provider_payment_id)`; indeks
  `(order_id, created_at)`; częściowy unikalny indeks `(order_id) WHERE status = 'pending'`;
  `CHECK (refund_required_reason IS NULL OR status = 'succeeded')`,
  `CHECK ((status = 'succeeded') = (executed_at IS NOT NULL))`. Płatności do zwrotu da się wylistować zapytaniem
  po `refund_required_reason IS NOT NULL` (bez ekranu w panelu).
- `payment_webhook_event`: `provider varchar(20)`, `event_id varchar(100)`, `payment_id uuid`, `received_at`;
  PK `(provider, event_id)`. Przechowywane na zawsze.
- Bez kluczy obcych do tabel Ordering. Rekordy Doctrine w `Payment/Infrastructure/Persistence/Doctrine/Record`,
  mapowanie w `DoctrinePaymentRepository`.

## Shared: marker `Unavailable`

- `App\Shared\Domain\Exception\Unavailable` (jak `Conflict`): `ProblemDetailsSubscriber` mapuje go na 503 z ogólną
  odpowiedzią bez `detail`, jak każdy 5xx (sklep pokazuje dla 503 stały tekst, symulator tylko ponawia). Używają go
  `PaymentProviderUnavailable` i `OrderBusy`. Test funkcjonalny w `tests/Functional/Shared` (kontroler testowy
  w `tests/Support`).

## PaymentSimulator

- Moduł `apps/api/src/PaymentSimulator` udaje dwóch zewnętrznych dostawców: endpointy API z tabeli adapterów,
  strona płatności i wysyłka webhooków. Płaska struktura bez warstw DDD (kontrolery, rekord Doctrine z blokadą przez
  ORM, handler `DeliverSimulatedWebhook`, podpisy), jedna warstwa Deptrac. Tabela `payment_simulator_transaction` (`id` losowy UUID v4, `provider`,
  `reference`, `amount`, `currency`, `return_url` (`demo-card`: `success_url`, `cancel_url`), `expires_at`,
  `status`: `open` | `paid` | `rejected` | `cancelled`, `decided_at NULL`, `created_at`). `decided_at` to czas
  wykonania płatności (`paidAt` / `completedAt` w webhooku).
- Uwierzytelnienie API symulatora tymi samymi kluczami API z env; zły klucz → 401.
- Anulowanie (endpointy z tabeli adapterów) blokuje wiersz transakcji (`SELECT … FOR UPDATE` przez ORM, jak decyzja
  na stronie płatności): `open` → `cancelled`; `cancelled` → znów sukces; `paid`, `rejected` → 409 ze statusem. Decyzja
  i anulowanie tej samej transakcji wykonują się po kolei, więc anulowana sesja nigdy nie zostanie opłacona.
- Strona płatności `GET /payment-simulator/{provider}/pay/{transactionId}` (HTML bez JS, bez nowych zależności,
  wartości escapowane): nazwa dostawcy, „To symulacja: żadne pieniądze nie zostaną pobrane.”, numer zamówienia,
  kwota, przyciski „Zapłać” i „Odrzuć płatność” oraz link „Wróć do sklepu” na adres powrotu (bez decyzji;
  `demo-card`: `cancelUrl`). Nieznane `transactionId`: strona HTML 404 „Nie znaleźliśmy tej płatności.”.
  Formularz `POST` z tokenem CSRF w ukrytym polu
  (HMAC-SHA256 id transakcji sekretem webhooków tego dostawcy). Transakcja zamknięta (opłacona, odrzucona,
  anulowana) albo po `expiresAt`: komunikat „Ta płatność jest już zamknięta.” bez przycisków i link powrotu.
- `POST` decyzji: pod blokadą wiersza transakcji status (raz, tylko z `open` i przed `expiresAt`; inaczej tylko
  przekierowanie), `decided_at`, wiadomość `DeliverSimulatedWebhook` na transport `payment_simulator` w tej samej
  transakcji, przekierowanie 303 na `returnUrl` (`demo-card`: `successUrl` albo `cancelUrl`).
- `DeliverSimulatedWebhook`: podpisany `POST` na `PAYMENT_WEBHOOK_BASE_URL` + ścieżka webhooka dostawcy; 2xx i 4xx
  kończą dostawę (4xx z logiem), 5xx i błąd sieci → wyjątek i ponowienie według strategii transportu. Identyfikator
  zdarzenia stały dla transakcji, więc ponowienie trafia na deduplikację. Podpis `demo-card` liczony przy każdej
  próbie (świeży `t`).
- Symulator działa także w środowisku demo (płatności udawane, `docs/vision.md`).

## Transporty `scheduled` i `payment_simulator`

- Oba Doctrine na tym samym połączeniu: `doctrine://default?queue_name=scheduled&auto_setup=false` i
  `…queue_name=payment_simulator…`, `use_notify: false`, `redeliver_timeout=300` (jak `outbox`: wiadomość
  pobrana przez worker, który padł przed potwierdzeniem, wraca po 5 minutach, nie po domyślnej godzinie),
  w istniejącej tabeli `messenger_messages` (jak `outbox` i `failed`); `DelayStamp` zapisuje `available_at`.
  Wiadomość czeka w bazie, więc restart albo przestój workera przed terminem tylko opóźnia anulowanie.
- Routing: `ExpireUnpaidOrder` → `scheduled` (ponowienia: 5 prób, 1 s, ×3); `DeliverSimulatedWebhook` →
  `payment_simulator` (ponowienia: 5 prób, 1 s, ×3, około 2 minut, potem `failed`).
- Worker: `messenger:consume outbox events scheduled payment_simulator` (`infra/docker-compose.yml`, także
  healthcheck z linią komendy; CI uruchamia stos przez compose).

## Testy wywołań HTTP dostawców

- W środowisku `test` klient HTTP adapterów Payment i dostawy webhooków symulatora to `MockHttpClient`, który
  przekazuje żądanie do osobnej instancji kernela testowego (własny kontener i `EntityManager`, więc `flush`
  i błąd symulatora nie dotykają `UnitOfWork` wywołującego; to samo połączenie DAMA i baza `shop_test`), ustawiony
  w klasie w `tests/Support` (wzorzec `MockHttpClient` jak w `tests/Integration/Shared/Health/ProbesTest.php`). Testy
  błędów dostawcy (timeout, 500, zła odpowiedź) podmieniają odpowiedź na stałą.
- Pełny przepływ przez prawdziwe HTTP (api ↔ worker ↔ przeglądarka) sprawdza E2E.

## Dane demo (`make seed`)

Każdy moduł czyści swoje dane (w `prod` każda komenda wymaga `--force-reset`, jak pozostałe seedy); `outbox`
i `failed` zostają:
- `payment-simulator:seed-demo`: `TRUNCATE payment_simulator_transaction` i
  `DELETE FROM messenger_messages WHERE queue_name = 'payment_simulator'`;
- `payment:seed-demo`: `TRUNCATE payment_payment, payment_webhook_event`;
- `ordering:seed-demo` dodatkowo `DELETE FROM messenger_messages WHERE queue_name = 'scheduled'` (właściciel
  `ExpireUnpaidOrder`).
- `make seed` (główny `Makefile`): `catalog`, `pricing`, `payment-simulator`, `payment`, `ordering`, `inventory`
  (`:seed-demo` każdej).

## API sklepu (`apps/api/openapi.json`)

Błędy RFC 9457. Odpowiedzi `Cache-Control: private, no-store`. `security.yaml`:
`^/api/v1/orders/[^/]+/payments?$` i `^/api/v1/payments/webhooks/` → `PUBLIC_ACCESS`,
`^/api/v1/payment-providers$` (`GET`, `HEAD`) → `PUBLIC_ACCESS`; `/api/v1/me/...` objęte regułą `ROLE_CUSTOMER`.
`/payment-simulator/...` jest poza firewallami `/api/v1`.

| Endpoint | Sukces | Błędy |
|---|---|---|
| `GET /api/v1/payment-providers` | 200 `[{code, name}]` | — |
| `POST /api/v1/orders/{orderId}/payments` (gość), nagłówki `Idempotency-Key`, `Order-Access-Token`, body `{provider}` | 201 `PaymentView {paymentId, provider, status, redirectUrl}`; 200 powtórzenie | 400 klucz, zły JSON; 401 wysłany niepoprawny token sesji; 404 zamówienie (także brak lub zły token, zamówienie klienta, id spoza UUID); 409 `OrderAlreadyPaid`, `PaymentDeadlinePassed`, `PaymentInProgress`, `PaymentIdempotencyKeyReused` (schemat `PaymentConflictProblem` z `enum` typów); 415; 422 (`provider` brak, nieznany); 503 dostawca albo (token sesji wysłany) klucze wystawcy niedostępne |
| `POST /api/v1/me/orders/{orderId}/payments` (klient) | jak wyżej | 401, 503 klucze wystawcy; pozostałe jak wyżej |
| `GET /api/v1/orders/{orderId}/payment` (gość, `Order-Access-Token`) | 200 `PaymentStatusView` | 401 wysłany niepoprawny token sesji; 404; 503 (token sesji wysłany) klucze wystawcy niedostępne |
| `GET /api/v1/me/orders/{orderId}/payment` (klient) | 200 `PaymentStatusView` | 401, 404, 503 klucze wystawcy |
| `POST /api/v1/payments/webhooks/{provider}` | 204 | 400 (podpis, treść), 401 wysłany niepoprawny token sesji, 404 (dostawca, płatność), 422 kwota, 503 `OrderBusy` |

`type` problemów płatności (prefiks `tag:piotrp321.github.io,2026:shop/problems/`): `order-already-paid`,
`payment-deadline-passed`, `payment-in-progress`, `payment-idempotency-key-reused`.

Komunikaty `detail`: „Zamówienie jest już opłacone.”, „Minął termin płatności za to zamówienie.”, „Płatność za to
zamówienie jest w toku. Spróbuj za chwilę.” (równoległe rozpoczęcie) albo „Płatność została już wykonana. Czekamy na
jej potwierdzenie.” (anulowanie odrzucone przez dostawcę; ten sam `type`), „Ten klucz żądania był już użyty dla innej płatności.”, „Nie znaleźliśmy
zamówienia.”. 422 pola `provider`: „Wybierz sposób płatności.” (brak, `null`, pusty) i „Nieznany sposób płatności.”
(kod spoza listy). 503 bez `detail`.

## Sklep (`apps/store`)

### BFF

- `POST /api/checkout` (reguły `Origin`, `Content-Type`, `Idempotency-Key` jak w CHECKOUT): body niesie `provider`.
  Brak albo kod spoza listy z `GET /api/v1/payment-providers` → 422 z naruszeniem pola `provider` („Wybierz sposób
  płatności.”) bez składania zamówienia; lista niedostępna → 503 bez składania. Po sukcesie złożenia (201, 200)
  ustawia `store_order_confirmation` z `{orderId, number, email, totalGross, currency, accessToken|null}`, potem
  wywołuje rozpoczęcie płatności tym samym `Idempotency-Key` (gość: `Order-Access-Token`, klient: `/me/...`).
  Odpowiedź `{number, redirectUrl}`; dowolny błąd rozpoczęcia płatności (także 409
  `PaymentIdempotencyKeyReused` po zmianie dostawcy przy ponowieniu z tym samym kluczem) → `{number,
  redirectUrl: null}` (zamówienie złożone, ponowienie na stronie potwierdzenia; błąd w logu).
- Wybór endpointu płatności (start i status) zależy od ciasteczka, nie od sesji: `accessToken` w
  `store_order_confirmation` → endpointy gościa z `Order-Access-Token` (także gdy gość zalogował się po złożeniu);
  `accessToken: null` → `/me/...` z tokenem sesji, a bez sesji 401 bez API.
- `POST /api/checkout/payment` (te same reguły `Origin`, `Content-Type`, `Idempotency-Key`), body `{provider}`:
  zamówienie z `store_order_confirmation` (brak lub zły podpis: 404 „Nie znaleźliśmy zamówienia.” bez API);
  odpowiedź `{redirectUrl}`; 404, 409 (z `type`), 422 przekazywane z `detail` i `violations`; 401 sesji kończy
  sesję; 503 przy niedostępnym API (także 400, 415 API i 401 endpointu gościa).
- `GET /api/checkout/payment`: `PaymentStatusView` z API dla zamówienia z ciasteczka; 404 bez ciasteczka i przy 404
  API; 401 (brak sesji przy zamówieniu klienta, 401 API kończy sesję); 503 przy innym błędzie API.
- `src/api/client.ts`: `placeOrder` (wynik z `redirectUrl`), `startPayment`, `getPaymentStatus`;
  `src/checkout/payment-api.ts`: `listPaymentProviders` (serwer).

### UI

- `/checkout`: grupa radiowa „Sposób płatności” z dostawcami z `GET /api/v1/payment-providers` (wymagana, bez
  domyślnego; brak wyboru: „Wybierz sposób płatności.” przy grupie, bez żądania). Lista niedostępna: „Płatności są
  chwilowo niedostępne. Spróbuj za chwilę.” zamiast przycisku. Przycisk jak w CHECKOUT (P9). Sukces
  z `redirectUrl` → `window.location.assign(redirectUrl)`; bez `redirectUrl` → `/checkout/confirmation`.
  Zmiana dostawcy nie zmienia `Idempotency-Key` złożenia (dostawca nie należy do odcisku zamówienia).
- `/checkout` z pustym koszykiem i poprawnym `store_order_confirmation` (np. klient cofnął się ze strony dostawcy
  przyciskiem „Wstecz”): obok komunikatu o pustym koszyku link „Opłać zamówienie R-100001” na
  `/checkout/confirmation`. Sklep nie sprawdza przy tym statusu (robi to strona potwierdzenia).
- Godziny (termin płatności) formatuje sklep z `paymentDeadline` (UTC z API) jawnie w strefie `Europe/Warsaw`
  (`Intl.DateTimeFormat`, `pl-PL`), tak samo na serwerze i w przeglądarce.
- `/checkout/confirmation` (Server Component z danymi ciasteczka i statusem z API, Client Component do odświeżania
  i ponowienia):

| Stan | Treść |
|---|---|
| `paid` | „Dziękujemy za zamówienie”, numer, kwota, „Płatność przyjęta. Potwierdzenie wysłaliśmy na adres …” |
| `new`, płatność `pending`, przed terminem | numer, kwota, termin, „Czekamy na potwierdzenie płatności…”; odświeżanie co 2 s przez 30 s; przez cały czas link „Wróć do płatności” (`resumeUrl`), a po 30 s dodatkowo „Nie mamy jeszcze potwierdzenia płatności.”, przycisk „Sprawdź ponownie”, wybór dostawcy i „Zapłać innym sposobem” (nowa próba anuluje poprzednią u dostawcy) |
| `new`, po terminie (okno 5 minut przed anulowaniem; każda płatność) | numer, kwota, „Minął termin płatności. Jeśli zapłaciłeś, czekamy na potwierdzenie; w przeciwnym razie zamówienie zostanie anulowane.”, bez przycisków płatności; odświeżanie co 2 s przez 30 s, potem „Sprawdź ponownie” |
| `new`, przed terminem, płatność `failed`, `cancelled` albo bez płatności | numer, kwota, termin („Opłać do 14:35”), komunikat („Płatność nie powiodła się.” przy `failed`, brak w innych), wybór dostawcy i „Zapłać” (gdy `canStartPayment`) |
| `cancelled` | „Zamówienie zostało anulowane, bo nie otrzymaliśmy płatności w terminie.” i link do sklepu |
| odczyt statusu nieudany (503, sieć) | numer i kwota z ciasteczka, „Nie udało się sprawdzić statusu płatności.” i przycisk „Sprawdź ponownie” |
| odczyt statusu: 401 | „Twoja sesja wygasła. Zaloguj się ponownie.” z linkiem (`returnTo=/checkout/confirmation`) |
| odczyt statusu: 404 | „Nie znaleźliśmy zamówienia.” i link do sklepu |
| brak ciasteczka | jak w CHECKOUT |

- „Zapłać”: `Idempotency-Key` nowy przy każdej próbie i po zmianie dostawcy, ten sam przy ponowieniu niepewnego
  wyniku (błąd sieci, 5xx, 503) z tym samym dostawcą. Wyniki:

| Wynik | UI |
|---|---|
| `redirectUrl` | przekierowanie do dostawcy |
| 409 `PaymentInProgress` | `detail`, odświeżenie statusu i dalsze odświeżanie (stan `pending`) |
| 409 `OrderAlreadyPaid`, `PaymentDeadlinePassed` | odświeżenie statusu (stan `paid` albo `cancelled`, albo `detail`) |
| 409 `PaymentIdempotencyKeyReused` | `detail`, nowy klucz |
| 404 | „Nie znaleźliśmy zamówienia.” i link do sklepu |
| 422 | komunikat przy grupie dostawców |
| 401 | „Twoja sesja wygasła. Zaloguj się ponownie.” z linkiem (`returnTo=/checkout/confirmation`) |
| 503, 5xx, błąd sieci | „Nie udało się rozpocząć płatności. Spróbuj ponownie.” (ponowienie z tym samym kluczem) |

- Fokus po odrzuceniu na komunikacie (`role="alert"`), jak w kasie.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Webhook dostarczony dwa razy (po kolei albo równolegle) | jedna zmiana płatności i zamówienia, jeden e-mail o płatności, drugi webhook 204 bez zmian |
| Płatność wykonana przed terminem, webhook w oknie 5 minut po terminie | zamówienie `paid` |
| Płatność wykonana po terminie (czas u dostawcy), webhook w oknie | płatność `succeeded` z oznaczeniem `paid_after_deadline`, zamówienie anulowane po oknie |
| Błąd w `MarkOrderPaid` (konflikt wersji, błąd bazy) | 503/500, wycofanie całości (także wiersza deduplikacji), symulator ponawia, ponowienie opłaca zamówienie |
| Zły podpis, brak nagłówka, podpis `demo-card` starszy niż 300 s | 400, bez zmian |
| Kwota w webhooku inna niż płatności | 422, bez zmian, log błędu |
| Webhook w trakcie równoległej zmiany zamówienia (e-mail potwierdzenia, anulowanie) | 503, wycofanie, symulator ponawia |
| Klient wraca przed webhookiem | „Czekamy na potwierdzenie…”, potem `paid` |
| Klient zamyka stronę dostawcy, odświeża stronę potwierdzenia | „Wróć do płatności” prowadzi do tej samej sesji; „Zapłać innym sposobem” anuluje ją u dostawcy i zaczyna nową (P5) |
| Nowa próba, a poprzednia sesja już opłacona u dostawcy | 409 `PaymentInProgress`, bez nowej płatności; webhook opłaca zamówienie |
| Dwie równoległe próby z różnymi kluczami (dwie karty) | jedna płatność `pending`, druga 409 `PaymentInProgress` |
| Płatność udana po anulowaniu zamówienia | płatność `succeeded` z oznaczeniem `order_cancelled`, zamówienie `cancelled`, webhook 204 bez ponowień |
| Druga udana płatność zamówienia (tylko u prawdziwego dostawcy; symulator nie opłaca anulowanej sesji) | zamówienie opłacone pierwszą, druga `succeeded` z oznaczeniem `duplicate` |
| Opłacenie równolegle z anulowaniem | jedno wygrywa (wersja zamówienia); anulowanie ponowione widzi `paid`; webhook ponowiony widzi `cancelled` i oznacza płatność do zwrotu |
| Restart workera przed terminem anulowania | wiadomość czeka w bazie; anulowanie po starcie workera |
| `ExpireUnpaidOrder` dostarczone drugi raz | bez zmian, jedno zwolnienie rezerwacji, jeden e-mail o anulowaniu |
| Dostawca niedostępny przy rozpoczęciu | 503, bez płatności; zamówienie złożone, ponowienie na stronie potwierdzenia |
| Brak płatności do terminu + 5 min | `cancelled`, rezerwacja zwolniona, e-mail o anulowaniu |
| Double-click „Zapłać” | jedna płatność (`Idempotency-Key`) |

## Kryteria akceptacji

1. Gość i klient wybierają dostawcę w kasie, płacą w symulatorze i widzą na stronie potwierdzenia „Płatność
   przyjęta”; zamówienie ma status `paid`, przychodzi e-mail o płatności. Rezerwacja zostaje aktywna.
2. Obaj dostawcy działają przez ten sam port; webhook z błędnym podpisem albo spoza tolerancji czasu jest odrzucany,
   powtórzony nic nie zmienia, kwota jest sprawdzana; konflikt wersji zamówienia daje 503 i ponowienie.
3. Nieopłacone zamówienie jest anulowane 5 minut po terminie, jego rezerwacja zwolniona (`reserved` maleje),
   klient dostaje e-mail; opłacone nie jest anulowane, anulowane nigdy nie staje się opłacone. Płatność wykonana
   po terminie albo potwierdzona po anulowaniu jest trwale oznaczona do zwrotu.
4. Nieudana albo anulowana u dostawcy płatność może być ponowiona do terminu; do oczekującej płatności można wrócić;
   zamówienie ma najwyżej jedną oczekującą płatność; po terminie i po opłaceniu rozpoczęcie płatności daje 409.
5. Gość bez tokenu albo z cudzym tokenem i klient dla cudzego zamówienia dostają 404.
6. Payment i PaymentSimulator przestrzegają reguł Deptrac; żaden context nie zależy od Payment.
7. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check`,
   `make -C apps/store test` oraz `make e2e`.

## Testy

### Jednostkowe (TDD, in-memory)

- Ordering: `markPaid` (każdy wynik, `executedAt` równe terminowi i sekundę po nim), `expireUnpaid` (przed i po terminie + 5 min, każdy status), `grantsAccess`,
  `isPayable`, token gościa, `ExpireUnpaidOrder` (zwolnienie rezerwacji tylko przy anulowaniu, ponowne zaplanowanie
  przy `tooEarly`, nieznane zamówienie), `MarkOrderPaid` (tłumaczenie konfliktu wersji), `GetOrderForPayment`
  (każdy przypadek dostępu), treści e-maili (termin w `Europe/Warsaw`).
- Payment: przejścia `Payment` (także `cancel`, `requireRefund`, `resumeUrl`), `StartPayment` (każdy błąd,
  powtórzenie, każdy wynik `cancelSession`), `HandleProviderWebhook` (deduplikacja, kwota, każdy wynik `markPaid`
  i jego oznaczenie zwrotu, `OrderBusy`, inny błąd), adaptery: podpis (dobry, zły, brak, stary znacznik czasu
  `demo-card`), parsowanie kwoty `"123.45"` i czasu wykonania, mapowanie statusów i wyników anulowania.
- PaymentSimulator: podpisy webhooków zgodne z weryfikacją adapterów (ten sam wektor testowy).

### Integracyjne

- Repozytoria Payment i Ordering (`CHECK`, unikalne indeksy), migracja.
- Przepływ na bazie testowej z `MockHttpClient` do kernela: start → strona symulatora → decyzja →
  `DeliverSimulatedWebhook` przez transport `payment_simulator` → webhook → `paid`, wiersz outboxa `OrderPaid`.
- `PlaceOrder` zapisuje `ExpireUnpaidOrder` z opóźnieniem w transakcji (wycofane złożenie: brak wiadomości);
  `ExpireUnpaidOrder` zwalnia rezerwację (`reserved` maleje) i zapisuje `OrderCancelled`.
- Seedy: `payment-simulator:seed-demo` i `ordering:seed-demo` usuwają tylko swoje kolejki (`payment_simulator`,
  `scheduled`), `outbox` i `failed` zostają; `payment:seed-demo` czyści tabele Payment; w `prod` bez `--force-reset`
  odmowa bez zmian.
- `ExpireUnpaidOrder` przeżywa restart workera: wiadomość zapisana przy złożeniu, pierwszy proces
  `messenger:consume` kończy pracę przed terminem (wiadomość niedostępna), po czym test przesuwa `available_at` tej
  wiadomości SQL-em w przeszłość i ustawia zegar aplikacji po terminie (transport porównuje `available_at` z czasem
  systemowym), a drugi proces anuluje zamówienie.
  Ta sama wiadomość dostarczona drugi raz: bez zmian, `reserved` zmienia się raz, jeden wiersz outboxa
  `OrderCancelled`.
- Webhook z wymuszonym błędem `MarkOrderPaid`: odpowiedź 5xx, płatność `pending`, brak wiersza deduplikacji;
  ponowiona dostawa opłaca zamówienie.
- Webhook płatności wykonanej przed terminem, odebrany 3 minuty po terminie: `paid`; wykonanej po terminie:
  oznaczenie `paid_after_deadline`, zamówienie `new` do anulowania.
- Webhook sukcesu dla anulowanego zamówienia: 204, oznaczenie `order_cancelled`, zamówienie `cancelled`, rezerwacja
  dalej zwolniona; powtórzony: bez zmian.
- Współbieżność (`Payment/Concurrency`, 5 rund):

| ID | Scenariusz | Oczekiwany wynik |
|---|---|---|
| W1 | to samo zdarzenie sukcesu 10 razy równolegle | jedna zmiana płatności, jedno `OrderPaid`, jeden wiersz deduplikacji, pozostałe 204 |
| W2 | dwa różne zdarzenia tej samej płatności równolegle (sukces i odmowa) | płatność `succeeded`, zamówienie `paid` raz |
| W3 | webhook sukcesu równolegle z `ExpireUnpaidOrder` (termin + 5 min minął) | dokładnie jeden status końcowy; rezerwacja zwolniona wtedy i tylko wtedy, gdy `cancelled`; przy `cancelled` płatność oznaczona do zwrotu po ponowieniu |
| W4 | 10 równoległych `StartPayment` z tym samym kluczem | jedna płatność, pozostałe powtórzenia albo 409 |
| W5 | 2 równoległe `StartPayment` z różnymi kluczami | jedna płatność `pending`, drugie 409 `PaymentInProgress` |
| W6 | nowa próba (anulowanie sesji) równolegle z decyzją „Zapłać” w symulatorze | albo sesja anulowana i nowa płatność, albo 409 i zamówienie opłacone przez webhook; nigdy obie płatności udane |
| W7 | webhook sukcesu poprzedniej płatności trzyma blokadę jej wiersza (płatność `succeeded` i zamówienie `paid` zapisane, transakcja otwarta), `StartPayment` z nowym kluczem czeka na blokadę, webhook zatwierdza | `OrderAlreadyPaid` (409), żadna nowa płatność, zamówienie `paid` |

- E-maile `SendPaymentReceived`, `SendOrderCancelled` w Mailpit; zdarzenie dostarczone drugi raz po kolei: jeden
  e-mail.

### Funkcjonalne API

Każdy endpoint z tabeli: sukces, powtórzenie, powtórzenie z tym samym kluczem bez tokenu, z cudzym tokenem albo
przez innego klienta (404), 400, 401, 404 (każdy przypadek dostępu), 409 (każdy `type`),
415, 422, 503 (dostawca niedostępny), `no-store`; webhook: każdy przypadek podpisu, deduplikacja, kwota, nieznany
dostawca i płatność, 503 przy konflikcie wersji. Symulator: 401 klucza API, strona płatności (otwarta, opłacona,
odrzucona, anulowana, po terminie), CSRF, anulowanie (otwarta, anulowana drugi raz, opłacona, odrzucona). Marker
`Unavailable`: 503 bez `detail`.

### Architektura

Deptrac z regułami Payment i PaymentSimulator; zakazane: `*_* → Payment_*`, `*_* → PaymentSimulator_*`,
`Payment_* → PaymentSimulator`, `PaymentSimulator → *_*` (każdy context), `Payment_Domain` → `Vendor`,
`Payment_Infrastructure → Ordering_Domain`.

### Vitest (`apps/store`)

BFF `/api/checkout` (brak i nieznany `provider` bez API, złożenie + rozpoczęcie, błąd rozpoczęcia, ten sam klucz po
zmianie dostawcy), BFF `/api/checkout/payment` (POST i GET, brak ciasteczka, wybór endpointu po `accessToken`
z sesją i bez, 401 i 404 odczytu, każdy wiersz tabeli wyników), `CheckoutPage` (wybór dostawcy, lista niedostępna,
przekierowanie, link „Opłać zamówienie” przy pustym koszyku i ciasteczku), `src/api/client.test.ts` (`startPayment`,
`getPaymentStatus`), strona potwierdzenia (każdy stan z tabeli, odświeżanie, „Wróć do płatności”,
„Zapłać innym sposobem” po 30 s, ponowienie, klucze, godzina w `Europe/Warsaw`).

### E2E (Playwright)

- `checkout-guest.spec.ts`: gość wybiera „Szybki przelew (demo)”, w symulatorze „Zapłać”, strona potwierdzenia
  pokazuje „Płatność przyjęta”; w Mailpit e-mail z potwierdzeniem i e-mail o płatności.
- `checkout-customer.spec.ts`: Jan płaci „Kartą płatniczą (demo)”: najpierw „Odrzuć płatność” (komunikat i ponowny
  wybór), potem „Zapłać” i „Płatność przyjęta”.
- `payment-resume.spec.ts`: gość składa zamówienie, na stronie symulatora cofa się przyciskiem „Wstecz” na `/checkout`,
  wybiera link „Opłać zamówienie R-…”, na stronie potwierdzenia „Wróć do płatności” otwiera tę samą sesję, „Zapłać”
  daje „Płatność przyjęta”.
- Komentarze o rezerwacjach w testach E2E i `fixtures/stock.yaml`: rezerwacja opłaconego zamówienia zostaje do
  wysyłki (ORDER-MANAGEMENT).

## Ryzyka i ograniczenia

- Płatność wykonana po terminie, potwierdzona po anulowaniu albo druga udana płatność zamówienia wymagają ręcznego
  zwrotu (poza zakresem): są oznaczone w `payment_payment.refund_required_reason` i w logu, bez ekranu w panelu.
  Klient takiego zamówienia widzi „anulowane” i dostaje e-mail o anulowaniu.
- Drugi e-mail (o płatności, o anulowaniu) jest możliwy przy awarii między wysyłką a zapisem znacznika i przy
  równoległym dostarczeniu tego samego zdarzenia do dwóch workerów, jak przy potwierdzeniu zamówienia (CHECKOUT).
- Czas wykonania płatności pochodzi od dostawcy (podpisana treść webhooka); API mu ufa.
- Wywołanie HTTP dostawcy w transakcji rozpoczęcia płatności (limit 5 s); przy symulatorze w tym samym API jedno
  rozpoczęcie zajmuje dwa wątki FrankenPHP. Przy prawdziwym dostawcy do przeniesienia poza transakcję.
- Webhooki bez rate limitingu (D9).
- Symulator działa w tym samym kontenerze co API, a `StartPayment` woła go przez HTTP z transakcji: gdy równoległych
  rozpoczęć płatności jest tyle, ile wątków FrankenPHP, wszystkie czekają na wolny wątek symulatora, kończą się po 5 s
  odpowiedzią 503 i przez ten czas trzymają połączenia z bazą. Lokalnie i w demo bez znaczenia; przed testami
  obciążeniowymi płatności symulator trzeba uruchomić jako osobną usługę (decyzja A3).
- `ExpireUnpaidOrder` po wyczerpaniu ponowień trafia do `failed`: zamówienie zostaje `new`, a rezerwacja aktywna, aż
  ktoś ponowi wiadomość (`bin/console messenger:failed:retry`).
- Worker obsługuje transporty po kolei (`outbox events scheduled payment_simulator`): długa kolejka zdarzeń opóźnia
  webhooki symulatora, a sklep po 30 s pokazuje „Nie mamy jeszcze potwierdzenia płatności.”.
- Gość traci możliwość zapłaty po utracie ciasteczka potwierdzenia (inne urządzenie, wylogowanie); zamówienie
  wygasa.
- Klient może zarezerwować stan na 35 minut bez płatności.
- Wydajność transportów Doctrine z opóźnieniem przy docelowej skali niepotwierdzona testami obciążeniowymi.
