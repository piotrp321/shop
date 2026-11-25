# Audyt PostgreSQL – ustalenia

Rewizja: main 2b4258c7. Stos pomiarowy: `shop-perf25-postgres-1` (PostgreSQL 18.6, dane medium: 1 009 581 produktów,
3 009 116 wariantów, 1 000 066 zamówień, 98 568 klientów z zamówieniami; baza `shop` 8 609 MB, `keycloak` 140 MB).
Wszystkie pomiary w sesji `default_transaction_read_only = on`, `statement_timeout = 60s`, użytkownik `postgres`; każde
zapytanie 3× `EXPLAIN (ANALYZE, BUFFERS)` w jednej sesji, czasy w kolejności 1./2./3. przebiegu (1. = zimniejszy cache),
bufory z ostatniego przebiegu. Surowe plany: `scratchpad/results/pg/<nazwa>.txt` (pełna ścieżka katalogu:
`docs/reports/performance-audit-2026-11-25/results/pg/`),
indeksy z rozmiarami: `indexes.txt`, ustawienia: `settings.txt`. Skrypty pomocnicze: `scratchpad/bin/q.sh`, `x.sh`.

Zastrzeżenia pomiaru:
- Stos był w tym czasie używany przez sesję prowadzącą (widoczne nowe połączenia `shop`), więc czasy mogą być lekko
  zawyżone; liczby buforów są wiarygodne.
- Liczniki `pg_stat_user_*` nigdy nie były resetowane, a na stosie biegł głównie generator danych, więc `idx_scan = 0`
  nie dowodzi, że indeks jest zbędny w ruchu sklepu. Wnioski o zbędnych indeksach opieram na definicjach (prefiksy).
- Lokalny Postgres ma page cache hosta (Docker Desktop), więc „read” to zwykle odczyt z pamięci systemu, nie z dysku.
  Na serwerze z limitem 2 GiB tak nie będzie (patrz K1).

## Streszczenie

Ścieżki sklepu (strona produktu, oferty listingu, koszyk, składanie zamówienia, płatność, historia zamówień klienta,
wygaszanie) są w Postgresie dobrze zaprojektowane: jedno zapytanie na krok, `= ANY(array)` zamiast N+1, keyset zamiast
OFFSET, liczniki ograniczone (`BoundedCounter`), indeksy pod każde sortowanie. Zmierzone czasy to dziesiąte części
milisekundy do kilku milisekund i rosną logarytmicznie z danymi. **Koszt zapytań sklepu nie tłumaczy błędów 500/503
przy 8 zimnych wejściach na listing.** Postgres po stronie sklepu ma natomiast trzy problemy, które przy profilu
`server` (10 mln produktów / 5 mln zamówień / 1 mln klientów, który już jest ustawiony w `environments/prod`) wyjdą
na wierzch: (1) konfiguracja i pamięć – domyślny `postgresql.conf` (shared_buffers 128 MB) i limit 2 GiB wobec bazy
~70 GB, (2) brak jakichkolwiek limitów czasu (`statement_timeout`, `idle_in_transaction_session_timeout`, `lock_timeout`),
(3) model połączeń (połączenie na żądanie, `max_connections = 100` wspólne z Keycloakiem, którego pula domyślnie
sięga 100). Najgorsze zapytania są w panelu: filtr kategorii listy produktów/cen/stanów (2–4 s przy 1 mln, liniowo
→ 20–40 s przy 10 mln) i krótkie frazy (0,3–0,85 s → 3–8 s), a bez `statement_timeout` taki request zajmuje wątek
PHP współdzielony ze sklepem.

## Tabela pomiarów

| # | Zapytanie | Ścieżka | Plik:linia (apps/api/src/…) | Czas medium (ms, 3×) | Bufory | Plan | Prognoza 10 mln / 5 mln | Waga |
|---|---|---|---|---|---|---|---|---|
| 1 | produkt po slug | PDP | Catalog/Infrastructure/Persistence/Doctrine/DbalProductDetailsSource.php:26 | 0.57/0.08/0.13 | 9 | Index Scan `slug_key` + pkey kategorii | log; ~+1 poziom B-tree; na zimno 3–5 odczytów z dysku | OK |
| 2 | warianty produktu | PDP | DbalProductDetailsSource.php:39 | 0.09/0.02/0.03 | 4 | Index Scan `(product_id, position)` | stały | OK |
| 3 | warianty 48 produktów | `GET /product-offers` (listing) | Catalog/…/DbalVariantSource.php:83 | 2.5/0.8/0.4 | 392 | Nested Loop po pkey i `(product_id, position)` | log; ~150–400 losowych stron na zimno | OK |
| 4 | ceny 144 wariantów | product-offers, koszyk, wycena | Pricing/…/DoctrineVariantPriceRepository.php:56 | 1.8/0.35/0.6 | 493 | Index Scan pkey | log | OK |
| 5 | aktywne promocje 144 wariantów | product-offers | Pricing/…/DbalActivePromotions.php:33 | 1.2/0.8/1.0 | 61 | indeksy `variant_id` | zależy od liczby promocji (≤1000 wariantów/promocję) | OK |
| 6 | Omnibus: historia cen 144 wariantów | product-offers | Pricing/…/DbalOmnibusHistory.php:43 | 2.7/0.6/0.6 | 1032 | LATERAL po `(variant_id, changed_at DESC, id)` | log; rośnie z liczbą zmian ceny na wariant w 30 dniach | OK |
| 7 | Omnibus: promocje 144 wariantów | product-offers | DbalOmnibusHistory.php:72 | 1.5/0.8/0.8 | 57 | indeksy | j.w. | OK |
| 8 | zamówienia klienta (430 zamówień) | `/me/orders` | Ordering/…/DbalCustomerOrderReader.php:56 | 0.19/0.06/0.05 | 23 | Index Scan częściowy `(customer_id, placed_at DESC, id DESC)` | stały | OK |
| 9 | panel produkty, domyślnie | admin | Catalog/…/DbalBackofficeProductSource.php:69 | 0.07/0.02/0.02 | 39 | Index Scan `(name, id)` | log | OK |
| 10 | panel produkty, fraza 2 znaki rzadka `qx` | admin (produkty, ceny, stany) | DbalBackofficeProductSource.php:29,69 | 324/352/418 | 669 k | Index Scan całego `(name, id)` z filtrem ILIKE | liniowo: ~3,5–4 s | Średnia |
| 11 | licznik dla `qx` | admin | DbalBackofficeProductSource.php:84 → Shared/Infrastructure/Persistence/BoundedCounter.php | 847/584/439 | 669 k | Parallel Index Only Scan całego `(name, id)` | liniowo: ~4–8 s | Średnia |
| 12 | panel produkty, fraza 3 znaki bez trigramu `#!#` | admin | j.w. | 362/348/398 | 669 k | j.w. (pg_trgm nie wyciąga trigramów z interpunkcji) | liniowo ~4 s | Średnia |
| 13 | panel produkty, fraza 3+ znaki zwykła (`tur`, `zzqq`) | admin | j.w. | 0.02–4 | ≤2,6 k | GIN trigram / walk `(name,id)` | log/stały | OK |
| 14 | **panel produkty, kategoria z 4 produktami** | admin (produkty, ceny, stany: Pricing/Inventory `QueryBusProductDirectory` → `SearchBackofficeProducts`) | DbalBackofficeProductSource.php:35–43,69 | **2157/4090/3512** | **1 014 k (825 k read)** | Index Scan `(name, id)` całej tabeli, filtr `OR` z hashed SubPlan, 1 000 187 wierszy odrzuconych, estymata 757 186 wierszy zamiast 4 | liniowo: **20–40 s**, ~8 GB odczytu z dysku przy 2 GiB RAM | **Wysoka** |
| 15 | licznik, kategoria z 4 produktami | admin | DbalBackofficeProductSource.php:84 | 841/625/624 | 125 k | j.w. | liniowo: 6–8 s | Wysoka (razem z 14) |
| 16 | panel produkty, kategoria z 4 prod., sort `updatedAt` | admin | :69 | 957/1138/862 | 996 k | Index Scan `(updated_at, id)` całej tabeli | liniowo: ~10 s | Wysoka (razem z 14) |
| 17 | panel produkty, duży liść / korzeń | admin | :69 | 22/4.7/5.6; korzeń 5.2/0.15/0.11 | ≤5 k | walk `(name,id)` szybko trafia 50 | stały | OK |
| 18 | (propozycja) kategoria jako zbiór id + sort | – | – | mała: 18/17/8.7; **korzeń: 5176/10466/8135** | korzeń 954 k + temp | HashAggregate zbioru id, Sort | pokazuje, że prosta zamiana jednego planu na drugi nie wystarczy | – |
| 19 | panel zamówienia, domyślnie | admin | Ordering/…/DbalBackofficeOrderReader.php:78 | 0.23/0.14/0.11 | 209 | Index Scan `(placed_at DESC, id DESC)` | log | OK |
| 20 | zamówienia: dzień + sort po kwocie | admin | :78 | 3.9/0.45/0.41 | 274 | indeks `placed_at` + top-N sort | ∝ zamówień/dzień | OK |
| 21 | zamówienia: fraza zwykła `kowal`/`marek`+miesiąc | admin | :40,78 | 2.7–8.6 | ≤1,4 k | GIN trigram + BitmapAnd | log | OK |
| 22 | licznik zamówień, fraza `kowal` | admin | :89 | 70/32/30 | 8,4 k | bitmapa trigramów | ∝ trafień, ograniczone 10 001 | OK |
| 23 | zamówienia: fraza 3 znaki bez trigramu `#!#` | admin | :40,78 | 331/394/408 | 56 k | Index Scan całego `(placed_at, id)` z filtrem | liniowo: ~2 s przy 5 mln | Niska–Średnia |
| 24 | licznik zamówień `#!#` | admin | :89 | 400/398/402 | 56 k | j.w. | ~2 s | Niska–Średnia |
| 25 | zamówienia: status + sort po kwocie | admin | :78 | 0.29–1.7 | ≤270 | `(status, total_gross, id)` | log | OK |
| 26 | klienci: fraza pasująca do wszystkich (`example`) | admin | Ordering/…/DbalCustomerReader.php:32,67 | 0.45/0.14/0.10 | 54 | walk `(last_order_at DESC)` z filtrem | stały (trafia szybko) | OK |
| 27 | licznik klientów `example` | admin | DbalCustomerReader.php:77 | 10/8.7/8.5 | 462 | bitmapa, LIMIT 10 001 | stały | OK |
| 28 | klienci: fraza `#!#` | admin | DbalCustomerReader.php:32,67 | 52/40/40 | 3,7 k | pełny walk 98 k | liniowo: ~0,4 s przy 1 mln | Niska |
| 29 | stany: brak w magazynie, strona | admin | Inventory/…/DbalStockLevelProducts.php | 0.07/0.03/0.03 | 4 | indeks częściowy | stały | OK |
| 30 | stany: licznik niski stan | admin, dashboard | DbalStockLevelProducts.php (count) | 6.4/2.7/3.0 | 52 | indeks częściowy, LIMIT | ograniczone | OK |
| 31 | drzewo kategorii z licznikami (180 kat.) | admin | Catalog/…/DbalCategoryProductCounts.php:43 | 68/48/53 | 1,8 k | 180 × ograniczony licznik (≤1001) | ∝ liczbie kategorii, nie produktów | OK |
| 32 | reindeks: strona 500 produktów | `catalog:reindex` | Catalog/…/DbalProductSearchSource.php:22–59 | 53/44/45 | 11 k | keyset po pkey, LATERAL; **Seq Scan `catalog_category` 3× na produkt** | 10 mln: 20 000 stron × 45 ms ≈ 15 min czasu bazy; przy tysiącach kategorii koszt ∝ produkty × kategorie | Niska (Średnia przy dużym drzewie) |
| 33 | mapa strony: granice chunków | `GET /sitemap/product-chunks` | Catalog/…/DbalSitemapProductSource.php:29 | 1307/615/554 | 663 k | Index Only Scan całego pkey + WindowAgg | liniowo: 6–13 s na każde zimne odświeżenie (tag `CATALOG`) | Średnia |
| 34 | mapa strony: chunk | `GET /sitemap/products` | DbalSitemapProductSource.php:58 | 36/7.8/6.0 | 3,9 k | Index Scan pkey, zakres | ograniczony `row_limit` 50 000 | OK |

Nie zmierzono (brak danych na medium albo zapis): koszyk (`cart_cart`/`cart_line` puste), wygaszanie (brak zamówień
`new`), transakcja składania zamówienia (zapisy) – ocena z kodu niżej.

## Znaleziska

### K1. Postgres działa na domyślnej konfiguracji, a limit pamięci w prod to 2 GiB wobec bazy ~70 GB przy profilu `server` — Krytyczna

- Dowód: [POMIAR] `pg_settings` na stosie: `shared_buffers = 16384 × 8 kB = 128 MB`, `work_mem = 4 MB`,
  `effective_cache_size = 4 GB` (domyślne), `maintenance_work_mem = 64 MB`, `max_wal_size = 1 GB`, `random_page_cost = 4`,
  `jit = on`, `max_connections = 100`, `track_io_timing = off`, brak `pg_stat_statements` (`settings.txt`). [KOD]
  `infra/docker-compose.yml:12–26` i `infra/k8s/charts/shop/templates/postgres.yaml` nie przekazują żadnych parametrów
  (brak `command`/`args`, brak ConfigMapy z `postgresql.conf`); `infra/k8s/environments/prod/values.yaml:114`:
  `postgres: { requests: { cpu: 500m, memory: 1Gi }, limits: { memory: 2Gi } }`; `infra/k8s/environments/prod/values.yaml`
  ustawia `demoData.profile: server` (10 mln produktów, 5 mln zamówień, 1 mln klientów – `apps/api/fixtures/demo-data/profiles.yaml`).
- [SZACUNEK] rozmiar bazy dla `server` z rozmiarów medium (tabela+indeksy): część katalogowa (product 1584 MB, variant
  771, variant_price 387, price_history 914, stock_item 382, stock_movement 1088, product_category 34) ≈ 5,2 GB × 10 ≈
  52 GB; część zamówień (order 1191, line 814, status_change 768, payment 408, rezerwacje 72, usage 14) ≈ 3,3 GB × 5 ≈
  16 GB; klienci 133 MB × 10 ≈ 1,3 GB. **Razem ≈ 70 GB, w tym ≈ 24 GB samych indeksów katalogu.** Indeksy „gorące” dla
  sklepu (pkey i slug produktu, pkey i `(product_id, position)` wariantu, pkey ceny, pkey stanu, historia cen) to ok.
  950 MB na medium → ≈ 9,5 GB przy 10 mln.
- Przyczyna: konfiguracja + ograniczenie sprzętu (rozdział pamięci węzła 24 GB: ES 4 GiB, Keycloak 2 GiB, API 2 GiB,
  store 1,5 GiB, Postgres 2 GiB). W kontenerze page cache jest liczony do limitu cgroup, więc Postgres ma realnie
  ≤ ~1,8 GB łącznie na shared_buffers i cache plików. Losowe wejścia na 10 mln produktów (boty, crawlery mapy strony,
  zimne strony produktów) będą prawie zawsze czytać z dysku; operacje masowe (reindeks, generator, `pg_dump` kopii)
  wypierają cały cache.
- Wzrost: koszt pojedynczego zapytania log, ale odsetek trafień w cache spada liniowo z rozmiarem danych przy stałej
  pamięci; ze współbieżnością rośnie kolejka I/O.
- Rekomendacja (S): przekazać parametry przez `args: ["-c", "shared_buffers=…", …]` w chartcie i `command` w compose
  (bez nowej usługi): `shared_buffers` ≈ 25% limitu, `effective_cache_size` ≈ 75% limitu, `work_mem` 8–16 MB,
  `maintenance_work_mem` 256–512 MB, `max_wal_size` 4–8 GB (generator, reindeks), `random_page_cost = 1.1` (SSD/NVMe),
  `track_io_timing = on`, `shared_preload_libraries = pg_stat_statements`, `log_min_duration_statement = 500ms`,
  `log_lock_waits = on`. (https://www.postgresql.org/docs/18/runtime-config-resource.html,
  https://wiki.postgresql.org/wiki/Tuning_Your_PostgreSQL_Server). Decyzja sprzętowa dla właściciela (M): przy 10 mln
  produktów Postgres jest źródłem prawdy i powinien mieć więcej pamięci niż ES/Keycloak; sensowny punkt wyjścia to
  6–8 GiB limitu (np. kosztem zmniejszenia `requests/limits` innych usług albo profilu danych). Bez tego nie twierdzić,
  że baza „mieści się”.
- Efekt: dla gorących indeksów sklepu trafienia w pamięć zamiast I/O; szybsze COPY/CREATE INDEX/VACUUM.
- Pomiar potwierdzający: na medium z limitem 2 GiB (stos k8s `load` ma te same zasoby) po restarcie Postgresa
  wykonać 10 000 losowych odczytów PDP (zapytania #1–#2) i sprawdzić
  `SELECT blks_read, blks_hit FROM pg_stat_database WHERE datname='shop'` przed/po oraz `pg_statio_user_indexes` dla
  `catalog_product_slug_key`; z `track_io_timing = on` – `blk_read_time`.

### K2. Brak `statement_timeout`, `idle_in_transaction_session_timeout` i `lock_timeout` — Wysoka

- Dowód: [POMIAR] wszystkie trzy = 0 (`settings.txt`); [KOD] `apps/api/config/packages/doctrine.yaml` nie ustawia opcji
  sesji; `apps/api/docker/frankenphp/Caddyfile` – `php_server` bez trybu worker i bez `num_threads` (domyślnie 2× liczba
  CPU widzianych przez proces). Przerwanie żądania po stronie PHP/klienta nie przerywa zapytania w Postgresie.
- Skutek: zapytania panelu #10–#16 i #33 trwają 0,5–4 s przy 1 mln i będą trwały 5–40 s przy 10 mln. Każde zajmuje
  wątek PHP tej samej puli, która obsługuje sklep (jedna replika `api`), i backend Postgresa. Kilka równoległych
  odświeżeń listy produktów z filtrem kategorii albo jedno zimne odświeżenie mapy strony przez crawlera wystarczy, żeby
  wyczerpać wątki API → 503/timeouty także w sklepie. Brak `idle_in_transaction_session_timeout` oznacza, że proces
  zabity w środku transakcji składania zamówienia (blokady `FOR UPDATE` na koszyku, kodzie rabatowym i stanach) może
  trzymać blokady do zerwania TCP.
- Przyczyna: konfiguracja.
- Rekomendacja (S): `ALTER ROLE shop SET statement_timeout = '5s'` (albo w DSN/`options` Doctrine `-c statement_timeout=…`),
  dłuższy wyjątek tylko dla komend konsoli (reindeks, backfill, generator – `SET statement_timeout` na początku komendy
  albo osobna rola); `idle_in_transaction_session_timeout = '60s'`; `lock_timeout` w migracjach (np. `SET lock_timeout = '5s'`).
  Dla ról `keycloak` własne wartości. (https://www.postgresql.org/docs/18/runtime-config-client.html)
- Pomiar: przy włączonym `log_min_duration_statement` przejść panel z filtrem małej kategorii (zapytanie #14) i zmierzyć
  zajętość wątków FrankenPHP (`/metrics` FrankenPHP: `frankenphp_busy_threads`) w trakcie 8 równoległych takich żądań
  i równoległego listingu sklepu.

### K3. Model połączeń: połączenie na żądanie, `max_connections = 100` wspólne z Keycloakiem — Wysoka [HIPOTEZA + KOD]

- Dowód: [KOD] `doctrine.yaml` – brak `persistent`, brak puli; FrankenPHP bez trybu worker, więc każde żądanie API
  otwiera nowe połączenie TCP + uwierzytelnienie SCRAM + fork backendu Postgresa i zamyka je na końcu. Workery
  messengera (`worker`, `worker-commands`), CronJoby (order-expiry, queue-check, rate-limit-check, reindex, backup) też
  się łączą. Keycloak (`infra/k8s/charts/shop/templates/keycloak.yaml:15–19`) nie ustawia `KC_DB_POOL_MAX_SIZE` –
  domyślnie 100 (https://www.keycloak.org/server/db, „db-pool-max-size”). [POMIAR] `max_connections = 100`, w trakcie
  audytu widoczne 9 backendów w stanie `starting` (nawiązywanie połączeń) przy 12 połączeniach `shop`.
- Skutek: suma potencjalnych połączeń (16+ wątków API na replikę + 2 workery + joby + do 100 Keycloaka) przekracza
  100; przy burzy logowań/odświeżeń tokenów Keycloak może zająć pulę i API dostaje `FATAL: sorry, too many clients
  already` → HTTP 500. Fork backendu (~1–3 ms CPU i kilka MB RSS na połączenie) przy każdym żądaniu to stały narzut
  rosnący liniowo z RPS, w kontenerze z limitem 2 GiB.
- Rekomendacja (S): ustawić `KC_DB_POOL_MAX_SIZE` (np. 10–20) i `KC_DB_POOL_MIN_SIZE`; jawnie policzyć budżet
  połączeń w `values.yaml` (wątki FrankenPHP × repliki + workery + joby + Keycloak + rezerwa ≤ `max_connections` −
  `superuser_reserved_connections`); ustawić `num_threads` FrankenPHP jawnie. PgBouncer (transaction pooling)
  dopiero gdy pomiar pokaże, że koszt nawiązywania połączeń jest istotny albo potrzeba wielu replik API – przy jednej
  replice i ~16 wątkach nie jest potrzebny. Alternatywa bez nowej usługi: tryb worker FrankenPHP z trwałym połączeniem
  (większa zmiana – stan między żądaniami; M).
- Pomiar: w trakcie testu 8 zimnych listingów co 0,5 s `SELECT usename, state, count(*) FROM pg_stat_activity GROUP BY 1,2`
  i `grep -c 'too many clients' ` w logu Postgresa; osobno `pgbench -C -S` vs `pgbench -S` (koszt połączenia) na kopii.

### K4. Filtr kategorii w listach panelu (produkty, ceny, stany) – pełny przegląd indeksu sortowania — Wysoka

- Dowód: [POMIAR] #14–#16: kategoria z 4 produktami → 2,2–4,1 s, 1 014 k buforów (825 k spoza shared_buffers),
  `Rows Removed by Filter: 1 000 187`; licznik 0,6–0,8 s; sort po `updated_at` ~1 s. [KOD]
  `Catalog/Infrastructure/Persistence/Doctrine/DbalBackofficeProductSource.php:35–43` (warunek
  `primary_category_id IN (…) OR EXISTS (…)`) i `:69–71` (ORDER BY name/updated_at, id). Ten sam źródłowy SQL obsługuje
  listę cen (`Pricing/Infrastructure/Catalog/QueryBusProductDirectory.php:48`) i stanów
  (`Inventory/Infrastructure/Catalog/QueryBusProductDirectory.php:46`).
- Przyczyna: błąd kodu/zapytania. Planer nie umie oszacować selektywności `OR` dwóch hashed SubPlanów (estymata
  757 186 wierszy) i wybiera przegląd indeksu sortowania z filtrem; dla rzadkiej kategorii czyta całą tabelę.
  Odwrotny plan (zbiór id kategorii, potem sort) jest szybki dla małej kategorii (18 ms), ale dla korzenia trwa 5–10 s
  (#18), więc nie wystarczy „przepisać na IN/UNION”.
- Wzrost: liniowy z liczbą produktów (najgorszy przypadek), stały dla dużych kategorii.
- Rekomendacja (M): wybór strategii po rozmiarze kategorii – najpierw ograniczony licznik po indeksach kategorii
  (`primary_category_id` + `catalog_product_category(category_id)` z LIMIT np. 2 000, jak `DbalCategoryProductCounts`),
  dla małego wyniku plan „zbiór id → sort”, dla dużego obecny walk po `(name, id)`. Alternatywa: obsłużyć filtr
  kategorii w panelu z Elasticsearch (indeks ma już ścieżki kategorii i szkice) – większa zmiana kontraktu.
- Efekt: 2–4 s → ~20 ms dla małych kategorii; przy 10 mln usuwa przypadki 20–40 s.
- Pomiar: zapytania z `results/pg/adm_prod_cat_4products.txt` i `rewrite_cat_4products.txt` po zmianie;
  dla pewności także kategoria średnia (~1 000–5 000 produktów).

### K5. Krótkie i „bez trigramów” frazy wyszukiwania w panelu – pełny skan — Średnia

- Dowód: [POMIAR] #10–#12 (produkty: fraza 2 znaki `qx` albo 3 znaki interpunkcji `#!#`: strona 0,32–0,42 s + licznik
  0,44–0,85 s, 669 k buforów), #23–#24 (zamówienia, `#!#`: 0,33–0,41 s + 0,40 s), #28 (klienci `#!#`: 40–52 ms przy
  98 k). [KOD] `SearchBackofficeProducts::MIN_TERM_LENGTH = 2` (Catalog/Application/Query/SearchBackofficeProducts.php:13);
  komentarz w DbalBackofficeProductSource.php:21–22 wprost godzi się na skan dla 2 znaków; zamówienia i klienci mają
  minimum 3 znaki (`OrderSearchTerm::MIN_FRAGMENT_LENGTH`), ale fraza złożona ze znaków niebędących literą/cyfrą nie
  daje trigramów (https://www.postgresql.org/docs/18/pgtrgm.html, „Index Support”).
- Przyczyna: ograniczenie pg_trgm + reguła walidacji.
- Wzrost: liniowy (produkty 10 mln: ~4–8 s na żądanie; zamówienia 5 mln: ~2 s).
- Rekomendacja (S): wymagać w frazie co najmniej 3 znaków alfanumerycznych (albo dla 2 znaków dopasowanie prefiksowe
  `name ILIKE 'qx%'`, które obsłuży indeks `(name, id)` po dodaniu `text_pattern_ops`/`lower`); razem z K2 ogranicza to
  najgorszy przypadek.
- Pomiar: `results/pg/adm_prod_term2_rare.txt`, `adm_ord_fragment3_punct_none.txt` po zmianie walidacji (oczekiwane 400).

### K6. Granice chunków mapy strony liczone funkcją okna po całej tabeli — Średnia

- Dowód: [POMIAR] #33: 0,55–1,3 s, Index Only Scan 1 000 191 wierszy + WindowAgg. [KOD]
  `Catalog/Infrastructure/Persistence/Doctrine/DbalSitemapProductSource.php:29–35`; odpowiedź
  `Catalog/UI/Http/SitemapProductChunksController.php` ma `s-maxage=3600` i tag `CATALOG`.
- Skutek: przy 10 mln 6–13 s na każde zimne przeliczenie (po purge tagu `CATALOG` albo po godzinie), w publicznym
  endpoincie bez `statement_timeout` – crawler może to wywołać równolegle (cache stampede).
- Przyczyna: algorytm (liczenie pozycji wszystkich wierszy, żeby wybrać co 10 000.).
- Rekomendacja (S): granice iteracyjnie keysetem (`SELECT id FROM catalog_product WHERE id > :prev ORDER BY id OFFSET 9999 LIMIT 1`
  – nadal czyta indeks, ale bez WindowAgg i można przerwać/rozłożyć), albo – prościej i stałym kosztem – zapisywać
  granice w tabeli przy reindeksie/generatorze i tylko je odczytywać; sprawdzić, czy purge tagu `CATALOG` przy każdej
  zmianie produktu nie unieważnia tej odpowiedzi.
- Pomiar: czas #33 z `log_min_duration_statement` po purge `CATALOG` i 4 równoległych żądaniach.

### K7. Wzmocnienie zapisów: aktualizacje bez HOT na gorących tabelach — Średnia

- Dowód: [POMIAR] `pg_stat_user_tables`: `inventory_stock_item` 4 036 aktualizacji, 0 HOT; `ordering_customer` 98 568,
  0 HOT; `messenger_messages` 4 418, 0 HOT; `catalog_product` 1 000 000, 635 HOT. [KOD] `inventory_stock_item` ma
  indeksy częściowe z predykatem `physical - reserved …` (kolumny predykatu blokują HOT), `ordering_order` ma 12
  indeksów (752 MB przy tabeli 439 MB), w tym `status` w dwóch indeksach i GIN trigramów 140 MB – każda zmiana statusu
  (opłacenie, wysyłka, doręczenie) dopisuje wpisy do wszystkich 12 indeksów. `ordering_customer` (7 indeksów, w tym
  2 GIN) aktualizowany przy każdym zamówieniu i płatności klienta.
- Skutek: przy płatnościach i rezerwacjach rośnie WAL, bloat indeksów i praca autovacuum; dla stanów magazynowych
  (gorące warianty) bloat pkey spowalnia `FOR UPDATE`.
- Przyczyna: projekt indeksów (świadomy kompromis pod panel).
- Rekomendacja (S–M): `fillfactor = 80–90` dla `inventory_stock_item` i `ordering_customer` nic nie da, dopóki
  zmieniane kolumny są indeksowane – rozważyć dla stanów jeden indeks częściowy zamiast dwóch albo indeks na kolumnie
  wyliczanej rzadziej zmienianej; dla `ordering_order` ocenić, czy `(total_gross, id)` bez statusu i
  `lower(email)` (jest też trigram) są potrzebne; GIN trigramów z `fastupdate` (domyślne) zostawić.
  Per-tabela autovacuum dla tabel aktualizowanych: `autovacuum_vacuum_scale_factor = 0.02–0.05` na
  `inventory_stock_item`, `ordering_order`, `ordering_customer`, `messenger_messages`.
- Pomiar: po teście obciążeniowym checkoutu `SELECT relname, n_tup_upd, n_tup_hot_upd, n_dead_tup FROM pg_stat_user_tables`
  i `pg_stat_wal` (wal_bytes na zamówienie).

### K8. Serializacja na gorących wierszach w transakcji składania zamówienia — Średnia [KOD + HIPOTEZA]

- Dowód: [KOD] `Ordering/Application/Command/PlaceOrderHandler.php:122–186`: w jednej transakcji READ COMMITTED kolejno
  blokada koszyka (`FOR UPDATE`), 2 blokady doradcze limitu nieopłaconych (`PostgresUnpaidOrderLock`), wycena, blokada
  kodu rabatowego z limitem (`FOR NO KEY UPDATE`, `DoctrineDiscountCodeRepository.php:82`), `nextval` sekwencji numeru,
  czyszczenie koszyka, rezerwacja (`lockMany` – `FOR UPDATE` stanów w kolejności id,
  `DoctrineStockItemRepository.php:53`), insert zamówienia (12 indeksów), claim, zdarzenia do outboxa, wiadomość
  opóźniona, upsert `ordering_customer`. Kolejność blokad jest stała (brak cykli – dobrze). Numeracja to sekwencja –
  brak gorącego wiersza (dobrze). Statystyka sprzedaży ma 16 shardów na godzinę (dobrze).
- Skutek: kod rabatowy z limitem i popularny wariant są blokowane od momentu ich blokady do commitu całej transakcji
  (kilkadziesiąt instrukcji, każda 2 round-tripy przy natywnych prepared statements pdo_pgsql); przepustowość jednego
  kodu/wariantu ≈ 1 / czas trzymania blokady.
- Rekomendacja: najpierw zmierzyć czas transakcji i liczbę instrukcji; ewentualnie (S) blokować kod i stany możliwie
  późno (stany już są późno). Nie przebudowywać bez pomiaru.
- Pomiar: `pg_stat_statements` + `log_min_duration_statement = 0` dla jednej sesji składania zamówienia (liczba
  instrukcji, czas od `BEGIN` do `COMMIT`); test k6 50 równoległych zamówień tego samego wariantu i tego samego kodu
  z limitem, z `log_lock_waits = on`.

### K9. Tabele rosnące bez retencji i bez partycjonowania — Niska teraz, Średnia przy 50 mln zamówień

- Dowód: [POMIAR] `pricing_price_history` 3,9 mln (914 MB), `inventory_stock_movement` 3,26 mln (1088 MB),
  `ordering_order_status_change` 3,9 mln (768 MB), `payment_payment` 0,96 mln (408 MB),
  `inventory_reservation(_line)` (72 MB), `payment_webhook_event`, `ordering_order_notification`. [KOD] brak zadań
  retencji poza koszykami (`DbalExpiredCarts`) i wiadomościami.
- Ocena: dostęp jest zawsze po `variant_id`/`order_id` z indeksem złożonym, więc koszt zapytań nie rośnie z wielkością
  tabeli (log). Partycjonowanie **nie jest potrzebne** przy 5 mln zamówień; przy profilu `full` (50 mln zamówień)
  rozważyć partycjonowanie zamówień po `placed_at` dopiero, gdy VACUUM/kopie będą problemem. Retencja (S): ruchy
  magazynowe i historia cen starsze niż wymagane prawem okno (Omnibus: 30 dni + historia do panelu) – decyzja
  produktowa, nie techniczna.
- Pomiar: `pg_total_relation_size` tych tabel po wygenerowaniu `server`.

### K10. Zbędne (zdublowane prefiksem) indeksy — Niska

[KOD + POMIAR rozmiarów, `indexes.txt`]; każdy jest prefiksem innego indeksu tej samej tabeli:

| Indeks | Rozmiar medium | Pokrywa go | Prognoza 10 mln/5 mln |
|---|---|---|---|
| `idx_8bebef264584665a` `catalog_product_category(product_id)` | 8,5 MB | pkey `(product_id, category_id)` | ~85 MB |
| `ordering_order_line_order_id_idx (order_id)` | 62 MB | `(order_id, variant_id)` i `(order_id, position)` | ~310 MB |
| `ordering_order_status_change_order_id_idx (order_id)` | 88 MB | pkey `(order_id, position)` | ~440 MB |
| `pricing_promotion_variant_promotion_id_idx` | 88 kB | pkey `(promotion_id, variant_id)` | – |
| `ordering_order_notification_order_id_idx` | 8 kB | pkey `(order_id, kind)` | – |
| `ordering_order_delivery_vat_order_id_idx` | 8 kB | pkey `(order_id, vat_rate_bp)` | – |

Usunięcie (S) zmniejsza zapis przy każdym zamówieniu i ~0,85 GB indeksów przy `server`. Uwaga: Doctrine może je
odtwarzać przy `doctrine:schema:update`/diff (indeksy FK generowane z mapowania) – trzeba to obsłużyć w mapowaniu.
Pozostałe indeksy mają uzasadnienie w zapytaniach panelu (sprawdzone w kodzie); `catalog_product_variant` ma dwa
unikalne indeksy (352 MB) wymuszające reguły domeny – zostawić.

### K11. Generator i operacje masowe na `server` — Średnia (tylko generator/reindeks)

- [KOD] generator pisze COPY (`DemoData/Copy/CopyWriter.php`, `synchronous_commit = off` – dobrze) do tabel z
  pełnym kompletem indeksów (w tym GIN trigramów 214 MB/1 mln produktów) i z kluczami UUID v5 (`DemoCatalogExpander`,
  `DemoUuid::v5`) – losowymi, więc wstawianie trafia w losowe strony B-tree, które przy 2 GiB nie mieszczą się w cache.
  [SZACUNEK] przy 10 mln: ~24 GB indeksów katalogu budowanych losowymi wstawieniami + `max_wal_size = 1 GB` (częste
  checkpointy, pełne obrazy stron) – to może tłumaczyć szacunek 24 h z `values.yaml`.
- Rekomendacja (S–M): dla generatora na pustej bazie: tymczasowo `max_wal_size` 8–16 GB, `maintenance_work_mem`
  1 GB, budowa indeksów wtórnych (zwłaszcza GIN) po COPY albo sortowanie wierszy po kluczu przed COPY. Aplikacja
  używa UUID v7 (`Uuid::v7()` w kontrolerach i handlerach) – dobrze, lokalność B-tree przy wstawianiu jest zachowana.
- Reindeks (#32): keyset po pkey, 45 ms / 500 produktów – dobrze; LATERAL „names” robi Seq Scan `catalog_category`
  na każdy produkt (przy 180 kategoriach pomijalne, przy tysiącach kategorii koszt ∝ produkty × kategorie) [HIPOTEZA].
  Rekomendacja: wczytać mapę kategorii raz na stronę (180–kilka tysięcy wierszy) i składać nazwy w PHP.
- Pomiar: `EXPLAIN ANALYZE` zapytania reindeksu na bazie z ≥5 000 kategorii; czas i `pg_stat_wal`/`pg_stat_checkpointer`
  w trakcie generatora `server`.

### K12. Kolejka `messenger_messages` w tabeli (outbox, scheduled, jobs, promotions) — Niska

- [KOD] `apps/api/config/packages/messenger.yaml:28–31` – transport Doctrine bez NOTIFY (polling), wiersz usuwany po
  ack; indeks `(queue_name, available_at, delivered_at, id)` istnieje. [POMIAR] 3 żywe wiersze w 3,4 MB tabeli
  (churn), 0 aktualizacji HOT. Każde zdarzenie domenowe = INSERT + UPDATE (`delivered_at`) + DELETE w tej tabeli.
- Ocena: przy obecnej skali OK; przy dużej liczbie zdarzeń (masowa zmiana cen/stanów = zdarzenie na wariant) tabela
  i jej indeks puchną między przebiegami autovacuum, a polling czyta martwe wpisy. Rekomendacja (S): per-tabela
  `autovacuum_vacuum_scale_factor = 0`, `autovacuum_vacuum_threshold = 1000`. [HIPOTEZA] wersja symfony/doctrine-messenger
  używa `FOR UPDATE SKIP LOCKED` dla PostgreSQL – sprawdzić w `vendor` kontenera API.
- Pomiar: masowa zmiana ceny 10 000 wariantów → `n_dead_tup`, rozmiar `messenger_messages` i czas relay.

### K13. Plik PVC 10 Gi i `local-path` — Niska (informacyjnie)

[KOD] `infra/k8s/charts/shop/values.yaml:209–211` `storage.postgres: 10Gi`, klasa `local-path` (rozmiar nie jest
egzekwowany). Przy `server` baza ~70 GB + WAL; ochroną jest tylko alert dysku węzła i `minFreeGb: 32` generatora.
Rekomendacja: wpisać realny rozmiar (dokumentacyjnie) i alert na rozmiar bazy.

## Co jest zrobione dobrze (nie ruszać)

- Brak OFFSET w całym API (keyset wszędzie: panel, historia, reindeks, mapa strony, wygaszanie, czyszczenie koszyków).
- `BoundedCounter` i liczniki z LIMIT zamiast `COUNT(*)` po dużych tabelach; licznik produktów z `reltuples`.
- Odczyty wsadowe `= ANY(CAST(:ids AS uuid[]))` (oferty, ceny, stany, warianty) – brak N+1 na listingu; podzapytanie
  `item_count` w listach zamówień ograniczone do strony.
- Indeksy złożone dopasowane do sortowań z `id` jako tie-breaker i kursorem jako warunkiem indeksu; indeksy
  częściowe dla rzadkich stanów (szkice, bez zdjęć, niski/brak stanu, zwroty) z literałem zamiast parametru.
- Współbieżność: stała kolejka blokad w checkoucie, blokady `FOR UPDATE` po kluczu w kolejności id (bez zakleszczeń),
  `FOR NO KEY UPDATE` dla kodów, `SKIP LOCKED` przy czyszczeniu koszyków, sekwencja dla numerów zamówień,
  16 shardów godzinowej statystyki sprzedaży, wersjonowane UPDATE (optymistyczna kontrola).
- UUID v7 dla nowych encji (lokalność B-tree), COPY w generatorze, migracje indeksów `CONCURRENTLY` (8 migracji
  nietransakcyjnych).
- Partycjonowanie i dodatkowe usługi (PgBouncer, repliki odczytu) nie są dziś potrzebne – najpierw K1–K3.

## Uproszczenia

- Usunąć zdublowane indeksy (K10).
- Ujednolicić filtr kategorii panelu w jednym miejscu (K4 dotyczy trzech list, ale kod jest już wspólny – poprawka
  w jednym pliku).
- Granice mapy strony: zamiast funkcji okna prosta tabela granic albo keyset (K6).

## Pomiary do wykonania przez sesję prowadzącą (kolejność wg ważności)

1. Podczas testu 8 zimnych listingów: próbkowanie `pg_stat_activity` (liczba połączeń wg `usename`/`state`) i log
   Postgresa pod kątem `too many clients` / `remaining connection slots` (K3); równolegle `frankenphp_busy_threads`.
2. Na stosie z zasobami prod (2 GiB): współczynnik trafień cache (`pg_stat_database.blks_hit/blks_read`,
   `pg_statio_user_indexes`) dla losowych PDP i ofert listingu po restarcie Postgresa (K1).
3. 8 równoległych żądań listy produktów panelu z filtrem kategorii o kilku produktach (#14) + równoległy ruch sklepu:
   czas odpowiedzi sklepu i zajętość wątków API (K2, K4).
4. Włączyć `pg_stat_statements` + `track_io_timing` na stosie medium i powtórzyć scenariusze k6 sklepu i checkoutu;
   top 20 wg `total_exec_time` i `shared_blks_read` (weryfikuje, że ścieżki sklepu są tanie, i liczy instrukcje
   transakcji zamówienia – K8).
5. Checkout: 50 równoległych zamówień tego samego wariantu i tego samego kodu z limitem, `log_lock_waits = on`,
   czas trzymania blokad (K8); potem `n_tup_hot_upd` i `pg_stat_wal` (K7).
6. Zimne `GET /api/v1/sitemap/product-chunks` po purge `CATALOG`, 4 równolegle (K6).
7. Po wygenerowaniu `server` (gdzie bezpieczne): rozmiary tabel/indeksów, czas generatora z `max_wal_size` 1 GB
   vs 8 GB (K1, K11).
