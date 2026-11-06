# CHECKOUT: złożenie zamówienia przez gościa i klienta

Punkty 6 (checkout), 7 (nowe zamówienie i e-mail z potwierdzeniem) i 9 (rezerwacja stanów przy zakupie) „Zakresu MVP”
z `docs/vision.md`. Ścieżka E2E nr 1 z `docs/vision.md` kończy się w tym epiku na złożeniu zamówienia; płatność,
z którą kasa się domyka, opisuje PAYMENT #91 (`docs/features/payment/spec.md`).

Issues: #90

**Zależność od VARIANTS (#89).** Linie koszyka, ceny i stany są kluczowane wariantem (`variantId`), Catalog ma
zapytanie `GetVariantSummaries`, Pricing `GetCurrentPrices(variantIds)`, a Inventory `ReserveStock` z liniami
`{variantId, quantity}` (`docs/features/variants/spec.md`).

## Cel i zakres

- Gość i zalogowany klient (realm `shop`) przechodzą z koszyka do kasy, podają dane kontaktowe i adres dostawy
  w Polsce i składają zamówienie z całego koszyka.
- Złożenie zamówienia w jednej transakcji: odczyt i blokada koszyka, kopia cen z Pricing i nazw z Catalog w pozycjach
  zamówienia, rezerwacja stanów w Inventory (bez sprzedaży ponad stan przy równoległych zakupach), zapis zamówienia
  w statusie `new` z terminem płatności (30 minut od złożenia), opróżnienie koszyka. Zamówienie gościa dostaje losowy
  token dostępu (`accessToken`), którym gość rozpoczyna płatność (`docs/features/payment/spec.md`).
- `Idempotency-Key`: powtórzone żądanie (podwójne kliknięcie, ponowienie po błędzie sieci) nie tworzy drugiego
  zamówienia.
- Strona potwierdzenia w sklepie (po płatności pokazuje jej status) i e-mail z potwierdzeniem (Mailpit), wysyłany
  asynchronicznie po złożeniu.

Poza zakresem:
- płatność, statusy `paid` i `cancelled`, anulowanie nieopłaconych zamówień ze zwolnieniem rezerwacji (PAYMENT #91,
  `docs/features/payment/spec.md`);
- statusy po opłaceniu, anulowanie z panelu, zamówienia w panelu, historia zamówień klienta (ORDER-MANAGEMENT #92);
- kody rabatowe i promocje (PROMOTIONS #96);
- metody i koszty dostawy (decyzja P3, #120), adresy zapisane na koncie klienta (decyzja P5, #117), faktury i dane
  firmy, kraje inne niż Polska;
- rate limiting składania zamówień (decyzja T2, #116);
- powiązanie zamówienia gościa z kontem o tym samym adresie e-mail (#118), anonimizacja danych zamówień (RODO, #119).

## Decyzje

Decyzje użytkownika:

| # | Decyzja |
|---|---|
| P1 | Zamówienie obejmuje cały koszyk. Koszyk z pozycją, której nie da się kupić (`hasExcludedLines`), blokuje złożenie: klient najpierw usuwa pozycję albo zmniejsza ilość. |
| P2 | Żądanie złożenia niesie oczekiwaną kwotę brutto z podsumowania, które widział klient. Inna kwota w chwili złożenia: 409, bez zamówienia; sklep pokazuje nowe podsumowanie. |
| P3 | Bez wyboru metody dostawy i bez kosztu dostawy: suma zamówienia = suma pozycji. |
| P4 | Dane: e-mail, imię i nazwisko, telefon (wymagany), adres w Polsce: ulica i numer, kod pocztowy `NN-NNN`, miejscowość. |
| P5 | Klient zalogowany: e-mail z konta (bez edycji), imię i nazwisko wstępnie z konta, adres wpisywany przy każdym zamówieniu (bez zapisywania na koncie). |
| P6 | Numer zamówienia: `R-` i kolejny numer z sekwencji od 100001 (np. `R-100001`). |
| P7 | Strona potwierdzenia: numer zamówienia, kwota brutto i status płatności; adres e-mail po opłaceniu; szczegóły pozycji w e-mailu. Gość nie czyta zamówienia, tylko status jego płatności: tokenem dostępu (`accessToken`) z ciasteczka potwierdzenia (`docs/features/payment/spec.md`, decyzja A5), klient sesją. |
| P8 | E-mail: tekstowy, po polsku, z numerem, pozycjami (nazwa, wariant, ilość, wartość), sumą i adresem dostawy. |
| P9 | Przycisk złożenia: „Zamawiam z obowiązkiem zapłaty”. Bez akceptacji regulaminu (sklep nie ma regulaminu). |
| P10 | Gość na stronie kasy widzi link „Zaloguj się” (powrót do kasy, scalenie koszyka jak dziś). Zamówienie gościa nie jest wiązane z kontem o tym samym e-mailu. |
| A1 | Nowy bounded context Ordering z agregatem `Order` (pozycje i adres jako kopie, nie referencje), zapis przez rekordy Doctrine według ogólnej zasady. |
| A2 | Ordering prowadzi złożenie i zależy od Cart (`Ordering_Infrastructure → Cart_Application`, dwie komendy Cart). |
| A3 | Koszyk, rezerwacja i zamówienie w jednej transakcji bazy (zagnieżdżony dispatch, kontrakt z `docs/features/inventory/spec.md`), bez sagi i zdarzeń między nimi. |
| A4 | E-mail przez zdarzenie `OrderPlaced` w outboxie → RabbitMQ → komenda Ordering, Symfony Mailer (nowa zależność `symfony/mailer`) przez SMTP do Mailpit. |
| T1 | Nowa zależność `symfony/mailer`. |
| T2 | Rate limiting składania zamówień odłożony do kroku 10 planu `docs/features/agentic-software-house/plan.md` (bezpieczeństwo przed publicznym demo): limit na klienta wymaga adresu IP klienta przekazywanego przez sklep i brzeg sieci. |

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Ordering (nowy) | złożenie zamówienia, agregat `Order`, idempotencja, numer, e-mail z potwierdzeniem |
| Cart | blokada i odczyt linii koszyka do zamówienia, opróżnienie koszyka po złożeniu |
| Pricing | `GetCurrentPrices(variantIds)`: autorytatywna cena (netto, VAT, brutto, stawka, klasa) |
| Catalog | `GetVariantSummaries(variantIds)`: nazwa produktu, etykieta wariantu, `productId` |
| Inventory | `ReserveStock(reservationKey, lines)`: rezerwacja „wszystko albo nic” |
| Customer | `AuthenticatedCustomer` (`id`, `email`, `name` z tokena realmu `shop`) |
| Sklep | BFF kasy, formularz, strona potwierdzenia; żadnych obliczeń |

Reguły Deptrac (nowe warstwy `Ordering_Domain`, `Ordering_Application`, `Ordering_Infrastructure`, `Ordering_UI`;
żaden context nie zależy od Ordering):

| Warstwa | Może zależeć od |
|---|---|
| `Ordering_Domain` | `Native`, `Shared_Domain` |
| `Ordering_Application` | `Native`, `Ordering_Domain`, `Shared_Domain`, `Vendor` |
| `Ordering_Infrastructure` | `Native`, `Ordering_Application`, `Ordering_Domain`, `Shared_Domain`, `Shared_Infrastructure`, `Vendor`, `Cart_Application`, `Catalog_Application`, `Pricing_Application`, `Inventory_Application` |
| `Ordering_UI` | `Native`, `Ordering_Application`, `Ordering_Domain`, `Shared_Domain`, `Shared_Infrastructure`, `Vendor`, `Customer_Application` |

- Adaptery portów Ordering w `Ordering_Infrastructure` wołają zapytania i komendy innych contextów przez `query.bus`
  i `command.bus`, jak adaptery Cart (`QueryBusCartPrices`).
- `Ordering_UI → Customer_Application` wyłącznie przez `AuthenticatedCustomer` (jak `Cart_UI`).
- Cart nie zależy od Ordering. Od Cart zależy tylko Ordering, w jednym kierunku Ordering → Cart (komentarz
  w `deptrac.yaml`).
- Ordering ma własne value objecty (`VariantId`, `Money` itd.) i nie importuje kodu domenowego innych contextów.

## Model domenowy (Ordering)

### Value objecty

- `OrderId`: UUID v7 nadawany w handlerze; jest też kluczem rezerwacji w Inventory.
- `OrderNumber`: `R-` i liczba ≥ 100001 (P6).
- `OrderOwner`: `guest` albo `customer(customerId)`; `customerId` to `sub` z tokena (1–255 znaków).
- `IdempotencyKey`: 1–100 znaków `[A-Za-z0-9._:-]` (ten sam format co klucze Inventory).
- `EmailAddress`: przycięty, do 254 znaków, poprawny adres (walidacja formatu w DTO: `Assert\Email` i ograniczenie
  `MailerAcceptsEmail`, które buduje `Symfony\Component\Mime\Address` jak Mailer, więc `a..b@…`, `.a@…` i `a.@…`
  dają 422 zamiast zamówienia bez e-maila; domena sprawdza długość, obecność `@` i brak znaków sterujących).
- Znaki sterujące `\x00-\x1F` i `\x7F` są odrzucane w `email`, `fullName`, `phone`, `street`, `postalCode` i `city`,
  sprawdzane w wartości w takiej postaci, w jakiej przyszła (`trim` usuwa NUL, tabulator i końce linii z brzegów, więc
  sprawdzenie po przycięciu wpuściłoby je i zmieniło wartość po cichu). Stała wzorca jest w `ControlCharacters`
  (Ordering Domain); DTO używa jej w `Assert\Regex(match: false)`, VO rzucają `InvalidOrderInput`.
- `Contact {email, fullName, phone}`: `fullName` 1–100 znaków po przycięciu, bez znaków sterujących; `phone`: 9 cyfr, opcjonalnie poprzedzone
  `+48`; spacje i myślniki są usuwane, zapis w postaci `+48NNNNNNNNN`.
- `ShippingAddress {street, postalCode, city, country}`: `street` 1–100, `city` 1–60 znaków po przycięciu, oba bez znaków sterujących,
  `postalCode` `NN-NNN`, `country` zawsze `PL`.
- `Money`: nieujemne grosze (`int`) i `Currency` (tylko `PLN`).
- `PriceCopy {net, vat, gross, vatRateBp, taxClass}`: cena jednostkowa skopiowana z Pricing; `net + vat = gross`.
- `OrderLine {variantId, productId, productName, variantLabel, quantity, unitPrice: PriceCopy}`; `quantity` 1–99;
  wartości linii: `lineNet = unit.net × quantity`, `lineVat = unit.vat × quantity`, `lineGross = unit.gross × quantity`.
- `OrderTotals {net, vat, gross}`: sumy wartości linii; `net + vat = gross` co do grosza (suma linii, w których to
  zachodzi). Jedyne miejsce obliczeń sum.

### Agregat `Order`

Pola: `id`, `number`, `owner`, `accessToken` (gość: 32 losowe bajty w base64url, 43 znaki, generuje handler; klient:
null), `contact`, `shippingAddress`, `lines` (1–50, w kolejności koszyka, bez powtórzonego wariantu), `totals`
(liczone), `status` (`new`, `paid`, `cancelled`; po złożeniu `new`), `idempotencyKey`, `requestFingerprint`,
`placedAt`, `paymentDeadline` (`placedAt` + 30 min), `confirmationSentAt` (null), `version`. Pola cyklu płatności
(`paidAt`, `paymentId`, `cancelledAt`, `cancellationReason`, znaczniki e-maili o płatności i anulowaniu) opisuje
`docs/features/payment/spec.md`.

| Metoda | Działanie | Błędy |
|---|---|---|
| `place(id, number, owner, accessToken, contact, address, lines, idempotencyKey, fingerprint, now)` | nowe zamówienie w statusie `new` z terminem płatności `now` + 30 min, zapisuje zdarzenie `OrderPlaced {orderId, occurredAt}` | 0 albo > 50 linii, powtórzony wariant, zamówienie gościa bez tokenu albo klienta z tokenem: `InvalidOrder` (błąd logiczny: handler buduje linie z koszyka, który pilnuje tych limitów); ilość linii poza 1–99 odrzuca już `OrderLine::of` (też `InvalidOrder`) |
| `isReplayOf(fingerprint)` | to samo żądanie (ten sam koszyk i ta sama treść) powtórzone z tym samym kluczem | |
| `markConfirmationSent(now)` | `confirmationSentAt = now`; powtórzenie: bez zmian | |

- `reconstitute()` odtwarza zamówienie bez zdarzeń.
- `version` (optimistic locking): zapis podnosi ją przy każdej zmianie (znacznik e-maila, opłacenie, anulowanie;
  `apps/api/CLAUDE.md`, „Reguły modelu”), więc z równoległych zmian zapisuje się jedna.
- Status po złożeniu to `new`, pierwszy status cyklu z `docs/vision.md` (nowe → opłacone → wysłane → dostarczone).
  Z `new` zamówienie przechodzi w `paid` (płatność wykonana do terminu) albo w `cancelled` (brak płatności 5 minut
  po terminie; rezerwacja zwolniona) według `docs/features/payment/spec.md`. Dalsze przejścia doda ORDER-MANAGEMENT.
- Złożenie w swojej transakcji zapisuje też opóźnioną wiadomość `ExpireUnpaidOrder` (transport `scheduled`) na
  termin + 5 min; powtórzenie złożenia jej nie wysyła.

Komunikaty `detail` (po polsku, bez danych wejściowych) i `type` problemu (RFC 9457):

| Wyjątek | Rodzaj | `detail` | `type` (prefiks `tag:piotrp321.github.io,2026:shop/problems/`) |
|---|---|---|---|
| `CartEmpty` | `Conflict` (409) | „Koszyk jest pusty.” | `…/cart-empty` |
| `CartNotOrderable` | `Conflict` (409) | „Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.” | `…/cart-not-orderable` |
| `InsufficientStockForOrder` | `Conflict` (409) | „Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.” | `…/insufficient-stock-for-order` |
| `OrderTotalChanged` | `Conflict` (409) | „Kwota zamówienia się zmieniła. Sprawdź podsumowanie.” | `…/order-total-changed` |
| `IdempotencyKeyReused` | `Conflict` (409) | „Ten klucz żądania był już użyty dla innego zamówienia.” | `…/idempotency-key-reused` |
| `CartNotFound` (z Cart) | `NotFound` (404) | „Koszyk nie jest już dostępny.” (bez zmian) | `about:blank` |

Pięć wyjątków Ordering implementuje `App\Shared\Domain\Exception\TypedProblem` (`problemType(): string`) i trzyma
swój `type` w stałej `TYPE`; `ProblemDetailsSubscriber` wpisuje go do odpowiedzi. Wyjątki bez tego interfejsu i każda
inna odpowiedź błędu mają `type: about:blank`. Klient rozróżnia przyczyny 409 wyłącznie po `type`; `detail` jest
tekstem dla człowieka i może się zmienić. `type` to identyfikator (URI `tag:`), nie adres, pod którym coś jest
serwowane.

## Złożenie zamówienia (`PlaceOrder`)

Komenda `PlaceOrder(cartRef, contact, address, expectedTotalGross, idempotencyKey)` na `command.bus`, gdzie `cartRef`
to `guest(cartId)` albo `customer(customerId)`; wynik `PlacedOrder {order: OrderView, replayed: bool}`. Jedna
transakcja `command.bus` (`doctrine_transaction`), kroki w tej kolejności:

0. **Szybka ścieżka powtórzenia.** `OrderRepository::findByIdempotencyKey(key)` przed blokadą koszyka: zamówienie
   istnieje i `isReplayOf(fingerprint)`: wynik to to zamówienie z `replayed = true`, bez blokady koszyka. Dzięki temu
   powtórzenie działa także wtedy, gdy koszyk gościa w międzyczasie wygasł albo został scalony po zalogowaniu
   (blokada dałaby 404). W każdym innym przypadku dalej od kroku 1.
1. **Blokada koszyka.** Port `OrderCart::lockForOrder(cartRef)` → linie `{variantId, quantity}` w kolejności `addedAt`
   (adapter: komenda Cart `LockCartForOrder`, zagnieżdżony dispatch). Blokuje wiersz koszyka do końca transakcji, więc
   równoległe złożenia i zmiany tego koszyka czekają. Koszyk gościa nieznany, wygasły, scalony albo id koszyka klienta:
   `CartNotFound` (404). Klient bez koszyka albo z wygasłym koszykiem: brak linii.
2. **Idempotencja pod blokadą.** `OrderRepository::findByIdempotencyKey(key)` jeszcze raz (równoległe żądanie mogło
   złożyć zamówienie, czekając na tę samą blokadę):
   - zamówienie istnieje i `isReplayOf(fingerprint)`: wynik to to zamówienie z `replayed = true`, bez żadnej
     zmiany (koszyk nie jest opróżniany, więc pozycje dodane po pierwszym złożeniu zostają);
   - zamówienie istnieje z innym odciskiem (inny koszyk, inny klient albo inna treść): `IdempotencyKeyReused` (409).
   Odcisk żądania (`requestFingerprint`): SHA-256 znormalizowanych `cartRef` (`guest:<cartId>` albo
   `customer:<sub>`), danych kontaktowych, adresu i `expectedTotalGross` (po przycięciu i normalizacji telefonu).
   Koszyk jest częścią odcisku, więc dwa koszyki gości z tym samym kluczem i tymi samymi danymi dostają 409, a nie
   cudze zamówienie.
3. Brak linii: `CartEmpty` (409).
4. **Kopie danych.** Porty `OrderPrices::current(variantIds)` (Pricing `GetCurrentPrices`, tylko ceny aktualne w PLN)
   i `OrderCatalog::variants(variantIds)` (Catalog `GetVariantSummaries`), po jednym wywołaniu. Wariant bez aktualnej
   ceny albo nieznany Catalogowi: `CartNotOrderable` (409, decyzja P1).
5. **Kwota.** Linie i sumy w domenie (`OrderLine`, `OrderTotals`). `totals.gross ≠ expectedTotalGross`:
   `OrderTotalChanged` (409, decyzja P2), przed rezerwacją.
6. **Numer.** `OrderNumbers::next()`: `nextval('ordering_order_number_seq')` przez DBAL (sekwencja PostgreSQL nie jest
   częścią transakcji: wycofane złożenie zostawia lukę w numeracji, co jest dopuszczalne).
7. **Opróżnienie koszyka.** Port `OrderCart::clearAfterOrder(cartRef)` (komenda Cart `ClearCartAfterOrder`): usuwa
   wszystkie linie koszyka i odnawia jego `expiresAt` (`touch`); koszyk gościa zostaje `open`. Przy niedoborze
   w kroku 8 wycofanie transakcji przywraca linie.
8. **Rezerwacja.** Port `StockReservations::reserve(orderId, lines)` (Inventory `ReserveStock` z kluczem
   `orderId`, linie `{variantId, quantity}`, zagnieżdżony dispatch). Niedobór dowolnej linii (`InsufficientStock`
   z Inventory): `InsufficientStockForOrder` (409) bez liczby sztuk, cała transakcja wycofana. Rezerwacja jest
   ostatnią operacją przed zapisem zamówienia, więc wiersze stanów (najbardziej obciążone przy popularnym wariancie)
   są zablokowane możliwie krótko.
9. **Zapis.** `Order::place(…)`, `OrderRepository::add(order)` i zdarzenie `OrderPlaced` w outboxie
   (`DomainEventPublisher`), w tej samej transakcji. Zapis zamówienia jest ostatnim krokiem, więc `flush` komend
   zagnieżdżonych nie zapisuje niczego z Ordering.

Zasady transakcji (zapisywane w `apps/api/CLAUDE.md`):
- Handler nigdy nie łapie wyjątku z zagnieżdżonej komendy, żeby pisać dalej: każdy błąd wycofuje całą transakcję
  (koszyk, rezerwację, zamówienie, outbox). Wyjątek Inventory jest tłumaczony na wyjątek Ordering i rzucany dalej.
- Rollback jest atomowy: niedobór choćby jednej pozycji z wielu (albo błąd dowolnego późniejszego zapisu) zostawia
  bazę jak przed żądaniem. Koszyk ma te same linie, `reserved` żadnego wariantu się nie zmienia (Inventory
  rezerwuje „wszystko albo nic”, a wycofanie transakcji cofa każdy zapis), nie ma zamówienia, linii ani wiersza
  outboxa. Jedyny ślad to luka w numeracji.
- Kolejność blokad: wiersz koszyka, potem wiersze stanów (rosnąco po `variant_id`, `lockMany` Inventory). Inne komendy
  Cart blokują tylko koszyki (scalenie: gość, potem klient), a Inventory tylko stany i rezerwacje, więc nie ma cyklu
  blokad. Złożenie klienta blokuje tylko koszyk klienta.
- Równoległe złożenia z tym samym kluczem i tym samym koszykiem szereguje blokada koszyka: drugie widzi zamówienie
  pierwszego (krok 2). Repozytoria zapisują przez `flush` (np. `DoctrineCartRepository`); zapis zamówienia jest
  ostatni, więc `flush` komend zagnieżdżonych nie zapisuje niczego z Ordering. Równoległe złożenia z tym samym kluczem z dwóch różnych koszyków: unikalny indeks
  `idempotency_key`; naruszenie repozytorium tłumaczy na `IdempotencyKeyReused` (409).
- Poziom izolacji: READ COMMITTED.

Idempotencja a dane innych klientów:
- Zamówienie wraca w odpowiedzi tylko przy zgodnym odcisku. Odcisk zawiera `cartRef`: `cartId` z podpisanego
  ciasteczka gościa albo `sub` z tokena klienta. Sam klucz nigdy nie zwraca zamówienia.
- Odpowiedź 409 `IdempotencyKeyReused` ma tylko typ i stały `detail`, bez `orderId`, numeru, e-maila ani innych
  danych zamówienia, które użyło klucza.
- Powtórzenie po utracie koszyka gościa (wygaśnięcie, scalenie po zalogowaniu) idzie szybką ścieżką (krok 0), więc
  działa bez koszyka, ale tylko z tym samym `cartId` i tą samą treścią.

Ceny i kwoty liczy wyłącznie serwer:
- Żądanie nie niesie cen, ilości ani pozycji. Linie i ilości pochodzą z zablokowanego koszyka, ceny z Pricing
  w chwili złożenia (krok 4), a wartości linii i sumy liczy domena (`OrderLine`, `OrderTotals`).
- `expectedTotalGross` służy wyłącznie do porównania (P2) i nie trafia do zamówienia. Kwota niższa albo wyższa od
  sumy liczonej przez serwer daje 409 bez zamówienia.
- Nieznane pola treści żądania są ignorowane (jak w koszyku, `MapRequestPayload`): pola typu `lines`, `totals`,
  `unitGross` nie mają wpływu na zamówienie. BFF sklepu przekazuje do API tylko pola z kontraktu.

### Komendy Cart dla Ordering

- `LockCartForOrder(cartRef)` → `list<{variantId, quantity}>`: blokada jak w innych komendach Cart (`LockedCarts`,
  `lockForCustomer` bez zakładania koszyka), `openForChange(now)` (gość: 404 dla `merged` i wygasłego; klient:
  wygasły koszyk czyści w pamięci), linie z `linesAt(now)`. Niczego nie zapisuje: wygasły koszyk klienta jest tylko
  czytany jako pusty (porządkuje go `cart:purge-expired`).
- `ClearCartAfterOrder(cartRef)`: blokuje koszyk jak każda komenda Cart (`LockedCarts`; w transakcji, która już go
  zablokowała przez `LockCartForOrder`, to odświeżenie rekordu pod tą samą blokadą), nowa metoda agregatu
  `Cart::clearAfterOrder(now)` usuwa wszystkie linie i wywołuje `touch(now)`. Klient bez koszyka: bez zmian.
- Obie komendy są w `Cart_Application` i nie znają Ordering.

## Persystencja

- `ordering_order`: `id uuid` (PK), `number varchar(20)` (unikalny), `status varchar(20)`, `owner_type` (`guest` |
  `customer`), `customer_id varchar(255) NULL`, `email varchar(254)`, `full_name varchar(100)`, `phone varchar(12)`,
  `street varchar(100)`, `postal_code char(6)`, `city varchar(60)`, `country char(2)`, `currency char(3)`,
  `total_net`, `total_vat`, `total_gross` (`bigint`), `idempotency_key varchar(100)` (unikalny),
  `request_fingerprint char(64)`, `placed_at`, `payment_deadline`, `access_token varchar(43) NULL`,
  `confirmation_sent_at NULL`, `version int` oraz kolumny cyklu płatności (`docs/features/payment/spec.md`,
  „Persystencja”); `CHECK ((owner_type = 'customer') = (customer_id IS NOT NULL))`,
  `CHECK ((owner_type = 'guest') = (access_token IS NOT NULL))`, `CHECK (total_net + total_vat = total_gross)`.
- `ordering_order_line`: `id uuid` (v7, PK), `order_id uuid` (FK → `ordering_order`, bez `ON DELETE CASCADE`:
  zamówienia są przechowywane na zawsze), `position int`, `variant_id uuid`, `product_id uuid`,
  `product_name varchar(255)`, `variant_label varchar(255)`, `quantity int` (`CHECK 1–99`), `unit_net`, `unit_vat`,
  `unit_gross` (`int`), `vat_rate_bp int`, `tax_class varchar(50)`, `line_net`, `line_vat`, `line_gross` (`bigint`);
  unikalne `(order_id, position)` i `(order_id, variant_id)`.
- Sekwencja `ordering_order_number_seq` `START 100001`.
- Bez kluczy obcych do tabel Cart, Catalog, Pricing i Inventory.
- Rekordy Doctrine z atrybutami w `Ordering/Infrastructure/Persistence/Doctrine/Record`, mapowanie agregat ↔ rekord
  w `DoctrineOrderRepository` (`add`, `save`, `get`, `findByIdempotencyKey`), `#[Version]` na rekordzie zamówienia.
  Długości kolumn z wartościami z innych contextów (`product_name`, `variant_label`, `tax_class`) zgadzają się
  z kolumnami źródłowymi.
- Migracje: `Version…CreateOrderingTables`, `Version…AddOrderPaymentCycle`.

## E-mail z potwierdzeniem

- Zdarzenie `OrderPlaced {orderId, occurredAt}` (outbox → RabbitMQ, jak `ProductCreated`). Handler zdarzenia
  `SendOrderConfirmation` (`event.bus`, transport `events`, jak `IndexProduct`) robi całą pracę sam, bez komendy
  i bez transakcji wokół SMTP:
  - odczyt zamówienia; nieznane zamówienie: `UnrecoverableMessageHandlingException` (jak `IndexProduct`);
  - `confirmationSentAt` ustawione: nic (odbiorca idempotentny);
  - inaczej port `OrderConfirmationMailer::send(OrderView)`, potem `markConfirmationSent(now)` i zapis repozytorium
    (`flush`, `UPDATE` z wersją). Konflikt wersji przy zapisie (równoległe dostarczenie): wyjątek, ponowienie
    wiadomości, które widzi już ustawiony znacznik.
- Outbox: wiersz `OrderPlaced` powstaje w transakcji złożenia, więc wycofane złożenie nie zostawia zdarzenia ani
  e-maila. Relay outbox → RabbitMQ i dostawa do workera działają „co najmniej raz”: to samo zdarzenie może przyjść
  ponownie (ponowienie relaya po braku potwierdzenia, `redeliver_timeout`, ponowienie po błędzie handlera).
  Odbiorca jest idempotentny dzięki znacznikowi `confirmationSentAt` i wersji zamówienia.
- Wysyłka i zapis znacznika nie są atomowe: awaria między nimi albo równoległe dostarczenie tego samego zdarzenia do
  dwóch workerów daje drugi e-mail (świadome, w ryzykach; odwrotna kolejność groziłaby brakiem e-maila). Błąd SMTP:
  ponowienia według strategii transportu `events`, potem `failed`.
- Adapter `SymfonyOrderConfirmationMailer` (`Ordering_Infrastructure`, Symfony Mailer): wysyłka synchroniczna przez
  transport Mailera, bez Messengera (`framework.mailer.message_bus: false`; inaczej `MailerInterface::send()` wysłałby
  `SendEmailMessage` na domyślną szynę `command.bus`), więc błąd SMTP wraca do handlera. `MAILER_DSN`
  (`smtp://mailpit:1025` w `infra/docker-compose.yml` dla `api` i `worker`, w konfiguracji testów i CI), nadawca
  z `MAILER_FROM` (`Rytm <zamowienia@rytm.localhost>`), treść tekstowa po polsku (P8), temat „Potwierdzenie zamówienia
  R-100001”. Treść składa jedna klasa w `Ordering_Application` (`OrderConfirmationText`), testowana jednostkowo.
- Treść: powitanie z imieniem i nazwiskiem, numer, data i godzina złożenia w strefie `Europe/Warsaw`, pozycje
  (nazwa, etykieta wariantu po przecinku; wariant domyślny z pustą etykietą: sama nazwa, bez separatora; ilość,
  wartość brutto linii), suma brutto, adres dostawy, telefon, zdanie „Zamówienie czeka na płatność do 14:35
  (dd.mm.rrrr). Bez płatności anulujemy je automatycznie.” (termin płatności w strefie `Europe/Warsaw`). Kwoty
  w formacie `1 234,56 zł`.

## Dane demo (`make seed`)

- Komenda `ordering:seed-demo` czyści tabele Ordering (`ordering_order_line`, `ordering_order`) jednym
  `TRUNCATE` i usuwa kolejkę `scheduled` (`DELETE FROM messenger_messages WHERE queue_name = 'scheduled'`, wiadomości
  `ExpireUnpaidOrder` usuniętych zamówień) (`DbalOrderingReset`); `outbox` i `failed` zostają, sekwencja numerów też
  (luki są dopuszczalne). W `prod` wymaga `--force-reset`, jak pozostałe seedy.
- `make seed` uruchamia ją przed `inventory:seed-demo`, który czyści rezerwacje: po seedzie nie ma zamówienia bez
  rezerwacji o kluczu `orderId`.
- Wiersze outboxa `OrderPlaced` dla usuniętych zamówień kończą się w handlerze e-maila
  `UnrecoverableMessageHandlingException` (transport `failed`), jak dziś zdarzenie produktu po resecie katalogu.

## API

Sklep (`apps/api/openapi.json`). Błędy RFC 9457, `detail` z wyjątku domeny. Odpowiedzi obu endpointów (także błędy)
mają `Cache-Control: private, no-store`. `security.yaml`: `^/api/v1/orders$` → `PUBLIC_ACCESS` (przed regułą
`ROLE_CUSTOMER`); `/api/v1/me/orders` objęte istniejącą regułą `ROLE_CUSTOMER`.

| Endpoint | Sukces | Błędy |
|---|---|---|
| `POST /api/v1/orders` (gość), nagłówek `Idempotency-Key`, body `{cartId, email, fullName, phone, street, postalCode, city, expectedTotalGross}` | 201 `OrderView`, nagłówek `Location` nieobecny (bez odczytu zamówienia); 200 powtórzenie z tym samym kluczem i treścią | 400 brak lub zły klucz, zły JSON; 401 niepoprawny token; 404 koszyk; 409 z `type` przyczyny (`CartEmpty`, `CartNotOrderable`, `InsufficientStockForOrder`, `OrderTotalChanged`, `IdempotencyKeyReused`; schemat OpenAPI `OrderConflictProblem` z `enum` wartości `type`); 415; 422; 503 (token wysłany, klucze wystawcy niedostępne) |
| `POST /api/v1/me/orders` (klient), nagłówek `Idempotency-Key`, body `{fullName, phone, street, postalCode, city, expectedTotalGross}` | jak wyżej; e-mail z tokena | 401 (bez tokena, token `backoffice`), 503 (klucze wystawcy niedostępne), pozostałe jak wyżej bez 404 |

`OrderView`:

```
{
  orderId: uuid,
  number: string,
  status: "new" | "paid" | "cancelled" (po złożeniu "new"),
  email: string,
  fullName: string,
  phone: string,
  shippingAddress: { street, postalCode, city, country: "PL" },
  lines: [{ variantId, productName, variantLabel, quantity, unitGross: int, lineGross: int }],
  totals: { net: int, vat: int, gross: int, currency: "PLN" },
  placedAt: string (RFC 3339, UTC),
  paymentDeadline: string (RFC 3339, UTC; placedAt + 30 min),
  accessToken: string | null (gość: 43 znaki base64url; klient: null)
}
```

- Powtórzenie złożenia (200) zwraca to samo zamówienie, także ten sam `accessToken`.

- Token wysłany do `POST /api/v1/orders` i niepoprawny: 401 (jak koszyk gościa). Token poprawny nie zmienia
  endpointu gościa (klient używa `/me/orders`).
- Typy ścisłe, bez rzutowania. Komunikaty 422 (`violations[*].title`, jedno naruszenie na pole, `Assert\Sequentially`,
  pola DTO `mixed`, wymagane w OpenAPI jak w koszyku):

| Pole | Przypadek | Komunikat |
|---|---|---|
| `cartId` | brak, `null` | Identyfikator koszyka jest wymagany. |
| `cartId` | nie UUID | Identyfikator koszyka musi być UUID. |
| `email` | brak, `null`, pusty | Adres e-mail jest wymagany. |
| `email` | zły format (także taki, jakiego nie przyjmie Mailer), znak sterujący albo > 254 znaki | Podaj poprawny adres e-mail. |
| `fullName` | brak, pusty po przycięciu | Imię i nazwisko są wymagane. |
| `fullName` | > 100 znaków | Imię i nazwisko mogą mieć najwyżej 100 znaków. |
| `fullName` | znak sterujący | Imię i nazwisko zawierają niedozwolone znaki. |
| `phone` | brak, pusty | Numer telefonu jest wymagany. |
| `phone` | inny format (także ze znakiem sterującym) | Podaj 9-cyfrowy numer telefonu. |
| `street` | brak, pusty / > 100 / znak sterujący | Ulica i numer są wymagane. / Ulica i numer mogą mieć najwyżej 100 znaków. / Ulica i numer zawierają niedozwolone znaki. |
| `postalCode` | brak, pusty / zły format (także ze znakiem sterującym) | Kod pocztowy jest wymagany. / Podaj kod pocztowy w formacie 00-000. |
| `city` | brak, pusty / > 60 / znak sterujący | Miejscowość jest wymagana. / Miejscowość może mieć najwyżej 60 znaków. / Miejscowość zawiera niedozwolone znaki. |
| `expectedTotalGross` | brak, `null` / nie liczba całkowita / ujemna | Kwota zamówienia jest wymagana. / Kwota zamówienia musi być liczbą całkowitą. / Kwota zamówienia nie może być ujemna. |

- Nagłówek `Idempotency-Key` brak albo zły format: 400 z naruszeniem nagłówka (jak przyjęcie magazynowe).
- `cartId` wskazujący koszyk klienta: 404 (jak endpointy koszyka gościa).

## Sklep (`apps/store`)

### BFF

- `POST /api/checkout` (route handler w `app/api/checkout/`, `Cache-Control: private, no-store`, owinięty jak
  `withCartDeps`; kasa z płatnością: `docs/features/payment/spec.md`, „Sklep”): wymaga `Origin` równego `STORE_URL` i `Content-Type: application/json` (inaczej 403 bez API),
  nagłówka `Idempotency-Key` od przeglądarki (brak: 422 „Nieprawidłowe dane.”). Wybór koszyka jak w BFF koszyka:
  sesja → `POST /api/v1/me/orders` (przed złożeniem scalenie `store_cart`, jak przy każdej operacji koszyka), bez
  sesji → `POST /api/v1/orders` z `cartId` z `store_cart`; bez `store_cart`: 409 „Koszyk jest pusty.” z `type`
  `CartEmpty` (`…/cart-empty`) bez API.
- Body niesie też `provider` (kod z `GET /api/v1/payment-providers`). Brak albo kod spoza listy: 422 z naruszeniem pola
  `provider` („Wybierz sposób płatności.”), lista niedostępna: 503, w obu przypadkach przed scaleniem koszyka i bez
  składania zamówienia.
- Sukces (201 i 200): ciasteczko `store_order_confirmation` z `{orderId, number, email, totalGross, currency,
  accessToken}` (`accessToken` z `OrderView`: gość token, klient `null`) podpisane jak `store_cart`, `HttpOnly`,
  `SameSite=Lax`, `Path=/`, `Max-Age` 1 godzina. Potem BFF rozpoczyna płatność tym samym `Idempotency-Key`
  (gość z `Order-Access-Token`, klient przez `/me/...`); odpowiedź `{number, redirectUrl}`, gdzie `redirectUrl` jest
  `null`, gdy płatności nie udało się rozpocząć (zamówienie stoi, klient płaci ze strony potwierdzenia).
  `store_cart` zostaje (koszyk jest pusty). `POST /auth/logout` usuwa to ciasteczko razem z sesją (zawiera e-mail
  zamówienia i token dostępu, więc nie może przeżyć wylogowania).
- `POST` i `GET /api/checkout/payment` (`src/checkout/payment-bff.ts`): rozpoczęcie płatności i odczyt jej statusu dla
  zamówienia z ciasteczka. Endpoint wybiera ciasteczko, nie sesja: `accessToken` → endpointy gościa z
  `Order-Access-Token` (także gdy gość zalogował się po złożeniu), `accessToken: null` → `/me/...` z tokenem sesji (bez
  sesji 401 bez API). Strona potwierdzenia czyta ten sam status po stronie serwera (`readPaymentStatus`).
- Błędy: 404, 409 i 422 API przekazywane z `detail` i `violations`, a 409 także z `type` bez zmian (`about:blank` albo
  brak, gdy tak odpowiedziało API); 404 gościa (koszyk zniknął: nieznany, wygasły,
  scalony) usuwa też `store_cart`, jak BFF koszyka; 401 sesji kończy sesję; 503 przy niedostępnym API lub
  zależnościach (także 400 i 415 API oraz 401 endpointu gościa).
- `/auth/session` zwraca `{name, email}` (`email` z `GET /api/v1/me`); typ `AccountSession` w `src/api/client.ts`.
- `src/api/client.ts`: `placeOrder(input, idempotencyKey)` (wynik `ok` z `number` i `redirectUrl`), `startPayment`
  i `getPaymentStatus` (jedyne miejsce `fetch`). Wynik 409 to `conflict` z `reason`
  wyznaczonym wyłącznie z `type` (`src/checkout/problems.ts`: mapa typów ze schematu OpenAPI `OrderConflictProblem`
  na przyczyny, kompilator wymaga wpisu dla każdego typu schematu; inny albo brakujący `type` to `unknown`). `detail`
  służy tylko do wyświetlenia.

### UI

- `/cart`: przycisk „Przejdź do kasy” (link do `/checkout`), aktywny, gdy koszyk ma linie i nie ma
  `hasExcludedLines`; przy `hasExcludedLines` nieaktywny z tekstem „Usuń albo popraw oznaczone pozycje, żeby złożyć
  zamówienie.”.
- `/checkout` (`noindex, nofollow`, Client Component): podsumowanie z `/api/cart` (linie: nazwa, etykieta wariantu,
  ilość, wartość; suma brutto), formularz (P4, P5), grupa radiowa „Sposób płatności” (dostawcy z API, wymagany wybór, bez domyślnego), przycisk
  „Zamawiam z obowiązkiem zapłaty” (P9). Gość: pole
  e-mail i link „Zaloguj się” (`/auth/login?returnTo=/checkout`). Klient: e-mail z `/auth/session` jako tekst,
  imię i nazwisko wstępnie wypełnione z sesji (konto bez imienia ma w sesji e-mail jako nazwę: pole zostaje puste).
  Pusty koszyk albo `hasExcludedLines`: zamiast formularza komunikat i link do koszyka; przy pustym koszyku
  i poprawnym `store_order_confirmation` także link „Opłać zamówienie R-…” na `/checkout/confirmation` (powrót ze
  strony dostawcy bez decyzji, `docs/features/payment/spec.md`, P7).
- `Idempotency-Key`: UUID (`crypto.randomUUID`) tworzony przy wejściu na `/checkout` i używany ponownie przy
  ponowieniu niepewnego wyniku (błąd sieci, 403, 5xx, 503). Klucz należy do jednej logicznej próby: nowy powstaje
  po zmianie danych formularza, po 409 `IdempotencyKeyReused` i po 409 `OrderTotalChanged` (żądanie niesie wtedy
  inną kwotę, która należy do odcisku żądania, a nic nie zostało złożone). Po 409 `CartEmpty`, `CartNotOrderable`,
  `InsufficientStockForOrder` i po nieznanym 409 klucz zostaje: ponowienie tym samym kluczem po ewentualnym sukcesie
  da powtórzenie 200, nie drugie zamówienie. Strona niczego nie ponawia sama: każdy POST to kliknięcie klienta.
  Znany skutek reguły „zmiana danych = nowy klucz”: gdy wynik poprzedniej próby był niepewny (błąd sieci, 5xx, 503,
  403), a zamówienie jednak powstało, zmiana pola i ponowne kliknięcie dają 409 `CartEmpty` (pusty koszyk), a nie
  potwierdzenie. Drugiego zamówienia nie ma, bo koszyk jest pusty.
- `expectedTotalGross` = `totals.gross` z ostatnio wczytanego podsumowania. Na czas żądania przycisk jest
  zablokowany.
- Sukces: licznik koszyka 0 (zdarzenie `src/cart/events.ts`), przejście na stronę dostawcy (`redirectUrl`) albo,
  bez niego, na `/checkout/confirmation`.
- `/checkout/confirmation` (`noindex, nofollow`, Server Component czytający podpisane `store_order_confirmation`
  i status płatności z API tokenem gościa albo sesją klienta; odświeżanie i ponowienie płatności w Client Component
  `PaymentConfirmation`): „Dziękujemy za zamówienie”, numer, kwota brutto (`formatPrice`) i stan płatności według
  `docs/features/payment/spec.md` (po opłaceniu „Płatność przyjęta. Potwierdzenie wysłaliśmy na adres …”), link
  „Wróć do sklepu”. Token dostępu i `orderId` zostają na serwerze, do przeglądarki idą numer, e-mail, kwota, status
  i lista dostawców. Bez ciasteczka albo z niepoprawnym podpisem: „Nie znaleźliśmy informacji o zamówieniu.” i link do
  sklepu.

| Sytuacja | UI na `/checkout` |
|---|---|
| 422 | komunikaty przy polach (`violations`) |
| 409 `OrderTotalChanged` | `detail`, ponowne wczytanie podsumowania (nowa kwota), formularz zostaje, nowy klucz, bez linku do koszyka (pusty koszyk albo `hasExcludedLines` po przeładowaniu zastępuje formularz komunikatem z własnym linkiem) |
| 409 `CartEmpty`, `CartNotOrderable`, `InsufficientStockForOrder` | `detail` i link „Wróć do koszyka”, ponowne wczytanie podsumowania, klucz zostaje; gdy przeładowane podsumowanie zastępuje formularz komunikatem z własnym linkiem, bez drugiego linku |
| 409 `IdempotencyKeyReused` | `detail`, nowy klucz, formularz zostaje, bez linku do koszyka (kolejne kliknięcie składa zamówienie z nowym kluczem) |
| 409 nieznany (inny `type` albo brak) | `detail` (bez niego „Nie udało się złożyć zamówienia. Spróbuj ponownie.”) i link „Wróć do koszyka”, klucz zostaje, bez automatycznego ponawiania |
| 404 koszyka | „Koszyk nie jest już dostępny.” i link do sklepu |
| 401 | „Twoja sesja wygasła. Zaloguj się ponownie.” z linkiem (`returnTo=/checkout`) |
| 403, 503, 5xx, błąd sieci | „Nie udało się złożyć zamówienia. Spróbuj ponownie.” (ponowienie z tym samym kluczem) |

Po każdym odrzuceniu fokus przechodzi na komunikat błędu (`role="alert"`, `tabIndex=-1`), bo przycisk jest zablokowany na
czas żądania; po 422 z błędami pól na pierwsze błędne pole (w kolejności formularza), które ma `aria-invalid`
i `aria-describedby` na swój komunikat.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Podwójne kliknięcie, ponowienie po zgubionej odpowiedzi | jedno zamówienie; ponowienie dostaje 200 z tym samym zamówieniem, także gdy koszyk gościa w międzyczasie wygasł albo został scalony |
| Ten sam klucz z inną treścią albo z innego koszyka | 409 `IdempotencyKeyReused` |
| Dwa złożenia z różnymi kluczami z jednego koszyka (dwie karty) | jedno zamówienie, drugie 409 `CartEmpty` |
| Ostatnia sztuka kupowana równolegle przez wielu klientów | tylu zamawiających, ile sztuk; reszta 409 `InsufficientStockForOrder`, ich koszyki bez zmian |
| Zmiana ceny między podsumowaniem a złożeniem | 409 `OrderTotalChanged`, bez rezerwacji i zamówienia |
| Pozycja bez ceny, nieznany wariant | 409 `CartNotOrderable` |
| Dodanie do koszyka równolegle ze złożeniem | blokada koszyka: dodanie przed złożeniem jest w zamówieniu, po złożeniu zostaje w pustym koszyku |
| Błąd w trakcie złożenia (np. zapis zamówienia) | całość wycofana: koszyk bez zmian, brak rezerwacji, brak zdarzenia |
| Błąd wysyłki e-maila | zamówienie złożone; ponowienia transportu, potem `failed` |
| Zdarzenie dostarczone dwa razy, jedno po drugim | jeden e-mail (znacznik `confirmationSentAt`) |
| `make seed` po złożeniu zamówień | zamówienia i rezerwacje usunięte razem |
| Zamówienie nieopłacone | anulowane 5 minut po terminie płatności, rezerwacja zwolniona, e-mail o anulowaniu (`docs/features/payment/spec.md`) |

## Kryteria akceptacji

1. Gość i zalogowany klient składają zamówienie z koszyka na `/checkout`; zamówienie ma kopię nazw, etykiet wariantów,
   cen (netto, VAT, brutto, stawka, klasa) i adresu, status `new` i numer `R-…`; koszyk jest pusty.
2. Złożenie rezerwuje stany wszystkich pozycji w tej samej transakcji; przy niedoborze choćby jednej pozycji nic nie
   jest zapisane i nic nie jest zarezerwowane (koszyk, stany, zamówienie i outbox jak przed żądaniem). Sprzedaż
   ponad stan jest niemożliwa przy równoległych złożeniach (O1).
3. `Idempotency-Key`: ponowienie z tym samym kluczem i treścią zwraca to samo zamówienie bez drugiej rezerwacji, także
   równolegle (O2) i po wygaśnięciu albo scaleniu koszyka gościa (O6); inna treść, inny koszyk albo inny klient: 409
   bez danych cudzego zamówienia (O7).
4. Kwota inna niż oczekiwana, pusty koszyk i pozycje niemożliwe do kupienia dają 409 bez zmian. Ceny, ilości i sumy
   zamówienia pochodzą wyłącznie z koszyka, Pricing i domeny; pola żądania ich nie zmieniają.
5. Po złożeniu przychodzi jeden e-mail z potwierdzeniem do Mailpit, także przy powtórzonej dostawie zdarzenia po
   kolei; wycofane złożenie nie zostawia zdarzenia w outboxie.
6. Strona potwierdzenia pokazuje numer, kwotę i status płatności (e-mail po opłaceniu); strony kasy mają `noindex`, odpowiedzi `private, no-store`.
7. Żaden context nie zależy od Ordering; Deptrac odrzuca zależności spoza reguł Ordering.
8. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check`,
   `make -C apps/store test` oraz `make e2e`.

## Testy

### Jednostkowe (TDD, adaptery in-memory; `apps/api/tests/Unit/Ordering`, `apps/api/tests/Unit/Cart`)

- Value objecty: telefon (normalizacja, `+48`, 8 i 10 cyfr), kod pocztowy, długości po przycięciu, `OrderNumber`.
- `OrderLine` i `OrderTotals`: wartości linii, sumy, `net + vat = gross`, maksimum 50 × 99 × 99 999 999 w `int`.
- `Order`: `place` (zdarzenie, status `new`), limity linii, `isReplayOf`, `markConfirmationSent` idempotentne.
- `PlaceOrder`: pusty koszyk, wariant bez ceny i nieznany, kwota inna, niedobór z Inventory, powtórzenie (bez zmian,
  koszyk nie opróżniony; szybka ścieżka bez blokady koszyka, także dla koszyka gościa scalonego albo wygasłego), klucz
  z inną treścią, z innego koszyka gościa przy tych samych danych i innego klienta (409), kolejność kroków (rezerwacja
  po sprawdzeniu kwoty, numerze i opróżnieniu koszyka; zapis na końcu).
- `PlaceOrder` liczy kwoty z portu `OrderPrices` niezależnie od `expectedTotalGross` (kwota niższa i wyższa: 409).
- `SendOrderConfirmation`: wysyłka i znacznik, drugi raz bez wysyłki, nieznane zamówienie, konflikt wersji przy
  zapisie znacznika rzuca wyjątek (ponowienie), a ponowienie widzi znacznik i nie wysyła; `OrderConfirmationText`
  (treść, formatowanie kwot, data w `Europe/Warsaw`, wariant domyślny bez etykiety).
- Cart: `clearAfterOrder`, `LockCartForOrder` (gość `merged`, wygasły, koszyk klienta wygasły).

### Integracyjne (PostgreSQL, Mailpit; `apps/api/tests/Integration/Ordering`)

- `DoctrineOrderRepository`: mapowanie, `CHECK`, unikalne indeksy (bezpośredni SQL łamiący reguły jest odrzucany),
  naruszenie `idempotency_key` → `IdempotencyKeyReused`, sekwencja numerów.
- `PlaceOrder` na prawdziwej bazie: sukces (zamówienie, linie, rezerwacja z kluczem `orderId`, koszyk pusty,
  wiersz outboxa); niedobór i wymuszony błąd zapisu zamówienia wycofują wszystko (koszyk z liniami, brak rezerwacji,
  brak zamówienia, brak wiersza outboxa).
- Atomowy rollback: koszyk z 3 pozycjami, niedobór tylko ostatniej; `reserved` i `on_hand` wszystkich trzech
  wariantów bez zmian, brak rezerwacji, koszyk z 3 liniami i tym samym `expiresAt`, brak zamówienia, linii i wiersza
  outboxa. To samo przy wymuszonym błędzie zapisu wiersza outboxa.
- Zdarzenie `OrderPlaced` przechodzi przez serializer transportu `events` (zapis i odczyt) i handler dostaje
  `orderId`.
- Adaptery portów (Cart, Pricing, Catalog, Inventory) przez magistrale.
- `SymfonyOrderConfirmationMailer` wysyła do Mailpit synchronicznie (odczyt przez API Mailpit); niedostępny SMTP
  daje wyjątek w handlerze, bez wiadomości na `command.bus`.
- Powtórna dostawa przez worker (outbox → RabbitMQ → `events`): to samo `OrderPlaced` dostarczone dwa razy po kolei
  daje jeden e-mail; błąd SMTP przy pierwszej próbie daje ponowienie i jeden e-mail po przywróceniu SMTP, a po
  wyczerpaniu ponowień wiadomość jest w `failed`, a zamówienie bez znacznika; dwa równoległe handlery tego samego
  zdarzenia: jeden zapis znacznika się udaje, drugi dostaje konflikt wersji, a jego ponowienie niczego nie wysyła
  (najwyżej dwa e-maile, ryzyko z „Ryzyk”).
- `ordering:seed-demo`: czyści zamówienia i linie; w `prod` bez `--force-reset` odmowa bez zmian; `make seed` po
  złożonych zamówieniach zostawia zero zamówień i zero rezerwacji.

### Współbieżność (`Ordering/Concurrency/PlaceOrderConcurrencyTest`, jak C1–C6 i K1–K8, 5 rund)

| ID | Scenariusz | Oczekiwany wynik |
|---|---|---|
| O1 | 10 koszyków gości z tym samym wariantem (1 szt.), stan 3, równoległe złożenia | dokładnie 3 zamówienia i 7 × `InsufficientStockForOrder`; `reserved` = 3; 7 koszyków z liniami |
| O2 | 10 równoległych złożeń z tym samym kluczem i koszykiem | 1 zamówienie, 1 × 201 i 9 × powtórzenie z tym samym `orderId`, rezerwacja raz |
| O3 | 2 równoległe złożenia z różnymi kluczami z jednego koszyka | 1 zamówienie, 1 × `CartEmpty` |
| O4 | 2 koszyki `[A, B]` i `[B, A]` równolegle, stan A = B = 1 | dokładnie 1 zamówienie, brak zakleszczeń |
| O5 | złożenie klienta równolegle ze scaleniem koszyka gościa do tego klienta | brak zakleszczeń; linie gościa są w zamówieniu albo w koszyku klienta po złożeniu, nigdy utracone |
| O6 | po złożeniu koszyk gościa scalony do konta (osobna runda: wygasły); 10 równoległych powtórzeń z tym samym kluczem, `cartId` i treścią | 10 × powtórzenie z tym samym `orderId`, żadnego 404, żadnej nowej rezerwacji |
| O7 | ten sam klucz równolegle z dwóch różnych koszyków gości (osobna runda: dwóch klientów), te same dane | 1 zamówienie, 1 × `IdempotencyKeyReused` bez danych zamówienia; koszyk przegranego z liniami, jedna rezerwacja |

Po każdej rundzie: `reserved` każdego wariantu = suma ilości aktywnych rezerwacji, każde zamówienie ma rezerwację
o kluczu `orderId` z tymi samymi liniami.

### Funkcjonalne API (`apps/api/tests/Functional/Ordering`)

Oba endpointy: 201, 200 (powtórzenie), 400 (brak klucza, zły klucz, zły JSON), 401 (`/me/orders` bez tokena i z tokenem
`backoffice`; niepoprawny token na `/orders`), 404 (koszyk gościa nieznany, wygasły, scalony, koszyk klienta), 409
(każda przyczyna), 415, 422 (każdy wiersz tabeli komunikatów, dokładna lista `violations`), `Cache-Control:
private, no-store`.

- Ceny: treść z dodatkowymi polami (`lines` z innymi ilościami, `totals`, `unitGross`, `price`) daje zamówienie
  z ilościami z koszyka i cenami z Pricing; `expectedTotalGross` niższe (także 0) i wyższe od sumy daje 409
  `OrderTotalChanged` bez zamówienia i rezerwacji.
- Idempotencja: 409 `IdempotencyKeyReused` nie zawiera `orderId`, numeru ani e-maila zamówienia, które użyło klucza
  (inny koszyk gościa, inny klient, ta sama treść z innym `cartId`); powtórzenie po scaleniu i wygaśnięciu koszyka
  gościa daje 200.

### Architektura

Deptrac z regułami Ordering. Zakazane (brak reguły): `*_* → Ordering_*` (każdy inny context), `Ordering_Domain` →
inne contexty i `Vendor`, `Ordering_Application` → inne contexty, `Ordering_Infrastructure` → `*_Domain`
i `*_Infrastructure` innych contextów, `Ordering_UI` → `Customer_Infrastructure`, `Cart_*` → `Ordering_*`.

### Vitest (`apps/store`)

- BFF `app/api/checkout/route.test.ts`: gość, sesja (ze scaleniem `store_cart`), brak `store_cart` (409 z `type`
  `CartEmpty`), 403 `Origin`
  i `Content-Type`, brak `Idempotency-Key`, przekazanie klucza, do API trafiają tylko pola kontraktu (dodatkowe pola
  przeglądarki, np. ceny, są pomijane), 201 i 200 ustawiają `store_order_confirmation`
  (z walutą), 404 gościa usuwa `store_cart`, 409 (przekazuje `type` każdej z pięciu przyczyn, `about:blank` i brak),
  422, 401, 503, `no-store`.
- `/auth/session` (`app/auth/session`): `{name, email}`.
- `CheckoutPage`: formularz gościa i klienta (e-mail z sesji), podsumowanie, blokada przycisku, każdy wiersz tabeli
  UI (pięć przyczyn 409 i nieznany 409: komunikat, link do koszyka, formularz, ten sam albo nowy klucz przy kolejnym
  kliknięciu, brak automatycznego drugiego POST), ponowienie z tym samym kluczem po błędzie, nowy klucz po zmianie
  danych, fokus po odrzuceniu, pusty koszyk, `hasExcludedLines`.
- `/checkout/confirmation` i `PaymentConfirmation`: z ciasteczkiem, bez, z niepoprawnym podpisem, każdy stan płatności
  (`docs/features/payment/spec.md`).
- `CartPage`: przycisk „Przejdź do kasy” aktywny i nieaktywny.
- `src/api/client.test.ts`: `placeOrder`, `startPayment`, `getPaymentStatus`.

### E2E (Playwright)

- `checkout-guest.spec.ts` (ścieżka nr 1): gość wybiera wariant na kafelku, dodaje 2 szt., na `/cart` „Przejdź do
  kasy”, wypełnia formularz, wybiera „Szybki przelew (demo)”, składa zamówienie i płaci w symulatorze; strona
  potwierdzenia pokazuje numer `R-…`, „Płatność przyjęta” i kwotę równą sumie z koszyka; licznik koszyka 0; w Mailpit
  e-mail z potwierdzeniem i e-mail o płatności na podany adres z tym numerem.
- `checkout-customer.spec.ts`: Jan loguje się, opróżnia koszyk, dodaje wariant, składa zamówienie (e-mail z konta,
  bez pola e-mail), odrzuca płatność „Kartą płatniczą (demo)”, wybiera ponownie i płaci; w Mailpit e-maile na adres
  Jana z numerem tego zamówienia (wyszukiwanie po adresie i temacie z numerem, bo Jan dostaje e-maile przy każdym
  uruchomieniu).
- `payment-resume.spec.ts`: powrót ze strony symulatora na `/checkout`, link „Opłać zamówienie R-…”, „Wróć do
  płatności” otwiera tę samą sesję, płatność się udaje.
- E2E składają zamówienia na wariantach z dużym stanem i je opłacają; rezerwacje opłaconych zamówień zostają do
  wysyłki (ORDER-MANAGEMENT), z komentarzem w `fixtures/stock.yaml`.

## Ryzyka i ograniczenia

- Nieopłacone zamówienie trzyma rezerwację do 35 minut (termin płatności i 5 minut na webhooki), a złośliwy gość może
  w tym czasie zarezerwować cały stan (brak rate limitingu, decyzja T2).
- Składanie zamówień nie ma rate limitingu (T2) wbrew zasadzie z `apps/api/CLAUDE.md` i `docs/vision.md` do kroku 10
  planu `docs/features/agentic-software-house/plan.md`.
- Jedna transakcja obejmuje koszyk, rezerwację i zamówienie: popularny wariant szereguje złożenia na wierszu stanu,
  a transakcja jest dłuższa niż sama rezerwacja. Wydajność przy docelowej skali nie jest potwierdzona testami
  obciążeniowymi.
- Numeracja ma luki (wycofane złożenia) i ujawnia przybliżoną liczbę zamówień.
- Drugi e-mail jest możliwy przy awarii między wysyłką a zapisem znacznika oraz przy równoległym dostarczeniu tego
  samego zdarzenia do dwóch workerów (outbox daje „co najmniej raz”).
- Dane osobowe gościa (e-mail, telefon, adres) są w zamówieniu bez anonimizacji (RODO później).
- Gość nie czyta zamówienia, tylko status jego płatności, i tylko dopóki ma ciasteczko potwierdzenia (1 godzina,
  P7); szczegóły ma w e-mailu. `accessToken` z odpowiedzi złożenia jest w ciasteczku `HttpOnly` i nie trafia do skryptów
  strony (`docs/features/payment/spec.md`, decyzja A5).
- `make seed` usuwa zamówienia razem z rezerwacjami (dane demo); wiersze outboxa usuniętych zamówień trafiają do
  `failed`.
- `cartId` gościa jest w treści żądania (nie w ścieżce), więc nie trafia do logów dostępu API.
