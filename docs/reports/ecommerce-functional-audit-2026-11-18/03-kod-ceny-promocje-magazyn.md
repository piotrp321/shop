# Audyt funkcjonalny: ceny i podatki, promocje i kody, magazyn

Rewizja 1aeb5076 (origin/main). Tylko odczyt kodu, specyfikacji i testów; nic nie było uruchamiane. Statusy: KOMPLETNA /
CZĘŚCIOWA / ZAPLANOWANA / BRAK. Znaczenie braku: BLOKER / ISTOTNE / OPCJONALNE / NIEWARTE (teraz).

Ścieżki skrócone: `api/` = `apps/api/src/`, `admin/` = `apps/admin/src/`, `store/` = `apps/store/src/`.

Mapa endpointów (z `apps/api/openapi*.json`):
- Sklep: brak endpointu cen i stanów; ceny, promocja, Omnibus i poziom dostępności idą w `GET /api/v1/product-offers`
  (`VariantOfferResponse {gross, regularGross, promotion {endsAt, lowestPriorGross}, level, purchasable}`). Kod rabatowy:
  parametr `discountCode` w `GET /me/cart` i `/guest-carts/{id}` (podgląd) oraz pole w `POST /orders`, `/me/orders`.
- Panel: `GET /admin/prices`, `PUT /admin/variants/{id}/price`, `GET .../price-history`, `GET /admin/tax-classes`
  (tylko odczyt), CRUD `/admin/promotions` (bez POST-u masowego), `/admin/discount-codes` GET/POST/PUT (bez DELETE),
  `GET /admin/stock`, `POST .../stock/receipts`, `POST .../stock/corrections`, `GET .../stock-history`.

---

## 1. Ceny i podatki

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Cena brutto wariantu, netto i VAT liczone przez API | KOMPLETNA | Pracownik wpisuje brutto (grosze, int), API liczy netto HALF_UP i VAT = brutto − netto (zawsze sumuje się). Zakres 0,01–999 999,99 zł. Panel: dialog „Zmień cenę” na wierszu wariantu w `/prices` i karta „Ceny i stany” w formularzu produktu. Wejście netto (B2B) nie istnieje. | `api/Pricing/Domain/Price/PriceBreakdown.php` (`netOf`), `GrossPrice.php`; `admin/prices/PriceDialogs.vue`, `admin/products/ProductPriceStock.vue`; e2e `e2e/tests/price-change.spec.ts` | wpis netto: OPCJONALNE (B2C wpisuje brutto) |
| Ceny per wariant | KOMPLETNA | Kluczem ceny i historii jest `variantId`; produkt bez osi ma wariant domyślny; sklep pokazuje „od X zł” przy różnych cenach. | `docs/features/pricing/spec.md:8`; `api/Pricing/Domain/Price/VariantPrice.php` | — |
| Klasy podatkowe i stawki VAT | CZĘŚCIOWA | 8 klas zaszytych w enumie Catalog `TaxClassCode` + stawki w stałej `PolishVatRates::RATES` (np. `standard` 2300 bp, `printed_book` 500). Panel pozwala **wybrać** klasę w formularzu produktu (`GET /admin/tax-classes`), ale **nie** dodać klasy ani zmienić stawki: zmiana = edycja kodu, deploy, `pricing:reconcile-tax --apply` (CLI). Do czasu uruchomienia polecenia ceny tej klasy znikają ze sklepu („Cena niedostępna”). Komentarz w kodzie: stawki to „EXAMPLES… must be verified before anything is sold”. Klasy nazwane po asortymencie demo (żele, batony, mapy). | `api/Pricing/Domain/Tax/PolishVatRates.php:9-23`; `api/Catalog/.../TaxClassCode.php:13-20`; `docs/features/pricing/spec.md:387-389`; `admin/views/ProductFormView.vue:406-430` | BLOKER dla innego asortymentu niż demo (np. stawka 0%/zw., nowa klasa wymaga programisty) i dla weryfikacji księgowej stawek; ISTOTNE: edycja stawek z panelu i bezpieczna zmiana stawki (dziś wyłącza sprzedaż klasy do ręcznego CLI) |
| Tylko Polska / tylko PLN | BRAK (świadomie) | `enum Currency { case PLN }` w Pricing i osobny `Cart/Domain/Currency.php`; brak OSS/stawek innych krajów UE, brak wielu walut. Wizja: wiele walut „poza zakresem”. | `api/Pricing/Domain/Money/Currency.php`; `docs/vision.md:385` | NIEWARTE teraz dla sklepu PL; ISTOTNE przy sprzedaży do UE (OSS) |
| Zaokrąglenia | KOMPLETNA | Tylko liczby całkowite; netto linii zamówienia liczone z brutto linii (nie jednostkowe × ilość); rabat kodu rozkładany na linie metodą największych reszt (GMP). Test własności 1–100 000 groszy i maksimum. | `PriceBreakdown.php`, `api/Pricing/Domain/Basket/BasketQuote.php`; `apps/api/tests/Unit/Pricing/Domain/PriceBreakdownTest.php`, `BasketQuoteTest.php` | — |
| Historia cen | KOMPLETNA | Każda faktyczna zmiana = wpis z pełnym snapshotem, źródłem (`staff`/`seed`/`tax_reconciliation`) i aktorem; panel: dialog „Historia” z paginacją. Brak historii nie powstaje przy identycznym komplecie. | `api/Pricing/Domain/Price/PriceHistoryEntry.php`; `admin/prices/usePriceHistory.ts`; `GET .../price-history` | — |
| Omnibus (najniższa cena z 30 dni przed obniżką) | KOMPLETNA (dla promocji) | Liczona przy odczycie z historii cen i historii promocji (okno 30×24 h przed startem promocji, także obniżki z innych promocji w oknie); sklep: badge „Promocja” + „Najniższa cena z 30 dni przed obniżką: X zł” na kafelku i stronie produktu, JSON-LD. Wariant bez ceny przed startem: cena promocyjna bez badge’a i linii. Ograniczenie: obniżka ręczna ceny katalogowej (bez promocji) nie jest oznaczana jako obniżka, więc nie pokazuje Omnibusa (zgodne z prawem tylko dopóki sklep jej nie „ogłasza”). | `api/Pricing/Domain/Promotion/LowestPriorPrice.php`; `store/catalog/PromotionNotice.tsx`; `apps/api/tests/Unit/Pricing/Domain/LowestPriorPriceTest.php`; `e2e/tests/promotions.spec.ts` | — |
| Cena przekreślona / „compare at” | BRAK (decyzja Q11) | API zwraca `regularGross`, ale sklep świadomie nie przekreśla ceny katalogowej („there is no regular price to strike out”). Brak pola „cena sugerowana/compare at” w panelu. | `store/catalog/PromotionNotice.tsx` (komentarz); `docs/features/promotions/spec.md` Q11; `grep regularGross store/src` = tylko testy | OPCJONALNE (przekreślenie przy Omnibusie jest ryzykowne prawnie; samo pole compare-at niewarte) |
| Cenniki / grupy cenowe klientów, ceny B2B | BRAK | Jedna cena na wariant; brak grup klientów i cenników. | brak w `api/Pricing/Domain`, `api/Customer` | OPCJONALNE (B2C); ISTOTNE dla B2B/hurtu |
| Ceny hurtowe / progowe (od N szt.) | BRAK | — | `BasketQuote.php` (tylko cena jednostkowa × ilość) | OPCJONALNE |
| Planowanie zmiany ceny katalogowej | BRAK | Zmiana ceny działa natychmiast; jedyny „harmonogram” to promocja procentowa. | `SetVariantPriceHandler.php` | OPCJONALNE |
| Masowa zmiana cen | BRAK | Jeden dialog na wariant; brak zaznaczania wielu wierszy, „+10% w kategorii”, edycji w siatce. | `admin/views/PricesView.vue`, `admin/prices/*` (brak checkboxów/bulk) | ISTOTNE (sklep z setkami wariantów nie zmieni cennika ręcznie) |
| Import / eksport cen (CSV/XLSX) | BRAK | Ceny ładuje tylko seed (`pricing:seed-demo` z `fixtures/prices.yaml`, `demo-data:prices`). Brak importu z panelu i brak API zbiorczego. | lista komend `#[AsCommand]` (tylko seed/demo); brak „csv” w `api/` i `admin/` | BLOKER dla realnego startu z istniejącym asortymentem (ISTOTNE co najmniej); bez importu cennik wprowadza się ręcznie wariant po wariancie |
| Uprawnienia do cen | KOMPLETNA (sztywno) | Cenę zmienia tylko `admin`; `marketing` nie (może robić promocje). Role zaszyte w voterze. | `api/Pricing/Infrastructure/Security/PriceVoter.php`; spec `pricing/spec.md:448-450` | OPCJONALNE |
| Ceny w wyszukiwarce (filtr/sort) | KOMPLETNA | `GET /products` ma `priceMin`, `priceMax`, `sort=price_asc|price_desc`; reindeks po zmianie ceny i granicy promocji. Uwaga: `pricing/spec.md:602` twierdzi, że ceny nie ma w ES — dokument nieaktualny. | `apps/api/openapi.json` (parametry `/api/v1/products`); `api/Pricing/Infrastructure/Search/ReindexProductOnPriceChange.php` | — (niespójność dokumentacji) |
| Koszt dostawy | BRAK | Zamówienie nie ma kosztu dostawy ani metod wysyłki; suma = linie − rabat. To poza moim zakresem, ale wyklucza promocję „darmowa dostawa”. | brak `shipping cost` w `api/Ordering`, `store/` | BLOKER (inny obszar audytu) |

## 2. Promocje, rabaty, kody

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Promocja procentowa na wybrane warianty z harmonogramem | KOMPLETNA | 1–90%, okres `[start, koniec)` max 90 dni, obowiązkowy koniec; start „od teraz” albo zaplanowany; granice okresu obsługują opóźnione wiadomości (reindeks + purge cache). Panel: lista z filtrem statusu, formularz, wybór wariantów z wyszukiwarką (pojedynczo albo „wszystkie warianty produktu”), „Zakończ teraz”, usunięcie zaplanowanej. Role `admin`, `marketing`. | `api/Pricing/Domain/Promotion/Promotion.php`, `PromotionPeriod.php:13`, `PercentOff.php:13`; `admin/views/PromotionFormView.vue`, `admin/promotions/PromotionVariantPicker.vue:19-25`; `e2e/tests/promotions.spec.ts`, `admin-screens-promotions.spec.ts` | — |
| Promocja kwotowa / cena promocyjna wpisana per wariant | BRAK (Q2) | Tylko procent jednakowy dla wszystkich wariantów promocji. | `docs/features/promotions/spec.md:28-29` | ISTOTNE (typowe „-50 zł” albo „teraz 99,99 zł”) |
| Zakres: kategoria / marka / cały sklep | BRAK (Q3) | Cel promocji = lista 1–1000 wariantów wybierana ręcznie; nowe produkty kategorii nie wchodzą automatycznie. | `promotions/spec.md:27`; `Promotion.php` | ISTOTNE (wyprzedaż kategorii = ręczne klikanie, limit 1000) |
| Łączenie promocji, priorytety | BRAK (Q4, celowo) | Wariant w jednej promocji naraz; nakładające się okresy = 409 z listą konfliktów. Kod łączy się z promocją bez górnej granicy (90% + 90%). | `PromotionOverlap.php`; raport pomocniczy 03 #7, `BasketQuote.php` | OPCJONALNE (prosty model jest zaletą); limit łącznego rabatu: OPCJONALNE |
| Edycja aktywnej promocji | CZĘŚCIOWA | Po starcie zmienia się tylko nazwa i koniec; nie da się usunąć jednego wariantu ani poprawić procentu. Cena katalogowa wariantu w aktywnej promocji jest zablokowana (409 `price-locked-by-promotion`). | `Promotion.php` (`revise`); `SetVariantPriceHandler.php`; `promotions/spec.md` Q5, Q13 | ISTOTNE (pomyłka = zakończenie całej promocji i nowa, co psuje Omnibus kolejnej) |
| X za Y, gratis, zestawy/bundle, automatyczny rabat koszykowy (np. „-10% od 300 zł” bez kodu), rabat od ilości | BRAK | Brak reguł koszykowych bez kodu. | `api/Pricing/Domain/Basket/BasketQuote.php` (tylko promocja wariantu + jeden kod) | ISTOTNE (rabat koszykowy bez kodu), OPCJONALNE (X za Y, zestawy) |
| Darmowa dostawa jako rabat | BRAK | Brak kosztu dostawy w ogóle. | j.w. | zależne od dostaw (BLOKER innego obszaru) |
| Kody rabatowe: procent / kwota na całe zamówienie | KOMPLETNA | Procent 1–90% albo kwota 1–10 000 zł; okres (obowiązkowy start, opcjonalny koniec), minimalna wartość zamówienia (kwotowy: obowiązkowa > kwoty), limit użyć łącznie, włącz/wyłącz. Panel: lista z filtrem statusu i wyszukiwaniem po prefiksie, formularz, licznik aktywnych użyć. Sklep: pole „Kod rabatowy” w kasie z podglądem rabatu i polskimi komunikatami odrzucenia; e-mail i szczegóły zamówienia pokazują „Kod X: −Y zł”. Użycie zapisywane w transakcji złożenia (blokada wiersza kodu z limitem), zwalniane przy wygaśnięciu nieopłaconego zamówienia. | `api/Pricing/Domain/DiscountCode/DiscountCode.php`, `UsageLimit.php`, `MinimumOrder.php`; `admin/views/DiscountCodesView.vue`, `DiscountCodeFormView.vue`; `store/checkout/CheckoutPage.tsx:63-725`; `apps/api/tests/Integration/Ordering/Concurrency/DiscountCodePlacementConcurrencyTest.php`; `e2e/tests/discount-codes.spec.ts` | — |
| Kod tylko w kasie (nie w koszyku) | CZĘŚCIOWA (D3) | Koszyk nie ma pola kodu; kod żyje w stanie komponentu kasy, odświeżenie strony go gubi. | `discount-codes/spec.md` D3; `CheckoutPage.tsx:104`; `store/cart/CartPage.tsx` (brak „discount”) | OPCJONALNE |
| Limit użyć na klienta / kod jednorazowy / pierwszy zakup | BRAK | Tylko limit łączny; kody działają dla gości. | `discount-codes/spec.md:28-30`, D2 | ISTOTNE (typowy „WITAJ10 na pierwsze zakupy” jest nadużywalny) |
| Masowe generowanie kodów (seria unikalnych) | BRAK | — | `discount-codes/spec.md:28` | OPCJONALNE (ISTOTNE przy kampaniach/influencerach) |
| Kod na produkty / kategorie, wykluczenia (np. bez promocji) | BRAK | Kod dotyczy całego zamówienia i zawsze łączy się z promocją. | `discount-codes/spec.md:33`, D2 | ISTOTNE (brak „nie łączy się z promocjami” = ryzyko marży) |
| Usuwanie kodu / zmiana tekstu | BRAK (celowo) | Wyłączenie zastępuje usunięcie; tekst stały. | `discount-codes/spec.md` Q3 | NIEWARTE |
| Ochrona przed zgadywaniem kodów i blokowaniem limitu nieopłaconymi zamówieniami | ZAPLANOWANA | Dziś podgląd kodu bez limitu (`?discountCode=` jako wyrocznia), limit zużywają zamówienia `new` do 35 min. Epik ABUSE-LIMITS (20 prób / 15 min z IP) — spec, bez kodu. | `docs/features/abuse-limits/spec.md:26,43-47`; raport pomocniczy 03 #2, #5 | BLOKER publicznego startu (bezpieczeństwo kampanii) |
| Karty podarunkowe | BRAK | — | brak w `api/` | OPCJONALNE |
| Program lojalnościowy / punkty | BRAK | Poza zakresem. | `discount-codes/spec.md:31` | NIEWARTE teraz |
| Prezentacja promocji w sklepie | KOMPLETNA (minimalna) | Badge „Promocja” + linia Omnibus na kafelku i stronie produktu; koszyk bez oznaczenia promocji; brak przekreślenia, brak odliczania do końca, brak strony „Promocje”/filtra „tylko promocje”. | `store/catalog/ProductPrice.tsx`, `PromotionNotice.tsx`; `promotions/spec.md:30` | OPCJONALNE (filtr „promocje”: ISTOTNE marketingowo) |

## 3. Magazyn

| Funkcja | Status | Co działa / czego brakuje | Dowód | Znaczenie braku |
|---|---|---|---|---|
| Stan per wariant (fizyczny, zarezerwowany, dostępny) | KOMPLETNA | `available = physical − reserved`, niezmiennik `0 ≤ reserved ≤ physical ≤ 1 000 000` w domenie i `CHECK` w bazie; każdy wariant ma rekord stanu (synchronizacja po zdarzeniach Catalog). Panel `/inventory`: lista pogrupowana po produkcie, wyszukiwanie, kategoria, sort, filtr poziomu. | `api/Inventory/Domain/Stock/StockItem.php`; `inventory/spec.md:56-116`; `admin/views/InventoryView.vue` | — |
| Rezerwacje | KOMPLETNA | Powstają przy złożeniu zamówienia (nie przy dodaniu do koszyka), „wszystko albo nic”, idempotentne po `orderId`; zatwierdzane przy wysyłce (stan fizyczny spada), zwalniane przy anulowaniu przez pracownika i przy wygaśnięciu nieopłaconego zamówienia. | `api/Ordering/Application/Command/ChangeOrderStatusHandler.php:52-64`; `ExpireUnpaidOrderHandler.php`; `api/Inventory/Application/Command/ReserveStockHandler.php` | — |
| Timeout nieopłaconych zamówień | CZĘŚCIOWA | Termin płatności 30 min zaszyty (`Order::PAYMENT_TIME = '+30 minutes'`), wygaśnięcie 5 min później jedną opóźnioną wiadomością; brak siatki bezpieczeństwa (wiadomość w `failed` = towar zarezerwowany na zawsze, panel tego nie naprawi). Brak konfiguracji czasu (np. dla przelewu tradycyjnego 3 dni). Siatkę (CronJob, alert) planuje ASYNC-RELIABILITY (status pusty). | `api/Ordering/Domain/Order.php:31-32`; `api/Inventory/Domain/Reservation/Reservation.php` („never expires”); `docs/features/async-reliability/spec.md:192`, `plan.md:8-14` | ISTOTNE (siatka bezpieczeństwa: BLOKER niezawodności przy realnym ruchu); konfigurowalny czas: ISTOTNE przy płatnościach odroczonych |
| Ochrona przed sprzedażą ponad stan | KOMPLETNA | `SELECT … FOR UPDATE` po rosnącym `variant_id`, decyzja w agregacie; koszyk sprawdza dostępność przy dodaniu/zmianie (409), złożenie rezerwuje autorytatywnie. Testy wieloprocesowe C1–C6 na PostgreSQL (`pcntl_fork`, 5 rund). Ryzyko: rezerwacja gościa bez limitu sztuk (50 × 99) blokuje stan na 35 min — limity w ABUSE-LIMITS (spec). | `apps/api/tests/Integration/Inventory/Concurrency/ReservationConcurrencyTest.php`, `StockChangeConcurrencyTest.php`; `api/Cart/Domain/Cart.php:400-406`; `abuse-limits/spec.md:25` | blokada stanu przez boty: BLOKER publicznego startu (zaplanowane) |
| Przyjęcie (PZ) | CZĘŚCIOWA | Przyjęcie ilości 1–100 000 na **jeden wariant** z notatką, idempotentne (`Idempotency-Key`). Brak dokumentu przyjęcia wielu pozycji, dostawcy, numeru dokumentu, ceny zakupu/kosztu, skanera EAN. | `api/Inventory/Application/Command/ReceiveStockHandler.php`; `admin/inventory/StockDialogs.vue`; `e2e/tests/stock-receipt.spec.ts` | ISTOTNE (dostawa 50 pozycji = 50 dialogów; brak kosztu = brak marży/wartości magazynu) |
| Korekty | KOMPLETNA | Ustawienie stanu policzonego z powodem (inwentaryzacja, uszkodzenie, zaginięcie, inne + wymagana notatka), kontrola wersji, nie poniżej zarezerwowanego. | `api/Inventory/Domain/Stock/CorrectionReason.php`, `StockItem.php` | — |
| Historia ruchów | KOMPLETNA | Każda operacja (przyjęcie, korekta, rezerwacja, zwolnienie, zatwierdzenie) dopisuje wpis ze stanem po operacji, źródłem i aktorem; panel: dialog „Historia” 11 kolumn. Brak globalnego dziennika ruchów (tylko per wariant) i eksportu. | `StockMovement.php`; `GET .../stock-history`; `admin/inventory/useStockHistory.ts` | eksport/raport ruchów: OPCJONALNE |
| Zwrot towaru → przywrócenie stanu | BRAK | `registerReturn` zmienia status i nalicza zwrot pieniędzy, ale nic nie wraca na stan; `OrderReturned` obsługuje tylko e-mail. Pracownik musi ręcznie zrobić przyjęcie. | `api/Ordering/Domain/Order.php:281-290`; `api/Ordering/Application/EventHandler/SendReturnReceived.php` (jedyny konsument) | ISTOTNE (łatwo zapomnieć; brak powiązania zwrotu z ruchem) |
| Wiele magazynów / lokalizacji | BRAK (poza zakresem) | Jeden magazyn; issue multi-warehouse odłożone. | `inventory/spec.md:13` | OPCJONALNE (mały sklep: niewarte; omnichannel: ISTOTNE) |
| Próg niskiego stanu i alerty | CZĘŚCIOWA | Próg globalny 5 szt. zaszyty w stałej (zmiana = migracja indeksu); pulpit ma kafelki „Bez stanu” i „Niski stan” prowadzące do filtra `/inventory?level=`. Brak progu per wariant i powiadomienia e-mail. | `api/Inventory/Domain/Stock/AvailabilityLevel.php:13`; `admin/dashboard/todo.ts:79-89`; `inventory/spec.md:113-116` | ISTOTNE (próg per produkt i e-mail), reszta działa |
| Backorder / przedsprzedaż / „dostępny za X dni” | BRAK | Stan 0 = „Niedostępny”, nie do kupienia. | `store/catalog/availability-label.tsx`; `CartLineState.php` | OPCJONALNE |
| „Powiadom o dostępności” | BRAK | — | brak w `store/`, `api/` | OPCJONALNE (ISTOTNE dla konwersji) |
| Inwentaryzacja / import stanów | BRAK (poza korektą) | Korekta pojedynczego wariantu; brak arkusza spisu, importu CSV, synchronizacji z ERP/WMS/hurtownią. Stany wprowadza tylko seed. | lista `#[AsCommand]` (`inventory:seed-demo`, `demo-data:stock`) | BLOKER dla sklepu z istniejącym asortymentem lub zewnętrznym magazynem (min. ISTOTNE) |
| Prezentacja dostępności w sklepie | KOMPLETNA | „Dostępny” / „Ostatnie sztuki” (1–5) / „Niedostępny” na kafelku, stronie i wariancie; nigdy liczba sztuk; filtr „dostępne” w liście; schema.org `OutOfStock`. Odświeżenie przez zdarzenie i purge Varnisha (sekundy; zgubione: do 1 h). | `store/catalog/availability-label.tsx:10-11`, `product-json-ld.ts:13`; `inventory/spec.md:344-351` | — |
| Rola `warehouse` | KOMPLETNA (wąska) | Przyjęcia i korekty: `admin`, `warehouse`; wszyscy pracownicy widzą stany i historię. Brak dla magazyniera listy kompletacji/pakowania, etykiet, skanowania — wysyłka to zmiana statusu zamówienia. | `api/Inventory/Infrastructure/Security/StockVoter.php`; `inventory/spec.md:335-336` | kompletacja: ISTOTNE (inny obszar: zamówienia) |

---

## Mocne strony (z dowodem)

1. **Współbieżność stanów udowodniona testami wieloprocesowymi** na prawdziwym PostgreSQL: 20 rezerwacji na 7 sztuk =
   dokładnie 7 sukcesów; rezerwacje wielu linii w odwrotnej kolejności bez zakleszczeń; idempotencja przyjęć
   i rezerwacji przy 10 równoległych procesach; korekty kontra rezerwacje (`apps/api/tests/Integration/Inventory/Concurrency/*`,
   spec `inventory/spec.md:426-441`). Rzadkie nawet w Magento/Shopware (tam typowo optymistyczne odejmowanie stanu).
2. **Omnibus liczony poprawnie, nie „na skróty”**: z historii cen i historii innych promocji w oknie 30 dni, z obsługą
   wariantów bez historii (`LowestPriorPrice.php` + `LowestPriorPriceTest.php`); blokada zmiany ceny w aktywnej
   promocji chroni wiarygodność ceny referencyjnej (`PriceLockedByPromotion`). WooCommerce i Shopify wymagają do tego
   wtyczek/aplikacji.
3. **Pieniądze wyłącznie w groszach (int)**, jedna formuła VAT, netto linii z brutto linii po rabacie, rabat kodu
   rozłożony metodą największych reszt (suma zawsze się zgadza) — `PriceBreakdown.php`, `BasketQuote.php`, testy
   własności. Gotowe pod fakturę z rozbiciem VAT na pozycje.
4. **Spójna wycena koszyka i zamówienia**: jedna `QuoteBasket` dla widoku koszyka i złożenia; zamówienie kopiuje kwoty
   i dane promocji (decyzja A2 w `discount-codes/spec.md`), więc późniejsza zmiana ceny nie zmienia zamówienia.
5. **Pełna audytowalność**: historia każdej zmiany ceny i każdego ruchu stanu ze źródłem i aktorem z tokena, tylko
   dopisywana (`PriceHistoryEntry`, `StockMovement`).
6. **Kody rabatowe bez wyścigów**: ostatnie użycie kodu z limitem przy równoległych złożeniach
   (`DiscountCodePlacementConcurrencyTest.php`), zwolnienie użycia przy wygaśnięciu, polskie komunikaty odrzucenia
   z kwotą minimum.
7. **Zdarzenia → cache sklepu i indeks**: zmiana ceny, granica promocji i zmiana poziomu stanu unieważniają Varnish
   i reindeksują ES (`InvalidateStorefrontOn*`, `ReindexProductOn*`), więc sklep pokazuje nową cenę w sekundach
   (e2e `price-change.spec.ts`, `stock-receipt.spec.ts`, oczekiwanie do 70 s).

## Niewygodne w codziennym użyciu

- Ceny i stany zmienia się **jeden wariant na raz** w oknie dialogowym (`PriceDialogs.vue`, `StockDialogs.vue`); brak
  edycji w siatce, zaznaczania wielu, importu. Produkt z 30 rozmiarami/kolorami = 30 dialogów ceny i 30 przyjęć.
- Zmiana stawki VAT = programista + deploy + CLI; do tego czasu cała klasa znika ze sprzedaży.
- Aktywnej promocji nie da się poprawić (wariant, procent); cena wariantu w promocji zablokowana — pracownik musi
  zakończyć promocję dla wszystkich wariantów.
- Wybór wariantów do promocji ręczny (wyszukiwarka, 25 produktów na stronę, limit 1000), bez „cała kategoria”.
- Kod rabatowy tylko w kasie i ginie po odświeżeniu strony (D3); koszyk nie pokazuje rabatu.
- Zwrot towaru nie przywraca stanu — trzeba pamiętać o ręcznym przyjęciu.
- Próg „Ostatnie sztuki”/„Niski stan” jeden dla całego sklepu (5), zaszyty w kodzie i indeksie bazy.
- Termin płatności 30 min zaszyty — nie pasuje do przelewu tradycyjnego ani płatności odroczonych.
- Cenę zmienia tylko rola `admin` (marketing robi promocje, ale nie poprawi ceny).

## Nadmiarowe względem potrzeb realnego sklepu (na tym etapie)

- Maszyneria idempotencji i wersjonowania widoczna dla użytkownika (409 „zmieniono w międzyczasie”, klucze przyjęć)
  jest poprawna, ale przy 1–3 pracownikach rzadko potrzebna; koszt to dłuższa implementacja każdej prostej funkcji
  (np. masowej zmiany cen).
- `pricing:reconcile-tax` z kodami wyjścia i przeliczenie po zmianie klasy przez outbox/RabbitMQ — rozbudowane
  zabezpieczenia wokół stawek, których i tak nie da się edytować z panelu.
- Rozbudowana blokada advisory dla zapisów promocji i nakładania okresów przy jednocześnie braku podstawowych typów
  promocji (kwotowa, kategoria, rabat koszykowy).
- Specyfikacje opisują szczegóły (plany zapytań, indeksy częściowe) bardziej niż funkcje biznesowe; sporo z nich
  dotyczy skali 10 mln produktów, której pierwszy realny sklep nie potrzebuje.

## Do sprawdzenia w przeglądarce

1. Panel `/prices`: zmiana ceny wariantu (rola admin), widok historii, próba zmiany ceny wariantu w aktywnej promocji
   (oczekiwany komunikat 409 przy polu), oznaczenie „nieaktualna stawka”.
2. Formularz produktu: wybór klasy podatkowej (czy lista jest zrozumiała dla nietechnicznego użytkownika — nazwy
   klas typu „żele energetyczne”), karta „Ceny i stany” — czy da się ustawić cenę i stan nowego produktu bez wychodzenia.
3. `/promotions/new`: dodanie wariantów („wszystkie warianty produktu”), start „od teraz”, „Zakończ teraz”; potem w sklepie
   kafelek i strona produktu: badge „Promocja” + linia Omnibus, brak przekreślenia; koszyk bez oznaczenia.
4. `/discount-codes/new`: kod procentowy z minimum i limitem; w kasie sklepu: `WITAJ10`, `MINUS50`, wygasły `LATO15`,
   kod poniżej minimum (komunikat z kwotą), odświeżenie strony kasy (kod znika), widok zamówienia i e-mail (Mailpit)
   z linią kodu.
5. `/inventory`: przyjęcie i korekta (rola warehouse), filtr „Niski stan”/„Bez stanu” z pulpitu, historia ruchów
   po złożeniu, wysyłce i anulowaniu zamówienia; po „Zwrot” — czy stan pozostał bez zmian (oczekiwane: tak).
6. Sklep: wariant ze stanem 0 („niedostępny” w wyborze wariantu), 1–5 („Ostatnie sztuki”), próba dodania do koszyka
   więcej niż stan (komunikat), czas pojawienia się zmiany ceny/stanu po zapisie w panelu.
7. Ekran 375 px dialogów ceny, przyjęcia, korekty i formularza kodu.
