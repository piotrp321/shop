# DELIVERY (etap 1): metody i koszt dostawy, numer przesyłki

Pierwszy etap dostawy z punktu 2 „Zakresu po MVP” (`docs/vision.md`, „ścieżka sprzedaży”). Dziś zamówienie nie ma
dostawy: suma zamówienia to suma pozycji (`docs/features/checkout/spec.md`, P3), a wysyłka nie zapisuje numeru
przesyłki. Po epiku administrator definiuje w panelu metody dostawy kurierem z ceną i progiem darmowej dostawy, koszyk
pokazuje koszt dostawy i brakującą do progu kwotę, klient wybiera metodę w kasie, zamówienie przechowuje kopię metody
i koszt z podziałem VAT, a pracownik przy wysyłce wpisuje numer przesyłki, który klient dostaje w e-mailu i na stronie
zamówienia.

Issues: #120
Zakres: `apps/api/src/Delivery` (nowy), `apps/api/src/Pricing/Domain/Basket`, `apps/api/src/Pricing/Application/Query`,
`apps/api/src/Cart`, `apps/api/src/Ordering`, `apps/api/src/DemoData/Verify`, `apps/api/config`, `apps/api/deptrac.yaml`,
`apps/api/migrations`, `apps/api/fixtures`, `apps/api/bin/seed-demo`, `apps/api/tests`, `apps/api/openapi.json`,
`apps/api/openapi-admin.json`, `apps/api/CLAUDE.md`, `apps/store/src/api`, `apps/store/src/cart`,
`apps/store/src/checkout`, `apps/store/src/account`, `apps/admin/src`, `apps/admin/tests`, `e2e/support`, `e2e/tests`,
`docs/vision.md`, `docs/features/checkout/spec.md`, `docs/features/cart/spec.md`,
`docs/features/order-management/spec.md`, `docs/features/pricing/spec.md`, `docs/features/admin-screens/spec.md`,
`docs/features/admin-features/spec.md`, `docs/features/demo-data/spec.md`

## Integracja czy własna budowa

`docs/vision.md:360-362` wymaga, żeby epik z dojrzałym rozwiązaniem zewnętrznym zaczynał od porównania.

| Etap | Co jest do zbudowania | Rozstrzygnięcie |
|---|---|---|
| 1 (ten epik) | własny cennik metod kurierskich (stała cena, próg), wybór w kasie, numer przesyłki wpisywany ręcznie | Nie ma czego integrować: cennik i wybór metody to dane sklepu. Kurier działa bez integracji: etykietę nadaje się ręcznie w panelu przewoźnika, a numer przesyłki wpisuje pracownik (`docs/reports/ecommerce-functional-audit-2026-11-18.md:499`). Bez nowych zależności. |
| 2 (osobny epik, bez terminu) | Paczkomaty i punkty odbioru (mapa), etykiety, statusy przesyłek | InPost ShipX z Geowidget v5 bezpośrednio albo broker (Furgonetka, Apaczka). Wybór zapada przed etapem 2 po rozpoznaniu technicznym; źródła i stan rozpoznania: `docs/reports/ecommerce-functional-audit-2026-11-18.md:495-501` (dokumentacji API brokerów nie zweryfikowano). |

## Cel i zakres

- Nowy moduł `Delivery`: metody dostawy (nazwa, opis czasu dostawy, cena brutto, próg darmowej dostawy, aktywność,
  kolejność, szablon linku śledzenia) i wycena dostawy dla wartości towarów.
- API panelu `/api/v1/admin/delivery-methods` (lista, dodanie, odczyt, zmiana) i ekran panelu „Dostawa” dla roli
  `admin`.
- Podział kosztu dostawy na stawki VAT towarów zamówienia w Pricing.
- Koszyk: `delivery` z najniższym kosztem („Dostawa od X zł”), brakującą do progu kwotą i opcjami z sumą do zapłaty.
- Kasa: wymagany wybór metody (`deliveryMethodId`), kontrola dostępności metody i sumy z dostawą przy złożeniu.
- Zamówienie: kopia metody, koszt z podziałem VAT, suma z dostawą (płatność i zwrot), dostawa w widokach i w e-mailu
  z potwierdzeniem; statystyki sprzedaży bez dostawy.
- Wysyłka: opcjonalny numer przesyłki, przewoźnik (nazwa metody) i link śledzenia w e-mailu o wysyłce, na stronie
  zamówienia klienta i w panelu; dodanie albo poprawienie numeru po wysyłce w panelu.

Kryterium ukończenia: koszyk powyżej progu, który po wpisaniu kodu rabatowego spada poniżej progu, ma płatną dostawę
w odczycie koszyka z kodem (podgląd koszyka w kasie), w podsumowaniu kasy i w złożonym zamówieniu (P6); strona koszyka
nie ma pola kodu.

## Poza zakresem

| Pozycja | Powód |
|---|---|
| Paczkomaty, punkty odbioru, odbiór osobisty, etykiety, integracja z przewoźnikiem albo brokerem, webhooki statusów przesyłki | Etap 2 (P3, P11). Brak Paczkomatów nie blokuje startu sprzedaży (decyzja właściciela). |
| Osobne stany zamówienia, płatności i przesyłki | P1: wraca z etapem 2 albo z PAYMENT-OPERATOR (#769). |
| Pobranie (płatność przy odbiorze) | Decyzja właściciela. |
| Zwroty częściowe, zwrot bez kosztu dostawy | P13. |
| Kody rabatowe na darmową dostawę | Decyzja właściciela; kod nie obniża kosztu dostawy (P6). |
| Cena zależna od wagi lub gabarytu | P5: katalog nie ma wagi. |
| Wysyłki częściowe | P10. |
| Zapisane adresy klienta | #117. |
| Dostawa poza Polskę | Decyzja właściciela; adres zamówienia zostaje w Polsce. |
| Usuwanie metod | P16: metody się wyłącza. |

## Decyzje

Decyzje właściciela:

| # | Decyzja |
|---|---|
| P1 | Zamówienie ma jeden status jak dziś (`new`, `paid`, `shipped`, `delivered`, `cancelled`, `returned`). Dane przesyłki (numer) są zapisane w zamówieniu; nie ma osobnego stanu przesyłki. Rozdzielenie stanów zamówienia, płatności i przesyłki jest poza zakresem i wraca z etapem 2 (webhooki przewoźnika) albo z PAYMENT-OPERATOR (#769). |
| P2 | Nowy moduł (bounded context) `Delivery`: metody dostawy, cennik, próg darmowej dostawy, wycena. Dopisany do `docs/vision.md`. |
| P3 | Etap 1 ma tylko dostawę pod adres (kurier); metody definiuje administrator. Paczkomaty, punkty odbioru, odbiór osobisty, etykiety i integracja z przewoźnikiem albo brokerem to etap 2: osobny epik bez ustalonego terminu, w kolejności priorytetów właściciela. Brak Paczkomatów nie blokuje startu sprzedaży. |
| P4 | VAT od dostawy: brutto dostawy dzieli się proporcjonalnie do brutto towarów zamówienia po rabatach w poszczególnych stawkach VAT, a każdy udział dostaje stawkę swoich towarów (nie 23% z urzędu ani stawkę z faktury przewoźnika). Podstawa: art. 29a ust. 6 pkt 2 ustawy o VAT (Dz.U. 2025 poz. 775) i interpretacje indywidualne Dyrektora KIS: z 4.02.2022, 0114-KDIP4-2.4012.862.2021.1.MC (stanowisko z kluczem opartym o ceny brutto towarów uznane za prawidłowe), oraz z 18.03.2024, 0114-KDIP4-1.4012.66.2024.2.DP (stawki towarów i podział proporcjonalny). Reguła dotyczy modelu: krajowa sprzedaż towarów opodatkowanych VAT, dostawę organizuje sprzedawca jako element sprzedaży, jedna stała cena brutto metody. Właściciel nie ma księgowej; regułę przyjął na podstawie tych źródeł. |
| P5 | Jedna stała cena brutto na metodę. Bez cen zależnych od wagi i gabarytu. |
| P6 | Darmowa dostawa: opcjonalny próg na metodę, porównywany z wartością towarów po rabatach. Kod rabatowy nigdy nie obniża samego kosztu dostawy, ale może obniżyć wartość towarów poniżej progu i wtedy klient traci darmową dostawę. Odczyt koszyka z kodem (podgląd koszyka w kasie) i podsumowanie kasy pokazują to od razu po zastosowaniu kodu (koszt dostawy, „Do darmowej dostawy brakuje Y zł”); strona koszyka nie ma pola kodu. Kryterium akceptacji: koszyk powyżej progu spada poniżej niego po wpisaniu kodu, a dostawa staje się płatna w odczycie koszyka z kodem (podgląd koszyka w kasie), w podsumowaniu kasy i w zamówieniu. |
| P7 | Ekran panelu „Dostawa” dla roli `admin`: nazwa, opis (czas dostawy), cena brutto, próg „darmowa od”, aktywność, kolejność wyświetlania, szablon linku śledzenia. |
| P8 | Koszyk pokazuje „Dostawa od X zł” (najtańsza aktywna metoda) i „Do darmowej dostawy brakuje Y zł”, policzone przez API. Metodę wybiera się dopiero w kasie. |
| P9 | Numer przesyłki wpisuje pracownik przy „Wyślij”, opcjonalnie. Przewoźnik to nazwa metody (bez osobnego pola przewoźnika). Link śledzenia jest w e-mailu „wysłane” i na stronie zamówienia klienta. |
| P10 | „Dostarczone” i brak wysyłek częściowych bez zmian. |
| P11 | Bez integracji z przewoźnikiem i bez nowych zależności; porównanie integracji z budową: sekcja „Integracja czy własna budowa”. |
| P12 | Implementacja zaczyna się po scaleniu epiku ASYNC-RELIABILITY do `main` (gałąź `feat/async-reliability`); przed startem `paths` planu są sprawdzane na `main`. |
| P13 | Anulowanie przez pracownika i zwrot oddają pełną kwotę zamówienia z dostawą (dzisiejsze `Refund::due(totals->gross)`, `Order.php:270,288`, teraz jawna decyzja). Zwroty częściowe są poza zakresem. |
| P14 | Statystyki sprzedaży (podsumowania sprzedaży na pulpicie) liczą towary po rabatach, BEZ dostawy. „Suma zamówień” klienta w panelu liczy pełne kwoty zamówień Z dostawą. Anulowanie i zwrot nie zmieniają żadnego z tych podsumowań, jak dziś (W7). |
| P15 | Wdrożenie: `deliveryMethodId` jest wymagany od pierwszego wydania, bez warstwy zgodności. Żądanie bez klucza dostaje 422 przy polu `deliveryMethodId` z komunikatem „Kasa została zaktualizowana. Odśwież stronę i wybierz sposób dostawy.”. Stara karta kasy pokazuje go bez zmian po swojej stronie, a klient sam odświeża stronę. Nowa kasa sprawdza wybór przed wysłaniem („Wybierz sposób dostawy.”). Stara karta panelu: wysyłka bez klucza `trackingNumber` dostaje 422 do przeładowania karty (zaakceptowane). |
| P16 | Metod się nie usuwa, tylko wyłącza. Limit: 20 aktywnych metod (lista w panelu bez stronicowania, ograniczona wycena). |
| P17 | Wygenerowane zamówienia historii demo nie mają dostawy (koszt 0, bez metody). Istniejące zamówienia: koszt 0, bez metody; wszystko działa jak dziś. |
| P18 | Numer przesyłki można dodać albo poprawić po wysyłce: te same role, które dziś wysyłają zamówienie (`admin` i `warehouse`, `docs/features/order-management/spec.md`, P6), tylko w zamówieniu `shipped` albo `delivered`. Bez zdarzenia, e-maila, powiadomienia i wpisu w historii (W8a). |
| P19 | Na obecnym demo zamówienie bez metody dostawy może powstać tylko w krótkim oknie wymiany wersji podczas wdrożenia epiku; po zakończeniu wdrożenia każde nowe zamówienie ma metodę dostawy. Zamówienia sprzed epiku i wygenerowana historia demo zostają bez metody (P17). Sprawdzenie po wdrożeniu: sekcja „Wdrożenie i zgodność wersji”. |

Założenia architektoniczne:

| # | Założenie |
|---|---|
| A1 | `Delivery` ma agregat `DeliveryMethod` zapisywany przez rekord ORM (ogólna zasada persystencji), bez zdarzeń domenowych i bez publicznego endpointu: sklep dostaje metody wyłącznie w odpowiedzi koszyka. Od `Delivery` nie zależy żaden moduł domenowo; `Cart_Infrastructure` i `Ordering_Infrastructure` czytają `Delivery_Application` przez `query.bus` (porty w swoich warstwach Application, adaptery w Infrastructure, jak `QueryBusCartPrices`). |
| A2 | Wycena dostawy jest w jednym miejscu: zapytanie `QuoteDelivery(goodsGross)` w `Delivery_Application`. Koszyk i złożenie zamówienia liczą z niego tę samą wartość, więc koszt w koszyku i w zamówieniu nie może się rozjechać przy tych samych danych. |
| A3 | Podział kosztu na stawki VAT robi Pricing (jedyne miejsce liczenia netto i VAT, `Pricing/Domain/Price/PriceBreakdown.php:106-111`): czysta funkcja `DeliveryCostSplit::of` za zapytaniem `Pricing\Application\Query\SplitDeliveryCost`. Ordering woła je przez nową metodę portu `OrderPrices` (`Ordering/Infrastructure/Pricing/QueryBusOrderPrices.php`); Deptrac już dopuszcza `Ordering_Infrastructure → Pricing_Application`. |
| A4 | Zamówienie trzyma kopię dostawy (value object `OrderDelivery`), a `OrderTotals` obejmuje dostawę. Płatność (`GetOrderForPaymentHandler.php:38`, `Order::markPaid`), zwrot (`Order.php:270,288`) i suma klienta biorą sumę z dostawą bez zmian kodu; wartość towarów zostaje osobno dostępna dla statystyk sprzedaży (P14). |
| A5 | Ograniczenia CHECK na dużej tabeli (`ordering_order`) dodaje migracja jako `NOT VALID`, a sprawdza osobna migracja `VALIDATE CONSTRAINT`. Nowa zasada w `apps/api/CLAUDE.md`. |
| A6 | Numer przesyłki to nowy klucz `trackingNumber` żądania zmiany statusu; `Order::ship` go zapisuje. Zdarzenie `OrderShipped` się nie zmienia: `SendOrderShipped` wczytuje zamówienie i buduje `OrderView` (`Ordering/Application/EventHandler/SendOrderShipped.php:39-43`), więc e-mail dostaje numer z zamówienia. |
| A7 | Kroki dostawy w złożeniu zamówienia to odczyty bez blokad w transakcji `command.bus` złożenia (`docs/features/checkout/spec.md`, A3); kolejność blokad (`apps/api/CLAUDE.md:165-168`) się nie zmienia. |
| A8 | Limit 20 aktywnych metod sprawdza handler przy zapisie bez blokady. Dwóch administratorów aktywujących naraz dwudziestą metodę może dać 21 aktywnych; skutek to o jedną dłuższa lista i wycena, więc mechanizm szeregowania nie jest potrzebny („Ryzyka i ograniczenia”). |
| A9 | Unikalność nazwy bez względu na wielkość liter daje indeks unikalny na `lower(name)` (baza w locale UTF-8 obrazu `postgres:18.6`, więc `lower` obejmuje polskie litery; potwierdza to test z „Ł” i „ł”). Konflikt to 409 z typem jak `DiscountCodeTaken` (`Pricing/Domain/DiscountCode/DiscountCodeTaken.php:13-15`). |

## Wymagane przed startem

- **Reguła VAT (P4).** Przyjęta przez właściciela na podstawie źródeł z P4; DELIVERY-2 nie czeka na konsultację.
- **ASYNC-RELIABILITY na `main` (P12).** Gałąź epiku powstaje od `origin/main` po scaleniu `feat/async-reliability`.
  Orkiestrator sprawdza przed startem, czy pliki i wiersze, na które powołuje się ta specyfikacja, i `paths` planu
  zgadzają się z `main` (ASYNC-RELIABILITY zmienia m.in. `Ordering/Application`, `messenger.yaml` i dodaje test
  kształtu zdarzeń); rozbieżność to poprawka planu przed startem zadań.
- **Zasoby spoza repo: brak.** Rola `admin` istnieje (`StaffUser.php:20`, `infra/keycloak/realms/backoffice.json:92`),
  więc bez zmian w Keycloaku i w chartcie.
- **użytkownik, po zakończeniu rolloutu na serwerze:** dodanie aktywnych metod dostawy w panelu „Dostawa” (sekcja „Wdrożenie i zgodność wersji”);
  do tego czasu kasa na serwerze nie przyjmuje zamówień.

## Stan obecny

- **Suma zamówienia = suma pozycji.** `OrderTotals::of($lines)` sumuje tylko linie (`Ordering/Domain/OrderTotals.php:25-39`),
  wołane w `Order.php:99,136` i w kontroli sumy złożenia (`PlaceOrderHandler.php:139`). Suma brutto zasila płatność
  (`GetOrderForPaymentHandler.php:38`, `Order::markPaid`, `Order.php:190`), zwrot (`Order.php:270,288`) i podsumowania
  (`MarkOrderPaidHandler.php:64,67`). Checkout spec, P3: „Bez wyboru metody dostawy i bez kosztu dostawy”.
- **Złożenie.** `PlaceOrderHandler` po blokadzie koszyka, powtórzeniu, wielkości zamówienia gościa i limicie
  nieopłaconych zamówień wycenia koszyk (`:134`), kopiuje linie, odrzuca kod (`:136-138`), porównuje sumę linii
  z `expectedTotalGross` (`:139`, 409 `OrderTotalChanged` „Kwota zamówienia się zmieniła. Sprawdź podsumowanie.”),
  potem zapisuje użycie kodu, numer, czyści koszyk i rezerwuje stany. Odcisk żądania
  (`Ordering/Domain/RequestFingerprint.php:25-40`) dopisuje kod rabatowy tylko, gdy jest. Treść żądania czyta
  `#[MapRequestPayload]` (`CustomerOrderController.php:60`): brak klucza daje `null`, więc pierwsze ograniczenie
  pola (`NotNull`) daje komunikat brakującego pola.
- **Wycena i VAT.** Netto z brutto HALF_UP w liczbach całkowitych i VAT = brutto − netto są tylko w
  `Pricing/Domain/Price/PriceBreakdown.php:106-111`; `ofLineGross` przyjmuje 0…`MAX_LINE_GROSS` (`:52-60`, 99 ×
  99 999 999). Rozkład rabatu metodą największych reszt (`gmp`, remis dla wcześniejszej linii) to publiczne
  `BasketQuote::allocate` (`Pricing/Domain/Basket/BasketQuote.php:225-246`); jego dokumentacja zakłada D < S. Towary
  po rabacie mają zawsze co najmniej 1 grosz, pojedyncza linia może mieć 0.
- **Koszyk.** `CartViewBuilder` (`Cart/Application/View/CartViewBuilder.php:53,96`) liczy sumy z wyceny Pricing tylko,
  gdy są linie do kupienia. Kod rabatowy nie jest zapisany w koszyku: odczyt koszyka przyjmuje `discountCode` do
  podglądu w kasie (`docs/features/cart/spec.md:13-15`). Strona koszyka nie ma pola kodu; kod stosuje się tylko w kasie.
  Odpowiedzi koszyka są `private, no-store`. Pusty koszyk BFF: `apps/store/src/cart/bff.ts:29-35`.
- **Kasa w sklepie.** Formularz jest stanem Reacta (`CheckoutPage.tsx:98`); po wczytaniu strony gość dostaje pusty
  formularz, a klient imię i nazwisko z konta (`prefilled`, `:423-425`). Odświeżenie strony gubi wpisane dane
  i zastosowany kod rabatowy (`appliedCode`, `:109`); przeglądarka może podpowiedzieć pola z autouzupełniania
  (atrybuty `autoComplete`, `:44-50`), ale tego nie gwarantuje. Naruszenie pola, którego formularz nie zna, trafia
  do komunikatu nad formularzem (`:273-280`). `expectedTotalGross` = `totals.gross` koszyka (`:241`). BFF przekazuje
  do API tylko pola z listy (`apps/store/src/checkout/api.ts:11-12`, `pickFields`). Mapowanie typów 409 na powody
  (`apps/store/src/checkout/problems.ts`) używa `satisfies Record<OrderConflictType, …>`, więc nowy typ w kontrakcie
  bez wpisu tam to błąd `tsc`.
- **Wysyłka.** Żądanie zmiany statusu (`ChangeOrderStatusRequest`, czytane przez `#[RequiredKeysBody]`) wymaga każdego
  parametru konstruktora jako klucza (`Shared/UI/Http/RequiredKeysBodyResolver.php:86-89`, „To pole jest wymagane.”).
  `Order::ship(staff, note, now)` (`Order.php:230`). E-mail o wysyłce (`Ordering/Application/OrderShippedText.php:20-35`)
  podaje adres bez przewoźnika i numeru. `docs/features/order-management/spec.md:30`: numer przesyłki poza zakresem.
- **Podsumowania.** `MarkOrderPaid` przy wyniku `paid` dopisuje `total_gross` do sprzedaży godzinowej
  (`SalesHours::add`, `MarkOrderPaidHandler.php:64`; tabela `ordering_sales_hour`) i do `paid_gross` klienta
  (`CustomerSummaries::addPayment`, `:67`; tabela `ordering_customer`). Przebudowy liczą to samo z `ordering_order`:
  `SUM(total_gross)` zamówień z `paid_at` w godzinie (`DbalSalesHours.php:102-108`) i
  `SUM(total_gross) FILTER (WHERE paid_at IS NOT NULL)` klienta (`DbalCustomerSummaries.php:154`). Anulowanie przez
  pracownika i zwrot niczego z tych podsumowań nie odejmują (`docs/features/admin-features/spec.md:164-166`,
  „sprzedaż brutto”), a `paid_at` zamówienia anulowanego albo zwróconego zostaje, więc przebudowa liczy je tak samo.
- **Dane demo.** `OrdersLoader` (`DemoData/Orders/OrdersLoader.php:23-28`) ładuje zamówienia przez `COPY` z jawną listą
  kolumn, więc nowe kolumny dostają wartości domyślne. `OrdersVerifier` (`DemoData/Verify/OrdersVerifier.php:67-68`)
  wymaga sumy zamówienia = suma linii − rabat. `make seed` uruchamia `bin/seed-demo`; reset Ordering:
  `Ordering/Infrastructure/Demo/DbalOrderingReset.php:23`.
- **Wdrożenie.** `api`, `worker` (z `worker-commands` po ASYNC-RELIABILITY), `admin` i `store` są w fali Argo 1
  (`infra/k8s/charts/shop/templates/api.yaml:156,161,168`, `admin.yaml:30`, `store.yaml:49`); migracje biegną przed nią.
  PostgreSQL 18 (`infra/docker-compose.yml:13`).

## Moduł Delivery

### Model

- Agregat `DeliveryMethod` (`id` UUID v7, `name`, `description`, `price`, `freeFrom`, `active`, `position`,
  `trackingUrlTemplate`), value objecty modułu (kopie lokalne wzorców, nie import z innych modułów):
  - `DeliveryMethodName`: 1–100 znaków po obcięciu białych znaków Unicode na brzegach (wzorzec `StaffNote::EDGES`,
    `Ordering/Domain/StaffNote.php:17`); odrzuca znaki sterujące, U+0085, U+2028, U+2029 (wzorzec
    `Ordering/Domain/ControlCharacters.php:15`), niepoprawny UTF-8 i nazwę z samych białych znaków (np. NBSP).
  - `DeliveryMethodDescription`: 0–200 znaków po obcięciu, jedna linia (znak nowej linii jest znakiem sterującym),
    te same znaki zakazane; pusty opis jest dozwolony.
  - `DeliveryPrice`: brutto 0…99 999 999 groszy (górna granica jak `GrossPrice::MAX`).
  - `FreeDeliveryThreshold`: `null` (brak progu) albo 1…99 999 999 999 groszy.
  - `position`: 0…999.
  - `TrackingUrlTemplate`: opcjonalny; zaczyna się od `https://` i ma nazwę hosta, najwyżej 500 znaków, bez białych
    znaków i znaków sterujących, z dokładnie jednym `{numer}`. `urlFor(number)` zastępuje `{numer}` przez
    `rawurlencode(number)`.
- Reguły agregatu: limit 20 aktywnych metod (stała w domenie) sprawdza handler dodania i zmiany, gdy wynik jest
  aktywny, a metoda nie była aktywna; nazwa jest unikalna bez wielkości liter (A9). Metody się nie usuwa (P16).
- Persystencja: tabela `delivery_method (id UUID PK, name VARCHAR(100), description VARCHAR(200), price_gross BIGINT,
  free_from_gross BIGINT NULL, active BOOLEAN, position SMALLINT, tracking_url_template VARCHAR(500) NULL, version INT,
  created_at, updated_at)`, rekord ORM z `#[ORM\Version]` (wzorzec `DiscountCodeRecord.php:72`), indeks unikalny
  `lower(name)`, indeks `(active, position)`, CHECK zakresów ceny, progu i kolejności. Mapowanie w
  `config/packages/doctrine.yaml`, warstwy `Delivery_*` w `deptrac.yaml` z zależnościami `Cart_Infrastructure` i
  `Ordering_Infrastructure → Delivery_Application`.

### W1. API panelu

Wszystkie trasy w `openapi-admin.json`, firewall `backoffice`, tylko rola `admin` (`DeliveryVoter`, wzorzec
`Pricing/Infrastructure/Security/DiscountCodeVoter.php`, z jedną rolą); inne role 403, bez tokena 401;
`AdminRouteAccessTest` obejmuje nowe trasy.

- `GET /api/v1/admin/delivery-methods`: `{items: DeliveryMethod[]}` bez stronicowania: najpierw aktywne po `position`,
  potem po nazwie; dalej nieaktywne po nazwie. Element: `id`, `name`, `description`, `priceGross`, `freeFromGross`
  (`null` bez progu), `active`, `position`, `trackingUrlTemplate` (`null` bez szablonu), `version`.
- `POST /api/v1/admin/delivery-methods` (201 z metodą) i `PUT /api/v1/admin/delivery-methods/{id}` (200; cała
  metoda z `version`, wzorzec `Pricing/UI/Http/DiscountCode/ReviseDiscountCodeController.php`). Treść przez
  `#[RequiredKeysBody]`: każdy klucz wymagany (`description`, `freeFromGross` i `trackingUrlTemplate` mogą być
  `null`). `GET /api/v1/admin/delivery-methods/{id}` zwraca metodę.
- Nieznany identyfikator (także nie UUID): 404 przed odczytem treści.
- Walidacja (422 z polem naruszenia, po jednym naruszeniu na pole, komunikaty dokładnie takie):

  | Pole | Komunikaty |
  |---|---|
  | brak klucza | „To pole jest wymagane.” |
  | `name` | „Nazwa jest wymagana.” (`null`, nie tekst, pusta po obcięciu); „Nazwa może mieć najwyżej 100 znaków.”; „Nazwa zawiera niedozwolone znaki.” |
  | `description` | „Opis musi być tekstem.”; „Opis może mieć najwyżej 200 znaków.”; „Opis zawiera niedozwolone znaki.”; `null` i tekst z samych białych znaków to pusty opis |
  | `priceGross` | „Cena jest wymagana.” (`null`); „Cena musi być liczbą całkowitą groszy.”; „Cena musi wynosić od 0,00 do 999 999,99 zł.” |
  | `freeFromGross` | „Próg darmowej dostawy musi być liczbą całkowitą groszy.”; „Próg darmowej dostawy musi wynosić od 0,01 do 999 999 999,99 zł.” |
  | `active` | „Wskaż, czy metoda jest aktywna.” (`null` albo nie wartość logiczna) |
  | `position` | „Kolejność musi być liczbą całkowitą od 0 do 999.” |
  | `trackingUrlTemplate` | „Link śledzenia musi być tekstem.”; „Link śledzenia może mieć najwyżej 500 znaków.”; „Link śledzenia musi zaczynać się od https:// i mieć nazwę hosta.”; „Link śledzenia nie może zawierać spacji ani znaków sterujących.”; „Link śledzenia musi zawierać dokładnie jedno {numer}.”; `null` i tekst z samych białych znaków to brak szablonu |
  | `version` (PUT) | „Wersja metody jest wymagana.”; „Wersja metody musi być liczbą całkowitą od 1.” |

- 409 (problem z `type`, schemat `DeliveryConflictProblem` w `nelmio_api_doc.yaml`, obszar `admin`):
  `tag:piotrp321.github.io,2026:shop/problems/delivery-method-name-taken` „Metoda dostawy o tej nazwie już istnieje.”
  (także nazwa różniąca się wielkością liter, także wobec nieaktywnej); `…/delivery-method-limit-reached` „Aktywnych
  metod dostawy może być najwyżej 20. Wyłącz inną metodę.”; `about:blank` przy innej wersji „Metoda dostawy została
  w międzyczasie zmieniona. Odśwież ją i spróbuj ponownie.”. Nic się nie zmienia.
- Zmiana metody nie zmienia złożonych zamówień (kopie, W5). Wyłączenie działa od następnego odczytu koszyka.

### W2. Wycena dostawy

- Zapytanie `QuoteDelivery(goodsGross)` (`query.bus`): aktywne metody po `position`, potem po nazwie. Dla każdej:
  `methodId`, `name`, `description`, `trackingUrlTemplate`, `costGross` (0, gdy `goodsGross` ≥ `freeFromGross`, inaczej
  cena), `freeFromGross`, `missingToFreeGross` (`freeFromGross − goodsGross`, gdy próg jest i nie został osiągnięty,
  inaczej `null`; zawsze `null`, gdy `costGross` = 0, czyli przy cenie 0 albo osiągniętym progu, więc „Darmowa” nigdy
  nie występuje razem z „Do darmowej dostawy brakuje”).
- Podsumowanie: `fromGross` = najmniejszy `costGross`; `missingToFreeGross` = najmniejsza dodatnia brakująca kwota
  wśród metod, `null` gdy `fromGross` = 0 albo żadna metoda nie ma progu.
- Zero aktywnych metod: pusta lista, `fromGross` i `missingToFreeGross` `null`.
- `goodsGross` to wartość towarów po rabatach (promocje i kod); `goodsGross` < 0 to błąd logiki (`InvalidArgumentException`).

## W3. Podział kosztu dostawy na stawki VAT (Pricing)

- `Pricing/Domain/Basket/DeliveryCostSplit::of(int $cost, array $goodsByRate)`, gdzie `$goodsByRate` to
  `stawka w punktach bazowych → brutto towarów po rabacie` zamówienia. Wynik: lista udziałów `{rateBp, gross, net, vat}`.
- Algorytm: stawki malejąco; koszt dzielony `BasketQuote::allocate(wartości, Σ wartości, koszt)` (Σ udziałów = koszt,
  remis reszt dla wcześniejszej pozycji, więc grosz trafia do wyższej stawki); netto i VAT udziału z
  `PriceBreakdown::ofLineGross(udział, stawka)`; udziały 0 są pomijane.
- Przypadki brzegowe: pusta mapa stawek albo Σ wartości = 0 → `InvalidArgumentException` (zamówienie ma zawsze towary
  ≥ 1 grosz); grupa stawki o wartości 0 dostaje 0; jedna stawka dostaje cały koszt; koszt 0 → brak udziałów; koszt
  większy niż wartość towarów (np. towar 1 zł, dostawa 19,99 zł) dzieli się tak samo. Dokumentacja `allocate`
  dopuszcza D ≥ S (algorytm jest poprawny dla każdego D ≥ 0 przy S > 0; test to potwierdza), bez zmiany zachowania
  dla rabatów i generatora demo.
- Zapytanie `Pricing\Application\Query\SplitDeliveryCost(costGross, goodsByRate)` zwraca udziały; Ordering woła je
  przez nową metodę portu `OrderPrices` (A3).
- Przykład do testu: koszt 1999, towary 23% = 10 000, 8% = 5 000 → udziały 1333 (23%: netto 1084, VAT 249) i 666
  (8%: netto 617, VAT 49).
- Groszowe zaokrąglenia, remis reszt, netto `HALF_UP` i VAT = brutto − netto to decyzja techniczna, która zapewnia zgodność
  sum, a nie wymóg interpretacji. Organ w obu pismach z P4 pisze o proporcji do „podstawy opodatkowania” towarów; klucz
  brutto przyjmujemy za interpretacją z 2022 r. Przy towarach w jednej stawce oba klucze dają ten sam wynik. Darmowa
  dostawa: koszt 0, brak udziałów, VAT 0.

## W4. Koszyk

- Port `CartDelivery` (Cart Application) z adapterem `Cart/Infrastructure/Delivery/QueryBusCartDelivery` (`QuoteDelivery`).
- `CartView.delivery`: `null`, gdy w koszyku nie ma linii do kupienia; inaczej `{fromGross, missingToFreeGross,
  options: [{methodId, name, description, costGross, freeFromGross, missingToFreeGross, totalGross}]}`, liczone od
  `totals.gross` (towary po rabatach, z kodem z parametru `discountCode` podglądu). `totalGross` = `totals.gross` +
  `costGross`, liczy API. Szablonu linku śledzenia koszyk nie zwraca.
- Odczyt koszyka gościa i klienta, z kodem i bez; kontrakt w `openapi.json`, klient sklepu wygenerowany. Pusty
  koszyk BFF (`bff.ts:29-35`) ma `delivery: null`.
- Strona koszyka (`apps/store/src/cart/CartPage.tsx`) pod sumą:
  - `fromGross` > 0: „Dostawa od 19,99 zł”; `fromGross` = 0: „Darmowa dostawa”; `fromGross` `null` albo `delivery`
    `null`: bez wiersza dostawy;
  - `missingToFreeGross` nie `null`: „Do darmowej dostawy brakuje 50,01 zł”.
  - Kwoty w formacie cen sklepu; wiersze aktualizują się po każdej zmianie koszyka (odczyt z API), w stałym regionie
    `aria-live="polite"` podsumowania koszyka.

## W5. Złożenie zamówienia

- Nowe wymagane pole żądania (gość i klient) `deliveryMethodId` (UUID):
  - brak klucza albo `null`: 422 przy `deliveryMethodId` „Kasa została zaktualizowana. Odśwież stronę i wybierz
    sposób dostawy.” (P15);
  - nie tekst albo nie UUID: 422 przy `deliveryMethodId` „Nieprawidłowy sposób dostawy. Odśwież stronę i wybierz go
    ponownie.”.
- Kroki handlera po wycenie i odrzuceniu kodu (`PlaceOrderHandler.php:134-138`), przed kontrolą sumy (`:139`):
  1. Port `OrderDeliveryQuotes` (Ordering Application) z adapterem `Ordering/Infrastructure/Delivery/QueryBusOrderDeliveryQuotes`
     wycenia `QuoteDelivery` dla wartości towarów zamówienia (Σ brutto linii po rabacie) i wybiera metodę
     `deliveryMethodId`.
  2. Metoda nieznana albo nieaktywna: 409 `tag:piotrp321.github.io,2026:shop/problems/delivery-method-unavailable`
     „Wybrany sposób dostawy jest niedostępny. Wybierz inny.”, dopisany do `OrderConflictProblem`; nic się nie zapisuje.
  3. Podział kosztu metody na stawki (`OrderPrices` → `SplitDeliveryCost`) z brutto linii po rabacie pogrupowanym
     po `vat_rate_bp`.
  4. Suma zamówienia z dostawą porównana z `expectedTotalGross`: inna → istniejące 409 `OrderTotalChanged` (zmiana
     ceny metody, przekroczenie albo utrata progu, zmiana ceny towaru).
- Kolejność odrzuceń: jak dziś do odrzucenia kodu, potem `delivery-method-unavailable`, potem `OrderTotalChanged`,
  potem niedobór stanu.
- Transakcja: ta sama transakcja `command.bus` złożenia; nowe kroki to odczyty bez blokad, kolejność blokad bez
  zmian (A7). Metoda wyłączona po odczycie i przed zatwierdzeniem złożenia zostaje w tym zamówieniu (kopia z chwili
  odczytu).
- Odcisk żądania (`RequestFingerprint::of`) dopisuje `delivery:<deliveryMethodId>` po opcjonalnym kodzie (zasada
  `apps/api/CLAUDE.md:169-170`; prefiks odróżnia identyfikator od kodu). Powtórzenie z tym samym kluczem i odciskiem
  zwraca istniejące zamówienie jak dziś; ponowienie żądania sprzed wdrożenia (bez klucza `deliveryMethodId`) dostaje 422.
- Limity bez zmian: istniejące limity złożenia działają; żadnej nowej trasy publicznej.

## W6. Zamówienie z dostawą

- Value object `OrderDelivery` (Ordering Domain): `methodId`, `methodName`, `trackingUrlTemplate` (kopie z chwili
  złożenia), `gross`, `net`, `vat` i lista udziałów VAT `{rateBp, gross, net, vat}`. Niezmienniki: Σ udziałów brutto
  = `gross`, netto + VAT = brutto dla całości i każdego udziału, udział > 0, stawka każdego udziału jest stawką
  którejś linii zamówienia, `gross` = 0 ⇔ brak udziałów.
- `Order::place(…, OrderDelivery $delivery)`: każde nowe zamówienie ma metodę (P15). Odtworzenie zamówienia z bazy
  przyjmuje `null` (zamówienia sprzed epiku i wygenerowane, P17).
- `OrderTotals::of($lines, ?OrderDelivery $delivery)`: netto, VAT i brutto obejmują dostawę; `goodsGross()` = Σ brutto
  linii (towary po rabacie). Płatność, kontrola kwoty `markPaid` i zwrot (`Refund::due(totals->gross)`) biorą sumę
  z dostawą bez zmian kodu (P13).
- Widoki: `OrderView`, JSON klienta (`CustomerOrderJson`), JSON zamówienia gościa i JSON panelu dostają
  `delivery: {methodName, gross} | null` (`null` dla zamówienia bez metody); `totals` obejmują dostawę. Kontrakty
  w `openapi.json` i `openapi-admin.json`, klienci wygenerowani.
- E-mail z potwierdzeniem (`OrderConfirmationText.php:35-37`): przed „Suma brutto” wiersz „Dostawa (<nazwa metody>):
  19,99 zł” (`MailFormat::amount`, także „0,00 zł” przy darmowej dostawie).

## W7. Statystyki (P14)

- Sprzedaż (pulpit, `ordering_sales_hour`): `MarkOrderPaidHandler` dopisuje `totals()->goodsGross()` zamiast
  `totals()->gross()` (`:64`); przebudowa (`DbalSalesHours`, `:102-108`, także `app:backfill` i
  `ordering:read-models:rebuild`) liczy `SUM(total_gross - delivery_gross)`. Przychód, liczba zamówień, średnia
  wartość i zmiana liczą się więc z towarów po rabatach, bez dostawy.
- Suma klienta (`ordering_customer.paid_gross`): bez zmian, `totals()->gross()` z dostawą (`:67`) i
  `SUM(total_gross)` w przebudowie (`DbalCustomerSummaries.php:154`).
- Symetria: anulowanie i zwrot dziś niczego z obu podsumowań nie odejmują („Stan obecny”) i tak zostaje; dodanie przy
  płatności i przebudowa używają tej samej wartości, więc przebudowa daje te same liczby co przyrosty. Zamówienia
  sprzed epiku mają `delivery_gross` = 0, więc istniejące wiersze podsumowań się nie zmieniają i nie wymagają
  przebudowy.

## W8. Wysyłka z numerem przesyłki

- `ChangeOrderStatusRequest` dostaje wymagany klucz `trackingNumber`. Reguły (422 przy `trackingNumber`):
  - `null` albo tekst z samych białych znaków (także Unicode) → brak numeru, przy każdym przejściu;
  - przejście inne niż `ship` i każda inna wartość (bez względu na jej format i typ): „Numer przesyłki można podać
    tylko przy wysyłce.”; ten komunikat ma pierwszeństwo przed komunikatem formatu;
  - `ship`: wartość, która nie jest tekstem: „Numer przesyłki musi być tekstem.”; tekst po obcięciu inny niż ASCII
    `[A-Za-z0-9-]{1,40}`: „Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki.”;
  - brak klucza (stara karta panelu): „To pole jest wymagane.” (resolver, P15).
- Value object `TrackingNumber`; `Order::ship(staff, note, ?TrackingNumber, now)` zapisuje numer. `OrderShipped` bez
  zmian (A6). Numer zmienia się później tylko przez W8a.
- Widoki (jak w W6): `delivery.methodName` jest zawsze, gdy zamówienie ma metodę (także bez numeru przesyłki);
  `shipment: {carrier, trackingNumber, trackingUrl} | null` jest tylko, gdy zamówienie ma numer przesyłki (bez numeru
  `null`); `carrier` = nazwa metody (`null` dla zamówienia bez metody); `trackingUrl` z kopii szablonu (`null` bez
  szablonu).
- E-mail o wysyłce (`OrderShippedText.php:20-35`) po adresie: „Sposób dostawy: <nazwa>”, gdy zamówienie ma metodę
  (także bez numeru); „Numer przesyłki: <numer>” i „Śledzenie przesyłki: <link>” tylko, gdy jest numer (link tylko
  z szablonem). Bez numeru nie ma wiersza „Przewoźnik”.

### W8a. Dodanie albo poprawienie numeru po wysyłce (P18)

- `PUT /api/v1/admin/orders/{orderId}/tracking-number` w `openapi-admin.json`, firewall `backoffice`. Treść przez
  `#[RequiredKeysBody]`: `{trackingNumber, version}`, oba klucze wymagane („To pole jest wymagane.”). `version` to
  wersja zamówienia, którą widział pracownik, jak w żądaniu zmiany statusu („Wersja zamówienia jest wymagana.”,
  „Wersja zamówienia musi być liczbą całkowitą od 1.”); bez niej mechanizm współbieżności zmiany statusu nie działa.
  Odpowiedź 200: zamówienie jak w panelu (`BackofficeOrder`).
- `trackingNumber`: tekst po obcięciu zgodny z `[A-Za-z0-9-]{1,40}` ustawia albo zastępuje numer; `null` albo tekst
  z samych białych znaków (także Unicode) usuwa numer; inny tekst: 422 przy `trackingNumber` z komunikatem formatu
  z W8; nie tekst: „Numer przesyłki musi być tekstem.”.
- Uprawnienia: nowy atrybut `OrderVoter` przyznawany rolom `SHIPPERS` (`admin`, `warehouse`,
  `Ordering/Infrastructure/Security/OrderVoter.php:33`), sprawdzany przed odczytem treści; inne role 403, bez tokena
  401. Nieznane zamówienie albo identyfikator nie-UUID: 404.
- Status: tylko `shipped` i `delivered`; inny status: 409 `tag:piotrp321.github.io,2026:shop/problems/tracking-number-not-changeable`
  „Numer przesyłki można zmienić tylko w zamówieniu wysłanym albo dostarczonym.” w `BackofficeOrderConflictProblem`
  (styl `OrderTransitionNotAllowed`). Nic się nie zmienia.
- Współbieżność: ten sam mechanizm co zmiana statusu (`ChangeOrderStatusHandler.php:48`): `StaffOrderLock::lock`
  blokuje wiersz zamówienia i porównuje wersję; inna wersja to dzisiejsze 409 konfliktu wersji („Zamówienie zostało
  w międzyczasie zmienione.”). Zapis zmieniający numer zwiększa wersję zamówienia; zapis bez zmiany niczego nie zapisuje.
- Domena: `Order::changeTrackingNumber(?TrackingNumber)` ze strażnikiem statusu; bez zdarzenia domenowego, e-maila,
  powiadomienia, wpisu w historii statusów i nowego przepływu. Zapis przez `writeChanges()`.
- Klient widzi bieżący numer i link na stronie zamówienia (W10). E-mail „wysłane”, który już wyszedł, nie jest
  wysyłany ponownie; zamówienie nie zapisuje, kto zmienił numer („Ryzyka i ograniczenia”).

## W9. Panel

- Ekran „Dostawa” (`apps/admin/src/delivery/`, widoki `DeliveryMethodsView` i `DeliveryMethodFormView` w
  `apps/admin/src/views/`, wzorzec `DiscountCodeFormView` i `apps/admin/src/prices/parseGross.ts`), pozycja menu
  „Dostawa” z `visible(staff)` tylko dla `admin` (`apps/admin/src/layout/menu.ts`), trasy z kontrolą roli jak inne
  ekrany tylko dla `admin`.
- Lista bez stronicowania: tabela z kolumnami „Nazwa”, „Cena”, „Darmowa od” („—” bez progu), „Kolejność”, „Status”;
  aktywne na górze w kolejności API, pod nimi nieaktywne z etykietą „Nieaktywna” (wyciszony ton). Nad tabelą
  „Aktywne: N z 20” i przycisk „Dodaj metodę”. Stany: ładowanie, błąd z „Spróbuj ponownie”, pusta lista „Brak metod
  dostawy. Dodaj pierwszą, żeby sklep przyjmował zamówienia.”.
- Formularz (dodanie i zmiana): „Nazwa”, „Opis (czas dostawy)”, „Cena brutto (zł)”, „Darmowa od (zł, opcjonalnie)”,
  „Aktywna”, „Kolejność”, „Link śledzenia (opcjonalnie)” z podpowiedzią „Adres https:// z {numer} w miejscu numeru
  przesyłki”. Naruszenia API przy polach, 409 nad formularzem z focusem na komunikacie, zapis z komunikatem jak inne
  formularze panelu i powrotem do listy. Kwoty w zł z przecinkiem (`parseGross`); puste „Darmowa od” → `null`.
  Bezpieczeństwo zmian w formularzu (`docs/features/admin-ux-fixes/spec.md`, „Etap 2”: B3, B4): szkic zmienia tylko
  pracownik. Pola są wyłączone od wysłania zapisu do jego wyniku i na czas odczytu stanu z serwera („Sprawdź stan”, „Odrzuć moje zmiany i pokaż stan z serwera”). 409 wersji pokazuje „Metoda dostawy została
  w międzyczasie zmieniona. Twoje zmiany zostają w formularzu.”, zostawia szkic i wersję, niczego nie czyta sam i daje
  przycisk „Odrzuć moje zmiany i pokaż stan z serwera” (odczyt, a szkic zastępuje dopiero odpowiedź 200); 409 nazwy
  i limitu zostawiają to, co wpisano. Brak odpowiedzi przez 10 s, błąd sieci i każde 5xx to nieznany wynik zapisu,
  nigdy komunikat, że się nie udał: „Nie wiemy, czy zapis się powiódł. Twoje zmiany zostają w formularzu.” ze „Sprawdź
  stan” (zmiana) albo „Odblokuj formularz” i linkiem do listy (nowa metoda, „Nie wiemy, czy metoda dostawy została
  utworzona.”). Limit nie przerywa żądania, a panel niczego nie ponawia sam; spóźniony sukces to tylko toast
  „Wcześniejszy zapis został wykonany.”. Po „Odblokuj formularz” (albo błędzie sieci lub 5xx) kolejny zapis, który dostanie 409
  nazwy, dopisuje „Możliwe, że utworzył ją Twój wcześniejszy zapis.”.
- Szczegóły zamówienia (`OrderDetailView.vue`): wiersz dostawy (nazwa metody i koszt) w podsumowaniu kwot, gdy
  zamówienie ma metodę; numer przesyłki i link śledzenia (nowa karta) tylko, gdy jest `shipment`. Okno „Wyślij” (`useOrderDetail.ts:210-222`) ma opcjonalne pole
  „Numer przesyłki” (tylko dla `ship`) z podpowiedzią „Litery, cyfry i myślniki, do 40 znaków”; naruszenie przy polu.
- W szczegółach zamówienia `shipped` albo `delivered`, dla ról `admin` i `warehouse`: akcja „Zmień numer przesyłki”
  („Dodaj numer przesyłki”, gdy numeru nie ma) otwiera małe okno z polem „Numer przesyłki” (ta sama podpowiedź; puste
  pole usuwa numer). Pole z etykietą, obsługa klawiaturą, po zamknięciu focus wraca na akcję, wynik i błędy (422 przy
  polu, 409 statusu i wersji, 403) w `aria-live`, ekran 375 px.
- Ekran 375 px, klawiatura, etykiety, `aria-live` dla statusów (lista jakości).

## W10. Sklep: kasa i konto

- Kasa (`CheckoutPage.tsx`): grupa przycisków radio (`fieldset` z `legend` „Sposób dostawy”) z opcji
  `cart.delivery.options`: nazwa, opis, koszt albo „Darmowa” (przy `costGross` 0), a przy opcji z
  `missingToFreeGross` różnym od `null` „Do darmowej dostawy brakuje Y zł” (nigdy razem z „Darmowa”, W2). Gdy odczyt
  koszyka ma `delivery: null` (nic do kupienia), kasa nie pokazuje sekcji sposobu dostawy i działa dzisiejsze
  zachowanie pustego koszyka. Wybór wymagany; wstępnie zaznaczony tylko, gdy jest dokładnie jedna opcja. Wybrana metoda zostaje po
  odświeżeniu podglądu (kod, zmiana sum), jeśli dalej jest na liście; inaczej wybór znika.
- Podsumowanie: wartość towarów, rabat kodu, „Dostawa” (koszt wybranej opcji albo „Darmowa”; bez wyboru „Wybierz
  sposób dostawy”), „Do zapłaty” = `option.totalGross` (bez wyboru zamiast kwoty „Wybierz sposób dostawy”). Sklep
  nie dodaje kwot. Zmiany kosztu i progu po kodzie są ogłaszane w regionie `aria-live` podsumowania.
- Wysłanie: `expectedTotalGross` = `option.totalGross`, `deliveryMethodId` wybranej opcji
  (`PlaceOrderInput` w `apps/store/src/api/client.ts`, lista pól BFF `checkout/api.ts:11-12`). Bez wyboru: komunikat
  „Wybierz sposób dostawy.” przy grupie i focus na pierwszej opcji, bez żądania.
- 409 `delivery-method-unavailable` (powód `delivery_method_unavailable` w `problems.ts`): komunikat API nad
  formularzem z focusem, nowy `Idempotency-Key`, ponowny odczyt podglądu (metody), wybór znika, jeśli metody nie ma.
  422 przy `deliveryMethodId` (stara karta albo zły identyfikator): komunikat nad formularzem (dzisiejsza ścieżka
  nieznanego pola).
- Brak metod (`options` puste): `Alert` „Nie możemy teraz przyjąć zamówienia: brak dostępnych sposobów dostawy.
  Spróbuj później.” i nieaktywny przycisk złożenia.
- Konto (`apps/store/src/account/OrderDetailPage.tsx`): wiersz „Dostawa (<nazwa>)” z kosztem w podsumowaniu kwot
  (bez wiersza dla zamówienia bez metody); tylko gdy jest `shipment`: „Numer przesyłki” i link „Śledź przesyłkę
  (otwiera się w nowej karcie)” (`target="_blank"`, `rel="noopener noreferrer"`, tylko z `trackingUrl`).
- Ekran 375 px, klawiatura i etykiety (lista jakości).

## W11. Persystencja zamówienia i migracje

- Migracja 1 (transakcyjna) na `ordering_order`: `delivery_method_id UUID NULL`, `delivery_method_name VARCHAR(100)
  NULL`, `delivery_tracking_url_template VARCHAR(500) NULL`, `delivery_gross`, `delivery_net`, `delivery_vat BIGINT
  DEFAULT 0 NOT NULL`, `tracking_number VARCHAR(40) NULL`; tabela `ordering_order_delivery_vat (order_id UUID FK
  ordering_order, vat_rate_bp INT, gross BIGINT, net BIGINT, vat BIGINT, PRIMARY KEY (order_id, vat_rate_bp),
  CHECK (net + vat = gross AND gross > 0 AND net >= 0 AND vat >= 0))` (potrzebna później INVOICING #771). CHECK na
  `ordering_order` dodane jako `NOT VALID`:
  - kolumny metody razem ustawione albo razem `null` (`delivery_method_id`, `delivery_method_name`);
  - szablon tylko z metodą;
  - `delivery_net + delivery_vat = delivery_gross` i wszystkie ≥ 0;
  - metoda albo `delivery_gross` = 0;
  - `tracking_number` tylko przy `shipped_at IS NOT NULL`.
  W PostgreSQL 18 dodanie kolumny `NULL` albo ze stałą wartością domyślną i CHECK `NOT VALID` zmieniają tylko
  metadane (krótka blokada `ACCESS EXCLUSIVE`, bez przepisania tabeli).
- Migracja 2: `VALIDATE CONSTRAINT` każdego z tych CHECK (skan pod `SHARE UPDATE EXCLUSIVE`, zapisy trwają).
- Kolumnę `tracking_number` i jej CHECK dodaje migracja 1 (zadanie zamówienia z dostawą); mapowanie numeru (odczyt
  w `toDomain()`, zapis w `writeChanges()`) dochodzi z wysyłką z numerem (W8).
- `DoctrineOrderRepository`: `write()` (`:189-212`) zapisuje kolumny dostawy i udziały (nowy rekord OneToMany,
  zapisywany tylko przy dodaniu zamówienia), `toDomain()` odtwarza dostawę; `writeChanges()` (`:223-230`) zapisuje
  `tracking_number`, a `toDomain()` go odtwarza (W8). `OrderingSchemaTest` obejmuje nowe kolumny i tabelę.
- `DbalOrderingReset` obcina też `ordering_order_delivery_vat`.

## W12. Dane demo i seed

- `apps/api/fixtures/delivery-methods.yaml`: „Kurier standardowy” (19,99 zł, darmowa od 299,00 zł, „Dostawa w 2–3 dni
  robocze”, kolejność 10, szablon `https://tracking.example.com/{numer}`) i „Kurier ekspresowy” (39,99 zł, darmowa od
  999,00 zł, „Dostawa następnego dnia roboczego”, kolejność 20, bez szablonu).
- Komenda `delivery:seed-demo` (warstwa UI Delivery): usuwa metody i ładuje plik; w `prod` tylko z `--force-reset`
  (wzorzec `ordering:seed-demo`). `bin/seed-demo` uruchamia ją po `pricing`.
- Generator danych demo: zamówienia historii bez dostawy (P17; `COPY` bez nowych kolumn). `OrdersVerifier`
  (`:67-68`, krotka sum netto, VAT i brutto linii wobec `total_net`, `total_vat`, `total_gross`) dolicza do sum linii
  `delivery_net`, `delivery_vat` i `delivery_gross`, a do kontroli wartość − rabat także `delivery_gross`.
- E2E zakładają metody seeda; testy panelu tworzą własne metody z unikalnymi nazwami, droższe od metod seeda, z
  kolejnością 999, i kończą je wyłączone; testy innych plików nie zakładają liczby metod.

## Wdrożenie i zgodność wersji

- Migracje są zgodne wstecz: stary kod nie zna nowych kolumn, więc zapisuje zamówienia z wartościami domyślnymi
  (bez metody, dostawa 0), które spełniają nowe CHECK.
- `api`, `worker`, `worker-commands`, `admin` i `store` są w tej samej fali Argo; w trakcie wymiany podów:
  - stara strona kasy (także karta otwarta przed wdrożeniem i użyta długo po nim) albo stary BFF sklepu (lista pól
    bez `deliveryMethodId`) z nowym API: 422 z komunikatem P15 nad formularzem; klient odświeża stronę (stara karta nie dostaje przycisku odświeżenia). Odświeżenie gubi wpisane
    dane i kod rabatowy („Stan obecny”); po wymianie podów `store` strona ma wybór metody;
  - nowa strona kasy ze starym API: odczyt koszyka bez `delivery` daje stan „brak metod” do odświeżenia po wymianie
    podów `api`; żądanie złożenia z `deliveryMethodId` stare API ignoruje (nieznany klucz), a suma z dostawą różna od
    sumy towarów daje 409 `OrderTotalChanged`, więc nie powstaje zamówienie z kwotą inną niż widziana. Przy
    darmowej dostawie (suma z dostawą = suma towarów) stare API przyjmuje zamówienie bez metody, z kosztem dostawy 0;
  - stara karta panelu: wysyłka dostaje 422 przy `trackingNumber` do przeładowania karty (P15).
- Stary kod (poprzednia wersja `api`, `worker`, `worker-commands`) nie zna kolumn dostawy: zamówienie z kosztem
  dostawy odczytałby z sumą bez dostawy (e-mail z potwierdzeniem, kwota płatności, zwrot, suma klienta). Takie
  zamówienie nie może powstać w oknie wymiany podów, bo na serwerze po wdrożeniu nie ma żadnej metody dostawy, a bez
  aktywnej metody nowe API nie składa zamówień (W5). Dlatego administrator dodaje metody w panelu „Dostawa” dopiero
  po zakończeniu rolloutu, gdy jednocześnie: przebieg `deploy.yml` się zakończył; aplikacja Argo `shop` ma
  `operationState.phase` `Succeeded`, a `syncResult.revision` i `sync.revision` są równe nowemu snapshotowi
  `deploy-images` (polecenie w runbooku `infra/k8s/README.md`, sekcja 7, „Sprawdzenie”); aplikacje są `Synced`
  i `Healthy`; pody `api`, `worker` i `worker-commands` należą wyłącznie do nowych ReplicaSetów i żaden nie jest
  `Terminating` (`ssh <user>@<adres> 'sudo k3s kubectl -n shop get pods -l "app.kubernetes.io/name in
  (api,worker,worker-commands)" -o custom-columns=POD:.metadata.name,IMAGE:.spec.containers[0].image,DELETING:.metadata.deletionTimestamp'`:
  każdy obraz to digest `api` z `values-images.yaml` nowego snapshotu, a `DELETING` to `<none>`). Ta chwila jest też `:koniec_wdrozenia` w zapytaniu kontrolnym niżej. W trakcie rolloutu nie biegnie żaden
  seed (`make -C infra/k8s generate`, `make -C infra/k8s reinitialize`). Ta kolejność dotyczy tylko wydania tego
  epiku: kolejne wersje znają dostawę.
- Cofnięcie do wersji sprzed epiku (runbook, sekcja 7, „Cofnięcie wersji”) jest bezpieczne tylko przed dodaniem metod.
  Po ich dodaniu nie jest wspierane: stary kod liczy bez dostawy sumy, kwotę płatności i zwrot każdego zamówienia
  z kosztem dostawy, także opłaconego. Zostaje poprawka w przód albo odtworzenie kopii zapasowej.
- Zamówienie bez metody tylko w oknie wymiany wersji (P19): nowe API odrzuca złożenie bez `deliveryMethodId` (422,
  W5), a `Order::place` wymaga dostawy (W6), więc gdy działają już tylko nowe pody `api`, takie zamówienie nie może
  powstać. Bez metody zostają zamówienia sprzed epiku i wygenerowana historia demo (P17).
- **Po wdrożeniu**, po zgodzie użytkownika jak przy innych sprawdzeniach po wdrożeniu (kryteria „po wdrożeniu”
  w opisie PR): liczba zamówień złożonych po zakończeniu wymiany podów bez metody wynosi 0, czyli
  `SELECT count(*) FROM ordering_order WHERE placed_at > :koniec_wdrozenia AND delivery_method_id IS NULL` = 0
  (`:koniec_wdrozenia` = chwila, w której rollout fali 1 się zakończył).
- Kształt wiadomości i zdarzeń się nie zmienia (`OrderShipped` bez zmian), więc test kształtu zdarzeń
  ASYNC-RELIABILITY przechodzi bez nowych zapisów.
- Po wdrożeniu na serwer bez metod dostawy kasa nie przyjmuje zamówień, dopóki administrator nie doda aktywnej metody
  w panelu „Dostawa” (krok użytkownika, dopiero po zakończeniu rolloutu opisanym wyżej; `delivery:seed-demo` na serwerze
  wymagałby `--force-reset`).

## Scenariusze błędów

| Sytuacja | Odpowiedź API | Co widzi użytkownik |
|---|---|---|
| Kasa bez `deliveryMethodId` (stara karta, stary BFF) | 422 przy `deliveryMethodId` | „Kasa została zaktualizowana. Odśwież stronę i wybierz sposób dostawy.” nad formularzem |
| Zły identyfikator metody | 422 przy `deliveryMethodId` | „Nieprawidłowy sposób dostawy. Odśwież stronę i wybierz go ponownie.” |
| Metoda wyłączona albo nieznana w chwili złożenia | 409 `delivery-method-unavailable` | komunikat API, odświeżona lista metod, wybór znika |
| Cena metody albo próg zmienił się po podglądzie; kod obniżył wartość poniżej progu | 409 `order-total-changed` | nowe podsumowanie z kosztem dostawy, ponowne kliknięcie |
| Brak aktywnych metod | koszyk: `options` puste | kasa: `Alert`, przycisk nieaktywny; koszyk bez wiersza dostawy |
| Nazwa metody zajęta (też inną wielkością liter) | 409 `delivery-method-name-taken` | komunikat nad formularzem panelu |
| 21. aktywna metoda | 409 `delivery-method-limit-reached` | komunikat nad formularzem panelu |
| Metoda zmieniona przez kogoś innego | 409 `about:blank` | komunikat, że zmiany zostają w formularzu, z przyciskiem „Odrzuć moje zmiany i pokaż stan z serwera” (bez automatycznego odczytu) |
| Zapis metody bez odpowiedzi (10 s), błąd sieci, 5xx | brak odpowiedzi albo 5xx | stan „nieznany wynik” ze „Sprawdź stan” albo „Odblokuj formularz”, szkic zostaje |
| Przy „Wyślij”: numer z polskimi znakami, spacją w środku, za długi | 422 przy `trackingNumber` (komunikat formatu) | komunikat przy polu okna „Wyślij” |
| Numer (inny niż `null` i same białe znaki) przy „Dostarczone”, „Anuluj”, „Zwrot”, w dowolnym formacie | 422 przy `trackingNumber` („Numer przesyłki można podać tylko przy wysyłce.”) | komunikat przy polu |
| Stara karta panelu wysyła zamówienie | 422 przy `trackingNumber` („To pole jest wymagane.”) | komunikat do przeładowania karty |
| Rola inna niż `admin` na `/api/v1/admin/delivery-methods` | 403 | pozycja menu ukryta |
| Poprawienie numeru w zamówieniu innym niż `shipped` i `delivered` | 409 `tracking-number-not-changeable` | komunikat w oknie numeru (`aria-live`) |
| Poprawienie numeru na nieaktualnej wersji zamówienia | 409 konfliktu wersji | komunikat z prośbą o odświeżenie |
| Poprawienie numeru przez rolę inną niż `admin` i `warehouse` | 403 | akcja niewidoczna |
| Poprawiony numer w złym formacie | 422 przy `trackingNumber` | komunikat przy polu okna numeru |

## Dokumentacja do zmiany

Zmienia ją zadanie, które zmienia opisane zachowanie:

- `docs/vision.md`: wiersz bounded contextu `Delivery` w tabeli „Bounded contexty (api)”; w „Zakresie po MVP”
  (punkt 2) dostawa zostaje jako punkty odbioru i integracja z przewoźnikiem (etap 2).
- `apps/api/CLAUDE.md` (zasada, nie opis kodu): ograniczenia CHECK na dużej tabeli dodaje migracja jako `NOT VALID`,
  a sprawdza osobna migracja `VALIDATE CONSTRAINT`.
- `docs/features/checkout/spec.md`: P3 (`:42`) i „Poza zakresem” (`:30`): wybór metody i koszt dostawy w sumie,
  odwołanie do tej specyfikacji; ekran kasy z wyborem metody.
- `docs/features/cart/spec.md` (`:15`): koszty dostawy w odczycie koszyka (`delivery`).
- `docs/features/order-management/spec.md`: zwrot pełnej kwoty z dostawą (P3 tamtej specyfikacji), reguła wysyłki
  z numerem przesyłki i jego poprawianie po wysyłce, „Poza zakresem” (`:30`).
- `docs/features/pricing/spec.md`: podział kosztu dostawy na stawki VAT i `SplitDeliveryCost`.
- `docs/features/admin-screens/spec.md`: ekran „Dostawa”, dostawa i przesyłka w szczegółach zamówienia, pole numeru
  w oknie „Wyślij”, akcja „Zmień numer przesyłki”.
- `docs/features/admin-features/spec.md` (`:164-166`): przychód = towary po rabatach bez dostawy; suma klienta
  z dostawą.
- `docs/features/demo-data/spec.md` (`:289`): kontrola sumy zamówienia z dostawą.

## Kryteria akceptacji

1. **Metody (API panelu).** Testy funkcjonalne: dodanie, lista w kolejności z W1, odczyt, zmiana z wersją; każde
   naruszenie z tabeli W1 przy właściwym polu z polskim komunikatem (brak klucza, zły typ, za długi tekst, znaki
   sterujące, NUL, U+2028, sam NBSP, szablon bez `{numer}`, z dwoma `{numer}`, `http://`); niepoprawny UTF-8 w treści
   odrzuca dekoder JSON (400 bez zapisu, jak w innych endpointach; value objecty też go odrzucają); 409
   nazwy (także „KURIER” wobec „kurier” i „Ł” wobec „ł”), 409 limitu przy 21. aktywnej (także przez aktywację
   istniejącej), 409 wersji; 404 nieznanego i nie-UUID; 403 dla ról innych niż `admin`, 401 bez tokena; ekran
   panelu przy 401 i 403 zachowuje się jak inne ekrany tylko dla `admin`.
2. **Wycena.** Testy jednostkowe `QuoteDelivery`: koszt 0 od progu (równość włącznie), brakująca kwota,
   `missingToFreeGross` metody `null` przy `costGross` 0 (cena 0 i osiągnięty próg), `fromGross` i
   `missingToFreeGross` podsumowania, brak progów, zero aktywnych metod, nieaktywne pominięte, kolejność,
   `goodsGross` < 0 → `InvalidArgumentException`.
3. **Podział VAT.** Testy jednostkowe `DeliveryCostSplit` (TDD): przykład z W3, jedna stawka, grupa 0, koszt 0, koszt
   większy niż towary, remis reszt na korzyść wyższej stawki, Σ udziałów = koszt i netto + VAT = brutto na losowych
   danych, pusta mapa → wyjątek.
4. **Koszyk.** Testy funkcjonalne odczytu koszyka gościa i klienta: `delivery` `null` bez linii do kupienia; poniżej
   progu koszt i brakująca kwota; powyżej progu koszt 0; z kodem, który obniża wartość poniżej progu, koszt płatny
   i brakująca kwota (P6); `totalGross` = towary + koszt. Vitest strony koszyka: „Dostawa od”, „Darmowa dostawa”,
   „Do darmowej dostawy brakuje”, brak wiersza, zmiany ogłaszane w `aria-live`. E2E: wiersze na stronie koszyka poniżej i powyżej progu metod seeda.
5. **Złożenie.** Testy funkcjonalne: zamówienie z metodą ma kopię metody, koszt, udziały VAT i sumę z dostawą;
   brak `deliveryMethodId` i `null` → 422 z komunikatem P15; zły format → 422; metoda nieaktywna albo nieznana →
   409 `delivery-method-unavailable` bez zamówienia, rezerwacji i użycia kodu; zmiana ceny metody i kod obniżający
   wartość poniżej progu → 409 `OrderTotalChanged`; powtórzenie z tym samym kluczem → to samo zamówienie; inna
   metoda z tym samym kluczem → 409 `idempotency-key-reused`. Test współbieżności złożenia (`PlaceOrderConcurrencyTest`)
   przechodzi z metodą dostawy.
6. **Zamówienie i pieniądze.** Testy jednostkowe `Order` i `OrderTotals` (TDD): sumy z dostawą, niezmienniki
   `OrderDelivery`, płatność kwotą z dostawą, zwrot przy anulowaniu i zwrocie = suma z dostawą. Test integracyjny
   repozytorium: zapis i odczyt dostawy i udziałów (numeru: kryterium 9); zamówienie bez metody (stare) działa we wszystkich
   przejściach. Test migracji: CHECK odrzucają złamania z W11 po `VALIDATE`.
7. **Statystyki.** Test: opłacenie zamówienia z dostawą dodaje do sprzedaży towary bez dostawy, a do sumy klienta
   kwotę z dostawą; przebudowa obu modeli odczytu daje te same liczby; anulowanie i zwrot nie zmieniają żadnego.
8. **Kasa.** Vitest: wybór wymagany („Wybierz sposób dostawy.” i focus), wstępny wybór tylko przy jednej opcji,
   „Do zapłaty” z `option.totalGross`, stan bez metod, 409 `delivery-method-unavailable` (focus, odświeżenie, nowy
   klucz), 422 nieznanego pola nad formularzem, 375 px. E2E: zamówienie gościa i klienta z dostawą płatną i darmową;
   koszyk powyżej progu po kodzie spada poniżej progu i ma płatną dostawę w odczycie koszyka z kodem (podgląd
   koszyka w kasie), w podsumowaniu kasy i w zamówieniu (P6); `delivery: null` → brak sekcji sposobu dostawy i
   dzisiejsze zachowanie pustego koszyka.
9. **Wysyłka.** Testy funkcjonalne zmiany statusu: `ship` z numerem i bez, `null` i same białe znaki (także
   Unicode) jako brak numeru przy każdym przejściu, zły format przy `ship` (polskie znaki, spacja, 41 znaków, NUL),
   dowolna inna wartość przy innym przejściu → „Numer przesyłki można podać tylko przy wysyłce.” (także w złym
   formacie), brak klucza → 422; `shipment` tylko z numerem, `delivery.methodName` także bez numeru; link z szablonu
   z zakodowanym numerem; e-mail o wysyłce z „Sposób dostawy: <nazwa>” przy metodzie, z numerem i linkiem tylko przy
   numerze, bez wiersza „Przewoźnik”. E2E: wysyłka z numerem
   w panelu, numer i link na stronie zamówienia klienta i w e-mailu w Mailpit; numer zapisany przy wysyłce wraca
   po odczycie z repozytorium.
10. **Poprawienie numeru (P18).** Testy funkcjonalne `PUT /api/v1/admin/orders/{orderId}/tracking-number`: `admin`
    i `warehouse` ustawiają, zastępują i usuwają (`null`, same białe znaki) numer w zamówieniu `shipped` i
    `delivered`; zły format i zły typ → 422 przy polu; brak klucza → 422; status `new`, `paid`, `cancelled`,
    `returned` → 409 `tracking-number-not-changeable`; inna wersja → 409 konfliktu wersji; `support` i `marketing` →
    403; bez tokena 401; nieznane zamówienie 404; żadne zdarzenie, e-mail ani wpis historii nie powstaje. Test
    jednostkowy `Order::changeTrackingNumber` (strażnik statusu). Vitest okna numeru (etykieta, focus, `aria-live`).
    E2E: poprawienie numeru w panelu, strona zamówienia klienta pokazuje nowy numer i link.
11. **Panel.** Vitest ekranu „Dostawa” (lista z nieaktywnymi, licznik, stany, formularz, naruszenia przy polach, 409)
    i okna „Wyślij”. E2E: dodanie, zmiana, wyłączenie metody; pozycja menu ukryta dla roli bez dostępu.
12. **Dane demo.** `OrdersVerifier` przechodzi na danych wygenerowanych (dostawa 0) i odrzuca zamówienie, którego
    `total_net`, `total_vat` albo `total_gross` nie równa się sumie linii z `delivery_net`, `delivery_vat` albo
    `delivery_gross`; `make seed` zakłada dwie metody.
13. **Zamówienie zawsze z metodą (P19).** Test funkcjonalny: złożenie bez `deliveryMethodId` i z `null` jest
    odrzucone (422) i nie tworzy zamówienia; test jednostkowy: `Order::place` wymaga `OrderDelivery` (bez dostawy nie
    powstaje zamówienie). Po wdrożeniu: zapytanie z sekcji „Wdrożenie i zgodność wersji” zwraca 0.
14. **Całość.** `make check`, `make test` i `make e2e` przechodzą; Deptrac bez nowych zależności poza
    `Cart_Infrastructure` i `Ordering_Infrastructure → Delivery_Application`; commitowane `openapi.json`,
    `openapi-admin.json` i klienci są aktualni (CI).

## Ryzyka i ograniczenia

- **Reguła VAT od dostawy** (P4) opiera się na interpretacjach wydanych innym podatnikom: nie dają one sklepowi ochrony
  i nie obejmują zwolnienia z VAT, OSS ani pełnomocnictwa pocztowego. Inny model sprzedaży wymaga nowej oceny reguły.
- **Faktury i paragony (INVOICING #771).** Interpretacja z 18.03.2024 (P4) uznaje za nieprawidłowe wykazywanie dostawy
  jako osobnej pozycji w zasadniczej części faktury albo paragonu: koszt dostawy wchodzi w wartość pozycji towarów,
  a rozbicie może być w części informacyjnej. Koszyk, podsumowanie zamówienia i e-maile nie są fakturą, więc osobny wiersz
  dostawy w nich zostaje. Do faktur potrzebne są udziały w `ordering_order_delivery_vat`; zamówienia sprzed epiku ich
  nie mają (dostawa 0).
- **Okno wdrożenia.** Przez czas wymiany podów fali 1 część klientów dostaje 422 albo 409 i odświeża stronę, tracąc
  wpisane dane i kod (sekcja „Wdrożenie i zgodność wersji”); zaakceptowane (P15).
- **Serwer bez metod po wdrożeniu** nie przyjmuje zamówień do czasu dodania metody w panelu.
- **Limit 20 aktywnych metod bez blokady** (A8): równoczesna aktywacja przez dwóch administratorów może dać 21.
- **Nieaktywne metody nie znikają** (P16): lista panelu rośnie o każdą wyłączoną metodę; bez stronicowania.
- **Poprawiony numer przesyłki** (P18) nie wysyła ponownie e-maila „wysłane”: klient widzi bieżący numer i link na
  stronie zamówienia, a gość tylko w e-mailu z pierwotnym numerem. Zamówienie nie zapisuje, kto i kiedy zmienił numer.
- **Wycena czyta metody przy każdym odczycie koszyka** (jedno zapytanie po indeksie `(active, position)` do najwyżej
  ok. 20 wierszy); wydajność przy docelowej skali niepotwierdzona testami obciążeniowymi.
- **Migracja 1 czeka na krótką blokadę `ACCESS EXCLUSIVE`** na `ordering_order`; długa transakcja na tej tabeli w
  chwili migracji opóźnia Job `migrate`.
