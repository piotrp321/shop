# DISCOUNT-CODES: kody rabatowe w kasie

Punkt 10 „Zakresu MVP” z `docs/vision.md`, część 2 (część 1: PROMOTIONS #96, promocje i cena Omnibus). Klient wpisuje
w kasie kod rabatowy, widzi rabat w podsumowaniu i składa zamówienie z rabatem. Reguły cen i rabatów liczy wyłącznie
API (Pricing), fronty tylko wyświetlają.

Issues: #225
Zakres: `apps/api/src/Pricing`, `apps/api/src/Cart`, `apps/api/src/Ordering`, `apps/api/tests`, `apps/api/config`, `apps/api/openapi.json`, `apps/api/openapi-admin.json`, `apps/store/src/api/schema.d.ts`, `apps/admin/src/api/schema.d.ts`, `apps/store/src/checkout`, `apps/store/src/cart`, `apps/store/src/account`, `apps/store/app/api/cart`, `apps/admin/src/discount-codes`, `apps/admin/src/orders`, `apps/admin/src/views/OrderDetailView.vue`, `apps/admin/tests/OrderDetailView.test.ts`, `apps/admin/tests/useOrderDetail.test.ts`, `apps/admin/src/router/index.ts`, `apps/admin/src/layout/AppMenu.vue`, `apps/admin/tests/AppMenu.test.ts`, `e2e/support`, `apps/api/CLAUDE.md`, `apps/store/CLAUDE.md`, `apps/admin/CLAUDE.md`, `docs/features/pricing/spec.md`, `docs/features/cart/spec.md`, `docs/features/checkout/spec.md`, `docs/features/order-management/spec.md`

**Zależności.** PROMOTIONS (#96: cena skuteczna i `promotion` w `PriceSnapshot` z `GetCurrentPrices`, `PercentOff`,
blokada doradcza zapisów Pricing) musi być na `main` przed startem tego epiku w AO. CHECKOUT (#90), PAYMENT (#91)
i ORDER-MANAGEMENT (#92) są na `main`.

## Cel i zakres

- **Kod rabatowy**: pracownik tworzy kod procentowy albo kwotowy z okresem ważności, minimalną wartością zamówienia
  i limitem użyć; może go wyłączyć i włączyć.
- **Kasa**: klient wpisuje kod, podsumowanie pokazuje rabat i kwotę po rabacie albo powód odrzucenia kodu.
- **Zamówienie z kodem**: złożenie zapisuje użycie kodu; rabat jest rozłożony na linie, netto i VAT każdej linii są
  liczone z brutto linii po rabacie; zamówienie ma kopię kodu i rabatu; płatność na kwotę po rabacie; e-mail
  z potwierdzeniem ma linię kodu.
- **Zwrot użycia**: anulowanie nieopłaconego zamówienia zwalnia użycie kodu (idempotentnie).
- **Kopia promocji w pozycji zamówienia**: nazwa, procent i cena katalogowa promocji, w której był wariant.
- Panel: widok kodów rabatowych. Dane demo: kody po `make seed`. E2E: kasa z kodem.

Poza zakresem:
- łączenie kilku kodów w jednym zamówieniu;
- kody jednorazowe generowane seriami;
- limit użyć na klienta;
- rate limiting sprawdzania kodów (T2 CHECKOUT, przed publicznym demo);
- programy lojalnościowe;
- kod zapisany w koszyku (kod istnieje tylko w kasie);
- kod na wybrane produkty lub kategorie (kod dotyczy całego zamówienia).

## Decyzje

Decyzje użytkownika (z przygotowania PROMOTIONS #96, opis issue #225):

| # | Decyzja |
|---|---|
| D1 | Kod procentowy (1–90%) albo kwotowy; kwotowy ma obowiązkowe minimum zamówienia większe od kwoty kodu. Okres ważności z obowiązkowym początkiem i opcjonalnym końcem. Opcjonalny limit użyć łącznie. Kod można włączać i wyłączać. Kody zmieniają role `admin` i `marketing`. |
| D2 | Bez limitu użyć na klienta. Jeden kod na zamówienie, liczony od cen skutecznych (kod łączy się z promocją). |
| D3 | Kod wpisywany w kasie, nie zapisywany w koszyku. |
| D4 | Każde anulowanie nieopłaconego zamówienia zwraca użycie kodu, idempotentnie. |
| D5 | E-mail z potwierdzeniem: pozycje po cenie promocyjnej (przed rabatem kodu), linia „Kod <KOD>: −X zł”, suma po rabacie. |
| D6 | Podgląd kodu ma jeden komunikat dla kodu nieznanego, wyłączonego, jeszcze nieobowiązującego i wygasłego; osobne komunikaty „wykorzystany” i „za mała suma”. |
| D7 | Użycie kodu zapisuje zagnieżdżona komenda Pricing w transakcji złożenia, pod blokadą wiersza kodu tylko dla kodu z limitem; kolejność blokad: koszyk → kod → stany. |
| D8 | Netto i VAT każdej linii zamówienia (także bez kodu) są liczone z brutto linii po rabacie. Istniejące zamówienia zachowują zapisane kwoty. |
| D9 | Widok koszyka wycenia linie i sumy jedną wyceną Pricing, tą samą co przy złożeniu. |

| Q1 | Anulowanie opłaconego zamówienia przez pracownika i zwrot towaru nie zwracają użycia kodu (zwrot pieniędzy jest już po rabacie). |
| Q2 | Minimum zamówienia porównywane z sumą brutto cen skutecznych (po promocjach, przed rabatem kodu). |
| Q3 | Tekstu kodu nie da się zmienić, kodu nie da się usunąć (wyłączenie zastępuje usunięcie). Kod `planned` zmienia się w całości. Po starcie (`active`, `expired`, także wyłączony po starcie) zmieniają się tylko koniec, `enabled` i limit; limit tylko, gdy kod już go ma, i nie poniżej liczby aktywnych użyć (zdjęcie limitu dozwolone). Rodzaj, wartość, minimum i początek są po starcie stałe. |
| Q4 | Kod: 3–32 znaki `[A-Z0-9-]`, bez rozróżniania wielkości liter, unikalny na zawsze. Kwota rabatu 1–10 000 zł, minimum do 1 000 000 zł, limit 1–1 000 000, koniec bez maksymalnej długości okresu, początek w przeszłości dozwolony przy tworzeniu. |
| Q5 | Szczegóły zamówienia w koncie klienta i w panelu: wartości pozycji przed rabatem, linia „Kod X: −Y zł”, sumy po rabacie. Kopię promocji w pozycji pokazuje tylko panel. Panel kodów pokazuje liczbę aktywnych użyć, bez listy zamówień; widzi go każdy pracownik. |
| Q6 | Kod odrzucony przy złożeniu: komunikat przy polu, kod zdjęty, podsumowanie wczytane bez kodu, nowy klucz żądania; klient klika ponownie. Sklep nigdy nie składa zamówienia sam na inną kwotę. |
| Q7 | Rabat procentowy zaokrąglony do 0 gr nie jest stosowany i nie zużywa limitu: kod procentowy ma niejawne minimum `ceil(100 / p)` groszy (najmniejsza suma, przy której `floor(S × p / 100) ≥ 1`), łączone z minimum kodu przez `max`. Poniżej klient dostaje istniejący komunikat „za mała suma” z tą kwotą. |

Decyzje DDD i architektoniczne:

| # | Decyzja |
|---|---|
| A1 | Agregat `DiscountCode` w Pricing. Użycie to osobny agregat `DiscountCodeUsage` z własnym repozytorium (unikalne `orderId`, indeks częściowy aktywnych użyć), nie kolekcja kodu: kod bez limitu może mieć dowolnie wiele użyć. Liczba aktywnych użyć z zapytania, bez licznika w wierszu kodu (licznik wymagałby blokady kodu przy zwolnieniu). |
| A2 | `QuoteBasket` w Pricing jest jedyną wyceną dla Cart i Ordering (D9). Ordering kopiuje kwoty linii z wyceny zamiast mnożyć cenę jednostkową; `OrderDiscount` i `PromotionCopy` to kopie w zamówieniu. |
| A3 | Użycie zapisywane przez rekord ORM w komendzie zagnieżdżonej w `PlaceOrder`, z odczytem rekordu kodu od nowa (pod blokadą dla kodu z limitem), jak w Cart; nie DBAL jak Inventory. Uzasadnienie: handler złożenia nigdy nie łapie wyjątku zagnieżdżonej komendy, więc każde odrzucenie wycofuje całą transakcję i `UnitOfWork` nie zapisze wycofanych zmian. Wyjątek zapisany w `apps/api/CLAUDE.md`. |
| A4 | Zwolnienie użycia to komenda Pricing wołana przez Ordering przy wygaśnięciu (jak `ReleaseReservation` Inventory), nie zdarzenie. Pricing nie zależy od Ordering; Deptrac bez nowych kierunków. |

## Bounded contexty i zależności

| Context | Odpowiada za |
|---|---|
| Pricing | kody rabatowe, użycia kodów, wycena koszyka (`QuoteBasket`: ceny skuteczne, rabat, rozkład na linie, netto i VAT linii), zapis i zwolnienie użycia |
| Cart | widok koszyka wyceniany przez `QuoteBasket` (z kodem w podglądzie kasy) |
| Ordering | złożenie z kodem (wycena, zapis użycia, kopia kodu, rabatu i promocji), zwolnienie użycia przy wygaśnięciu, e-mail i widoki zamówienia z rabatem |
| Sklep, panel | wyświetlanie wartości z API; żadnych obliczeń rabatu ani sum |

- Nowych kierunków zależności nie ma: `Cart_Infrastructure → Pricing_Application` i
  `Ordering_Infrastructure → Pricing_Application` już są (`apps/api/deptrac.yaml`). Pricing nie zależy od Cart ani
  Ordering: zwolnienie użycia wywołuje Ordering komendą Pricing (jak `ReleaseReservation` Inventory), nie zdarzeniem.
- Uprawnienia: `DiscountCodeVoter` w `Pricing_Infrastructure` na rolach `AuthenticatedStaffMember` (jak
  `PromotionVoter`).

## Model domenowy (Pricing)

### Kod rabatowy

- `DiscountCodeValue` (tekst kodu): po przycięciu i zamianie na wielkie litery 3–32 znaki `[A-Z0-9-]`; porównanie bez
  rozróżniania wielkości liter (zapis i wyszukiwanie po postaci znormalizowanej). Unikalny wśród wszystkich kodów, także
  wyłączonych i wygasłych.
- `DiscountValue`: `percent(PercentOff)` (1–90, `PercentOff` z PROMOTIONS) albo `amount(Money)` (grosze, 100–1 000 000,
  czyli 1–10 000 zł).
- `MinimumOrder`: brutto w groszach, 1–100 000 000, opcjonalne dla procentowego; dla kwotowego obowiązkowe
  i większe od kwoty kodu (D1).
- `ValidityPeriod {startsAt, endsAt | null}`: `startsAt < endsAt`, czasy z dokładnością do sekundy (UTC).
  `isValidAt(t)` = `startsAt ≤ t` i (`endsAt` = null albo `t < endsAt`).
- `UsageLimit`: 1–1 000 000 albo brak.
- Agregat `DiscountCode` (UUID v7): `code`, `value`, `minimumOrder`, `period`, `usageLimit`, `enabled`, `createdBy`,
  `updatedBy` (aktor z tokena; seed: `null`), `updatedAt`, `version`. Status wyliczany z czasu i flagi: `disabled`,
  `planned` (`now < startsAt`), `active`, `expired` (`now ≥ endsAt`); `disabled` ma pierwszeństwo.

| Metoda | Działanie | Błędy |
|---|---|---|
| `create(id, code, value, minimum, period, limit, actor, now)` | nowy kod, włączony | reguły VO: `InvalidDiscountCode` (`InvalidInput`, 422) |
| `revise(value, minimum, period, limit, enabled, activeUses, actor, now)` | zmiana według reguł edycji (Q3); limit nie mniejszy od liczby aktywnych użyć; nowy koniec (różny od zapisanego) musi być późniejszy niż `now` (natychmiastowe zatrzymanie kodu to wyłączenie) | `DiscountCodeNotEditable` (409, `…/discount-code-not-editable`); `InvalidDiscountCode` |
| `rejectionFor(subtotal, activeUses, now)` | `null` albo `DiscountCodeRejection`: pierwszy spełniony powód w kolejności `invalid` (wyłączony, nieobowiązujący, wygasły), `used_up` (limit osiągnięty), `below_minimum` | |

- `DiscountCodeRejection {reason, minimumOrderGross | null}` daje też `type` problemu i polski `detail` (tabela
  w „API”, kwota w formacie `300,00 zł`): jedyne miejsce tekstów odrzucenia. Cart (podgląd) i Ordering (409) biorą je
  z wyniku Pricing, więc podgląd i 409 mają identyczny `detail`. Kod nieznany daje odrzucenie `invalid` bez kodu.

- Tekstu kodu nie da się zmienić. Kodu nie da się usunąć (wyłączenie zastępuje usunięcie; użycia wskazują na kod).
- Unikalność tekstu: unikalny indeks; naruszenie repozytorium tłumaczy na `DiscountCodeTaken` (409,
  `…/discount-code-taken`, „Taki kod już istnieje.”).

### Użycie kodu

- `DiscountCodeUsage {codeId, orderId, usedAt, releasedAt | null}`: osobna encja, nie kolekcja agregatu (kod bez limitu
  może mieć dowolnie wiele użyć). Aktywne użycie: `releasedAt` = null. Jedno użycie na zamówienie (`orderId` unikalne).
- Zapis (`RedeemDiscountCode`) i zwolnienie (`ReleaseDiscountCodeUsage`) opisuje „Warstwa aplikacji”.

### Wycena koszyka i rozkład rabatu

`BasketQuote::of(lines, prices, code | null, activeUses, now)` (domena Pricing, czysta funkcja):

- Wejście: linie `{variantId, quantity}` (1–50, w kolejności koszyka) i ich ceny skuteczne (cena katalogowa albo
  promocyjna, PROMOTIONS) ze stawką VAT. Linia bez ceny skutecznej nie wchodzi do wyceny (Cart oznacza ją jako
  niekupowalną jak dziś).
- `g_i` = brutto ceny skutecznej × ilość; `S` = suma `g_i` (wartość przed rabatem).
- Kod (gdy podany i znany): `rejectionFor(S, activeUses, now)`; minimum porównywane z `S` (Q2). Minimum
  skuteczne kodu procentowego to `max(minimum kodu, ceil(100 / p))` groszy (Q7), więc zastosowany kod zawsze daje
  `D ≥ 1`; `minimumOrderGross` w odrzuceniu to minimum skuteczne.
  Odrzucony kod daje wycenę bez rabatu z powodem odrzucenia.
- Rabat `D`: procentowy `floor(S × p / 100)`; kwotowy: kwota kodu (minimum > kwota, więc `D < S`).
- Rozkład na linie (metoda największych reszt): `d_i = floor(g_i × D / S)`; brakujące `D − Σ d_i` groszy dostają po
  jednym linie o największej reszcie `g_i × D mod S`, przy remisie wcześniejsza w kolejności koszyka. `Σ d_i = D`
  i `d_i ≤ g_i`. Iloczyny liczone przez `ext-gmp` (już wymagane w `apps/api/composer.json`): przy 50 liniach po 99 sztuk
  w cenie `GrossPrice::MAX` iloczyn `g_i × D` (ok. 4,4·10^21) przekracza `PHP_INT_MAX`. Wyniki (`d_i`, `L_i`, sumy) mieszczą
  się w `int`.
- `D = 0` nie występuje: kod procentowy poniżej minimum skutecznego jest odrzucony (`below_minimum`, Q7), kwotowy ma
  `D ≥ 100`. Każde zapisane użycie odpowiada rabatowi co najmniej 1 grosz.
- Linia po rabacie: `L_i = g_i − d_i` (0 ≤ `L_i` ≤ 99 × `GrossPrice::MAX`); netto i VAT linii liczy nowa fabryka
  `PriceBreakdown::ofLineGross(Money L_i, VatRate)` tą samą formułą co `fromGross` (jedyne miejsce obliczeń VAT;
  `2 · L_i · 10000 + d` mieści się w 64 bitach). `GrossPrice` i jego zakres 1–99 999 999 bez zmian. Bez kodu `d_i = 0`
  i netto liczy się z brutto linii (D8), a nie jako netto jednostkowe × ilość.
- Wynik: linie `{variantId, quantity, unit: {net, vat, gross, vatRateBp, taxClass}, regularUnitGross, promotion
  {promotionId, name, percentOff} | null, grossBeforeDiscount g_i, discountGross d_i, net, vat, gross L_i}`, sumy
  `{subtotalGross S, discountGross D, net, vat, gross}` (sumy linii) i `discount {codeId, code, kind, value, amountGross
  D} | null` albo `rejection {reason, minimumOrderGross | null}`.

## Warstwa aplikacji (Pricing)

### Odczyty

- `QuoteBasket(lines, discountCode | null)` (`query.bus`, 1–50 linii; wywołujący nie pyta o pustą listę): ceny
  skuteczne tym samym odczytem co `GetCurrentPrices` (bez Omnibusa), kod po tekście znormalizowanym, liczba aktywnych
  użyć kodu (tylko gdy kod ma limit; indeks częściowy), `BasketQuote::of` z `now` z zegara. Kod pusty albo z samych
  białych znaków to brak kodu. Tekst w złym formacie (znaki spoza `[A-Za-z0-9-]`, długość poza 3–32) to kod nieznany
  (`invalid`), nie błąd zapytania; więcej niż 64 znaki: `InvalidBasketQuery` (granice HTTP odrzucają wcześniej).
- Panel: `ListDiscountCodes(status?, q?, after, perPage)` (keyset po `(created_at DESC, id)`; `q` to prefiks tekstu
  kodu; `perPage` 1–100, domyślnie 25; zły parametr albo kursor: 400) i `GetDiscountCode(id)`; oba z liczbą aktywnych
  użyć.

### Komendy

- `CreateDiscountCode`, `ReviseDiscountCode(id, expectedVersion, …)` (wersja z widoku, 409
  `DiscountCodeModifiedConcurrently`). Zmiana pod blokadą wiersza kodu (`PESSIMISTIC_WRITE` przez ORM z odświeżeniem
  rekordu), liczba aktywnych użyć czytana pod blokadą. Złożenie blokuje tylko kod z limitem, dlatego dodanie limitu
  kodowi bez limitu jest dozwolone wyłącznie, gdy kod jest `planned` (taki kod nie ma użyć i złożenie go odrzuca), a
  wartość i minimum są stałe po starcie (reguły edycji, Q3). Bez tych reguł edycja w trakcie złożeń mogłaby przekroczyć
  dodany limit albo zapisać w zamówieniu wartość sprzed zmiany.
- `RedeemDiscountCode(codeId, orderId, subtotalGross)` (`command.bus`, tylko zagnieżdżona w `PlaceOrder`): rekord kodu
  jest zawsze czytany od nowa z bazy (odświeżenie rekordu z identity map); kod z limitem pod blokadą
  (`PESSIMISTIC_WRITE` przez ORM, jak koszyk), kod bez limitu bez blokady. Na tym odczycie `rejectionFor(subtotal,
  activeUses, now)` jeszcze raz (`now` z zegara w chwili komendy); odrzucenie: `DiscountCodeRejected(rejection)`
  (Pricing Application); inaczej zapis `DiscountCodeUsage` przez rekord Doctrine.
- `ReleaseDiscountCodeUsage(orderId)` (`command.bus`, zagnieżdżona w `ExpireUnpaidOrder`): aktywne użycie zamówienia
  dostaje `releasedAt = now`; brak użycia albo już zwolnione: bez skutku (idempotencja). Kodu nie blokuje.
- Seed zapisuje kody komendą `CreateDiscountCode` z aktorem `null`.

### Zasady transakcji

- Blokady w złożeniu: wiersz koszyka → wiersz kodu (tylko z limitem) → wiersze stanów (D7). Zwolnienie użycia zmienia
  tylko wiersz użycia (bez blokady kodu), więc kolejność wygaśnięcia (zamówienie → rezerwacja i stany → użycie) nie
  tworzy cyklu z złożeniem.
- Zapis przez rekordy ORM w zagnieżdżonej komendzie jest bezpieczny w tym przepływie, bo handler `PlaceOrder` nigdy nie
  łapie wyjątku zagnieżdżonej komendy: odrzucenie wycofuje całą transakcję (powody wyjątku Inventory w
  `apps/api/CLAUDE.md` dotyczą łapania błędów i wspólnego `UnitOfWork`; rekord kodu jest odświeżany pod blokadą).
- Dwa równoległe złożenia z ostatnim wolnym użyciem kodu z limitem: blokada wiersza kodu szereguje je, drugie dostaje
  `used_up` i całe złożenie się wycofuje.

### Współbieżność: wyłączenie albo zmiana końca kodu bez limitu w trakcie złożenia

Złożenie nie blokuje kodu bez limitu, a pracownik zmienia kod pod blokadą jego wiersza (`ReviseDiscountCode`).
Izolacja READ COMMITTED: każde zapytanie złożenia widzi ostatni zatwierdzony stan kodu. Przypadki według chwili
zatwierdzenia wyłączenia:

| Wyłączenie zatwierdzone | Wynik |
|---|---|
| przed wyceną (krok 4) | wycena odrzuca kod (`invalid`); 409 `discount-code-invalid`, nic nie zapisane |
| między wyceną a `RedeemDiscountCode` (krok 5a) | odczyt od nowa w `Redeem` widzi wyłączony kod; `DiscountCodeRejected` wycofuje całą transakcję (koszyk, numer bez luki poza sekwencją, brak rezerwacji, brak zamówienia, brak użycia); 409 `discount-code-invalid` |
| po odczycie w `Redeem` (albo jeszcze niezatwierdzone w chwili odczytu) | zamówienie powstaje z kodem: złożenie jest uszeregowane przed wyłączeniem, bo kod był ważny w chwili ostatniego sprawdzenia w tej transakcji |

- Zapis użycia nie czeka na edycję kodu: klucz obcy `code_id` bierze `FOR KEY SHARE` na wierszu kodu, a `UPDATE` kodu
  bez zmiany klucza bierze `FOR NO KEY UPDATE`; te blokady się nie wykluczają, więc nie ma zakleszczenia ani
  oczekiwania między złożeniem a edycją.
- Gwarancje spójności:
  - użycie i zamówienie powstają w jednej transakcji: nie ma użycia bez zamówienia ani zamówienia z rabatem bez
    użycia;
  - kopia kodu w zamówieniu (`OrderDiscount`: rodzaj, wartość, kwota) zgadza się z kodem, który przeszedł ostatnie
    sprawdzenie: wartość, rodzaj i minimum aktywnego kodu są stałe (Q3), a kod `planned` jest odrzucany już w wycenie,
    więc wycena i `Redeem` widzą te same wartości;
  - rozkład rabatu i sumy zamówienia pochodzą z jednej wyceny (A2), a kwota jest porównana z `expectedTotalGross`
    przed zapisem (P2 CHECKOUT);
  - każde zamówienie z kodem ma kod, który był włączony i ważny w chwili ostatniego sprawdzenia w transakcji złożenia;
    zamówienie złożone tuż przed wyłączeniem jest poprawne (jak zakup tuż przed końcem promocji).
- Tak samo zachowuje się zmiana końca kodu na wcześniejszy (nowy koniec jest zawsze w przyszłości, więc złożenie
  przed nim jest poprawne) i włączenie kodu (złożenie przed zatwierdzeniem włączenia dostaje `invalid`).

## Ordering

### Model

- `OrderLine` dostaje `discount` (`Money`, ≥ 0), wartości linii `net`, `vat`, `gross` z wyceny (zamiast mnożenia ceny
  jednostkowej) oraz `promotion: PromotionCopy {promotionId, name, percentOff, regularUnitGross} | null` (kopia, nie
  referencja). `grossBeforeDiscount` = `unit.gross × quantity`; niezmiennik: `gross = grossBeforeDiscount − discount`,
  `net + vat = gross`.
- `Order` dostaje `discount: OrderDiscount {code, kind, value, amount} | null` (kopia). Niezmiennik: suma `discount`
  linii = `discount.amount` (0 bez kodu).
- `OrderTotals` bez zmian w roli: sumy wartości linii (już po rabacie).
- Istniejące zamówienia (D8): kolumny linii `line_net`, `line_vat`, `line_gross` już są zapisane; nowa kolumna
  `discount_gross` dostaje 0, promocja i kod `null`. `reconstitute()` czyta zapisane wartości linii, niczego nie
  przelicza.

### Złożenie (`PlaceOrder`)

Komenda dostaje `discountCode | null` (pusty albo biały to `null`); odcisk żądania (`RequestFingerprint`) obejmuje kod
znormalizowany, dopisany do tablicy pól tylko wtedy, gdy nie jest `null`, więc odcisk żądania bez kodu się nie zmienia
(ponowienie z kluczem sprzed wdrożenia działa). Kroki
(zmiany względem `docs/features/checkout/spec.md`):

4. **Kopie danych.** Port `OrderPrices` woła `QuoteBasket(lines, discountCode)` (zamiast `GetCurrentPrices`) i Catalog
   jak dziś. Linia bez ceny albo nieznana Catalogowi: `CartNotOrderable` (409). Kod odrzucony w wycenie:
   `DiscountCodeRejected(reason)` (Ordering, 409, typy niżej), przed porównaniem kwoty.
5. **Kwota.** `totals.gross` (po rabacie) ≠ `expectedTotalGross`: `OrderTotalChanged` (409).
5a. **Użycie kodu** (gdy jest rabat): port `DiscountCodeRedemptions::redeem(codeId, orderId, subtotalGross)` (Pricing
   `RedeemDiscountCode`, zagnieżdżony dispatch); odrzucenie z Pricing adapter tłumaczy na `DiscountCodeRejected`
   Ordering. `orderId` jest nadawany przed tym krokiem.
6.–9. Bez zmian (numer, opróżnienie koszyka, rezerwacja, zapis).

Powtórzenie z tym samym kluczem i odciskiem zwraca istniejące zamówienie bez ponownego użycia kodu.

### Wygaśnięcie (`ExpireUnpaidOrder`)

- Wynik `cancelled`: po zwolnieniu rezerwacji port `DiscountCodeRedemptions::release(orderId)` (Pricing
  `ReleaseDiscountCodeUsage`, zagnieżdżony dispatch), potem zapis zamówienia. Wywołanie także dla zamówienia bez kodu
  (bez skutku), więc Ordering nie musi znać kodu, żeby zwolnić.
- Anulowanie opłaconego zamówienia przez pracownika (ORDER-MANAGEMENT `cancel`) i zwrot towaru nie zwalniają użycia
  (Q1). Pracownik nie anuluje zamówienia `new` (ORDER-MANAGEMENT P2), więc jedynym anulowaniem nieopłaconego zamówienia jest
  wygaśnięcie.

### Płatność

- Payment bierze kwotę z `totals.gross` zamówienia (`GetOrderForPayment`), więc płatność jest na kwotę po rabacie bez
  zmian w Payment.

### E-mail z potwierdzeniem (D5)

- Pozycje: `- <nazwa> × <ilość>: <grossBeforeDiscount>` (cena skuteczna, przed rabatem kodu).
- Przy kodzie po pozycjach linia `Kod <KOD>: −X zł` (`discount.amount`), potem `Suma brutto:` (po rabacie).
- Bez kodu treść jak dziś (wartości pozycji równe `gross` linii).

### Widoki zamówienia

- `OrderView`, `CustomerOrderView`, `BackofficeOrderView`: linia dostaje `grossBeforeDiscount` i `discountGross`;
  zamówienie dostaje `discount {code, kind, value, amountGross} | null`. Panel dodatkowo widzi `promotion` linii
  (Q5).

## Cart

- Nowy port `CartQuote::quote(lines, discountCode | null)` (adapter: `QueryBusCartQuote`, Pricing `QuoteBasket`).
- `CartViewBuilder`: reguła zakupu (cena, potem stan) bez zmian w `ProductOffer::purchasability()`, z cenami
  z `CartPrices` jak dziś. Cena jednostkowa (`unitPrice`), wartość linii (`lineTotal`) i sumy linii kupowalnych pochodzą
  wyłącznie z wyceny (D9), która obejmuje tylko linie kupowalne. To dwa odczyty cen na widok koszyka (świadomie:
  kupowalność zależy od stanu, a rabat od zbioru linii kupowalnych); na granicy promocji mogą się różnić, ale do
  zamówienia trafia tylko wycena z chwili złożenia (P2 CHECKOUT, 409 `OrderTotalChanged`). Linia niekupowalna ma
  `unitPrice` z `CartPrices` jak dziś i `lineTotal = null`.
- `lineTotal` = `grossBeforeDiscount` (`g_i`), `unitPrice` = cena skuteczna jednostkowa; widok koszyka nie pokazuje
  rabatu linii, więc linie plus wiersz kodu sumują się do „Razem”.
- `GetCart(cartRef, discountCode | null)`. `CartTotals`: `subtotalGross`, `discountGross`, `gross` (do zapłaty),
  `currency`, `itemCount`. `CartView.discount`: `{code, status: applied, amountGross, kind, value}` albo `{code,
  status: rejected, reason, detail, minimumOrderGross | null}` (`reason`: `invalid`, `used_up`, `below_minimum`) albo
  `null`. Bez linii kupowalnych (pusty koszyk albo same wykluczone) nie ma wyceny: sumy 0 i `discount = null`, także gdy
  przyszedł kod. Koszyk z liniami wykluczonymi i kupowalnymi dostaje wycenę kodu z linii kupowalnych (złożenia i tak
  nie da się zrobić, P1 CHECKOUT).

## Persystencja

- `pricing_discount_code`: `id uuid` (PK), `code varchar(32)` (unikalny), `kind` (`percent`, `amount`),
  `percent_off smallint NULL`, `amount_gross integer NULL`, `minimum_order_gross bigint NULL`, `starts_at`, `ends_at NULL`
  (`timestamptz`), `usage_limit integer NULL`, `enabled boolean`, `created_at`, `created_by_id`, `created_by_name`,
  `updated_at`, `updated_by_id`, `updated_by_name`, `version`; CHECK: rodzaj zgodny z wypełnioną wartością,
  `percent_off BETWEEN 1 AND 90`, `minimum_order_gross > amount_gross` dla kwotowego, `starts_at < ends_at`; indeks
  `(created_at DESC, id)`.
- `pricing_discount_code_usage`: `id uuid` (PK), `code_id` (FK do kodu), `order_id uuid` (unikalny, bez FK do Ordering),
  `used_at`, `released_at NULL`; indeks częściowy `(code_id) WHERE released_at IS NULL`.
- `ordering_order`: `discount_code varchar(32) NULL`, `discount_kind varchar(8) NULL` (`percent`, `amount`), `discount_value integer NULL`,
  `discount_gross bigint NULL`; CHECK: wszystkie cztery razem albo żadna.
- `ordering_order_line`: `discount_gross bigint NOT NULL DEFAULT 0`, `promotion_id uuid NULL`, `promotion_name
  varchar(100) NULL`, `promotion_percent_off smallint NULL`, `regular_unit_gross integer NULL`; CHECK: pola promocji
  razem albo żadne.
- Migracja nie przelicza istniejących zamówień (D8).
- `pricing:seed-demo` czyści kody i użycia (`DbalPricingReset`).

## API

Błędy w RFC 9457 (`TypedProblem`).

Sklep (`apps/api/openapi.json`):

| Endpoint | Zmiana |
|---|---|
| `GET /api/v1/guest-carts/{cartId}?discountCode=`, `GET /api/v1/me/cart?discountCode=` | opcjonalny parametr (pusty = brak kodu); `totals {subtotalGross, discountGross, gross, currency, itemCount}`, `discount` jak w „Cart”; parametr > 64 znaki: 400 |
| `POST /api/v1/orders`, `POST /api/v1/me/orders` | pole opcjonalne `discountCode: string | null` (brak, `null`, `""` i białe znaki = bez kodu); nie-string albo > 64 znaki: 422 z naruszeniem `discountCode` („Kod rabatowy może mieć najwyżej 64 znaki.”), w kolejności walidacji CHECKOUT; nowe 409: `discount-code-invalid`, `discount-code-used-up`, `discount-code-below-minimum` (z rozszerzeniem `minimumOrderGross`), dopisane do schematu `OrderConflictProblem` |
| `OrderView` (odpowiedź złożenia), `GET /api/v1/me/orders/{id}` | linie `grossBeforeDiscount`, `discountGross`; `discount {code, kind, value, amountGross} | null` |

Komunikaty (D6):

| Powód | `type` | `detail` |
|---|---|---|
| `invalid` | `…/discount-code-invalid` | „Ten kod jest nieprawidłowy albo nieaktywny.” |
| `used_up` | `…/discount-code-used-up` | „Ten kod został już wykorzystany.” |
| `below_minimum` | `…/discount-code-below-minimum` | „Ten kod działa przy zamówieniu od X zł.” (`minimumOrderGross`) |

Panel (`apps/api/openapi-admin.json`, firewall `backoffice`, `private, no-store`; zmiany: `admin` i `marketing`,
pozostali 403; odczyt: każdy pracownik):

| Endpoint | Odpowiedzi |
|---|---|
| `GET /api/v1/admin/discount-codes?status&q&after&perPage` | 200 `{items: [DiscountCode], next}`; `status` jeden z `disabled`, `planned`, `active`, `expired`; `q` przycięty i zamieniony na wielkie litery, 1–32 znaki; 400 |
| `POST /api/v1/admin/discount-codes` `{code, kind, value, minimumOrderGross \| null, startsAt, endsAt \| null, usageLimit \| null, enabled}` | 201 `DiscountCode`; 403; 409 `discount-code-taken`; 422 |
| `GET /api/v1/admin/discount-codes/{id}` | 200 `DiscountCode`; 404 |
| `PUT /api/v1/admin/discount-codes/{id}` `{kind, value, minimumOrderGross, startsAt, endsAt, usageLimit, enabled, version}` (bez `code`) | 200; 403; 404; 409 (wersja, `discount-code-not-editable`); 422 |

- `DiscountCode`: `{id, code, kind: percent | amount, value, minimumOrderGross | null, startsAt, endsAt | null,
  usageLimit | null, activeUses, enabled, status, version}`; `value` to procent (1–90) dla `percent` i grosze
  (100–1 000 000) dla `amount`.
- Czasy w RFC 3339 ze strefą (zapis i odpowiedź w UTC); `startsAt` w przeszłości jest dozwolony przy tworzeniu (kod od
  razu aktywny). Typy ścisłe jak `PUT` ceny (`docs/features/pricing/spec.md`).
- Kolejność sprawdzeń: 401 (brak tokena albo token `shop`), 403 (rola), 400 (parametry listy), 404, 422 (treść), 409.
- Komunikaty 422 (naruszenie przy polu): `code` „Kod ma 3–32 znaki: litery, cyfry i myślnik.”; `value` „Rabat
  procentowy to 1–90%.” albo „Kwota rabatu to 1–10 000 zł.”; `minimumOrderGross` „Kod kwotowy wymaga minimum
  większego od kwoty rabatu.” albo „Minimum to 0,01–1 000 000 zł.”; `endsAt` „Koniec musi być po początku.”;
  `usageLimit` „Limit to 1–1 000 000 użyć.” albo „Limit nie może być mniejszy od liczby użyć (N).”.
- `DiscountCodeNotEditable` (409) ma `detail` „Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był
  użyty.”.
| `GET /api/v1/admin/orders/{id}` (istniejący) | linie `grossBeforeDiscount`, `discountGross`, `promotion {name, percentOff, regularUnitGross} | null`; `discount | null` |

## Sklep (`apps/store`)

- Kasa: pole „Kod rabatowy” z przyciskiem „Zastosuj” pod podsumowaniem. Zastosowanie przeładowuje podsumowanie z
  `discountCode` (BFF koszyka przekazuje parametr). Zastosowany kod: linia „Kod <KOD>: −X zł”, „Razem” po rabacie
  i przycisk „Usuń kod”. Odrzucony: `detail` przy polu, podsumowanie bez rabatu.
- Złożenie wysyła `discountCode` zastosowanego kodu i `expectedTotalGross` = `totals.gross` podsumowania. BFF
  (`CUSTOMER_ORDER_FIELDS` w `apps/store/src/checkout/api.ts`) przepuszcza `discountCode`; `problems.ts` zna trzy nowe
  typy 409. 409 z typem kodu (Q6): `detail` przy polu kodu, kod zdjęty, podsumowanie wczytane bez kodu, nowy klucz;
  zamówienie składa dopiero ponowne kliknięcie klienta.
- `Idempotency-Key`: zastosowanie i usunięcie kodu zmieniają odcisk żądania, więc dają nowy klucz (jak zmiana danych
  formularza, `docs/features/checkout/spec.md`); po 409 z typem kodu też nowy klucz. Po 409 `OrderTotalChanged`
  podsumowanie wczytuje się ponownie z tym samym kodem.
- Koszyk z liniami wykluczonymi zastępuje formularz jak dziś (pole kodu znika). 400 z koszyka (kod > 64 znaki):
  komunikat przy polu, podsumowanie bez kodu; pole ma `maxLength` 64.
- Kod nie przeżywa przeładowania strony kasy (D3); koszyk (strona `/cart`) bez zmian w wyglądzie.
- Konto klienta, szczegóły zamówienia: wartości pozycji przed rabatem, linia kodu, sumy po rabacie.

## Panel (`apps/admin`)

- Menu „Kody rabatowe” (`/discount-codes`). Przyciski zmian widzą tylko `admin` i `marketing`.
- Lista z filtrem statusu, wyszukiwaniem po początku kodu i stronicowaniem kursorami; kolumny: kod, rabat, minimum,
  okres (w `Europe/Warsaw`), użycia (`activeUses` / limit), status.
- Formularz: kod (tylko przy tworzeniu), rodzaj i wartość, minimum, początek, koniec (opcjonalny), limit (opcjonalny),
  włączony. Pola niedostępne według reguł edycji są zablokowane. 409 `discount-code-taken` przy polu kodu, inne 409:
  `detail` i odświeżenie.
- Szczegóły zamówienia (`OrderDetailView`): wartość pozycji przed rabatem, kopia promocji przy pozycji, linia kodu,
  sumy po rabacie.

## Dane demo (`make seed`)

- `fixtures/discount-codes.yaml`, ładowane przez `pricing:seed-demo` po cenach i promocjach: `WITAJ10` (10%, bez
  minimum, bez limitu i końca, początek w chwili seeda), `MINUS50` (50 zł od 300 zł, początek w chwili seeda, bez
  końca), `LATO15` (15%, okres od 60 do 30 dni przed seedem, wygasły). Czasy w pliku są względne (przesunięcie od chwili
  seeda). Plik sprawdzany przed resetem (błąd kończy seed bez zmian w danych).
- E2E nie używa kodów demo: tworzy własny kod przez API panelu (helper w `e2e/support`) z unikalnym tekstem na
  uruchomienie.

## Scenariusze błędów

| Sytuacja | Wynik |
|---|---|
| Kod nieznany, wyłączony, przed początkiem, po końcu | podgląd: „Ten kod jest nieprawidłowy albo nieaktywny.”; złożenie: 409 `discount-code-invalid` |
| Limit użyć osiągnięty (także między podglądem a złożeniem, także równoległe złożenia) | 409 `discount-code-used-up`, bez zamówienia; jedno z równoległych złożeń wygrywa |
| Suma przed rabatem poniżej minimum | „Ten kod działa przy zamówieniu od X zł.” / 409 `discount-code-below-minimum` |
| Cena lub kod zmienione między podglądem a złożeniem (kwota inna) | 409 `OrderTotalChanged` |
| Zamówienie z kodem nieopłacone w terminie | anulowane, użycie kodu zwolnione (licznik spada) |
| Powtórzone wygaśnięcie | bez zmian (idempotencja) |
| Powtórzone złożenie (ten sam klucz) | to samo zamówienie, jedno użycie kodu |
| Pracownik `support`/`warehouse` zmienia kod | 403 |
| Tekst kodu zajęty | 409 `discount-code-taken` |
| Limit poniżej liczby aktywnych użyć | 422 |

## Kryteria akceptacji

1. Kod procentowy i kwotowy obniża sumę zamówienia według reguł D1–D2; rabat liczony od cen skutecznych (z promocją).
2. Podgląd w kasie pokazuje rabat albo jeden z trzech komunikatów (D6).
3. Zamówienie z kodem ma kopię kodu i rabatu, rabat rozłożony na linie (suma rozkładu = rabat), netto i VAT każdej
   linii z brutto po rabacie (`PriceBreakdown`); płatność i e-mail na kwotę po rabacie, e-mail z linią kodu.
4. Widok koszyka i złożenie liczą wartości i sumy tą samą wyceną `QuoteBasket`.
5. Limit użyć nie jest przekraczany przy równoległych złożeniach; anulowanie nieopłaconego zamówienia zwalnia użycie
   idempotentnie.
6. Pozycja zamówienia ma kopię promocji (nazwa, procent, cena katalogowa); istniejące zamówienia mają niezmienione kwoty.
7. Kody zmieniają tylko `admin` i `marketing`; każdy pracownik je widzi; Deptrac bez nowych kierunków.
8. Po `make seed` działają kody demo.
9. Przechodzą `make -C apps/api check`, `make -C apps/api test`, `check` i `test` sklepu i panelu oraz `make e2e`.

## Testy

- Jednostkowe (TDD, `apps/api/tests/Unit/Pricing`, `Unit/Ordering`, `Unit/Cart`): VO kodu (format, wartości, minimum,
  okres, limit); `DiscountCode` (statusy, `rejectionFor` dla każdego powodu, granice okresu, reguły edycji, limit
  poniżej użyć); `BasketQuote` (bez kodu: netto z brutto linii; procent i kwota; rozkład z resztami i remisem; linia
  1 grosz; maksymalne kwoty; promocja w linii); `PlaceOrder` (kod odrzucony w wycenie i przy użyciu, powtórzenie bez
  drugiego użycia, odcisk z kodem); `ExpireUnpaidOrder` (zwolnienie, zamówienie bez kodu); `Order` (niezmienniki
  rabatu); tekst e-maila z kodem i bez.
- Integracyjne (PostgreSQL): repozytoria kodów i użyć, unikalność tekstu, migracja na istniejących zamówieniach
  (kwoty bez zmian), reset demo, seed z błędnym plikiem.
- Współbieżność (5 rund): dwa równoległe złożenia z ostatnim wolnym użyciem: jedno zamówienie i 409 `used_up`;
  wygaśnięcie równoległe ze złożeniem z tym samym kodem: bez zakleszczenia; wyłączenie kodu bez limitu równoległe ze
  złożeniami: każde złożenie kończy się zamówieniem z użyciem albo 409 `discount-code-invalid` bez zamówienia i bez
  użycia (liczba użyć = liczba zamówień z kodem), bez zakleszczenia i bez oczekiwania edycji na złożenie; dodanie
  limitu aktywnemu kodowi bez limitu: 409 `discount-code-not-editable`.
- Q7 (jednostkowe): kod procentowy przy sumie poniżej `ceil(100 / p)` daje `below_minimum` z tą kwotą, przy równej
  daje rabat 1 grosz; minimum kodu większe od niejawnego wygrywa.
- Funkcjonalne API: koszyk z `discountCode` (zastosowany, każdy powód odrzucenia, 400), złożenie z kodem (201, każde
  409, powtórzenie), wygaśnięcie zwalnia użycie, endpointy panelu (200/201, 400, 401, 403 `support` i `warehouse`, 404,
  409, 422), widoki zamówienia z rabatem.
- Architektura: Deptrac bez nowych reguł; próby obejścia (pliki tymczasowe muszą dać błąd): `Pricing_* → Ordering_*`,
  `Pricing_* → Cart_*`.
- Vitest: sklep – kasa (zastosowanie, każdy komunikat, usunięcie kodu, złożenie z kodem, 409 kodu), szczegóły
  zamówienia z rabatem; panel – lista, formularz (blokady pól, czasy w `Europe/Warsaw`), widoczność przycisków według
  ról, 409, szczegóły zamówienia z rabatem i promocją.
- E2E (Playwright, `discount-codes.spec.ts`): pracownik `marketing` tworzy kod przez API panelu; gość w kasie widzi
  komunikat „za mała suma”, potem zastosowany kod, składa zamówienie, płaci kwotę po rabacie (symulator) i dostaje
  e-mail z linią kodu (Mailpit).

## Ryzyka i ograniczenia

- Kod z limitem szereguje złożenia z tym kodem (blokada wiersza na czas transakcji złożenia). Popularny kod z limitem
  w szczycie wydłuża złożenia; kod bez limitu nie blokuje. Wydajność nie jest potwierdzona testami obciążeniowymi.
- Liczba aktywnych użyć jest liczona zapytaniem (indeks częściowy) przy każdej wycenie kodu z limitem.
- Bez rate limitingu (T2) kody można zgadywać przez podgląd koszyka.
- Zmiana netto linii bez kodu (D8) może różnić się o grosze od dotychczasowego „netto jednostkowe × ilość”.

## Dokumentacja do aktualizacji razem z implementacją

- `docs/features/pricing/spec.md`: kody rabatowe, użycia, `QuoteBasket` i rozkład rabatu; „Poza zakresem” bez kodów.
- `docs/features/cart/spec.md`: widok koszyka z wyceny `QuoteBasket`, `discountCode`, nowe sumy.
- `docs/features/checkout/spec.md`: kod w złożeniu, kroki 4–5a, nowe 409, wartości linii z wyceny, e-mail z kodem;
  „Poza zakresem” bez kodów rabatowych.
- `docs/features/order-management/spec.md`: wygaśnięcie zwalnia użycie kodu; szczegóły zamówienia z rabatem i promocją.
- `apps/api/CLAUDE.md`: kolejność blokad koszyk → kod → stany, komendy `RedeemDiscountCode`
  i `ReleaseDiscountCodeUsage`, nowe typy problemów, `pricing:seed-demo` z kodami.
- `apps/store/CLAUDE.md` i `apps/admin/CLAUDE.md`: kod w kasie, widok kodów w panelu.

