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
- Złożenie zamówienia w jednej transakcji: odczyt i blokada koszyka, kopia wyceny z Pricing (ceny skuteczne, promocje,
  rabat kodu rabatowego rozłożony na linie) i nazw z Catalog w pozycjach zamówienia, zapis użycia kodu, rezerwacja stanów w Inventory (bez sprzedaży ponad stan przy równoległych zakupach), zapis zamówienia
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
- paczkomaty, punkty odbioru i integracja z przewoźnikiem (etap 2 dostawy, `docs/features/delivery/spec.md`), adresy
  zapisane na koncie klienta (decyzja P5, #117), faktury i dane firmy, kraje inne niż Polska;
- powiązanie zamówienia gościa z kontem o tym samym adresie e-mail (#118), anonimizacja danych zamówień (RODO, #119).

## Decyzje

Decyzje użytkownika:

| # | Decyzja |
|---|---|
| P1 | Zamówienie obejmuje cały koszyk. Koszyk z pozycją, której nie da się kupić (`hasExcludedLines`), blokuje złożenie: klient najpierw usuwa pozycję albo zmniejsza ilość. |
| P2 | Żądanie złożenia niesie oczekiwaną kwotę brutto z podsumowania, które widział klient. Inna kwota w chwili złożenia: 409, bez zamówienia; sklep pokazuje nowe podsumowanie. |
| P2a | Żądanie złożenia niesie też linie podsumowania, które widział klient (`expectedLines`: wariant i ilość każdej linii; `docs/features/cart-order-consistency/spec.md`, D1–D3, D8). Koszyk z innymi pozycjami albo ilościami w chwili złożenia: 409 `cart-changed`, także przy tej samej kwocie, bez zamówienia; sklep pokazuje nowe podsumowanie. Zamówienie powstaje wyłącznie z koszyka. |
| P3 | Klient wybiera w kasie metodę dostawy (kurier), a zamówienie ma jej kopię i koszt: suma zamówienia = suma pozycji po rabacie + koszt dostawy (`docs/features/delivery/spec.md`, W5, W6). Zamówienia sprzed dostawy nie mają metody i kosztują tyle, co ich pozycje. |
| P4 | Dane: e-mail, imię i nazwisko, telefon (wymagany), adres w Polsce: ulica i numer, kod pocztowy `NN-NNN`, miejscowość. |
| P5 | Klient zalogowany: e-mail z konta (bez edycji), imię i nazwisko wstępnie z konta, adres wpisywany przy każdym zamówieniu (bez zapisywania na koncie). |
| P6 | Numer zamówienia: `R-` i kolejny numer z sekwencji od 100001 (np. `R-100001`). |
| P7 | Strona potwierdzenia: numer zamówienia, kwota brutto i status płatności; adres e-mail po opłaceniu; szczegóły pozycji w e-mailu. Gość nie czyta zamówienia, tylko status jego płatności: tokenem dostępu (`accessToken`) z ciasteczka potwierdzenia (`docs/features/payment/spec.md`, decyzja A5), klient sesją. |
| P8 | E-mail: tekstowy, po polsku, z numerem, pozycjami (nazwa, wariant, ilość, wartość), wierszem „Dostawa (<nazwa metody>): X zł” (także 0,00 zł), sumą i adresem dostawy. |
| P9 | Przycisk złożenia: „Zamawiam z obowiązkiem zapłaty”. Bez akceptacji regulaminu (sklep nie ma regulaminu). |
| P10 | Gość na stronie kasy widzi link „Zaloguj się” (powrót do kasy, scalenie koszyka jak dziś). Zamówienie gościa nie jest wiązane z kontem o tym samym e-mailu. |
| A1 | Nowy bounded context Ordering z agregatem `Order` (pozycje i adres jako kopie, nie referencje), zapis przez rekordy Doctrine według ogólnej zasady. |
| A2 | Ordering prowadzi złożenie i zależy od Cart (`Ordering_Infrastructure → Cart_Application`, dwie komendy Cart). |
| A3 | Koszyk, rezerwacja i zamówienie w jednej transakcji bazy (zagnieżdżony dispatch, kontrakt z `docs/features/inventory/spec.md`), bez sagi i zdarzeń między nimi. |
| A4 | E-mail przez zdarzenie `OrderPlaced` w outboxie → RabbitMQ → komenda Ordering, Symfony Mailer (nowa zależność `symfony/mailer`) przez SMTP do Mailpit. |
| T1 | Nowa zależność `symfony/mailer`. |
| T2 | Złożenie zamówienia ma limit liczby prób na adres gościa albo konto klienta (`docs/features/hardening/spec.md`, „Rate limiting”): przekroczenie to 429 przed kontrolerem, a kasa pokazuje komunikat, zachowuje formularz i `Idempotency-Key`. |

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Ordering (nowy) | złożenie zamówienia, agregat `Order`, idempotencja, numer, e-mail z potwierdzeniem |
| Cart | blokada i odczyt linii koszyka do zamówienia, opróżnienie koszyka po złożeniu |
| Pricing | `QuoteBasket(lines, discountCode)`: autorytatywna wycena (cena skuteczna: netto, VAT, brutto, stawka, klasa; w aktywnej promocji cena promocyjna z kopią promocji; rabat kodu rozłożony na linie, netto i VAT linii z brutto po rabacie); `RedeemDiscountCode` zapisuje użycie kodu |
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
  dają 422 zamiast zamówienia bez e-maila; domena sprawdza długość, obecność `@` i brak znaków niedozwolonych).
- Znaki sterujące `\x00-\x1F` i `\x7F`, U+0085, U+2028 i U+2029 (separatory linii i akapitu) oraz tekst, który nie
  jest poprawnym UTF-8, są odrzucane w `email`, `fullName`, `phone`, `street`, `postalCode` i `city`, sprawdzane w
  wartości w takiej postaci, w jakiej przyszła (`trim` usuwa NUL, tabulator i końce linii z brzegów, więc sprawdzenie po
  przycięciu wpuściłoby je i zmieniło wartość po cichu). Wzorzec (z flagą `u`) jest w `ControlCharacters` (Ordering
  Domain); DTO używa go w `Assert\Regex(match: false)` (niepoprawny UTF-8 też daje naruszenie, a `Length` przed nim
  ma ten sam komunikat w `charsetMessage`), VO rzucają `InvalidOrderInput` (`ControlCharacters::in()` uznaje
  niepoprawny UTF-8 za znak niedozwolony). Przycinanie tych pól zostaje `trim()` (tylko ASCII); jedynie notatka
  pracownika przycina białe znaki Unicode (spec zarządzania zamówieniami, `StaffNote`).
- `Contact {email, fullName, phone}`: `fullName` 1–100 znaków po przycięciu, bez znaków niedozwolonych; `phone`: 9 cyfr, opcjonalnie poprzedzone
  `+48`; spacje i myślniki są usuwane, zapis w postaci `+48NNNNNNNNN`.
- `ShippingAddress {street, postalCode, city, country}`: `street` 1–100, `city` 1–60 znaków po przycięciu, oba bez znaków niedozwolonych,
  `postalCode` `NN-NNN`, `country` zawsze `PL`.
- `Money`: nieujemne grosze (`int`) i `Currency` (tylko `PLN`).
- `PriceCopy {net, vat, gross, vatRateBp, taxClass}`: cena jednostkowa skopiowana z wyceny Pricing; `net + vat = gross`.
  To cena skuteczna z chwili złożenia: w aktywnej promocji cena promocyjna (netto i VAT ze stawki ceny katalogowej,
  `docs/features/pricing/spec.md`, „Cena skuteczna i cena Omnibus”).
- `PromotionCopy {promotionId, name, percentOff, regularUnitGross}`: kopia promocji, która dała cenę jednostkową linii
  (nazwa 1–100 znaków, cena katalogowa ≥ 1 gr), albo brak.
- `LineAmounts {discount, net, vat, gross}`: wartości linii skopiowane z wyceny (`QuoteBasket`, decyzje A2 i D8
  `docs/features/discount-codes/spec.md`): udział linii w rabacie kodu, netto i VAT liczone przez Pricing z brutto linii
  po rabacie (`PriceBreakdown::ofLineGross`, także bez kodu), `net + vat = gross`.
- `OrderLine {variantId, productId, productName, variantLabel, quantity, unitPrice: PriceCopy, amounts: LineAmounts,
  promotion: PromotionCopy | null}`; `quantity` 1–99; `grossBeforeDiscount = unit.gross × quantity`; niezmiennik
  `lineGross = grossBeforeDiscount − discount` (inaczej `InvalidOrder`). `lineNet`, `lineVat`, `lineGross` to wartości
  z wyceny, nie mnożenie ceny jednostkowej.
- `OrderDiscount {code, kind (percent | amount), value, amount}`: kopia kodu rabatowego, którym złożono zamówienie
  (tekst 1–32 znaki, wartość ≥ 1, kwota ≥ 1 gr), albo brak.
- `ExpectedLines`: linie podsumowania z żądania (P2a): 1–50 wariantów (`Order::MAX_LINES`), każdy raz (porównanie bez
  wielkości liter, zapis małymi literami), po 1–99 sztuk (`OrderLine::MAX_QUANTITY`); inaczej `InvalidOrderInput`
  „Nieprawidłowa lista pozycji zamówienia. Odśwież stronę.”. `matches(linie koszyka)` porównuje zbiory par wariant
  i ilość (kolejność bez znaczenia; koszyk z powtórzonym wariantem nigdy się nie zgadza), `canonical()` to
  `<variantId>*<ilość>` posortowane po wariancie i złączone przecinkami (do odcisku).
- `DiscountCodeText`: kod z żądania przed wyceną i odciskiem: poprawny UTF-8, przycięty z białych znaków Unicode
  i niewidocznych znaków formatu, litery ASCII wielkie; pusty to brak kodu; ponad 64 znaki (przed przycięciem):
  `InvalidOrderInput` „Kod rabatowy może mieć najwyżej 64 znaki.”.
- `OrderTotals {net, vat, gross}`: sumy wartości linii (po rabacie); `net + vat = gross` co do grosza (suma linii,
  w których to zachodzi). Jedyne miejsce obliczeń sum.

### Agregat `Order`

Pola: `id`, `number`, `owner`, `accessToken` (gość: 32 losowe bajty w base64url, 43 znaki, generuje handler; klient:
null), `contact`, `shippingAddress`, `lines` (1–50, w kolejności koszyka, bez powtórzonego wariantu), `discount`
(`OrderDiscount` albo null; suma `discount` linii równa `discount.amount`, bez kodu 0), `totals` (liczone), `status` (`new`, `paid`, `shipped`, `delivered`, `cancelled`, `returned`; po złożeniu `new`; cykl po
opłaceniu: `docs/features/order-management/spec.md`), `idempotencyKey`, `requestFingerprint`,
`placedAt`, `paymentDeadline` (`placedAt` + 30 min), `version`. Pola cyklu płatności (`paidAt`, `paymentId`,
`cancelledAt`, `cancellationReason`) opisuje `docs/features/payment/spec.md`. Znaczniki wysłanych e-maili nie są
w zamówieniu: są w tabeli powiadomień (`docs/features/order-management/spec.md`, „Znaczniki wysyłki poza agregatem”).

| Metoda | Działanie | Błędy |
|---|---|---|
| `place(id, number, owner, accessToken, contact, address, lines, discount, idempotencyKey, fingerprint, now)` | nowe zamówienie w statusie `new` z terminem płatności `now` + 30 min, zapisuje zdarzenie `OrderPlaced {orderId, occurredAt}` | 0 albo > 50 linii, powtórzony wariant, zamówienie gościa bez tokenu albo klienta z tokenem, suma rabatów linii różna od kwoty rabatu: `InvalidOrder` (błąd logiczny: handler buduje linie z koszyka, który pilnuje tych limitów); ilość linii poza 1–99 odrzuca już `OrderLine::of` (też `InvalidOrder`) |
| `isReplayOf(fingerprint)` | to samo żądanie (ten sam koszyk i ta sama treść) powtórzone z tym samym kluczem | |

- `reconstitute()` odtwarza zamówienie bez zdarzeń, z zapisanymi wartościami linii (niczego nie przelicza).
- `version` (optimistic locking): zapis podnosi ją przy każdej zmianie (opłacenie, anulowanie; wysłanie e-maila nie
  zmienia zamówienia;
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
| `CartContentChanged` | `Conflict` (409) | „Zawartość koszyka się zmieniła. Sprawdź podsumowanie.” | `…/cart-changed` |
| `GuestOrderTooLarge` | `Conflict` (409) | „Zamówienie bez konta może mieć najwyżej 10 różnych produktów, po 10 sztuk każdego. Zmniejsz ilości w koszyku.” | `…/guest-order-too-large` |
| `TooManyUnpaidOrders` | `Conflict` (409) | „Nie możemy teraz przyjąć kolejnego zamówienia, bo z tych danych albo z tej sieci czeka już kilka nieopłaconych. Spróbuj ponownie za ok. 35 minut.” (ten sam dla limitu adresu, e-maila i konta) | `…/too-many-unpaid-orders` |
| `CartNotOrderable` | `Conflict` (409) | „Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.” | `…/cart-not-orderable` |
| `InsufficientStockForOrder` | `Conflict` (409) | „Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.” | `…/insufficient-stock-for-order` |
| `OrderTotalChanged` | `Conflict` (409) | „Kwota zamówienia się zmieniła. Sprawdź podsumowanie.” | `…/order-total-changed` |
| `IdempotencyKeyReused` | `Conflict` (409) | „Ten klucz żądania był już użyty dla innego zamówienia.” | `…/idempotency-key-reused` |
| `CartNotFound` (z Cart) | `NotFound` (404) | „Koszyk nie jest już dostępny.” (bez zmian) | `about:blank` |

Wyjątki Ordering z tabeli (bez `CartNotFound`) implementują `App\Shared\Domain\Exception\TypedProblem` (`problemType(): string`) i trzymają
swój `type` w stałej `TYPE`; `ProblemDetailsSubscriber` wpisuje go do odpowiedzi. Wyjątki bez tego interfejsu i każda
inna odpowiedź błędu mają `type: about:blank`. Klient rozróżnia przyczyny 409 wyłącznie po `type`; `detail` jest
tekstem dla człowieka i może się zmienić. `type` to identyfikator (URI `tag:`), nie adres, pod którym coś jest
serwowane.

## Złożenie zamówienia (`PlaceOrder`)

Komenda `PlaceOrder(cartRef, contact, address, deliveryMethodId, expectedTotalGross, expectedLines, idempotencyKey, discountCode | null, clientAddress)` na
`command.bus` (kod normalizuje `DiscountCodeText`; pusty albo biały to brak kodu; `expectedLines` to lista
`{variantId, quantity}`, z której handler buduje `ExpectedLines` przed pierwszym portem), gdzie `cartRef`
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
   `customer:<sub>`), danych kontaktowych, adresu i `expectedTotalGross` (po przycięciu i normalizacji telefonu),
   znormalizowanego kodu rabatowego, dopisanego tylko wtedy, gdy jest, `delivery:<deliveryMethodId>` (UUID małymi
   literami) po nim i `lines:<ExpectedLines::canonical()>` na końcu; bez kodu składnik kodu jest pominięty. Ten sam
   klucz z inną metodą dostawy albo inną listą linii (inne ilości, inne warianty; ta sama lista w innej kolejności
   albo wielkości liter to ta sama lista) to inne żądanie (`IdempotencyKeyReused`).
   Koszyk jest częścią odcisku, więc dwa koszyki gości z tym samym kluczem i tymi samymi danymi dostają 409, a nie
   cudze zamówienie.
3. Brak linii: `CartEmpty` (409), także gdy `expectedLines` wymienia pozycje.
3a. **Zawartość koszyka** (P2a). Linie zablokowanego koszyka (wariant i ilość) inne niż `expectedLines` (inna ilość,
   brak linii, linia dodatkowa, inny wariant): `CartContentChanged` (409 `cart-changed`), zaraz po `CartEmpty`, przed
   limitem zamówienia gościa, limitem nieopłaconych zamówień, wyceną, kodem, numerem i rezerwacją. Porównanie działa
   pod blokadą koszyka z kroku 1, więc żadna zmiana koszyka nie wchodzi między porównanie a opróżnienie koszyka.
   Odrzucenie niczego nie zapisuje (koszyk bez zmian, bez numeru, rezerwacji, użycia kodu i roszczenia limitu).
   `expectedLines` nie trafia do zamówienia.
3b. Zamówienie gościa większe niż 10 linii albo 10 sztuk jednej linii: `GuestOrderTooLarge` (409, reguła domeny `GuestOrderSize`), zaraz po porównaniu zawartości, przed wyceną, kodem, numerem i rezerwacją, więc przed `CartNotOrderable`, `DiscountCodeRejected`, `OrderTotalChanged` i niedoborem. Zamówienie klienta nie podlega tej regule (do 50 linii po 99 sztuk, jak koszyk).
3c. **Limit nieopłaconych zamówień** (`docs/features/abuse-limits/spec.md`, L6, D6–D8; `UnpaidOrderLimit`). Blokady
   doradcze transakcji na adres klienta (`ClientIp`, z kontrolera w komendzie, poza odciskiem żądania), potem na
   tożsamość (`email:<klucz>` gościa albo `customer:<sub>`), i pod nimi liczenie roszczeń z `expires_at` po teraz:
   co najmniej 5 z adresu albo 3 tej tożsamości (`config/packages/ordering.yaml`): `TooManyUnpaidOrders` (409), przed
   wyceną, kodem, numerem i rezerwacją. Powtórzenie (kroki 0 i 2) wraca przed limitem.
4. **Kopie danych.** Porty `OrderPrices::quote(lines, discountCode)` (Pricing `QuoteBasket`: ceny skuteczne bez ceny
   Omnibus, promocje, rabat kodu rozłożony na linie, netto i VAT linii z brutto po rabacie; tylko w PLN)
   i `OrderCatalog::variants(variantIds)` (Catalog `GetVariantSummaries`), po jednym wywołaniu. Wariant bez aktualnej
   ceny albo nieznany Catalogowi: `CartNotOrderable` (409, decyzja P1). Kod odrzucony w wycenie:
   `DiscountCodeRejected` (409 z typem i `detail` odrzucenia Pricing), przed porównaniem kwoty.
4a. **Dostawa** (`docs/features/delivery/spec.md`, W5; odczyty bez blokad). Port `OrderDeliveryQuotes` (Delivery
   `QuoteDelivery` dla wartości towarów po rabacie) wybiera metodę `deliveryMethodId`; metoda nieznana albo nieaktywna:
   `DeliveryMethodUnavailable` (409 `delivery-method-unavailable`), po odrzuceniu kodu. Koszt metody dzieli na stawki
   VAT linii port `OrderPrices` (Pricing `SplitDeliveryCost`; darmowa dostawa nie ma udziałów).
5. **Kwota.** Linie z wartościami wyceny, dostawa i sumy w domenie (`OrderLine`, `OrderDelivery`, `OrderTotals`).
   `totals.gross` (po rabacie, z dostawą) `≠ expectedTotalGross`: `OrderTotalChanged` (409, decyzja P2), przed
   rezerwacją.
5a. **Użycie kodu** (gdy wycena zastosowała kod). `orderId` (UUID v7) jest nadawany przed tym krokiem. Port
   `DiscountCodeRedemptions::redeem(codeId, orderId, subtotalGross)` (Pricing `RedeemDiscountCode`, zagnieżdżony
   dispatch): kod czytany od nowa, kod z limitem pod blokadą wiersza, reguły kodu jeszcze raz; odrzucenie adapter
   tłumaczy na `DiscountCodeRejected` Ordering (409), cała transakcja wycofana (bez numeru z sekwencji).
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
9. **Zapis.** `Order::place(…)`, `OrderRepository::add(order)`, roszczenie nieopłaconego zamówienia (adres, tożsamość,
   `expires_at` = wygaszenie zamówienia) i zdarzenie `OrderPlaced` w outboxie (`DomainEventPublisher`), w tej samej
   transakcji. Zapis zamówienia jest ostatnim krokiem, więc `flush` komend
   zagnieżdżonych nie zapisuje niczego z Ordering.

Zasady transakcji (zapisywane w `apps/api/CLAUDE.md`):
- Handler nigdy nie łapie wyjątku z zagnieżdżonej komendy, żeby pisać dalej: każdy błąd wycofuje całą transakcję
  (koszyk, użycie kodu, rezerwację, zamówienie, outbox). Wyjątki Inventory (niedobór) i Pricing (odrzucenie kodu) są
  tłumaczone na wyjątki Ordering i rzucane dalej.
- Rollback jest atomowy: niedobór choćby jednej pozycji z wielu (albo błąd dowolnego późniejszego zapisu) zostawia
  bazę jak przed żądaniem. Koszyk ma te same linie, `reserved` żadnego wariantu się nie zmienia (Inventory
  rezerwuje „wszystko albo nic”, a wycofanie transakcji cofa każdy zapis), nie ma zamówienia, linii ani wiersza
  outboxa. Jedyny ślad to luka w numeracji.
- Kolejność blokad: wiersz koszyka, blokada doradcza adresu, blokada doradcza tożsamości (limit nieopłaconych
  zamówień), potem wiersz kodu rabatowego (tylko kod z limitem, `RedeemDiscountCode`), potem
  wiersze stanów (rosnąco po `variant_id`, `lockMany` Inventory). Blokady adresu i tożsamości bierze tylko złożenie. Inne komendy Cart blokują tylko koszyki (scalenie:
  gość, potem klient), Inventory tylko stany i rezerwacje, a zwolnienie użycia przy wygaśnięciu nie blokuje kodu, więc
  nie ma cyklu blokad. Złożenie klienta blokuje tylko koszyk klienta. Dwa złożenia z ostatnim wolnym użyciem kodu
  z limitem szereguje blokada kodu: drugie dostaje 409 `discount-code-used-up`. Kod bez limitu nie jest blokowany;
  jego wyłączenie w trakcie złożenia opisuje `docs/features/discount-codes/spec.md` („Współbieżność”).
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
- Żądanie nie niesie cen. Pozycje i ilości niesie tylko do porównania (`expectedLines`, krok 3a): linie i ilości
  zamówienia pochodzą wyłącznie z zablokowanego koszyka, ceny, rabat i wartości linii z wyceny Pricing w chwili
  złożenia (krok 4), koszt dostawy z wyceny Delivery i podziału Pricing (krok 4a), a sumy liczy domena (`OrderTotals`).
- `expectedTotalGross` służy wyłącznie do porównania (P2) i nie trafia do zamówienia. Kwota niższa albo wyższa od
  sumy liczonej przez serwer daje 409 bez zamówienia. Tak samo `expectedLines` (P2a): inne linie dają 409 bez
  zamówienia, także przy tej samej kwocie.
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
  `version int` oraz kolumny cyklu płatności (`docs/features/payment/spec.md`,
  „Persystencja”); `CHECK ((owner_type = 'customer') = (customer_id IS NOT NULL))`,
  `CHECK ((owner_type = 'guest') = (access_token IS NOT NULL))`, `CHECK (total_net + total_vat = total_gross)`.
- `ordering_order_line`: `id uuid` (v7, PK), `order_id uuid` (FK → `ordering_order`, bez `ON DELETE CASCADE`:
  zamówienia są przechowywane na zawsze), `position int`, `variant_id uuid`, `product_id uuid`,
  `product_name varchar(255)`, `variant_label varchar(255)`, `quantity int` (`CHECK 1–99`), `unit_net`, `unit_vat`,
  `unit_gross` (`int`), `vat_rate_bp int`, `tax_class varchar(50)`, `line_net`, `line_vat`, `line_gross` (`bigint`,
  po rabacie), `discount_gross bigint NOT NULL DEFAULT 0` (`CHECK ≥ 0`), kopia promocji `promotion_id uuid`,
  `promotion_name varchar(100)`, `promotion_percent_off smallint`, `regular_unit_gross int` (wszystkie `NULL`,
  `CHECK`: razem albo żadne); unikalne `(order_id, position)` i `(order_id, variant_id)`.
- Kopia kodu w `ordering_order`: `discount_code varchar(32)`, `discount_kind varchar(8)` (`percent`, `amount`),
  `discount_value int`, `discount_gross bigint` (wszystkie `NULL`; `CHECK`: wszystkie cztery albo żadna, wartość
  i kwota ≥ 1). Zamówienia sprzed kodów rabatowych zachowują zapisane kwoty: `discount_gross` linii 0, promocja i kod
  `null` (migracja `Version…AddOrderDiscounts` niczego nie przelicza).
- Sekwencja `ordering_order_number_seq` `START 100001`.
- Bez kluczy obcych do tabel Cart, Catalog, Pricing i Inventory.
- Rekordy Doctrine z atrybutami w `Ordering/Infrastructure/Persistence/Doctrine/Record`, mapowanie agregat ↔ rekord
  w `DoctrineOrderRepository` (`add`, `save`, `get`, `findByIdempotencyKey`), `#[Version]` na rekordzie zamówienia.
  Długości kolumn z wartościami z innych contextów (`product_name`, `variant_label`, `tax_class`) zgadzają się
  z kolumnami źródłowymi.
- `ordering_order_notification`: znaczniki e-maili zamówienia (`docs/features/order-management/spec.md`).
- Migracje: `Version…CreateOrderingTables`, `Version…AddOrderPaymentCycle`, `Version…CreateOrderNotifications`,
  `Version…MoveOrderEmailMarkers`, `Version…AddOrderDiscounts`.

## E-mail z potwierdzeniem

- Zdarzenie `OrderPlaced {orderId, occurredAt}` (outbox → RabbitMQ, jak `ProductCreated`). Handler zdarzenia
  `SendOrderConfirmation` (`event.bus`, transport `events`, jak `IndexProduct`) robi całą pracę sam, bez komendy
  i bez transakcji wokół SMTP:
  - odczyt zamówienia; nieznane zamówienie: `UnrecoverableMessageHandlingException` (jak `IndexProduct`), bez
    zajęcia wysyłki;
  - zajęcie wysyłki rodzaju `confirmation` w tabeli powiadomień (port `OrderNotifications`, lease 5 min): e-mail już
    wysłany: nic (odbiorca idempotentny); wysyłkę trzyma inny handler: ponowienie sekundę po końcu jego lease;
  - inaczej port `OrderConfirmationMailer::send(OrderView)`, potem znacznik wysyłki w tabeli powiadomień. Zamówienie
    i jego wersja się nie zmieniają. Szczegóły: `docs/features/order-management/spec.md`, „Znaczniki wysyłki poza
    agregatem”.
- Outbox: wiersz `OrderPlaced` powstaje w transakcji złożenia, więc wycofane złożenie nie zostawia zdarzenia ani
  e-maila. Relay outbox → RabbitMQ i dostawa do workera działają „co najmniej raz”: to samo zdarzenie może przyjść
  ponownie (ponowienie relaya po braku potwierdzenia, `redeliver_timeout`, ponowienie po błędzie handlera).
  Odbiorca jest idempotentny dzięki znacznikowi wysyłki, a równoległe dostarczenie wysyła jeden e-mail dzięki
  zajęciu wysyłki.
- Wysyłka i zapis znacznika nie są atomowe: udana wysyłka bez zapisanego znacznika (awaria procesu albo bazy przed
  zapisem, błąd SMTP zgłoszony po przyjęciu wiadomości) daje drugi e-mail (świadome, w ryzykach; odwrotna kolejność
  groziłaby brakiem e-maila). Błąd SMTP: zwolnienie zajęcia, ponowienia
  według strategii transportu `events`, potem `failed`.
- Adapter `SymfonyOrderConfirmationMailer` (`Ordering_Infrastructure`, Symfony Mailer): wysyłka synchroniczna przez
  transport Mailera, bez Messengera (`framework.mailer.message_bus: false`; inaczej `MailerInterface::send()` wysłałby
  `SendEmailMessage` na domyślną szynę `command.bus`), więc błąd SMTP wraca do handlera. `MAILER_DSN`
  (`smtp://mailpit:1025?timeout=10` w `infra/docker-compose.yml` dla `api` i `worker`, w konfiguracji testów i CI;
  `timeout` to limit każdej operacji gniazda SMTP w sekundach, krótszy niż zajęcie wysyłki), nadawca
  z `MAILER_FROM` (`Worf <zamowienia@worf.localhost>`), treść tekstowa po polsku (P8), temat „Potwierdzenie zamówienia
  R-100001”. Treść składa jedna klasa w `Ordering_Application` (`OrderConfirmationText`), testowana jednostkowo.
- Treść: powitanie z imieniem i nazwiskiem, numer, data i godzina złożenia w strefie `Europe/Warsaw`, pozycje
  (nazwa, etykieta wariantu po przecinku; wariant domyślny z pustą etykietą: sama nazwa, bez separatora; ilość,
  wartość brutto linii przed rabatem kodu), przy kodzie linia „Kod <KOD>: −X zł” (kwota rabatu), suma brutto (po
  rabacie), adres dostawy, telefon, zdanie „Zamówienie czeka na płatność do 14:35
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
| `POST /api/v1/orders` (gość), nagłówek `Idempotency-Key`, body `{cartId, email, fullName, phone, street, postalCode, city, expectedTotalGross, deliveryMethodId, expectedLines: [{variantId, quantity}], discountCode?}` | 201 `OrderView` (z `delivery: {methodName, gross}`), nagłówek `Location` nieobecny (bez odczytu zamówienia); 200 powtórzenie z tym samym kluczem i treścią (bez drugiego użycia kodu) | 400 brak lub zły klucz, zły JSON; 401 niepoprawny token; 404 koszyk; 409 z `type` przyczyny (`CartEmpty`, `CartContentChanged` (`cart-changed`, odpowiedź tylko z `type` i `detail`, bez rozszerzeń), `GuestOrderTooLarge` tylko dla gościa, `CartNotOrderable`, `InsufficientStockForOrder`, `OrderTotalChanged`, `IdempotencyKeyReused`, `discount-code-invalid`, `discount-code-used-up`, `discount-code-below-minimum` z rozszerzeniem `minimumOrderGross`, `delivery-method-unavailable`; schemat OpenAPI `OrderConflictProblem` z `enum` wartości `type`); 415; 422; 503 (token wysłany, klucze wystawcy niedostępne) |
| `POST /api/v1/me/orders` (klient), nagłówek `Idempotency-Key`, body `{fullName, phone, street, postalCode, city, expectedTotalGross, deliveryMethodId, expectedLines: [{variantId, quantity}], discountCode?}` | jak wyżej; e-mail z tokena | 401 (bez tokena, token `backoffice`), 503 (klucze wystawcy niedostępne), pozostałe jak wyżej bez 404 |

`OrderView`:

```
{
  orderId: uuid,
  number: string,
  status: "new" | "paid" | "shipped" | "delivered" | "cancelled" | "returned" (po złożeniu "new"),
  email: string,
  fullName: string,
  phone: string,
  shippingAddress: { street, postalCode, city, country: "PL" },
  lines: [{ variantId, productName, variantLabel, quantity, unitGross: int, grossBeforeDiscount: int,
           discountGross: int, lineGross: int (po rabacie) }],
  discount: { code, kind: "percent" | "amount", value: int, amountGross: int } | null,
  totals: { net: int, vat: int, gross: int (po rabacie), currency: "PLN" },
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
| `email` | zły format (także taki, jakiego nie przyjmie Mailer), znak niedozwolony (sterujący, U+0085, U+2028, U+2029, niepoprawny UTF-8) albo > 254 znaki | Podaj poprawny adres e-mail. |
| `fullName` | brak, pusty po przycięciu | Imię i nazwisko są wymagane. |
| `fullName` | > 100 znaków | Imię i nazwisko mogą mieć najwyżej 100 znaków. |
| `fullName` | znak niedozwolony (jak wyżej) | Imię i nazwisko zawierają niedozwolone znaki. |
| `phone` | brak, pusty | Numer telefonu jest wymagany. |
| `phone` | inny format (także ze znakiem niedozwolonym) | Podaj 9-cyfrowy numer telefonu. |
| `street` | brak, pusty / > 100 / znak niedozwolony | Ulica i numer są wymagane. / Ulica i numer mogą mieć najwyżej 100 znaków. / Ulica i numer zawierają niedozwolone znaki. |
| `postalCode` | brak, pusty / zły format (także ze znakiem niedozwolonym) | Kod pocztowy jest wymagany. / Podaj kod pocztowy w formacie 00-000. |
| `city` | brak, pusty / > 60 / znak niedozwolony | Miejscowość jest wymagana. / Miejscowość może mieć najwyżej 60 znaków. / Miejscowość zawiera niedozwolone znaki. |
| `expectedTotalGross` | brak, `null` / nie liczba całkowita / ujemna | Kwota zamówienia jest wymagana. / Kwota zamówienia musi być liczbą całkowitą. / Kwota zamówienia nie może być ujemna. |
| `deliveryMethodId` | brak, `null` / nie tekst, pusty albo nie UUID (8-4-4-4-12 cyfr szesnastkowych, dowolna wielkość liter) | Kasa została zaktualizowana. Odśwież stronę i wybierz sposób dostawy. / Nieprawidłowy sposób dostawy. Odśwież stronę i wybierz go ponownie. |
| `discountCode` | nie tekst (liczba, tablica, `true`) / > 64 znaki | Kod rabatowy musi być tekstem. / Kod rabatowy może mieć najwyżej 64 znaki. |
| `expectedLines` | brak, `null` | Kasa została zaktualizowana. Odśwież stronę. |
| `expectedLines` | nie lista (tekst, liczba, `true`, obiekt), lista pusta, ponad 50 elementów, ten sam `variantId` dwa razy (także w innej wielkości liter) | Nieprawidłowa lista pozycji zamówienia. Odśwież stronę. |
| `expectedLines[n]` | element nie jest obiektem (liczba, tekst, `null`, lista) | Nieprawidłowa lista pozycji zamówienia. Odśwież stronę. |
| `expectedLines[n].variantId` | brak, nie tekst, nie UUID (8-4-4-4-12 cyfr szesnastkowych, dowolna wielkość liter; także ze spacją, końcem linii, NUL, U+2028, NBSP) | Nieprawidłowa lista pozycji zamówienia. Odśwież stronę. |
| `expectedLines[n].quantity` | brak, nie liczba całkowita (tekst, ułamek, także `2.0`, `true`), 0, ujemna, > 99 | Nieprawidłowa lista pozycji zamówienia. Odśwież stronę. |

- `discountCode` jest opcjonalny: brak klucza, `null`, `""` i same białe znaki to złożenie bez kodu.
- `expectedLines` daje najwyżej jedno naruszenie (ograniczenie `ExpectedLinesFormat`): pierwszy zły element, a w nim
  pierwsze złe pole. Kolejność elementów nie ma znaczenia. UUID spoza wersji (nil, max) jest poprawnym elementem: daje
  409 `cart-changed`, bo koszyk go nie ma. Pusty obiekt `{}` to element bez `variantId`.

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
- Body może nieść `discountCode` (zastosowany kod; `CUSTOMER_ORDER_FIELDS` w `src/checkout/api.ts` przepuszcza go jak
  pola formularza do API, które go waliduje i normalizuje: `docs/features/discount-codes/spec.md`).
- Body niesie `expectedLines` (linie pokazanego podsumowania, P2a); `CUSTOMER_ORDER_FIELDS` przepuszcza je do API bez
  zmian, także wartość, której API nie przyjmie (wtedy 422 API).
- Body niesie `buyer`: kupującego, którego pokazała kasa (`"guest"` przy polu e-mail albo znacznik konta z
  `/auth/session`; `docs/features/cart-order-consistency/spec.md`, Z4). Pole służy tylko do porównania: nie trafia do
  API, a konto zamówienia wynika wyłącznie z sesji. Brak pola (żądanie strony kasy otwartej przed wdrożeniem tego
  sprawdzenia): 422 „Kasa została zaktualizowana. Odśwież stronę.” bez `violations`, którego `detail` taka strona
  pokazuje nad formularzem; wartość niebędąca tekstem albo pusty tekst: 422 „Nieprawidłowe dane.”; w obu przypadkach
  bez API. Sprawdzenie (`checkBuyer` w `src/checkout/bff.ts`) jest przed listą dostawców,
  scaleniem koszyka i złożeniem, więc odrzucenie nie woła API:

  | Kasa pokazała | Sesja przy wysłaniu | Odpowiedź BFF |
  |---|---|---|
  | gość | brak ciasteczka sesji albo ciasteczko z obcym podpisem | zamówienie gościa |
  | gość albo konto A | sesja innego kupującego (klient przy kasie gościa, konto B przy kasie konta A) | 409 `checkout-buyer-changed`, bez scalenia, sesja i `store_cart` zostają |
  | konto A | sesja konta A (także po odświeżeniu tokenu i w nowej sesji tego konta) | zamówienie konta A |
  | konto A | brak ciasteczka sesji, obcy podpis, sesji nie ma w Redisie albo tokenu nie da się odświeżyć | 401, ciasteczko sesji usunięte (sesja także); nigdy zamówienie gościa, także z `store_cart` i polem `email` w żądaniu |
  | gość | sesji nie ma w Redisie albo tokenu nie da się odświeżyć | 401 jak wyżej |
  | dowolne | Redis albo Keycloak niedostępny przy odczycie sesji | 503, sesja zostaje, bez przejścia do zamówienia gościa |

  BFF najpierw odświeża token sesji (`freshAccessToken`, jak przed każdym wywołaniem API; może czytać Redis), potem
  czyta `sub` z tej samej sesji i porównuje znacznik w stałym czasie (`isBuyerOf` w `src/auth/buyer.ts`: skróty SHA-256
  obu tekstów i `timingSafeEqual`). Token i `sub` pochodzą z jednej sesji, a odświeżenie zachowuje `sub`, więc samo
  odświeżenie nie daje konfliktu. Każdy tekst inny niż znacznik konta sesji (znacznik innego konta, z innym kluczem,
  sam `sub`, inna wielkość liter, znaki sterujące, bardzo długi tekst w granicy 16 KiB treści) to inny kupujący. 409
  `checkout-buyer-changed` (własny typ BFF `CHECKOUT_BUYER_CHANGED_TYPE` poza mapą typów API w
  `src/checkout/problems.ts`) ma tylko `type` i `detail` „Od wczytania kasy zmienił się kupujący. Nie złożyliśmy
  zamówienia.”, bez adresu i znacznika drugiego konta.
- `GET /api/cart` (BFF koszyka) przyjmuje jeden parametr, `discountCode` (podgląd kodu na kasie), i przekazuje go do
  API; inne parametry pomija. 400 API na kod (za długi, nie tekst) trafia do przeglądarki jako 400 `{detail}` z tekstem
  API; w innych odczytach 400 API to 503.
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
- `/auth/session` zwraca `{name, email, buyer}` (`email` z `GET /api/v1/me`, `buyer`: znacznik kupującego,
  HMAC-SHA256 z `sub` kluczem wyprowadzonym z `STORE_SESSION_SECRET` przez HKDF-SHA256 (bez soli, info
  `checkout-buyer`, 32 bajty), w base64url, `buyerTag` w `src/auth/buyer.ts`, z `sub` tej samej sesji, której token
  odczytał `/api/v1/me`; klucz znaczników jest inny niż klucz podpisów ciasteczek, więc żaden znacznik nie jest
  podpisem żadnej wartości ciasteczka); typ `AccountSession`
  w `src/api/client.ts`. Ten sam `sub` daje ten sam znacznik w każdej sesji i po odświeżeniu tokenu. Odpowiedź nie
  zawiera `sub`, identyfikatora sesji ani tokenów; gość dostaje `null`.
- `src/api/client.ts`: `placeOrder(input, idempotencyKey)` (wynik `ok` z `number` i `redirectUrl`), `startPayment`
  i `getPaymentStatus` (jedyne miejsce `fetch`). Wynik 409 to `conflict` z `reason`
  wyznaczonym wyłącznie z `type` (`src/checkout/problems.ts`: mapa typów ze schematu OpenAPI `OrderConflictProblem`
  na przyczyny, kompilator wymaga wpisu dla każdego typu schematu; inny albo brakujący `type` to `unknown`; trzy typy
  kodu rabatowego to jedna przyczyna `discount_code_rejected`, `cart-changed` to `cart_changed`). `detail` służy tylko
  do wyświetlenia.
- `loadCart(discountCode?)` w tym samym pliku wysyła kod w zapytaniu `/api/cart?discountCode=…` (kodowanie, które nie
  rzuca na samotnym surogacie UTF-16: zamienia go na U+FFFD) i mapuje 400 na `invalid` z `detail`.

### UI

- `/cart`: przycisk „Przejdź do kasy” (link do `/checkout`), aktywny, gdy koszyk ma linie i nie ma
  `hasExcludedLines`; przy `hasExcludedLines` nieaktywny z tekstem „Usuń albo popraw oznaczone pozycje, żeby złożyć
  zamówienie.”.
- `/checkout` (`noindex, nofollow`, Client Component): podsumowanie z `/api/cart` (linie: nazwa, etykieta wariantu,
  ilość, wartość; wiersz „Dostawa” z kosztem wybranej metody albo „Darmowa”; „Do zapłaty” = `totalGross` wybranej
  opcji, bez wyboru zamiast obu kwot „Wybierz sposób dostawy”), formularz (P4, P5), grupa radiowa „Sposób dostawy”
  (`fieldset` z `legend`, opcje `cart.delivery.options`: nazwa, opis, koszt albo „Darmowa”, „Do darmowej dostawy
  brakuje Y zł”, nigdy razem z „Darmowa”; wymagany wybór; bez sekcji przy `delivery: null`; szczegóły:
  `docs/features/delivery/spec.md`, W10; stany: akapit „Dostawa w kasie” niżej), grupa radiowa „Sposób płatności” (dostawcy z API, wymagany wybór, bez domyślnego), przycisk
  „Zamawiam z obowiązkiem zapłaty” (P9). Gość: pole
  e-mail i link „Zaloguj się” (`/auth/login?returnTo=/checkout`), który pokazuje się dopiero po odczycie
  przełączników wdrożenia (`useSiteSwitches`, `/api/site`) i tylko przy `customerAccounts: true`; błąd odczytu daje stan
  domyślny, czyli link, jak w nagłówku. Klient: e-mail z `/auth/session` jako tekst,
  imię i nazwisko wstępnie wypełnione z sesji (konto bez imienia ma w sesji e-mail jako nazwę: pole zostaje puste).
  Pusty koszyk albo `hasExcludedLines`: zamiast formularza komunikat i link do koszyka; przy pustym koszyku
  i poprawnym `store_order_confirmation` także link „Opłać zamówienie R-…” na `/checkout/confirmation` (powrót ze
  strony dostawcy bez decyzji, `docs/features/payment/spec.md`, P7).
- Teksty z danych w podsumowaniu, w wyborze dostawy i w komunikacie nad formularzem (nazwa produktu, etykieta
  wariantu, nazwa i opis metody dostawy, kod rabatowy, adres konta) łamią się także w środku słowa (`wrap-anywhere`),
  więc słowo dłuższe niż ekran nie powoduje przewijania poziomego przy 320 i 375 px.
- Status i ponowienie wczytania (`CheckoutPage.tsx`): jeden region `role="status"` (`data-testid="checkout-status"`)
  istnieje od pierwszego renderu przez wszystkie stany ekranu (wczytywanie, błąd, treść, pusty koszyk, wygasła sesja);
  zmienia się tylko jego tekst („Wczytywanie…” w trakcie odczytu, także odczytu nieaktualnego podsumowania po kliknięciu
  przycisku zamówienia, „Składamy zamówienie…” w trakcie składania zamówienia i odczytu koszyka po jego odrzuceniu za
  rozbieżność koszyka albo kwoty, poza tym pusty) i jest jedynym miejscem tego tekstu. Region podsumowania (`checkout-summary-status`) i kodu rabatowego (`checkout-code-status`) są osobne. Po nieudanym
  odczycie kasy (`/api/cart` albo `/auth/session`) komunikat „Nie udało się wczytać kasy” jest w `role="alert"`,
  a „Spróbuj ponownie” poza nim. Na czas ponowienia przycisk zostaje na ekranie z `aria-disabled="true"` (nie
  `disabled`, więc zachowuje fokus; ponowna aktywacja nie wysyła żądania), komunikat znika, a region mówi
  „Wczytywanie…”. Sukces: fokus na nagłówek „Kasa”; ponowny błąd: komunikat wraca, fokus na przycisku. Fokus
  przenosi się tylko wtedy, gdy w chwili odpowiedzi jest nadal na przycisku albo na `<body>`; przeniesiony w trakcie
  zostaje tam. Pierwszy odczyt kasy czeka na zakończenie akcji stron koszyka tej karty (`actionsFinished()`,
  `src/cart/cart-actions.ts`; zmiana zlecona w koszyku, gdy nawigacja do kasy była w drodze), z regionem „Wczytywanie…”
  do tego czasu; bez akcji albo po ich porzuceniu przy 401 czyta od razu. Ponowienie i odczyt po kodzie rabatowym nie
  czekają: w karcie z kasą nie ma już strony koszyka.
- Kod rabatowy (`docs/features/discount-codes/spec.md`): pod podsumowaniem pole „Kod rabatowy” (`maxLength` 64,
  etykieta) w osobnym formularzu, więc Enter zastosuje kod i nie złoży zamówienia, z przyciskiem „Zastosuj”. Zastosowanie
  wczytuje podsumowanie z kodem (`/api/cart?discountCode=…`; kod przycięty z białych znaków). Zastosowany kod: linia
  „Kod <KOD>: −X zł”, suma po rabacie (wartości linii przed rabatem, wszystko z API) i przycisk „Usuń kod” zamiast pola
  (usunięcie wczytuje podsumowanie bez kodu; gdy się nie uda, kod zostaje i jest komunikat). Odrzucenie: `detail`
  z podsumowania (`rejected`) albo 400 koszyka pokazane przy polu (`role="alert"`, `aria-invalid`,
  `aria-describedby`), podsumowanie bez rabatu, fokus w polu; pole puste albo kod pusty po normalizacji w API:
  „Wpisz kod rabatowy.”. Pole znika razem z formularzem (pusty koszyk, `hasExcludedLines`). Zastosowanie i usunięcie
  ogłasza obszar `role="status"`. W trakcie sprawdzania kodu „Zastosuj”, „Usuń kod” i przycisk zamówienia mają
  `aria-disabled="true"` zamiast `disabled` (zachowują fokus) i nie wysyłają żądania. Po odpowiedzi fokus idzie na pole
  kodu albo „Usuń kod” tylko wtedy, gdy jest nadal na elemencie, z którego wyszło sprawdzenie („Zastosuj”, pole kodu
  z Enterem, „Usuń kod”), albo na `<body>`; przeniesiony w trakcie zostaje tam. Złożenie wysyła `discountCode` zastosowanego kodu (bez kodu: bez klucza)
  i `expectedTotalGross` z podsumowania po rabacie. Kod nie przeżywa przeładowania strony (stan tylko w pamięci
  strony).
- Dostawa w kasie (stany; `CheckoutPage.tsx`):
  - Wstępnie zaznaczona jest metoda tylko wtedy, gdy przy wczytaniu kasy (wejście, „Spróbuj ponownie”) koszyk ma
    dokładnie jedną opcję; przy kilku nie ma wyboru domyślnego.
  - Wybór zostaje po każdym ponownym odczycie podsumowania (zastosowanie i usunięcie kodu, odrzucony kod, 409), jeśli
    metoda jest dalej wśród opcji; inaczej wybór znika (też wtedy, gdy jedyna pozostała opcja jest inna) i nie wraca,
    gdy metoda pojawi się później. Do czasu nowego wyboru „Dostawa” i „Do zapłaty” mówią „Wybierz sposób dostawy”.
  - Kod rabatowy nie obniża kosztu dostawy, ale może obniżyć wartość towarów poniżej progu: po odczycie z kodem opcje
    i podsumowanie od razu pokazują koszt, „Do darmowej dostawy brakuje Y zł” i „Do zapłaty” z nowej `totalGross`. Zmianę
    ogłasza obszar `role="status"` podsumowania (`data-testid="checkout-summary-status"`, obecny w stronie przed zmianą):
    „Dostawa „<nazwa>” nie jest już darmowa, kosztuje X zł. Do darmowej dostawy brakuje Y zł.”, „Dostawa „<nazwa>” jest
    teraz darmowa.” albo przy zmianie samej brakującej kwoty „Dostawa „<nazwa>”: Do darmowej dostawy brakuje Y zł.”.
    Mowa o wybranej metodzie, a bez wyboru o każdej, której dotyczy zmiana; przy zmianie, która niczego takiego nie
    zmienia, obszar jest pusty. Kwoty są z API. Wybór dostawy nie jest zablokowany w trakcie sprawdzania kodu: wybrana
    metoda to ta wybrana w chwili odpowiedzi odczytu (ta sama, którą pokazuje podsumowanie i niesie następne
    zamówienie), także dla listy zmian w stanie nieaktualnym.
  - Opcja z kosztem 0 pokazuje „Darmowa” i nie pokazuje „Do darmowej dostawy brakuje”, nawet gdy API poda brakującą kwotę.
  - Brak aktywnych metod (`options` puste) albo odczyt koszyka bez `delivery` (API sprzed dostawy): zamiast grupy
    `Alert` („Nie możemy teraz przyjąć zamówienia: brak dostępnych sposobów dostawy. Spróbuj później.”,
    `role="status"`), nieaktywny przycisk złożenia, bez wiersza „Dostawa”. `delivery: null` (nic do kupienia) to dzisiejsze
    zachowanie pustego koszyka.
  - 409 `delivery-method-unavailable`: komunikat API nad formularzem z fokusem, nowy `Idempotency-Key`, ponowny odczyt
    podsumowania z zastosowanym kodem, wybór usunięty, gdy metody nie ma wśród opcji.
- `Idempotency-Key`: UUID (`crypto.randomUUID`) tworzony przy wejściu na `/checkout` i używany ponownie przy
  ponowieniu niepewnego wyniku (błąd sieci, 403, 5xx, 503). Klucz należy do jednej logicznej próby: nowy powstaje
  po zmianie danych formularza, po zastosowaniu i usunięciu kodu rabatowego (żądanie niesie inny kod i kwotę), po 409
  `IdempotencyKeyReused`, po 409 `OrderTotalChanged` i `CartContentChanged` (żądanie niesie wtedy inną kwotę albo
  inne linie, które należą do odcisku żądania, a nic nie zostało złożone), po 409 `checkout-buyer-changed` (inny
  kupujący) i po 409 kodu rabatowego. Odrzucony podgląd kodu klucza nie zmienia (żądanie
  zostaje, jakie było). Po 409 `CartEmpty`, `CartNotOrderable`,
  `InsufficientStockForOrder` i po nieznanym 409 klucz zostaje: ponowienie tym samym kluczem po ewentualnym sukcesie
  da powtórzenie 200, nie drugie zamówienie. Strona niczego nie ponawia sama: każdy POST to kliknięcie klienta.
  Znany skutek reguły „zmiana danych = nowy klucz”: gdy wynik poprzedniej próby był niepewny (błąd sieci, 5xx, 503,
  403), a zamówienie jednak powstało, zmiana pola i ponowne kliknięcie dają 409 `CartEmpty` (pusty koszyk), a nie
  potwierdzenie; komunikat ma wtedy ostrzeżenie o wcześniejszej próbie (akapit „Niepewna próba” niżej). Drugiego
  zamówienia nie ma, bo koszyk jest pusty.
- `expectedTotalGross` = `totalGross` wybranej opcji dostawy z ostatnio wczytanego podsumowania, `deliveryMethodId` =
  jej `methodId`, `expectedLines` = `variantId` i `quantity` każdej linii tego samego podsumowania, w jego kolejności
  (także podsumowania wczytanego z kodem, bez kodu i po 409). Bez wybranej metody (także gdy wybranej nie ma już wśród opcji podsumowania) albo bez dostawcy
  płatności żądania nie ma: „Wybierz sposób dostawy.” i „Wybierz sposób płatności.” przy grupach, których dotyczą,
  jednym kliknięciem także oba naraz; każda z nich ma wtedy `aria-invalid` i `aria-describedby` na swój komunikat,
  a fokus idzie na pierwszą opcję pierwszej grupy z błędem (dostawa przed płatnością). Zmiana metody daje nowy klucz
  (inna treść żądania); po 409 `delivery-method-unavailable` komunikat jest nad formularzem, podsumowanie wczytuje się
  od nowa i powstaje nowy klucz.
- Składanie zamówienia: od wysłania `POST /api/checkout` do odpowiedzi z błędem (po rozbieżności koszyka albo kwoty: do
  końca odczytu koszyka) albo, po sukcesie, do opuszczenia strony formularz jest zablokowany i nic w nim się nie zmienia. Pola formularza i pole kodu są tylko do odczytu
  z `aria-disabled="true"` i wyglądem nieaktywnym; wybór dostawy i płatności ma `aria-disabled="true"` bez `disabled`
  i nie zmienia się po kliknięciu, dotyku, strzałkach ani Spacji; „Zastosuj”, „Usuń kod” i przycisk zamówienia mają
  `aria-disabled="true"` zamiast `disabled` i nie wysyłają żądania. Każda z tych kontrolek zachowuje fokus. Enter w polu,
  kliknięcie i Spacja na przycisku dają jedno żądanie; region statusu mówi „Składamy zamówienie…”. Po błędzie kontrolki
  są odblokowane, a wartości pól zostają; dostawa, płatność, kod i podsumowanie zmieniają się tylko tak, jak opisuje
  tabela niżej. Przycisk zamówienia przy braku sposobów dostawy jest `disabled` (stan trwały, nie żądanie w drodze).
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
| 409 `OrderTotalChanged`, `CartContentChanged` | nowy klucz i najpierw ponowne wczytanie podsumowania z tym samym kodem, potem w jednym renderze komunikat z listą zmian, nowe podsumowanie i odblokowany formularz z zachowanymi danymi (akapit „Rozbieżność koszyka i kwoty” niżej) |
| 409 `checkout-buyer-changed` (BFF) | nowy klucz i najpierw ponowne wczytanie sesji i podsumowania z tym samym kodem, potem w jednym renderze komunikat, kupujący i podsumowanie z odczytu oraz odblokowany formularz z zachowanymi danymi (akapit „Zmiana kupującego” niżej) |
| 409 `discount-code-invalid`, `discount-code-used-up`, `discount-code-below-minimum` (Q6) | `detail` przy polu kodu, kod zdjęty, podsumowanie wczytane bez kodu (nowa kwota), nowy klucz, nic nie składa się samo: zamówienie składa dopiero kolejne kliknięcie; gdy podsumowania nie da się wczytać, ekran zostaje taki jak był (kod zastosowany, „Usuń kod” z fokusem) z `detail` i komunikatem o odświeżeniu, a 401 pokazuje komunikat sesji |
| 409 `CartEmpty`, `GuestOrderTooLarge`, `CartNotOrderable`, `InsufficientStockForOrder` | `detail` i link „Wróć do koszyka”, ponowne wczytanie podsumowania, klucz zostaje; gdy przeładowane podsumowanie zastępuje formularz komunikatem z własnym linkiem, bez drugiego linku |
| 409 `IdempotencyKeyReused` | `detail`, nowy klucz, formularz zostaje, bez linku do koszyka (kolejne kliknięcie składa zamówienie z nowym kluczem) |
| 409 nieznany (inny `type` albo brak) | `detail` (bez niego „Nie udało się złożyć zamówienia. Spróbuj ponownie.”) i link „Wróć do koszyka”, klucz zostaje, bez automatycznego ponawiania |
| 404 koszyka | „Koszyk nie jest już dostępny.” i link do sklepu |
| 401 | „Twoja sesja wygasła. Zaloguj się ponownie.” z linkiem (`returnTo=/checkout`) |
| 403, 503, 5xx, błąd sieci, odpowiedź bez numeru zamówienia | „Nie wiemy, czy zamówienie zostało złożone. Spróbuj ponownie: tego samego zamówienia nie złożymy drugi raz.” (ponowienie z tym samym kluczem) |

Niepewna próba. Kasa pamięta do końca życia strony klucze prób, które mogły złożyć zamówienie, choć żadna odpowiedź
o nim nie powiedziała: wynik niepewny (wiersz 403, 503, 5xx, błąd sieci) i 409 `IdempotencyKeyReused` (zamówienie
z tym kluczem istnieje). Nowy klucz (zmiana formularza, dostawy, kodu) niczego o nich nie rozstrzyga i żadne
odrzucenie też nie: odrzucenie API, także na ten sam klucz, dowodzi tylko, że zamówienia z tym kluczem nie było
w chwili sprawdzenia, a niepewne żądanie mogło jeszcze dotrzeć do API i je złożyć; odrzucenia BFF przed API
i odpowiedzi na inny klucz nie mówią o nim nic. Wynik zna tylko sukces (także powtórzenie 200), po którym strona i tak
przechodzi dalej. Dopóki zostaje choć jeden taki klucz, każdy komunikat odrzucenia albo błędu zamówienia nad
formularzem (każdy wiersz tabeli, także komunikaty o nieudanym odczycie i wygasłej sesji) ma po swoim tekście akapit
„Wcześniejsza próba mogła złożyć zamówienie. Sprawdź pocztę, zanim zamówisz ponownie.”; w komunikatach rozbieżności
koszyka i kwoty oraz zmiany kupującego to zdanie zastępuje „Nie złożyliśmy zamówienia.”. Odrzucenie pokazane tylko
przy polach (422 z błędami pól, 409 kodu rabatowego przy polu kodu) ma wtedy nad formularzem komunikat z samym tym
zdaniem; fokus jest jak zawsze. Wyjątek: komunikat wyniku niepewnego nie powtarza ostrzeżenia, gdy jedyną niepewną
próbą jest ta, której dotyczy. Stan jest czytany w chwili pokazania komunikatu, a decyduje o nim jedno miejsce
(`setFailure` w `CheckoutPage.tsx`). Bez niepewnej próby teksty są takie, jak w tabeli.

Rozbieżność koszyka i kwoty (409 `cart-changed` i `order-total-changed`; `docs/features/cart-order-consistency/spec.md`,
„Z2 i Z3”):

1. Formularz zostaje zablokowany jak w trakcie składania zamówienia (pola, dostawa, płatność, kod, przycisk), a region
   statusu mówi dalej „Składamy zamówienie…”. Kasa czyta koszyk z zastosowanym kodem; komunikatu jeszcze nie ma.
2. Po odczycie, w jednym renderze: komunikat (`role="alert"`, `tabIndex=-1`) z listą zmian, podsumowanie z odczytu
   i odblokowany formularz. Dane formularza, wybór płatności i dostawy (gdy metoda jest nadal wśród opcji) zostają;
   region podsumowania ogłasza dostawę jak po każdym odczycie. Następne kliknięcie to nowa próba z nowym kluczem
   i z liniami oraz kwotą nowego podsumowania.
3. Komunikat: pierwszy akapit „Zawartość koszyka zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.”
   (`cart-changed`) albo „Kwota zamówienia zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.”
   (`order-total-changed`; `detail` API nie jest pokazywany). Gdy w chwili pokazania komunikatu zostaje niepewna
   próba tej strony (reguła niepewnej próby w akapicie „Zmiana kupującego”, punkt 3, także z innym kluczem i także
   z tym samym kluczem, który właśnie odrzucono), zamiast „Nie złożyliśmy zamówienia.” jest „Wcześniejsza próba mogła
   złożyć zamówienie. Sprawdź pocztę, zanim zamówisz ponownie.”; dotyczy to także komunikatu z odczytu w kasie
   nieaktualnej (punkt 5, również przez kod rabatowy), a lista, podsumowanie, link i fokus są takie same (akapit
   „Niepewna próba” wyżej). Potem lista zmian (`src/checkout/cart-changes.ts`, czysta
   funkcja dwóch `CartView` i wybranej metody: porównuje wartości z API, niczego nie liczy), potem „Sprawdź podsumowanie
   i zamów ponownie.” z linkiem „Przejdź do koszyka”. Gdy porównanie nie znajduje różnic, bez listy. Pozycja listy (po
   jednej na zmianę, w kolejności podsumowania: linie pokazane, nowe linie, rabat, dostawa, kwota; `<nazwa>` to nazwa
   produktu i etykieta wariantu po przecinku, jak w koszyku, a dla wariantu nieznanego w obu podsumowaniach „Nieznany
   produkt”):

   | Zmiana | Tekst |
   |---|---|
   | inna ilość | „<nazwa>: było 2 szt., jest 1 szt.” |
   | pozycji już nie ma | „<nazwa>: usunięto z koszyka” |
   | nowa pozycja | „<nazwa>: dodano, 1 szt.” |
   | inna wartość linii przy tej samej ilości (obie z ceną) | „<nazwa>: wartość była 549,00 zł, jest 499,00 zł” |
   | inny rabat kodu (`totals.discountGross`) | „Rabat: był 50,00 zł, jest 0,00 zł” |
   | inny koszt wybranej dostawy | „Dostawa „<metoda>”: była 0,00 zł, jest 14,99 zł” |
   | wybrana metoda zniknęła z opcji | „Dostawa „<metoda>” nie jest już dostępna. Wybierz sposób dostawy.” (wybór zdjęty) |
   | inna `totalGross` wybranej metody w obu podsumowaniach | „Do zapłaty: było 549,00 zł, jest 1 647,00 zł” |

   Bez wybranej metody i dla koszyka bez nic do kupienia (`delivery: null`) lista nie mówi o dostawie ani kwocie.
4. Koszyk pusty albo z `hasExcludedLines` po odczycie: zamiast formularza widok tych stanów z własnym linkiem do
   koszyka, a komunikat ma pierwszy akapit i listę, bez ostatniego akapitu i bez drugiego linku. Kod odrzucony przy
   odczycie: komunikat przy polu kodu jak przy każdym odczycie, kod zdjęty, wiersz „Rabat” w liście.
5. 401 odczytu: komunikat wygasłej sesji. Odczyt nieudany: „Koszyk albo kwota zamówienia się zmieniły, ale nie udało
   się wczytać aktualnego podsumowania. Spróbuj ponownie.”, a podsumowanie jest nieaktualne (także po 401): następne
   kliknięcie „Zamawiam z obowiązkiem zapłaty” niczego nie zamawia, tylko czyta koszyk z tą samą blokadą formularza
   (region statusu: „Wczytywanie…”) i pokazuje komunikat z punktu 3 (albo znowu ten o nieudanym odczycie); dopiero
   kolejne kliknięcie składa zamówienie. Reguła: dopóki klient nie zobaczył listy zmian względem podsumowania
   odrzuconego zamówienia (albo komunikatu bez listy, gdy różnic nie ma), kliknięcie nie zamawia, a komunikat na
   ekranie jest prawdziwy. Dlatego udany odczyt podsumowania w inny sposób w stanie nieaktualnym (zastosowanie kodu,
   „Usuń kod”, kod odrzucony przez podsumowanie albo przez 400, po którym kasa czyta podsumowanie bez kodu) od razu
   zastępuje komunikat o nieudanym odczycie komunikatem z punktu 3: lista zmian względem podsumowania odrzuconego
   zamówienia i metody wybranej w chwili odpowiedzi odczytu, z tym samym pierwszym akapitem; dopiero kolejne kliknięcie
   zamawia. Dotyczy to także kodu zastosowanego po nieudanym odczycie, zanim strona go wyrenderowała (stan nieaktualny
   jest ustawiany od razu, nie dopiero z renderem). Nieudane albo 401 sprawdzenie kodu zostawia stan nieaktualny.
6. Komunikat z listą zmian opisuje różnicę z chwili odrzuconego zamówienia. Znika, gdy po jego pokazaniu klient sam
   zmieni podsumowanie: udane zastosowanie kodu, „Usuń kod” albo wybór innej metody dostawy; inaczej przeczyłby
   podsumowaniu obok. Zostaje przy edycji pól, wyborze płatności, kodzie odrzuconym (przez podsumowanie albo 400)
   i nieudanym sprawdzeniu kodu. Zniknięcie nie przenosi fokusu, a fokus i ogłoszenia kodu i dostawy są jak zawsze.
   Komunikaty innego rodzaju zostają, a odczyt przez kod w stanie nieaktualnym pokazuje listę zmian (punkt 5).

Zmiana kupującego (409 `checkout-buyer-changed`: logowanie, wylogowanie albo inne konto w innej karcie;
`docs/features/cart-order-consistency/spec.md`, Z4). Kasa wysyła w `buyer` kupującego, którego pokazuje: `"guest"`
przy polu e-mail albo `buyer` z odczytu `/auth/session`, z którego pochodzi pokazany adres konta (wartość z renderu,
z którego wyszło kliknięcie).

1. Formularz zostaje zablokowany jak w trakcie składania zamówienia, region statusu mówi dalej „Składamy
   zamówienie…”. Kasa czyta od nowa sesję (`/auth/session`) i koszyk z zastosowanym kodem; komunikatu jeszcze nie ma,
   a sama odpowiedź sesji niczego nie zmienia na ekranie.
2. Po obu odczytach, w jednym renderze: komunikat (`role="alert"`, `tabIndex=-1`, fokus według F2), kupujący
   i podsumowanie z odczytu, odblokowany formularz. Wpisane pola (imię i nazwisko, telefon, adres), wybór płatności
   i dostawy (gdy metoda jest nadal wśród opcji) zostają; imię nie jest wypełniane od nowa z drugiego konta. Pole
   e-mail pojawia się albo znika według nowej sesji. Koszyk innego konta jest pokazany bez listy zmian (pusty albo
   z pozycjami nie do kupienia: widok tych stanów). W tym samym czasie (i tak samo po wczytaniu kasy i po „Spróbuj
   ponownie”) nagłówek pokazuje sesję z tego odczytu
   („Zalogowany jako …” albo link „Zaloguj się”; kasa przekazuje ją zdarzeniem `announceSession`, bez drugiego
   żądania `/auth/session`), a fokus się nie przenosi. Tak samo po każdym 401, po którym kasa pokazuje wygasłą sesję
   (zamówienie, odczyt koszyka, kod rabatowy, wczytanie kasy): sklep usunął sesję, więc nagłówek pokazuje link
   „Zaloguj się”.
3. Tekst: „Od wczytania kasy zmieniło się konto: teraz to <adres konta>. Nie złożyliśmy zamówienia. Zamówienie będzie
   zamówieniem tego konta: sprawdź dane i podsumowanie, potem zamów ponownie.”, a bez sesji po odczycie: „Od
   wczytania kasy nastąpiło wylogowanie. Nie złożyliśmy zamówienia. Podaj adres e-mail, sprawdź dane i podsumowanie,
   potem zamów ponownie.”. Wcześniejsza próba mogła jednak złożyć zamówienie, czego BFF, który odrzuca przed API, nie
   wie: dopóki zostaje niepewna próba (akapit „Niepewna próba” wyżej), komunikat w obu wariantach (także z odczytu
   w kasie nieaktualnej, punkt 5) mówi zamiast „Nie złożyliśmy zamówienia.”: „Wcześniejsza próba mogła złożyć
   zamówienie. Sprawdź pocztę, zanim zamówisz ponownie.”. `detail` 409 `checkout-buyer-changed` z BFF nie jest
   pokazywany.
4. Następne kliknięcie to nowa próba: nowy klucz, `buyer` i `expectedLines` z ostatniego odczytu. Kolejna zmiana konta
   między odczytem a kliknięciem daje kolejne 409 i kolejny odczyt.
5. Odczyt nieudany (sesja albo koszyk): „Kupujący się zmienił, ale nie udało się wczytać kasy. Spróbuj ponownie.”,
   kupujący i podsumowanie zostają jak były, a kasa jest nieaktualna (stan ustawiany od razu, jak w punkcie 5
   rozbieżności): następne kliknięcie „Zamawiam z obowiązkiem zapłaty” niczego nie zamawia, tylko czyta sesję i koszyk
   z blokadą formularza (region „Wczytywanie…”) i pokazuje komunikat z punktu 3. Odczyt podsumowania przez kod
   rabatowy nie czyta sesji, więc stanu nie zmienia. 401 odczytu koszyka: komunikat wygasłej sesji, kasa nieaktualna.

Granica ochrony zamówienia: zamówienie powstaje tylko wtedy, gdy w chwili złożenia koszyk na serwerze ma te same
pozycje i ilości co podsumowanie pokazane w kasie, ta sama jest kwota do zapłaty i ten sam kupujący (gość albo to samo
konto); inaczej 409 (kasa konta bez sesji: 401), a kasa pokazuje, co się zmieniło. Kasa konta wysłana bez sesji nigdy
nie staje się zamówieniem gościa. Poza gwarancją: `docs/features/cart-order-consistency/spec.md`, „Granica ochrony
zamówienia”.

Po odrzuceniu fokus przechodzi na komunikat błędu (`role="alert"`, `tabIndex=-1`); po 422 z błędami pól na pierwsze
błędne pole (w kolejności formularza), które ma `aria-invalid` i `aria-describedby` na swój komunikat; po odrzuconym
kodzie na pole kodu; po rozbieżności koszyka albo kwoty dopiero po odczycie koszyka. Dzieje się tak tylko wtedy, gdy
w chwili odpowiedzi (po rozbieżności: w chwili końca odczytu) fokus jest nadal na elemencie, z którego
wyszło wysłanie (przycisk albo pole, w którym naciśnięto Enter), albo na `<body>`. Gdy klient przeszedł w tym czasie
do innej kontrolki, fokus zostaje tam, a odrzucenie ogłasza `role="alert"`: komunikat nad formularzem, a po 422
z błędami pól albo dostawcy same teksty tych błędów przy polach i przy grupie „Sposób płatności” (te same teksty, bez
zmiany wyglądu). Gdy fokus idzie na pierwsze błędne pole, błędy przy polach nie są w `role="alert"`; opisuje je
`aria-describedby` pola.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Podwójne kliknięcie, ponowienie po zgubionej odpowiedzi | jedno zamówienie; ponowienie dostaje 200 z tym samym zamówieniem, także gdy koszyk gościa w międzyczasie wygasł albo został scalony |
| Ten sam klucz z inną treścią albo z innego koszyka | 409 `IdempotencyKeyReused` |
| Dwa złożenia z różnymi kluczami z jednego koszyka (dwie karty) | jedno zamówienie, drugie 409 `CartEmpty` |
| Zmiana pozycji albo ilości w koszyku (inna karta, przeglądarka, urządzenie) między podsumowaniem a złożeniem, także przy tej samej kwocie | 409 `CartContentChanged`, bez zamówienia, rezerwacji, użycia kodu, roszczenia limitu i numeru; koszyk bez zmian |
| Logowanie, wylogowanie albo zmiana konta w innej karcie między wczytaniem kasy a złożeniem, także przy tych samych pozycjach i kwocie | sklep: 409 `checkout-buyer-changed` (inny kupujący) albo 401 (kasa konta bez sesji), bez wywołania API i bez scalenia koszyka; nigdy zamówienie gościa z kasy konta |
| Ostatnia sztuka kupowana równolegle przez wielu klientów | tylu zamawiających, ile sztuk; reszta 409 `InsufficientStockForOrder`, ich koszyki bez zmian |
| Zmiana ceny między podsumowaniem a złożeniem | 409 `OrderTotalChanged`, bez rezerwacji i zamówienia |
| Pozycja bez ceny, nieznany wariant | 409 `CartNotOrderable` |
| Dodanie do koszyka równolegle ze złożeniem | blokada koszyka: dodanie przed złożeniem daje 409 `CartContentChanged` (koszyk z obiema liniami), po złożeniu zostaje w pustym koszyku; dodana linia nigdy nie trafia do zamówienia |
| Błąd w trakcie złożenia (np. zapis zamówienia) | całość wycofana: koszyk bez zmian, brak rezerwacji, brak zdarzenia |
| Błąd wysyłki e-maila | zamówienie złożone; ponowienia transportu, potem `failed` |
| Zdarzenie dostarczone dwa razy, jedno po drugim albo równolegle | jeden e-mail (znacznik i zajęcie wysyłki w tabeli powiadomień) |
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
4. Kwota inna niż oczekiwana, linie koszyka inne niż `expectedLines`, pusty koszyk i pozycje niemożliwe do kupienia
   dają 409 bez zmian. Ceny, ilości i sumy zamówienia pochodzą wyłącznie z koszyka, Pricing i domeny; pola żądania
   ich nie zmieniają.
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
- `Order`: `place` (zdarzenie, status `new`), limity linii, `isReplayOf`.
- `PlaceOrder`: pusty koszyk, wariant bez ceny i nieznany, kwota inna, niedobór z Inventory, powtórzenie (bez zmian,
  koszyk nie opróżniony; szybka ścieżka bez blokady koszyka, także dla koszyka gościa scalonego albo wygasłego), klucz
  z inną treścią, z innego koszyka gościa przy tych samych danych i innego klienta (409), kolejność kroków (rezerwacja
  po sprawdzeniu kwoty, numerze i opróżnieniu koszyka; zapis na końcu).
- `PlaceOrder` liczy kwoty z portu `OrderPrices` niezależnie od `expectedTotalGross` (kwota niższa i wyższa: 409).
- `ExpectedLines` (duplikat, wielkość liter, granice 1, 50, 99, kolejność bez znaczenia, postać kanoniczna);
  `PlaceOrder`: inna zawartość (inna ilość, zamienione ilości przy tej samej kwocie, brak linii, linia dodatkowa, inny
  wariant) daje `CartContentChanged` po `CartEmpty`, przed limitem gościa, limitem nieopłaconych zamówień i wyceną,
  bez żadnego zapisu; odcisk kończy się `lines:…`; ten sam klucz z inną listą to `IdempotencyKeyReused`, z tą samą
  (także w innej kolejności i wielkości liter) powtórzenie, także po opróżnieniu koszyka.
- `SendOrderConfirmation`: przypadki z `docs/features/order-management/spec.md` („Znaczniki wysyłki poza
  agregatem”, testy jednostkowe); `OrderConfirmationText`
  (treść, formatowanie kwot, data w `Europe/Warsaw`, wariant domyślny bez etykiety).
- Cart: `clearAfterOrder`, `LockCartForOrder` (gość `merged`, wygasły, koszyk klienta wygasły).

### Integracyjne (PostgreSQL, Mailpit; `apps/api/tests/Integration/Ordering`)

- `DoctrineOrderRepository`: mapowanie, `CHECK`, unikalne indeksy (bezpośredni SQL łamiący reguły jest odrzucany),
  naruszenie `idempotency_key` → `IdempotencyKeyReused`, sekwencja numerów.
- `PlaceOrder` na prawdziwej bazie: sukces (zamówienie, linie, rezerwacja z kluczem `orderId`, koszyk pusty,
  wiersz outboxa); niedobór i wymuszony błąd zapisu zamówienia wycofują wszystko (koszyk z liniami, brak rezerwacji,
  brak zamówienia, brak wiersza outboxa).
- Przebieg R1 (`docs/features/cart-order-consistency/spec.md`): koszyk A × 1 i B × 2 w tej samej cenie, żądanie
  z A × 2 i B × 1 i tą samą kwotą daje `CartContentChanged`; koszyk, stany, sekwencja numerów, roszczenia i użycia
  kodów bez zmian. Powtórzenie zaraz po złożeniu (koszyk pusty) zwraca to samo zamówienie.
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
  wyczerpaniu ponowień wiadomość jest w `failed`, a wysyłka bez znacznika i zajęcia; wysyłka zajęta przez inny
  handler wraca sekundę po końcu jego lease i kończy się jednym e-mailem.
- `ordering:seed-demo`: czyści zamówienia, linie i powiadomienia; w `prod` bez `--force-reset` odmowa bez zmian; `make seed` po
  złożonych zamówieniach zostawia zero zamówień i zero rezerwacji.

### Współbieżność (`Ordering/Concurrency/PlaceOrderConcurrencyTest`, jak C1–C6 i K1–K8, 5 rund)

| ID | Scenariusz | Oczekiwany wynik |
|---|---|---|
| O1 | 10 koszyków gości z tym samym wariantem (1 szt.), stan 3, równoległe złożenia | dokładnie 3 zamówienia i 7 × `InsufficientStockForOrder`; `reserved` = 3; 7 koszyków z liniami |
| O2 | 10 równoległych złożeń z tym samym kluczem i koszykiem | 1 zamówienie, 1 × 201 i 9 × powtórzenie z tym samym `orderId`, rezerwacja raz |
| O3 | 2 równoległe złożenia z różnymi kluczami z jednego koszyka | 1 zamówienie, 1 × `CartEmpty` |
| O4 | 2 koszyki `[A, B]` i `[B, A]` równolegle, stan A = B = 1 | dokładnie 1 zamówienie, brak zakleszczeń |
| O5 | złożenie klienta równolegle ze scaleniem koszyka gościa do tego klienta; nieparzyste rundy z listą sprzed scalenia, parzyste z listą po scaleniu | brak zakleszczeń; albo zamówienie dokładnie z listy (sprzed scalenia: linie gościa zostają w koszyku; po scaleniu: wszystkie linie w zamówieniu), albo `CartContentChanged` i wszystkie linie w koszyku; linie nigdy utracone |
| O6 | po złożeniu koszyk gościa scalony do konta (osobna runda: wygasły); 10 równoległych powtórzeń z tym samym kluczem, `cartId` i treścią | 10 × powtórzenie z tym samym `orderId`, żadnego 404, żadnej nowej rezerwacji |
| O7 | ten sam klucz równolegle z dwóch różnych koszyków gości (osobna runda: dwóch klientów), te same dane | 1 zamówienie, 1 × `IdempotencyKeyReused` bez danych zamówienia; koszyk przegranego z liniami, jedna rezerwacja |
| O8 | złożenie z listą sprzed zmiany równolegle ze zmianą ilości tej linii | albo zamówienie z ilością z listy i `CartLineNotFound` zmiany, albo `CartContentChanged`, brak zamówienia i koszyk z nową ilością; nigdy zamówienie z nową ilością |
| O9 | złożenie równolegle z dodaniem innego wariantu | albo zamówienie z liniami z listy i koszyk z samą nową linią, albo `CartContentChanged` i koszyk z obiema; nigdy zamówienie z dodaną linią ani utracona linia |
| O10 | złożenie koszyka z dwiema liniami równolegle z usunięciem jednej | albo zamówienie z obiema (usunięcie spóźnione niczego nie zmienia: `RemoveCartItem` brakującej linii to sukces), albo `CartContentChanged` i koszyk z jedną linią |
| O11 | dwa złożenia z różnymi kluczami z jednego koszyka: lista aktualna i lista nieaktualna | dokładnie jedno zamówienie, z listą aktualną; nieaktualne dostaje `CartContentChanged` albo `CartEmpty` |
| O12 | dwa warianty w tej samej cenie; złożenie z listą A × 2, B × 1 równolegle ze zmianami ilości w innej karcie na A × 1, B × 2 (ta sama kwota). Nieparzyste rundy: zmiana B przed rundą (koszyk startuje z A × 2, B × 2), równolegle tylko zmiana A; parzyste: obie zmiany równolegle od A × 2, B × 1 | albo zamówienie dokładnie z listy i obie zmiany trafiają w opróżniony koszyk (`CartLineNotFound`), albo `CartContentChanged` i koszyk A × 1, B × 2; nigdy zamówienie A × 1, B × 2 |

Po każdej rundzie: `reserved` każdego wariantu = suma ilości aktywnych rezerwacji, każde zamówienie ma rezerwację
o kluczu `orderId` z tymi samymi liniami, a jego linie są równe `expectedLines` żądania, które je złożyło.

### Funkcjonalne API (`apps/api/tests/Functional/Ordering`)

Oba endpointy: 201, 200 (powtórzenie), 400 (brak klucza, zły klucz, zły JSON), 401 (`/me/orders` bez tokena i z tokenem
`backoffice`; niepoprawny token na `/orders`), 404 (koszyk gościa nieznany, wygasły, scalony, koszyk klienta), 409
(każda przyczyna), 415, 422 (każdy wiersz tabeli komunikatów, dokładna lista `violations`), `Cache-Control:
private, no-store`.

- Wariant w aktywnej promocji: pozycja i sumy zamówienia gościa mają cenę promocyjną (netto i VAT ze stawki ceny
  katalogowej), bez kodu Ordering dla promocji.

- Ceny: treść z dodatkowymi polami (`lines` z innymi ilościami, `totals`, `unitGross`, `price`) daje zamówienie
  z ilościami z koszyka i cenami z Pricing; `expectedTotalGross` niższe (także 0) i wyższe od sumy daje 409
  `OrderTotalChanged` bez zamówienia i rezerwacji.
- Idempotencja: 409 `IdempotencyKeyReused` nie zawiera `orderId`, numeru ani e-maila zamówienia, które użyło klucza
  (inny koszyk gościa, inny klient, ta sama treść z innym `cartId`, inna lista linii); powtórzenie po scaleniu
  i wygaśnięciu koszyka gościa daje 200.
- Zawartość (P2a): 409 `cart-changed` tylko z `type`, `title`, `status` i `detail` dla gościa i klienta (zamienione
  ilości przy tej samej kwocie, inna ilość, brak linii, linia dodatkowa, inny wariant), bez zamówienia, rezerwacji
  i roszczenia; lista w innej kolejności i wielkości liter UUID składa zamówienie; UUID spoza wersji (nil, max) i lista
  50 linii po 99 sztuk to 409, nie 422; pusty koszyk to `cart-empty` przy każdej liście.

### Architektura

Deptrac z regułami Ordering. Zakazane (brak reguły): `*_* → Ordering_*` (każdy inny context), `Ordering_Domain` →
inne contexty i `Vendor`, `Ordering_Application` → inne contexty, `Ordering_Infrastructure` → `*_Domain`
i `*_Infrastructure` innych contextów, `Ordering_UI` → `Customer_Infrastructure`, `Cart_*` → `Ordering_*`.

### Vitest (`apps/store`)

- BFF `app/api/checkout/route.test.ts`: gość, sesja (ze scaleniem `store_cart`), brak `store_cart` (409 z `type`
  `CartEmpty`), 403 `Origin`
  i `Content-Type`, brak `Idempotency-Key`, przekazanie klucza, do API trafiają tylko pola kontraktu (dodatkowe pola
  przeglądarki, np. ceny, są pomijane; `expectedLines` przechodzi bez zmian dla gościa i klienta), 201 i 200 ustawiają `store_order_confirmation`
  (z walutą), 404 gościa usuwa `store_cart`, 409 (przekazuje `type` każdej z pięciu przyczyn, `about:blank` i brak),
  422, 401, 503, `no-store`.
- `/auth/session` (`app/auth/session`): `{name, email, buyer}`; znacznik równy HMAC liczonemu w teście, ten sam dla
  tego samego `sub` w dwóch sesjach i po odświeżeniu tokenu w trakcie żądania, inny dla innego `sub`; bez `sub`,
  identyfikatora sesji i tokenów; sesja usunięta między odczytem tokenu a `sub`: `null`; błąd Redisa: 503.
- `src/auth/buyer.test.ts`: znacznik (także: nie przechodzi `readSignedValue` jako podpis wartości zbudowanej z `sub`,
  w tym `checkout-buyer:<sub>` i samego `sub`) i porównanie (obcy znacznik, inny klucz, `guest`, sam `sub`, wielkość liter, znaki
  sterujące, białe znaki Unicode, bardzo długi i pusty tekst), jedno wywołanie `timingSafeEqual` na buforach równej
  długości dla każdego tekstu. `src/checkout/problems.test.ts`: przyczynę `buyer_changed` wyznacza tylko `type`.
- BFF, kupujący (`app/api/checkout/route.test.ts`): każdy wiersz tabeli kupującego z liczbą wywołań API (zero przy
  409, 401, 503 i 422, także listy dostawców) i stanem ciasteczek; `buyer` nie trafia do API; 422 dla braku, `null`,
  liczby, `true`, obiektu, tablicy i pustego tekstu; 409 przy sesji i 401 bez niej dla obcych wartości; zmiana `sub`
  po odświeżeniu: 409; logi bez znacznika, `sub`, identyfikatora sesji i tokenów.
- `CheckoutPage`: pierwszy odczyt po zakończeniu akcji strony koszyka (do tego czasu „Wczytywanie…”), od razu bez
  akcji i po akcjach porzuconych; formularz gościa i klienta (e-mail z sesji), podsumowanie, blokada przycisku, każdy wiersz tabeli
  UI (pięć przyczyn 409 i nieznany 409: komunikat, link do koszyka, formularz, ten sam albo nowy klucz przy kolejnym
  kliknięciu, brak automatycznego drugiego POST), `expectedLines` równe liniom pokazanego podsumowania (w jego
  kolejności, także po zastosowaniu i usunięciu kodu i po odczycie po 409), 409 `cart-changed`
  i `order-total-changed` na wstrzymanym odczycie koszyka (do odpowiedzi formularz zablokowany, bez komunikatu, region
  „Składamy zamówienie…”, zmiana pola i wyboru nic nie zmienia; potem komunikat z listą i nowe podsumowanie w jednym
  renderze, sprawdzane obserwatorem DOM; fokus według F2; pusty koszyk, `hasExcludedLines`, 401, kod odrzucony przy
  odczycie, metoda dostawy znika, brak różnic; odczyt nieudany i podsumowanie nieaktualne: kliknięcie czyta koszyk
  i nie wysyła zamówienia, ponowny błąd, 401; kod zastosowany, usunięty, odrzucony przez podsumowanie i przez 400
  w stanie nieaktualnym: od razu lista zmian względem podsumowania odrzuconego zamówienia i zamawia dopiero kolejne
  kliknięcie; nieudane i 401 sprawdzenie kodu: stan zostaje, kliknięcie tylko czyta), 409 `checkout-buyer-changed` na
  wstrzymanych odczytach sesji i koszyka (blokada do końca obu odczytów, sama odpowiedź sesji niczego nie pokazuje;
  komunikat, kupujący i podsumowanie w jednym renderze; gość → konto, konto A → konto B, konto → wylogowanie; zachowane
  pola; następne wysłanie z nowym znacznikiem, kluczem i liniami; drugie 409 i drugi odczyt; kod w odczycie; fokus F2;
  pusty koszyk; 401; odczyt nieudany i kasa nieaktualna: kliknięcie czyta sesję i koszyk, nie zamawia, a odczyt kodu
  stanu nie zmienia), niepewna próba (`failed` albo `IdempotencyKeyReused`) przed odrzuceniem: ostrzeżenie zamiast
  „Nie złożyliśmy zamówienia.” po zmianie kupującego i w komunikacie rozbieżności (z innym kluczem, z tym samym
  kluczem po odrzuceniu API, w kasie nieaktualnej, także przez kod rabatowy), ostrzeżenie po tekście pozostałych
  komunikatów (pusty koszyk, klucz użyty, 404, 401, 422, 429, nieudany odczyt), nad formularzem po 422 pól i po 409
  kodu, wynik niepewny bez ostrzeżenia przy jedynej niepewnej próbie i z nim przy wcześniejszej innej, bez niepewnej
  próby (także po 429) teksty jak w tabeli, oraz strażnik: po niepewnej próbie (wynik niepewny albo klucz użyty, ten
  sam i inny klucz) każdy wynik inny niż sukces (każdy status, każda przyczyna 409 z `detail` i bez) nie zostawia
  na ekranie „Nie złożyliśmy zamówienia” ani, poza tekstem zastępczym odrzucenia bez `detail`, „Nie udało się złożyć
  zamówienia”, i ma ostrzeżenie albo tekst wyniku niepewnego; funkcja różnic `cart-changes.test.ts` (każdy wiersz tabeli, kolejność,
  brak różnic, pozycja bez produktu, kwoty z API bez sumowania), ponowienie z tym samym kluczem po błędzie, nowy klucz po zmianie
  danych, fokus po odrzuceniu, pusty koszyk, `hasExcludedLines`, region statusu (ten sam węzeł od wczytywania do treści),
  „Spróbuj ponownie” (przycisk z `aria-disabled` w trakcie, fokus po sukcesie, ponownym błędzie i przeniesiony w trakcie),
  link „Zaloguj się” według przełączników (przed odczytem, wyłączone konta, włączone konta), blokada w trakcie
  składania zamówienia (pola, wybory, kod, jedno żądanie, blokada w chwili przejścia po sukcesie, odblokowanie po
  błędzie z zachowanymi wartościami), fokus po odrzuceniu i po sprawdzeniu kodu pod warunkiem pozostania fokusu (wysłanie
  przyciskiem i Enterem z „Miejscowość”, 422 i błąd) oraz oba błędy wyboru jednym kliknięciem.
- `/checkout/confirmation` i `PaymentConfirmation`: z ciasteczkiem, bez, z niepoprawnym podpisem, każdy stan płatności
  (`docs/features/payment/spec.md`).
- `CartPage`: przycisk „Przejdź do kasy” aktywny i nieaktywny.
- `src/api/client.test.ts`: `placeOrder` (także mapowanie `type` każdej przyczyny 409 na `reason`), `startPayment`,
  `getPaymentStatus`.

### E2E (Playwright)

- `checkout-guest.spec.ts` (ścieżka nr 1): gość wybiera wariant na kafelku, dodaje 2 szt., na `/cart` „Przejdź do
  kasy”, wypełnia formularz, wybiera „Szybki przelew (demo)”, składa zamówienie i płaci w symulatorze; strona
  potwierdzenia pokazuje numer `R-…`, „Płatność przyjęta” i kwotę równą sumie z koszyka; licznik koszyka 0; w Mailpit
  e-mail z potwierdzeniem i e-mail o płatności na podany adres z tym numerem.
- `checkout-customer.spec.ts`: Jan loguje się, opróżnia koszyk, dodaje wariant, składa zamówienie (e-mail z konta,
  bez pola e-mail), odrzuca płatność „Kartą płatniczą (demo)”, wybiera ponownie i płaci; w Mailpit e-maile na adres
  Jana z numerem tego zamówienia (wyszukiwanie po adresie i temacie z numerem, bo Jan dostaje e-maile przy każdym
  uruchomieniu).
- `store-ux-checkout.spec.ts`: ponowienie wczytania kasy po błędzie `/api/cart` i `/auth/session` (odpowiedź ponowienia
  wstrzymana: przycisk z fokusem i `aria-disabled`, drugi Enter bez żądania, region „Wczytywanie…”; sukces: fokus na
  „Kasa”; ponowny błąd: komunikat i fokus na przycisku; fokus przeniesiony w trakcie zostaje), ten sam węzeł regionu
  statusu, brak przewijania poziomego przy 320 i 375 px oraz link „Zaloguj się” przy podstawionym `/api/site`
  (wyłączone konta, włączone, błąd odczytu; odpowiedź wstrzymana). Składanie zamówienia z `POST /api/checkout`
  wstrzymanym w przeglądarce i zakończonym odpowiedzią podstawioną przez test (500 albo 422), więc bez zamówienia:
  wpisywanie w pola i w pole kodu, mysz, dotyk, strzałki i Spacja na wyborach oraz „Zastosuj” niczego nie zmieniają,
  przycisk ma fokus i `aria-disabled`, region „Składamy zamówienie…”, jeden POST; wysłanie Enterem z „Miejscowość”
  (po 422 fokus na pierwszym błędnym polu; po przejściu Tab w trakcie fokus zostaje, a po 500 komunikat, po 422 błąd
  pola jest w `role="alert"`); pusty formularz przy 375 px
  z dotykiem: oba błędy wyboru, fokus na pierwszym sposobie dostawy w widoku, bez POST; brak przewijania poziomego
  przy 320 i 375 px.
- `cart-order-consistency.spec.ts` (dwie karty jednego kontekstu, zmiany koszyka w drugiej karcie żądaniami do BFF
  koszyka, gość): R1 (ilości dwóch wariantów zamienione przy tej samej kwocie: 409, komunikat wymienia obie pozycje,
  podsumowanie ma nowe ilości, drugie kliknięcie składa zamówienie, a e-mail ma ilości nowego podsumowania), R2 (ilość
  zmieniona, odczyt koszyka po 409 wstrzymany: bez komunikatu, „Składamy zamówienie…”, formularz zablokowany; po
  zwolnieniu komunikat i nowe podsumowanie w tej samej klatce, „było 1 szt., jest 3 szt.”, „Do zapłaty”, link do
  koszyka), R3 (granica: zmiana ilości ze strony koszyka wstrzymana w drodze, zamówienie z drugiej karty zgodne
  z kasą, spóźniony zapis dostaje 404) oraz komunikat R1 z najdłuższą nazwą produktu danych demo przy 375 px z dotykiem
  i przy 320 px: bez przewijania poziomego, link „Przejdź do koszyka” osiągalny Tabem z komunikatu i działający
  dotykiem albo Enterem. W tym samym pliku zmiana kupującego w drugiej karcie na kontach demo Jan i Anna (każdy test
  przygotowuje potrzebne koszyki, a po każdym koszyki obu kont są opróżniane; w R4, R5 i R6 nagłówek po komunikacie
  pokazuje tę samą sesję co kasa): R4 (kasa gościa, logowanie Jana: 409,
  komunikat z adresem Jana, formularz bez pola e-mail z zachowanymi danymi; drugie kliknięcie składa zamówienie Jana,
  na adres z kasy nie przychodzi nic), R5 (kasa Jana, wylogowanie i logowanie Anny z tymi samymi pozycjami i kwotą:
  409, komunikat z adresem Anny, koszyk Anny bez zmian; drugie kliknięcie składa zamówienie Anny, koszyk Jana zostaje)
  i R6 (kasa Jana, wylogowanie i koszyk gościa w drugiej karcie: 401, widok wygasłej sesji, żaden koszyk nie został
  zamówiony). Przy 375 px z dotykiem i przy 320 px komunikat zmiany kupującego i wiersz „Potwierdzenie wyślemy na adres”
  z adresem konta o 60 znakach bez spacji (odpowiedź 409 i `/auth/session` podstawione w przeglądarce) nie powodują
  przewijania poziomego; tak samo podsumowanie, wybór dostawy i komunikat z listą zmian z nazwą produktu, etykietą
  wariantu i nazwą metody dostawy bez spacji dłuższymi niż ekran i kodem rabatowym o 64 znakach (odczyty koszyka
  i odpowiedź 409 `cart-changed` podstawione w przeglądarce, nic nie jest zamawiane). R7 (kasa Jana, odpowiedź na
  zamówienie utracona po jego złożeniu, logowanie Anny w drugiej karcie przed ponowieniem: 409 na ten sam klucz,
  komunikat „Wcześniejsza próba mogła złożyć zamówienie…” z adresem Anny; zamówienie Jana istnieje i jest opłacane).
- `payment-resume.spec.ts`: powrót ze strony symulatora na `/checkout`, link „Opłać zamówienie R-…”, „Wróć do
  płatności” otwiera tę samą sesję, płatność się udaje.
- E2E składają zamówienia na wariantach z dużym stanem i je opłacają; rezerwacje opłaconych zamówień zostają do
  wysyłki (ORDER-MANAGEMENT), z komentarzem w `fixtures/stock.yaml`.

## Ryzyka i ograniczenia

- Nieopłacone zamówienie trzyma rezerwację do 35 minut (termin płatności i 5 minut na webhooki). Limit liczby zamówień
  (T2, `docs/features/hardening/spec.md`) nie ogranicza ilości w jednym zamówieniu, więc złośliwy gość może w tym czasie
  zarezerwować dużą część stanu (D16 tamtej specyfikacji).
- Jedna transakcja obejmuje koszyk, rezerwację i zamówienie: popularny wariant szereguje złożenia na wierszu stanu,
  a transakcja jest dłuższa niż sama rezerwacja. Wydajność przy docelowej skali nie jest potwierdzona testami
  obciążeniowymi.
- Numeracja ma luki (wycofane złożenia) i ujawnia przybliżoną liczbę zamówień.
- Drugi e-mail jest możliwy, gdy wysyłka się udała, a znacznik nie został zapisany: awaria procesu albo bazy przed
  zapisem (po wygaśnięciu zajęcia wysyłki, 5 min) albo błąd SMTP zgłoszony po przyjęciu wiadomości (zwolnienie
  zajęcia i ponowienie); także gdy wysyłka trwa dłużej niż zajęcie. Outbox daje „co najmniej raz”.
- Dane osobowe gościa (e-mail, telefon, adres) są w zamówieniu bez anonimizacji (RODO później).
- Gość nie czyta zamówienia, tylko status jego płatności, i tylko dopóki ma ciasteczko potwierdzenia (1 godzina,
  P7); szczegóły ma w e-mailu. `accessToken` z odpowiedzi złożenia jest w ciasteczku `HttpOnly` i nie trafia do skryptów
  strony (`docs/features/payment/spec.md`, decyzja A5).
- `make seed` usuwa zamówienia razem z rezerwacjami (dane demo); wiersze outboxa usuniętych zamówień trafiają do
  `failed`.
- `cartId` gościa jest w treści żądania (nie w ścieżce), więc nie trafia do logów dostępu API.
