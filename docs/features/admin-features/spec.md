# ADMIN-FEATURES: pulpit biznesowy, kategorie, klienci i brakujące funkcje panelu (część 1)

Panel ma funkcje, które ma każdy dojrzały panel e-commerce: pulpit z kolejkami i sprzedażą, cenę i stan w karcie
produktu, sortowanie i liczniki list, zarządzanie kategoriami i klientów. Każda liczba jest liczona w API, panel tylko
wyświetla. Wygląd ekranów według standardów listy i formularza z `apps/admin/CLAUDE.md` („Wygląd”).

To część 1 issue #461 (jego punkty 1–5 i poprawka #408). Część 2, ADMIN-TOOLS (punkty 6–9: notatki wewnętrzne
i zwroty płatności, akcje masowe i eksport, globalna wyszukiwarka, dziennik zmian), ma osobne issue (#586)
i osobną specyfikację; ta część nie zależy od niczego z części 2.

Issues: #461, #408
Zakres: `apps/api/src/Backoffice`, `apps/api/src/Catalog`, `apps/api/src/Ordering`, `apps/api/src/Inventory`, `apps/api/src/Pricing`, `apps/api/src/Shared`, `apps/api/tests/Unit/Backoffice`, `apps/api/tests/Unit/Catalog`, `apps/api/tests/Unit/Ordering`, `apps/api/tests/Unit/Inventory`, `apps/api/tests/Unit/Pricing`, `apps/api/tests/Unit/Shared`, `apps/api/tests/Integration/Backoffice`, `apps/api/tests/Integration/Catalog`, `apps/api/tests/Integration/Ordering`, `apps/api/tests/Integration/Inventory`, `apps/api/tests/Integration/Pricing`, `apps/api/tests/Integration/Shared`, `apps/api/tests/Functional/Backoffice`, `apps/api/tests/Functional/Catalog`, `apps/api/tests/Functional/Ordering`, `apps/api/tests/Functional/Inventory`, `apps/api/tests/Functional/Pricing`, `apps/api/tests/Functional/Shared`, `apps/api/tests/Support`, `apps/api/tests/Factory`, `apps/api/tests/Unit/Bin`, `apps/api/config/packages/security.yaml`, `apps/api/config/packages/messenger.yaml`, `apps/api/bin/seed-demo`, `apps/api/deptrac.yaml`, `apps/api/openapi.json`, `apps/api/openapi-admin.json`, `apps/api/CLAUDE.md`, `apps/admin/src`, `apps/admin/tests`, `apps/admin/CLAUDE.md`, `apps/store/src/api/schema.d.ts`, `apps/store/src/catalog`, `apps/store/app/c`, `apps/store/tests`, `apps/store/CLAUDE.md`, `infra/varnish`, `infra/docker-compose.yml`, `infra/k8s/charts/shop`, `docs/vision.md`, `docs/adr/admin-ui-library.md`, `docs/features/admin-design/spec.md`, `docs/features/catalog-admin/spec.md`, `docs/features/inventory/spec.md`, `docs/features/pricing/spec.md`, `docs/features/order-management/spec.md`, `docs/features/demo-data/spec.md`, `e2e/tests/staff-dashboard.spec.ts`, `e2e/tests/admin-design.spec.ts`, `e2e/tests/admin-screens-inventory.spec.ts`, `e2e/tests/admin-screens-orders.spec.ts`, `e2e/tests/admin-screens-prices.spec.ts`, `e2e/tests/price-change.spec.ts`, `e2e/tests/stock-receipt.spec.ts`, `e2e/tests/catalog-admin.spec.ts`, `e2e/support`, `e2e/CLAUDE.md`, `infra/k8s/CLAUDE.md`

## Cel i zakres

Pracownik zaczyna dzień od pulpitu, który mówi, ile jest do zrobienia (kolejki z liczbami) i jak idzie sprzedaż,
a każdy kafel prowadzi do przefiltrowanej listy. W karcie produktu widzi i zmienia ceny i stany wariantów. Listy da
się sortować, widać liczbę wyników, a zamówienia da się znaleźć po fragmencie numeru, e-maila albo nazwiska i po
zakresie dat. Panel zarządza drzewem kategorii (ze zmianą adresów w sklepie i przekierowaniami) i pokazuje klientów
z historią zamówień.

Sekcje 1–5 to punkty 1–5 issue #461 w kolejności priorytetu właściciela. Poprzedza je sekcja 0, poprawka uprawnień
odczytu panelu (#408), na której stoją moduł klientów i nowe odczyty.

Skala (`docs/vision.md`: 10 mln produktów; profil `full` danych demo: 50 mln zamówień, 1 mln klientów): żadne
zapytanie żądania nie liczy `count(*)` po całej tabeli ani nie sumuje zamówień, nic nie ładuje całej tabeli do
pamięci.

### Wdrożenie: schemat w migracjach, wypełnienie po rolloucie

- Migracje tej części zmieniają tylko schemat: nowe tabele, kolumny z wartościami domyślnymi albo `NULL`, indeksy.
  Indeksy na dużych tabelach (`ordering_order`, `catalog_product`, `inventory_stock_item`, a także indeksy
  wyszukiwania `ordering_customer`) są tworzone w migracjach nietransakcyjnych przez `CREATE INDEX CONCURRENTLY IF NOT
  EXISTS`, poprzedzone `DROP INDEX CONCURRENTLY IF EXISTS` indeksu o tej nazwie w stanie `INVALID` (po nieudanej
  próbie), więc ponowienie Joba `migrate` przechodzi. Zasada jest w `apps/api/CLAUDE.md`. Migracje nie wołają kodu
  aplikacji i nie aktualizują dużych tabel.
- Pierwsze wypełnienie danych (rekordy stanów z `product_id`, szacunek liczby produktów, modele odczytu sprzedaży
  i klientów) robi komenda `app:backfill` (Shared UI), uruchamiana **po rolloucie** `api` i obu workerów (`worker`, `worker-commands`): w klastrze Job
  `backfill` w fali po `waitRollout` (jak `purge-caches`; własny limit czasu `jobs.backfillActiveDeadlineSeconds`,
  21 600 s), lokalnie na końcu `bin/seed-demo` (z `--force`). Komenda uruchamia po kolei zarejestrowane kroki
  (`BackfillStep`, kolejność z priorytetu): `inventory:stock-records:sync`, `catalog:analyze-products`
  (`ANALYZE catalog_product`), `ordering:read-models:rebuild:sales` i `ordering:read-models:rebuild:customers`. Każdy
  krok jest idempotentny, działa partiami w osobnych transakcjach, a po zakończeniu zapisuje się w `app_backfill (name
  PK, completed_at)`, więc kolejne wdrożenia go pomijają (opcja `--force` liczy od nowa). Do końca wypełnienia pulpit
  i lista klientów mogą pokazywać niepełne dane (okno wdrożenia, zapisane w ryzykach).
- Wypełnienie jest poprawne przy ruchu: przebudowa każdej godziny sprzedaży i każdej partii klientów zakłada brakujące
  wiersze docelowe, blokuje je (`SELECT … FOR UPDATE`), dopiero potem liczy wartości z zamówień i zapisuje je w tej samej
  transakcji. Równoległy `MarkOrderPaid` albo `PlaceOrder` czeka na blokadę: jego zamówienie, niezatwierdzone w chwili
  liczenia, dolicza potem przyrostowo, a zatwierdzone wcześniej przebudowa liczy sama i nadpisuje wiersz, więc żadne
  zamówienie nie jest pominięte ani policzone dwa razy (test współbieżności z dwoma procesami).

## Model zagrożeń

**Aktywa i strony.** Dane osobowe klientów (kontakt, adresy, historia i wartość zamówień), dane sprzedaży,
integralność katalogu i drzewa kategorii. Chronimy je przed kontem `backoffice` bez roli personelu, pracownikiem
z rolą bez prawa do danej funkcji, klientem sklepu (token `shop`) i anonimowym klientem HTTP.

**Drogi ataku w zakresie**, każda z kryterium i sprawdzeniem:

| Droga | Zamyka | Sprawdzenie |
|---|---|---|
| Konto `backoffice` bez roli czyta dane panelu | sekcja 0: `ROLE_STAFF` tylko z rolą personelu | test funkcjonalny po wszystkich trasach `/api/v1/admin/*` z routera (bez roli 403, token klienta i brak tokenu 401) |
| Nowa trasa panelu bez ochrony | ten sam test (trasy z routera) | nowa trasa bez `ROLE_STAFF` łamie test bez jego zmiany |
| Rola bez prawa czyta klientów albo sprzedaż | votery `VIEW_CUSTOMERS`, `VIEW_SALES` | test funkcjonalny każdej nowej trasy dla każdej roli |
| Pulpit ujawnia kolejkę albo sprzedaż roli bez dostępu | pola `null` według voterów | test pulpitu dla każdej roli |
| Zmiana drzewa przez rolę bez prawa | `MANAGE_CATEGORIES` przed treścią | test 403 dla `support` i `warehouse` |
| Publiczne `categories/redirect` ujawnia usunięte kategorie | przekierowania usuwane z kategorią (FK `ON DELETE CASCADE`) | test funkcjonalny 404 po usunięciu |
| Odpowiedzi panelu w cache pośrednim | `private, no-store` na każdej nowej trasie panelu | test nagłówków |

**Poza zakresem.** Przejęte konto pracownika z rolą (widzi dane jak każdy pracownik tej roli); RODO: usunięcie konta
klienta i anonimizacja `ordering_customer` i zamówień (osobne zgłoszenie razem z #119).

## 0. Uprawnienia odczytu panelu (#408)

Token realmu `backoffice` bez żadnej roli personelu czyta w panelu tylko, kim jest (`GET /admin/me`); każda inna trasa
`/api/v1/admin` odpowiada mu 403, także odczyty bez własnego `#[IsGranted]` (np. `/dashboard`, `/categories`, `/stock`,
`/prices`, `/promotions`, `/discount-codes` i historie wariantów), bo ochrona jest w `security.yaml`, a nie w atrybutach
tras.

- `StaffUser::getRoles()` daje `ROLE_STAFF` tylko kontu z co najmniej jedną rolą personelu (`admin`, `support`,
  `warehouse`, `marketing`). `security.yaml`: `GET` i `HEAD` `/api/v1/admin/me` wymagają tylko uwierzytelnienia
  (`IS_AUTHENTICATED`), reszta `/api/v1/admin` `ROLE_STAFF`, więc konto bez roli dostaje 403 wszędzie poza
  `/admin/me`, a nowa trasa panelu jest chroniona domyślnie.
- `VIEW_PRODUCTS` (`ProductVoter`) wymaga roli personelu.
- Test funkcjonalny przechodzi wszystkie trasy `/api/v1/admin/*` z routera (każda metoda, parametry ścieżki wypełnione
  losowym UUID): token bez roli → 403 (poza `/admin/me`), token klienta i brak tokenu → 401.
- Panel: `/me` z pustą listą ról pokazuje na każdej trasie (także po wejściu z linku, np. `/products`) „Twoje konto
  nie ma roli w panelu. Skontaktuj się z administratorem.” zamiast menu i treści (powłoka, link „Wyloguj” zostaje).

## 1. Pulpit: kolejki i sprzedaż

`GET /api/v1/admin/dashboard` ma obiekty `queues` i `sales` obok `catalog` i `system`. `sales` jest `null` bez
`VIEW_SALES`; w `queues` każda kolejka jest licznikiem ograniczonym albo `null`, gdy rola jej nie widzi.

### Kolejki (`queues`)

| Kolejka (pole) | Definicja | Rola (voter) | Link w panelu |
|---|---|---|---|
| Opłacone do wysłania (`paidToShip`) | zamówienia `paid` | `VIEW_ORDERS` | `/orders?status=paid` |
| Czekają na płatność (`awaitingPayment`) | zamówienia `new` | `VIEW_PENDING_PAYMENTS` (`admin`, `support`; `OrderVoter`) | `/orders?status=new` |
| Zwroty do wykonania (`pendingRefunds`) | zwroty Ordering bez wykonania | `COMPLETE_REFUND` | `/orders?refund=pending` |
| Zwroty po terminie (`overdueRefunds`) | j.w. z `refund_due_at` < teraz | `COMPLETE_REFUND` | `/orders?refund=overdue` |
| Warianty bez stanu (`outOfStockVariants`) | warianty z dostępnym = 0 | `MANAGE_STOCK` | `/inventory?level=out_of_stock` |
| Warianty z niskim stanem (`lowStockVariants`) | dostępne 1–5 (`AvailabilityLevel::LOW_STOCK_THRESHOLD`) | `MANAGE_STOCK` | `/inventory?level=low_stock` |
| Produkty bez zdjęć (`productsWithoutImages`) | `catalog_product.images = '[]'` | `MANAGE_PRODUCTS` | `/products?images=none` |

- Każda liczba to **licznik ograniczony** `{value, capped}` (`Shared\Domain\BoundedCount`, w OpenAPI wspólny schemat
  `BoundedCountResponse`; `BoundedCounter`): API liczy najwyżej do 1000 (`SELECT count(*) FROM (SELECT 1 … LIMIT
  1001)`), powyżej `{value: 1000, capped: true}`, panel „1000+”. Zapytanie każdej kolejki zawęża skan indeksem do
  wierszy kolejki: zamówienia istniejącym `(status, placed_at DESC, id DESC)` i częściowym indeksem zwrotów, stany
  indeksami częściowymi z „Rekordu stanu każdego wariantu”, produkty bez zdjęć indeksem częściowym `catalog_product
  (id) WHERE images = '[]'::jsonb`.
- **Liczba produktów** (`catalog.productCount`) nigdy nie jest `count(*)`: to szacunek z `pg_class.reltuples`
  (`{value, estimated: true}`; panel „ok. 10 mln”, `KpiTile` dostaje oznaczenie wartości szacunkowej). Statystyki
  aktualizuje autovacuum, a po masowych zmianach jawne `ANALYZE catalog_product`: na końcu `catalog:seed-demo`
  i generatora danych demo oraz jako krok `app:backfill` (`catalog:analyze-products`). Szacunek niedostępny
  (`reltuples` < 0: tabela jeszcze nie analizowana) daje `productCount: null`; panel pokazuje w kaflu „—” z opisem
  „Liczba produktów będzie dostępna po aktualizacji statystyk bazy.”, a pulpit działa dalej. `categoryCount` jest
  dokładny (mała tabela). Produkty bez zdjęć liczy kolejka `queues.productsWithoutImages`, nie sekcja `catalog`.
- `system.searchIndex` porównuje dokładny `_count` ES z szacunkiem: `inSync` = różnica najwyżej 1% szacunku; bez
  szacunku `inSync: null` (ekran „Stan systemu”: „Nie można porównać: brak statystyk bazy.”).
- Kafel „Kategorie” prowadzi do `/categories` (sekcja 4), „Bez zdjęć” do `/products?images=none`.
- Karta „Do obsługi” (`TodoCard`) ma wiersz każdej kolejki z jej liczbą (także zwroty po terminie, niski stan i bez
  zdjęć) oraz wiersze bez kolejki (aktywne promocje, kody rabatowe). Wiersz z 0 zostaje z „0”. Rola z kolejką `null`
  nie widzi wiersza.

### Rekord stanu każdego wariantu (decyzja A9)

Kolejki i filtr poziomu stanu liczą się w Inventory, bez złączeń z tabelami Catalog:

- Catalog publikuje integracyjne zdarzenia `ProductCreated` i `ProductChanged` (`Catalog\Application\Event`,
  tłumaczone z domenowych przez `IntegrationEvents`); odbiorcy, np. `IndexProduct` i konsument Inventory, słuchają
  integracyjnych.
- Każdy wariant ma rekord `inventory_stock_item`: konsument integracyjnych `ProductCreated` i `ProductChanged`
  w Inventory czyta warianty produktu (`ListProductVariants` przez istniejący port Inventory → Catalog) i zakłada
  brakujące rekordy 0/0 z `product_id` przez `INSERT … ON CONFLICT (variant_id) DO UPDATE SET product_id =
  excluded.product_id WHERE inventory_stock_item.product_id IS NULL` (idempotentny; uzupełnia rekord założony
  wcześniej przez przyjęcie albo korektę, `lockOrCreate`, które `product_id` nie znają). Rekordy produktu, których
  wariantu już nie ma, usuwa `DELETE … WHERE product_id = ANY(:products) AND variant_id <> ALL(:variants) AND
  physical = 0 AND reserved = 0` (rekord ze stanem, który pojawił się równolegle z usunięciem wariantu, zostaje;
  historia ruchów zostaje zawsze). Do czasu obsłużenia zdarzenia wariant bez rekordu pokazuje się bez stanu
  (`stock: null`), a rekord bez `product_id` nie należy do filtra poziomu ani kolejek.
- Kolumna `inventory_stock_item.product_id` dopuszcza `NULL`. `inventory:stock-records:sync` (krok `app:backfill`,
  partiami po 100 produktów z Catalog przez port) zakłada rekordy 0/0 dla istniejących wariantów bez rekordu
  i uzupełnia `product_id`; `inventory:seed-demo` zakłada rekord z `product_id` każdemu wariantowi.
- Indeksy częściowe na wyrażeniu `physical - reserved`: `(product_id) WHERE (physical - reserved) = 0`
  (`inventory_stock_item_out_of_stock_idx`) i `(product_id) WHERE (physical - reserved) BETWEEN 1 AND 5`
  (`inventory_stock_item_low_stock_idx`); próg w predykacie to stała z `AvailabilityLevel`, a test schematu
  (`InventorySchemaTest`) pilnuje zgodności migracji ze stałą.

### Sprzedaż (`sales`, decyzje A2, P1, P2, P5)

- **Tylko zakończone godziny.** Każdy okres kończy się na początku bieżącej godziny („Dane do 14:00”), a okres
  porównawczy ma tę samą długość w godzinach zegara `Europe/Warsaw`:
  - „Dziś”: od północy do początku bieżącej godziny, porównane z wczoraj od północy do tej samej godziny. Między 0:00
    a 1:00 okres jest pusty: miary `null`, panel „Pierwsze dane po 1:00”.
  - „7 dni”: ostatnie 168 zakończonych godzin, porównane ze 168 godzinami wcześniej; „30 dni”: 720 godzin
    i 720 wcześniej (okresy kroczące, równe długością także przy zmianie czasu).
  - W dniu zmiany czasu „Dziś” i „wczoraj” obejmują te same godziny zegara, więc mogą różnić się o godzinę rzeczywistą;
    to jedyna nierówność, zapisana w opisie kafla („wg godzin zegara”).
- Miary okresu (`today`, `last7Days`, `last30Days` obok `dataUntil` i `currency`): przychód brutto (`revenue`), liczba
  zamówień (`orderCount`), średnia wartość (`averageOrderValue`: przychód / liczba, zaokrąglenie do grosza w API, bez
  zamówień `null`) i zmiana przychodu względem okresu porównawczego w punktach bazowych (`changeBasisPoints`, `null`
  przy zerowym okresie porównawczym). Przychód = suma wartości towarów po rabatach, bez kosztu dostawy
  (`total_gross - delivery_gross`, `docs/features/delivery/spec.md`, P14), zamówień opłaconych w okresie wg `paid_at`;
  późniejsze anulowanie przez pracownika i zwrot nie zmniejszają przychodu (sprzedaż brutto), zwroty są osobnymi
  kolejkami.
- **Wstępna agregacja** w Ordering: `ordering_sales_hour (hour_start timestamptz, shard smallint, order_count int,
  gross bigint, PK (hour_start, shard))`, godzina w UTC (godziny Warszawy to pełne godziny UTC), `shard` = jeden z 16
  kubełków z identyfikatora zamówienia, żeby równoległe płatności jednej godziny nie czekały na jeden wiersz (blokada
  trwa do końca transakcji webhooka). `MarkOrderPaid` w swojej transakcji robi DBAL `INSERT … ON CONFLICT
  (hour_start, shard) DO UPDATE SET order_count = order_count + 1, gross = gross + :gross` (`:gross` = wartość towarów
  bez dostawy, `OrderTotals::goodsGross()`; przebudowa sumuje `total_gross - delivery_gross`) tylko przy wyniku `paid`
  (`alreadyPaid` i odmowy nic nie dopisują), więc każde zamówienie liczy się raz, a wycofany webhook wycofuje też
  licznik. Jawny wyjątek od persystencji przez rekordy ORM, zapisany w `apps/api/CLAUDE.md` (jak
  `DbalOrderNotifications`). Odczyt sumuje najwyżej 2 × 720 godzin × 16 wierszy. Przebudowę godzin wspiera częściowy
  indeks `ordering_order (paid_at) WHERE paid_at IS NOT NULL`.
- `ordering:read-models:rebuild` (komenda Ordering, idempotentna) przebudowuje `ordering_sales_hour` godzina po
  godzinie i `ordering_customer` partiami klientów z zamówień, z blokadą wierszy docelowych przed liczeniem (sekcja
  „Wdrożenie”); jej dwie części to kroki `app:backfill` (pierwsze wypełnienie po rolloucie; `bin/seed-demo` woła je
  na końcu, po `ordering:seed-demo`), a generator danych demo woła ją po wstawieniu zamówień.
- Panel: trzy kafle `KpiTile` (przychód; w stopce liczba zamówień i średnia; etykieta zmiany „+12,5% vs wczoraj”
  albo „vs poprzednie 7 dni”, ton `success` / `danger`, bez porównania „brak danych do porównania”) i podpis „Dane do
  <godzina>”, tylko przy `sales`.

### Granica modułów pulpitu (decyzja A1)

`GetDashboard` (Backoffice) składa sekcje przez porty Backoffice (`CatalogStatistics`, `OrderQueues`,
`SalesSummary`, `StockQueues`) z adapterami na `query.bus` do zapytań kontekstów właścicieli (`GetOrderQueues`,
`GetSalesSummary` w Ordering, `GetStockQueues` w Inventory, `GetCatalogStatistics` w Catalog). Deptrac ma krawędzie
`Backoffice_Infrastructure → Ordering_Application, Inventory_Application`. Uprawnienie każdej sekcji sprawdza voter jej
kontekstu; kontroler pulpitu pyta `isGranted` i przekazuje do zapytania, które sekcje czytać (jak `includeSystem`).
Kolejkę zwrotów płatności z części 2 można dodać tym samym wzorcem.

### Filtry list z pulpitu

- `GET /admin/stock?level=out_of_stock|low_stock`: produkty z co najmniej jednym wariantem na tym poziomie,
  stronicowane przez Inventory po `product_id` (kursor keyset własny dla `level`, kolejność identyfikatorów
  produktów) z indeksów częściowych; nazwy produktów i etykiety wariantów strony podaje Catalog
  (`ListProductVariants` przez port). `level` wyklucza `q`, `category`, `sort` i `productId` (400). Wiersze
  strony pokazują wszystkie warianty produktu, a pasujące mają `matchesLevel: true`.
- `GET /admin/products?images=none`: produkty bez zdjęć (indeks częściowy), łączy się z `q`, `category`, `status`.
- `GET /admin/orders?refund=overdue`: zwroty po terminie (podzbiór `refund=pending`, ten sam sort i kursor).
  `refund=pending|overdue` łączy się ze `status` i `q`, a odrzuca `placedFrom`, `placedTo` i `customerId` (400).
- Panel: filtr „Poziom” (`Select`: Wszystkie, Bez stanu, Niski stan) na liście stanów (wybór poziomu czyści i wyłącza
  wyszukiwanie i kategorię, z opisem „Filtr poziomu nie łączy się z wyszukiwaniem ani kategorią.”), „Zdjęcia”
  (Wszystkie, Bez zdjęć) na liście produktów, przełącznik „Tylko po terminie” przy zwrotach; wszystkie w adresie
  (`useListUrlState`).

## 2. Cena i stan w karcie produktu

- `GET /admin/prices?productId=<uuid>` i `GET /admin/stock?productId=<uuid>`: jeden produkt (także szkic) z wariantami,
  `next: null`; nieznany produkt to pusta lista. `productId` wyklucza `q`, `category`, `level`, `sort` i `after` (400).
- Formularz istniejącego produktu ma kartę „Ceny i stany” (`FormSection` pod „Warianty”): wiersz na zapisany wariant
  (etykieta, brutto, netto i VAT jak na liście cen, fizycznie, zarezerwowane, dostępne, poziom) z akcjami z list cen
  i stanów („Zmień cenę” dla `admin`; „Przyjęcie” i „Korekta” dla `admin` i `warehouse`; „Historia ceny” i „Historia
  stanu” dla każdego). Okna i ich zasady (wersje, `Idempotency-Key`, 409, fokus, toasty) są te same na listach
  i w karcie: to wspólne komponenty `PriceDialogs.vue` i `StockDialogs.vue` w `src/prices/` i `src/inventory/`. Zapis
  w oknie nie zmienia `dirty` formularza produktu ani jego pól.
- Karta pokazuje tylko warianty zapisane w API; dodany, niezapisany wiersz edytora wariantów: „Zapisz produkt, żeby
  ustawić cenę i stan.”. Po zapisie produktu karta odczytuje ceny i stany od nowa.
- Wariant bez ceny (`price: null` z API) ma `Badge` „Bez ceny”, a karta, gdy ma taki wariant, `Alert` (`warning`)
  „Warianty bez ceny są w sklepie niedostępne.”. Toast po utworzeniu produktu: „Produkt zapisany. Ustaw cenę, inaczej
  produkt będzie w sklepie niedostępny.”.
- Nowy produkt (`/products/new`) nie ma karty; pojawia się po pierwszym zapisie.

## 3. Sortowanie, liczniki i wyszukiwanie list

### Sortowanie (decyzje A10, P6)

Parametr `sort=<klucz>` z zamkniętą listą na endpoint; każdy klucz ma indeks i kursor keyset z kluczem sortu w treści
(kursor innego sortu to 400, jak `BackofficeOrderCursor`).

| Lista | Klucze (`-` = malejąco) | Domyślny | Indeksy |
|---|---|---|---|
| Produkty, ceny, stany | `name`, `-name`, `-updatedAt`, `updatedAt` | `name` | `(name, id)` i częściowy szkiców; `(updated_at, id)` i częściowy szkiców |
| Zamówienia | `-placedAt`, `placedAt`, `-total`, `total` | `-placedAt` | `(placed_at, id)`, `(status, placed_at, id)`, `(total_gross, id)`, `(status, total_gross, id)` |
| Klienci | `-lastOrderAt`, `name`, `-orderCount`, `-paidGross` | `-lastOrderAt` | sekcja 5 |
| Promocje, kody | bez zmian | — | — |

- `refund=pending|overdue` ma własny sort (`refund_due_at`) i odrzuca `sort` (400). `sort=total` i `-total` odrzucają
  `q`, `placedFrom` i `placedTo` (400): żaden indeks nie wspiera kwoty z frazą albo zakresem dat.
- Panel: `Select` „Sortuj” w `ListToolbar` (działa przy kartach zamówień poniżej 640 px i przy wierszach grup cen
  i stanów), polskie etykiety („Nazwa A–Z”, „Ostatnio zmienione”, „Najnowsze”, „Najwyższa kwota”…), stan w adresie
  (`sort`), zmiana wraca na pierwszą stronę; opcje niedozwolone przy bieżących filtrach są wyłączone z opisem. Kolumna
  sortu ma `aria-sort` na `<th>`, gdy jest widoczna. Bez `@tanstack/vue-table`.

### Liczniki wyników (decyzje A3, P7)

- Listy produktów, cen, stanów, zamówień, klientów, promocji i kodów zwracają przy pierwszej stronie (bez `after`)
  `total: BoundedCountResponse` (`{value, capped}`, schemat OpenAPI) z limitem 10 000; kolejne strony `total: null`,
  a panel trzyma licznik pierwszej strony do zmiany zapytania.
- Koszt: zliczenie najwyżej 10 001 wierszy po tym samym warunku i indeksie co strona.
- `ListPager`: „Pokazano 1–25 z 1 234” albo „z ponad 10 000” (listy cen i stanów liczą produkty: „Pokazano produkty
  1–25 z …”); bez numerów dalszych stron.

### Wyszukiwanie zamówień (decyzja P8)

- `q`: dokładny numer (`^[Rr]-[0-9]+$`) i pełny adres e-mail (lokalna część, `@`, domena z kropką i ostatnim członem co
  najmniej 2 znaki, do 254 znaków; „jan@gm” nim nie jest) szukane dokładnie; inny tekst 3–100 znaków (także
  fragment z `@`, np. „jan@gm”) to fragment szukany w numerze, e-mailu i imieniu i nazwisku. Inny tekst krótszy niż 3
  albo dłuższy niż 100 znaków albo ze znakiem sterującym: 400 z komunikatem pola `q`.
- Fragment używa jednego indeksu GIN trigram (`gin_trgm_ops`) na wyrażeniu `lower(number || E'\x1f' || email ||
  E'\x1f' || full_name)` (jeden indeks zamiast trzech, mniejszy koszt zapisu przy składaniu zamówienia), a warunek
  zapytania to dokładnie to wyrażenie `LIKE '%' || lower(:fragment) || '%'` z ucieczką `\`, `%` i `_`, więc planista
  może go użyć. Separator U+001F nie występuje w polach (numer, e-mail i imię i nazwisko odrzucają znaki sterujące)
  ani we fragmencie (400), więc fragment nie może przejść przez granicę pól: wynik jest taki sam jak dopasowanie
  w którymkolwiek polu osobno, bez fałszywych trafień.
- Weryfikacja: test funkcjonalny porównuje wyniki z dopasowaniem w każdym polu osobno, także dla fragmentów
  sklejających koniec jednego pola z początkiem następnego (bez trafienia); test integracyjny na reprezentatywnych
  danych (co najmniej 100 000 zamówień w bazie testowej, po `ANALYZE`) sprawdza `EXPLAIN`: `Bitmap Index Scan` na tym
  indeksie, bez `Seq Scan` po `ordering_order`, dla fragmentu numeru, e-maila i nazwiska.
- Zakres dat: `placedFrom`, `placedTo` (`YYYY-MM-DD` w `Europe/Warsaw`, włącznie; API zamienia na chwile UTC),
  `placedFrom` > `placedTo` to 400. Łączy się ze `status`, `q` i sortem po dacie.
- Panel: etykieta pola bez zmian („Szukaj po numerze zamówienia lub e-mailu”), podpowiedź „Numer, e-mail albo fragment
  (od 3 znaków) numeru, e-maila lub nazwiska. Enter wyszukuje.”; pola daty „Od” i „Do” (natywne `input type="date"`)
  w pasku listy zamówień, w adresie.

## 4. Kategorie (decyzje P9, P10)

### Reguły

- Kategoria: nazwa (1–100 znaków po przycięciu, według reguły tekstów z `docs/features/api-input-contract/spec.md`),
  slug (unikalny wśród rodzeństwa), rodzic albo korzeń, **pozycja** wśród rodzeństwa (kolumna `position`, wartość
  domyślna 0) i `version` (domyślnie 1).
- Kolejność rodzeństwa (drzewo publiczne i panelu, `CategoryTreeBuilder`) to `position`, potem nazwa w `pl_PL`
  (`Collator`), potem `id`. Dopóki pracownik nie zmieni kolejności, wszystkie pozycje są 0, więc kolejność jest
  alfabetyczna, także po `make seed` (`DemoCatalogLoader` nie ustawia pozycji).
- Zmiana kolejności, przeniesienie i utworzenie kategorii (pod blokadą drzewa) najpierw numerują rodzeństwo docelowe
  od nowa (0, 1, 2…) według bieżącej kolejności z reguły wyżej, potem wstawiają kategorię na wskazaną pozycję
  (utworzenie: na końcu). Dzięki temu pierwsza ręczna zmiana nie przestawia pozostałych kategorii, a kolejność działów
  w mega menu ustawia pracownik.
- Głębokość najwyżej 4 poziomy (tyle ma drzewo demo); utworzenie podkategorii albo przeniesienie, które da głębsze
  drzewo, to 409 `category-too-deep`.
- Zmiany (komendy Catalog): `CreateCategory` (na końcu rodzeństwa), `ReviseCategory` (nazwa, slug; `version`),
  `MoveCategory` (rodzic, pozycja; `version`; nie do własnego poddrzewa: 409 `category-cycle`; slug zajęty
  u rodzeństwa nowego rodzica: 409 `category-slug-taken`), `DeleteCategory` (`version`). Zmiana kolejności to
  `MoveCategory` z tym samym rodzicem. `version` chroni nazwę, slug i rodzica; pozycje rodzeństwa przesuwane przy
  wstawieniu nie zmieniają jego wersji (szereguje je blokada drzewa).
- Usunąć można tylko kategorię bez podkategorii i bez produktów (głównych ani dodatkowych, także szkiców); inaczej
  409 `category-not-empty` z liczbą podkategorii i produktów (`subcategoryCount`, `productCount`:
  `BoundedCountResponse`) i `detail` „Przenieś produkty do innej kategorii, zanim usuniesz tę.”.
- Uprawnienia: `MANAGE_CATEGORIES` (`admin`, `marketing`); odczyt każdy pracownik z rolą.
- Współbieżność: każda zmiana drzewa bierze blokadę doradczą transakcji Catalog (`pg_advisory_xact_lock` na stałym
  kluczu, jak promocje), więc dwa przeniesienia nie utworzą cyklu, a głębokość i pustość są sprawdzane na
  zatwierdzonych danych. Zapis kategorii przez rekordy ORM; przeniesienie zmienia zmaterializowaną `path` kategorii
  i poddrzewa jednym DBAL `UPDATE … WHERE path LIKE :old || '%'` w transakcji komendy (jawny wyjątek w
  `apps/api/CLAUDE.md`; rekordy z identity map są potem odświeżane).

### Przekierowania

- Zmiana sluga albo przeniesienie zmienia adres `/c/<ścieżka>` kategorii i potomków. Catalog zapisuje stare ścieżki
  slugów w `catalog_category_redirect (old_path PK, category_id FK ON DELETE CASCADE)`; publiczne
  `GET /api/v1/categories/redirect?path=<ścieżka>` (ścieżka: 1–4 slugi w formacie `CategorySlug` rozdzielone `/`,
  inaczej 400) zwraca `{path}` bieżącej ścieżki albo 404 (`PUBLIC_ACCESS`
  w `security.yaml`, `Cache-Control: public, max-age=60`, tag `catalog`; reguła cache w `infra/varnish/default.vcl`
  z testem `.vtc`). Ścieżka istniejącej kategorii wygrywa: utworzenie albo zmiana, która przywraca starą ścieżkę,
  usuwa jej przekierowanie. Kolejna zmiana prowadzi stare adresy do bieżącej ścieżki (wpis wskazuje kategorię).
- Sklep: `/c/[...path]` spoza drzewa pyta o przekierowanie i odpowiada `permanentRedirect` (308) z zachowaniem
  parametrów; 404 i 400 przekierowania to 404 strony (`notFound()`), inny błąd API to błąd strony, jak przy drzewie.

### Odświeżenie sklepu i reindeksacja

- Zmiana drzewa publikuje integracyjne `CategoryTreeChanged {categoryId, kind}` (outbox). Odbiorca unieważnia sklep:
  purge i rewalidacja tagu `catalog` (drzewo, mega menu, okruszki, listingi; zmiana drzewa jest rzadka, więc pełne
  unieważnienie zamiast tagów kategorii).
- Zmiana nazwy i przeniesienie zmieniają `category_names` i `category_ancestor_ids` w dokumentach ES produktów
  poddrzewa. Reindeksację prowadzi **łańcuch partii** na transporcie `jobs` (minimalna infrastruktura zadań w tle, którą
  może wykorzystać część 2):
  - przebieg `catalog_category_reindex (id, category_id, cursor uuid null, next_batch int, finished_at)` zakłada
    odbiorca `CategoryTreeChanged`, razem z wiadomością pierwszej partii (`ReindexCategoryBatch {runId, batch}`);
  - handler w jednej transakcji `command.bus`: blokuje wiersz przebiegu (`SELECT … FOR UPDATE`), porównuje `batch`
    z `next_batch` (inny albo przebieg zakończony: koniec bez zmian), pobiera identyfikatory 500 kolejnych produktów
    z indeksu wyszukiwania (zapytanie `term category_ancestor_ids = <kategoria>`, sort po `id`, `search_after` =
    `cursor`: dokumenty poddrzewa zawierają kategorię także przed zmianą, a koszt partii nie zależy od rozmiaru
    katalogu, bez nowych indeksów SQL), przebudowuje ich dokumenty istniejącym `ProductIndexing` (wersja zewnętrzna ES
    daje idempotencję), zapisuje `cursor` i `next_batch + 1` i wysyła wiadomość następnej partii; pusta partia ustawia
    `finished_at` i unieważnia `catalog` jeszcze raz. Druga kopia tej samej partii czeka na blokadę i po niej widzi
    nowy `next_batch`, więc nie przetwarza partii drugi raz ani nie wysyła drugiej wiadomości;
  - partia trwa sekundy, daleko poniżej `redeliver_timeout` (300 s); transport `jobs` ponawia 5 razy z rosnącym
    odstępem, potem wiadomość trafia do `failed` z logiem `error`, przebieg zostaje bez `finished_at`, a stan indeksu
    naprawia `catalog:reindex` (zapisane w `apps/api/CLAUDE.md`);
  - `catalog:seed-demo` czyści `catalog_category_redirect`, `catalog_category_reindex` i kolejkę `jobs` (`DELETE FROM
    messenger_messages WHERE queue_name IN ('outbox', 'jobs')`), jak inne seedy swoje kolejki;
  - transport `jobs` (Doctrine, `queue_name=jobs`) konsumuje `worker-commands` w compose i chartcie razem z `payment_simulator`, `scheduled` i `promotions`; wzorzec
    łańcucha (blokada przebiegu, numer partii, kolejna wiadomość w tej samej transakcji) jest opisany
    w `apps/api/CLAUDE.md` jako sposób zadań wywołanych zmianą w domenie i wykonywanych przez workera (komendy
    uruchamiane świadomie, jak `catalog:reindex` i `cart:purge-expired`, zostają przy swoich wzorcach).
  - Do końca łańcucha filtr kategorii w sklepie może pokazywać stare przypisania (ryzyko niżej). Utworzenie,
    usunięcie (pustej) kategorii i zmiana kolejności rodzeństwa nie reindeksują.

### Ekran „Kategorie” (`/categories`, menu Sklep › Katalog › Kategorie)

- Drzewo z Reka UI `Tree` (`role="tree"`, strzałki, rozwijanie) z nazwą, slugiem i liczbą produktów przypisanych
  bezpośrednio (główna albo dodatkowa; `BoundedCount` do 1000) z `GET /admin/categories?withCounts=true` (bez
  parametru drzewo bez liczenia: używają go filtry list).
- Akcje węzła (`RowActions`, tylko `MANAGE_CATEGORIES`): „Dodaj podkategorię”, „Edytuj” (okno: nazwa, slug),
  „Przenieś do…” (okno z `CategoryCombobox` z opcją „Poziom główny”, poddrzewo wyłączone), „W górę”, „W dół”, „Usuń”
  (`ConfirmDialog`). Przeciąganie węzła (natywne HTML5 drag and drop) zmienia rodzica albo kolejność; klawiatura
  i czytnik ekranu używają menu, przeciąganie jest skrótem.
- „Dodaj kategorię” nad drzewem (korzeń). Okno dodania i edycji ma pola „Nazwa” i „Slug” (oba wymagane; slug wpisuje
  pracownik, format i unikalność sprawdza API, podpowiedź z formatem pod polem). Błędy: 409 sluga przy polu, 409 wersji („Kategoria została w międzyczasie
  zmieniona – odśwież”, z odświeżeniem drzewa), 409 głębokości, cyklu i niepustej kategorii z `detail` w oknie.
- Toast po zmianie; po zmianie sluga albo przeniesieniu „Stary adres przekierowuje na nowy.”.

## 5. Klienci (decyzje A4, P11)

### Model odczytu

- Klient panelu to konto sklepu (`owner_type = customer`, `customer_id` = `sub`), które złożyło co najmniej jedno
  zamówienie. Goście nie są klientami (ich zamówienia znajduje wyszukiwanie po e-mailu); konta bez zamówień nie są
  widoczne (API nie korzysta z Keycloak Admin API).
- Model jest w Ordering, bo jego jedynym źródłem są zamówienia; kontekst Customer zostaje przy tożsamości z tokenu
  (`docs/vision.md`). Tabela `ordering_customer (customer_id PK, email, full_name, phone, order_count, paid_count,
  paid_gross, first_order_at, last_order_at, last_order_id)`, aktualizowana DBAL `INSERT … ON CONFLICT DO UPDATE` (ten
  sam wyjątek co sprzedaż):
  - w transakcji `PlaceOrder` tylko dla nowego zamówienia (nie przy powtórzeniu po `Idempotency-Key`):
    `order_count + 1`, kontakt z zamówienia tylko gdy `(excluded.last_order_at, excluded.last_order_id) >=
    (ordering_customer.last_order_at, ordering_customer.last_order_id)`, więc kontakt jest zawsze z najnowszego
    zamówienia;
  - w `MarkOrderPaid` przy wyniku `paid`: `paid_count + 1`, `paid_gross + total_gross` (pełna kwota zamówienia
    z dostawą, inaczej niż przychód; klient bez wiersza, którego zamówienia poprzedzają wypełnienie, dostaje go
    z opłaconego zamówienia). Wartość bez odejmowania zwrotów (jak przychód).
- `ordering:read-models:rebuild` (sekcja 1) przebudowuje tabelę; `ordering:seed-demo` ją czyści.
- Indeksy: `(last_order_at DESC, customer_id)`, `(lower(full_name), customer_id)`, `(order_count DESC, customer_id)`,
  `(paid_gross DESC, customer_id)` (migracja tabeli) i dwa GIN trigram na `lower(email)` i `lower(full_name)`
  (osobna migracja nietransakcyjna, `CONCURRENTLY`).

### API (`openapi-admin.json`, tag `Customers`, `VIEW_CUSTOMERS`: `admin`, `support`)

- `GET /admin/customers?q=&sort=&after=&perPage=`: fragment e-maila albo imienia i nazwiska (3–100 znaków, trigram),
  sort z sekcji 3 (`q` łączy się tylko z `-lastOrderAt`), `total`. Element: `customerId, email, fullName,
  orderCount, paidGross, currency, lastOrderAt`.
- `GET /admin/customers/{customerId}`: kontakt (e-mail, imię i nazwisko, telefon z najnowszego zamówienia),
  podsumowanie (zamówienia, opłacone, wartość, pierwsze i ostatnie zamówienie), adresy (do 10 różnych adresów
  dostawy z ostatnich 50 zamówień, najnowszy pierwszy). Nieznany: 404.
- `GET /admin/orders?customerId=<sub>`: historia zamówień klienta (`VIEW_ORDERS`; istniejący częściowy indeks
  `(customer_id, placed_at DESC, id DESC)`), łączy się ze `status`, odrzuca `q`, `sort`, `refund` i zakres dat (400).
- Szczegóły zamówienia w panelu mają `customerId` (null dla gościa).

### Panel

- Menu Sklep › Klienci (`/customers`, `/customers/:customerId`, strażnik `canViewCustomers`).
- Lista według standardu listy (Klient z e-mailem, Zamówienia, Wartość, Ostatnie zamówienie). Karta klienta w układzie
  szczegółów zamówienia: boczna „Kontakt” (`mailto:`, telefon), „Podsumowanie”, „Adresy”; główna „Zamówienia” (lista
  zamówień klienta, standard listy). Kolejność w DOM jak w szczegółach zamówienia (boczna przed główną, od 1280 px Tab
  zaczyna od bocznej): karta klienta dochodzi do listy wyjątków od kolejności zgodnej z układem w
  `docs/features/admin-design/spec.md` („Dostępność”). Karta „Klient” w szczegółach zamówienia ma link „Karta
  klienta” tylko przy `customerId` i dla ról z `canViewCustomers`.
- `VIEW_SALES` (sekcja 1) i `VIEW_CUSTOMERS` są atrybutami `OrderVoter`.

## Decyzje

Zatwierdzone przez właściciela (z doprecyzowaniami do A3 i P5). Numeracja ma luki: decyzje A5–A8 i P12–P14 dotyczą
części 2 (ADMIN-TOOLS).

- **Z1** Funkcja ma dwie części; minimalna infrastruktura zadań w tle (transport `jobs`, łańcuch partii z blokadą
  przebiegu) jest w części 1, bo potrzebuje jej reindeksacja kategorii.
- **A1** Pulpit składa Backoffice przez porty i `query.bus`, z krawędziami Deptrac do Ordering i Inventory.
- **A2** Sprzedaż z tabeli godzinowej (16 kubełków) w Ordering, aktualizowanej synchronicznie w `MarkOrderPaid`.
- **A3** Liczniki ograniczone (pulpit 1000, listy 10 000, tylko pierwsza strona); liczba produktów tylko z szacunku
  `reltuples` (`ANALYZE` po masowych zmianach, `null` bez szacunku), nigdy `count(*)`.
- **A4** Klienci jako model odczytu `ordering_customer` w Ordering (tylko konta z zamówieniami), bez Keycloak Admin API.
- **A9** Inventory trzyma rekord stanu każdego wariantu (z `product_id`), filtr poziomu stronicowany w Inventory.
- **A10** Sortowanie (`Select` w pasku) bez `@tanstack/vue-table`.
- **P1** Przychód = brutto zamówień opłaconych w okresie wg `paid_at`, bez odejmowania anulowań i zwrotów.
- **P2** Sprzedaż widzi tylko `admin`; kolejki według tabeli w sekcji 1.
- **P5** Okresy z zakończonych godzin, porównania równej długości (sekcja 1).
- **P6** Klucze sortowania jak w tabeli sekcji 3.
- **P7** Licznik wyników do 10 000.
- **P8** Fragment od 3 znaków w numerze, e-mailu i nazwisku; zakres dat w dniach polskich.
- **P9** Usunięcie tylko pustej kategorii; do 4 poziomów; ręczna kolejność także w sklepie.
- **P10** Kategoriami zarządzają `admin` i `marketing`.
- **P11** Klienci: tylko konta z zamówieniami, `admin` i `support`, adresy z ostatnich zamówień.

## Kryteria akceptacji

1. Token `backoffice` bez roli dostaje 403 na każdej trasie `/api/v1/admin/*` poza `/admin/me` (test po trasach
   z routera); panel pokazuje komunikat o braku roli.
2. Pulpit pokazuje kolejki (liczniki ograniczone) i sprzedaż trzech okresów z porównaniem według ról; każdy kafel
   i wiersz „Do obsługi” prowadzi do przefiltrowanej listy; zapytania pulpitu korzystają z indeksów (test `EXPLAIN`
   bez `Seq Scan` na `ordering_order`, `inventory_stock_item`, `catalog_product`), a liczba produktów nie wykonuje
   `count(*)` także przy braku statystyk (test: `productCount: null`, brak zapytania `count`).
3. Sprzedaż liczy każde zamówienie raz (powtórzony webhook, `alreadyPaid`, odmowa, wycofany webhook nic nie dodają);
   okresy kończą się na zakończonej godzinie, porównania mają równą długość (test granic doby `Europe/Warsaw`,
   pierwszej godziny doby i dnia zmiany czasu); `ordering:read-models:rebuild` daje te same wartości co zapis
   przyrostowy, także uruchomiona równolegle z płatnościami i złożeniami (test współbieżności z dwoma procesami).
4. Każdy wariant ma rekord stanu z `product_id` po zdarzeniu Catalog i po `inventory:stock-records:sync`, także gdy
   przyjęcie wyprzedziło zdarzenie; rekord ze stanem nie jest usuwany; filtr `level` i kolejki stanów zgadzają się
   z poziomem z `AvailabilityLevel`. Migracje zmieniają tylko schemat, ponowienie migracji z indeksem `INVALID`
   przechodzi, a `app:backfill` uruchomiony drugi raz nic nie zmienia.
5. Formularz produktu pokazuje ceny i stany wariantów z akcjami według ról; zapis w oknie nie gubi zmian formularza;
   wariant bez ceny ma ostrzeżenie.
6. Listy sortują się po kluczach z sekcji 3 w obu kierunkach z kursorem; kursor innego sortu i niedozwolone połączenia
   to 400; licznik pierwszej strony „z N” / „z ponad 10 000”.
7. Zamówienia da się znaleźć po fragmencie numeru, e-maila i nazwiska (od 3 znaków) i zawęzić zakresem dat.
8. Kategorie: utworzenie, edycja, przeniesienie (także przeciąganiem), kolejność i usunięcie pustej według sekcji 4;
   równoległe przeniesienia nie tworzą cyklu (test współbieżności); stary adres `/c/…` przekierowuje 308 na nowy,
   także po kolejnej zmianie; po zmianie drzewa sklep pokazuje nowe menu, a produkty poddrzewa są przeindeksowane.
9. Łańcuch partii: dwie równoległe dostawy tej samej wiadomości partii przetwarzają ją raz i wysyłają jedną wiadomość
   następnej partii (test współbieżności z dwoma procesami); ponowna dostawa po zakończeniu nic nie zmienia.
10. Lista i karta klienta dla `admin` i `support` (403 dla pozostałych), link z zamówienia do karty; model odczytu
    zgodny z zamówieniami po przebudowie i po kolejnych złożeniach (także powtórzonych) i płatnościach.
11. Każda droga z „Modelu zagrożeń” ma swoje sprawdzenie.
12. Pulpit, ekran kategorii (drzewo i okna) oraz lista i karta klienta są bez naruszeń axe WCAG 2.2 A/AA przy 1280
    i 768 px i przechodzą reflow 320 px (E2E `admin-design.spec.ts`, `catalog-admin.spec.ts`, `admin-customers.spec.ts`);
    każda akcja na drzewie kategorii działa samą klawiaturą, bez przeciągania (E2E `catalog-admin.spec.ts`).
13. `make check`, testy `apps/api`, `apps/admin`, `apps/store` i E2E przechodzą; E2E obejmują pulpit z kolejkami
    i sprzedażą (`staff-dashboard.spec.ts`), ekran kategorii i zmianę sluga z przekierowaniem w sklepie
    (`catalog-admin.spec.ts`) oraz kartę klienta (`admin-customers.spec.ts`).

## Weryfikacja

Kryteria akceptacji sprawdzają testy automatyczne w CI: `make check`, testy `apps/api` (jednostkowe, integracyjne
z PostgreSQL i procesami równoległymi, funkcjonalne po trasach z routera), `apps/admin`, `apps/store` i E2E. Spójność
całości ze specyfikacją i regresje istniejących funkcji sprawdza przegląd gałęzi przed merge'em, w tym wdrożenie:
kolejność migracji, migracje tylko schematu, Job `backfill` po rolloucie, transport `jobs` w `worker-commands` compose i chartu.

## Dokumenty opisujące funkcję

- `docs/vision.md`: klienci w panelu z modelu odczytu Ordering (sekcja „Klienci i pracownicy”, tabela contextów),
  kolejność kategorii w mega menu ustawiana przez pracownika i przekierowanie 308 starych adresów kategorii (sekcja
  SEO i „Przyjazne adresy”).
- `docs/features/deploy/spec.md` („Chart `shop`”): Job `backfill` po rolloucie. `infra/k8s/CLAUDE.md` („Chart
  a compose”): transport `jobs` w `worker-commands` chartu jak w compose.
- `apps/api/CLAUDE.md`: wyjątki DBAL (sprzedaż, klienci, ścieżki poddrzewa, rekordy stanów), migracje
  `CONCURRENTLY`, transport `jobs` i łańcuch partii, reguły liczników ograniczonych, szacunku `reltuples` i sortowania,
  `app:backfill` i zasady jego kroków.
- `apps/admin/CLAUDE.md`: sortowanie w `ListToolbar`, moduły `categories` i `customers`; brak `@tanstack/vue-table`
  i zaznaczania wierszy.
- `apps/store/CLAUDE.md`: przekierowania kategorii, kolejność drzewa.
- `docs/adr/admin-ui-library.md` i `docs/features/admin-design/spec.md`: sortowanie bez `@tanstack/vue-table`; pulpit
  (kafle sprzedaży, liczby „Do obsługi”, linki kafli); w „Dostępności” karta klienta na liście wyjątków kolejności
  fokusu.
- `docs/features/catalog-admin/spec.md`: `VIEW_PRODUCTS` z rolą, filtr zdjęć, sortowanie, kategorie.
- `docs/features/inventory/spec.md`: rekord stanu każdego wariantu, filtr poziomu, `productId`.
- `docs/features/pricing/spec.md`: odczyty z rolą, `productId`, sortowanie.
- `docs/features/order-management/spec.md`: fragment i zakres dat, sortowanie, `customerId`.
- `docs/features/demo-data/spec.md`: generator woła `ordering:read-models:rebuild` i `ANALYZE` po wstawieniu danych
  i zakłada rekord stanu każdemu wariantowi.

## Poza zakresem

Część 2 (ADMIN-TOOLS): notatki wewnętrzne i zwroty płatności z Payment (#221), akcje masowe i zaznaczanie wierszy,
eksport CSV, globalna wyszukiwarka (Ctrl+K), dziennik zmian. Z #461: zarządzanie kontami pracowników, ustawienia
sklepu, import CSV, zapisane widoki list, wysyłka i tracking (#120), faktury i druk, recenzje produktów. Ponadto:
wykresy sprzedaży w czasie i „Najczęściej kupowane”, klienci bez zamówień i goście jako klienci, edycja danych
klienta, RODO (usunięcie konta i anonimizacja).

## Ryzyka

- **Reindeksacja po zmianie drzewa**: przeniesienie działu z milionami produktów reindeksuje je łańcuchem partii długo;
  do końca filtr kategorii w sklepie pokazuje stare przypisania. Akceptowane, bo zmiany drzewa są rzadkie.
- **Trigram na zamówieniach**: częsty fragment (np. „gmail”) przy 50 mln zamówień daje duży skan bitmapy przed
  sortowaniem; zakres dat zawęża, licznik i strona ograniczają odpowiedź, nie skan.
- **Filtr kategorii z sortowaniem**: warunek `EXISTS` po ścieżce nie jest warunkiem indeksu (H4 z DEMO-DATA); ta
  funkcja go nie zmienia.
- **Szacunek liczby produktów**: rozjeżdża się z rzeczywistością między `ANALYZE` (autovacuum); panel opisuje go
  jako „ok.”.
- **DEMO-DATA**: generator pisze tabele z pominięciem komend; bez przebudowy modeli odczytu, `ANALYZE` i rekordów
  stanów kolejki i klienci byłyby puste. Specyfikacja DEMO-DATA (`docs/features/demo-data/spec.md`, krok 8 i „Stany po
  zamówieniach”) każe generatorowi wołać `ordering:read-models:rebuild`, robić `ANALYZE` i zakładać rekord stanu
  (z `product_id`) każdemu wariantowi, a nowe kolumny mają wartości domyślne, więc generator się nie psuje.
- **Migracje na dużych tabelach**: indeksy `CONCURRENTLY` trwają przy 50 mln zamówień długo i muszą zmieścić się
  w `activeDeadlineSeconds` Joba `migrate` (1800 s); przy profilu `full` (k3d) to do sprawdzenia, przy demo na
  serwerze bez znaczenia.
- **Okno wypełnienia**: od migracji do końca `app:backfill` (Job po rolloucie) pulpit, kolejki stanów i klienci
  pokazują niepełne dane; przy 50 mln zamówień przebudowa trwa długo, ale jest poprawna przy ruchu i wznawialna.
- **Koszt zapisu przy składaniu zamówienia**: indeksy `ordering_order` (jeden GIN trigram i dwa pod sort po kwocie)
  i upsert `ordering_customer` wydłużają `PlaceOrder`; mierzy to test obciążeniowy checkoutu (`load/`).
