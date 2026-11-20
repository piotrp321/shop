# DELIVERY – plan zadań

Specyfikacja: `docs/features/delivery/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją po
każdym scalonym zadaniu. Po każdym zadaniu CI gałęzi epiku (`checks` i `e2e`) musi być zielone: zadanie, które zmienia
kontrakt, zmienia w tym samym kroku wszystko, co z niego korzysta. Zadania nie mają własnych PR. Zadania jednej fali
z listą `parallel_with` mogą iść naraz. Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | DELIVERY-1, DELIVERY-2 | DELIVERY-2: `a823dfc4`; DELIVERY-1: `3cdb00d8` |
| 2 | DELIVERY-3, DELIVERY-5 | DELIVERY-5: `e6115953`; DELIVERY-3: `0e34ee14` |
| 3 | DELIVERY-4 | w toku |
| 4 | DELIVERY-6, DELIVERY-7 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

Warunki przed startem (specyfikacja, „Wymagane przed startem”): `feat/async-reliability` zmergowany do `main`
(gałąź epiku powstaje od `origin/main` po tym merge'u), `paths` zadań i odwołania specyfikacji sprawdzone na `main`.
Reguła VAT od dostawy (P4) jest przyjęta, DELIVERY-2 nie czeka na konsultację.

Łańcuchy zależności:
- moduł Delivery z `QuoteDelivery` (1) → koszyk (3), złożenie zamówienia (4), ekran panelu (5);
- podział VAT w Pricing (2) → złożenie zamówienia (4);
- koszyk z opcjami (3) → złożenie zamówienia z minimalną kasą (4): wymagany `deliveryMethodId` i wybór metody w kasie
  wchodzą razem, więc istniejące E2E przechodzą po tym zadaniu;
- złożenie z dostawą i działająca kasa (4) → pozostałe stany kasy i nowe E2E (6) oraz numer przesyłki (7, widoki
  `delivery` i E2E składające zamówienia przez kasę).

Dokumentacja: opis działania jest w specyfikacji epiku i w kodzie. `apps/api/CLAUDE.md` zmienia tylko DELIVERY-4
(zasada `NOT VALID` + `VALIDATE CONSTRAINT`). Inne specyfikacje i `docs/vision.md` zmienia zadanie, które zmienia
opisane w nich zachowanie (specyfikacja, „Dokumentacja do zmiany”).

Na końcu epiku jeden przegląd całej zmiany w świeżym kontekście: `architecture-reviewer` (nowy moduł, granice
Deptrac, VAT w Pricing), `qa-adversarial` (pieniądze, migracje, złożenie zamówienia, okno wdrożenia, poprawianie
numeru przesyłki) i `ux-reviewer` w trybie `integration` (koszyk, kasa, konto klienta, ekran „Dostawa”, okno „Wyślij”
i okno numeru przesyłki w panelu).

## Zadania

```json
{
  "schema_version": 1,
  "epic": "DELIVERY",
  "spec": "docs/features/delivery/spec.md",
  "tasks": [
    {
      "id": "DELIVERY-1",
      "title": "Moduł Delivery: metody dostawy, API panelu, wycena i seed",
      "scope": "Spec: „Moduł Delivery” (Model, W1, W2), W12 (plik metod i `delivery:seed-demo`), A1, A2, A8, A9, decyzje P2, P5, P7, P16, kryteria 1, 2 i 12 (seed). API: nowy moduł `apps/api/src/Delivery/{Domain,Application,Infrastructure,UI}` z agregatem `DeliveryMethod` i value objectami (nazwa, opis, cena, próg, kolejność, szablon linku z `urlFor`), rekord ORM z `#[ORM\\Version]`, migracja tabeli `delivery_method` (indeks unikalny `lower(name)`, indeks `(active, position)`, CHECK zakresów), mapowanie w `config/packages/doctrine.yaml`, warstwy `Delivery_*` w `deptrac.yaml` razem z zależnościami `Cart_Infrastructure` i `Ordering_Infrastructure → Delivery_Application` (używają ich zadania 3 i 4), komendy dodania i zmiany z limitem 20 aktywnych i 409 nazwy, wersji i limitu, `DeliveryVoter` (tylko `admin`), trasy `GET`/`POST /api/v1/admin/delivery-methods` i `GET`/`PUT /api/v1/admin/delivery-methods/{id}` z `#[RequiredKeysBody]`, schemat `DeliveryConflictProblem` w `nelmio_api_doc.yaml`, `AdminRouteAccessTest`, zapytanie `QuoteDelivery(goodsGross)`. Seed: `fixtures/delivery-methods.yaml` (dwie metody kurierskie z W12), komenda `delivery:seed-demo` (`--force-reset` w `prod`), wpis w `bin/seed-demo` po `pricing`. Kontrakt: `openapi-admin.json` i klient panelu (`apps/admin/src/api/schema.d.ts`) w tym samym zadaniu. Dokumentacja: `docs/vision.md` (bounded context `Delivery`, linia dostawy w „Zakresie po MVP”). Nie obejmuje: koszyka (3), złożenia zamówienia (4), ekranu panelu (5); bez publicznego endpointu Delivery.",
      "acceptance_criteria": [
        "TDD: testy jednostkowe value objectów i agregatu `DeliveryMethod` oraz `QuoteDelivery` uruchomione przed implementacją nie przechodzą, po niej przechodzą; raport zawiera komendy i wyniki obu uruchomień.",
        "Testy jednostkowe value objectów: granice nazwy (1 i 100 znaków po obcięciu, 101), opisu (0 i 200, 201, znak nowej linii), ceny (0 i 99 999 999, -1, 100 000 000), progu (`null`, 1, 0), kolejności (0, 999, 1000), szablonu (`https://` z hostem, `http://`, bez hosta, spacja, znak sterujący, bez `{numer}`, dwa `{numer}`, 500 i 501 znaków); NUL, U+2028, sam NBSP i niepoprawny UTF-8 odrzucone; `urlFor` zastępuje `{numer}` zakodowanym numerem.",
        "Testy jednostkowe `QuoteDelivery`: koszt 0 od progu włącznie, brakująca kwota, `fromGross` i `missingToFreeGross` podsumowania (także `null` przy `fromGross` 0 i bez progów), zero aktywnych metod (pusta lista, `null`), nieaktywne pominięte, kolejność `position`, potem nazwa; `missingToFreeGross` metody `null` przy `costGross` 0 (cena 0 albo osiągnięty próg); `goodsGross` < 0 → `InvalidArgumentException`.",
        "Testy funkcjonalne API panelu: dodanie (201), lista w kolejności z W1 (aktywne, potem nieaktywne), odczyt, zmiana z wersją; każde naruszenie z tabeli W1 przy właściwym polu z dokładnym polskim komunikatem, także brak klucza i zły typ; 409 `delivery-method-name-taken` przy nazwie różniącej się wielkością liter (także „Ł” wobec „ł”) i wobec nieaktywnej metody; 409 `delivery-method-limit-reached` przy 21. aktywnej przez dodanie i przez aktywację istniejącej (zmiana nieaktywnej metody bez aktywacji przy 20 aktywnych przechodzi); 409 `about:blank` przy innej wersji; 404 dla nieznanego i nie-UUID przed odczytem treści; 403 dla ról `support`, `warehouse` i `marketing`; 401 bez tokena; `AdminRouteAccessTest` obejmuje cztery trasy.",
        "`make seed` zakłada dwie metody z W12; `delivery:seed-demo` w `prod` bez `--force-reset` kończy się kodem 1 bez zmian; testy funkcjonalne komendy na obu ścieżkach.",
        "Deptrac ma warstwy `Delivery_*` zależne tylko od `Shared` i `Vendor` oraz zależności `Cart_Infrastructure → Delivery_Application` i `Ordering_Infrastructure → Delivery_Application`; żaden inny moduł nie zależy od Delivery.",
        "Commitowane `apps/api/openapi-admin.json` i `apps/admin/src/api/schema.d.ts` są aktualne (sprawdzenie CI); `docs/vision.md` ma bounded context `Delivery` i linię dostawy etapu 2.",
        "`make -C apps/api check`, `make -C apps/api test`, `make -C apps/admin check` i `make -C apps/admin test` przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/src/Delivery/",
        "apps/api/config/packages/doctrine.yaml",
        "apps/api/config/packages/nelmio_api_doc.yaml",
        "apps/api/deptrac.yaml",
        "apps/api/migrations/",
        "apps/api/fixtures/delivery-methods.yaml",
        "apps/api/bin/seed-demo",
        "apps/api/tests/",
        "apps/api/openapi-admin.json",
        "apps/admin/src/api/schema.d.ts",
        "docs/vision.md"
      ],
      "parallel_with": [
        "DELIVERY-2"
      ]
    },
    {
      "id": "DELIVERY-2",
      "title": "Podział kosztu dostawy na stawki VAT w Pricing",
      "scope": "Spec: W3, A3, decyzja P4, kryterium 3. Pricing: czysta funkcja `Pricing/Domain/Basket/DeliveryCostSplit::of(cost, goodsByRate)` (stawki malejąco, `BasketQuote::allocate`, `PriceBreakdown::ofLineGross` na udział, pominięte udziały 0), dokumentacja `BasketQuote::allocate` dopuszczająca D ≥ S bez zmiany zachowania, zapytanie `Pricing\\Application\\Query\\SplitDeliveryCost` z handlerem. Dokumentacja: `docs/features/pricing/spec.md` (podział kosztu dostawy i zapytanie). Nie obejmuje: portu `OrderPrices` i użycia w Ordering (4).",
      "acceptance_criteria": [
        "TDD: testy `DeliveryCostSplit` i handlera `SplitDeliveryCost` uruchomione przed implementacją nie przechodzą, po niej przechodzą; raport zawiera komendy i wyniki obu uruchomień.",
        "Testy jednostkowe: przykład z W3 (1999 przy 23% = 10 000 i 8% = 5 000 → 1333 z netto 1084 i VAT 249 oraz 666 z netto 617 i VAT 49); jedna stawka dostaje cały koszt; grupa o wartości 0 dostaje 0 i nie ma udziału; koszt 0 → brak udziałów; koszt większy niż wartość towarów; remis reszt na korzyść wyższej stawki; na losowych danych Σ udziałów = koszt, netto + VAT = brutto w każdym udziale i każdy udział > 0; pusta mapa albo Σ wartości 0 → `InvalidArgumentException`.",
        "Istniejące testy rozkładu rabatu i generatora demo przechodzą bez zmian (zachowanie `allocate` dla D < S się nie zmienia).",
        "`docs/features/pricing/spec.md` opisuje podział kosztu dostawy i `SplitDeliveryCost`.",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [],
      "paths": [
        "apps/api/src/Pricing/Domain/Basket/",
        "apps/api/src/Pricing/Application/Query/",
        "apps/api/tests/",
        "docs/features/pricing/spec.md"
      ],
      "parallel_with": [
        "DELIVERY-1",
        "DELIVERY-3",
        "DELIVERY-5"
      ]
    },
    {
      "id": "DELIVERY-3",
      "title": "Koszyk: koszt dostawy, próg darmowej dostawy i opcje",
      "scope": "Spec: W4, decyzje P6 i P8, kryterium 4. API Cart: port `CartDelivery` z adapterem `Cart/Infrastructure/Delivery/QueryBusCartDelivery` (`QuoteDelivery` z zadania 1), pole `CartView.delivery` (`null` bez linii do kupienia; `fromGross`, `missingToFreeGross`, opcje z `totalGross` = `totals.gross` + koszt, bez szablonu linku) w odczycie koszyka gościa i klienta, z kodem i bez. Kontrakt: `openapi.json` i klient sklepu (`apps/store/src/api/schema.d.ts`) w tym samym zadaniu. Sklep: `delivery: null` w pustym koszyku BFF (`apps/store/src/cart/bff.ts`), wiersze „Dostawa od X zł”, „Darmowa dostawa” i „Do darmowej dostawy brakuje Y zł” na stronie koszyka, typowane dane testowe koszyka i kasy z nowym polem (kasa jeszcze go nie wyświetla). E2E: nowy plik `e2e/tests/delivery-cart.spec.ts`. Dokumentacja: `docs/features/cart/spec.md` (koszty dostawy w odczycie koszyka). Ryzyko STANDARD: `totalGross` koszyka to dane do wyświetlenia, które złożenie zamówienia sprawdza ponownie wobec `expectedTotalGross` (409), więc błąd tu nie może dać złej kwoty obciążenia. Nie obejmuje: wyboru metody w kasie (4, 6), złożenia (4).",
      "acceptance_criteria": [
        "TDD: testy jednostkowe `CartViewBuilder` z dostawą uruchomione przed implementacją nie przechodzą, po niej przechodzą; raport zawiera komendy i wyniki obu uruchomień.",
        "Testy funkcjonalne odczytu koszyka gościa i klienta: `delivery` `null` bez linii do kupienia; poniżej progu koszt metody i `missingToFreeGross`; od progu koszt 0; z kodem rabatowym, który obniża wartość towarów poniżej progu, koszt płatny i brakująca kwota (P6); `totalGross` każdej opcji = `totals.gross` + `costGross`; zero aktywnych metod daje pustą listę i `fromGross` `null`; odpowiedź bez szablonu linku; nagłówki `private, no-store` bez zmian.",
        "Vitest strony koszyka: „Dostawa od 19,99 zł” przy `fromGross` > 0, „Darmowa dostawa” przy 0, brak wiersza przy `null` i przy `delivery: null`, „Do darmowej dostawy brakuje …” przy `missingToFreeGross`; zmiany wierszy dostawy ogłaszane w regionie `aria-live`; ekran 375 px bez przewijania w poziomie.",
        "E2E `delivery-cart.spec.ts` na metodach seeda: koszyk poniżej progu pokazuje „Dostawa od” i brakującą kwotę, powyżej progu „Darmowa dostawa” (asercje bez zakładania liczby metod).",
        "Commitowane `apps/api/openapi.json` i `apps/store/src/api/schema.d.ts` są aktualne (sprawdzenie CI); `docs/features/cart/spec.md` opisuje `delivery`.",
        "`make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check` i `make -C apps/store test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DELIVERY-1"
      ],
      "paths": [
        "apps/api/src/Cart/",
        "apps/api/tests/",
        "apps/api/openapi.json",
        "apps/store/src/api/schema.d.ts",
        "apps/store/src/cart/",
        "apps/store/src/checkout/",
        "e2e/tests/delivery-cart.spec.ts",
        "docs/features/cart/spec.md"
      ],
      "parallel_with": [
        "DELIVERY-2",
        "DELIVERY-5"
      ]
    },
    {
      "id": "DELIVERY-4",
      "title": "Zamówienie z dostawą: złożenie, VAT dostawy, sumy, migracje, statystyki i wybór metody w kasie",
      "scope": "Spec: W5, W6, W7, W10 (część kasy potrzebna do złożenia zamówienia), W11, W12 (`OrdersVerifier`), „Wdrożenie i zgodność wersji”, A4, A5, A7, decyzje P1, P13, P14, P15, P17, P19, kryteria 5, 6, 7, 8 (część), 12 (weryfikator) i 13. Jeden działający krok: zmiana kontraktu złożenia razem z minimalną kasą w sklepie, tak że po scaleniu cały istniejący zestaw E2E przechodzi. Ordering: value object `OrderDelivery` z udziałami VAT, `Order::place(…, OrderDelivery)` (odtworzenie z `null`), `OrderTotals::of($lines, ?OrderDelivery)` z `goodsGross()`, port `OrderDeliveryQuotes` z adapterem `Ordering/Infrastructure/Delivery/QueryBusOrderDeliveryQuotes`, nowa metoda portu `OrderPrices` w `QueryBusOrderPrices` (`SplitDeliveryCost` z zadania 2), pole `deliveryMethodId` żądań złożenia gościa i klienta z komunikatami W5, kroki złożenia po odrzuceniu kodu i przed kontrolą sumy, 409 `delivery-method-unavailable` w `OrderConflictProblem` (`nelmio_api_doc.yaml`), odcisk żądania z `delivery:<id>`, `delivery` w `OrderView`, JSON klienta, gościa i panelu, wiersz dostawy w e-mailu z potwierdzeniem, sprzedaż godzinowa z `goodsGross()` i `SUM(total_gross - delivery_gross)` w przebudowie (suma klienta bez zmian), `DoctrineOrderRepository` (kolumny dostawy, rekord udziałów, odczyt dostawy), `DbalOrderingReset`. Migracje: kolumny (także `tracking_number` z jego CHECK, bez mapowania numeru, które dodaje zadanie 7) i tabela `ordering_order_delivery_vat` z CHECK `NOT VALID`, osobna migracja `VALIDATE CONSTRAINT`. Demo: `DemoData/Verify/OrdersVerifier.php` z `delivery_net`, `delivery_vat` i `delivery_gross` w krotce sum. Kontrakt: `openapi.json`, `openapi-admin.json` i oba wygenerowane klienty w tym samym zadaniu; sklep: powód `delivery_method_unavailable` w `apps/store/src/checkout/problems.ts` (wymaga go `satisfies`) i typowane dane testowe zamówień sklepu i panelu z nowym polem. Minimalna kasa (`CheckoutPage.tsx`): wymagana grupa radio „Sposób dostawy” z etykietami z `cart.delivery.options` (nazwa, opis, koszt albo „Darmowa”, „Do darmowej dostawy brakuje Y zł”), sekcja tylko przy `delivery` różnym od `null` (inaczej dzisiejsze zachowanie pustego koszyka), wiersz „Dostawa” i „Do zapłaty” = `option.totalGross` w podsumowaniu, `deliveryMethodId` i `expectedTotalGross` = `option.totalGross` w `PlaceOrderInput` (`apps/store/src/api/client.ts`) i na liście pól BFF (`apps/store/src/checkout/api.ts`), komunikat „Wybierz sposób dostawy.” przed wysłaniem; 409 `delivery-method-unavailable` i 422 przy `deliveryMethodId` pokazane dzisiejszą ścieżką komunikatu nad formularzem. E2E: `e2e/support/` wybiera metodę przy składaniu zamówienia, istniejące testy, które składają zamówienia albo sprawdzają sumy, uwzględniają dostawę. Dokumentacja: zasada `NOT VALID` + `VALIDATE CONSTRAINT` w `apps/api/CLAUDE.md` (jedyna zmiana `CLAUDE.md` w epiku); `docs/features/checkout/spec.md` (P3, „Poza zakresem”, wybór metody w kasie), `docs/features/order-management/spec.md` (zwrot pełnej kwoty z dostawą), `docs/features/admin-features/spec.md` (przychód bez dostawy, suma klienta z dostawą), `docs/features/demo-data/spec.md` (kontrola sumy z dostawą). Nie obejmuje: pozostałych stanów i przepływów kasy oraz nowych scenariuszy E2E kasy (6), numeru przesyłki i widoków przesyłki (7), wyświetlania dostawy w panelu i na koncie (7).",
      "acceptance_criteria": [
        "TDD: testy jednostkowe `OrderDelivery`, `OrderTotals`, `Order::place` i `PlaceOrderHandler` (adaptery in-memory) z dostawą uruchomione przed implementacją nie przechodzą, po niej przechodzą; raport zawiera komendy i wyniki obu uruchomień.",
        "Testy jednostkowe: niezmienniki `OrderDelivery` (Σ udziałów = brutto, netto + VAT = brutto, udział > 0, stawka udziału jest stawką linii, brutto 0 ⇔ brak udziałów); sumy zamówienia obejmują dostawę, `goodsGross()` = Σ linii; `markPaid` przyjmuje tylko kwotę z dostawą; zwrot przy anulowaniu i zwrocie towaru = suma z dostawą (P13); zamówienie odtworzone bez dostawy przechodzi wszystkie przejścia jak dziś; `Order::place` wymaga `OrderDelivery` (P19).",
        "Testy funkcjonalne złożenia (gość i klient): zamówienie ma kopię nazwy metody i szablonu, koszt, udziały VAT i sumę z dostawą; brak klucza i `null` → 422 bez zamówienia (P19) przy `deliveryMethodId` „Kasa została zaktualizowana. Odśwież stronę i wybierz sposób dostawy.”; nie-UUID i zły typ → 422 „Nieprawidłowy sposób dostawy. Odśwież stronę i wybierz go ponownie.”; metoda nieaktywna albo nieznana → 409 `delivery-method-unavailable` z polskim `detail`, bez zamówienia, rezerwacji, użycia kodu i zmiany koszyka; zmiana ceny metody i kod obniżający wartość poniżej progu → 409 `OrderTotalChanged`; kolejność odrzuceń z W5; powtórzenie z tym samym kluczem i metodą zwraca to samo zamówienie, inna metoda z tym samym kluczem → 409 `idempotency-key-reused`; `PlaceOrderConcurrencyTest` przechodzi z metodą dostawy.",
        "Testy integracyjne: repozytorium zapisuje i odczytuje dostawę i udziały (numer przesyłki mapuje zadanie 7); migracje na bazie z istniejącymi zamówieniami zostawiają je bez metody z dostawą 0, a po `VALIDATE CONSTRAINT` każdy CHECK z W11 odrzuca swoje złamanie; `OrderingSchemaTest` obejmuje nowe kolumny i tabelę; `DbalOrderingReset` obcina tabelę udziałów.",
        "Test statystyk: opłacenie zamówienia z dostawą dodaje do `ordering_sales_hour` towary bez dostawy, a do `ordering_customer.paid_gross` kwotę z dostawą; `ordering:read-models:rebuild` daje te same liczby; anulowanie i zwrot nie zmieniają żadnego (zmiana testów sprzedaży razem z nową regułą P14).",
        "Test e-maila z potwierdzeniem: wiersz „Dostawa (<nazwa>): 19,99 zł” przed sumą, „0,00 zł” przy darmowej dostawie; `OrderView`, JSON klienta, gościa i panelu mają `delivery` (`null` dla zamówienia bez metody).",
        "`OrdersVerifier` przechodzi na wygenerowanych zamówieniach (dostawa 0) i zgłasza zamówienie, którego `total_net`, `total_vat` albo `total_gross` nie równa się sumie linii z odpowiednio `delivery_net`, `delivery_vat` albo `delivery_gross`, albo którego wartość linii − rabat + `delivery_gross` nie równa się `total_gross`.",
        "Commitowane `openapi.json`, `openapi-admin.json` i oba klienty są aktualne (sprawdzenie CI); `problems.ts` mapuje nowy typ 409; `apps/api/CLAUDE.md` ma zasadę `NOT VALID` + `VALIDATE CONSTRAINT`; dokumenty z zakresu opisują nowe zachowanie.",
        "Vitest kasy (część minimalna): grupa radio z `fieldset` i `legend` „Sposób dostawy” i etykietą każdej opcji; bez wyboru „Wybierz sposób dostawy.” przy grupie, focus na pierwszej opcji i brak żądania; żądanie niesie `deliveryMethodId` i `expectedTotalGross` = `option.totalGross`; wiersz „Dostawa” i „Do zapłaty” z wybranej opcji („Wybierz sposób dostawy” bez wyboru); `delivery: null` bez sekcji i z dzisiejszym zachowaniem pustego koszyka; 409 `delivery-method-unavailable` i 422 przy `deliveryMethodId` jako komunikat nad formularzem; grupa działa z klawiatury, ekran 375 px bez przewijania w poziomie. Test BFF: `deliveryMethodId` przechodzi do API dla gościa i klienta, inne nieznane klucze dalej są odrzucane.",
        "Cały istniejący zestaw E2E (`make e2e`) przechodzi po tym zadaniu: pomocnicy w `e2e/support/` wybierają metodę dostawy, a testy składające zamówienia albo sprawdzające sumy uwzględniają dostawę.",
        "`make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check`, `make -C apps/store test`, `make -C apps/admin check` i `make -C apps/admin test` przechodzą."
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DELIVERY-1",
        "DELIVERY-2",
        "DELIVERY-3"
      ],
      "paths": [
        "apps/api/src/Ordering/",
        "apps/api/src/DemoData/Verify/OrdersVerifier.php",
        "apps/api/config/packages/nelmio_api_doc.yaml",
        "apps/api/migrations/",
        "apps/api/tests/",
        "apps/api/openapi.json",
        "apps/api/openapi-admin.json",
        "apps/api/CLAUDE.md",
        "apps/store/src/api/",
        "apps/store/src/checkout/",
        "apps/store/src/account/",
        "apps/admin/src/api/schema.d.ts",
        "apps/admin/tests/",
        "e2e/support/",
        "e2e/tests/checkout-guest.spec.ts",
        "e2e/tests/checkout-customer.spec.ts",
        "e2e/tests/checkout-guest-limits.spec.ts",
        "e2e/tests/discount-codes.spec.ts",
        "e2e/tests/payment-resume.spec.ts",
        "e2e/tests/visual-screens.spec.ts",
        "e2e/tests/order-management.spec.ts",
        "e2e/tests/admin-screens-orders.spec.ts",
        "e2e/tests/admin-customers.spec.ts",
        "e2e/tests/admin-design.spec.ts",
        "e2e/tests/seo.spec.ts",
        "docs/features/checkout/spec.md",
        "docs/features/order-management/spec.md",
        "docs/features/admin-features/spec.md",
        "docs/features/demo-data/spec.md"
      ],
      "parallel_with": [
        "DELIVERY-5"
      ]
    },
    {
      "id": "DELIVERY-5",
      "title": "Panel: ekran „Dostawa”",
      "scope": "Spec: W9 (ekran „Dostawa”, bez szczegółów zamówienia), decyzje P7 i P16, kryterium 11 (ekran). Panel: `apps/admin/src/delivery/` (uprawnienia, odczyt i zapis przez wygenerowanego klienta z zadania 1), widoki `DeliveryMethodsView` i `DeliveryMethodFormView` w `apps/admin/src/views/` (wzorzec `DiscountCodeFormView`, `parseGross`), trasy w `apps/admin/src/router/index.ts`, pozycja menu „Dostawa” z `visible(staff)` tylko dla `admin` w `apps/admin/src/layout/menu.ts`; lista z nieaktywnymi pod aktywnymi, licznik „Aktywne: N z 20”, stany ładowania, błędu i pustej listy, formularz z polami i podpowiedzią z W9, naruszenia przy polach, 409 nad formularzem z focusem. E2E: nowy plik `e2e/tests/admin-screens-delivery.spec.ts` (własne metody z unikalną nazwą, droższe od seeda, kolejność 999, na końcu wyłączone). Dokumentacja: `docs/features/admin-screens/spec.md` (ekran „Dostawa”). Nie obejmuje: szczegółów zamówienia i okna „Wyślij” (7), zmian API.",
      "acceptance_criteria": [
        "Vitest: lista w kolejności API z etykietą „Nieaktywna” pod aktywnymi, „Aktywne: N z 20”, „—” bez progu; stany ładowania, błędu z „Spróbuj ponownie” i pustej listy z tekstem z W9; formularz dodania i zmiany z etykietami, kwotami w zł z przecinkiem, pustym „Darmowa od” jako `null`; naruszenia API przy polach; 409 nazwy, limitu i wersji nad formularzem z focusem; pozycja menu tylko dla `admin`.",
        "Ekran działa z klawiatury, każda kontrolka ma etykietę, statusy zapisu i błędów są w `aria-live`, ekran 375 px bez przewijania w poziomie (istniejące testy dostępności i reflow panelu obejmują nowe trasy).",
        "E2E `admin-screens-delivery.spec.ts`: administrator dodaje metodę, zmienia ją i wyłącza (widoczna jako „Nieaktywna”); błąd walidacji przy polu; rola bez dostępu nie widzi pozycji menu, a wejście na adres ekranu daje dzisiejszy stan braku dostępu (403); wygasła sesja (401) prowadzi do logowania jak na innych ekranach; metody seeda bez zmian.",
        "`docs/features/admin-screens/spec.md` opisuje ekran „Dostawa”.",
        "`make -C apps/admin check` i `make -C apps/admin test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DELIVERY-1"
      ],
      "paths": [
        "apps/admin/src/delivery/",
        "apps/admin/src/views/DeliveryMethodsView.vue",
        "apps/admin/src/views/DeliveryMethodFormView.vue",
        "apps/admin/src/router/index.ts",
        "apps/admin/src/layout/menu.ts",
        "apps/admin/tests/",
        "e2e/tests/admin-screens-delivery.spec.ts",
        "docs/features/admin-screens/spec.md"
      ],
      "parallel_with": [
        "DELIVERY-2",
        "DELIVERY-3",
        "DELIVERY-4",
        "DELIVERY-6"
      ]
    },
    {
      "id": "DELIVERY-6",
      "title": "Kasa: pozostałe stany i przepływy dostawy oraz nowe scenariusze E2E",
      "scope": "Spec: W10 (kasa poza częścią z zadania 4), decyzje P6 i P8, kryterium 8 (reszta). Sklep (`CheckoutPage.tsx`): wstępny wybór tylko przy jednej opcji; wybór zachowany po odświeżeniu podglądu (kod, zmiana sum), jeśli metoda dalej jest na liście, inaczej usunięty; utrata darmowej dostawy po kodzie widoczna od razu w opcjach i podsumowaniu, z ogłoszeniem w regionie `aria-live` podsumowania; opcja z kosztem 0 pokazuje „Darmowa” bez „Do darmowej dostawy brakuje”; stan bez aktywnych metod (`Alert` z tekstem W10, nieaktywny przycisk złożenia); 409 `delivery-method-unavailable`: komunikat z focusem, nowy `Idempotency-Key`, ponowny odczyt podglądu, wybór usunięty, jeśli metody nie ma. Strona potwierdzenia płatności nie ma wierszy pozycji (checkout spec, P7), więc bez zmian. E2E: nowy plik `e2e/tests/delivery-checkout.spec.ts` (zamówienie z dostawą płatną i darmową, scenariusz P6). Dokumentacja: `docs/features/checkout/spec.md` (stany kasy z dostawą). Nie obejmuje: kontraktu i minimalnej kasy (4), strony konta (7).",
      "acceptance_criteria": [
        "Vitest kasy: wstępny wybór tylko przy jednej opcji; wybór zostaje po zastosowaniu kodu, jeśli metoda jest dalej na liście, i znika, jeśli jej nie ma; po kodzie, który obniża wartość poniżej progu, opcja pokazuje koszt i „Do darmowej dostawy brakuje”, „Do zapłaty” z nowej `option.totalGross`, zmiana ogłoszona w `aria-live`; opcja z kosztem 0 pokazuje „Darmowa” bez „Do darmowej dostawy brakuje”; brak metod: `Alert` z tekstem W10 i nieaktywny przycisk; 409 `delivery-method-unavailable`: komunikat z focusem, nowy `Idempotency-Key`, ponowny odczyt podglądu, wybór usunięty, gdy metody nie ma; ekran 375 px i obsługa klawiaturą nowych stanów.",
        "E2E `delivery-checkout.spec.ts`: zamówienie gościa i klienta z dostawą płatną (poniżej progu) i darmową (powyżej progu), suma zamówienia z dostawą na stronie potwierdzenia; scenariusz P6: koszyk powyżej progu po wpisaniu kodu spada poniżej progu i ma płatną dostawę w odczycie koszyka z kodem (podgląd koszyka w kasie), w podsumowaniu kasy i w złożonym zamówieniu; asercje bez zakładania liczby metod.",
        "`make e2e` przechodzi; `docs/features/checkout/spec.md` opisuje stany kasy z dostawą.",
        "`make -C apps/store check` i `make -C apps/store test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DELIVERY-4"
      ],
      "paths": [
        "apps/store/src/checkout/CheckoutPage.tsx",
        "apps/store/src/checkout/CheckoutPage.test.tsx",
        "e2e/tests/delivery-checkout.spec.ts",
        "docs/features/checkout/spec.md"
      ],
      "parallel_with": [
        "DELIVERY-5",
        "DELIVERY-7"
      ]
    },
    {
      "id": "DELIVERY-7",
      "title": "Numer przesyłki: wysyłka, poprawienie po wysyłce, e-mail, konto klienta i panel",
      "scope": "Spec: W8, W8a, W9 (szczegóły zamówienia, okno „Wyślij” i akcja numeru), W10 (konto), A6, decyzje P1, P9, P10, P15 (stara karta panelu) i P18, kryteria 9, 10 i 11 (okna panelu). API Ordering: wymagany klucz `trackingNumber` w `ChangeOrderStatusRequest` z komunikatami W8, value object `TrackingNumber`, `Order::ship(staff, note, ?TrackingNumber, now)`, mapowanie kolumny `tracking_number` z migracji zadania 4 (zapis w `writeChanges()`, odczyt w `toDomain()`, bez nowej migracji), `shipment` (tylko z numerem) w `OrderView` i JSON klienta, gościa i panelu (link z kopii szablonu), w `OrderShippedText` „Sposób dostawy: <nazwa>” przy metodzie oraz numer i link tylko przy numerze; endpoint `PUT /api/v1/admin/orders/{orderId}/tracking-number` z treścią `{trackingNumber, version}` (W8a): atrybut `OrderVoter` dla `admin` i `warehouse`, `StaffOrderLock::lock` z wersją jak zmiana statusu, `Order::changeTrackingNumber` ze strażnikiem statusu `shipped`/`delivered`, 409 `tracking-number-not-changeable` w `BackofficeOrderConflictProblem`, bez zdarzenia, e-maila i wpisu historii; `OrderShipped` bez zmian. Kontrakt: `openapi.json`, `openapi-admin.json` i oba klienty. Sklep: wiersz dostawy (nazwa metody i koszt) i, tylko przy `shipment`, numer z linkiem „Śledź przesyłkę (otwiera się w nowej karcie)” w `apps/store/src/account/OrderDetailPage.tsx`. Panel: wiersz dostawy (nazwa metody i koszt) oraz numer i link tylko przy `shipment` w `OrderDetailView.vue`, pole „Numer przesyłki” w oknie „Wyślij” (`apps/admin/src/orders/useOrderDetail.ts`), akcja „Zmień numer przesyłki” / „Dodaj numer przesyłki” z małym oknem dla `admin` i `warehouse`. E2E: nowy plik `e2e/tests/delivery-tracking.spec.ts`, dostosowanie `order-management.spec.ts` i `admin-screens-orders.spec.ts` do pola numeru i akcji. Dokumentacja: `docs/features/order-management/spec.md` (reguła wysyłki z numerem, poprawianie numeru, „Poza zakresem”), `docs/features/admin-screens/spec.md` (dostawa i przesyłka w szczegółach zamówienia, pole w oknie „Wyślij”, akcja numeru). Zależność od 4: widoki `delivery` i składanie zamówień przez kasę w E2E. Nie obejmuje: statusów przesyłki, ponownego e-maila po zmianie numeru.",
      "acceptance_criteria": [
        "TDD: testy jednostkowe `TrackingNumber`, `Order::ship` z numerem i `Order::changeTrackingNumber` uruchomione przed implementacją nie przechodzą, po niej przechodzą; raport zawiera komendy i wyniki obu uruchomień.",
        "Testy funkcjonalne zmiany statusu: `null` i tekst z samych białych znaków (także Unicode) to brak numeru przy każdym przejściu; `ship` z numerem i bez; przy `ship` zły typ, polskie znaki, spacja w środku, 41 znaków, NUL → 422 przy `trackingNumber` z komunikatem formatu W8; przy `deliver`, `cancel` i `return` każda inna wartość, także w złym formacie i złego typu → 422 „Numer przesyłki można podać tylko przy wysyłce.” (pierwszeństwo przed komunikatem formatu); brak klucza → 422 „To pole jest wymagane.” (zmiana testów wysyłki razem z nowym kluczem). Test integracyjny repozytorium: numer zapisany przy wysyłce wraca po odczycie.",
        "Testy funkcjonalne `PUT /api/v1/admin/orders/{orderId}/tracking-number`: `admin` i `warehouse` ustawiają, zastępują i usuwają (`null`, same białe znaki Unicode) numer w zamówieniu `shipped` i `delivered`; zły format i zły typ → 422 przy `trackingNumber` z komunikatami W8; brak klucza → 422 „To pole jest wymagane.”; status `new`, `paid`, `cancelled`, `returned` → 409 `tracking-number-not-changeable` z polskim `detail`; inna wersja → 409 konfliktu wersji; `support` i `marketing` → 403, bez tokena 401, nieznane zamówienie i nie-UUID → 404; nic się nie zmienia przy odmowie; nie powstaje zdarzenie, e-mail ani wpis historii. Test jednostkowy `Order::changeTrackingNumber` (TDD, strażnik statusu).",
        "Testy widoków: `shipment` `null` bez numeru (a `delivery.methodName` dalej jest), z numerem: przewoźnik = nazwa metody, numer i link z zakodowanym numerem; `carrier` i `trackingUrl` `null` odpowiednio bez metody i bez szablonu. Test e-maila o wysyłce: „Sposób dostawy: <nazwa>” przy metodzie także bez numeru, „Numer przesyłki” i „Śledzenie przesyłki” tylko przy numerze (link tylko z szablonem), bez wiersza „Przewoźnik”; test kształtu `OrderShipped` z ASYNC-RELIABILITY przechodzi bez nowych zapisów.",
        "Vitest: strona zamówienia klienta z wierszem dostawy, a numerem i linkiem `target=\"_blank\"` `rel=\"noopener noreferrer\"` tylko przy `shipment`, bez wiersza dostawy dla zamówienia bez metody; szczegóły zamówienia w panelu z dostawą, a numerem i linkiem tylko przy `shipment`; okno „Wyślij” z opcjonalnym polem tylko dla `ship` i naruszeniem przy polu. Okno „Zmień numer przesyłki” („Dodaj numer przesyłki” bez numeru) tylko dla `admin` i `warehouse` w zamówieniu `shipped` i `delivered`: pole z etykietą, focus wraca na akcję po zamknięciu, wynik i błędy (422, 409, 403) w `aria-live`.",
        "Strona zamówienia klienta i szczegóły zamówienia w panelu działają z klawiatury (link śledzenia, pole numeru w oknie „Wyślij”) i na ekranie 375 px bez przewijania w poziomie.",
        "E2E `delivery-tracking.spec.ts`: pracownik wysyła zamówienie z numerem; numer i link widać na stronie zamówienia klienta i w e-mailu w Mailpit; pracownik poprawia numer w panelu, a strona zamówienia klienta pokazuje nowy numer i link; `order-management.spec.ts` i `admin-screens-orders.spec.ts` przechodzą z nowym polem.",
        "Commitowane `openapi.json`, `openapi-admin.json` i oba klienty są aktualne (sprawdzenie CI); dokumenty z zakresu opisują numer przesyłki.",
        "`make -C apps/api check`, `make -C apps/api test`, `make -C apps/store check`, `make -C apps/store test`, `make -C apps/admin check` i `make -C apps/admin test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DELIVERY-4"
      ],
      "paths": [
        "apps/api/src/Ordering/",
        "apps/api/config/packages/nelmio_api_doc.yaml",
        "apps/api/tests/",
        "apps/api/openapi.json",
        "apps/api/openapi-admin.json",
        "apps/store/src/api/schema.d.ts",
        "apps/store/src/api/client.test.ts",
        "apps/store/src/checkout/PaymentConfirmation.test.tsx",
        "apps/store/src/account/",
        "apps/admin/src/api/schema.d.ts",
        "apps/admin/src/orders/",
        "apps/admin/src/views/OrderDetailView.vue",
        "apps/admin/tests/",
        "e2e/tests/delivery-tracking.spec.ts",
        "e2e/tests/order-management.spec.ts",
        "e2e/tests/admin-screens-orders.spec.ts",
        "docs/features/order-management/spec.md",
        "docs/features/admin-screens/spec.md"
      ],
      "parallel_with": [
        "DELIVERY-6"
      ]
    }
  ]
}
```
