# Audyt wydajności PostgreSQL – schemat, zapytania, konfiguracja

Zakres: statyczna analiza eksportu rewizji (bez `.git`): `apps/api/migrations` (41 migracji), rekordy ORM
(`*/Infrastructure/Persistence/Doctrine/Record`), wszystkie repozytoria i ręczny SQL w `apps/api/src`, konfiguracja
Doctrine i Messengera, Postgres w `infra/docker-compose.yml`, `infra/postgres`, chart `infra/k8s/charts/shop`
i `infra/k8s/environments/prod/values.yaml`. Moduł `DemoData` czytany tylko jako źródło wolumenów
(`apps/api/fixtures/demo-data/profiles.yaml`, `docs/features/demo-data/spec.md`).

Nie było działającej bazy. Plany zapytań, które zależą od statystyk, są opisane jako „hipoteza do pomiaru” i trafiły
do sekcji „Zapytania do EXPLAIN”. Ścieżki plików podaję względem `apps/api/` (kod, migracje) albo katalogu głównego
repozytorium (infra).

Oznaczenia:
- **[K]** – potwierdzone analizą kodu (wynika wprost z SQL, schematu albo z udokumentowanej semantyki PostgreSQL),
- **[H]** – hipoteza do pomiaru (zależy od statystyk, rozkładu danych albo obciążenia),
- waga: **skala** (10 mln produktów, 30 mln wariantów, 1 mln klientów, 50 mln zamówień, 60 tys. aktywnych
  użytkowników) / **demo** (worf.pl: profil `server`, 10 mln produktów, 5 mln zamówień, ruch symboliczny).

## 0. Ocena ogólna

Warstwa zapytań jest napisana świadomie: paginacja wszędzie jest kursorowa (keyset, porównanie krotek, osobne
zapytania zamiast `IS NULL OR`), liczniki list są ograniczone (`BoundedCounter`, `LIMIT 10001`), liczba produktów na
pulpicie pochodzi z `pg_class.reltuples`, sprzedaż godzinowa ma 16 shardów na godzinę przeciw gorącemu wierszowi,
blokady są brane w stałej kolejności (posortowane `variant_id`, posortowane klucze advisory), indeksy w migracjach na
dużych tabelach są budowane `CONCURRENTLY`. Nie znalazłem `OFFSET`, nieograniczonego `COUNT(*)` w ścieżce żądania
sklepu ani `IN (...)` z długą listą parametrów (listy idą jako jedna tablica `uuid[]`).

Główne ryzyka leżą gdzie indziej:
1. **Serwer bazy działa na domyślnej konfiguracji** (128 MB `shared_buffers`, `max_connections = 100`, brak
   jakiegokolwiek timeoutu, brak poolera) w kontenerze z limitem 2 GiB, a ten sam serwer obsługuje Keycloaka.
2. **Wzmocnienie zapisu na `ordering_order` i `ordering_customer`**: dużo indeksów (w tym GIN trigram) na kolumnach,
   które zmienia każda zmiana statusu i każde zamówienie, więc żaden z tych UPDATE nie jest HOT.
3. **Serializacja składania zamówień na gorących wierszach** (popularny wariant, kod rabatowy z limitem) z blokadą
   trzymaną przez większą część transakcji składania.
4. **Wyszukiwanie fragmentem** (`LIKE '%…%'`) po zamówieniach i klientach przy częstych fragmentach oraz filtr
   kategorii produktów w panelu (`OR EXISTS`).
5. **Tabele rosnące bez sprzątania** (rezerwacje, ruchy magazynowe, powiadomienia, zdarzenia webhooków, historia
   statusów) bez partycjonowania i bez planu archiwizacji.

## 1. Inwentarz tabel

Wolumeny przy skali docelowej wyliczone z `profiles.yaml` (profil `full`) i generatora: 1,9 linii na zamówienie
(`DemoData/Orders/OrderGenerator.php:69`, wagi 45/30/15/10), ~30 mln wariantów (spec DEMO-DATA), historia 3 lata,
~10% zamówień z kodem rabatowym. Wartości oznaczone „~” to szacunki **[H]**.

PK: `uuid` w kodzie aplikacji to UUID v7 (`Uuid::v7()`: zamówienia, linie, płatności, ruchy, historia cen, linie
koszyka, kody, promocje, nowe produkty i warianty), dane demo produktów/wariantów/kategorii to UUID v5 (losowy rozkład,
`DemoData/Identity/DemoUuid.php:33`), identyfikator koszyka to 16 losowych bajtów (`Cart/Domain/CartId.php:33`,
w praktyce v4).

| Tabela | PK (typ) | Indeksy dodatkowe (migracja:linia) | FK i indeks po stronie dziecka | JSON/JSONB | Wolumen (skala) | Sprzątanie |
|---|---|---|---|---|---|---|
| `catalog_category` | `id` uuid (v7/v5) | `parent_id` (…100747:20), `path text_pattern_ops` (…100747:22), uniq `(parent_id, slug)` i częściowy uniq `slug WHERE parent_id IS NULL` (…AddCatalogCategorySlug:23,25) | `parent_id` → self, indeks jest | – | ~130–200 | – |
| `catalog_category_redirect` | `old_path` text | `category_id` (…170000:27) | → category, indeks jest | – | setki | brak (małe) |
| `catalog_category_reindex` | `id` uuid v7 | – | brak FK | – | rośnie o 1 na zmianę drzewa | **brak** (małe) |
| `catalog_product` | `id` uuid (v5 w demo, v7 nowe) | uniq `slug` (…101125:21), `primary_category_id` (:20), GIN trgm `name` (…AddCatalogProductNameIndexes:21), `(name, id)` (:22), częściowy `(name, id) WHERE status='draft'` (…AddCatalogProductStatus:28), `(updated_at, id)` i częściowy draft (…IndexCatalogProductUpdatedAt:23–24), częściowy `(id) WHERE images='[]'` (…IndexCatalogProductWithoutImages:38) | → category, indeks jest | `images`, `variant_axes` JSONB | 10 mln | – |
| `catalog_product_category` | `(product_id, category_id)` | `product_id` (…101125:23) **zdublowany z PK**, `category_id` (:24) | oba FK z indeksem | – | ~kilka mln **[H]** | – |
| `catalog_product_variant` | `id` uuid | uniq `(product_id, position)` (…CreateCatalogProductVariants:25), uniq `(product_id, values_key)` (:26) | → product, pokryty prefiksem | `option_values` JSONB | ~30 mln | – |
| `pricing_variant_price` | `variant_id` uuid | brak (dobrze: UPDATE ceny może być HOT) | brak FK (granica modułu) | – | ~30 mln | – |
| `pricing_price_history` | `id` uuid v7 | `(variant_id, changed_at DESC, id)` (…CreatePricingTables:22) | brak FK | – | ~40–60 mln **[H]** | **brak** (historia biznesowa) |
| `pricing_promotion` | `id` uuid v7 | `(starts_at DESC, id)` (…CreatePromotions:23) | – | – | setki–tysiące | – |
| `pricing_promotion_variant` | `(promotion_id, variant_id)` | `variant_id` (:27), `promotion_id` (:28) **zdublowany z PK** | → promotion, pokryty | – | ≤1000 na promocję (`Promotion::MAX_VARIANTS`) → ~mln **[H]** | – |
| `pricing_discount_code` | `id` uuid v7 | uniq `code` (…CreateDiscountCodes:28), `(created_at DESC, id)` (:30) | – | – | dziesiątki–setki | – |
| `pricing_discount_code_usage` | `id` uuid v7 | uniq `order_id` (:34), `code_id` (:35) **nieużywany przez zapytania**, częściowy `code_id WHERE released_at IS NULL` (:37) | → code, indeks jest | – | ~5 mln (10% zamówień); kilkaset tys. na jeden kod **[H]** | – |
| `inventory_stock_item` | `variant_id` uuid | częściowe `product_id WHERE physical-reserved=0` i `… BETWEEN 1 AND 5` (…IndexStockLevels:22–23,42) | brak FK | – | ~30 mln | – |
| `inventory_reservation` | `reservation_key` varchar(100) | – | – | – | ~50 mln (1 na zamówienie) | **brak** (zakończone zostają na zawsze) |
| `inventory_reservation_line` | `(reservation_key, variant_id)` | – | → reservation, pokryty PK | – | ~95 mln | **brak** |
| `inventory_stock_movement` | `id` uuid v7 | `(variant_id, occurred_at DESC, id)` (…CreateInventoryTables:28), częściowy uniq `reference WHERE type='receipt'` (:30) | brak FK | – | ~190–250 mln (rezerwacja + zatwierdzenie/zwolnienie na linię + przyjęcia) **[H]** | **brak** |
| `cart_cart` | `id` uuid (losowy) | częściowy uniq `customer_id` (…CreateCartTables:24), `expires_at` (:25) | – | – | aktywne koszyki gości (setki tys.–mln) + ≤1 mln klientów | `cart:purge-expired` co godzinę, partie po 1000 (`DbalExpiredCarts.php:26–31`) |
| `cart_line` | `id` uuid v7 | uniq `(cart_id, variant_id)` (:28), `cart_id` (:29) **zdublowany z uniq** | → cart ON DELETE CASCADE, pokryty | – | kilka mln | kaskada z koszykiem |
| `ordering_order` | `id` uuid v7 | uniq `number` (…CreateOrderingTables:26), uniq `idempotency_key` (:27), `(placed_at DESC, id DESC)`, `(status, placed_at DESC, id DESC)`, `lower(email)`, częściowy pending refund (…AddBackofficeOrderListIndexes:20–26), częściowy `(customer_id, placed_at DESC, id DESC) WHERE owner_type='customer'` (…AddCustomerOrderListIndex:20), `(total_gross, id)`, `(status, total_gross, id)` (…IndexOrderTotals:23–24), GIN trgm `lower(number‖email‖full_name)` (…IndexOrderSearch:41), częściowy `paid_at` (…IndexOrderPaidAt:40) – **12 indeksów** | – | – | 50 mln | – |
| `ordering_order_line` | `id` uuid v7 | `order_id` (:30) **zdublowany**, uniq `(order_id, position)` (:31), uniq `(order_id, variant_id)` (:32) | → order, pokryty | – | ~95 mln | – |
| `ordering_order_status_change` | `(order_id, position)` | `order_id` (…AddOrderLifecycle:36) **zdublowany z PK** | → order, pokryty | – | ~150–200 mln | **brak** |
| `ordering_order_notification` | `(order_id, kind)` | `order_id` (…CreateOrderNotifications:22) **zdublowany z PK** | → order, pokryty | – | ~100–150 mln | **brak** |
| `ordering_customer` (model odczytu) | `customer_id` varchar(255) | `(last_order_at DESC, customer_id)`, `(lower(full_name), customer_id)`, `(order_count DESC, customer_id)`, `(paid_gross DESC, customer_id)` (…CreateCustomers:28–31), GIN trgm `lower(email)`, `lower(full_name)` (…IndexCustomerSearch:22–23) | – | – | 1 mln | przebudowa `app:backfill` |
| `ordering_sales_hour` (model odczytu) | `(hour_start, shard)` | – | – | – | ~26 tys. godzin × 16 = ~420 tys. | przebudowa |
| `payment_payment` | `id` uuid v7 | uniq `idempotency_key` (…CreatePaymentTables:23), uniq `(provider, provider_payment_id)` (:24), częściowy uniq `order_id WHERE status='pending'` (:26), `(order_id, created_at)` (:27) | brak FK (granica) | – | ~55 mln | – |
| `payment_webhook_event` | `(provider, event_id)` | – | – | – | ~55 mln+ | **brak** (deduplikacja na zawsze) |
| `payment_simulator_transaction` | `id` uuid v4 | – | – | – | 1 na płatność demo | **brak** (tylko demo) |
| `messenger_messages` | `id` bigint identity | `(queue_name, available_at, delivered_at, id)` (…CreateMessengerTables:20) | – | `body`, `headers` TEXT | chwilowo: outbox + `scheduled` (1 ExpireUnpaidOrder na zamówienie przez 30 min) | ack = DELETE; kolejka `failed` **bez sprzątania** |
| `app_backfill` | `name` | – | – | – | kilka | – |

Tabele rosnące bez ograniczeń i bez sprzątania **[K]**: `inventory_stock_movement`, `inventory_reservation`
+ `inventory_reservation_line` (zakończone rezerwacje nie są nigdy usuwane), `ordering_order_status_change`,
`ordering_order_notification`, `payment_webhook_event`, `pricing_price_history`, `payment_simulator_transaction`,
`catalog_category_reindex`, kolejka `failed` w `messenger_messages`. Klucze idempotencji nie mają osobnej tabeli:
żyją na zawsze w `ordering_order.idempotency_key` i `payment_payment.idempotency_key` z unikalnymi indeksami
(varchar(100) + `request_fingerprint char(64)` na każdym wierszu). Koszyki gości są sprzątane (co godzinę), wiadomości
Messengera są kasowane po ack. Sesji w Postgresie nie ma.

## 2. Inwentarz zapytań

Kolumna „Plan przy skali” opisuje plan oczekiwany ze schematu; **[H]** = do potwierdzenia `EXPLAIN (ANALYZE, BUFFERS)`.

### 2.1 Ordering

| Zapytanie (plik:linia) | Wołane z | Indeks | Plan przy skali |
|---|---|---|---|
| Lista zamówień panelu: `SELECT … , (SELECT SUM(quantity) FROM ordering_order_line WHERE order_id=o.id) … WHERE <filtry> ORDER BY <placed_at\|total_gross\|refund_due_at>, id LIMIT :limit` (`Ordering/Infrastructure/Persistence/Doctrine/DbalBackofficeOrderReader.php:30–32,78–80`) | `GET /api/v1/admin/orders` (`BackofficeOrdersController`), pulpit (kolejki), karta klienta | wg sortu: `(placed_at DESC,id DESC)`, `(status, placed_at…)`, `(total_gross,id)`, `(status,total_gross,id)`, częściowy refund, częściowy klienta; podzapytanie: `ordering_order_line_order_id_idx` | bez fragmentu: Index Scan (Backward) + Limit, podzapytanie 50× index scan – dobrze **[K]**. Status + zakres dat + sort po kwocie: index na kwocie z filtrem dat albo zakres dat + top‑N – **[H]** (EXPLAIN 7) |
| Fragment: `lower(number‖E'\x1f'‖email‖E'\x1f'‖full_name) LIKE '%'‖lower(:fragment)‖'%'` (`DbalBackofficeOrderReader.php:40,121–125`) | parametr `q` listy zamówień (3–100 znaków, `BackofficeOrdersController.php:55`) | GIN trgm `ordering_order_search_trgm_idx` | rzadki fragment: Bitmap Index Scan GIN → top‑N – dobrze. Częsty fragment (`gmail`, `kowal`, `ska`, `@wp.`): bitmapa na miliony wierszy + recheck + sort, albo przejście indeksu `placed_at` z filtrem wyrażenia – **[H] High** (EXPLAIN 1–2) |
| Licznik listy: `SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE … LIMIT 10001)` (`Shared/Infrastructure/Persistence/BoundedCounter.php:54–56`, `DbalBackofficeOrderReader.php:85–90`) | każda lista panelu | jak strona | koszt ograniczony do 10 001 wierszy, ale gdy pasujących jest mniej (rzadki filtr bez indeksu: np. status + fragment), czyta wszystkich kandydatów **[K]**; index-only scan zależy od mapy widoczności (częste UPDATE `ordering_order`) – **[H]** (EXPLAIN 8) |
| Zamówienia klienta w sklepie (`Ordering/Infrastructure/Persistence/Doctrine/DbalCustomerOrderReader.php:24–27,56–57`) | `GET /api/v1/me/orders` | częściowy `(customer_id, placed_at DESC, id DESC) WHERE owner_type='customer'` | Index Scan + Limit, 50 podzapytań po liniach – dobrze **[K]** |
| Adresy klienta: `DISTINCT ON` z ostatnich N zamówień (`DbalCustomerReader.php:112–123`) | karta klienta w panelu | częściowy indeks klienta | Index Scan Limit N + sort w pamięci N wierszy – dobrze **[K]** |
| Lista klientów: `… FROM ordering_customer c [WHERE (lower(email) LIKE … OR lower(full_name) LIKE …)] ORDER BY <sort>, customer_id LIMIT` (`DbalCustomerReader.php:32,66–68`) | `GET /api/v1/admin/customers` | 4 indeksy sortu, 2 GIN trgm | bez fragmentu: Index Scan + Limit **[K]**; częsty fragment: BitmapOr dwóch GIN → sort setek tysięcy wierszy z 1 mln – **[H] Medium** (EXPLAIN 6) |
| Karta klienta: `SELECT … FROM ordering_customer WHERE customer_id = ?` (`DbalCustomerReader.php:82–85`) | panel | PK | Index Scan **[K]** |
| `addOrder` upsert `ordering_customer` (`DbalCustomerSummaries.php:55–78`) | `PlaceOrderHandler.php:142` (transakcja składania) | PK (ON CONFLICT) | 1 wiersz, ale **UPDATE nie-HOT** (zmienia `order_count`, `last_order_at`, często `email`/`full_name` – wszystkie zaindeksowane) **[K]** |
| `addPayment` upsert z `SELECT … FROM ordering_order WHERE id=…` (`DbalCustomerSummaries.php:85–92`) | webhook płatności | PK zamówienia, PK klienta | 1 wiersz, nie-HOT (`paid_gross` zaindeksowane) **[K]** |
| Przebudowa klientów: `INSERT … SELECT DISTINCT`, `SELECT … FOR UPDATE` zakresu, `UPDATE … FROM (GROUP BY) JOIN (DISTINCT ON)`, `DELETE … NOT EXISTS` (`DbalCustomerSummaries.php:95–183`) | `app:backfill` (RebuildCustomersStep), `ordering:read-models:rebuild` | częściowy indeks klienta, PK | zakres klientów → Index Scan po częściowym indeksie, heap fetch każdego zamówienia zakresu; `IN (:locked)` z tablicą do rozmiaru partii **[K]**; czas całości przy 50 mln **[H]** (EXPLAIN 15) |
| Sprzedaż godzinowa: upsert `ordering_sales_hour` (`DbalSalesHours.php:35–48`), suma z zakresu godzin (`:50–61`), przebudowa godziny (`:85–118`), `nextHour` z `LEAST` (`:63–83`) | webhook płatności, pulpit (`GetSalesSummary`), backfill | PK `(hour_start, shard)`, częściowy `paid_at` | upsert HOT (kolumny nieindeksowane), suma z ~11 tys. wierszy na 30 dni, przebudowa: Bitmap/Index Scan zakresu `paid_at` – dobrze **[K]** |
| Numer zamówienia `nextval('ordering_order_number_seq')` (`DbalOrderNumbers.php:25`) | składanie | sekwencja | bez blokady wiersza, bez gorącego punktu (sekwencja poza transakcją) **[K]** |
| Powiadomienia: upsert z warunkiem, `SELECT`, `UPDATE` (`DbalOrderNotifications.php:36–87`) | konsumenci zdarzeń (e-maile) | PK `(order_id, kind)` | 1 wiersz **[K]** |
| Repozytorium zamówienia: `get` (QB + HINT_REFRESH), `lock` (`find … PESSIMISTIC_WRITE` / `SELECT 1 … FOR UPDATE` + `refresh`), `findByIdempotencyKey` (`findOneBy`), `add`/`save` (`DoctrineOrderRepository.php:72–187`) | składanie, płatność, wygaśnięcie, akcje personelu | PK, uniq `idempotency_key`, leniwe kolekcje linii i historii po `order_id` | 3 zapytania na odczyt agregatu (zamówienie + linie + historia) – akceptowalne **[K]**; `save` = UPDATE wersjonowany (optimistic lock `#[ORM\Version]`) + INSERT historii |

### 2.2 Catalog

| Zapytanie (plik:linia) | Wołane z | Indeks | Plan przy skali |
|---|---|---|---|
| Lista produktów panelu: `SELECT p.id,p.name,p.tax_class,p.updated_at FROM catalog_product p WHERE [name ILIKE] [kategoria OR EXISTS] [status literal] [images='[]'] ORDER BY name\|updated_at, id LIMIT` (`Catalog/Infrastructure/Persistence/Doctrine/DbalBackofficeProductSource.php:29–43,69–71`) | `GET /api/v1/admin/products` | `(name,id)`, `(updated_at,id)`, częściowe draft, GIN trgm `name`, częściowy bez zdjęć | bez filtra: Index Scan + Limit **[K]**. Filtr kategorii: `primary_category_id IN (…) OR EXISTS (…)` nie jest warunkiem indeksu → przejście indeksu sortu z filtrem SubPlan na każdym wierszu; mała kategoria = skan dużej części 10 mln – **[H] High** (EXPLAIN 3–4; ryzyko H4 ze spec DEMO-DATA). Termin 2-znakowy: skan `(name,id)` z filtrem ILIKE (komentarz w kodzie) **[K]** |
| Licznik listy produktów z `LIMIT 10001` (`DbalBackofficeProductSource.php:80–85`) | ta sama lista | jak wyżej | z filtrem kategorii o < 10 001 produktach: pełny skan 10 mln z SubPlan – **[H] High** (EXPLAIN 4) |
| Podsumowania strony: `… (SELECT COUNT(*) FROM catalog_product_variant WHERE product_id=p.id) … WHERE p.id = ANY(:ids)` (`DbalBackofficeProductSource.php:95–103`) | ta sama lista | PK, uniq `(product_id, position)` | ≤ rozmiar strony – dobrze **[K]** |
| Liczniki kategorii `countAll`: dla każdej kategorii `count(*)` z `UNION ALL` primary + additional `LIMIT 1001` (`DbalCategoryProductCounts.php:20–23,41–57`) | drzewo kategorii panelu z `withCounts` (`GetBackofficeCategoryTreeHandler.php:18,31`) | `primary_category_id`, `category_id` | ~150 kategorii × ≤1001 wpisów indeksu ≈ 150 tys. wpisów na żądanie – **[K] Low/Medium** |
| Szczegóły produktu po slugu + warianty + okruszki (`DbalProductDetailsSource.php:26–80`) | strona produktu w sklepie | uniq `slug`, uniq `(product_id, position)`, PK kategorii | 3 Index Scany – dobrze **[K]** |
| Edycja produktu (`DbalProductEditSource.php:23–43`) | panel | PK, PK `catalog_product_category`, `(product_id, position)` | dobrze **[K]** |
| Warianty: `summaries`, `find`, `ofProducts` (`= ANY(uuid[])`), `page` po `v.id` (`DbalVariantSource.php:29–130`) | koszyk, składanie (`OrderCatalog`), reindeks ofert, backfill stanów | PK wariantu, PK produktu | `ANY` z tablicą rozmiaru koszyka/partii – dobrze; `page` = Index Scan PK + Nested Loop do produktu **[K]** |
| Źródło indeksu wyszukiwarki: CTE `page` (`ORDER BY id LIMIT`) + 3× `LATERAL` (kategorie dodatkowe, warianty JSON, nazwy przodków) (`DbalProductSearchSource.php:22–59`) | `catalog:reindex`, reindeks pojedynczego produktu, partie reindeksu kategorii | PK produktu, PK/indeksy pomocnicze | 20 tys. stron po 500 przy 10 mln; koszt dominuje ES i `GetProductOffers` (ryzyko H5 spec) **[K]** |
| Mapa strony – granice: `row_number() OVER (ORDER BY id)` na całym `catalog_product` (`DbalSitemapProductSource.php:29–35`) | `GET` porcji mapy strony, cache 1 h na instancję (`CachingSitemapProductSource.php:30–38`) | PK (index-only scan) | 10 mln wierszy przez WindowAgg co godzinę na każdy pod API – **[K] Medium** (EXPLAIN 12) |
| Mapa strony – porcja: `id >= … AND id < … ORDER BY id LIMIT 50001` (`DbalSitemapProductSource.php:58–64`) | jw. | PK | Index Scan zakresu – dobrze **[K]** |
| Repozytorium produktu `all()`: `SELECT product, additional, variant … LEFT JOIN … ORDER BY slug` bez limitu, hydratacja ORM (`DoctrineProductRepository.php:78–93`) | **brak wywołań** w `src` (sprawdzone `grep`) | – | martwy kod, który przy 10 mln produktów i 30 mln wariantów wyczerpałby pamięć – **[K] Medium (latentne)** |
| Repozytorium kategorii `all()` z HINT_REFRESH (`DoctrineCategoryRepository.php:74–86`) | m.in. każdy listing sklepu (`ListProductsHandler.php:79`), drzewo, redirect | – | ~150 wierszy, hydratacja ORM na każde żądanie, które nie trafi w cache – **[K] Low** |
| `movePaths`: `UPDATE catalog_category … WHERE path LIKE :from‖'%'` (`DoctrineCategoryRepository.php:98–110`) | przeniesienie kategorii | `path text_pattern_ops` | dobrze **[K]** |
| Blokady: `pg_advisory_xact_lock` drzewa (`PostgresCategoryTreeLock.php:33`), `FOR UPDATE`/`FOR KEY SHARE` kategorii (`DoctrineCategoryRepository.php:112–123`), run reindeksu `FOR UPDATE` (`DbalCategoryReindexRuns.php:41–44`), `pg_try_advisory_lock` przebudowy indeksu (`PostgresSearchIndexRebuildLock.php:23`) | panel, worker | – | patrz §4 (długa transakcja partii reindeksu) |
| Statystyki: `reltuples` z `pg_class` i `ANALYZE catalog_product` (`PostgresProductTableStatistics.php:20,27`) | pulpit, backfill | – | stały koszt **[K]** |

### 2.3 Inventory

| Zapytanie (plik:linia) | Wołane z | Indeks | Plan przy skali |
|---|---|---|---|
| `lockMany`: `SELECT … FROM inventory_stock_item WHERE variant_id = ANY(:ids) ORDER BY variant_id FOR UPDATE` (`Inventory/Infrastructure/Persistence/Doctrine/DoctrineStockItemRepository.php:53–56`) | rezerwacja przy składaniu (`ReserveStockHandler.php:52`), zatwierdzenie/zwolnienie (`ReservationTransitionHandler.php:99`), przyjęcie/korekta | PK | Index Scan, blokady w kolejności `variant_id` – brak zakleszczeń między tymi ścieżkami **[K]**; gorący wiersz – §4 |
| `save`: `UPDATE inventory_stock_item SET physical, reserved, version, updated_at WHERE variant_id AND version` (`DoctrineStockItemRepository.php:148–162`) | jw. | PK | **nie-HOT**: `physical` i `reserved` występują w predykatach indeksów częściowych (…IndexStockLevels:22–23), a PostgreSQL liczy kolumny predykatów jako zaindeksowane **[K]** |
| `INSERT inventory_stock_movement` (`DoctrineStockItemRepository.php:188–209`) | każda zmiana stanu | PK + `(variant_id, occurred_at DESC, id)` | append **[K]** |
| `findReceipt` (`DoctrineStockItemRepository.php:83–86`) | przyjęcie towaru (idempotencja) | częściowy uniq `reference WHERE type='receipt'` | Index Scan **[K]** |
| Historia stanu wariantu (`DbalStockHistoryReader.php:36–44`) | panel | `(variant_id, occurred_at DESC, id)` | Index Scan + Limit – dobrze **[K]** |
| Produkty po poziomie stanu: `SELECT product_id … WHERE physical-reserved=0 AND product_id IS NOT NULL GROUP BY product_id ORDER BY product_id LIMIT` (`DbalStockLevelProducts.php:35–45`), liczniki `LIMIT 10001` (`:58–73`) | panel (filtr stanu), pulpit (kolejki) | częściowe indeksy po `product_id` | Index(-Only) Scan częściowego indeksu + Group – dobrze; index-only zależy od VM (gorące UPDATE stanu) **[H]** (EXPLAIN 19) |
| `ensure` / `removeEmptyOfRemovedVariants` (`DbalStockRecords.php:65–98`) | synchronizacja rekordów stanu po zmianie wariantów, backfill | PK; DELETE trafia w częściowy indeks out-of-stock (warunek `physical - reserved = 0` powtórzony) | dobrze **[K]** |
| Rezerwacje: `INSERT … ON CONFLICT DO NOTHING`, multi-row INSERT linii, `SELECT status [FOR UPDATE]`, `UPDATE status` (`DoctrineReservationRepository.php:37–103`) | składanie, płatność, wygaśnięcie | PK | dobrze; UPDATE statusu HOT (brak indeksu na `status`) **[K]** |

### 2.4 Pricing

| Zapytanie (plik:linia) | Wołane z | Indeks | Plan przy skali |
|---|---|---|---|
| Ceny `findMany = ANY(uuid[])`, `page` po `variant_id` (`DoctrineVariantPriceRepository.php:56–83`) | wycena koszyka/składania, oferty reindeksu, rekoncyliacja podatkowa | PK | dobrze **[K]** |
| Zapis ceny: UPDATE wersjonowany + INSERT historii (`DoctrineVariantPriceRepository.php:95–159`) | `SetVariantPriceHandler` (pod `pg_advisory_xact_lock_shared`) | PK; historia: PK + 1 | UPDATE HOT-able (brak indeksów dodatkowych) **[K]** |
| Aktywne promocje wariantów: `JOIN pricing_promotion … WHERE pv.variant_id = ANY(:ids) AND starts_at <= :at AND ends_at > :at` (`DbalActivePromotions.php:33–42`) | wycena koszyka/składania, oferty | `pricing_promotion_variant_variant_id_idx`, PK promocji | Nested Loop, filtr czasu po stronie promocji – dobrze przy kilku promocjach na wariant **[K]** |
| Omnibus: `unnest(3 tablic) CROSS JOIN LATERAL (ostatni wpis przed oknem UNION ALL wpisy w oknie)` (`DbalOmnibusHistory.php:43–51`), promocje w oknie (`:72–79`) | strona produktu/oferty w promocji, reindeks | `(variant_id, changed_at DESC, id)` | po jednym Index Scan na wariant – dobrze **[K]** |
| Historia ceny wariantu (`DbalPriceHistoryReader.php:34–42`) | panel | `(variant_id, changed_at DESC, id)` | dobrze **[K]** |
| Lista promocji z `count(*)` wariantów na wiersz (`DbalPromotionReader.php:40–44`) | panel | `(starts_at DESC, id)`, PK `promotion_variant` | ≤1000 na promocję × rozmiar strony – dobrze **[K]** |
| Lista kodów z `(SELECT count(*) FROM pricing_discount_code_usage u WHERE u.code_id=c.id AND u.released_at IS NULL)` na wiersz (`DbalDiscountCodeReader.php:42–46`) | `GET /api/v1/admin/discount-codes` | częściowy `code_id WHERE released_at IS NULL` | koszt = suma aktywnych użyć kodów strony; przy ~5 mln użyć na kilkanaście kodów to setki tysięcy na kod, czyli miliony wpisów indeksu na stronę – **[H] High** (EXPLAIN 5) |
| Prefiks kodu `c.code LIKE :prefix` (`DbalDiscountCodeReader.php:107–108`) | jw. | uniq `code` w domyślnej kolacji (nie `text_pattern_ops`/`C`) – nie obsłuży LIKE | Seq Scan małej tabeli – **[K] Low** |
| Liczba aktywnych użyć kodu z limitem pod blokadą wiersza kodu (`DoctrineDiscountCodeUsageRepository.php:24,76–82`, `RedeemDiscountCodeHandler.php:43–51`) | składanie zamówienia z kodem z limitem | częściowy `code_id WHERE released_at IS NULL` | O(liczba użyć) przy każdym składaniu, w sekcji krytycznej – **[K] mechanizm, [H] koszt** (EXPLAIN 9) |
| Kolizje promocji `conflictingVariantIds`, `isInActivePromotion` (`DoctrinePromotionRepository.php:87–117`) | zapis promocji, zapis ceny | `variant_id` | dobrze **[K]** |
| Repozytoria kodów/promocji przez ORM (`find`, `findByCode`, `lock … FOR NO KEY UPDATE`) (`DoctrineDiscountCodeRepository.php:39–94`) | wycena, składanie, panel | PK, uniq `code` | dobrze **[K]** |

### 2.5 Cart, Payment, Backoffice, Shared, Messenger, infra

| Zapytanie (plik:linia) | Wołane z | Indeks | Plan przy skali |
|---|---|---|---|
| Odczyt koszyka z liniami (`Cart/Infrastructure/Persistence/Doctrine/DbalCartReader.php:23–51`) | `GET` koszyka gościa/klienta | PK / częściowy uniq `customer_id`, `cart_line` po `cart_id` | dobrze **[K]** |
| Blokada koszyka `find … PESSIMISTIC_WRITE` / `SELECT 1 … FOR UPDATE` + `refresh` linii (`DoctrineCartRepository.php:154–181`), upsert koszyka klienta (`:77–97`) | każda zmiana koszyka, składanie | PK, częściowy uniq | dobrze; UPDATE koszyka zmienia `expires_at` (zaindeksowane) → nie-HOT **[K]** |
| Purge: `WITH batch AS MATERIALIZED (SELECT id … WHERE expires_at < :now ORDER BY expires_at, id LIMIT :limit FOR UPDATE SKIP LOCKED) DELETE …` (`DbalExpiredCarts.php:26–31`) | CronJob `cart-purge` co godzinę | `expires_at` | Index Scan + Limit – dobrze **[K]** |
| Płatności: `findOneBy(idempotencyKey)`, `lockPendingOfOrder` (QB + PESSIMISTIC_WRITE), `lockByProviderPayment`, `INSERT payment_webhook_event ON CONFLICT DO NOTHING`, `latestOfOrder` (`findOneBy … ORDER BY createdAt DESC, id DESC`) (`Payment/Infrastructure/Persistence/Doctrine/DoctrinePaymentRepository.php:84–142`) | start płatności, webhook, status płatności | uniq idempotencji, częściowy pending, uniq `(provider, provider_payment_id)`, `(order_id, created_at)` | dobrze **[K]** |
| Outbox na pulpicie: `SELECT count(*) FROM messenger_messages WHERE queue_name = 'outbox'` (`Backoffice/Infrastructure/MessagingStatisticsAdapter.php:35`) | pulpit (co 30 s na otwarty panel) | indeks Messengera (prefiks `queue_name`) | nieograniczony `count(*)`; tani przy pustym outboxie, liniowy przy zaległości (awaria RabbitMQ) – **[K] Low/Medium** (EXPLAIN 10) |
| Pobranie wiadomości przez transport Doctrine Messengera (kształt z Symfony: `SELECT … WHERE queue_name = ? AND (delivered_at IS NULL OR delivered_at < ?) AND available_at <= ? ORDER BY available_at LIMIT 1 FOR UPDATE SKIP LOCKED`, potem `UPDATE delivered_at`, po ack `DELETE`) | worker: 5 transportów Doctrine (`config/packages/messenger.yaml:29–105`) | `(queue_name, available_at, delivered_at, id)` | Index Scan z filtrem `OR`; tabela o dużej rotacji, wrażliwa na wstrzymany horyzont xmin – **[H]** (EXPLAIN 11) |
| Sonda zdrowia `SELECT 1` (`Shared/Infrastructure/Health/Probe/PostgresProbe.php:27`) | readiness/pulpit | – | – |
| `initialize.sh`: `count(*)` na `catalog_product`, `pricing_variant_price`, `inventory_stock_item`, `ordering_order` (`infra/k8s/charts/shop/files/scripts/initialize.sh:13`) | Job `initialize` przy **każdym** wdrożeniu | PK (index-only) / seq scan | ~120 mln wierszy przy każdym deployu; minuty I/O na zimnym cache – **[K] Medium** (EXPLAIN 13) |
| `pg_dump -Fc` obu baz (`infra/k8s/charts/shop/files/scripts/backup.sh:46`) | CronJob `backup` (dziś `enabled: false` w prod) | – | jedna transakcja REPEATABLE READ na godziny przy 50 mln zamówień → horyzont xmin wstrzymuje vacuum wszystkich gorących tabel – **[K] mechanizm, [H] czas** |

## 3. Indeksy: brakujące, nadmiarowe, zdublowane

### 3.1 Zdublowane (prefiks PK albo indeksu unikalnego) – czysty koszt zapisu **[K]**

| Indeks | Pokrywa go | Tabela (wolumen) | Migracja:linia |
|---|---|---|---|
| `ordering_order_line_order_id_idx (order_id)` | uniq `(order_id, position)` i `(order_id, variant_id)` | ~95 mln | `Version20261105130000CreateOrderingTables.php:30` |
| `ordering_order_status_change_order_id_idx (order_id)` | PK `(order_id, position)` | ~150–200 mln | `Version20261107140000AddOrderLifecycle.php:36` |
| `ordering_order_notification_order_id_idx (order_id)` | PK `(order_id, kind)` | ~100–150 mln | `Version20261107120000CreateOrderNotifications.php:22` |
| `cart_line_cart_id_idx (cart_id)` | uniq `(cart_id, variant_id)` | mln | `Version20261103120000CreateCartTables.php:29` |
| `IDX_8BEBEF264584665A (product_id)` na `catalog_product_category` | PK `(product_id, category_id)` | mln | `Version20261029101125CreateCatalogProduct.php:23` |
| `pricing_promotion_variant_promotion_id_idx (promotion_id)` | PK `(promotion_id, variant_id)` | mln | `Version20261108090000CreatePromotions.php:28` |

Razem ok. 350–450 mln zbędnych wpisów indeksu przy skali (kilkanaście GB, każdy INSERT zamówienia płaci 3 dodatkowe
wstawienia). Waga: skala **Medium**, demo **Low**. Uwaga: rekordy ORM deklarują te indeksy (`#[ORM\Index]` w
`OrderLineRecord.php:24`, `OrderStatusChangeRecord.php:20`, `OrderNotificationRecord.php:20`, `CartLineRecord.php:20`,
`PromotionVariantRecord.php:18`), więc usunięcie wymaga zmiany atrybutów razem z migracją `DROP INDEX CONCURRENTLY`.

### 3.2 Nieużywane albo wątpliwe

- `pricing_discount_code_usage_code_id_idx (code_id)` (`Version20261108190000CreateDiscountCodes.php:35`): żadne
  zapytanie nie filtruje po wszystkich użyciach kodu (liczy tylko aktywne, częściowym indeksem); FK do kodu nie
  wymaga indeksu, bo kodów się nie usuwa (komentarz migracji). **[K] Low**.
- `ordering_order_total_gross_id_idx` i `ordering_order_status_total_gross_id_idx` (`Version20261115130100IndexOrderTotals.php:23–24`):
  dwa indeksy na 50 mln wierszy tylko dla sortu listy panelu po kwocie (fragment jest z nim wykluczony). Drugi jest
  zmieniany przy każdej zmianie statusu. Jeśli sort po kwocie jest rzadki, PG 18 (skip scan) albo `(status, total_gross, id)`
  sam może obsłużyć sort bez statusu tylko gdy statusów jest mało – do decyzji po pomiarze użycia
  (`pg_stat_user_indexes.idx_scan`). **[H] Medium (koszt zapisu)**.
- `ordering_order_placed_at_id_idx` i `(status, placed_at DESC, id DESC)`: oba uzasadnione (sort domyślny z i bez
  statusu) **[K]**.
- GIN trgm `ordering_order_search_trgm_idx` na wyrażeniu z trzech pól (50 mln × ~60 znaków ≈ dziesiątki trigramów na
  wiersz): największy indeks tabeli (szacunkowo kilka–kilkanaście GB **[H]**), aktualizowany przy każdym nie-HOT
  UPDATE zamówienia (każda zmiana statusu) przez listę oczekującą `fastupdate` – skoki opóźnień, gdy backend zapisu
  opróżnia listę (`gin_pending_list_limit` 4 MB) **[H]**.
- 4 indeksy sortu + 2 GIN na `ordering_customer` (`Version20261116090000CreateCustomers.php:28–31`,
  `Version20261116090100IndexCustomerSearch.php:22–23`): uzasadnione listą klientów panelu, ale każde zamówienie
  i płatność klienta wstawia nowe wpisy do wszystkich 7 indeksów (nie-HOT). **[K] Medium (zapis)**.

### 3.3 Brakujące / do rozważenia

- **Brak indeksu dla filtra kategorii listy produktów panelu** – problem jest w kształcie zapytania, nie w braku
  indeksu: `OR EXISTS` (`DbalBackofficeProductSource.php:35–43`). Przepisanie na `UNION` dwóch gałęzi (primary przez
  `catalog_product_primary_category_id_idx`, additional przez `IDX_8BEBEF2612469DE2`) z `ORDER BY … LIMIT` w każdej
  gałęzi; do sortu po nazwie w kategorii przydałby się `(primary_category_id, name, id)` **[H]**.
- **Wyszukiwanie fragmentem zamówień**: zamiast jednego GIN na 50 mln rozważyć ograniczenie (np. fragment tylko
  w ostatnich N miesiącach z warunkiem `placed_at`) albo przeniesienie do Elasticsearcha, który już jest w stosie **[H]**.
- **Kolacja**: bazy tworzone bez `LOCALE`/`ICU` (`infra/postgres/init/10-create-databases.sh`), więc domyślna kolacja
  obrazu (en_US.utf8). B-drzewa na tekście nie obsługują `LIKE 'prefix%'` (kody rabatowe); sort `lower(full_name)`
  i `name` płaci kosztem porównań glibc. Ścieżki kategorii mają `text_pattern_ops` – dobrze **[K] Low**.
- `inventory_stock_item` nie ma pełnego indeksu na `product_id` – świadomie (zapytania po produkcie zawsze łączą się
  z predykatem indeksu częściowego) **[K]**, nie dodawać.
- `payment_payment`: brak indeksu na `ordering_order.payment_id` – nie jest potrzebny (brak zapytań) **[K]**.
- Kolejność kolumn w indeksach złożonych zgadza się z zapytaniami (kolumna równości, potem sort, potem `id`
  w kierunku sortu); kursory są warunkiem indeksu (porównanie krotek) **[K]**.

### 3.4 Zgodność ORM z migracjami (ryzyko pośrednie)

Atrybuty `#[ORM\Index]` nie odpowiadają rzeczywistym definicjom (np. `CustomerRecord.php:24` deklaruje
`ordering_customer_name_idx` na `customer_id`, a migracja ma `(lower(full_name), customer_id)`; GIN trgm, kierunki
`DESC` i wyrażenia nie są wyrażalne w atrybutach). `doctrine:migrations:diff` może wygenerować `DROP/CREATE` tych
indeksów na tabelach po 50 mln wierszy bez `CONCURRENTLY`. **[K] Low (proces)** – warto mieć test, że diff jest pusty,
albo `schema_filter`.

## 4. Współbieżność i zapis

### 4.1 Kolejność blokad i zakleszczenia **[K]**

- Składanie (`Ordering/Application/Command/PlaceOrderHandler.php:104–142`, jedna transakcja `doctrine_transaction`):
  koszyk `FOR UPDATE` (:104) → kod rabatowy z limitem `FOR NO KEY UPDATE` (:130 → `DoctrineDiscountCodeRepository.php:82`)
  → `nextval` (:132) → wyczyszczenie koszyka (:133) → rezerwacja: wiersz rezerwacji, potem stany `ORDER BY variant_id
  FOR UPDATE` (:134 → `ReserveStockHandler.php:52`) → INSERT zamówienia, linii, historii (:137) → outbox (:138) →
  wiadomość `scheduled` (:139) → upsert `ordering_customer` (:142) → COMMIT.
- Płatność/wygaśnięcie/personel: zamówienie `FOR UPDATE` → rezerwacja → stany (posortowane) → użycie kodu (bez
  blokady kodu) → klient/sprzedaż. Komentarze w `ExpireUnpaidOrderHandler.php:26–28` i
  `RedeemDiscountCodeHandler.php:20–25` opisują brak cyklu i zgadza się to z kodem.
- Przebudowy modeli odczytu blokują wiersze zakresu `ORDER BY` klucza (`DbalCustomerSummaries.php:141`,
  `DbalSalesHours.php:98`), więc nie tworzą cyklu ze składaniem (które bierze wiersz klienta jako ostatni).
- Advisory: drzewo kategorii (globalne, panel), zapis promocji (exclusive) vs zapis ceny (shared), synchronizacja stanów
  (posortowane klucze crc32 – kolizje haszy tylko serializują, nie zakleszczają).

Nie znalazłem ścieżki z odwrotną kolejnością. Ryzyko zakleszczeń: **Low** **[K]** (do potwierdzenia
`log_lock_waits`/`deadlock_timeout` w teście k6 **[H]**).

### 4.2 Gorące wiersze

| Gorący wiersz | Mechanizm | Czas trzymania blokady | Skala / demo |
|---|---|---|---|
| `inventory_stock_item` popularnego wariantu (`DoctrineStockItemRepository.php:53–56,148–162`) | `FOR UPDATE` od rezerwacji (`PlaceOrderHandler.php:134`) do COMMIT, czyli przez INSERT zamówienia z 12 indeksami + GIN, linie, historię, outbox, `scheduled`, upsert klienta i fsync commitu | kilka–kilkanaście ms na zamówienie **[H]** → górna granica rzędu 50–200 zamówień/s na jeden wariant; flash sale jednego wariantu ustawia składania w kolejkę, a wątki FrankenPHP czekają bez `lock_timeout` | **High** / Low |
| `pricing_discount_code` z limitem użyć (`RedeemDiscountCodeHandler.php:43–51`) | `FOR NO KEY UPDATE` brany **przed** `nextval`, czyszczeniem koszyka i rezerwacją, trzymany do COMMIT; pod blokadą `count(*)` aktywnych użyć (rośnie z liczbą użyć) | cała reszta transakcji składania + O(n) licznika | **High** / Low |
| `pricing_discount_code` bez limitu | FK użycia → `FOR KEY SHARE` na wierszu kodu z wielu równoległych transakcji → MultiXact na jednym wierszu (znany problem skalowania PG: SLRU multixact, `pg_stat_slru`) | do COMMIT | **Medium [H]** / Low |
| `ordering_customer` jednego klienta | upsert składania i płatności | do COMMIT | Low (jeden klient rzadko składa równolegle) **[K]** |
| `ordering_sales_hour` | 16 shardów na godzinę (`SalesSql.php:24–27`) | do COMMIT webhooka | Low – dobre rozwiązanie **[K]** |
| `ordering_order_number_seq` | sekwencja, poza transakcją | – | brak problemu **[K]** |

Rekomendacje: przenieść rezerwację stanów (i blokadę kodu z limitem) możliwie blisko końca transakcji – po INSERT
zamówienia i upsercie klienta (kolejność blokad trzeba wtedy przemyśleć tak, by nadal nie było cyklu z płatnością);
licznik użyć kodu z limitem trzymać jako kolumnę `used_count` na kodzie (UPDATE pod tą samą blokadą zamiast
`count(*)`); ustawić `lock_timeout` dla transakcji żądań HTTP. **[H]** – zmierzyć k6 na jednym wariancie i jednym
kodzie z limitem (`pg_stat_activity.wait_event = 'transactionid'/'tuple'`, `pg_locks`).

### 4.3 HOT updates, bloat i autovacuum

| Tabela | Częstość UPDATE | Indeksowane kolumny zmieniane przez UPDATE | HOT? |
|---|---|---|---|
| `ordering_order` | ~3–4 zmiany statusu na zamówienie (opłacenie, wysyłka, doręczenie, ew. zwrot/anulowanie) + `version` | `status` (2 indeksy), `paid_at` (częściowy), `refund_*` (częściowy pending) | **nie** – każda zmiana statusu wstawia nowe wpisy do ~11 b-drzew i GIN trgm **[K]** |
| `ordering_customer` | każde zamówienie i płatność klienta | `order_count`, `last_order_at`, `paid_gross`, często `email`/`full_name` | **nie** – 7 indeksów, w tym 2 GIN **[K]** |
| `inventory_stock_item` | 2× na linię zamówienia (rezerwacja, zatwierdzenie/zwolnienie) + przyjęcia | `physical`, `reserved` w predykatach indeksów częściowych | **nie** **[K]** |
| `cart_cart` | każda zmiana koszyka | `expires_at` (indeks purge) | **nie** **[K]** (tabela mniejsza – Low) |
| `pricing_variant_price` | zmiana ceny | brak indeksów dodatkowych | tak **[K]** |
| `inventory_reservation` | zmiana statusu | brak | tak **[K]** |
| `ordering_sales_hour` | każda płatność | brak (tylko PK) | tak **[K]** |
| `payment_payment` | zmiana statusu | `status` w predykacie częściowego uniq pending | nie (Low) **[K]** |
| `messenger_messages` | INSERT → UPDATE `delivered_at` → DELETE | `delivered_at` w indeksie | nie; tabela o pełnej rotacji **[K]** |

Autovacuum działa na wartościach domyślnych (brak `ALTER TABLE … SET (autovacuum_*)` w migracjach i brak konfiguracji
serwera) **[K]**. Skutki przy skali **[H]**:
- `ordering_order` (50 mln): `autovacuum_vacuum_scale_factor = 0.2` → vacuum po ~10 mln martwych krotek, analyze po
  ~5 mln zmian; między przebiegami mapa widoczności jest nieaktualna, więc ograniczone liczniki i `count(*)` przez
  index-only scan robią heap fetch.
- Tabele tylko-do-dopisywania (`inventory_stock_movement`, `ordering_order_status_change`, `ordering_order_notification`,
  `pricing_price_history`): vacuum po wstawieniu 20% tabeli (`autovacuum_vacuum_insert_scale_factor`) – przy 200 mln
  wierszy to 40 mln wstawień między przebiegami; zamrażanie (anti-wraparound) przyjdzie jako duży, wymuszony vacuum.
- Gorące wiersze `inventory_stock_item`: nie-HOT UPDATE tego samego wiersza tworzy łańcuch wersji i wiele wpisów PK
  dla jednego klucza (bottom-up deletion od PG 14 łagodzi); vacuum tabeli 30 mln nastąpi po 6 mln martwych krotek.
- `messenger_messages`: mała, ale każda długa transakcja w bazie (backup `pg_dump`, partia reindeksu kategorii,
  długi raport, `initialize.sh`) wstrzymuje usuwanie martwych wierszy; skan `SKIP LOCKED` przechodzi przez rosnącą liczbę
  martwych krotek.

Rekomendacje: per-tabela `autovacuum_vacuum_scale_factor` 0.01–0.02 (lub `autovacuum_vacuum_threshold`) dla
`ordering_order`, `inventory_stock_item`, `ordering_customer`, `messenger_messages`; `autovacuum_vacuum_insert_scale_factor`
0.02 dla tabel historii; podniesienie `autovacuum_vacuum_cost_limit`, `autovacuum_max_workers`,
`maintenance_work_mem`. Rozważyć `fillfactor` 80–90 dla `inventory_stock_item` i `pricing_variant_price` (więcej HOT
przy UPDATE wersji/czasu), ale dla stanów bez usunięcia predykatów indeksów częściowych nie da efektu.

### 4.4 Długie transakcje **[K]**

- Partia reindeksu kategorii (`Catalog/Application/Command/ReindexCategoryBatchHandler.php:59–86`): `FOR UPDATE` runu
  (`DbalCategoryReindexRuns.php:41–44`), potem do 500 reindeksów produktu (odczyty SQL + wywołania HTTP do
  Elasticsearcha i ofert) w tej samej transakcji command.bus. Transakcja trwa sekundy–dziesiątki sekund i trzyma
  horyzont xmin. Skala **Medium**, demo Low.
- `pg_dump` w backupie (dziś wyłączony w prod) – godziny przy 50 mln zamówień (patrz §2.5). Skala **High** po włączeniu
  backupu, demo Medium. Alternatywa: backup fizyczny (`pg_basebackup`/WAL archiving, np. pgBackRest/WAL-G) albo dump
  z repliki.
- Przebudowa klientów (`DbalCustomerSummaries.php:128–183`): krótka transakcja na partię zakresu – dobrze.
- Brak `idle_in_transaction_session_timeout` i `statement_timeout` – nic nie przerwie zawieszonej transakcji (§5).

### 4.5 Koszt zapisu jednego zamówienia (szacunek **[K]** ze schematu, L = 2 linie, klient zalogowany, bez kodu)

Transakcja składania:

| Operacja | Wiersze heap | Wpisy indeksów |
|---|---|---|
| `INSERT ordering_order` | 1 | 10 b-drzew (PK, number, idempotency, placed, status+placed, lower(email), klienta, total, status+total; częściowe refund/paid_at nie) + GIN trgm (~50–60 kluczy do listy oczekującej) |
| `INSERT ordering_order_line` × 2 | 2 | 4 × 2 = 8 |
| `INSERT ordering_order_status_change` | 1 | 2 |
| `INSERT inventory_reservation` + linie × 2 | 3 | 3 |
| `UPDATE inventory_stock_item` × 2 (nie-HOT) | 2 | 2 PK + ew. częściowe |
| `INSERT inventory_stock_movement` × 2 | 2 | 4 |
| `DELETE cart_line` × 2, `UPDATE cart_cart` (nie-HOT) | 1 nowa wersja | 3 (PK, partial customer, expires_at) |
| upsert `ordering_customer` (nie-HOT) | 1 | 5 b-drzew + 2 GIN (~40 kluczy) |
| `messenger_messages`: outbox (zdarzenia zamówienia i stanu) + `scheduled` | ~3 | ~6 |
| `nextval` | – | – |
| **Razem** | **~16–17 krotek** | **~43 wpisy b-drzew + ~100 kluczy GIN** |

Po COMMIT relay outboxu: na każdą wiadomość UPDATE + DELETE (×3). Cykl życia zamówienia po złożeniu: płatność
(INSERT `payment_payment` z 5 indeksami, `payment_webhook_event`, UPDATE płatności nie-HOT, UPDATE zamówienia nie-HOT
~11 wpisów + GIN, historia, sprzedaż (HOT), klient nie-HOT, rezerwacja (HOT), 2× stan nie-HOT, 2× ruch, powiadomienia),
potem wysyłka i doręczenie (po UPDATE zamówienia nie-HOT + historia + powiadomienie każde). Łącznie ok. **40–50 krotek
i 120–150 wpisów b-drzew plus kilkaset kluczy GIN na zamówienie** w całym cyklu **[H]**. Przy 50 mln zamówień to rząd
wielkości 2–2,5 mld krotek i ~7 mld wpisów indeksów zapisanych w historii systemu; przy szczycie np. 100 zamówień/s –
kilkanaście tysięcy wpisów indeksów/s i odpowiadający WAL z full-page writes po każdym checkpoincie.

Optimistic locking: `#[ORM\Version]` na zamówieniu, produkcie, kodzie, promocji, cenie (`OrderRecord.php:161`,
`ProductRecord.php:88`, `DiscountCodeRecord.php:72`, `PromotionRecord.php:53`, `VariantPriceRecord.php:39`); stan
magazynowy ma ręczne `version` + blokadę wiersza (`DoctrineStockItemRepository.php:148–161`). Spójne **[K]**.

## 5. Konfiguracja serwera i połączeń

### 5.1 Parametry Postgresa **[K]**

Nic nie jest ustawione poza domyślnymi: `infra/docker-compose.yml:12–26` (obraz `postgres:18.6`, bez `command:`,
bez `postgresql.conf`), `infra/postgres/init/10-create-databases.sh` (tylko role i bazy), chart
`infra/k8s/charts/shop/templates/postgres.yaml:1–31` (StatefulSet, 1 replika, bez ConfigMap konfiguracji), brak
`ALTER SYSTEM`/`ALTER DATABASE … SET`/`ALTER ROLE … SET` w migracjach i skryptach. Obowiązują więc domyślne PG 18:

| Parametr | Wartość | Ocena przy skali |
|---|---|---|
| `shared_buffers` | 128 MB | ułamek procenta danych (orders + indeksy rzędu dziesiątek GB **[H]**) |
| `effective_cache_size` | 4 GB | przy limicie poda 2 GiB planner przeszacowuje cache |
| `work_mem` | 4 MB | sorty top‑N OK; sort/haszowanie przy fragmencie i przebudowach na dysk **[H]** |
| `maintenance_work_mem` | 64 MB | `CREATE INDEX CONCURRENTLY` na 50 mln i vacuum dużych tabel wolne |
| `max_connections` | 100 | wspólne z Keycloakiem (§5.2) |
| `statement_timeout`, `lock_timeout`, `idle_in_transaction_session_timeout`, `transaction_timeout` (PG 17+) | 0 (wyłączone) | brak ochrony przed zawieszonym zapytaniem / czekaniem na gorący wiersz |
| `max_wal_size` / `checkpoint_timeout` | 1 GB / 5 min | częste checkpointy przy intensywnym zapisie i ładowaniu demo, wzmocnione FPW przy losowych UUID v5/v4 |
| `random_page_cost` | 4 | domyślny dla dysków obrotowych; na SSD/NVMe planner niedocenia index scanów |
| autovacuum | domyślne (scale 0.2, cost limit 200, 3 workery) | §4.3 |
| `log_min_duration_statement`, `pg_stat_statements` | brak | nie ma narzędzi do pomiaru zapytań w produkcji |

Zasoby poda: domyślnie `requests 250m/256Mi, limits 1Gi` (`infra/k8s/charts/shop/values.yaml:185`), w prod
`requests 500m/1Gi, limits 2Gi` (`infra/k8s/environments/prod/values.yaml:94`). Wolumen `storage.postgres: 10Gi`
(`values.yaml:162`, prod bez nadpisania) na `local-path` (rozmiar nieegzekwowany), przy `diskPeakGb` 100/225 dla
profili `server`/`full`. Skala **Critical**, demo **High** (10 mln produktów i 5 mln zamówień już nie mieszczą się
w pamięci podręcznej) **[K]** konfiguracja, **[H]** skutek.

### 5.2 Połączenia i pooling **[K]**

- Brak PgBouncera/Pgpool i brak połączeń trwałych: `config/packages/doctrine.yaml:2–9` (`pdo_pgsql`, bez
  `persistent`, bez `options`). FrankenPHP działa w trybie klasycznym (`php_server`, bez `worker`,
  `docker/frankenphp/Caddyfile:1–11`), więc **każde żądanie API otwiera nowe połączenie** (fork backendu Postgresa,
  uwierzytelnienie SCRAM) i zamyka je na końcu.
- Liczba połączeń: wątki PHP FrankenPHP (domyślnie `num_threads` = 2 × liczba CPU widoczna dla procesu) × repliki API
  (`replicas: 1`, `infra/k8s/charts/shop/templates/_helpers.tpl:244`) + 1 worker (`messenger:consume` 6 transportów,
  `templates/api.yaml:136`) + Joby (backfill, cart-purge, initialize) + **Keycloak na tym samym serwerze** (baza
  `keycloak`, `templates/keycloak.yaml:17–18`, bez `KC_DB_POOL_MAX_SIZE`; domyślny pool Keycloaka/Agroal ma maksimum
  100 **[H]**) + backup. Suma może przekroczyć `max_connections = 100` przy obciążeniu logowania.
- Przy 60 tys. aktywnych użytkowników (rzędu tysięcy żądań/s niecache’owanych przez Varnish **[H]**) koszt nawiązania
  połączenia (ms) dominuje nad krótkimi zapytaniami, a skalowanie API w poziomie mnoży połączenia. Skala **Critical**,
  demo **Medium**.
- Rekomendacja: PgBouncer w trybie `transaction` (uwaga: advisory locks sesyjne `pg_try_advisory_lock` w
  `PostgresSearchIndexRebuildLock.php:23` wymagają sesji – tylko komenda CLI, może iść bezpośrednio; transakcyjne
  `pg_advisory_xact_lock` są zgodne), osobny limit połączeń Keycloaka, albo osobny serwer dla Keycloaka.
  Alternatywnie tryb worker FrankenPHP z połączeniem na wątek.
- Timeouty: ustawić `statement_timeout` (np. 2–5 s dla roli API, dłuższy dla Jobów przez `SET` w sesji),
  `lock_timeout` (np. 1–2 s), `idle_in_transaction_session_timeout` (np. 30 s) przez `ALTER ROLE shop SET …` albo
  parametr połączenia. Spec DEMO-DATA wymienia brak `statement_timeout` jako znane ryzyko.

### 5.3 Rozdział odczytu i zapisu **[K]**

Jedno połączenie `default` (`doctrine.yaml:2–9`), bez `PrimaryReadReplicaConnection`, bez drugiego połączenia dla
raportów. Wszystkie odczyty panelu i sklepu idą na primary. Kod jest jednak dobrze przygotowany do rozdzielenia:
czytniki listy są osobnymi klasami `Dbal*Reader`/`*Source` na DBAL „bez blokad; nic tu nie pisze” (np.
`DbalBackofficeOrderReader.php:16–17`), więc wstrzyknięcie im połączenia do repliki jest zmianą konfiguracji usług.
Uwaga na odczyty, które muszą widzieć własny zapis (idempotencja `findByIdempotencyKey`, `lock`) – zostają na primary.

## 6. Skalowalność schematu

| Tabela | Wolumen | Partycjonowanie? | Archiwizacja/retencja |
|---|---|---|---|
| `inventory_stock_movement` | ~200 mln+ | **tak, najpierw**: RANGE po `occurred_at` (miesiąc/kwartał). PK `id` (v7) musiałby zawierać `occurred_at`; częściowy uniq `reference WHERE type='receipt'` musi zawierać klucz partycji albo przejść do osobnej tabeli przyjęć | starsze partycje odłączane/archiwizowane; historia wariantu czyta tylko świeże |
| `ordering_order_status_change`, `ordering_order_notification` | 100–200 mln | razem z zamówieniami albo po `order_id` (hash) – raczej nie teraz | powiadomienia po wysłaniu i upływie okresu ponowień można kasować (rola deduplikacji kończy się szybko) |
| `inventory_reservation` + `_line` | 50 + 95 mln | nie | zakończone rezerwacje (`committed`/`released`) starsze niż okres możliwych webhooków – do usunięcia; ruchy magazynowe są historią |
| `payment_webhook_event` | ~55 mln+ | nie | retencja okna deduplikacji (dni–tygodnie) |
| `ordering_order` | 50 mln | dziś **nie**: globalne uniq `number` i `idempotency_key` wymagałyby klucza partycji; 50 mln wierszy jest wykonalne bez partycji przy poprawnej konfiguracji serwera **[H]** | wydzielenie kluczy idempotencji do tabeli z TTL zmniejszyłoby największe uniq-indeksy; zamówienia zamknięte starsze niż N lat – archiwum |
| `pricing_price_history` | ~40–60 mln | nie | historia potrzebna do Omnibusa tylko z 30 dni + ostatni wpis przed oknem; starsze do archiwum |
| `catalog_product` / `catalog_product_variant` | 10 / 30 mln | nie | – |
| `messenger_messages` | chwilowa | nie | sprzątanie kolejki `failed` |

Repliki do odczytu: patrz §5.3 – kod jest gotowy, konfiguracji nie ma. Pojedyncza instancja Postgresa (StatefulSet
`replicas: 1`) jest też pojedynczym punktem awarii; to temat dostępności, nie wydajności.

## 7. Zbędne albo przedwczesne optymalizacje

- **Uzasadnione** (nie ruszać): `ordering_sales_hour` z 16 shardami (gorący wiersz godziny przy płatnościach),
  `ordering_customer` jako model odczytu (bez niego lista 1 mln klientów z agregatami z 50 mln zamówień byłaby
  niemożliwa), `BoundedCounter`, `reltuples` zamiast `count(*)`, częściowe indeksy draftów, bez zdjęć, stanów
  (wąskie, tanie).
- **Do rewizji po pomiarze użycia** (`pg_stat_user_indexes`):
  - `ordering_order_total_gross_id_idx` + `ordering_order_status_total_gross_id_idx` – dwa indeksy na 50 mln dla
    sortu panelu po kwocie;
  - GIN trgm na zamówieniach (największy indeks, aktualizowany przy każdej zmianie statusu) – Elasticsearch jest już
    w stosie;
  - 4 indeksy sortu + 2 GIN na `ordering_customer` – każdy sort to osobny indeks aktualizowany przy każdym zamówieniu;
    przy 1 mln wierszy sort `order_count`/`paid_gross` mógłby iść bez indeksu z top‑N po ograniczonym filtrze **[H]**;
  - `catalog_product_updated_at_id_idx` (pełny) – zmiana `updated_at` przy każdej edycji produktu czyni UPDATE
    nie-HOT; uzasadniony, jeśli sort po ostatniej zmianie jest domyślny w panelu.
- **Zdublowane indeksy** z §3.1 – zbędne bez wątpliwości.
- **Martwy kod** `DoctrineProductRepository::all()` (`:78–93`) – usunąć z interfejsu, zanim ktoś go użyje.
- **Denormalizacje w zamówieniu** (kopie nazw, cen, promocji w liniach) – wymagane biznesowo (niezmienność zamówienia),
  nie optymalizacja.

## 8. Lista znalezisk z wagą

| # | Znalezisko | Plik:linia | Typ | Skala | Demo |
|---|---|---|---|---|---|
| F1 | Postgres na domyślnej konfiguracji (128 MB `shared_buffers`, brak strojenia autovacuum/WAL, `random_page_cost=4`), limit poda 2 GiB, brak `pg_stat_statements` | `infra/docker-compose.yml:12–26`, `infra/k8s/charts/shop/templates/postgres.yaml:1–31`, `infra/k8s/environments/prod/values.yaml:94` | [K] konf., [H] skutek | Critical | High |
| F2 | Brak poolera i połączeń trwałych: nowe połączenie na każde żądanie API; `max_connections=100` wspólne z Keycloakiem (domyślny pool do 100) | `config/packages/doctrine.yaml:2–9`, `docker/frankenphp/Caddyfile:1–11`, `infra/k8s/charts/shop/templates/keycloak.yaml:17–18` | [K] / [H] Keycloak | Critical | Medium |
| F3 | Brak `statement_timeout`, `lock_timeout`, `idle_in_transaction_session_timeout` | jw. (brak ustawień w całym repo) | [K] | High | Medium |
| F4 | Blokada stanu popularnego wariantu trzymana od rezerwacji przez INSERT zamówienia (12 indeksów + GIN), upsert klienta, outbox do COMMIT | `Ordering/Application/Command/PlaceOrderHandler.php:134–142`, `Inventory/Infrastructure/Persistence/Doctrine/DoctrineStockItemRepository.php:53–56` | [K] mechanizm, [H] przepustowość | High | Low |
| F5 | Kod rabatowy z limitem: blokada wiersza na prawie całą transakcję składania + `count(*)` aktywnych użyć pod blokadą (O(n)); kod bez limitu: `FOR KEY SHARE` z wielu transakcji → MultiXact | `Pricing/Application/Command/RedeemDiscountCodeHandler.php:43–58`, `PlaceOrderHandler.php:130`, `Pricing/Infrastructure/Persistence/Doctrine/DoctrineDiscountCodeUsageRepository.php:24` | [K] / [H] | High | Low |
| F6 | `ordering_order`: 12 indeksów (w tym GIN trgm) i zmiana zaindeksowanego `status` przy każdym przejściu → żaden UPDATE nie jest HOT; ~11 b-drzew + GIN przy każdej zmianie statusu | migracje `…CreateOrderingTables:26–27`, `…AddBackofficeOrderListIndexes:20–26`, `…AddCustomerOrderListIndex:20`, `…IndexOrderTotals:23–24`, `…IndexOrderSearch:41`, `…IndexOrderPaidAt:40`; `DoctrineOrderRepository.php:117–136` | [K] | High | Low |
| F7 | Wyszukiwanie fragmentem zamówień `LIKE '%…%'` po GIN na 50 mln przy częstym fragmencie: bitmapa na miliony wierszy + sort, albo przejście indeksu daty z filtrem wyrażenia | `Ordering/Infrastructure/Persistence/Doctrine/DbalBackofficeOrderReader.php:40,78–80`, `migrations/Version20261115140000IndexOrderSearch.php:41` | [H] | High | Medium |
| F8 | Filtr kategorii listy produktów panelu `IN (…) OR EXISTS (…)` nie jest warunkiem indeksu; licznik `LIMIT 10001` dla małej kategorii skanuje cały katalog z SubPlan | `Catalog/Infrastructure/Persistence/Doctrine/DbalBackofficeProductSource.php:35–43,80–85` | [K] kształt, [H] plan | High | High |
| F9 | Lista kodów rabatowych: skorelowany `count(*)` aktywnych użyć na każdy wiersz (setki tys. użyć na kod) | `Pricing/Infrastructure/Persistence/Doctrine/DbalDiscountCodeReader.php:42–46`, `migrations/Version20261108190000CreateDiscountCodes.php:37` | [H] | High | Medium |
| F10 | Tabele rosnące bez sprzątania i bez partycji: ruchy magazynowe (~200 mln+), rezerwacje, historia statusów, powiadomienia, zdarzenia webhooków, kolejka `failed`, klucze idempotencji na zawsze w uniq-indeksach | §1, §6 | [K] | High | Low |
| F11 | Nie-HOT UPDATE gorących tabel: `inventory_stock_item` (kolumny predykatów indeksów częściowych), `ordering_customer` (7 indeksów, 2 GIN), `cart_cart` (`expires_at`); autovacuum domyślne | `migrations/Version20261115120100IndexStockLevels.php:22–23,42`, `Ordering/Infrastructure/Persistence/Doctrine/DbalCustomerSummaries.php:55–92`, `migrations/Version20261116090000CreateCustomers.php:28–31` | [K] / [H] bloat | Medium | Low |
| F12 | Długie transakcje wstrzymujące vacuum: partia reindeksu kategorii (500 reindeksów z HTTP w transakcji), `pg_dump` w backupie | `Catalog/Application/Command/ReindexCategoryBatchHandler.php:59–86`, `infra/k8s/charts/shop/files/scripts/backup.sh:46` | [K] | Medium (High po włączeniu backupu) | Medium |
| F13 | 6 zdublowanych indeksów (prefiks PK/uniq) na tabelach 100–200 mln wierszy | §3.1 | [K] | Medium | Low |
| F14 | `initialize.sh` liczy `count(*)` 4 największych tabel przy każdym wdrożeniu | `infra/k8s/charts/shop/files/scripts/initialize.sh:13` | [K] | Medium | Medium |
| F15 | Granice mapy strony: `row_number()` po 10 mln produktów co godzinę na każdy pod | `Catalog/Infrastructure/Persistence/Doctrine/DbalSitemapProductSource.php:29–35` | [K] | Medium | Low |
| F16 | Wyszukiwanie fragmentem klientów (BitmapOr 2× GIN na 1 mln, potem sort) | `Ordering/Infrastructure/Persistence/Doctrine/DbalCustomerReader.php:32,66–68` | [H] | Medium | Low |
| F17 | Brak rozdziału odczytu/zapisu (jedno połączenie), choć czytniki są gotowe do wstrzyknięcia repliki | `config/packages/doctrine.yaml:2–9` | [K] | Medium | Low |
| F18 | Martwe `DoctrineProductRepository::all()` ładujące cały katalog do pamięci | `Catalog/Infrastructure/Persistence/Doctrine/DoctrineProductRepository.php:78–93` | [K] | Medium (latentne) | Low |
| F19 | `count(*)` outboxu na pulpicie bez limitu | `Backoffice/Infrastructure/MessagingStatisticsAdapter.php:35` | [K] | Low/Medium | Low |
| F20 | Domyślna kolacja (en_US): `LIKE 'prefix%'` kodów bez indeksu, droższe sorty tekstu; ORM `#[ORM\Index]` niezgodne z migracjami (ryzyko `migrations:diff`) | `infra/postgres/init/10-create-databases.sh`, `DbalDiscountCodeReader.php:107`, `Ordering/Infrastructure/Persistence/Doctrine/Record/CustomerRecord.php:24` | [K] | Low | Low |
| F21 | `countAll` drzewa kategorii (~150 × ≤1001 wpisów) i `CategoryRepository::all()` przy każdym listingu sklepu | `DbalCategoryProductCounts.php:41–57`, `Catalog/Application/Query/ListProductsHandler.php:79` | [K] | Low | Low |

## Zapytania do EXPLAIN

Uruchamiać jako `EXPLAIN (ANALYZE, BUFFERS, SETTINGS)` na k3d z profilem `full` (50 mln zamówień) po `ANALYZE`,
dwukrotnie (zimny i ciepły cache), najlepiej także z `SET plan_cache_mode = force_generic_plan` tam, gdzie kod
używa parametrów. Zapytania z `FOR UPDATE` uruchamiać w `BEGIN … ROLLBACK`. Posortowane od najbardziej ryzykownego.

**E1. Fragment zamówień, częsty fragment** (`DbalBackofficeOrderReader.php:40,78–80`)
Parametr: `'gmail'`, potem `'kowal'`, potem rzadki `'xq7z'` (żeby zobaczyć obie gałęzie planera).
```sql
SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency,
       (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count,
       o.refund_amount, o.refund_due_at, o.refund_completed_at
FROM ordering_order o
WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('gmail') || '%'
ORDER BY o.placed_at DESC, o.id DESC
LIMIT 50;
```
Dobry: `Limit → Index Scan Backward using ordering_order_placed_at_id_idx` z filtrem i małą liczbą „Rows Removed by
Filter” (częsty fragment) albo `Bitmap Index Scan ordering_order_search_trgm_idx` z małą liczbą wierszy + top‑N
(rzadki). Zły: `Bitmap Heap Scan` z milionami `rows`, `Recheck`, `lossy` blokami, `Sort Method: top-N heapsort` po
milionach albo przejście indeksu daty z milionami odrzuconych wierszy dla rzadkiego fragmentu.

**E2. Licznik fragmentu zamówień** (`BoundedCounter.php:54–56`)
```sql
SELECT count(*) FROM (
  SELECT 1 FROM ordering_order o
  WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('xq7z') || '%'
  LIMIT 10001) bounded;
```
Parametr: rzadki fragment (< 10 001 trafień) i częsty. Dobry: Bitmap GIN z małym `Heap Blocks`. Zły: Seq Scan 50 mln
albo bitmapa z milionami kandydatów do rechecku.

**E3. Lista produktów panelu w małej kategorii** (`DbalBackofficeProductSource.php:35–43,69–71`)
`:prefix` = ścieżka liścia z najmniejszą liczbą produktów + `'%'`; powtórzyć dla działu z największą liczbą.
```sql
SELECT p.id, p.name, p.tax_class, p.updated_at
FROM catalog_product p
WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
       OR EXISTS (SELECT 1 FROM catalog_product_category pc
                  WHERE pc.product_id = p.id
                    AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)))
ORDER BY p.name ASC, p.id ASC
LIMIT 50;
```
Dobry: mało przeczytanych bloków dzięki szybkiemu znalezieniu 50 pasujących. Zły: `Index Scan using
catalog_product_name_id_idx` z `Filter: (hashed SubPlan … OR (alternatives: SubPlan …))` i milionami „Rows Removed by
Filter”.

**E4. Licznik listy produktów w małej kategorii** (`DbalBackofficeProductSource.php:80–85`)
```sql
SELECT count(*) FROM (
  SELECT 1 FROM catalog_product p
  WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)
         OR EXISTS (SELECT 1 FROM catalog_product_category pc
                    WHERE pc.product_id = p.id
                      AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :prefix)))
  LIMIT 10001) bounded;
```
Parametr jak E3 (liść z < 10 001 produktów). Dobry: dwa Index Scany (primary, additional) i suma. Zły: `Seq Scan on
catalog_product` (10 mln) z SubPlan na każdym wierszu.

**E5. Lista kodów rabatowych z liczbą aktywnych użyć** (`DbalDiscountCodeReader.php:42–46`)
```sql
SELECT c.id, c.code, c.kind, c.percent_off, c.amount_gross, c.minimum_order_gross, c.starts_at, c.ends_at,
       c.usage_limit, c.enabled, c.version, c.created_at,
       (SELECT count(*) FROM pricing_discount_code_usage u WHERE u.code_id = c.id AND u.released_at IS NULL) AS active_uses
FROM pricing_discount_code c
ORDER BY c.created_at DESC, c.id
LIMIT 50;
```
Dobry: `Index Only Scan using pricing_discount_code_usage_active_idx` z `Heap Fetches: 0`, łącznie ms. Zły:
setki tysięcy `Heap Fetches` na kod (nieaktualna mapa widoczności), sekundy na stronę.

**E6. Fragment klientów** (`DbalCustomerReader.php:32,66–68`)
Parametr: `'gmail'` (częsty), `'nowak'`, rzadki.
```sql
SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name
FROM ordering_customer c
WHERE (lower(c.email) LIKE '%' || lower('gmail') || '%' OR lower(c.full_name) LIKE '%' || lower('gmail') || '%')
ORDER BY c.last_order_at DESC, c.customer_id
LIMIT 50;
```
Dobry: `Index Scan using ordering_customer_last_order_at_idx` z filtrem (częsty) albo `BitmapOr` + top‑N (rzadki).
Zły: `BitmapOr` na ~1 mln wierszy + sort, `external merge` przy `work_mem` 4 MB.

**E7. Zamówienia: status + wąski zakres dat + sort po kwocie** (`DbalBackofficeOrderReader.php:46–52,78–80`)
Parametry: najczęstszy status (`'delivered'`), zakres ostatniej doby.
```sql
SELECT o.id, o.number, o.placed_at, o.status, o.total_gross
FROM ordering_order o
WHERE o.status = 'delivered' AND o.placed_at >= now() - interval '1 day' AND o.placed_at < now()
ORDER BY o.total_gross DESC, o.id DESC
LIMIT 50;
```
Dobry: Index Scan po `(status, placed_at…)` dla doby + top‑N sort. Zły: `Index Scan Backward using
ordering_order_status_total_gross_id_idx` z milionami odrzuconych przez filtr daty.

**E8. Ograniczony licznik dużego statusu – mapa widoczności** (`BoundedCounter.php:54–56` z `STATUS`)
```sql
SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status = 'paid' LIMIT 10001) bounded;
```
Dobry: `Index Only Scan using ordering_order_status_placed_at_id_idx`, `Heap Fetches` ≈ 0. Zły: `Heap Fetches` ≈ 10 001
(VM nieaktualna po falach UPDATE statusów) albo Seq Scan.

**E9. Aktywne użycia kodu z limitem (sekcja krytyczna składania)** (`DoctrineDiscountCodeUsageRepository.php:24`)
Parametr: `:code` = id kodu z największą liczbą użyć.
```sql
SELECT count(*) FROM pricing_discount_code_usage WHERE code_id = CAST(:code AS uuid) AND released_at IS NULL;
```
Dobry: Index Only Scan, `Heap Fetches` ≈ 0, < 1 ms. Zły: dziesiątki ms (każde składanie z tym kodem czeka tyle na
blokadzie kodu).

**E10. Pending outbox na pulpicie** (`MessagingStatisticsAdapter.php:35`)
Uruchomić także przy sztucznej zaległości (zatrzymany RabbitMQ).
```sql
SELECT count(*) FROM messenger_messages WHERE queue_name = 'outbox';
```
Dobry: Index Only Scan, mało buforów. Zły: liniowo z zaległością, dużo martwych krotek (`n_dead_tup`), heap fetch.

**E11. Pobranie wiadomości przez worker** (kształt zapytania transportu Doctrine Messengera – potwierdzić w
`pg_stat_statements`)
```sql
BEGIN;
SELECT m.* FROM messenger_messages m
WHERE m.queue_name = 'scheduled'
  AND (m.delivered_at IS NULL OR m.delivered_at < now() - interval '300 seconds')
  AND m.available_at <= now()
ORDER BY m.available_at ASC
LIMIT 1
FOR UPDATE SKIP LOCKED;
ROLLBACK;
```
Parametr: kolejka `scheduled` przy szczycie (tysiące wiadomości z przyszłym `available_at`). Dobry: Index Scan po
`(queue_name, available_at, …)` z kilkoma blokami. Zły: przejście przez tysiące martwych/zablokowanych krotek (po
długiej transakcji w bazie).

**E12. Granice mapy strony** (`DbalSitemapProductSource.php:29–35`)
```sql
SELECT id FROM (SELECT id, row_number() OVER (ORDER BY id) AS position FROM catalog_product) numbered
WHERE position > 1 AND (position - 1) % 10000 = 0
ORDER BY position;
```
Oczekiwany plan: `WindowAgg → Index Only Scan using catalog_product_pkey` po 10 mln. Dobry: `Heap Fetches` ≈ 0 i kilka
sekund. Zły: Seq Scan + Sort na dysku, dziesiątki sekund (powtarzane co godzinę na pod).

**E13. Stan bazy w `initialize.sh`** (`infra/k8s/charts/shop/files/scripts/initialize.sh:13`)
```sql
SELECT (SELECT count(*) FROM catalog_product), (SELECT count(*) FROM pricing_variant_price),
       (SELECT count(*) FROM inventory_stock_item), (SELECT count(*) FROM ordering_order);
```
Dobry: index-only scany (sekundy). Zły: minuty przy zimnym cache (każdy deploy). Docelowo: `EXISTS (SELECT 1 …)`.

**E14. Lista produktów panelu – 2-znakowy termin** (`DbalBackofficeProductSource.php:21–29`)
```sql
SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p
WHERE p.name ILIKE '%ko%'
ORDER BY p.name ASC, p.id ASC LIMIT 50;
```
oraz z rzadkim 3+-znakowym terminem (`'%xyzw%'`). Dobry: 2 znaki – `Index Scan using catalog_product_name_id_idx`
z filtrem, szybko kończy; rzadki termin – Bitmap GIN. Zły: rzadki termin przez przejście `(name, id)` po 10 mln.

**E15. Przebudowa klientów – agregacja partii** (`DbalCustomerSummaries.php:148–163`)
`:after`/`:last` = granice partii ~1000 klientów z `nextBatchEnd`.
```sql
SELECT o.customer_id, COUNT(*) AS order_count, COUNT(o.paid_at) AS paid_count,
       COALESCE(SUM(o.total_gross) FILTER (WHERE o.paid_at IS NOT NULL), 0) AS paid_gross,
       MIN(o.placed_at), MAX(o.placed_at)
FROM ordering_order o
WHERE o.owner_type = 'customer' AND o.customer_id > :after AND o.customer_id <= :last
GROUP BY o.customer_id;
```
Dobry: `Index Scan using ordering_order_customer_placed_at_id_idx` (zakres) + GroupAggregate. Zły: Seq Scan 50 mln
na każdą partię.

**E16. Przebudowa godziny sprzedaży** (`DbalSalesHours.php:100–112`)
```sql
SELECT (get_byte(uuid_send(id), 15) & 15) AS shard, COUNT(*), SUM(total_gross)
FROM ordering_order
WHERE paid_at >= :hour AND paid_at < :hour + interval '1 hour'
GROUP BY 1;
```
Parametr: godzina szczytu (Black Friday). Dobry: Bitmap/Index Scan `ordering_order_paid_at_idx`. Zły: Seq Scan.

**E17. Blokada stanów przy składaniu** (`DoctrineStockItemRepository.php:53–56`)
```sql
BEGIN;
SELECT variant_id, physical, reserved, version, updated_at FROM inventory_stock_item
WHERE variant_id = ANY(CAST(:ids AS uuid[])) ORDER BY variant_id FOR UPDATE;
ROLLBACK;
```
`:ids` = 4 warianty, w tym najczęściej zamawiany. Plan trywialny (Index Scan PK); celem jest pomiar
`pg_stat_user_tables.n_tup_hot_upd` vs `n_tup_upd` i czasu oczekiwania (`pg_stat_activity.wait_event`) w teście k6 na
jednym wariancie.

**E18. Omnibus dla 1000 wariantów promocji** (`DbalOmnibusHistory.php:43–51`)
`:ids` = 1000 wariantów największej promocji, `:froms` = start − 30 dni, `:untils` = start.
```sql
SELECT w.variant_id, h.changed_at, h.gross_amount, h.currency
FROM unnest(CAST(:ids AS uuid[]), CAST(:froms AS timestamptz[]), CAST(:untils AS timestamptz[])) AS w(variant_id, window_start, starts_at)
CROSS JOIN LATERAL (
  (SELECT changed_at, id, gross_amount, currency FROM pricing_price_history
   WHERE variant_id = w.variant_id AND changed_at < w.window_start ORDER BY changed_at DESC, id DESC LIMIT 1)
  UNION ALL
  (SELECT changed_at, id, gross_amount, currency FROM pricing_price_history
   WHERE variant_id = w.variant_id AND changed_at >= w.window_start AND changed_at < w.starts_at)
) h
ORDER BY w.variant_id, h.changed_at, h.id;
```
Dobry: Nested Loop z 2000 Index Scanami po `(variant_id, changed_at DESC, id)`. Zły: Seq Scan historii.

**E19. Produkty bez stanu – strona i licznik** (`DbalStockLevelProducts.php:35–45,58–62`)
```sql
SELECT product_id FROM inventory_stock_item
WHERE physical - reserved = 0 AND product_id IS NOT NULL
GROUP BY product_id ORDER BY product_id LIMIT 50;
```
Dobry: `Index Only Scan using inventory_stock_item_out_of_stock_idx` + Group, mało heap fetch. Zły: Seq Scan 30 mln
(predykat nieudowodniony) albo dużo heap fetch po gorących UPDATE.

**E20. Aktywne promocje wariantów koszyka** (`DbalActivePromotions.php:33–42`)
```sql
SELECT pv.variant_id, p.id, p.name, p.percent_off, p.starts_at, p.ends_at
FROM pricing_promotion_variant pv JOIN pricing_promotion p ON p.id = pv.promotion_id
WHERE pv.variant_id = ANY(CAST(:ids AS uuid[]))
  AND p.starts_at <= now() AND p.ends_at > now();
```
`:ids` = 10 wariantów, w tym wariant z największą liczbą historycznych promocji. Dobry: Nested Loop, Index Scan po
`variant_id` + PK promocji. Zły: Seq Scan `pricing_promotion_variant`.

**E21. Strona źródła indeksu wyszukiwarki** (`DbalProductSearchSource.php:22–59`, `pageSql(true)`)
`:after` = id ze środka zakresu, `:limit` = 500. Wkleić `PAGE_SQL` z `WHERE id > CAST(:after AS uuid)`.
Dobry: `Limit → Index Scan using catalog_product_pkey` + 3 × Nested Loop po indeksach, < 100 ms na stronę. Zły: sort
po `id` na dysku albo Seq Scan w LATERAL nazw przodków.

**E22. Liczniki drzewa kategorii** (`DbalCategoryProductCounts.php:41–46`)
```sql
SELECT c.id, (SELECT count(*) FROM (
    SELECT 1 FROM catalog_product p WHERE p.primary_category_id = c.id
    UNION ALL SELECT 1 FROM catalog_product_category pc WHERE pc.category_id = c.id
    LIMIT 1001) t) AS products
FROM catalog_category c;
```
Dobry: ~150 × 2 Index Only Scany, < 1 s. Zły: Seq Scany w SubPlan.

## Uwagi do pomiaru

- Włączyć `pg_stat_statements`, `log_min_duration_statement = 200ms`, `log_lock_waits = on`, `track_io_timing = on`
  na k3d przed testem k6, inaczej F4/F5/F6/F11 nie da się zmierzyć.
- Po teście: `pg_stat_user_tables` (`n_tup_upd`, `n_tup_hot_upd`, `n_dead_tup`, `last_autovacuum`),
  `pg_stat_user_indexes` (`idx_scan` – kandydaci do usunięcia z §3.2), rozmiary `pg_relation_size`/`pg_indexes_size`
  dla `ordering_order` i GIN trgm, `pg_stat_slru` (multixact, F5).
