# 12. Research: nasz sklep na tle Sylius, Shopware 6, Medusa v2 i Saleor

## Zakres i metoda

**Nasz sklep (audyt statyczny, tylko katalog eksportu).** Przeczytane w całości albo w kluczowych częściach:
`docs/vision.md` (417 linii), specyfikacje `checkout`, `payment`, `inventory`, `pricing`, `promotions`,
`discount-codes`, `order-management`, `cart`, `search` (9 plików, wybrane sekcje: decyzje, model, transakcje, ryzyka),
`apps/api/config/packages/messenger.yaml`, `apps/api/CLAUDE.md` (początek), lista migracji (41), struktura
`apps/api/src` (1286 plików PHP, 9 modułów) oraz kilkanaście plików kodu potwierdzających tezy ze specyfikacji
(`PaymentProvider`, `StartPaymentHandler`, `HandleProviderWebhookHandler`, `ExpireUnpaidOrderHandler`, `OrderStatus`,
`PolishVatRates`, `LowestPriorPrice`, repozytoria Inventory, publisher i relay outboxa). Policzone pliki testów.
Niczego nie uruchamiałem.

**Platformy (sieć, 18.11.2026).** Około 75 pobrań źródeł pierwotnych: oficjalna dokumentacja, repozytoria i ADR-y.
Bieżące wersje widoczne w źródłach: Sylius gałąź `2.3`, Shopware `6.7.x` (zapowiedzi usunięć w 6.8), Medusa `v2.22.x`,
Saleor `3.23`. W zapytaniach nie było żadnych danych o projekcie.

**Oznaczenia.** `[SYn]`, `[SWn]`, `[MEn]`, `[SAn]`, `[BPn]` to źródła z listy na końcu, sprawdzone teraz. **(NZ)** =
teza z pamięci, niezweryfikowana w źródle w tej sesji. Dokumentacja Sylius 2.x (`docs.sylius.com`) przekierowuje na
logowanie (`connect.sylius.com`), więc dla Syliusa czytałem dokumentację 1.x („latest” na `old-docs.sylius.com`) oraz
kod i ADR-y gałęzi `2.3` na GitHubie; tezy o Syliusie z dokumentacji opisują więc zachowanie udokumentowane dla 1.x,
potwierdzone w 2.x tylko tam, gdzie podaję plik z gałęzi `2.3`.

---

## Ocena ogólna obszaru

**7/10** (nasz sklep względem dojrzałych platform, w roli „jednego sklepu”, nie platformy).

- **Rdzeń transakcyjny jest na poziomie platform albo powyżej**: brak nadsprzedaży egzekwowany blokadą wiersza
  i `CHECK` w bazie, klucze idempotencji z odciskiem żądania, transactional outbox, deduplikacja webhooków, cena Omnibus
  liczona automatycznie z historii. Żadna z czterech platform nie ma w rdzeniu kompletu tych mechanizmów (szczegóły
  w tematach 4, 5 i 6).
- **Zakres funkcji jest wyraźnie poniżej minimum „sklepu gotowego do sprzedaży”**, które w każdej z czterech platform
  jest w rdzeniu albo w oficjalnej wtyczce: metody i koszty dostawy, zwrot pieniędzy przez operatora, zwroty częściowe,
  faktury, edycja zamówienia. To są rzeczywiste braki, nie różnica celu (temat 10).
- **Model zamówienia jest prostszy niż we wszystkich platformach** (jeden status zamiast osobnych wymiarów zamówienia,
  płatności i wysyłki). Dla MVP z przedpłatą wystarcza, ale jest to najdroższa do późniejszej zmiany decyzja (Z-1).
- **Część złożoności jest większa niż w platformach** bez odpowiednika w korzyściach na tej skali (osobny model
  persystencji, powielone value objecty), sekcja „Zbędna złożoność”.

---

## Temat 1. Architektura i modularność

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | Monolit modularny: 7 bounded contextów + `Backoffice` + `PaymentSimulator` (`apps/api/src`), warstwy Domain / Application / Infrastructure / UI pilnowane przez Deptrac (`apps/api/deptrac.yaml`, 458 linii). Trzy szyny Messengera: `command.bus` z `doctrine_transaction`, `query.bus`, `event.bus` (`apps/api/config/packages/messenger.yaml:8-23`). Contexty wołają się przez porty i szynę (23 adaptery `QueryBus*`/`CommandBus*`), każdy ma własne value objecty; agregaty bez mapowania Doctrine, osobne rekordy persystencji (26 klas `Record`). Bez mechanizmu rozszerzeń. |
| **Sylius** | Komponenty (odsprzęgnięte pakiety) → bundle → Core → aplikacja Symfony; podział Shop / Admin / API; API na API Platform, „we dispatch command instead mixing API logic with business logic” [SY2]. Encje Doctrine i warstwa Resource, bez taktycznego DDD; szyny `sylius.command_bus` i `sylius.event_bus` [SY10], ADR-y o ujednoliceniu szyn [SY11]. Rozszerzanie przez pluginy (bundle) (NZ). Maszyny stanów: od 2.0 domyślnie Symfony Workflow zamiast winzou [SY10]. |
| **Shopware 6** | Core / Storefront / Administration, API-first, „domain-driven structure” jako zasada organizacji kodu, bez CQRS [SW1]. Własna warstwa DAL zamiast ORM, z indekserami liczącymi dane przy zapisie [SW17]. Dwa mechanizmy rozszerzeń: pluginy (w procesie) i aplikacje (wyłącznie HTTP, webhooki, skrypty) [SW14]. |
| **Medusa v2** | Trasy API → workflowy → moduły → PostgreSQL [ME1]. Moduły są izolowane: „Modules can't access resources, such as services or data models, from other modules”; łączą je linki i workflowy [ME11]. Spójność między modułami przez funkcje kompensujące (saga), nie przez wspólną transakcję [ME8]. |
| **Saleor** | Monolit w Pythonie z API wyłącznie GraphQL; „APIs are the only way to interact, configure, or extend the backend” [SA15]. Rozszerzanie przez aplikacje i webhooki synchroniczne/asynchroniczne; API pluginów jest przestarzałe [SA5, SA17]. Odradzają forki [SA5]. |
| **Dobra praktyka** | Monolit modularny z granicami egzekwowanymi narzędziem; punkty rozszerzeń tylko tam, gdzie są realni integratorzy. CQRS i DDD taktyczne tam, gdzie reguły są złożone (zamówienie, magazyn, ceny), nie wszędzie. |

**Ocena.** Granice modułów są egzekwowane mocniej niż w Syliusie i Shopware (tam granice są konwencją pakietów) i podobnie
jak w Medusie, z tą różnicą, że nasz sklep świadomie zostaje przy jednej transakcji bazy między contextami
(`docs/features/checkout/spec.md:52`, decyzja A3), a Medusa płaci za izolację modułów sagą i kompensacjami [ME8].
Dla jednego sklepu z jedną bazą nasz wybór jest prostszy i bezpieczniejszy. Żadna z platform nie stosuje osobnego modelu
persystencji obok modelu domeny ani własnych value objectów per moduł; to nasz koszt własny (sekcja „Zbędna złożoność”).

**Różnica celu, nie brak:** system pluginów, aplikacji i webhooków wychodzących. Nie kopiować.

---

## Temat 2. Model zamówienia i checkoutu

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | Koszyk i zamówienie to dwa agregaty w dwóch contextach. `PlaceOrder` w jednej transakcji: blokada koszyka → wycena → użycie kodu → numer → opróżnienie koszyka → rezerwacja → zapis (`docs/features/checkout/spec.md:172-222`). Zamówienie trzyma kopie: ceny jednostkowej (netto, VAT, brutto, stawka, klasa), nazw, adresu, promocji i kodu (`spec.md:110-123`), z `CHECK (total_net + total_vat = total_gross)`. Jeden status: `new`, `paid`, `shipped`, `delivered`, `cancelled`, `returned` (`apps/api/src/Ordering/Domain/OrderStatus.php:14-19`), przejścia jako metody agregatu w czystym PHP; historia statusów w agregacie. Bez edycji po złożeniu, zwrot tylko całego zamówienia (`docs/features/order-management/spec.md`, „Poza zakresem”). |
| **Sylius** | Koszyk to `Order` w stanie `cart`; po checkoucie `new` → `fulfilled` / `cancelled` [SY3]. Osobne maszyny: zamówienie, checkout (7 stanów, kroki powtarzalne), płatność, wysyłka; po zakończeniu checkoutu `paymentState = awaiting_payment`, `shippingState = ready` [SY4]. Koszty i rabaty jako `Adjustment` [SY3]. |
| **Shopware 6** | Koszyk to osobna, mutowalna struktura identyfikowana tokenem, zapisywana w całości poza DAL [SW2]; zamówienie „workflow optimized”, niezależne od katalogu: „The line item with all of its data, as well as all calculated prices, is persisted” [SW4]. Trzy maszyny stanów: `order`, `order_transaction`, `order_delivery`, w tym stany częściowe (`paid_partially`, `shipped_partially`, `returned_partially`) [SW6]. Przeliczenie zamówienia tylko na jawne żądanie [SW4]. Edycja przez wersjonowanie encji (NZ). |
| **Medusa v2** | Koszyk i zamówienie w osobnych modułach; `completeCartWorkflow` tworzy zamówienie [ME10]. Zmiany po złożeniu to `OrderChange` (edycja, wymiana, reklamacja, zwrot) z akcjami zatwierdzanymi osobno; wersja zamówienia rośnie przy zwrocie [ME7, ME18]. Rozliczenie jako `OrderTransaction`: suma transakcji ma się równać sumie zamówienia, inaczej jest saldo do wyrównania [ME6]. |
| **Saleor** | Checkout (jeden typ na koszyk i kasę) → `checkoutComplete` → zamówienie [SA3, SA13]. Statusy realizacji: `DRAFT`, `UNCONFIRMED`, `UNFULFILLED`, `PARTIALLY_FULFILLED`, `FULFILLED`, `PARTIALLY_RETURNED`, `RETURNED`, `CANCELED`, `EXPIRED` [SA4]; stan pieniędzy osobno (`authorizeStatus`, transakcje) [SA2]. |
| **Dobra praktyka** | Zamówienie jako niezmienna migawka (ceny, podatki, nazwy, adres). Osobne wymiary: cykl zamówienia, płatność, realizacja. Koszyk jako osobny, tani w zapisie byt. |

**Ocena.** Migawki cen, VAT, promocji i adresu są pełniejsze niż w dokumentacji którejkolwiek platformy (kopia stawki,
klasy podatkowej, promocji i rozkładu rabatu na linie, z niezmiennikami w bazie). Oddzielenie koszyka od zamówienia jest
zgodne z trzema z czterech platform. **Wyraźnie gorzej:** jeden status łączący płatność i realizację (Z-1) oraz brak
jakiejkolwiek korekty zamówienia po złożeniu.

---

## Temat 3. Płatności

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | Port `PaymentProvider` z trzema operacjami: `createSession`, `cancelSession`, `parseWebhook` (`apps/api/src/Payment/Application/Port/PaymentProvider.php:23-55`), dwa udawane adaptery o różnych API. Agregat `Payment` = jedna próba; `pending`, `succeeded`, `failed`, `cancelled`. Webhook: podpis HMAC (jeden dostawca ze znacznikiem czasu i tolerancją 300 s), blokada wiersza płatności, deduplikacja `(provider, eventId)`, kontrola kwoty, opłacenie zamówienia w tej samej transakcji (`docs/features/payment/spec.md:283-311`). `Idempotency-Key` na rozpoczęciu płatności. Płatność po terminie, po anulowaniu albo podwójna jest trwale oznaczana do zwrotu (`refund_required_reason`). Bez autoryzacji/pobrania, bez zwrotu przez dostawcę, bez płatności częściowych, bez odpytywania dostawcy o stan. |
| **Sylius** | Encja `Payment` z maszyną: `cart`, `new`, `processing`, `authorized`, `completed`, `failed`, `cancelled`, `refunded` [SY6]. Historycznie Payum; w 2.0 pakiety Payum usunięte z głównego `composer.json` i nowe transporty Messengera dla „payment requests” [SY10]. Zwroty w oficjalnym RefundPlugin na licencji MIT („basic refunds functionality”) [SY16]; zwroty częściowe i noty korygujące (NZ). Według wtyczek dla 2.x endpoint notify czyta stan ponownie z API operatora zamiast ufać treści (źródło pośrednie, wyniki wyszukiwania [SY19]). |
| **Shopware 6** | Zamówienie powstaje z otwartą transakcją, potem handler płatności: synchroniczny albo asynchroniczny z przekierowaniem i `/payment/finalize-transaction` [SW5]. Stany transakcji: `open`, `in_progress`, `authorized`, `paid`, `paid_partially`, `refunded`, `refunded_partially`, `failed`, `reminded`, `cancelled` [SW6]. Zwroty: encje capture i refund z własnymi maszynami stanów [SW18]. Webhooki operatorów „go beyond our standards”: zostawione integracjom [SW5]. |
| **Medusa v2** | Payment collection → sesje → autoryzacja przy złożeniu → `Payment` → capture i refund z kwotą (częściowe), bez płatności dzielonych; `pending_authorization` dopuszcza zamówienie „awaiting” do webhooka [ME4]. Webhook: `getWebhookActionAndData` dostawcy → `authorized` / `captured`; „if the cart associated with the payment session is not completed yet, Medusa will complete the cart” [ME5]. `idempotency_key` zwrotu od v2.22.0 [ME4]. |
| **Saleor** | Transactions API: `TransactionItem` z listą zdarzeń (`AUTHORIZATION_*`, `CHARGE_*`, `REFUND_*`), jedno zdarzenie danego typu i `pspReference`; `transactionEventReport` zwraca `alreadyProcessed: true` dla powtórzenia [SA2]. Wiele transakcji na checkout (płatność dzielona). Automatyczne domknięcie checkoutu, gdy `authorizeStatus` osiągnie `FULL`, przez zadanie cykliczne [SA2]. Opcjonalne zwolnienie środków porzuconych checkoutów [SA13]. |
| **Dobra praktyka** | Weryfikacja podpisu na surowej treści, tolerancja znacznika czasu, zapis przetworzonych identyfikatorów zdarzeń, brak założeń o kolejności, szybkie `2xx` i przetwarzanie w kolejce [BP2]. Klucze idempotencji na operacjach tworzących [BP1]. Uzgadnianie stanu z API operatora jako siatka bezpieczeństwa (praktyka branżowa, NZ). |

**Ocena.** Obsługa webhooka (podpis, tolerancja czasu, deduplikacja, kwota, blokada, atomowość z zamówieniem) spełnia
zalecenia [BP2] i jest dokładniejsza niż to, co platformy dają w rdzeniu (Shopware zostawia webhooki integracjom,
Medusa nie dokumentuje deduplikacji [ME5]). Trwałe oznaczanie płatności „do zwrotu” to rozwiązanie, którego w tej postaci
nie ma w dokumentacji platform. **Wyraźnie gorzej:** model płatności jest jednoetapowy i nie ma w nim zwrotu ani odczytu
stanu u operatora (Z-2, Z-3); wszystkie cztery platformy mają co najmniej stany autoryzacji, pobrania i zwrotu.

---

## Temat 4. Magazyn

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | `StockItem` per wariant: `physical`, `reserved`, `available = physical − reserved` (liczone). `Reservation` z kluczem `orderId`: `active → released \| committed` (`docs/features/inventory/spec.md:56-126`). Rezerwacja przy złożeniu zamówienia (w tej samej transakcji), zatwierdzenie przy wysyłce, zwolnienie przy anulowaniu albo wygaśnięciu po 30 + 5 min. Współbieżność: `SELECT … FOR UPDATE` w kolejności `variant_id` (`apps/api/src/Inventory/Infrastructure/Persistence/Doctrine/DoctrineStockItemRepository.php:54`) i `CHECK (reserved >= 0 AND physical >= reserved …)`. Historia ruchów. Jeden magazyn, bez rezerwacji w koszyku. |
| **Sylius** | `onHand` i `onHold` na wariancie; `hold` przy zakończeniu checkoutu, `sell` po opłaceniu, `release` / `giveBack` przy anulowaniu [SY5]. Blokada optymistyczna wariantów: `lock($variant, LockMode::OPTIMISTIC, $variant->getVersion())` [SY12]. Wiele magazynów tylko w Sylius Plus [SY5]. Anulowanie nieopłaconych zamówień poleceniem z crona (pośrednio [SY18]). |
| **Shopware 6** | Jedno pole `stock`, zmniejszane przy złożeniu zamówienia, zwiększane przy anulowaniu; `available_stock` wycofywane, bo liczenie go „is slow on large catalogs”; wymienny `AbstractStockStorage` [SW8]. Bez rezerwacji; wiele magazynów w rozszerzeniu komercyjnym [SW9]. |
| **Medusa v2** | `InventoryLevel` per lokalizacja: `stocked_quantity`, `reserved_quantity`, `incoming_quantity`; `ReservationItem` tworzony przy złożeniu zamówienia; realizacja zmniejsza oba liczniki [ME2, ME3]. Sprawdzenie stanu przy dodaniu do koszyka, bez rezerwacji [ME3]. Ochrona przed nadsprzedażą modułem blokad; domyślny dostawca w pamięci „not recommended for production” [ME9]. |
| **Saleor** | `Stock` per magazyn z `quantityAllocated` i `quantityReserved` [SA1]. Alokacja przy utworzeniu zamówienia; bez rezerwacji „the first checkout to complete receives it”. Opcjonalna rezerwacja w checkoucie z czasem życia osobno dla gości i zalogowanych [SA9, SA10]. Wygaśnięcie nieopłaconego zamówienia zwalnia alokację [SA4, SA16]. |
| **Dobra praktyka** | Rezerwacja przy złożeniu zamówienia (nie w koszyku), z terminem dla nieopłaconych; niezmiennik stanu egzekwowany w bazie; operacje idempotentne po kluczu. |

**Ocena.** To najmocniejszy obszar. Moment rezerwacji i zdjęcia ze stanu (zamówienie → wysyłka) jest taki jak w Medusie
i Saleorze. Ochrona przed nadsprzedażą jest silniejsza niż w każdej z platform: Sylius opiera się na blokadzie
optymistycznej (przegrany dostaje wyjątek), Shopware nie ma rezerwacji, Medusa wymaga skonfigurowania zewnętrznego
dostawcy blokad, a u nas szereguje baza i dodatkowo pilnuje `CHECK`. Brak rezerwacji w koszyku jest zgodny z domyślnym
zachowaniem wszystkich czterech. **Słabiej:** zwolnienie rezerwacji zależy od jednej opóźnionej wiadomości (Z-4).

**Różnica celu, nie brak:** wiele magazynów, strategie alokacji, lokalizacje.

---

## Temat 5. Ceny i promocje

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | Cena brutto wpisywana, netto i VAT wyliczane w groszach jedną formułą HALF_UP (`PriceBreakdown`, `docs/features/pricing/spec.md`, „Model domenowy”); netto i VAT linii liczone z brutto linii po rabacie. Klasa podatkowa w Catalog, stawka z tabeli w kodzie (`apps/api/src/Pricing/Domain/Tax/PolishVatRates.php:17-25`). Tylko PLN, jeden cennik. Promocja: jeden procent na listę wariantów na okres, wariant najwyżej w jednej promocji naraz; cena skuteczna liczona przy odczycie. Omnibus liczony automatycznie z historii cen i promocji (`apps/api/src/Pricing/Domain/Promotion/LowestPriorPrice.php:19-37`). Kody: procent albo kwota na całe zamówienie, minimum, limit łączny, jeden kod na zamówienie, bez limitu na klienta (`docs/features/discount-codes/spec.md:42`); użycie zapisywane pod blokadą wiersza kodu, zwalniane tylko przy wygaśnięciu. |
| **Sylius** | Cena per wariant i kanał w walucie bazowej kanału; `price`, `originalPrice`, `minimumPrice` („the price below which, any promotion can't decrease price”) [SY7]; pole `lowestPriceBeforeDiscount` w `ChannelPricing` [SY13]. Promocje katalogowe i koszykowe: reguły, akcje, filtry, `exclusive`, priorytet, limit użyć, kupony [SY8]. Podatki: kategorie, stawki, strefy, flaga „included in price”, kalkulatory z zaokrągleniem albo bez [SY9]. |
| **Shopware 6** | Brutto i netto powiązane stawką, cena katalogowa, pole „najtańsza cena (ostatnie 30 dni)” wypełniane ręcznie („You must fill in this field…”), ceny zaawansowane według reguł i progów ilości, ceny per waluta [SW12]. Promocje: bez kodu, kod stały, kody indywidualne; limit łączny i na klienta; warunki z Rule Buildera; priorytet; wykluczenia łączenia [SW13]. |
| **Medusa v2** | Price set, reguły cen, cenniki (wyprzedaż/nadpisanie) z okresem, waluty i regiony [ME12]; ceny z podatkiem przez preferencje per waluta/region [ME17]. Promocje: `standard` i `buyget`, reguły, metoda aplikacji, kampanie z budżetem użyć albo kwoty [ME13]. Omnibus: brak w rdzeniu (NZ). |
| **Saleor** | Ceny i waluty per kanał [SA15]; podatki konfigurowane per kanał, ceny wpisywane z podatkiem albo bez, klasy podatkowe, stawki płaskie albo aplikacja podatkowa [SA8]. Promocje katalogowe i zamówieniowe, wygrywa najlepszy rabat, bez sumowania [SA6]. Vouchery: limit łączny, raz na klienta, kody jednorazowe, minimum; użycie rośnie przy zamówieniu, zwalnia je wygaśnięcie, nie anulowanie [SA7]. `pricePrior`: „Saleor does not track `pricePrior` automatically” [SA12]. |
| **Dobra praktyka** | Kwoty całkowite w najmniejszej jednostce, jedna jawna reguła zaokrągleń, `netto + VAT = brutto` jako niezmiennik. Omnibus liczony z historii, nie wpisywany ręcznie. Limity kodów egzekwowane atomowo. |

**Ocena.** Arytmetyka cen (liczby całkowite, jedna formuła, niezmiennik w bazie) i Omnibus są **lepsze niż w Shopware
i Saleorze** (tam najniższą cenę wpisuje człowiek albo zewnętrzna aplikacja) i na poziomie Syliusa. Zasada zwalniania
użycia kodu tylko przy wygaśnięciu jest identyczna z Saleorem [SA7], co potwierdza decyzję Q1. Silnik promocji jest
celowo dużo uboższy niż w platformach (brak reguł, akcji, priorytetów, łączenia): to różnica celu. **Rzeczywisty brak:**
limit użyć kodu na klienta (Z-5), który mają Shopware i Saleor, oraz dolna granica ceny po rabatach w stylu
`minimumPrice` Syliusa (dziś nie jest potrzebna, bo procent ≤ 90 i jedna promocja na wariant).

**Różnica celu, nie brak:** kanały, waluty, cenniki, strefy podatkowe, aplikacje podatkowe.

---

## Temat 6. Spójność i niezawodność

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | Jedna transakcja `command.bus` obejmuje kilka contextów przez zagnieżdżony dispatch, ze stałą kolejnością blokad koszyk → kod → stany (`docs/features/checkout/spec.md:224-242`). Transactional outbox: zdarzenie do transportu Doctrine na tym samym połączeniu (`messenger.yaml:29-39`, `MessengerDomainEventPublisher.php:21-24`), relay do RabbitMQ z potwierdzeniem publikacji (`RelayDomainEventToBroker.php:23-26`, `confirm_timeout`), odbiorcy idempotentni. Opóźnione komendy w bazie (`scheduled`, `promotions`). `Idempotency-Key` z odciskiem żądania na złożeniu zamówienia, rozpoczęciu płatności i przyjęciu magazynowym. Wersje optymistyczne dla edycji z panelu, blokady doradcze dla promocji. |
| **Sylius** | Handlery bez `flush`, transakcja wokół handlera (ADR) [SY15]; szyny komend i zdarzeń [SY10]. Blokada optymistyczna stanów [SY12]. Outbox i klucze idempotencji API: brak w rdzeniu (NZ). |
| **Shopware 6** | Koszyk chroniony nieblokującym `CartLocker` z odpowiedzią 409; dokumentacja wprost opisuje luki: blokada „doesn't make cart loading, modification, and saving one atomic operation or compare cart versions”, domyślny magazyn blokad jest lokalny dla hosta [SW3]. Kolejki na Symfony Messenger [SW15]. Outbox: brak (NZ). |
| **Medusa v2** | Zamiast transakcji między modułami workflow z kompensacją [ME8]. `completeCartWorkflow` jest idempotentny po id koszyka i wymaga blokady wokół wywołania: „it will not create duplicate orders” [ME10]. Zdarzenia przez pub/sub na Redisie, bez opisanych gwarancji dostarczenia [ME16]. |
| **Saleor** | Webhooki asynchroniczne: do 5 prób z wykładniczym odstępem, bez gwarancji kolejności, z ostrzeżeniem o „possibility of losing events when the destination server is down” [SA11]. Deduplikacja zdarzeń transakcji po typie i `pspReference` [SA2]. |
| **Dobra praktyka** | Outbox: zapis komunikatu w transakcji zmiany, relay, „a message consumer must be idempotent” [BP3]. Nagłówek `Idempotency-Key` z odciskiem treści; projekt IETF (wersja 08, 22.10.2026) zaleca 422 dla klucza użytego z inną treścią i 409 dla żądania w toku [BP4]. Stripe porównuje parametry powtórzonego żądania z pierwszym [BP1]. |

**Ocena.** Tu nasz sklep jest **powyżej wszystkich czterech platform**: żadna nie dokumentuje outboxa w rdzeniu, a klucz
idempotencji na złożeniu zamówienia z odciskiem żądania ma odpowiednik tylko w Medusie (po id koszyka). Kody odpowiedzi
różnią się od projektu IETF w jednym miejscu: powtórzony klucz z inną treścią to u nas 409
(`docs/features/checkout/spec.md:163`), w projekcie 422 [BP4]; to kosmetyka, klient rozróżnia przyczynę po `type`.
Jedna transakcja przez kilka contextów jest uzasadniona wspólną bazą; saga Medusy rozwiązuje problem, którego tu nie ma.

---

## Temat 7. API i fronty

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | REST, dwa pliki OpenAPI (sklep i panel), z nich generowane klienty TS; błędy w RFC 9457 z `type`. Next.js: strony produktów ISR z rewalidacją na żądanie, listingi SSR przez Varnish z tagami xkey unieważnianymi po zdarzeniu z outboxa (`docs/vision.md:105-130`). Elasticsearch jako model odczytu z fasetami. Panel jako osobna SPA (Vue). |
| **Sylius** | REST na API Platform, operacje pod prefiksami `shop` i `admin` [SY2]; sklep i panel renderowane po stronie serwera (Twig) [SY2]. Wyszukiwarka: brak silnika w rdzeniu (NZ). |
| **Shopware 6** | Store API („JSON over HTTP”, dla frontów headless) i osobne Admin API [SW16]. Cache HTTP z odwrotnym proxy (Varnish, Fastly), unieważnianie przez trasy Store API, listingi tylko po TTL: „listing pages cannot guarantee strict, immediate consistency” [SW10]; unieważnianie opóźnione domyślnie co 5 minut [SW15]. Elasticsearch/OpenSearch dla wyszukiwarki i listingów [SW19]. |
| **Medusa v2** | REST (Express), trasy sklepu i panelu [ME1]. Wyszukiwarka przez integracje zewnętrzne (NZ). |
| **Saleor** | Wyłącznie GraphQL [SA15, SA17]. Wbudowane wyszukiwanie pełnotekstowe wielu encji od 3.23, z prefiksami i operatorami [SA18]; bez faset (NZ). |
| **Dobra praktyka** | Osobne API sklepu i panelu z osobnymi uprawnieniami; kontrakt generowany z kodu; cache z unieważnianiem po tagach dla stron produktów i akceptowana krótka nieświeżość listingów. |

**Ocena.** Podział API i kontrakt są na poziomie platform; generowany klient z kontrolą `tsc` w CI to mocniejsza gwarancja
niż w Syliusie i Shopware. Strategia cache jest zbieżna z Shopware (proxy z tagami) i w jednym punkcie dokładniejsza:
u nas listingi też są unieważniane po tagu produktu, Shopware świadomie zostawia je na TTL. REST zamiast GraphQL przy
dwóch własnych frontach to trafna decyzja; GraphQL Saleora służy obcym integratorom.

---

## Temat 8. Wydajność i skala

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | Cel: 10 mln produktów, 60 tys. aktywnych użytkowników (`docs/vision.md:227-234`), niepotwierdzony testami (pomiary na małym katalogu). Mechanizmy: indeks ES aktualizowany zdarzeniami, stronicowanie kursorami, indeksy częściowe stanów, modele odczytu (`ordering_customer`, sprzedaż godzinowa), mapa strony w porcjach, kolejka `jobs` na reindeksację poddrzewa. Znane ograniczenia: okno wyników 10 000, przebudowa indeksu nie jest online (`docs/features/search/spec.md:203`, `:410-411`), fasety `nested` na 10 mln niesprawdzone (`:424`). |
| **Sylius** | Doctrine ORM bez wbudowanej wyszukiwarki; duże katalogi wymagają pracy własnej (NZ). |
| **Shopware 6** | „When a project uses several thousand data sets, it's worth integrating Elasticsearch” [SW19]. Zalecenia dla dużych wdrożeń: cache HTTP zawsze, Redis dla numeracji, blokad i liczników, poczta przez kolejkę, wyłączenie auto-setup transportów, sitemap poza godzinami szczytu, testy k6 zamiast ogólnych benchmarków [SW15]. Indeksery przy zapisie [SW17]. |
| **Medusa v2** | Index Module (centralny magazyn do filtrowania między modułami) jest od v2.22.7 przestarzały; zastępuje go filtrowanie w bazie [ME14]. |
| **Saleor** | Ceny po promocji katalogowej przeliczane w tle [SA6]; treść webhooków budowana z opóźnieniem przez worker [SA11]. |
| **Dobra praktyka** | Wyszukiwarka i listingi z indeksu, nie z bazy transakcyjnej; odczyty publiczne z cache; ciężkie przeliczenia w tle; deklaracje wydajności dopiero po testach obciążeniowych na danych w docelowej skali. |

**Ocena.** Zestaw mechanizmów jest taki sam jak w zaleceniach Shopware, a cel (10 mln produktów) wyższy niż to, co
którakolwiek platforma opisuje w dokumentacji jako typowe. Wizja uczciwie zaznacza, że to cel niepotwierdzony.
Przypadek Medusy (wprowadzenie i wycofanie ogólnego silnika modeli odczytu [ME14]) potwierdza nasze podejście:
celowane modele odczytu zamiast ogólnego mechanizmu.

---

## Temat 9. Testy i jakość

| | Rozwiązanie |
|---|---|
| **Nasz sklep** | Pliki testów API: 233 jednostkowe, 163 integracyjne, 68 funkcjonalnych (`apps/api/tests`); 36 specyfikacji Playwright (`e2e/tests`); 216 plików testów frontów; skrypty k6 (`load/`). PHPStan `level: max` (`apps/api/phpstan.dist.neon:7`), Deptrac, PHP CS Fixer, ESLint, `tsc`; kontrakt przez aktualność OpenAPI w CI. |
| **Sylius** | „Full-stack behavior-driven development” z Behatem (`features/`) i PHPUnit [SY1]; ADR z 2025 r.: testy jednostkowe z PHPSpec do PHPUnit [SY14]. |
| **Shopware 6** | ADR „follow test pyramid”: zestaw E2E trwał ponad 6 godzin i był niestabilny, więc E2E tylko dla najważniejszych ścieżek, nowe testy E2E uruchamiane „at least 50 times” przed scaleniem, niestabilne do kwarantanny [SW11]. |
| **Medusa v2** | Jest; framework testów integracyjnych dla tras, workflowów i modułów, jednostkowe zwykłym Jestem [ME15]. |
| **Saleor** | pytest (`conftest.py`), pokrycie w Codecov, Ruff, Semgrep [SA15]. |
| **Dobra praktyka** | Piramida testów; E2E tylko dla krytycznych ścieżek; testy współbieżności dla magazynu i płatności; analiza statyczna na najwyższym poziomie; testy kontraktu między API i frontami. |

**Ocena.** Proporcje (dużo jednostkowych, mniej E2E) odpowiadają temu, do czego Shopware doszedł po kosztownym odwróceniu
piramidy. Analiza statyczna jest ostrzejsza niż udokumentowana w platformach, a Deptrac nie ma tam odpowiednika.
Do przejęcia z Shopware: reguła wielokrotnego uruchomienia nowego testu E2E przed scaleniem i kwarantanna niestabilnych.
Jakości samych testów (czy sprawdzają to, co deklarują) nie oceniałem.

---

## Temat 10. Minimalny zakres „sklepu gotowego do sprzedaży”

| Funkcja | Nasz sklep | Sylius | Shopware 6 | Medusa v2 | Saleor |
|---|---|---|---|---|---|
| Metody i koszty dostawy | **brak** (`checkout/spec.md:42`, P3) | rdzeń: przesyłki z metodą i własną maszyną stanów [SY3] | rdzeń: dostawy w koszyku, `order_delivery` [SW2, SW6] | rdzeń: metody wysyłki zamówienia, moduł realizacji [ME6] | rdzeń: metody dostawy, realizacje [SA4, SA13] |
| Podatki | VAT PL, klasy, stawki w kodzie | kategorie, stawki, strefy [SY9] | stawki, brutto/netto [SW12] | regiony, ceny z podatkiem [ME17] | per kanał, stawki albo aplikacja [SA8] |
| Zwrot towaru (RMA) | tylko całe zamówienie, rejestruje pracownik | Plus (NZ) | stany `returned(_partially)` [SW6]; pełne RMA komercyjne (NZ) | rdzeń: zwroty, wymiany, reklamacje, także dla gościa [ME18] | statusy `PARTIALLY_RETURNED`, `RETURNED` [SA4] |
| Zwrot pieniędzy | ręczny poza systemem, znacznik w zamówieniu | RefundPlugin (MIT) [SY16] | encje capture/refund [SW18] | refund z kwotą i kluczem idempotencji [ME4] | zdarzenia `REFUND_*`, żądanie zwrotu do aplikacji płatności [SA2] |
| Faktury | **brak** (`vision.md:366`) | InvoicingPlugin (MIT) [SY17] | dokumenty w rdzeniu (NZ) | brak w rdzeniu (NZ) | faktury przy zamówieniu (pośrednio: pole wyszukiwania [SA18]) |
| E-maile transakcyjne | 5 tekstowych (złożenie, płatność, anulowanie, wysyłka, zwrot) | rdzeń (Symfony Mailer) [SY10] | szablony i Flow Builder (pośrednio: tytuły ADR-ów [SW7]) | moduł powiadomień [ME1] | przez aplikacje i webhooki (NZ) |
| Role administratorów | 4 role z Keycloaka, votery w API | role w Plus (NZ) | ACL w rdzeniu (NZ) | (NZ) | grupy uprawnień (NZ) |
| Kanały, waluty | jeden, PLN | wiele [SY7] | kanały sprzedaży, waluty [SW12] | regiony, waluty [ME12] | „native-multichannel” [SA15] |
| Edycja zamówienia | **brak** | ograniczona (NZ) | tak (NZ) | `OrderChange` [ME7] | zamówienia robocze i niepotwierdzone [SA4] |
| RODO: anonimizacja | **brak** (`checkout/spec.md:32`, #119) | (NZ) | (NZ) | (NZ) | (NZ) |

**Ocena.** Kanały, waluty i języki to różnica celu. **Rzeczywiste braki** względem minimum wspólnego dla wszystkich
czterech platform: dostawa z kosztem, zwrot pieniędzy przez operatora, zwroty częściowe, dokument sprzedaży. Bez dostawy
i dokumentu sprzedaży sklep nie może legalnie i praktycznie sprzedawać w Polsce; wizja mówi to wprost („Poza zakresem”).

---

## Znaleziska

Znaleziska dotyczą różnic względem platform, które mają konkretną konsekwencję w naszym kodzie. Posortowane wagą.

### Z-1. Jeden status zamówienia łączy płatność i realizację — Medium, PREFERENCJA

- **Dowód:** `apps/api/src/Ordering/Domain/OrderStatus.php:14-19`:
  ```php
  case New = 'new';
  case Paid = 'paid';
  case Shipped = 'shipped';
  case Delivered = 'delivered';
  case Cancelled = 'cancelled';
  case Returned = 'returned';
  ```
  Przejścia w `docs/features/order-management/spec.md` („Statusy i przejścia”): `ship` tylko z `paid`, `return` tylko
  całego zamówienia.
- **Porównanie:** wszystkie cztery platformy rozdzielają te wymiary: Sylius (zamówienie, checkout, płatność, wysyłka)
  [SY3, SY4], Shopware (trzy maszyny, stany częściowe) [SW6], Saleor (status realizacji i osobno stan pieniędzy)
  [SA2, SA4], Medusa (zamówienie i `OrderTransaction`) [ME6].
- **Konsekwencja:** modelu nie da się rozszerzyć bez migracji danych i zmiany kontraktu API o: płatność przy odbiorze
  (wysyłka przed zapłatą), wysyłkę częściową, zwrot części pozycji, częściowy zwrot pieniędzy. Dziś szkody nie ma, bo
  MVP ma tylko przedpłatę i wysyłkę całości. Koszt rośnie z każdym miejscem, które czyta `status` (panel, sklep,
  e-maile, model `statusForPayment`, sprzedaż godzinowa).
- **Rekomendacja:** nie zmieniać teraz. Przed epikiem dostawy (#120) albo prawdziwych płatności zdecydować, czy
  `OrderStatus` zostaje jednym polem; jeśli w planie jest pobranie albo zwroty częściowe, rozdzielić na stan zamówienia,
  płatności i wysyłki w tym samym epiku, w którym i tak zmienia się kontrakt. Wzorzec nazw: Sylius [SY4].
- **Pewność:** wysoka co do faktu; średnia co do wagi (zależy od planu produktu). Sprawdziłem, że specyfikacja świadomie
  przyjmuje to uproszczenie (A1, A3 w `order-management/spec.md`).

### Z-2. Brak odczytu stanu płatności u operatora: opłacone zamówienie może zostać anulowane — Medium, RYZYKO

- **Dowód:** port ma tylko trzy operacje, `apps/api/src/Payment/Application/Port/PaymentProvider.php:27-55`:
  ```php
  public function code(): ProviderCode;
  public function createSession(PaymentId $paymentId, Amount $amount, string $orderNumber, …): ProviderSession;
  public function cancelSession(ProviderPaymentId $providerPaymentId): SessionCancellation;
  public function parseWebhook(array $headers, string $rawBody, \DateTimeImmutable $now): ?ProviderEvent;
  ```
  Wygaśnięcie nie pyta Payment ani operatora, `apps/api/src/Ordering/Application/Command/ExpireUnpaidOrderHandler.php:57-72`
  (blokada zamówienia, `reservations->release`, `redemptions->release`). Przeszukanie `apps/api/src/Payment` pod kątem
  uzgadniania (`reconcil`, odczyt statusu) nie zwraca niczego.
- **Warunki:** klient płaci przed terminem, a webhook nie dociera do `paymentDeadline + 5 min` (awaria API albo workera,
  długa kolejka, limit 429 na webhookach, przerwa po stronie operatora). Przy płatności w ostatnich minutach okno to
  około 5 minut. Prawdopodobieństwo: niskie w demo (symulator w tym samym procesie), realne przy prawdziwym operatorze
  i każdej kilkuminutowej niedostępności.
- **Konsekwencja:** klient zapłacił, dostaje e-mail o anulowaniu, towar wraca do sprzedaży; spóźniony webhook oznacza
  płatność „do zwrotu” (`HandleProviderWebhookHandler.php:81-82`), a zwrot jest ręczny. Sprzedawca traci zamówienie
  i ponosi koszt obsługi.
- **Porównanie:** Saleor domyka checkout zadaniem cyklicznym i liczy kwoty oczekujące [SA2]; Medusa po webhooku sama
  domyka koszyk [ME5]; wtyczki Syliusa 2.x czytają stan z API operatora (pośrednio [SY19]).
- **Rekomendacja:** przed integracją prawdziwego operatora dodać do portu odczyt stanu sesji i wywołać go dla oczekującej
  płatności, zanim zamówienie wygaśnie (Payment zna Ordering, więc krok należy do Payment: np. opóźniona komenda
  „potwierdź albo zamknij sesję” tuż przed terminem wygaśnięcia). Dla symulatora nie jest to potrzebne.
- **Pewność:** wysoka co do ścieżki w kodzie; średnia co do częstości. Sprawdziłem, że specyfikacja opisuje skutek
  (płatność po anulowaniu → zwrot ręczny, `payment/spec.md:634-636`), ale nie mechanizm zapobiegania.

### Z-3. Zwroty pieniędzy żyją poza systemem i w dwóch miejscach — Medium, RYZYKO

- **Dowód:** `docs/features/payment/spec.md:634-635`: „…wymagają ręcznego zwrotu (poza zakresem): są oznaczone
  w `payment_payment.refund_required_reason` i w logu, bez ekranu w panelu.” Zwroty z anulowania i zwrotu towaru są
  osobno, jako value object `Refund` w zamówieniu (`docs/features/order-management/spec.md`, „Zwrot pieniędzy (P3)”),
  zawsze na pełną kwotę.
- **Warunki:** każda płatność po terminie, po anulowaniu albo podwójna (rzadkie), oraz każde anulowanie opłaconego
  zamówienia i każdy zwrot towaru (codzienne w działającym sklepie).
- **Konsekwencja:** zwroty wynikające z płatności widzi tylko ktoś, kto czyta logi albo bazę; klient czeka na pieniądze
  bez terminu pilnowanego przez panel. Zwrot z zamówienia nie jest powiązany z transakcją u operatora, więc nie ma
  dowodu wykonania ani ochrony przed podwójnym zwrotem.
- **Porównanie:** Shopware ma encje pobrania i zwrotu z maszynami stanów [SW18], Saleor zdarzenia `REFUND_*` na
  transakcji [SA2], Medusa zwrot z kwotą i kluczem idempotencji [ME4], Sylius oficjalną wtyczkę [SY16].
- **Rekomendacja:** jeden rejestr zwrotów po stronie Payment (kwota, powód, stan, referencja operatora), zasilany przez
  oba źródła, z ekranem w panelu; operacja zwrotu w porcie operatora z kluczem idempotencji. Zgłoszenie #221 obejmuje
  ekran; warto rozszerzyć je o model.
- **Pewność:** wysoka (świadoma decyzja udokumentowana w specyfikacjach).

### Z-4. Zwolnienie rezerwacji zależy od jednej opóźnionej wiadomości — Low, RYZYKO

- **Dowód:** `docs/features/inventory/spec.md:126`: „Rezerwacja nie wygasa. Kończy ją wyłącznie zwolnienie albo
  zatwierdzenie.”; `docs/features/payment/spec.md:651-652`: „`ExpireUnpaidOrder` po wyczerpaniu ponowień trafia do
  `failed`: zamówienie zostaje `new`, a rezerwacja aktywna, aż ktoś ponowi wiadomość”.
- **Warunki:** sześć kolejnych niepowodzeń wiadomości (konflikty wersji, błąd Inventory, awaria bazy w trakcie ponowień)
  albo utrata wiersza kolejki `scheduled` (np. ręczne czyszczenie, seed). Prawdopodobieństwo niskie.
- **Konsekwencja:** towar zablokowany bezterminowo, wariant może pokazywać „niedostępny”, bez alertu.
- **Porównanie:** Sylius i Saleor zamykają nieopłacone zamówienia zadaniem przeglądającym zamówienia po terminie
  [SY18, SA16], więc pominięte zamówienie zostanie zamknięte przy następnym przebiegu.
- **Rekomendacja:** zostawić opóźnioną wiadomość jako ścieżkę główną i dodać tanie polecenie konsolowe uruchamiane
  cyklicznie, które wysyła `ExpireUnpaidOrder` dla zamówień `new` starszych niż termin + margines (indeks po statusie
  i terminie), oraz alert na niepustą kolejkę `failed`.
- **Pewność:** wysoka co do ścieżki; nie sprawdzałem, czy istnieje alert na kolejkę `failed`.

### Z-5. Kod rabatowy bez limitu na klienta — Low, RYZYKO

- **Dowód:** `docs/features/discount-codes/spec.md:42`: „Bez limitu użyć na klienta. Jeden kod na zamówienie…”;
  dane demo zawierają kod powitalny `WITAJ10` (`docs/vision.md:298`).
- **Warunki:** kod typu „na pierwsze zakupy” opublikowany publicznie. Pewne przy pierwszej takiej kampanii.
- **Konsekwencja:** ten sam klient używa kodu przy każdym zamówieniu; marketing może to ograniczyć tylko limitem łącznym.
- **Porównanie:** limit na klienta mają Shopware („how often … per customer”) [SW13] i Saleor („apply once per customer”)
  [SA7]. Zakupy gościa wymagają wtedy decyzji, czym jest „klient” (adres e-mail).
- **Rekomendacja:** dodać opcjonalne „raz na klienta” liczone po koncie albo znormalizowanym e-mailu zamówienia;
  mechanizm użyć (`DiscountCodeUsage` z `orderId`) już to umożliwia.
- **Pewność:** wysoka (świadoma decyzja D2).

### Z-6. Wywołanie HTTP operatora wewnątrz transakcji bazy — Low, RYZYKO

- **Dowód:** `apps/api/src/Payment/Application/Command/StartPaymentHandler.php:97` (`createSession`) i `:128`
  (`cancelSession`) wykonują się w transakcji `command.bus`; `docs/features/payment/spec.md:643-644`: „Przy prawdziwym
  dostawcy do przeniesienia poza transakcję.”
- **Warunki:** operator odpowiada wolno (limit 5 s) przy wielu równoległych rozpoczęciach płatności.
- **Konsekwencja:** połączenia z bazą i blokada wiersza płatności trzymane przez czas odpowiedzi operatora; przy piku
  wyczerpanie puli połączeń dotyka też koszyka i katalogu.
- **Porównanie:** Medusa wynosi wywołania zewnętrzne do kroków workflowu z kompensacją [ME8]; Shopware najpierw tworzy
  zamówienie z otwartą transakcją, a płatność obsługuje osobnym krokiem [SW5].
- **Rekomendacja:** przy integracji prawdziwego operatora: zapis `pending` w krótkiej transakcji, wywołanie poza nią,
  druga krótka transakcja z `attachProviderSession`; klucz idempotencji przekazany operatorowi [BP1].
- **Pewność:** wysoka; świadomie odroczone.

---

## Co przejąć (z priorytetem)

| Priorytet | Co | Skąd | Uwagi |
|---|---|---|---|
| P1 | Dostawa: metody, koszt, przesyłka jako osobny byt ze stanem | Sylius [SY3, SY4], Shopware `order_delivery` [SW6] | Warunek sprzedaży; razem z decyzją z Z-1 |
| P1 | Odczyt stanu u operatora przed anulowaniem nieopłaconego zamówienia | Saleor (zadanie domykające) [SA2], Medusa [ME5] | Z-2; przed pierwszym prawdziwym operatorem |
| P1 | Rejestr zwrotów pieniędzy z referencją operatora i kluczem idempotencji | Shopware ADR [SW18], Medusa [ME4], Saleor [SA2] | Z-3 |
| P2 | Rozdzielenie stanu zamówienia, płatności i wysyłki | Sylius [SY4], Shopware [SW6] | Z-1; tylko jeśli w planie jest pobranie albo częściowość |
| P2 | Zadanie przeglądające nieopłacone zamówienia po terminie | Sylius [SY18], Saleor [SA16] | Z-4; kilkadziesiąt linii kodu |
| P2 | Kod „raz na klienta” | Saleor [SA7], Shopware [SW13] | Z-5 |
| P2 | Dokument sprzedaży generowany przy zamówieniu, niezmienny | Sylius InvoicingPlugin [SY17] | Wymóg prawny przed sprzedażą; zakres do ustalenia z księgowością |
| P3 | Zwroty częściowe z ilością przyjętą i uszkodzoną | Medusa [ME18] | Po rejestrze zwrotów |
| P3 | Minimalna korekta zamówienia przed wysyłką (adres, kontakt) | Medusa `OrderChange` [ME7] | Bez edycji pozycji |
| P3 | Reguła dla nowych testów E2E: wielokrotne uruchomienie przed scaleniem, kwarantanna niestabilnych | Shopware ADR [SW11] | Tani proces |
| P3 | Przebudowa indeksu bez utraty zapisów z czasu przebudowy | praktyka aliasów; Shopware `es:index` przez kolejkę [SW19] | `search/spec.md:410-411` |

## Czego świadomie nie kopiować

- **System pluginów, aplikacji i webhooków wychodzących** (Shopware [SW14], Saleor [SA5]): służy tysiącom wdrożeń; jeden
  sklep integruje się kodem.
- **Izolacja modułów z sagą i kompensacjami** (Medusa [ME8, ME11]): rozwiązuje brak wspólnej transakcji, który tu nie
  występuje. Jedna transakcja jest prostsza i dowodliwie spójna.
- **Silnik reguł promocji i Rule Builder** (Sylius [SY8], Shopware [SW13]): reguły, akcje, priorytety i wykluczenia to
  duża powierzchnia błędów cenowych. Jedna promocja na wariant i jeden kod wystarczą, dopóki marketing nie pokaże
  konkretnej kampanii, której nie da się wyrazić.
- **Kanały, waluty, cenniki, strefy podatkowe, wiele magazynów**: poza zakresem wizji; dodanie „na zapas” komplikuje
  każdy odczyt ceny i stanu.
- **Koszyk jako zamówienie w stanie `cart`** (Sylius [SY3]): miesza tani, często zapisywany byt z rekordem księgowym.
- **GraphQL** (Saleor): przy dwóch własnych frontach i generowanym kliencie REST nie daje korzyści.
- **Ręczne pole najniższej ceny z 30 dni** (Shopware [SW12], Saleor [SA12]): nasze wyliczenie z historii jest lepsze.
- **Ogólny silnik modeli odczytu** (Medusa Index Module, wycofany [ME14]).
- **Blokada optymistyczna stanów** (Sylius [SY12]) i nieblokująca blokada koszyka z lukami (Shopware [SW3]): nasze
  blokady wierszy są mocniejsze.

---

## Mocne strony

- **Brak nadsprzedaży egzekwowany w trzech warstwach**: decyzja w agregacie, `SELECT … FOR UPDATE` w stałej kolejności
  (`DoctrineStockItemRepository.php:54`), `CHECK` w bazie (`docs/features/inventory/spec.md:161`). Mocniejsze niż
  w każdej z czterech platform (temat 4).
- **Idempotencja operacji API z odciskiem żądania** (`docs/features/checkout/spec.md:179-197`), zgodna co do zasady
  z projektem IETF [BP4] i praktyką Stripe [BP1]; w platformach brak odpowiednika poza Medusą.
- **Transactional outbox z potwierdzeniem publikacji** (`messenger.yaml:29-53`), zgodny z wzorcem [BP3]; platformy go
  nie dokumentują, a Saleor wprost ostrzega przed utratą zdarzeń [SA11].
- **Webhook płatności zgodny z zaleceniami operatora** [BP2]: podpis na surowej treści, tolerancja czasu, deduplikacja,
  kontrola kwoty, atomowość z zamówieniem (`docs/features/payment/spec.md:283-311`).
- **Omnibus liczony z historii cen i promocji** (`LowestPriorPrice.php:19-37`), bez ręcznego pola.
- **Migawka zamówienia z niezmiennikami w bazie** (`docs/features/checkout/spec.md:274-292`).
- **Granice modułów egzekwowane narzędziem** (`deptrac.yaml`) i kontrakt API sprawdzany kompilatorem frontów.
- **Zasada zwalniania użycia kodu** identyczna z dojrzałą platformą (Saleor [SA7]).

## Zbędna złożoność

Względem platform, które przy większym zakresie funkcji radzą sobie bez tych warstw:

- **Osobny model persystencji obok agregatów**: 26 klas rekordów i ręczne mapowanie w repozytoriach. Żadna z czterech
  platform tego nie robi (Sylius i Saleor mapują encje wprost, Shopware ma DAL). Wizja sama nazywa to założeniem do
  weryfikacji po szkielecie (`docs/vision.md:379-383`); nie znalazłem śladu, że weryfikacja się odbyła. PREFERENCJA,
  bez wykazanej szkody poza kosztem utrzymania.
- **Powielone value objecty**: `VariantId` i `ProductId` po 5 kopii, `Currency` 4, `Money` 3, `OrderId` 3. Zasada
  „własne VO w każdym contexcie” ma sens dla pojęć o różnym znaczeniu (cena a kwota do obciążenia), ale identyfikator
  wariantu znaczy wszędzie to samo. PREFERENCJA.
- **Wywołania między contextami przez szynę i 23 adaptery**: poprawne, ale przy jednej bazie i jednym procesie to dużo
  ceremonii na synchroniczny odczyt. PREFERENCJA; nie ruszać bez powodu.

## Braki

Względem minimum wspólnego dla czterech platform (temat 10): metody i koszty dostawy; zwrot pieniędzy przez operatora;
zwroty częściowe; dokument sprzedaży; edycja zamówienia; anonimizacja danych zamówień (RODO); limit kodu na klienta;
stany autoryzacji i pobrania płatności; adresy zapisane na koncie klienta. Względem dobrych praktyk: uzgadnianie stanu
płatności, przegląd nieopłaconych zamówień po terminie, potwierdzenie skali testami na katalogu docelowej wielkości.

## Czego nie zweryfikowano

- **Dokumentacja Sylius 2.x**: niedostępna bez logowania; tezy z dokumentacji dotyczą 1.x, kod i ADR-y z gałęzi `2.3`.
  Polecenie anulujące nieopłacone zamówienia i system Payment Request znam tylko z wyników wyszukiwania [SY18, SY19].
- **Tezy oznaczone (NZ)** w tabelach, w szczególności: brak outboxa i kluczy idempotencji w rdzeniu Syliusa i Shopware,
  edycja zamówień przez wersjonowanie w Shopware, role administratorów i RMA w poszczególnych edycjach, brak Omnibusa
  w Medusie, blokady w bazie Saleora, idempotencja `checkoutComplete`.
- **Wytyczne Komisji Europejskiej do art. 6a dyrektywy o cenach** (czy ogólnodostępny kod rabatowy jest „ogłoszeniem
  o obniżce” wymagającym ceny Omnibus): strona EUR-Lex nie zwróciła treści [BP5]. Warto to sprawdzić z prawnikiem,
  bo kody działają u nas bez linii Omnibus.
- **Zgodność zaokrągleń VAT z polską praktyką fakturowania** (VAT od sumy brutto w stawce a suma VAT linii): poza
  zakresem researchu; istotne przy dokumencie sprzedaży.
- **Wydajność**: żadnego porównania pomiarów; platformy nie publikują w dokumentacji liczb dla 10 mln produktów, a nasz
  sklep nie ma testów w tej skali.
- **Jakość testów** naszych i platform: porównałem rodzaje i liczby plików, nie treść.
- Stan faktyczny działania (niczego nie uruchamiałem) oraz to, czy istnieje alert na kolejkę `failed`.

---

## Źródła

Wszystkie pobrane 18.11.2026, o ile nie zaznaczono inaczej.

**Sylius**
- [SY1] https://github.com/Sylius/Sylius (README, gałąź `2.3`)
- [SY2] https://old-docs.sylius.com/en/latest/_sources/book/architecture/architecture.rst.txt (dokumentacja 1.x)
- [SY3] https://old-docs.sylius.com/en/latest/_sources/book/orders/orders.rst.txt (1.x)
- [SY4] https://old-docs.sylius.com/en/latest/_sources/book/orders/checkout.rst.txt (1.x)
- [SY5] https://old-docs.sylius.com/en/latest/_sources/book/products/inventory.rst.txt (1.x)
- [SY6] https://old-docs.sylius.com/en/latest/_sources/book/orders/payments.rst.txt (1.x)
- [SY7] https://old-docs.sylius.com/en/latest/_sources/book/products/pricing.rst.txt (1.x)
- [SY8] https://old-docs.sylius.com/en/latest/_sources/book/orders/cart-promotions.rst.txt (1.x)
- [SY9] https://old-docs.sylius.com/en/latest/_sources/book/orders/taxation.rst.txt (1.x)
- [SY10] https://github.com/Sylius/Sylius/blob/2.1/UPGRADE-2.0.md (pierwsze 100 tys. znaków)
- [SY11] https://github.com/Sylius/Sylius/tree/2.3/adr
- [SY12] https://github.com/Sylius/Sylius/blob/2.3/src/Sylius/Bundle/CoreBundle/Doctrine/ORM/Inventory/Operator/OrderInventoryOperator.php
- [SY13] https://github.com/Sylius/Sylius/blob/2.3/src/Sylius/Component/Core/Model/ChannelPricing.php
- [SY14] https://github.com/Sylius/Sylius/blob/2.3/adr/2025_04_07_transition_from_phpspec_to_phpunit.md
- [SY15] https://github.com/Sylius/Sylius/blob/2.3/adr/2022_03_24_remove_flush_from_handlers.md
- [SY16] https://github.com/Sylius/RefundPlugin
- [SY17] https://github.com/Sylius/InvoicingPlugin
- [SY18] https://old-docs.sylius.com/en/1.13/cookbook/deployment/cron-jobs.html (tylko fragment z wyników wyszukiwania)
- [SY19] https://docs.sylius.com/the-customization-guide/customizing-payments/how-to-integrate-a-payment-gateway-as-a-plugin
  i https://github.com/Sylius/Sylius/issues/16237 (tylko fragmenty z wyników wyszukiwania)

**Shopware 6**
- [SW1] https://developer.shopware.com/docs/concepts/framework/architecture/
- [SW2] https://developer.shopware.com/docs/concepts/commerce/checkout-concept/cart.html
- [SW3] https://developer.shopware.com/docs/concepts/commerce/checkout-concept/cart-concurrency.html
- [SW4] https://developer.shopware.com/docs/concepts/commerce/checkout-concept/orders.html
- [SW5] https://developer.shopware.com/docs/concepts/commerce/checkout-concept/payments.html
- [SW6] https://developer.shopware.com/docs/guides/plugins/plugins/checkout/order/using-the-state-machine.html
- [SW7] https://github.com/shopware/shopware/tree/trunk/adr
- [SW8] https://github.com/shopware/shopware/blob/trunk/adr/2023-05-15-stock-api.md
- [SW9] https://developer.shopware.com/docs/guides/plugins/plugins/content/stock/
- [SW10] https://developer.shopware.com/docs/concepts/framework/http_cache.html
- [SW11] https://github.com/shopware/shopware/blob/trunk/adr/2023-02-13-follow-test-pyramid.md
- [SW12] https://docs.shopware.com/en/shopware-6-en/catalogues/products
- [SW13] https://docs.shopware.com/en/shopware-6-en/marketing/promotions
- [SW14] https://developer.shopware.com/docs/concepts/extensions/apps-concept.html
- [SW15] https://developer.shopware.com/docs/guides/hosting/performance/performance-tweaks.html
- [SW16] https://developer.shopware.com/docs/concepts/api/store-api.html
- [SW17] https://developer.shopware.com/docs/concepts/framework/data-abstraction-layer.html
- [SW18] https://github.com/shopware/shopware/blob/trunk/adr/2021-10-13-refund-handling.md
- [SW19] https://developer.shopware.com/docs/guides/hosting/infrastructure/elasticsearch/elasticsearch-setup.html

**Medusa v2**
- [ME1] https://docs.medusajs.com/learn/introduction/architecture
- [ME2] https://docs.medusajs.com/resources/commerce-modules/inventory/concepts
- [ME3] https://docs.medusajs.com/resources/commerce-modules/inventory/inventory-in-flows
- [ME4] https://docs.medusajs.com/resources/commerce-modules/payment/payment-flow
- [ME5] https://docs.medusajs.com/resources/commerce-modules/payment/webhook-events
- [ME6] https://docs.medusajs.com/resources/commerce-modules/order/concepts
- [ME7] https://docs.medusajs.com/resources/commerce-modules/order/order-change
- [ME8] https://docs.medusajs.com/learn/fundamentals/workflows/compensation-function
- [ME9] https://docs.medusajs.com/resources/infrastructure-modules/locking
- [ME10] https://docs.medusajs.com/resources/references/medusa-workflows/completeCartWorkflow
- [ME11] https://docs.medusajs.com/learn/fundamentals/modules/isolation
- [ME12] https://docs.medusajs.com/resources/commerce-modules/pricing/concepts
- [ME13] https://docs.medusajs.com/resources/commerce-modules/promotion/concepts
- [ME14] https://docs.medusajs.com/learn/fundamentals/module-links/index-module
- [ME15] https://docs.medusajs.com/learn/debugging-and-testing/testing-tools
- [ME16] https://docs.medusajs.com/learn/fundamentals/events-and-subscribers
- [ME17] https://docs.medusajs.com/resources/commerce-modules/pricing/tax-inclusive-pricing
- [ME18] https://docs.medusajs.com/resources/commerce-modules/order/return

**Saleor**
- [SA1] https://docs.saleor.io/developer/stock/overview
- [SA2] https://docs.saleor.io/developer/payments/transactions
- [SA3] https://docs.saleor.io/developer/checkout/overview
- [SA4] https://docs.saleor.io/developer/order/order-status
- [SA5] https://docs.saleor.io/developer/extending/overview
- [SA6] https://docs.saleor.io/developer/discounts/promotions
- [SA7] https://docs.saleor.io/developer/discounts/vouchers
- [SA8] https://docs.saleor.io/developer/taxes
- [SA9] https://docs.saleor.io/developer/checkout/stock
- [SA10] https://docs.saleor.io/developer/stock/stock-reservation
- [SA11] https://docs.saleor.io/developer/extending/webhooks/asynchronous-events
- [SA12] https://docs.saleor.io/developer/discounts/displaying-catalogue-discounts
- [SA13] https://docs.saleor.io/developer/checkout/lifecycle
- [SA14] https://docs.saleor.io/developer/extending/webhooks/overview
- [SA15] https://github.com/saleor/saleor (README)
- [SA16] https://docs.saleor.io/developer/order/order-expiration
- [SA17] https://docs.saleor.io/overview/architecture
- [SA18] https://docs.saleor.io/api-usage/search
- [SA19] https://docs.saleor.io/developer/payments/overview

**Dobre praktyki**
- [BP1] https://docs.stripe.com/api/idempotent_requests
- [BP2] https://docs.stripe.com/webhooks (sekcje „Event delivery behaviors” i „Best practices for using webhooks”)
- [BP3] https://microservices.io/patterns/data/transactional-outbox.html
- [BP4] https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/ (wersja 08, 22.10.2026)
- [BP5] https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:52021XC1229(06) (**nie udało się pobrać treści**)

Adresy, które zwróciły 404 albo przekierowanie na logowanie i nie zostały użyte: `docs.sylius.com/the-book/*`
(logowanie), `old-docs.sylius.com/.../price_history.rst.txt`, `.../order_cancellation.rst.txt`,
`docs.saleor.io/developer/stock/lifecycle`, `.../checkout/finalizing`, `.../products/search`,
`.../checkout/order-creation`, `github.com/Sylius/Sylius/tree/2.2/docs`.
