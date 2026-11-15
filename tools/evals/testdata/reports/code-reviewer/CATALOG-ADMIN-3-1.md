## Review CATALOG-ADMIN-3: przeliczenie cen wariantów po zmianie klasy podatkowej

**SHA:** HEAD `1c35b5d45ddbbdb916edc610df9276ef0c2a25f3`. Potwierdziłem go w checkoucie `<home>/Development/claude/shop-CATALOG-ADMIN-3` i drzewo robocze było czyste. Baza to `74b693a5c2fc6386f4100576815f3c0a66c57902`, 5 commitów.
**Tryb:** FULL
**Werdykt:** PASS. Nie znalazłem nic na poziomie Critical ani Important. Są 3 uwagi Minor (sugestie).

### Trzy kontrole obowiązkowe

1. **Istniejące testy: bez uwag.** Diff nie usuwa żadnej linii testów. Do istniejących plików tylko dopisuje: dwa testy w `tests/Integration/Pricing/Catalog/QueryBusProductDirectoryTest.php` oraz `taxClassOf`, `withTaxClass` i `afterTaxClassRead` w `tests/Unit/Pricing/InMemoryProductDirectory.php`. Testy `ReconcileTax` są niezmienione i chronią wydzieloną pętlę (kryterium 4). Nie ma `skip`, `only` ani zmienionych wartości oczekiwanych.
2. **Zakres: bez uwag.** Zmiany dotyczą Pricing Application (`PriceReconciler`, `RepriceProductPrices`, `TaxClassChangedWhileRepricing`, port `ProductDirectory::taxClassOf` z `ProductTaxClass`) i Pricing Infrastructure (konsument, adapter), do tego testy i dokumentacja: `docs/features/pricing/spec.md`, `apps/api/CLAUDE.md` oraz `docs/features/variants/spec.md`, gdzie usunięto nieaktualny kontrakt „Przyszła zmiana klasy”. Catalog, `deptrac.yaml` ani konfiguracja się nie zmieniły.
3. **RED przed implementacją: prawdziwy RED.** Przejrzałem dziennik `<home>/.local/state/ao-catalog-admin/tasks/CATALOG-ADMIN-3/delivery-state.json.evidence.jsonl`.
   - `QueryBusProductDirectoryTest`: RED na head `00729cf`, w zmianach tylko plik testu, błąd „undefined method taxClassOf()” w testach `testTaxClassOf…`. GREEN przyszedł później.
   - `RepriceProductPricesTest`: RED na `42e63c2`, w zmianach tylko test, błąd „Class RepriceProductPrices not found” we wszystkich 13 testach. GREEN przyszedł później.
   - `RepriceOnTaxClassChangeTest` (unit i integracja): RED na `fc8213f`, w zmianach tylko dwa pliki testów. W integracji była prawdziwa porażka asercji: ceny zostały w `standard`, wersja 1. Pierwsze uruchomienie GREEN skończyło się kodem 1, drugie kodem 0.
   - Żaden plik produkcyjny badanego zachowania nie był w `head` ani w zmianach wpisu RED. Commit `00729cf` to refaktor bez zmiany zachowania, chroniony istniejącymi testami. Zgadza się to z `tdd.status: verified`.

### Zgodność z kryteriami akceptacji
- **Kryterium 1:** spełnione. Test integracyjny `tests/Integration/Pricing/RepriceOnTaxClassChangeTest.php` przechodzi pełną trasę: EditProduct, outbox, RabbitMQ, konsument. Sprawdza to samo brutto, stawkę 500, wersję 2, historię `tax_reconciliation` bez aktora, dwa `VariantPriceChanged` i powtórzone zdarzenie bez zapisu.
- **Kryterium 2:** spełnione. Data provider A→B→A (4 kolejności) i `testPricesAlreadyInTheClassOfTheProductAreNotWritten` (0 zapisów, ponowny odczyt klasy tylko raz).
- **Kryterium 3:** spełnione. Testy jednostkowe konsumenta: brak stawki daje `UnrecoverableMessageHandlingException` z klasą i produktem, drugi konflikt daje `PriceModifiedConcurrently` po zapisie pozostałych cen, a ponowienie dokańcza pracę. Nieznany produkt: 0 zapisów.
- **Kryterium 4:** spełnione. `PriceReconciler::reconcile/reprice/saved` to te same metody co dawniej w `ReconcileTax`, zmieniła się tylko nazwa parametru. `ReconcileTax` nadal resetuje sesję po każdej partii.
- **Kryterium 5:** spełnione, z drobną luką opisaną w M2.
- **Kryterium 6:** wynik podał orkiestrator. Niczego nie uruchamiałem, bo działałem tylko w trybie odczytu.

### Ocena decyzji developera
- **(a) `ProductDirectory::taxClassOf`:** uzasadnione. Port już czyta `ListProductVariants(includeDrafts: true)`, a nowa metoda używa tego samego zapytania. Adapter `src/Pricing/Infrastructure/Catalog/QueryBusProductDirectory.php:83-116` współdzieli `variantLists`. Catalog czyta przez DBAL (`ofProducts`, `JOIN` po wariantach), bez mapy tożsamości, więc ponowny odczyt w tym samym handlerze widzi stan po zatwierdzonych zmianach.
- **(b) `PriceReconciler` w konstruktorach:** akceptowalne. Klasa jest bezstanowa, dostaje te same zależności co jej właściciele, a sygnatura `ReconcileTax` się nie zmieniła. Szczegóły w M3.
- **(c) Cena w klasie produktu z nieaktualną stawką nie jest zapisywana:** zgodne ze spec K6 („Każda cena z klasą inną niż klasa produktu”) i opisane w dokumentacji.
- **(d) Ponowny odczyt klasy i `TaxClassChangedWhileRepricing`:** potrzebne i poprawne.
  - Wyścig: W1 przeczytał B zanim zatwierdzono B→A. W2 przeczytał A i znalazł ceny w A, więc nic nie zapisał. Potem W1 zapisał B.
  - Bez ponownego odczytu ceny zostałyby w B przy produkcie w A, wbrew spec.
  - Ponowny odczyt idzie w autocommit (READ COMMITTED; na `event.bus` nie ma `doctrine_transaction`), po zapisach W1. Odczyt klasy przez W2 nastąpił przed jego odczytem cen, a ten przed zapisami W1. Dlatego W1 zawsze zobaczy A i rzuci wyjątek do ponowienia.
  - Pozostałe przeplecenia też się domykają: częściowe zapisy obu workerów, konflikt wersji z ponownym odczytem ceny do B, po którym ponowny odczyt klasy daje A.
  - Limit ponowień transportu `events` (3: 1 s, 3 s, 9 s) wystarcza.
- **(e) Kolejność zgłaszania błędów:** brak stawki daje od razu `Unrecoverable`. Klasa jest wspólna dla wszystkich cen, więc nic nie zostaje zapisane. Konflikt wersji jest rzucany na końcu, co pozwala dokończyć pozostałe ceny, a ponowienie jest idempotentne.
- **Sesja Doctrine:** po odrzuconym zapisie `DoctrinePersistenceSession::reset()` podmienia zamknięty EntityManager, tak jak w `reconcile-tax`. Każdy `save` ma własną transakcję razem z outboxem. Worker czyści EM między wiadomościami.
- **Wiele handlerów `ProductChanged`:** przy ponowieniu Messenger zachowuje `HandledStamp`, więc `IndexProduct` i `InvalidateStorefrontOnProductChange` nie wykonają się ponownie. To istniejący wzorzec.
- **Deptrac:** `Pricing_Infrastructure → Catalog_Application` jest dozwolone. W `Pricing_Application` nie ma nowych zależności.

### Ustalenia

**Minor**

- **M1. Okno wyścigu przy tworzeniu pierwszej ceny, nieopisane w spec.**
  - Kod: `src/Pricing/Application/Command/SetVariantPriceHandler.php:50-58` (kod sprzed tego taska) bierze klasę z Catalog (`taxClassOf($variantId)`) przed `VariantPrice::create`. `RepriceProductPrices` (`src/Pricing/Application/TaxReconciliation/RepriceProductPrices.php:54`) przelicza tylko ceny istniejące w chwili `findMany`.
  - Przebieg: panel czyta klasę A, potem zatwierdzona zostaje zmiana na B, konsument nie znajduje ceny wariantu, a na końcu zapisuje się pierwsza cena w klasie A.
  - Skutek: ta cena zostaje w klasie A, a `isCurrent` uznaje ją za aktualną. Brutto jest poprawne, ale rozbicie VAT nie. Wykryje to dopiero `pricing:reconcile-tax`.
  - Okno jest wąskie, a K6 tego przypadku nie obiecuje. Proponuję dopisać go do „Przeliczenie po zmianie klasy podatkowej” w `docs/features/pricing/spec.md` jako znany przypadek, który obsługuje `reconcile-tax`. To nie jest błąd tego diffu.
- **M2. Nieaktualne opisy zależności Pricing → Catalog.**
  - `docs/features/pricing/spec.md:24-25` mówi, że zależność to tylko „adaptery portów Pricing wołają zapytania Catalog przez `query.bus`”. Teraz `Pricing_Infrastructure` subskrybuje też integracyjne `ProductChanged`. Wspomina o tym punkt dodany niżej (l. 39-41), ale punkt o kierunku zależności jest niekompletny.
  - `docs/features/pricing/spec.md:31` mówi „`ProductId` tylko w liście cen panelu”, a teraz używają go też `RepriceProductPrices` i `ProductTaxClass`.
  - Komentarz w `apps/api/deptrac.yaml:239-240` („Adapters of Pricing's ports ask Catalog through its queries…”) nie wspomina o konsumencie zdarzenia.
  - Zasada „dokumentacja opisuje stan obecny” wymaga poprawienia tych zdań. Zmiana w deptrac dotyczy tylko komentarza, nie reguł.
- **M3. `PriceReconciler` tworzony przez `new` w konstruktorach** (`ReconcileTax.php:38`, `RepriceProductPrices.php:35`).
  - Działa poprawnie, ale ukrywa współpracownika. Autowiring i tak zarejestruje tę klasę jako nieużywany serwis.
  - Wstrzyknięcie byłoby prostsze, kosztem zmiany konstruktora `ReconcileTax` i jego testów. Sugestia, bez wymogu zmiany.

Nie znalazłem błędów logiki, kontraktów ani współbieżności w zmianach tego taska. Testy pokrywają wszystkie kryteria, a złożoność (ponowny odczyt klasy) ma uzasadnienie w realnym wyścigu.

### Pliki
- `<home>/Development/claude/shop-CATALOG-ADMIN-3/apps/api/src/Pricing/Application/TaxReconciliation/PriceReconciler.php`
- `<home>/Development/claude/shop-CATALOG-ADMIN-3/apps/api/src/Pricing/Application/TaxReconciliation/RepriceProductPrices.php`
- `<home>/Development/claude/shop-CATALOG-ADMIN-3/apps/api/src/Pricing/Application/TaxReconciliation/ReconcileTax.php`
- `<home>/Development/claude/shop-CATALOG-ADMIN-3/apps/api/src/Pricing/Infrastructure/Catalog/RepriceOnTaxClassChange.php`
- `<home>/Development/claude/shop-CATALOG-ADMIN-3/apps/api/src/Pricing/Infrastructure/Catalog/QueryBusProductDirectory.php`
- `<home>/Development/claude/shop-CATALOG-ADMIN-3/apps/api/src/Pricing/Application/Command/SetVariantPriceHandler.php` (M1)
- `<home>/Development/claude/shop-CATALOG-ADMIN-3/docs/features/pricing/spec.md` (M2)
- `<home>/Development/claude/shop-CATALOG-ADMIN-3/apps/api/deptrac.yaml` (M2)