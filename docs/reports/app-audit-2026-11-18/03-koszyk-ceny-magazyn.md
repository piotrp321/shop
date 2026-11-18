# 03. Poprawność biznesowa koszyka, cen, promocji, kodów rabatowych i magazynu

Audyt statyczny migawki `main` (`ef204620`). Ścieżki względem katalogu eksportu.

## Zakres i metoda

Prześledzone pełne ścieżki wykonania od kontrolera do bazy dla: zmian koszyka, scalania, złożenia zamówienia
(blokada koszyka → wycena → użycie kodu → rezerwacja → zamówienie), wygaśnięcia nieopłaconego zamówienia, zmian statusu
przez pracownika, zmiany ceny, zapisu promocji, zapisu i użycia kodu, przyjęcia i korekty stanu.

Przeczytane w całości albo w części istotnej dla ścieżki (ok. 115 plików):

- `apps/api/src/Cart` (115 plików, 4,8 tys. linii): agregat `Cart`, wszystkie handlery komend i zapytań, `LockedCarts`,
  `CartViewBuilder`, `DoctrineCartRepository`, `DbalCartReader`, `DbalExpiredCarts`, adaptery do Pricing/Inventory/Catalog,
  `GuestCartController`.
- `apps/api/src/Inventory` (131 plików, 6,2 tys. linii): `StockItem`, `Reservation`, handlery `ReserveStock`,
  `Release/CommitReservation`, `ReceiveStock`, `CorrectStock`, oba repozytoria DBAL, `DbalStockRecords`,
  `GetStockLevels`, konsumenci `StockLevelChanged`.
- `apps/api/src/Pricing` (268 plików, 13,2 tys. linii): `PriceBreakdown`, `VariantPrice`, `EffectivePrice`,
  `PromotionalPrice`, `LowestPriorPrice`, `Promotion`, `PromotionPeriod`, `BasketQuote`, `DiscountCode`,
  `DiscountCodeUsage`, wszystkie handlery komend, `QuoteBasketHandler`, `GetCurrentPricesHandler`, repozytoria,
  `DbalActivePromotions`, `DbalOmnibusHistory`, `PostgresPromotionWriteLock`, przeliczenie po zmianie klasy podatkowej.
- Styk z Ordering: `PlaceOrderHandler`, `ExpireUnpaidOrderHandler`, `ChangeOrderStatusHandler`, `MarkOrderPaidHandler`,
  `Order`, `OrderStatus`, `OrderTotals`, `LineAmounts`, adaptery `CommandBus*`, `OrderPlacement`.
- Konfiguracja: `config/packages/messenger.yaml`, `rate_limiter.yaml`, `doctrine.yaml`, `security.yaml` (reguły koszyka).
- Migracje tabel `cart_*`, `inventory_*`, `pricing_*`, `ordering_order*` (constrainty, indeksy, typy kwot).
- Specyfikacje: `cart`, `inventory`, `pricing`, `promotions`, `discount-codes` (w całości albo sekcje decyzji i ryzyk),
  fragmenty `checkout`, `order-management`, `hardening`, `docs/vision.md` (wymagania niefunkcjonalne).
- Sklep: `apps/store/src/cart/merge.ts`, użycia `expectedTotalGross`, `hasExcludedLines`, `lowestPriorGross`.
- Testy: tylko lista scenariuszy współbieżności (`tests/Integration/*/Concurrency`), bez uruchamiania.

Metoda: dla każdej operacji zapisu ustalona kolejność blokad i odczytów, potem ręczne przeplatanie dwóch transakcji
w READ COMMITTED. Każde znalezisko sprawdzone względem specyfikacji (czy to świadoma decyzja) i względem innych warstw
(constraint, middleware `doctrine_transaction`, konsument zdarzeń).

## Ocena ogólna obszaru

**8,5 / 10.**

To najstaranniej zabezpieczony rodzaj kodu, jaki zwykle widuje się w sklepie tej wielkości. Pieniądze są wyłącznie
liczbami całkowitymi w groszach, VAT liczony jedną formułą, rabat rozkładany metodą największych reszt z dowodem sumy.
Każdy zbadany wyścig (ostatnia sztuka, ostatnie użycie kodu, dwa zamówienia z jednego koszyka, scalanie, pierwsze
dodanie klienta, nakładające się promocje, cena kontra promocja „od teraz”) jest zamknięty blokadą wiersza albo blokadą
doradczą w ustalonej kolejności, a do tego ma test wieloprocesowy na prawdziwym PostgreSQL. **Nie znalazłem ani jednego
błędu klasy `BŁĄD` (wady z konkretną ścieżką prowadzącą do złego wyniku).**

Punkty odjęte za ryzyka biznesowe, nie za poprawność kodu:

- rezerwacja i użycie kodu powstają przy złożeniu zamówienia, a nie przy płatności, więc niezapłacone zamówienia gościa
  mogą blokować stan (znane, nierozwiązane) i wyczerpywać limit kodu (nieopisane);
- zwolnienie rezerwacji zależy od jednej opóźnionej wiadomości bez siatki bezpieczeństwa;
- model operacyjny jest sztywny tam, gdzie pracownik popełni pomyłkę (aktywna promocja, stawka VAT);
- publiczne endpointy koszyka nie mają limitów.

## Znaleziska

### 1. Niezapłacone zamówienia gościa blokują stan magazynowy: brak limitu sztuk i liczby otwartych zamówień

- **Waga:** High. **Klasa:** `RYZYKO` (znane i udokumentowane, nierozwiązane w kodzie).
- **Dowód:**
  - `apps/api/src/Ordering/Application/Command/PlaceOrderHandler.php:133-134`: rezerwacja powstaje przy złożeniu,
    przed jakąkolwiek płatnością:
    ```php
    $this->cart->clearAfterOrder($command->cart);
    $this->reservations->reserve($id, $cartLines);
    ```
  - `apps/api/src/Ordering/Domain/Order.php:30-32`: `MAX_LINES = 50`, `PAYMENT_TIME = '+30 minutes'`,
    `DELIVERY_WINDOW = '+5 minutes'`; `apps/api/src/Cart/Domain/Cart.php:19`: `MAX_QUANTITY = 99`.
  - `apps/api/config/packages/rate_limiter.yaml:17-21`: jedyne ograniczenie to 10 złożeń na 15 minut na adres IP.
  - W `src/Ordering`, `src/Cart`, `src/Inventory` nie ma żadnego limitu sztuk ani liczby otwartych zamówień gościa
    (przeszukane).
  - Specyfikacja to przyznaje: `docs/features/hardening/spec.md:59` (D16) i `docs/features/checkout/spec.md:663-665`.
- **Konsekwencja:** jedno zamówienie gościa rezerwuje do 50 × 99 = 4950 sztuk na 35 minut bez płatności. Z jednego
  adresu IP da się utrzymywać stale ok. 23 otwarte zamówienia (10 na 15 minut, każde żyje 35 minut), czyli do 1150
  wariantów wyłączonych ze sprzedaży w sposób ciągły. Przy stanach rzędu 20–50 sztuk na wariant (dane demo) jeden skrypt
  zdejmuje ze sklepu całe działy: kafelki pokazują „Niedostępny”, a prawdziwi klienci dostają 409 przy dodaniu do
  koszyka. Sprzedawca traci sprzedaż, nie widząc przyczyny (w panelu to zwykłe zamówienia `new`).
- **Rekomendacja:** przed publicznym ruchem: (a) limit sztuk na linię i na zamówienie gościa (np. 5 i 20) w domenie
  Ordering, (b) limit liczby jednocześnie otwartych zamówień `new` na adres IP i na e-mail, (c) rozważyć krótszy czas
  rezerwacji dla gościa. To samo zamyka znalezisko 2.
- **Pewność:** wysoka. Sprawdzone: handler złożenia, limity domeny, konfiguracja limitera, obie specyfikacje. Nie
  sprawdzone: reguły Cloudflare (poza repo).

### 2. Limit użyć kodu zużywają niezapłacone zamówienia: kod z limitem można zablokować bez płacenia

- **Waga:** Medium. **Klasa:** `RYZYKO` (nieopisane w „Ryzykach” specyfikacji kodów).
- **Dowód:**
  - `apps/api/src/Pricing/Application/Command/RedeemDiscountCodeHandler.php:51-58`: użycie zapisywane przy złożeniu:
    ```php
    $activeUses = null === $code->usageLimit() ? 0 : $this->usages->activeUsesOf($id);
    $rejection = $code->rejectionFor($command->subtotalGross, $activeUses, $now);
    ...
    $this->usages->add(DiscountCodeUsage::record(...));
    ```
  - `apps/api/src/Pricing/Infrastructure/Persistence/Doctrine/DoctrineDiscountCodeUsageRepository.php:24`: aktywne
    użycie to każde z `released_at IS NULL`, także zamówienia `new`.
  - `apps/api/src/Ordering/Application/Command/ExpireUnpaidOrderHandler.php:69-70`: jedyne zwolnienie użycia,
    35 minut po złożeniu.
  - `docs/features/discount-codes/spec.md:42` (D2): brak limitu na klienta; kody działają dla gości.
- **Konsekwencja:** kampania „pierwsze 100 zamówień −30%”. Atakujący (albo konkurent) składa zamówienia gościa z kodem
  i nie płaci. Pięć adresów IP wystarcza, żeby stale trzymać ok. 100 aktywnych użyć. Prawdziwy klient w kasie widzi
  „kod wykorzystany”, mimo że nikt nie zapłacił. Kampania marketingowa nie działa, a panel pokazuje 100/100 użyć.
  Każde takie zamówienie blokuje też towar (znalezisko 1).
- **Rekomendacja:** limit otwartych zamówień `new` na IP i e-mail (jak w znalezisku 1). Docelowo rozdzielić „użycie
  wstępne” (zamówienie nieopłacone) od „użycia potwierdzonego” (opłacone) i pokazywać oba w panelu, żeby marketing
  widział, że limit zjadają nieopłacone zamówienia.
- **Pewność:** wysoka co do mechanizmu (kod przeczytany od kontrolera do bazy), średnia co do prawdopodobieństwa
  (wymaga celowego działania; koszt dla atakującego jest zerowy).

### 3. Zwolnienie rezerwacji i użycia kodu zależy od jednej opóźnionej wiadomości, bez uzgodnienia i bez alertu

- **Waga:** Medium. **Klasa:** `RYZYKO` (mało prawdopodobne, ale ciche i trwałe).
- **Dowód:**
  - `apps/api/src/Inventory/Domain/Reservation/Reservation.php:9-10`: „A reservation never expires: only a release or
    a commit ends it.”
  - `apps/api/config/packages/messenger.yaml:59-64`:
    ```yaml
    # ... after 5 tries (1, 3, 9, 27, 81 s) the message goes to `failed` and the order stays new.
    retry_strategy:
        max_retries: 5
    ```
  - `apps/api/src/Ordering/Domain/OrderStatus.php:32`: pracownik nie ma żadnego przejścia ze statusu `new`, więc
    takiego zamówienia nie da się anulować z panelu.
  - `ExpireUnpaidOrder` wysyła tylko `MessengerUnpaidOrderExpiry` (jedno miejsce w `src`); nie ma polecenia konsoli ani
    CronJoba, który wyszukuje zamówienia `new` po terminie.
  - `infra/k8s/charts/shop/templates/00-alerts.yaml`: reguły dotyczą kopii zapasowych, dysku i ciszy klastra; żadna
    nie patrzy na kolejkę `failed`.
- **Konsekwencja:** jeśli obsługa wygaśnięcia rzuci wyjątek pięć razy z rzędu (błąd logiczny w Inventory, np.
  `LogicException('A reserved variant has no stock.')` z `ReservationTransitionHandler.php:104`, rezerwacja usunięta
  resetem danych, przyszły błąd w kodzie), zamówienie zostaje `new` na zawsze. Towar jest trwale zarezerwowany, użycie
  kodu trwale zajęte, a panel nie pozwala tego naprawić. Sprzedawca widzi „0 dostępnych” przy pełnej półce. Naprawa
  wymaga ręcznego `messenger:failed:retry` przez operatora, który musi wiedzieć, że ma tam zajrzeć.
- **Rekomendacja:** proporcjonalnie: (a) idempotentne polecenie-zamiatacz uruchamiane z CronJoba, które dla zamówień
  `new` z `payment_deadline + 5 min < now()` wysyła `ExpireUnpaidOrder` (handler już jest idempotentny),
  (b) alert na liczbę wiadomości w `failed` > 0, (c) zapytanie uzgadniające „aktywne rezerwacje bez zamówienia
  `new`/`paid`”.
- **Pewność:** średnia. Sprawdzone: transport i strategia ponowień, handler, tabela przejść, szablony alertów, brak
  innego nadawcy wiadomości. Awaria bazy nie zużywa prób (transport jest w tej samej bazie), więc scenariusz wymaga
  błędu logicznego, nie awarii infrastruktury.

### 4. Zmiana stawki VAT zatrzymuje sprzedaż całej klasy do ręcznego uruchomienia polecenia

- **Waga:** Medium. **Klasa:** `RYZYKO` (rzadkie zdarzenie, duży skutek; brak dat obowiązywania jest świadomie poza
  zakresem).
- **Dowód:**
  - `apps/api/src/Pricing/Domain/Tax/PolishVatRates.php:12`: „Changing a rate: change it here, deploy, then run
    `pricing:reconcile-tax --apply`.”
  - `apps/api/src/Pricing/Application/Query/CurrentEffectivePrices.php:38-41`: cena ze stawką inną niż bieżąca stawka
    klasy nie ma ceny skutecznej:
    ```php
    foreach ($this->prices->findMany($ids) as $price) {
        if ($price->isCurrent($this->rates)) {
            $stored[$price->id()->toString()] = $price;
    ```
  - `apps/api/CLAUDE.md:1050`: `pricing:reconcile-tax` należy do poleceń „uruchamianych świadomie”, nie jest krokiem
    wdrożenia.
- **Konsekwencja:** po wdrożeniu nowej stawki klasy `standard` (23%) każdy wariant tej klasy traci cenę: kafelki
  „Niedostępny”, linie koszyka `no_price`, złożenie zamówienia 409. Sklep nie sprzedaje do chwili, gdy operator
  uruchomi polecenie. Zmiana stawki obowiązuje zwykle od północy konkretnego dnia, więc wdrożenie i polecenie trzeba
  wykonać dokładnie wtedy. Zachowanie „nie sprzedawaj ze złą stawką” jest poprawnym domyślnym wyborem; problemem jest
  ręczny, dwuetapowy proces o twardym terminie.
- **Rekomendacja:** dopisać `pricing:reconcile-tax --apply` jako krok po migracjach we wdrożeniu (jak kroki
  `app:backfill`), żeby okno skróciło się do sekund. Tabela stawek z datą obowiązywania dopiero, gdy pojawi się realna
  zmiana stawki.
- **Pewność:** wysoka co do mechanizmu. Nie zweryfikowano, czy runbook wdrożenia wymienia to polecenie w innym miejscu
  niż przeszukane (`infra`, `make`, `apps/api/CLAUDE.md`).

### 5. Publiczne endpointy koszyka bez limitów: zgadywanie kodów, zakładanie koszyków, sondowanie stanu

- **Waga:** Medium. **Klasa:** `RYZYKO` (częściowo znane: `docs/features/discount-codes/spec.md:469`,
  `docs/features/hardening/spec.md:34`).
- **Dowód:**
  - `apps/api/src/Shared/Infrastructure/Http/RateLimit.php:20-22`: limitery istnieją tylko dla `order_placement`,
    `payment_start`, `payment_webhook`. Trasy w `apps/api/src/Cart/UI/Http/GuestCartController.php:43,65,112` nie mają
    `RateLimit::ROUTE_DEFAULT`.
  - `infra/k8s/charts/shop/templates/ingress.yaml`: host `api` wystawia całe API (`paths: "/"`), więc endpointy są
    osiągalne z pominięciem BFF sklepu.
  - `apps/api/src/Pricing/Domain/DiscountCode/DiscountCodeRejectionReason.php:13-15`: powody `invalid`, `used_up`,
    `below_minimum`. Dwa ostatnie potwierdzają, że kod istnieje.
  - `apps/api/src/Cart/Domain/Cart.php:402-408`: dodanie N sztuk odpowiada `InsufficientStock` albo sukcesem.
- **Konsekwencja:**
  - kody: `GET /api/v1/guest-carts/{id}?discountCode=X` to darmowa wyrocznia. Kody marketingowe są krótkie
    i przewidywalne (`LATO10`, `WELCOME`), więc słownik kilku tysięcy prób znajduje aktywne kody; każda próba to 3–4
    zapytania do bazy;
  - koszyki: `POST /api/v1/guest-carts` zakłada wiersz żyjący 30 dni; przy 100 żądaniach na sekundę to kilkaset
    milionów wierszy do pierwszego czyszczenia;
  - stan: mimo zasady „liczba sztuk nigdy nie jest zwracana” dokładny stan do 99 sztuk da się ustalić siedmioma
    dodaniami (wyszukiwanie binarne po 409). Konkurent widzi stany i tempo sprzedaży.
- **Rekomendacja:** limiter na podgląd z kodem (np. 20 na 15 minut na IP i na koszyk) i na `POST /guest-carts`; jeden
  komunikat dla wszystkich powodów odrzucenia w podglądzie anonimowym albo dokładny powód dopiero przy złożeniu.
  Sondowania stanu nie da się zamknąć bez zmiany UX; wystarczy limit zapisów koszyka.
- **Pewność:** wysoka. Sprawdzone trasy, limiter, ingress i specyfikacje. Nie sprawdzone: reguły Cloudflare.

### 6. Aktywnej promocji nie da się poprawić: pomyłka w jednym wariancie wymaga zakończenia całej promocji

- **Waga:** Medium. **Klasa:** `PREFERENCJA` (świadome decyzje Q5 i Q13; oceniam ich koszt operacyjny).
- **Dowód:**
  - `apps/api/src/Pricing/Domain/Promotion/Promotion.php:135-137`:
    ```php
    $sameStart = null !== $startsAt && PromotionPeriod::wholeSecond($startsAt) == $this->period->startsAt();
    if (!$sameStart || !$percentOff->equals($this->percentOff) || !$this->hasExactly($variantIds)) {
        throw PromotionNotEditable::afterStart();
    ```
  - `apps/api/src/Pricing/Application/Command/SetVariantPriceHandler.php:68-70`: cena wariantu w aktywnej promocji
    jest zablokowana niezależnie od wersji.
  - Promocja obejmuje do 1000 wariantów i trwa do 90 dni; zakończonej nie da się wznowić.
- **Konsekwencja:** pracownik wpisał cenę katalogową 19,99 zł zamiast 199,90 zł (albo dodał do promocji zły wariant)
  i zauważył to po starcie promocji. Jedyna droga to „Zakończ teraz” dla wszystkich wariantów, poprawka i nowa promocja.
  W nowej promocji linia Omnibus każdego wariantu pokaże cenę z poprzedniej promocji jako najniższą z 30 dni, więc
  komunikat „obniżka” traci sens dla całej kampanii. Do tego czasu sklep sprzedaje po błędnej cenie.
- **Rekomendacja:** pozwolić usunąć wariant z aktywnej promocji ze skutkiem „od teraz” (koniec per wariant, historia
  zostaje, Omnibus dalej wiarygodny). Minimum: opis procedury w panelu przy komunikacie 409.
- **Pewność:** wysoka. Sprawdzone: `revise`, handler ceny, decyzje Q5/Q13 w `docs/features/promotions/spec.md`.

### 7. Kumulacja promocji i kodu bez górnej granicy i bez minimalnej kwoty do zapłaty

- **Waga:** Low. **Klasa:** `PREFERENCJA` (D2: „kod łączy się z promocją”).
- **Dowód:** `apps/api/src/Pricing/Domain/Basket/BasketQuote.php:67-72` liczy rabat kodu od sumy cen skutecznych
  (już po promocji); `PercentOff::MAX = 90` (`Promotion/PercentOff.php:13`) obowiązuje oba mechanizmy osobno.
  `DiscountCode::assertTerms` (`DiscountCode.php:236-241`) wymaga tylko `minimum > kwota`.
- **Konsekwencja:** promocja 90% i kod 90% dają cenę 1% katalogowej. Kod kwotowy 100 zł z minimum 100,01 zł daje
  zamówienie do zapłaty 0,01 zł (część dostawców płatności ma kwotę minimalną). Nie ma flagi „kod nie łączy się
  z promocją” ani wykluczeń produktów.
- **Rekomendacja:** flaga na kodzie „nie dotyczy pozycji w promocji” albo globalny dolny próg kwoty do zapłaty.
  Dopiero gdy marketing zacznie używać obu mechanizmów naraz.
- **Pewność:** wysoka (arytmetyka sprawdzona w domenie).

### 8. Korekta stanu z panelu przegrywa z każdą rezerwacją, choć rezerwacja nie zmienia stanu fizycznego

- **Waga:** Low. **Klasa:** `PREFERENCJA` (znane: `docs/features/inventory/spec.md:472`).
- **Dowód:** `apps/api/src/Inventory/Domain/Stock/StockItem.php:213-215` podnosi wersję przy każdym ruchu, także
  rezerwacji; `apps/api/src/Inventory/Application/Command/CorrectStockHandler.php:85-88` porównuje całą wersję.
- **Konsekwencja:** magazynier koryguje stan popularnego wariantu po inwentaryzacji. Między otwarciem okna a zapisem
  ktoś złożył zamówienie: 409 „Stan został w międzyczasie zmieniony”. W szczycie sprzedaży korekta może się nie udać
  wiele razy z rzędu, mimo że policzony stan fizyczny nadal jest prawdziwy.
- **Rekomendacja:** porównywać `expectedPhysical` zamiast wersji (konflikt tylko po przyjęciu, wysyłce albo innej
  korekcie) albo korekta różnicowa (`delta`).
- **Pewność:** wysoka.

### 9. Ceny i promocje nie mają ostatniej linii obrony w bazie, w odróżnieniu od Inventory i Cart

- **Waga:** Low. **Klasa:** `PREFERENCJA`.
- **Dowód:** `apps/api/migrations/Version20261101120000CreatePricingTables.php:20` (tabela cen bez żadnego `CHECK`:
  ani `gross_amount > 0`, ani `net_amount + vat_amount = gross_amount`); `Version20261108090000CreatePromotions.php:26`
  (brak ograniczenia wykluczającego dwie promocje wariantu w tym samym czasie). Dla porównania Inventory ma
  `inventory_stock_item_quantities` (`Version20261102120000CreateInventoryTables.php:23`), a zamówienia
  `total_net + total_vat = total_gross`.
- **Konsekwencja:** niezmiennik Q4 trzyma wyłącznie blokada doradcza w kodzie. Ręczna poprawka SQL albo przyszła
  ścieżka zapisu z pominięciem blokady da dwie aktywne promocje, a `DbalActivePromotions.php:48` wybierze wtedy
  przypadkową (ostatni wiersz wyniku). Cena w koszyku i w zamówieniu mogłaby się różnić między żądaniami.
- **Rekomendacja:** `CHECK` na tabeli cen (tani). Wykluczenia promocji nie warto budować w bazie przy obecnym modelu
  (okres jest w innej tabeli niż wariant); wystarczy `ORDER BY p.starts_at DESC` w zapytaniu, żeby wynik był
  deterministyczny.
- **Pewność:** wysoka.

### 10. Zwrot towaru nie wraca na stan, a anulowanie opłaconego zamówienia nie zwalnia użycia kodu

- **Waga:** Low. **Klasa:** `RYZYKO` operacyjne (obie decyzje świadome: Q1 w `discount-codes`, opis w `Order.php:276`).
- **Dowód:** `apps/api/src/Ordering/Application/Command/ChangeOrderStatusHandler.php:60-66`: anulowanie woła tylko
  `reservations->release`, zwrot nie woła niczego. Typy ruchów magazynowych to `receipt`, `correction`, `reservation`,
  `release`, `commit`; nie ma ruchu „zwrot”.
- **Konsekwencja:** po zwrocie towar wraca fizycznie na półkę, ale system go nie zna, dopóki magazynier nie zrobi
  „Przyjęcia” albo „Korekty”. W historii zwrot od klienta wygląda jak dostawa. Brak powiązania z zamówieniem utrudnia
  uzgodnienie stanu. Dla kodu z małym limitem (np. 10 użyć) zamówienie anulowane przez pracownika zjada użycie na stałe.
- **Rekomendacja:** przy rejestracji zwrotu opcjonalne pole „wraca na stan: N szt.” tworzące ruch z referencją
  zamówienia. Zwalnianie użycia przy anulowaniu przez pracownika jako opcja w oknie anulowania.
- **Pewność:** wysoka.

### 11. Własne nieopłacone zamówienie klienta trzyma jego towar; nie ma anulowania przez klienta

- **Waga:** Low. **Klasa:** `RYZYKO` (świadome P2 w `order-management`).
- **Dowód:** `PlaceOrderHandler.php:133` opróżnia koszyk przy złożeniu; `OrderStatus.php:32` nie daje przejść z `new`;
  w module Payment nic nie zwalnia rezerwacji po nieudanej płatności (jedyny nadawca `ExpireUnpaidOrder` to złożenie).
- **Konsekwencja:** klient kupuje ostatnią sztukę, płatność się nie udaje albo klient chce zmienić zamówienie (adres,
  ilość). Koszyk jest pusty, towar zarezerwowany przez jego własne zamówienie. Może tylko ponowić płatność tego samego
  zamówienia w ciągu 30 minut albo czekać 35 minut na wygaśnięcie. Po dodaniu produktu ponownie widzi „Niedostępny”.
- **Rekomendacja:** „Anuluj i wróć do koszyka” na stronie płatności (zwolnienie rezerwacji i użycia, odtworzenie
  koszyka z pozycji zamówienia).
- **Pewność:** średnia (moduł Payment przeszukany tylko pod kątem nadawców zwolnienia, nie czytany w całości).

## Mocne strony

Każdy punkt to przeanalizowany wyścig albo reguła z dowodem.

1. **Nadsprzedaż ostatniej sztuki jest niemożliwa.** Kolejność: A i B składają zamówienie na ostatnią sztukę. A
   wykonuje `SELECT … ORDER BY variant_id FOR UPDATE` (`DoctrineStockItemRepository.php:54`), B czeka na tym samym
   wierszu. Po commicie A baza zwraca B wiersz w nowej wersji (READ COMMITTED), `StockItem::reserve`
   (`StockItem.php:114`) rzuca `StockShortage`, a cała transakcja B (koszyk, kod, zamówienie) się wycofuje. Trzy
   niezależne zabezpieczenia: blokada wiersza, `UPDATE … WHERE version = :read_version` (`:149`) i `CHECK (reserved >= 0
   AND physical >= reserved …)`. Blokady wielu wariantów w rosnącej kolejności, więc dwa koszyki z tymi samymi
   wariantami w odwrotnej kolejności się nie zakleszczają. Testy `O1`, `O4`, `C1`, `C2`.
2. **Ostatnie użycie kodu z limitem.** A i B składają zamówienie z kodem, wolne jest jedno użycie. Oba przechodzą
   wycenę (odczyt bez blokady), potem `RedeemDiscountCodeHandler.php:44-46` bierze `FOR NO KEY UPDATE` wiersza kodu
   (`DoctrineDiscountCodeRepository.php:82`); B czeka, po commicie A liczy aktywne użycia od nowa i dostaje `used_up`.
   Wybór `FOR NO KEY UPDATE` zamiast `FOR UPDATE` (żeby klucz obcy użycia nie blokował zmian kodu bez limitu) i zakaz
   dodania limitu po starcie kodu (`DiscountCode.php:107-110`, bo kod bez limitu nie jest blokowany) pokazują, że
   konsekwencje zostały przemyślane. Test `testTwoPlacementsForTheLastFreeUsage…`.
3. **Dwa zamówienia z jednego koszyka.** Złożenie blokuje wiersz koszyka (`PlaceOrderHandler.php:104`) i dopiero pod
   blokadą drugi raz sprawdza klucz idempotencji (`:106-113`). Ten sam klucz: jedno zamówienie i powtórki 200. Różne
   klucze: drugie żądanie po zdjęciu blokady widzi pusty koszyk (`CartEmpty`). Ten sam klucz z dwóch koszyków: unikalny
   indeks `ordering_order_idempotency_key_key`. Testy `O2`, `O3`, `O7`.
4. **Koszyk bez utraconych aktualizacji.** Każda komenda zaczyna od `find(…, PESSIMISTIC_WRITE)`
   (`DoctrineCartRepository.php:158`), a rekord już obecny w identity map jest odświeżany pod blokadą razem z liniami
   (`:163-178`). Pierwsze dodanie klienta z dwóch kart: `INSERT … ON CONFLICT (customer_id) … DO NOTHING` (`:81-83`),
   więc jeden koszyk bez naruszenia unikalności. Scalanie blokuje zawsze gościa, potem klienta
   (`MergeGuestCartHandler.php:47-53`); linie gościa są usuwane przy scaleniu, więc podwójne przeniesienie nie istnieje.
   Baza pilnuje `quantity BETWEEN 1 AND 99`, jednego koszyka na klienta i unikalności `(cart_id, variant_id)`. Testy
   `K1`–`K8`.
5. **Zmiana ceny między koszykiem a zamówieniem jest wykrywana.** Sklep wysyła `expectedTotalGross` z widoku, który
   klient widział (`apps/store/src/checkout/CheckoutPage.tsx:239`), a handler porównuje go z wyceną z chwili złożenia
   (`PlaceOrderHandler.php:124`) i odmawia `OrderTotalChanged`. Żądanie nie niesie cen, ilości ani linii: wszystko
   pochodzi z zablokowanego koszyka i Pricing. Koszyk i zamówienie używają tej samej wyceny (`QuoteBasket`).
6. **Pieniądze i VAT.** Wyłącznie `int` w groszach, bez `float`. `PriceBreakdown::netOf` (`PriceBreakdown.php:106-111`)
   to jedyne miejsce formuły; VAT = brutto − netto, więc suma zgadza się co do grosza. Sprawdziłem, że dla stawek 5%,
   8% i 23% przypadek „dokładnie pół grosza” nie istnieje (parzystość), więc zaokrąglenie netto i ustawowe zaokrąglenie
   VAT dają ten sam wynik. Netto linii liczone z brutto linii po rabacie (metoda „od brutto”), spójnie w koszyku
   i zamówieniu. Kwoty linii i sum w `BIGINT` (99 × 999 999,99 zł nie mieści się w `INT`), iloczyny rozkładu rabatu
   w GMP.
7. **Rozkład rabatu kodu na linie.** Metoda największych reszt (`BasketQuote.php:214-235`) daje `Σ d_i = D`
   i `d_i ≤ g_i`; `Order::assertDiscount` sprawdza sumę jeszcze raz. Kod procentowy ma niejawne minimum
   `ceil(100/p)` groszy, więc nigdy nie zużywa limitu rabatem 0 gr; kod kwotowy zawsze ma minimum powyżej kwoty, więc
   rabat nie przekracza wartości koszyka (constraint `pricing_discount_code_minimum_check` w bazie).
8. **Cena Omnibus liczona poprawnie.** `LowestPriorPrice` (`LowestPriorPrice.php:37-66`) bierze najniższą cenę
   skuteczną z okna `[start − 30 dni, start)`, uwzględnia wcześniejsze promocje wariantu i pomija czas sprzed pierwszej
   ceny. Podniesienie ceny tuż przed promocją nie zawyża ceny odniesienia. Wiarygodność historii zapewniają: blokada
   ceny w aktywnej promocji, niezmienność promocji po starcie i blokada doradcza (wyłączna dla zapisów promocji,
   współdzielona dla zapisu ceny, zegar czytany po blokadzie: `SetVariantPriceHandler.php:66-70`). Sklep pokazuje
   oznaczenie promocji tylko razem z linią Omnibus i nie pokazuje przekreślonej ceny katalogowej.
9. **Daty i strefy czasowe.** API przyjmuje tylko RFC 3339 z jawną strefą (`Rfc3339.php`), przechowuje `timestamptz`,
   PHP działa w UTC, okresy są lewostronnie domknięte i prawostronnie otwarte, porównania z bazą z mikrosekundami.
   Okno Omnibusa liczone w UTC, więc zmiana czasu go nie wydłuża.
10. **Idempotencja przyjęć i rezerwacji.** Klucz przyjęcia sprawdzany pod blokadą wiersza, a przypadek „ten sam klucz
    dla dwóch wariantów naraz” łapie częściowy indeks unikalny. Rezerwacja ma klucz = identyfikator zamówienia; nagłówek
    wstawiany `ON CONFLICT DO NOTHING`. Zwolnienie i zatwierdzenie blokują wiersz rezerwacji, więc wygrywa jedno
    przejście (test `C5`).
11. **Wygaśnięcie kontra płatność i pracownik.** Wygaśnięcie blokuje wiersz zamówienia przed Inventory; płatność
    zapisuje z wersją. Kolejności blokad (zamówienie → rezerwacja → stany → użycie; koszyk → kod → stany) nie tworzą
    cyklu, co jest opisane w kodzie i testowane (`testAnExpiryAndAPlacementWithTheSameCodeDoNotDeadlock`).
12. **Zdarzenia w tej samej transakcji.** `StockLevelChanged` i `VariantPriceChanged` trafiają do outboxa na tym samym
    połączeniu, więc wycofane złożenie nie zostawia zdarzenia. Indeks wyszukiwania i cache sklepu odświeżają się
    z aktualnego stanu (konsument czyta od nowa), więc kolejność i powtórzenia nie szkodzą. Zakup zawsze rozstrzyga
    baza, nie indeks.
13. **Świadomość ograniczeń.** Każda specyfikacja ma sekcję „Ryzyka i ograniczenia”, która uczciwie wymienia większość
    kompromisów, a kod nie twierdzi niczego o wydajności bez testu.

## Zbędna złożoność

- **Trzy style persystencji w trzech modułach** dla tego samego problemu („zablokuj, przeczytaj, zmień”): Cart przez ORM
  z ręcznym odświeżaniem identity map (`DoctrineCartRepository::lockRecord`, 25 linii kodu tylko po to, żeby ORM nie
  zwrócił starego stanu), Inventory przez czysty DBAL, Pricing przez ORM z wersją i `HINT_REFRESH`. Każdy wybór jest
  uzasadniony w komentarzu, ale nowa osoba musi znać trzy zestawy pułapek. Koszyk w DBAL byłby krótszy i prostszy.
- **Oferta listy produktów i fakty dla indeksu wyszukiwania mieszkają w Cart** (`GetProductOffers`,
  `QueryBusProductOfferFacts`). Moduł „koszyk” zasila kafelki katalogu i Elasticsearch, bo tam jest reguła
  kupowalności. To działa, ale nazwa contextu przestała opisywać odpowiedzialność; `Cart` stał się faktycznie „Offer”.
- **Dwa odczyty cen na jeden widok koszyka** (`GetCurrentPrices` dla kupowalności, potem `QuoteBasket` dla kwot),
  każdy z osobnym zapytaniem o promocje. Udokumentowane jako świadome; jedna wycena zwracająca też „brak ceny”
  usunęłaby drugi odczyt i przypadek brzegowy „kupowalna według pierwszego odczytu, bez ceny w drugim”
  (`CartViewBuilder.php:66-69`).
- Poza tym złożoność jest proporcjonalna do problemu. Nie znalazłem abstrakcji bez użycia.

## Braki

- Limit sztuk i liczby otwartych zamówień gościa (znalezisko 1); limit użyć kodu na klienta i kody jednorazowe
  (świadomie poza zakresem).
- Siatka bezpieczeństwa dla wygaśnięć i uzgodnienie rezerwacji z zamówieniami oraz użyć kodów z zamówieniami
  (znalezisko 3).
- Anulowanie nieopłaconego zamówienia przez klienta i przez pracownika; wcześniejsze zwolnienie rezerwacji po
  nieudanej płatności.
- Ruch magazynowy „zwrot” powiązany z zamówieniem.
- Harmonogram zmian cen i stawek VAT (cena zmienia się natychmiast; brak też progu ostrzegawczego przy dużej zmianie
  ceny, np. o rząd wielkości).
- Wykluczenia w kodach rabatowych (produkty w promocji, kategorie).
- Testy obciążeniowe potwierdzające, że szeregowanie na wierszu popularnego wariantu i kodu z limitem wytrzymuje
  szczyt (specyfikacje same to zaznaczają).
- Test współbieżności dla zmiany limitu kodu równolegle ze złożeniami (jest dla wyłączenia i dla zmiany kodu bez
  limitu).

## Czego nie zweryfikowano

- Niczego nie uruchamiałem: wnioski o blokadach wynikają z lektury kodu i znanej semantyki PostgreSQL (READ COMMITTED,
  `LockRows` po sortowaniu, czekanie `INSERT … ON CONFLICT` na niezatwierdzony wiersz). Testy współbieżności istnieją,
  ale nie sprawdziłem, czy przechodzą.
- Zachowanie zagnieżdżonego `doctrine_transaction` (savepointy, stan `UnitOfWork` po wycofaniu do savepointu) przyjąłem
  za komentarzami w kodzie i `apps/api/CLAUDE.md`.
- Moduł Payment czytałem tylko w zakresie styku (kto zwalnia rezerwację, wynik `markPaid`); wyścig webhook kontra
  wygaśnięcie oceniłem po stronie Ordering.
- Reguły Cloudflare i faktyczna konfiguracja produkcji (czy worker konsumuje `scheduled`, czy ktoś monitoruje `failed`)
  są poza repo.
- Poprawność stawek VAT: kod sam zaznacza, że są przykładowe i niezweryfikowane księgowo (`PolishVatRates.php:10-11`).
  To warunek przed realną sprzedażą, nie wada kodu.
- Teoretyczny wyścig, którego nie uznałem za znalezisko: dodanie limitu do zaplanowanego kodu w tej samej sekundzie,
  w której kod startuje. Złożenie, które przeczytało kod jeszcze bez limitu (`RedeemDiscountCodeHandler.php:43`), nie
  bierze blokady i może przekroczyć nowy limit o liczbę równoległych złożeń. Wymaga edycji kodu dokładnie w chwili
  startu kampanii.
- Identyfikator koszyka gościa jest w ścieżce URL API (`/api/v1/guest-carts/{cartId}`) i jest jednocześnie kluczem
  dostępu; nie sprawdzałem, czy logi dostępu (Traefik, Varnish, Alloy) go maskują.
- Panelu (`apps/admin`) i BFF sklepu nie audytowałem poza trzema punktami wymienionymi w zakresie.
