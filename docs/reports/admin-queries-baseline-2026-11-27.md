# PERF-7-1. Zapytania panelu i mapy strony: pomiar przed zmianą i wybór wariantów

Zadanie PERF-7-1 epiku PERF-7 (`docs/features/admin-queries/`). Zadanie pomiarowe: bez zmian kodu aplikacji. Zawiera macierz przypadków przed zmianą (`small`, `medium`),
hipotezy H1 i H2, klasyfikację fragmentów bez trygramu, W7 i P4 przed zmianą, prototypy wariantów K, F, Z i M na kopii `medium` (koszt odczytu, zapisu i rozmiar), wybór i pytania do właściciela.
Dowody (zapytania, plany, mieszanka, surowe wyniki k6, skrypty, polecenia): katalog `docs/reports/admin-queries-baseline-2026-11-27/`.

## 0. Zastrzeżenia, które obowiązują cały raport

1. **Ekstrapolacja do `server` jest szacunkiem, nie pomiarem.** Czasy z dysku („z dysku”) wynikają z założeń przepustowości (sekcja 2.3), a nie z
   pomiaru dysku worf.pl. Sesja profilu `server` (PR #972) zmierzyła ograniczenie przez IO na worf.pl **wyłącznie dla fazy generowania zamówień**
   (odczyt 180–209 MB/s, iowait 0,19). Dla zapytań panelu i dla `demo-data:verify` ograniczenie dyskiem jest **hipotezą**. Dlatego ekstrapolacja „z dysku”
   jest założeniem i **nie może służyć jako dowód spełnienia ani niespełnienia W7 na `server`**; W7 i P2 uznajemy za spełnione albo niespełnione
   wyłącznie według pomiaru na `server` (spec, „Akceptacja tylko na `server`”). Miarą niezależną od maszyny są bufory i liczba wierszy.
2. Wolumen `server` na worf.pl (pomiar, #972): 10 000 191 produktów, 5 000 000 zamówień, `ordering_customer` 880 082 wierszy (klient bez zamówienia nie ma
   wiersza; 1 mln jest tylko w Keycloak), suma `order_count` 3 000 222. Ekstrapoluję do tych liczb. Liczby A4 (880 082, 3 000 222) są zmierzone.
3. Lokalny `medium` mieści się w pamięci hosta (Docker VM 33,6 GB), więc jego czasy są dolną granicą czasu węzła z 2 GiB pamięci Postgresa.
4. Stan „przed” dla W7 i P4 daje wyłącznie to zadanie (PERF-0 ich nie mierzył). Tempo panelu 5 żądań/s jest zatwierdzone przez właściciela (decyzja D4 specyfikacji).
5. Kryterium P4 „p95 i p99 w normie W1” na `server` zależy od PERF-2B (sklep już dziś nie spełnia W1 na `server`: 503 z Elasticsearcha przy 2 zimnych listingach/s, #972).
   PERF-7 odpowiada za różnicę sklepu z panelem i bez.
6. Wszystkie pomiary czasu robiłem na maszynie współdzielonej z innymi sesjami; obciążenie spoza stosu `perf7` w oknach jest zapisane (sekcja 2.4).

## 1. Streszczenie

- **Stan obecny na `medium` (1,0 mln produktów, 1,0 mln zamówień, 98 570 klientów w `ordering_customer`):** z 258 przypadków macierzy 36 przekracza
  maksimum W7 (2000 ms) już w wariancie „w pamięci” po ekstrapolacji do `server`, a kolejne 63 tylko w wariancie „z dysku” (założenie). Wszystkie 36 są w obszarze produktów
  i mapy strony: filtr małej i średniej kategorii (strona i licznik), frazy bez trygramu (`#!#`, `---`, `a.b`, 2 znaki), fraza skorelowana z sortem, kombinacje małej kategorii
  z frazą, granice mapy strony. Zamówienia i klienci mieszczą się w 2000 ms „w pamięci” w każdym przypadku macierzy.
- **W7 na `medium`: niespełnione.** Mieszanka 5 żądań/s: p50 43 ms, **p95 2,29 s**, p99 4,1 s, maksimum 5,8 s; siedem klas przekracza 2 s (małe i średnie kategorie, frazy skorelowane z sortem
  i bez trygramu, kombinacje). Na `small` W7 jest spełnione (p95 76 ms, maks. 407 ms).
- **P4 na `medium`: kryterium z specyfikacji niespełnione**, choć wpływ bezwzględny jest mały: z panelem zimne listingi sklepu +13 ms p50, +21 ms p95, +41 ms p99, koszyki +13/+18/+21 ms; Postgres
  jedzie wtedy na ok. 10,5 rdzenia. Bez 5xx i bez opuszczonych żądań, ale **nie bez niepowodzeń**: 409 w kroku zmiany ilości koszyka (4/15/25 w trzech przebiegach bez panelu, 9/26/38 z panelem) w sklepie; werdykt P4 „brak niepowodzeń sklepu” nie jest spełniony w żadnym wariancie (sekcja 6). Niepowodzenia samej mieszanki panelu (2 odpowiedzi 400 na przebieg, `q=z`) są raportowane osobno i nie wchodzą do werdyktu P4.
- **H1 potwierdzona częściowo** (mechanizm inny niż w hipotezie), **H2 potwierdzona** (jedno zapytanie biegło w Postgresie 8,1 s, ponad 7 s po przerwaniu żądania przez klienta po 1 s; w kontroli bez przerwania 2,95 s, różnicy nie wyjaśniłem).
- **Koszt zapisu nowych indeksów** (prototypy na kopii `medium`, WAL na zapis; miarą są KB, procenty są względne do syntetycznego zapisu jednego wiersza i nie przenoszą się na ruch produkcyjny): K1 +15 KB na zapis produktu (+40%), F2 +14 KB (+38%), Z1 +24 KB na cykl zamówienia (+42%) i +9 KB na zapis klienta (+45%).
- **Wybór wariantów (według reguły „od najprostszego, stop na pierwszym spełniającym P2 i P8”; P2 według ekstrapolacji „w pamięci”, SZACUNEK):**
  - **K1** (K0 odpada: dział 4,1–10,8 s w pamięci na `server`): wszystkie przypadki kategorii bez filtra mieszczą się w 2000 ms (maks. 1,88 s dla działu, zapas 6–15%), strona i licznik małej kategorii z 3,5 s do 7 ms na `medium`; K1 liczy i zwraca produkt raz także przy kilku kategoriach dodatkowych w poddrzewie (I2, syntetyczny produkt, 82 pary bez różnic).
    Poza P2 zostają: dział + szkice `-updatedAt` 10,7 s, dział + fraza średnia 4,0–9,0 s, a przypadki dział + marka (1,92 s), + bez zdjęć (1,69 s licznik) i + szkice `name` (1,4 s, raz 12,6 s) są graniczne albo niestabilne.
  - **F1 dla fraz z trygramem + F2 dla fraz bez trygramu** (F1 sam nie pomaga fragmentom bez trygramu: 14,4 s w pamięci na `server` → F2 15 ms); nie mieści się jedna klasa: fraza częsta i skorelowana z sortem (`rowerowa`, 601 tys. trafień na `server`: 2,83 s w pamięci, R1).
  - **Z0** mieści się w P2 w pamięci (zamówienia `#!#`/`---` 1,2–1,5 s na `server`, klienci 0,22 s), więc według reguły Z1 nie jest potrzebny; **pod obciążeniem mieszanki klasa `orders_punct` ma jednak p95 630 ms i maks. 825 ms na `medium`** (skalowane ×5: ok. 3,2 s i 4,1 s, SZACUNEK górny), więc Z0 nie jest potwierdzone dla W7 pod obciążeniem (pytanie 10.3.3). Z1 zmierzony i gotowy (+24 KB WAL na cykl zamówienia).
  - **M1** (granice mapy strony keysetem): 93 ms na `medium`, na `server` ok. 0,9–1,1 s w bazie i ok. 1,1–1,6 s z 1001 zapytaniami przez sieć (SZACUNEK), czyli w budżecie 2000 ms; M2 nie daje zysku.
- **Przypadki poza P2 po prototypach** (SZACUNEK „w pamięci”): fraza częsta skorelowana z sortem (R1), dział + szkice, dział + bez zdjęć i dział + marka (R2a–R2c) oraz dział + fraza średnia (R2d). Sekcja 10.2 rozdziela:
  - **rozwiązania zmierzone na `shop_proto`:** R1 spacer index-only do 26. trafienia 0,9–1,6 s na `server`; R2b/R2c częściowe indeksy po kategorii (dział + szkice `-updatedAt` 1095 → 110 ms, bez zdjęć licznik 181 → 11 ms); R2a kolumna kategorii głównej w członkostwach (17 ms zamiast 115 ms);
  - **bez zmierzonego rozwiązania: R2d** (dział + fraza średnia, 4,0–9,0 s): częściowe indeksy go nie zmieniają, zaproponowane kandydaty GIN zawężeni do kategorii są niezmierzone.
  Właściciel nie akceptuje wyjątków W7, więc R2d pozostaje otwarte do decyzji po sprawdzeniu w zadaniu 3; pozostałe są objęte rozwiązaniami do potwierdzenia na `server`. Zastrzeżenie: wszystko, co rośnie liniowo, jest „z dysku” tylko założeniem.
- **Pytania do właściciela: sekcja 10.**

## 2. Środowisko i metoda

### 2.1 Rewizja, dane, stos

- Rewizja kodu: gałąź epiku `claude/perf-7-implementation-bc3101` (start `301fe095`, schemat z PRODUCT-DATA: `brand`, `description`, `parameters` w `catalog_product`).
- Stos: projekt Compose `perf7` (porty 28100–28106), sklep w trybie `dev`, API w `APP_ENV=prod`, `APP_DEBUG=0` (lokalne nadpisanie compose poza repo; w trybie `dev` każde żądanie
  trwa ok. 0,4 s i fałszuje W7) i z `CLIENT_IP_HEADER=CF-Connecting-IP` (jak w klastrze za Cloudflare; bez niego tworzenie koszyków w P4 dostaje 429 po jednym adresie).
- Dane: `small` = `make seed-scale PROFILE=small` (2191 produktów, 5000 zamówień, 873 klientów w `ordering_customer`, 180 kategorii); `medium` = `PROFILE=medium` (1 000 191 produktów, 1 000 000 zamówień,
  98 570 klientów w `ordering_customer`, 180 kategorii, 200 192 członkostw dodatkowych kategorii, baza 10 GB). Tabele po generatorze zwakuowane i zanalizowane.
- Prototypy wariantów: baza `shop_proto` = `CREATE DATABASE shop_proto TEMPLATE shop` (kopia `medium`), więc baza „przed” (`shop`) jest nienaruszona.
- Maszyna: Apple M4 Max, Docker Desktop VM 16 CPU, 33,6 GB RAM.

### 2.2 Konfiguracja PostgreSQL (SHOW)

PostgreSQL 18.6 w obrazie z compose, bez nadpisań (konfiguracja jak na gałęzi epiku; PERF-1 jej w trakcie nie zmienił): `shared_buffers` 128 MB, `work_mem` 4 MB, `maintenance_work_mem` 64 MB,
`effective_cache_size` 4 GB, `random_page_cost` 4, `effective_io_concurrency` 16, `max_parallel_workers_per_gather` 2, `jit` on, `max_wal_size` 1 GB, `synchronous_commit` on, `track_io_timing` off
(włączane `SET track_io_timing = on` w sesji pomiarowej macierzy, bez nadpisania compose), brak `pg_stat_statements`.

### 2.3 Metoda pomiaru i założenia ekstrapolacji

- **Macierz** (`scripts/matrix.py`): przypadki losowane z danych profilu (kategorie według rozmiaru i miejsca 26. członka w kolejności nazw, frazy według liczby trafień i miejsca w sortowaniu, fragmenty zamówień
  i klientów, kombinacje filtrów, szkice, bez zdjęć), zapytania identyczne z aplikacyjnymi (`DbalBackofficeProductSource`, `DbalBackofficeOrderReader`, `DbalCustomerReader`, `BoundedCounter` z limitem 10 001, kursory
  z prawdziwych stron) z wartościami wstawionymi. Każdy przypadek: pierwsza strona, druga strona z kursorem, sort `name` i `-updatedAt`, licznik pierwszej strony. `EXPLAIN (ANALYZE, BUFFERS, TIMING OFF)`;
  10 powtórzeń, 3 gdy pierwsze trwa > 2 s. Pierwsze uruchomienie przypadku jest „pierwsze” (shared buffers chłodne względem poprzednich przypadków, cache systemu operacyjnego ciepły: nie da się go zrzucić bez
  dotykania innych stosów), kolejne „powtórzone”. 258 przypadków na `medium`, 243 na `small`. Dla `small` i `medium` przypadki to te same klasy z danych każdego profilu (inne konkretne kategorie i frazy), więc wzrost
  liczę ze zmiennej kosztu, nie z identyczności przypadków.
- **Zmienna kosztu** każdego przypadku: liczba produktów w poddrzewie kategorii, liczba trafień frazy, rozmiar tabeli (zamówień, klientów) albo stała (strona, limit licznika). Spacer po indeksie sortowania, który znajduje potrzebne wiersze
  (26 albo 10 001), jest ograniczony gęstością trafień, którą wzrost danych zachowuje, chyba że trafienia skupiają się w dalszej części porządku (wtedy liczba przeczytanych wierszy to wielokrotność oczekiwania `k/gęstość × N` i rośnie z `N`); plan z bitmapą
  indeksu, plan sekwencyjny i spacer, który nie znajduje kompletu wierszy, czytają cały obszar i rosną z `N` (`scripts/extrapolate.py`, `effective_scale`).
- **Ekstrapolacja do `server`** (SZACUNEK): bufory i wiersze `medium` razy wzrost zmiennej (produkty ×10,0 ze 1 000 191 do 10 000 191; zamówienia ×5; klienci ×8,93 z 98 570 do 880 082); czas „w pamięci” = mediana powtórzeń `medium` razy wzrost
  buforów; czas „z dysku” = czas „w pamięci” plus strony spoza `shared_buffers` (odczyty `medium` razy wzrost, a dla przypadków rosnących co najmniej bufory `server` minus 16 384 stron, które mieszczą się w 128 MB)
  razy koszt strony: **0,5 ms** (2000 IOPS odczytu losowego, bez kredytu na prefetch) albo **0,04 ms** (200 MB/s odczytu sekwencyjnego dla planów sekwencyjnych). To założenia; dysku worf.pl nie mierzyłem (patrz zastrzeżenie 1).
  Docelową pamięć Postgresa ustali PERF-1 (jeszcze bez wartości).

### 2.4 Okna pomiarów i obciążenie hosta

- Macierz `medium`: 13:44:49–13:54:30 UTC. H2 14:35, ścieżki błędu 14:37–14:40, kalibracja tempa sklepu 14:40–14:49, W7 `medium` 14:49–15:07, P4 pierwsza próba (odrzucona) 15:07–15:29, P4 15:30–15:54,
  przebieg dodatkowy 16:00–16:08 UTC. Okna uzgodnione z sesją prowadzącą (host wolny od 13:44 i od 14:35 UTC). Pomiary czasu na `small` poszły przed uzgodnieniem okien (brak w mojej sesji narzędzia
  komunikacji z sesją prowadzącą; pomiary porównawcze).
- Obciążenie spoza `perf7` zapisane w `results/medium/host-load-w7.txt`, `w7/host-load-attempt1.txt`, `p4/host-load-p4.txt`, `p4/host-load-p4x.txt`: 15:06:47 `priceless_thompson` 240% (tuż przy końcu 3. przebiegu W7); 15:13 `promtool` (`great_visvesvaraya`) 94% podczas pierwszej, odrzuconej próby P4;
  15:54 po drugim P4 i 16:00 przed przebiegiem dodatkowym `async-idx-m1-rabbitmq` 84–89% (przebieg „bez panelu” tego uruchomienia jest odrzucony; przebieg „z panelem” 16:05–16:08 zakończył się bez obciążenia spoza `perf7` powyżej 5%).
  Warianty (część 2b): 16:28–17:00 UTC; na wejściu `async-idx-m1-api` 6,6% CPU, na końcu nic powyżej 5% (`results/medium/variants/host-load-2b.txt`). Macierz `medium` i W7 poszły bez obciążenia spoza `perf7` powyżej 5% na wejściu, z wyjątkiem zdarzenia 15:06:47.

## 3. Macierz przypadków przed zmianą: `small` i `medium`

Pełne tabele (czas, bufory, wiersze, zmienna kosztu, `small` i `medium`; 258 wierszy): `results/medium/tables/matrix-small-medium.md`, z ekstrapolacją `results/medium/tables/matrix-extrapolation.md`;
zapytania w `cases.json` (pole `sql`) każdego zbioru, plany `EXPLAIN (ANALYZE, BUFFERS)` tylko cytowanych przypadków w `results/medium/explain/` i `results/medium/variants/*/explain/` (pozostałe odtwarza `matrix.py run` z `cases.json`, sekcja 11). Wybrane przypadki (po jednym z każdej klasy; „server” = SZACUNEK):

| przypadek | zmienna kosztu (medium → server) | bufory small / medium / server | rows scanned medium | czas small mediana ms | czas medium pierwszy / mediana / maks. ms | server w pamięci ms | server z dysku ms |
|---|---|---|---|---|---|---|---|
| P-default.p1.name | page 25 → 25 | 23 / 29 / 29 | 26 | 0.1 | 0.2 / 0.1 / 0.2 | 0 | 0 |
| P-default.count | page 25 → 25 | 7 / 44 / 44 | 10001 | 0.2 | 0.9 / 0.8 / 0.9 | 1 | 1 |
| P-cat-small.p1.name | products in the subtree 15 → 150 | 2120 / 1014124 / 10139497 | 1000551 | 1.2 | 2801.7 / 3067.9 / 3131.9 | 30674 | 5092230 |
| P-cat-small.p1.-updatedAt | products in the subtree 15 → 150 | 775 / 1004352 / 10041794 | 1000551 | 0.9 | 819.5 / 894.4 / 965.8 | 8942 | 5021647 |
| P-cat-small.count | products in the subtree 15 → 150 | 681 / 318648 / 3185932 | 1000551 | 1.0 | 423.2 / 435.4 / 467.8 | 4354 | 1589128 |
| P-cat-mid.p1.name | products in the subtree 10585 → 105832 | 2120 / 90151 / 901355 | 90083 | 1.3 | 267.5 / 286.2 / 361.9 | 2862 | 445347 |
| P-cat-mid.count | products in the subtree 10585 → 105832 | 681 / 310108 / 3100547 | 947828 | 1.1 | 489.3 / 504.5 / 629.0 | 5044 | 1547126 |
| P-cat-big-early.p1.name | products in the subtree 15199 → 151964 | 1447 / 34 / 340 | 206 | 1.0 | 0.3 / 0.2 / 0.3 | 2 | 2 |
| P-cat-big-early.count | products in the subtree 15199 → 151964 | 681 / 117854 / 1178337 | 658653 | 1.3 | 380.8 / 360.7 / 432.5 | 3606 | 584583 |
| P-cat-big-late.p1.name | products in the subtree 22829 → 228251 | 799 / 108416 / 1083974 | 107720 | 0.9 | 331.8 / 356.3 / 442.7 | 3562 | 537357 |
| P-cat-big-late.count | products in the subtree 22829 → 228251 | 681 / 219287 / 2192493 | 437372 | 1.3 | 338.2 / 211.7 / 350.0 | 2117 | 1090171 |
| P-cat-department.p1.name | products in the subtree 206849 → 2068134 | 149 / 34 / 340 | 206 | 0.5 | 0.2 / 0.2 / 0.2 | 2 | 2 |
| P-cat-department.p1.-updatedAt | products in the subtree 206849 → 2068134 | 110 / 1590 / 15897 | 200668 | 0.3 | 19.2 / 17.1 / 19.2 | 171 | 171 |
| P-cat-department.count | products in the subtree 206849 → 2068134 | 681 / 10131 / 101293 | 248704 | 1.4 | 62.7 / 66.5 / 74.1 | 664 | 3981 |
| P-cat-mostly-additional.p1.name | products in the subtree 6689 → 66879 | 2120 / 3728 / 37274 | 5253 | 1.6 | 13.1 / 5.7 / 13.1 | 57 | 10502 |
| P-cat-mostly-additional.count | products in the subtree 6689 → 66879 | 681 / 319859 / 3198040 | 1002975 | 1.0 | 510.6 / 517.3 / 593.8 | 5172 | 1596000 |
| P-term-none.p1.name | hits 0 → 0 | 15 / 19 / 190 | 0 | 0.1 | 0.2 / 0.1 / 0.2 | 1 | 1 |
| P-term-rare.p1.name | hits 2980 → 29795 | 29 / 3228 / 32274 | 2980 | 0.1 | 16.0 / 7.6 / 16.0 | 76 | 8021 |
| P-term-type-late.p1.name | hits 8584 → 85825 | 25 / 8447 / 84455 | 8584 | 0.1 | 48.7 / 18.7 / 48.7 | 187 | 34222 |
| P-term-brand-mid.p1.name | hits 17200 → 171970 | 60 / 607790 / 6076855 | 599513 | 0.3 | 2507.4 / 2422.9 / 2540.7 | 24225 | 3054460 |
| P-term-brand-common.p1.name | hits 60168 → 601577 | 69 / 232318 / 2322781 | 229152 | 0.2 | 875.6 / 870.1 / 1027.6 | 8700 | 1161898 |
| P-term-very-common-a.p1.name | hits 119269 → 119269 | 41 / 281 / 281 | 275 | 0.1 | 0.7 / 0.6 / 0.7 | 1 | 1 |
| P-term-very-common-a.count | hits 119269 → 119269 | 36 / 18384 / 18384 | 21663 | 1.0 | 14.8 / 13.6 / 14.8 | 14 | 14 |
| P-term-two-rare.p1.name | hits 0 → 0 | 673 / 217218 / 2171807 | 1000191 | 1.5 | 1457.5 / 1452.9 / 1502.1 | 14527 | 1100390 |
| P-term-two-rare.count | hits 0 → 0 | 36 / 847300 / 8471544 | 1000191 | 1.0 | 283.0 / 238.7 / 299.6 | 2387 | 4229966 |
| P-term-two-common.p1.name | hits 258773 → 258773 | 68 / 418 / 418 | 410 | 0.2 | 2.1 / 0.6 / 2.1 | 1 | 1 |
| P-term-punct-1.p1.name | hits 0 → 0 | 673 / 217218 / 2171807 | 1000191 | 1.8 | 1423.4 / 1431.0 / 1501.6 | 14308 | 1100171 |
| P-term-punct-1.count | hits 0 → 0 | 36 / 847327 / 8471814 | 1000191 | 1.0 | 289.4 / 241.5 / 289.4 | 2415 | 4230129 |
| P-term-punct-3.p1.name | hits 0 → 0 | 332 / 157665 / 1576379 | 660920 | 0.8 | 648.3 / 652.0 / 717.6 | 6519 | 794673 |
| P-term-polish-lower.p1.name | hits 10067 → 100653 | 36 / 10033 / 100313 | 10067 | 0.2 | 45.3 / 21.9 / 45.3 | 219 | 42183 |
| P-combo-small-cat+common.p1.name | hits 0 → 0 | 2120 / 1014124 / 10139497 | 1000551 | 1.9 | 3868.7 / 4088.5 / 4110.8 | 40878 | 5102434 |
| P-combo-mid-cat+brand.p1.name | hits 0 → 0 | 2120 / 1015194 / 10150195 | 1002435 | 2.0 | 3487.7 / 3675.4 / 3764.7 | 36747 | 5103653 |
| P-combo-drafts.p1.name | hits 20108 → 20108 | 27 / 29 / 29 | 26 | 0.1 | 0.2 / 0.1 / 0.2 | 0 | 0 |
| P-combo-no-images.p1.name | hits 20003 → 20003 | 59 / 1087 / 1087 | 1071 | 0.2 | 4.1 / 1.3 / 4.1 | 1 | 1 |
| P-combo-no-images+brand.p1.name | hits 1239 → 12388 | 59 / 234041 / 2340008 | 230854 | 0.2 | 1013.0 / 946.6 / 1014.1 | 9464 | 1171276 |
| P-combo-no-images+dept.count | hits 4172 → 41713 | 67 / 26155 / 261505 | 220555 | 0.3 | 98.4 / 130.8 / 161.4 | 1307 | 132010 |
| O-default.p1.-placedAt | page 25 → 25 | 84 / 110 / 110 | 83 | 0.1 | 0.2 / 0.1 / 0.2 | 0 | 0 |
| O-frag-punct-1.p1 | hits 0 → 0 | 291 / 76159 / 380795 | 999999 | 3.1 | 295.5 / 300.1 / 333.7 | 1501 | 183706 |
| O-frag-punct-1.count | hits 0 → 0 | 285 / 56170 / 280850 | 999999 | 3.7 | 254.5 / 242.1 / 254.5 | 1210 | 11541 |
| O-frag-punct-3.count | hits 36835 → 184175 | 238 / 13291 / 66455 | 29060 | 0.7 | 60.0 / 51.7 / 60.0 | 258 | 25294 |
| O-frag-common-surname.p1 | hits 5368 → 5368 | 249 / 523 / 523 | 5916 | 0.6 | 5.4 / 4.9 / 5.4 | 5 | 5 |
| O-frag-none.p1 | hits 0 → 0 | 15 / 19 / 95 | 0 | 0.1 | 0.2 / 0.1 / 0.2 | 1 | 1 |
| C-default.p1.-lastOrderAt | page 25 → 25 | 28 / 29 / 29 | 26 | 0.1 | 0.2 / 0.1 / 0.2 | 0 | 0 |
| C-frag-punct-1.p1 | hits 0 → 0 | 38 / 3690 / 32946 | 98571 | 0.5 | 27.4 / 25.1 / 27.4 | 224 | 871 |
| C-frag-common-surname.p1 | hits 234 → 2089 | 38 / 255 / 2277 | 234 | 0.5 | 1.7 / 0.9 / 1.7 | 8 | 8 |
| X-discount-codes | active uses of the codes 70210 → 351050 | 43 / 122 / 610 | 70227 | 0.1 | 3.6 / 3.1 / 3.6 | 16 | 16 |
| X-category-counts | categories x bound 1001 180 → 180 | 785 / 2159 / 2159 | 105502 | 0.5 | 15.2 / 10.3 / 15.2 | 10 | 10 |
| X-sitemap-boundaries-M0 | products 1000191 → 10000191 | 24 / 840453 / 8403086 | 2000382 | 0.4 | 254.5 / 225.2 / 254.5 | 2252 | 4195603 |

Przypadki powyżej 2000 ms po ekstrapolacji „w pamięci” (36 przypadków): `results/medium/tables/p2-failing.md`. Podsumowanie po grupach:

| grupa | przypadków | powyżej 2000 ms w pamięci | powyżej 2000 ms tylko z dysku |
|---|---|---|---|
| category | 28 | 9 | 12 |
| combination | 47 | 9 | 14 |
| customers | 36 | 0 | 1 |
| lists | 6 | 0 | 0 |
| orders | 56 | 0 | 9 |
| other | 7 | 0 | 0 |
| phrase | 76 | 17 | 27 |
| sitemap | 2 | 1 | 0 |
| razem | 258 | 36 | 63 |

Obserwacje (pomiar, `medium`):

- Lista bez filtrów, sort `name` i `-updatedAt`, strony głębokie, dashboard, drzewo kategorii z licznikami, kody rabatowe, zamówienia i klienci bez filtra, filtry statusu, `R-…` i pełny e-mail: bufory i czas stałe (0,1–11 ms).
- Filtr kategorii: pierwsza strona sortu `name` kosztuje tyle, ile spacer po `(name, id)` aż do 26. członka kategorii (głównego albo dodatkowego), więc rośnie z miejscem tego członka w kolejności nazw (H1 niżej); małej kategorii (15 produktów) nie
  da się obsłużyć spacerem (plan czyta całą tabelę: 1,01 mln buforów, 3,1 s). **Licznik pierwszej strony kategorii jest osobnym kosztem:** Seq Scan `catalog_product` do 10 001 trafień, 360–517 ms dla małej, średniej, dużej i „głównie dodatkowej” kategorii (310–320 tys. buforów),
  więc strona i licznik razem dają 3,5 s dla małej kategorii.
- Frazy: z trygramem i mało trafień: GIN, 8–19 ms (`zupa`, `bagażnik`, polskie znaki, wielkość liter działają); fraza średnia i skorelowana z sortem (`treningowe`, 17 200 trafień): 2,4 s i 608 tys. buforów; bez trygramu: patrz sekcja 4.3.
- Kombinacje: mała kategoria + fraza częsta w katalogu (`pro`, 0 trafień w kategorii) 4,1 s (jak sama kategoria, bo fraza nie zawęża spaceru); średnia kategoria + marka 3,7 s; szkice i „bez zdjęć” bez kategorii: ms (indeksy częściowe); bez zdjęć + marka 0,95 s;
  bez zdjęć + dział (licznik) 131 ms. Kombinacje szkice + mała kategoria 100 ms (szkice są rzadkie, częściowy indeks).

## 4. Hipotezy i klasyfikacja

### 4.1 H1: duży liść z typem na końcu alfabetu jest wolny już na `medium`

**Potwierdzona częściowo, mechanizm inny.** W danych demo ok. 20% produktów ma dodatkowe kategorie (200 192 członkostw na 1 000 191 produktów), więc każdy duży liść ma 26. członka (nie typ z końca alfabetu, ale produkt dodatkowy) w pierwszych
0–10,6% nazw. Czystego „późnego” liścia w tych danych nie ma. Koszt pierwszej strony sortu `name` rośnie z miejscem 26. członka w kolejności nazw:

| kategoria (klasa) | produktów w poddrzewie | miejsce 26. członka | czas p1 `name` | bufory |
|---|---|---|---|---|
| Atlasy i mapy szczegółowe (`big-early`) | 15 199 | 0% | 0,2 ms | 34 |
| Stroje pływackie (`big-late`) | 22 829 | 10,6% | 356 ms | 108 416 |
| Rolki do masażu (`mid`) | 10 585 | skupione dalej | 286 ms | 90 151 |
| Piłka nożna (`small`, 15 produktów) | 15 | nie ma 26 członków | 3068 ms | 1 014 124 |

Koszt jest liniowy w liczbie produktów razy udział miejsca 26. członka: `big-late` ok. 3,6 s w pamięci na `server` (SZACUNEK), czyli ponad W7. Dalsze strony (kursor) i sort `-updatedAt` tych kategorii: 3–6 ms.
Zmienna kosztu: produkty (całość) × udział; wartość raportu: 10,6% × 10 000 191 ≈ 1,06 mln wierszy spaceru na `server`.

### 4.2 H2: przerwanie żądania przez klienta nie zatrzymuje zapytania w Postgresie

**Potwierdzona.** `h2.py`: żądanie `GET /api/v1/admin/products?category=<mała kategoria>&sort=name`, klient (curl `--max-time 1`) przerywa po 1,0 s; w `pg_stat_activity` backend 89350
wykonuje `SELECT … FROM catalog_product` (`state = active`) do t = 8,1 s, czyli ponad 7 s po przerwaniu; `h2.txt` pokazuje jedno zapytanie (jeden `query_start`, nie sumę strony i licznika), które działało 8,1 s. Kontrola bez przerwania: to samo zapytanie 2,95 s, HTTP 200 po 3,29 s. Różnicy 8,1 s wobec 2,95 s nie wyjaśniłem (przypuszczenie, niesprawdzone: chłodne strony po wcześniejszych zapytaniach); wniosek o H2 nie zależy od niej: zapytanie nie zostało przerwane po 1 s.
Przerwanie po stronie przeglądarki (zadanie PERF-7-6) zmniejszy liczbę żądań w toku i wyścigi widoku, ale nie koszt w bazie; ten ogranicza wybór wariantów (P2) i `statement_timeout` z PERF-1. Dowód: `results/medium/h2/h2.txt`.

### 4.3 Fragmenty bez trygramu (plan zapytania)

pg_trgm nie wyciąga trygramu z fragmentu złożonego z interpunkcji albo z 2 znaków: GIN zwraca 31–40% tabeli kandydatów (`index entries` w planie) albo planista wybiera plan sekwencyjny / spacer.
Na `medium` (pomiar; „server w pamięci” = SZACUNEK liniowy w tabeli):

| obszar | fragment | trygram? | plan | czas p1 / licznik `medium` | wiersze czytane | server w pamięci |
|---|---|---|---|---|---|---|
| produkty | `#!#`, `---` | nie | Bitmap Heap + GIN trgm (400 265 wpisów z 1 000 191) | 1431–1479 ms / 235–242 ms (Index Only Scan całego `(name, id)`) | 1 000 191 | 14,3–14,8 s / 2,4 s |
| produkty | `a.b` | nie | Bitmap Heap + GIN trgm (311 167 wpisów) | 652 ms / 674 ms | 660 920 | 6,5 s / 6,7 s |
| produkty | 2 znaki rzadkie (`qx`, `zx`) | nie | jw. | 1453–1459 ms / 239–242 ms | 1 000 191 | 14,5 s / 2,4 s |
| produkty | 2 znaki częste (`er`) | nie | spacer `(name, id)` z filtrem | 0,6 ms / 19,5 ms | 410 | stałe |
| produkty | `ab-cd`, `ąęś`, polskie znaki, `kowal` | tak | GIN trgm, mało trafień | 0,1–22 ms | ≤ liczba trafień | liniowo w trafieniach |
| zamówienia | `#!#`, `---` | nie | spacer `placed_at` z filtrem / Seq Scan (sort `-total`) | 300–310 ms / 242–247 ms | 999 999 | 1,5 s / 1,2 s |
| zamówienia | `a.b`, `ab-cd`, `ąęś`, `kowal`, `ann` | tak | GIN trgm (`a.b`: 106 395 wpisów, 36 835 trafień) | 0,1–0,9 ms / 52 ms (`a.b` licznik) | ≤ trafień | `a.b` licznik 258 ms |
| zamówienia | `R-…`, pełny e-mail | tak (dokładnie) | indeks `number` / `lower(email)` | 0,1 ms | 2 | stałe |
| klienci | `#!#`, `---` | nie | Seq Scan `ordering_customer` (98 571 wierszy) | 25 ms / 25 ms | 98 571 | 0,22 s |
| klienci | `a.b`, `ab-cd`, `ąęś`, `kowal` | tak | GIN trgm (`name`/`email`) | 0,1–1,3 ms / 11 ms | ≤ trafień | 0,1 s |

Kryterium klasy to wyciąganie trygramu z wzorca `LIKE`, nie `show_trgm` ani selektywność (sekcja 9.2, I3). Wniosek: klasa „bez trygramu” to **fragmenty z samej interpunkcji (`#!#`, `---`) w zamówieniach, klientach i produktach oraz dla produktów `a.b` i frazy 2-znakowe rzadkie** (frazy 2-znakowe częste przechodzą spacerem po indeksie).
Zamówienia i klienci są w niej tańsze niż produkty (czytają tabelę podsumowań albo 1 mln zamówień).

## 5. W7: panel pod obciążeniem (przed zmianą)

Mieszanka (`results/medium/mix.json`, generowana z danych przez `load/panel_mix.py`; 258 żądań w 35 klasach po usunięciu `q=z` (259 w pomiarze „przed”)): listy domyślne we wszystkich sortowaniach, strony głębokie z prawdziwymi kursorami, frazy według częstości, kategorie według rozmiaru,
kombinacje z filtrami, Ceny, Stany, zamówienia, klienci, kody rabatowe, dashboard, drzewo kategorii. 5 żądań/s przez 300 s (model otwarty), 3 przebiegi; mediana i (najniższy–najwyższy):

| zbiór | żądań/przebieg | p50 ms | p95 ms | p99 ms | maks. ms | błędy 5xx | 4xx |
|---|---|---|---|---|---|---|---|
| `small` | 1601 | 22 (20,1–28,6) | 76 (54–109) | 117 (73–181) | 407 (111–473) | 0 | 0 |
| `medium` | 1600 | 43 (27–78) | **2288 (2058–3081)** | 4146 (3161–5251) | **5812 (3922–6849)** | 0 | 4–5 na przebieg (`q=z`, odpowiedź 400: fraza ma co najmniej 2 znaki; defekt generatora, poprawiony, a żądanie usunięte z `mix.json`: patrz niżej) |

`medium`, klasy powyżej maksimum 2000 ms (mediana maksimów trzech przebiegów): `products_category_small` 5812, `products_phrase_type` 5424, `products_phrase_brand` 3440, `products_combo` 3420, `products_phrase_twochar` 3287, `products_phrase_punct` 3201,
`products_category_medium` 2117. Klasy zamówień, klientów, kodów rabatowych, dashboardu, drzewa kategorii, stanów, cen i domyślnych list: p95 poniżej ok. 150 ms (`orders_punct`: p95 630 ms, maks. 825 ms). Rozrzut: p50 i p95 rosną w kolejnych przebiegach
(27→43→78 ms, 2,06→2,29→3,08 s); 3. przebieg kończył się razem z obciążeniem spoza `perf7` (sekcja 2.4), wniosek (p95 i maksimum poza W7, dominują klasy znane z macierzy) jest odporny na ten szum. Wyniki: `results/{small,medium}/w7/` (`panel.json`, liczniki PostgreSQL, `report.txt`).

**Zmiana mieszanki względem pomiaru „przed”.** `results/medium/mix.json` zawierał żądanie `GET /api/v1/admin/products?q=z&sort=name` (fraza 1-znakowa, 400 jako błąd generatora, nie część obciążenia); po pomiarze usunąłem **1 żądanie z 259** (258 zostaje; notatka `notes.edited` w pliku). Pomiar „przed” miał przez nie 4–5 odpowiedzi 400 na przebieg; mieszanka „po” (zadanie 7) jest o to żądanie krótsza, więc rozkład klas jest praktycznie ten sam (waga klasy `products_phrase_brand` spada o ok. 1/12 jej żądań).

**W7 na `medium`: niespełnione z powodu opóźnień, niezależnie od tych 400. Przyczyna: ta sama co w macierzy (kategoria mała i średnia, fraza skorelowana z sortem i bez trygramu).** Wynik na `server` nie jest zmierzony (R5).

## 6. P4: wpływ panelu na sklep (przed zmianą)

Scenariusz: sklep przez API: 15 zimnych listingów/s (nominał W1: połowa 30/s z W9; adresy z `baseline.py urls`, każdy raz; na `medium` lokalnie utrzymane bez błędów: p95 236 ms w kalibracji) i 5 koszyków/s (tworzenie, dodanie, odczyt, zmiana: W5, 20 żądań/s); wariant
„z panelem” dodaje mieszankę W7 5/s, 8 wątków najcięższych klas (kategoria mała, fraza skorelowana, 2 znaki, interpunkcja, interpunkcja w zamówieniach i klientach, kombinacje) i zimne obliczenie granic mapy strony co 30 s. 3 przebiegi po 180 s każdego wariantu, na przemian,
po niezliczanej rozgrzewce 60 s. Wyniki (mediana i rozrzut przebiegów bez panelu; `results/medium/p4/report.txt`):

| miara | bez panelu (rozrzut) | z panelem | różnica | kryterium (różnica ≤ rozrzut bez panelu) |
|---|---|---|---|---|
| listingi p50 | 25,4 ms (0,4) | 38,6 ms | +13,2 ms | nie |
| listingi p95 | 133,6 ms (4,7) | 154,2 ms | +20,6 ms | nie |
| listingi p99 | 567,9 ms (1,9) | 609,1 ms | +41,2 ms | nie |
| koszyki p50 | 14,3 ms (0,3) | 27,0 ms | +12,7 ms | nie |
| koszyki p95 | 18,8 ms (0,3) | 37,0 ms | +18,2 ms | nie |
| koszyki p99 | 20,9 ms (0,3) | 41,8 ms | +20,9 ms | nie |
| zadane tempo | utrzymane (0 opuszczonych) | utrzymane | | tak |
| 5xx, błędy sieci, opuszczone żądania (sklep) | 0 | 0 | | tak |
| 409 w zmianie ilości koszyka (`PUT …/items/{variantId}` z ilością 2) | 4 / 15 / 25 | 9 / 26 / 38 | | **nie: niepowodzenia w obu wariantach** |
| 400 w mieszance panelu (`q=z`, fraza krótsza niż 2 znaki): osobno, nie wchodzi do werdyktu P4 | 0 | 2 / 2 / 2 | | (W7 je liczy) |

**Źródło 409 (z kodu i zapisanych odpowiedzi).** Wszystkie 409 pochodzą z klasy `shop_cart_change` (`panel.json`: 4/15/25 bez panelu, 9/26/38 z panelem, na 901 zmian w przebiegu; w pozostałych klasach sklepu 0).
Kontrakt `PUT /api/v1/guest-carts/{cartId}/items/{variantId}` (`GuestCartController::changeItem`): 409 przy zwiększeniu ilości „powyżej dostępnych jednostek” albo wariantu bez ceny lub stanu; scenariusz zwiększa ilość z 1 do 2, więc 409 dostaje koszyk
z wariantem, który ma mniej niż 2 dostępne jednostki. Koszyk niczego nie rezerwuje, więc liczba 409 nie wynika z zajmowania stanu przez przebiegi. **HIPOTEZA (nie sprawdzona bez pomiaru):** liczba zależy od losowego zbioru 60 produktów, który `discover()` wybiera w każdym
przebiegu (udział wariantów z jedną jednostką); w kolejności przebiegów (4, 9, 15, 26, 25, 38) widać wzrost, więc nie wykluczam też spadku dostępności wariantów w trakcie sesji (np. zamówienia z workera). Zapisałem stan stanów wariantów tylko w zakresie `panel.json`, więc tego nie rozstrzygam.
Nie jest to zachowanie sklepu pod panelem (jest w przebiegach bez panelu), tylko własność scenariusza; do zadania 7 (powtórzenie P4 po zmianach, ta sama mieszanka i ten sam scenariusz) zostaje: wybrać warianty ze stanem co najmniej 2 albo zmieniać ilość na 1 (zmiana ilości w dół
zawsze się udaje), żeby kryterium „brak niepowodzeń” mogło być spełnione; zmiana scenariusza po pomiarze „przed” wymaga powtórzenia pomiaru „przed”.

Zasoby PostgreSQL na przebieg: bez panelu 1,0–1,2 aktywnej sesji, CPU Postgresa ok. 15%, 6 mln trafień buforów, 30–38 tys. odczytów, 0,24–0,31 GB odczytu, `temp_bytes` 0; z panelem 10,5 aktywnej sesji (maks. 14), CPU Postgresa 1036–1057%
(≈ 10 rdzeni), 113 mln trafień i 363 mln odczytów buforów (2,9 TB z cache systemu), `temp_bytes` 0; WAL bez zmiany (2,2–2,4 MB wobec 2,7–3,6 MB). Różnicę przypisuję 8 wątkom najcięższych zapytań panelu (klasy z macierzy powyżej 2 s): **HIPOTEZA**; nie rozdzielałem wkładu mieszanki 5/s, 8 wątków i zimnych granic mapy strony, a specyfikacja wymaga wskazania zapytania, które różnicę powoduje. Wskaże je pomiar w PERF-7-7 (przebiegi z każdą składową osobno).
W15 (CPU węzła do 70%): lokalnie nie oceniam (host 16 rdzeni, współdzielony).

**P4 na `medium`: kryterium z specyfikacji niespełnione: różnica median ponad rozrzut we wszystkich sześciu miarach (rozrzut bez panelu bardzo mały), wpływ bezwzględny kilkanaście milisekund, oraz niepowodzenia sklepu (409 w zmianie ilości koszyka) w obu wariantach.** Narzędzie liczy dla P4 niepowodzenia tylko po stronie sklepu (spec: „błędy sklepu”); niepowodzenia mieszanki panelu wypisuje osobno (W7 je liczy). Pierwsza próba P4 (odrzucona: odnowienie tokenu odmówione w 6. przebiegu,
za mało adresów, brak rozgrzewki) ma w `results/medium/p4/attempt1/` tylko raport (surowe przebiegi usunięto z dowodów). Wynik na `server` niezmierzony; zależy od PERF-2B (zastrzeżenie 5).

## 7. Mapa strony: stan obecny (M0)

`DbalSitemapProductSource::boundaries` (`row_number()` po całym kluczu głównym): 225 ms mediany (254 ms pierwszy), 840 453 bufory, 2 000 382 wiersze, na `medium`; zmienna kosztu: produkty (każdy poprawny wariant czyta wszystkie klucze).
Na `server` SZACUNEK: ok. **2,25 s w pamięci** (powyżej budżetu 2000 ms z propozycji) i 8,4 mln buforów (z dysku według założeń sekcji 2.3 wielokrotnie więcej; patrz zastrzeżenie 1). Porcja (`publishedInRange`): 4 bufory, 0,0 ms (stała, ograniczona `row_limit`).
Zimne obliczenie granic w P4 (co 30 s, cache wyczyszczony) jest częścią obciążenia „z panelem”. Warianty M1 i M2: sekcja 9.4.

## 8. Koszt zapisu i rozmiar prototypowych indeksów (P8; kopia `medium`)

Prototypy zbudowane na `shop_proto` (budowa nie `CONCURRENTLY`), rozmiary i czasy budowy na `medium` (rozmiar `server` SZACUNEK: razy wzrost zmiennej):

| indeks (wariant) | rozmiar `medium` | czas budowy `medium` | rozmiar `server` (SZACUNEK) | dla porównania obecny |
|---|---|---|---|---|
| `(primary_category_id, name, id)` (K1) | 93 MB | 3 s | ok. 0,93 GB | `(name, id)` 111 MB |
| `(primary_category_id, updated_at, id)` (K1) | 56 MB | 1 s | ok. 0,56 GB | `(updated_at, id)` 71 MB |
| GIN `perf_bigrams(name)` (F2) | 64 MB | 37 s | ok. 0,64 GB (spec szacował ok. 2 GB) | GIN trygramów nazwy 248 MB |
| GIN bigramów wyrażenia wyszukiwania zamówień (Z1) | 66 MB | 56 s | ok. 0,33 GB | GIN trygramów 131 MB |
| GIN bigramów `lower(email)`, `lower(full_name)` klientów (Z1) | 6,0 MB, 3,4 MB | 4 s, 2 s | ok. 53 MB, 30 MB | 13 MB, 9 MB |

WAL na zapis (bajty na zapis, mediana z dwóch przebiegów; „zimny” = pierwszy przebieg po `CHECKPOINT`, ze stronami w pełnych obrazach; „flush” = WAL opróżnienia list oczekujących indeksów GIN, który należy do kosztu zapisu; `scripts/write-cost.sql`):

| zapis | stan bazowy | + K1 | + K1 + F2 | + K1 + F2 + Z1 |
|---|---|---|---|---|
| produkt (zapis z `updated_at`, zimny) | 22,6 KB + flush 15,2 KB | 35,8 KB + 17,1 KB | 36,7 KB + 30,5 KB | 37,0 KB + 33,0 KB |
| cykl zamówienia (złożenie, płatność, wysyłka, dostawa; zimny) | 35,4 KB + 23,8 KB | 38,3 KB + 22,8 KB | 36,9 KB + 23,3 KB | 42,0 KB + 42,2 KB |
| zapis klienta (zimny) | 14,0 KB + 6,4 KB | 14,9 KB + 6,3 KB | 14,9 KB + 7,7 KB | 16,8 KB + 13,0 KB |

Procenty poniżej są względne do **syntetycznego zapisu jednego wiersza** po `CHECKPOINT` (zimne strony, bez równoległości, bez pełnego zapisu produktu z wariantami i zdjęciami) i nie przenoszą się na ruch produkcyjny; miarą główną są przyrosty w KB. Efekt wariantu (zimny, razem z flush): **K1 +15 KB na zapis produktu (+40%)**: dwa indeksy z losowym liściem na każdą nie-HOT aktualizację; **F2 +14 KB (+38%)** przez flush listy oczekujących GIN; **Z1 +24 KB na cykl zamówienia (+42%) i +9 KB na zapis klienta (+45%)**.
Zapisy „gorące” (strony już w WAL od checkpointu): różnice małe (produkt 5,6–6,1 KB). Czas na zapis jest zaszumiony obciążeniem hosta (0,2–5 ms); wiarygodny jest WAL. Licznik obrazów stron (`pg_stat_wal.wal_fpi`) nie odświeża się w pętli, więc obrazy
wynikają z różnicy zimny/gorący. Surowe wyniki: `results/medium/write-cost/`, tabela: `results/medium/tables/write-cost.md`. Pomiary W6, W11 i W20 z nowymi indeksami: zadanie 7 (po wdrożeniu).

## 9. Warianty (prototypy na kopii `medium`)

Reguła (spec, „Warianty”): w każdym obszarze od najprostszego wariantu, stop na pierwszym, który spełnia P2 i P8. P2 oceniam według ekstrapolacji „w pamięci” (SZACUNEK, sekcja 2.3); kolumna „z dysku” jest założeniem
(zastrzeżenie 1) i **nie rozstrzyga**. Prototypy biegną na `shop_proto` (kopia `medium`, z indeksami prototypów; po pomiarach kosztu zapisu ma ok. 3000 zmienionych produktów i 500 dopisanych zamówień, co nie zmienia
planów). Równoważność wyników z obecnym SQL (`scripts/equiv.py`, te same wiersze, kolejność, kursory, liczniki) sprawdzona na `medium` dla każdego rekomendowanego wariantu: **K1 82 pary, F1 72 pary, F2 72 pary, Z1 44 pary: 0 różnic**
(K0 24 pary na `small`: 0 różnic; kursory stron 2 rysowane osobno dla obecnego SQL i prototypu dostają kursor prototypu, bo baza prototypów miała zapisy). Surowe wyniki: `results/medium/variants/<wariant>/`.

### 9.1 Filtr kategorii: K0 → K1

- **K0** (dwie gałęzie połączone `UNION`, sort i `LIMIT`; bez zmiany schematu): naprawia małą kategorię i liczniki (0,3 ms i 0,2 ms na `medium`), ale czyta i sortuje całe poddrzewo, więc **dział (206 849 produktów)
  kosztuje 411 ms (str. 1 `name`), 1085 ms (str. 1 `-updatedAt`) i 411 ms (str. 2) na `medium` wobec 0,2 / 17 / 0,2 ms dziś: 4,1 s, 10,8 s i 4,1 s w pamięci na `server`. P2 niespełnione (8 przypadków powyżej 2000 ms: 4 strony działu, 3 kombinacje działu z filtrem i licznik „głównie dodatkowa + marka” 2,1 s); kategorie średnie i duże 0,5–0,9 s (w pamięci mieszczą się). K0 odrzucony.
- **K1** = K0 + btree `(primary_category_id, name, id)` i `(primary_category_id, updated_at, id)`: jedna uporządkowana gałąź z `LIMIT` na kategorię poddrzewa i jedna gałąź produktów będących w poddrzewie wyłącznie przez kategorię dodatkową
  (członkostwa poddrzewa złączone z produktami, bez tych, których kategoria główna jest w poddrzewie, jako zbiór `MATERIALIZED`; jawne złączenie jest konieczne: plan z `IN (…)` wraca do przejścia po `(name, id)` i czyta tabelę).
  Dwie wcześniejsze postacie K1 (dodatkowa gałąź jako `IN`: do 1,1 s na `medium`; z dedupem `DISTINCT ON` bez odfiltrowania głównych: dział 176–200 ms) mają w `variants/K1-first-form`, `K1-second-form` tylko tabelę `compare.md`.
  **Deduplikacja (I2).** Domena pozwala na do 10 kategorii dodatkowych produktu (`ProductCategories::MAX_ADDITIONAL`), więc produkt może mieć kilka członkostw w jednym poddrzewie; w danych demo żaden nie ma dwóch w jednym poddrzewie, więc wcześniejsze wyniki tego nie pokazywały. Syntetyczny produkt (`scripts/synthetic-two-additional.sql`: nazwa pierwsza w kolejności, `updated_at` z przyszłości, kategoria główna poza działem,
  dwie kategorie dodatkowe w dziale) dał **RED**: dotychczasowy K1 zwracał go dwa razy na górze strony działu (`equiv.py --only department`: 2 różnice w 14 parach) i liczył podwójnie (licznik bez limitu: 206 850 obecne, **206 851** stara formuła K1; `results/medium/variants/K1/exact-count.txt`).
  Poprawka: zbiór `additional` jest `SELECT DISTINCT` (jeden wiersz na produkt), licznik analogicznie; **GREEN: 82 pary bez różnic z syntetycznym produktem w bazie** (`variants/K1/equiv.txt`; RED w `variants/K1/equiv-red-before-dedup.txt`: 14 par działu, 2 różnice), licznik bez limitu 206 850 (`exact-count.txt` mierzy formułę ze złączeniem i półzłączeniem, nie końcowe `DISTINCT`, którego pokrywają 82 pary `equiv.txt`; licznik `equiv.py` ma limit 10 001, więc w dziale sam nie wykryłby podwójnego liczenia). Wariant z półzłączeniem `IN (…)` dawał te same wiersze, ale 2× wolniej (350–380 ms dla działu), więc odrzucony. Koszt `DISTINCT` jest w pomiarach niżej (dział 169–189 ms, jak przed poprawką).
  Ograniczenie: dane demo nie mają produktów z kilkoma kategoriami dodatkowymi w jednym poddrzewie, więc koszt tego przypadku (jeden syntetyczny produkt) jest niezmierzony w skali; deduplikacja działa na zbiorze liniowym w członkostwach poddrzewa, jak sam zbiór.

Porównanie (mediana `medium` ms → `server` w pamięci ms, SZACUNEK; **NIE** = powyżej 2000 ms):

| kategoria | przypadek | obecne: medium ms → server w pamięci ms | K0 | K1 |
|---|---|---|---|---|
| mała (15) | str. 1 `name` | 3067.9 → 30674 **NIE** | 0.3 → 3 | 6.9 → 69 |
| mała (15) | str. 1 `-updatedAt` | 894.4 → 8942 **NIE** | 0.3 → 3 | 6.9 → 69 |
| mała (15) | licznik | 435.4 → 4354 **NIE** | 0.2 → 2 | 6.8 → 68 |
| średnia (10 585) | str. 1 `name` | 286.2 → 2862 **NIE** | 50.7 → 507 | 8.3 → 83 |
| średnia (10 585) | str. 1 `-updatedAt` | 4.6 → 46 | 48.6 → 486 | 8.3 → 83 |
| średnia (10 585) | str. 2 `name` | 6.1 → 61 | 49.9 → 498 | 8.3 → 83 |
| średnia (10 585) | licznik | 504.5 → 5044 **NIE** | 18.5 → 185 | 9.1 → 91 |
| duża wczesna (15 199) | str. 1 `name` | 0.2 → 2 | 64.4 → 643 | 10.1 → 101 |
| duża wczesna (15 199) | str. 1 `-updatedAt` | 4.0 → 40 | 64.6 → 646 | 9.4 → 94 |
| duża wczesna (15 199) | str. 2 `name` | 0.2 → 2 | 67.6 → 676 | 9.6 → 96 |
| duża wczesna (15 199) | licznik | 360.7 → 3606 **NIE** | 17.7 → 177 | 1.4 → 14 |
| duża późna (22 829) | str. 1 `name` | 356.3 → 3562 **NIE** | 93.6 → 936 | 10.2 → 102 |
| duża późna (22 829) | str. 1 `-updatedAt` | 3.0 → 30 | 89.1 → 891 | 10.0 → 100 |
| duża późna (22 829) | str. 2 `name` | 3.7 → 37 | 92.0 → 920 | 10.4 → 104 |
| duża późna (22 829) | licznik | 211.7 → 2117 **NIE** | 18.5 → 185 | 1.6 → 16 |
| dział (206 849) | str. 1 `name` | 0.2 → 2 | 411.0 → 4109 **NIE** | 169.3 → 1692 |
| dział (206 849) | str. 1 `-updatedAt` | 17.1 → 171 | 1084.7 → 10845 **NIE** | 169.8 → 1698 |
| dział (206 849) | str. 2 `name` | 0.2 → 2 | 410.5 → 4104 **NIE** | 178.6 → 1786 |
| dział (206 849) | licznik | 66.5 → 664 | 62.6 → 626 | 2.0 → 20 |
| głównie dodatkowa (6 689) | str. 1 `name` | 5.7 → 57 | 16.5 → 164 | 9.8 → 98 |
| głównie dodatkowa (6 689) | str. 1 `-updatedAt` | 5.9 → 59 | 14.9 → 149 | 9.8 → 98 |
| głównie dodatkowa (6 689) | str. 2 `name` | 4.8 → 48 | 15.2 → 152 | 9.6 → 96 |
| głównie dodatkowa (6 689) | licznik | 517.3 → 5172 **NIE** | 12.3 → 123 | 9.7 → 97 |

- K1 mieści wszystkie przypadki kategorii w 2000 ms w pamięci. Najwyższe: dział (str. 1 `name` 1,69 s, str. 2 1,79 s, `-updatedAt` 1,70/1,88 s): koszt liniowy w członkostwach dodatkowych poddrzewa (ok. 40 tys. wierszy `catalog_product_category` na `medium`, ok. 400 tys. na `server`:
  168 tys. buforów na `medium`, 1,69 mln na `server`); zapas 6–15% od limitu. Kategorie małe, średnie, duże i „głównie dodatkowe”: 7–11 ms na `medium`, 0,07–0,11 s na `server`; liczniki 1,6–9 ms.
- Przypadki z dodatkowym filtrem obok kategorii (`results/medium/tables/k1-combinations.md`): kategoria + fraza `pro`, + szkice, + bez zdjęć: 7–25 ms na `medium` (`server`: 0,07–0,25 s) dla małych, średnich, dużych i „głównie dodatkowych”.
  **Dział z dodatkowym filtrem:** po poprawce K1 mieści się dział + marka (`%pro%`) 1,92 s, dział + bez zdjęć (strona 1,67 s i 0,62 s, licznik 1,69 s), dział + szkice licznik 1,10 s i `name` 1,44 s; **nie mieści się dział + szkice `-updatedAt`: 10,7 s** (703–1211 ms na `medium`, 350 tys. buforów).
  Te same przypadki w poprzednich przebiegach K1 miały inne wyniki (dział + szkice `name`: 12,6 s, w następnym przebiegu 1,4 s; dział + marka 2,04 s i 1,92 s): plan zależy od statystyk po `ANALYZE`, a przypadki są na granicy, więc wynik „mieści się” dla działu z filtrem jest niepewny, zob. 10.2 (częściowe indeksy po kategorii usuwają zmienność).
  Przyczyny z planów i **zmierzone rozwiązania: sekcja 10.2**.
- **Koszt zapisu K1 (P8, sekcja 8):** dwa indeksy 93 + 56 MB (`server` ok. 1,5 GB, SZACUNEK), zbudowane w 3 s i 1 s na `medium`; zapis produktu +13,3 KB WAL (+59%), z flush +15 KB (+40%) przy zimnych stronach (każda aktualizacja zmienia `updated_at`,
  więc jest nie-HOT i dopisuje wpis do obu nowych indeksów w losowym liściu). `catalog_product` zapisują edycje produktu i generator (cena i stan idą do innych tabel), więc częstość tych zapisów jest niska; wpływ na W20 (generator z dwoma dodatkowymi indeksami przy 10 mln)
  zmierzy zadanie 3.
- **K2 i K3 nie były prototypowane** (K1 mieści P2 dla kategorii bez filtra; dla R2 są zmierzone tańsze rozwiązania, sekcja 10.2), więc przegląd miejsc zapisu `git grep` nie jest potrzebny. Kopia kluczy sortowania (K2/K3) usunęłaby koszt członkostw dodatkowych działu (zapas 6–15% → ok. ms),
  za cenę zmiany mapowania asocjacji i utrzymania kopii w każdym miejscu zapisu (CRITICAL); rekomenduję ją tylko, jeśli pomiar na `server` pokaże dział powyżej 2000 ms.

### 9.2 Fraza: F1 → F2

- **F1** (spacer po indeksie sortowania z budżetem B = 20 000 wierszy, potem kandydaci z GIN trygramów i sort): spacer kosztuje 14–40 ms (stały, nie rośnie z danymi) i wypełnia stronę, gdy trafienia są gęste w prefiksie porządku; inaczej dochodzą kandydaci (liniowo w trafieniach).
  Poprawia frazy skorelowane z sortem: `treningowe` (17 200 trafień) 2423 → 140 ms na `medium` (24,2 s → 1,26 s na `server` w pamięci); `zupa` 187 → 214 ms (bez zmiany); `źródło` i polskie znaki 15–25 ms; wszystkie frazy częste 22–47 ms.
  **Nie pomaga fragmentom bez trygramu** (`#!#`, `---`, `a.b`, 2 znaki rzadkie): spacer niczego nie znajduje, kandydaci przechodzą ten sam GIN (1,43–1,49 s na `medium`, 14,4–14,9 s na `server`).
  **Nie mieści frazy częstej i skorelowanej z sortem:** `rowerowa` (60 168 trafień, 601 tys. na `server`) sort `name` 297 ms na `medium`, 2,83 s w pamięci na `server` (R1; licznik 295 ms).
- **F2** (F1 + GIN bigramów `perf_bigrams(name)` dla fraz, z których pg_trgm nie wyciąga trygramu): kandydaci dla `#!#`, `---`, `a.b`, `qx`, `zx` 0,0–0,1 ms (wobec 650–1490 ms), liczniki 0,0 ms (wobec 240–670 ms): **każdy przypadek bez trygramu 14–25 ms na `medium` (0,14–0,25 s na `server`, SZACUNEK; z trafieniami 15–32 ms, I3 niżej)**.
  Fraza z trygramem zostaje przy F1 (bigramy dają dla niej gorsze listy: `rowerowa` 2,2 s), więc do wdrożenia: F1 dla frazy z trygramem, F2 dla frazy, z której pg_trgm trygramu nie wyciąga (kryterium wyboru drogi do ustalenia w zadaniu 2).
- Wynik kombinacji F1+F2 (SZACUNEK „w pamięci”; wszystkie przypadki macierzy fraz poza jednym ≤ 1,3 s): w 2000 ms mieszczą się wszystkie fragmenty bez trygramu i frazy z trygramem poza `rowerowa/name` (2,83 s, R1).
- **F2 dla fraz bez trygramu, ale z trafieniami (I3, `scripts/i3.py`, `variants/I3/`).** Nazwy demo nie mają interpunkcji i 2-znakowych fraz rzadkich, więc przypadki z trafieniami są syntetyczne (znaczniki dopisane do losowych nazw na `shop_proto`, zdjęte po pomiarze; rozmieszczone losowo, nie w prefiksie kolejności):
  `#!#` 250 trafień, `a.b` 12 000, `qx` 250, a z danych `gg` 12 828 i `ze` 155 298 (mediana `medium` ms: obecne p1 `name` / F1 / F2; server w pamięci w nawiasie, SZACUNEK):

  | fraza (trafień) | obecne | F1 | F2 |
  |---|---|---|---|
  | `#!#` rzadka (250) | 1453 (14,5 s) | 1464 (14,6 s) | **16 (0,16 s)**, licznik 1,7 |
  | `qx` rzadka (250) | 1457 (14,6 s) | 1478 (14,8 s) | **15 (0,15 s)**, licznik 1,1 |
  | `gg` średnia (12 828) | 2001 (20,0 s) | 1490 (14,9 s) | **32 (0,32 s)**, licznik 14,8 |
  | `a.b` średnia (12 000) | 4,2 (spacer znajduje strony) | 16,5 | 16,5 |
  | `ze` częsta (155 298) | 1,1 | 19 | 19, licznik 140 (obecny 31) |

  **F2 nadal mieści P2 dla fraz bez trygramu, także z trafieniami.** Wynik jest zgodny z kosztem liniowym w trafieniach (kandydaci bigramów: ok. 0,13 ms na 100 trafień poza spacerem); fraza 2-znakowa średnia skorelowana z sortem o setkach tysięcy trafień na `server` byłaby klasy R1 (kandydaci), nie zmierzona.
  **Kryterium wyboru drogi F2 jest regułą do ustalenia testem planu w PERF-7-2, nie opisem działania pg_trgm.** Kandydaci reguły: fragment bez słowa (ciągu liter i cyfr) dłuższego niż 2 znaki idzie drogą F2, albo droga wynika z oszacowania planisty.
  Dane z macierzy i I3: `#!#` i `---` nie dają trygramu (`show_trgm` zwraca `{}`); dla `a.b`, `qx` i `gg` `show_trgm` zwraca trygramy z dopełnieniem krawędzi słowa, a plan pokazuje, że GIN zwraca 31–40% tabeli (`qx` 400 265 wpisów z 1 000 191, `a.b` 311 167), czyli pg_trgm wyciąga z wzorca `%a.b%` trygram
  nieselektywny, nie żaden; `ab-cd` ma trygram selektywny (0,1 ms). Reguła „słowo ≤ 2 znaki” myli się więc na `ab-cd` (wyszłaby droga F2, a F1 jest szybka), więc rozstrzygnie test planu (frazy `#!#`, `---`, `a.b`, `qx`, `ab-cd`).
- **Koszt F2 (P8):** indeks 64 MB (`server` ok. 0,64 GB, SZACUNEK; spec szacował ok. 2 GB; obecny GIN trygramów 248 MB) zbudowany w 37 s; zapis produktu +0,8 KB WAL zimno plus flush listy oczekujących GIN (+13 KB), razem **+14 KB (+38% zapisu bez nowych indeksów, +27% zapisu z K1)**; czasy zapisu zaszumione obciążeniem hosta.

### 9.3 Zamówienia i klienci: Z0 → Z1

- **Z0** (bez zmian): fragmenty bez trygramu (`#!#`, `---`) w zamówieniach 300 / 310 ms (str. 1), 242 / 247 ms (licznik), 241 / 249 ms (sort `-total`) na `medium`, **1,2–1,5 s w pamięci na `server`** (5 mln zamówień, SZACUNEK); u klientów 25 ms, 0,22 s na `server`. Pozostałe fragmenty (`a.b`, `ab-cd`, `ąęś`, `kowal`, `R-…`,
  pełny e-mail) mają trygram lub indeks i mieszczą się wielokrotnie. **Z0 spełnia P2 w pamięci** (zapas 22–40% dla zamówień) i nie zmienia kosztu zapisu, więc według reguły to wybór; „z dysku” (założenie) zamówienia kosztowałyby ok. 183 s / 11 s, ale tego nie uznajemy za dowód.
- **Z1** (GIN bigramów na wyrażeniach dzisiejszych indeksów trygramów): `#!#` i `---` 0,0–0,1 ms (zamówienia i klienci), ale **bigramy dla fragmentów, które mają trygram, są gorsze** (`ann`: 2,4 s wobec 0,6 ms), więc Z1 służy tylko fragmentom bez trygramu.
  Rozmiar: zamówienia 66 MB (`server` ok. 0,33 GB), klienci 6,0 + 3,4 MB (`server` ok. 53 + 30 MB); budowa 56 s, 4 s i 2 s. **Koszt zapisu (P8): cykl zamówienia (złożenie, płatność, wysyłka, dostawa) +24 KB WAL (+42%: 59 KB → 84 KB z flush), zapis klienta +9 KB (+45%).**
  Zapis ścieżki zamówienia (W6, W11) jest gorącą ścieżką sklepu, a fragment bez trygramu to rzadki przypadek wyszukiwania w panelu; W6 i W11 z nowym indeksem zmierzy zadanie 7, gdyby Z1 zatwierdzono.
- Wybór: **Z0**, z Z1 jako gotowym wariantem, jeśli pomiar na `server` pokaże przekroczenie (decyzja właściciela, sekcja 10).

### 9.4 Granice mapy strony: M0 → M1 → (M2)

- **M0** (stan obecny): 225 ms mediany na `medium`, 840 453 bufory; na `server` ok. 2,25 s w pamięci i 8,4 mln buforów. Powyżej budżetu 2000 ms w pamięci.
- **M1** (keyset `WHERE id >= :poprzednia ORDER BY id OFFSET :n LIMIT 1` do końca; `scripts/sitemap-m1.sql`): 101 zapytań, 100 granic, **89–111 ms w bazie (3 przebiegi), najwolniejsze zapytanie 2,4 ms**, granice **identyczne z M0** (sprawdzone w pętli).
  Jedno zapytanie czyta ok. 10 tys. wpisów indeksu (8,5 tys. buforów); razem tyle samo co M0 (8,4 mln buforów na `server`), bo G2 wymaga odczytu wszystkich kluczy; różnica to brak funkcji okna i sortowania. Na `server` ok. 1001 zapytań, ok. 0,9–1,1 s w bazie
  plus 1001 podróży do bazy (SZACUNEK 0,2–0,5 ms każda): **ok. 1,1–1,6 s** (mieści się w 2000 ms w pamięci; przy czytaniu z dysku liniowo więcej, założenie). Cięcie z wielu zapytań wymaga jednej transakcji `REPEATABLE READ` tylko do odczytu (G2), co zadanie 4 testuje.
- **M2** (granice kandydujące z `pg_stats.histogram_bounds`, każda porcja sprawdzona licznikiem `LIMIT row_limit + 1`; zmierzone tylko dla porównania, M1 już wystarczał): 102 porcje, największa 11 326 wierszy (poniżej `row_limit` 50 000), weryfikacja 127–225 ms,
  `ANALYZE` z `SET STATISTICS 100` 2,1 s, z 1000 (potrzebne dla 10 mln produktów) 26,8 s (próbka 300 tys. wierszy, niezależna od rozmiaru tabeli). **Brak zysku względem M1** (taki sam odczyt wszystkich kluczy, więcej części ruchomych i zmiana decyzji D1 w `docs/features/seo/spec.md`). M2 odrzucony.
- Brak kosztu zapisu M0/M1; P5 (czas zimnego obliczenia na `server`): niezmierzony (R5), szacunek M1 1,1–1,6 s.

### 9.5 Tabela zbiorcza wariantów (SZACUNEK dla `server`, „w pamięci”)

| obszar | wariant | najgorszy przypadek obszaru spełniający / niespełniający 2000 ms | koszt zapisu (P8, zimny, z flush) | rozmiar (`server`) | status |
|---|---|---|---|---|---|
| kategoria | K0 | dział str. 1 `-updatedAt` 10,8 s (NIE) | brak | brak | odrzucony |
| kategoria | **K1** (z deduplikacją) | dział 1,88 s (zapas 6%); dział + szkice `-updatedAt` 10,7 s i dział + fraza średnia 4,0–9,0 s (NIE, R2: sekcja 10.2) | +15 KB (+40%) na zapis produktu | ok. 1,5 GB | **rekomendowany** |
| fraza | F1 | `rowerowa` 2,83 s (NIE, R1); bez trygramu 14,4 s (NIE) | brak | brak | niewystarczający sam |
| fraza | **F1 + F2** | `rowerowa` 2,83 s (NIE, R1; spacer index-only 1,6 s, 10.2); reszta ≤ 1,3 s; fraza bez trygramu z trafieniami 0,15–0,32 s (I3) | +14 KB (+38% zapisu bez nowych indeksów) na zapis produktu (F2) | ok. 0,64 GB (F2) | **rekomendowany** |
| zamówienia, klienci | **Z0** | zamówienia `#!#` 1,5 s | brak | brak | **wybór wg reguły** |
| zamówienia, klienci | Z1 | wszystko ≤ 0,0 ms dla `#!#`/`---` | +24 KB (+42%) na cykl zamówienia, +9 KB (+45%) na klienta | ok. 0,33 GB + 83 MB | gotowy, niewybrany |
| mapa strony | M0 | 2,25 s (NIE) | brak | brak | stan obecny |
| mapa strony | **M1** | 1,1–1,6 s | brak | brak | **rekomendowany** |
| mapa strony | M2 | jak M1 | brak (statystyki 1000) | brak | odrzucony (bez zysku) |

## 10. Wybór wariantów, przypadki niespełnione, pytania do właściciela

### 10.1 Rekomendacja i uzasadnienie

1. **Kategoria: K1.** Najprostszy wariant, który mieści przypadki kategorii w P2 (K0 rozbija dział: 4–11 s). Zmiana ogranicza się do zapytania i dwóch indeksów (dwie migracje `CONCURRENTLY`), bez zmiany modelu i miejsc zapisu. Koszt: 150 MB na `medium`, ok. 1,5 GB na `server`, +15 KB WAL na zapis produktu.
2. **Fraza: F1 dla fraz z trygramem, F2 dla fraz bez trygramu.** F1 sam nie pomaga fragmentom bez trygramu (to dokładnie ta klasa, dla której F2 istnieje w specyfikacji). Koszt F2: 0,64 GB i +15 KB WAL na zapis produktu.
3. **Zamówienia i klienci: Z0** według reguły specyfikacji (Z0 spełnia P2 w pamięci w pomiarze izolowanym macierzy; SZACUNEK). Wpływ Z0 na P4 jest **niepotwierdzony**: P4 jest niespełnione, a udziału klas `orders_punct` i `customers_punct` w obciążeniu nie mierzyłem osobno. Pod obciążeniem mieszanki klasa `orders_punct` ma na `medium` p95 630 ms i maks. 825 ms (pytanie 10.3.3). Z1 gotowy.
4. **Mapa strony: M1.** Mieści budżet 2000 ms w pamięci (SZACUNEK 1,1–1,6 s), gwarancje G1–G3 bez zmian, w transakcji `REPEATABLE READ`.
5. **Front (PERF-7-6):** H2 potwierdzona: anulowanie żądania nie zatrzyma zapytania w Postgresie; jedyną ochroną kosztu jest `statement_timeout` z PERF-1.

### 10.2 Przypadki bez wariantu spełniającego P2: przyczyna z planu, rozwiązania, rozstrzygające sprawdzenie

Właściciel nie akceptuje wyjątków W7, więc przypadki z tabeli nie zostają wyjątkami; dla R1 i R2a–R2c rozwiązania są zmierzone, dla **R2d niezmierzone** (kolumna „rozwiązanie”). Dla każdej klasy podaję przyczynę z zapisanych planów (`results/medium/variants/F1/explain/`, `…/K1/explain/`), możliwe rozwiązania z pełną funkcjonalnością, koszt zapisu i ryzyko,
oraz tanie sprawdzenie na `shop_proto`, które rozstrzyga (faza B poprawek). Liczby `server` to SZACUNEK „w pamięci”; analiza korzysta wyłącznie z zapisanych planów i pomiarów.

| przypadek | wartość `server` (SZACUNEK) | co jest liniowe i w czym | rozwiązanie |
|---|---|---|---|
| R1: fraza częsta skorelowana z sortem `name` (`rowerowa`: 60 168 → 601 577 trafień) | 2,83 s (F1); licznik 0,30 s | odczyt sterty kandydatów GIN: ok. strona na trafienie | zmierzone: spacer index-only 0,9–1,6 s |
| R2a: dział + fraza częsta (`%pro%`), `str. 1 name` | 1,92 s (K1 z deduplikacją; 2,04 s przed nią): graniczne | odczyt produktu po PK dla każdego członkostwa dodatkowego działu (41 772 → ok. 418 tys.) | zmierzone: kolumna kategorii głównej 17 ms zamiast 115 ms |
| R2b: dział + szkice, `-updatedAt` / `name` | **10,72 s** / 1,44 s; **niestabilne**: `name` było 12,6 s w przebiegu sprzed deduplikacji, `-updatedAt` 7,1 s | każda z 26 gałęzi kategorii przechodzi wszystkie szkice (20 108 → ok. 201 tys.) globalnego częściowego indeksu | zmierzone: częściowe btree po kategorii, 1095 → 110 ms |
| R2c: dział + bez zdjęć, licznik | 1,69 s (2,17 s przed deduplikacją): graniczne, **niestabilne** | wiersze działu z indeksu kategorii filtrowane indeksem bez zdjęć (przyczyna z planu niezachowanego w dowodach: hipoteza) | zmierzone: częściowe btree po kategorii, 181 → 11 ms |
| R2d: dział + fraza średnia (`snowboardowe`, 12 952 trafień), `name` / `-updatedAt` | **4,0 s / 9,0 s** | wszystkie wiersze działu (223 tys. buforów na `medium`) przechodzone przez gałęzie z filtrem frazy | **niezmierzone** (częściowe indeksy nie zmieniają) |

Przypadki oznaczone jako niestabilne zmieniają plan między przebiegami po `ANALYZE` (zmierzone: ten sam przypadek raz 12,6 s, raz 1,4 s), więc ich wartość `server` jest przedziałem, nie liczbą.

**R1. Fraza częsta skorelowana z sortem.**
- *Przyczyna z planu* (`F1.brand-common.p1.name.gin.out`): Bitmap Heap Scan czyta 52 880 stron sterty dla 60 168 kandydatów (53 559 odczytów), żeby tylko posortować ich nazwy (top-N heapsort 26 z 60 168): 297 ms na `medium`, liniowo w trafieniach (strona sterty na trafienie).
  Spacer F1 z budżetem B = 20 000 wierszy nie znajduje 26 trafień, bo w kolejności `name` pierwsze 26 trafień leży dalej: obecne zapytanie czyta 229 152 wiersze `(name, id)` (ok. 23% nazw), zanim je znajdzie (870 ms, bo każdy wiersz oznacza odczyt sterty na filtr).
- *Rozwiązania:*
  1. **Dłuższy spacer index-only** (nazwa jest w indeksie, więc bez odczytu sterty): spacer F1 kosztuje 14 ms za 20 000 wierszy (ok. 0,7 µs na wiersz), więc dojście do 26. trafienia (229 tys. wierszy) to ok. 0,16 s na `medium` i ok. 1,6 s na `server` przy zachowaniu rozkładu (SZACUNEK z kosztu na wiersz, niezmierzony).
     Zapis: bez zmian. Ryzyko: koszt zależy od miejsca 26. trafienia w kolejności nazw (rozkład, nie wolumen), a nie od liczby trafień; trzeba wybierać drogę: budżet rosnący geometrycznie (20 tys., 200 tys., 2 mln wierszy; koszt ≤ ok. 2× ostatniego kroku) i przejście na kandydatów, gdy licznik GIN pokazuje mało trafień.
  2. Tańszego sortu kandydatów nie ma bez nowej struktury (GIN nie zwraca nazwy); struktury wymagające nowego rozszerzenia (np. trygramy w GiST z kluczem sortowania) nie były badane.
- *Wynik sprawdzenia (faza B; `scripts/r-checks.py`, `variants/R/r-compare.md`):* spacer index-only bez budżetu do 26. trafienia: `rowerowa` (60 168 trafień) 159 ms i 202 726 buforów, `pływacki` (25 720) 137 ms, `snowboardowe` (12 952) 92 ms, `zupa` 21 ms, `źródło` 2,5 ms, `bagażnik` 3 ms;
  koszt ok. 0,7 µs na wiersz. **Na `server` (rozkład zachowany): 1,6 s, 1,4 s i 0,9 s w pamięci (SZACUNEK; zapas 20–55%)**, czyli rozwiązanie 1 mieści R1 w P2, wobec 2,83 s kandydatów. **Zastrzeżenie:** spacer „index-only” ma bufory porównywalne z liczbą wierszy (`rowerowa`: 229 179 wierszy, 202 726 buforów), więc prawdopodobnie czyta stertę przez mapę widoczności po zapisach (tabela po generatorze i `VACUUM`); bez linii `Heap Fetches` w planach (nie zachowałem ich) to niepotwierdzone, a przy częstych zapisach albo czytaniu z dysku koszt może być wyższy niż 0,7 µs na wiersz. Zależność od wolumenu: udział miejsca 26. trafienia w nazwach (23% dla `rowerowa`);
  w danych demo jest niezależny od wolumenu, w prawdziwym katalogu może go nie być (powtórzyć na `server`). Wybór drogi: liczba trafień z licznika GIN (do 10 001) i budżet geometryczny; przy małej liczbie trafień kandydaci (7–21 ms).

**R2a. Dział + fraza częsta (1,92 s; 2,04 s przed deduplikacją).**
- *Przyczyna z planu* (`K1.department.cat+brand.p1.name.out`): gałęzie kategorii głównych kończą w mniej niż 1 ms (fraza częsta, 26 trafień blisko początku indeksu); cały koszt to CTE `additional`: 168,8 tys. z 169,4 tys. buforów i 101–105 ms ze 169–174 ms.
  Plan to `Unique` nad `Incremental Sort` nad `Nested Loop`: dla każdego z 41 772 członkostw działu odczyt produktu po kluczu głównym (`Index Searches: 41772`), żeby odrzucić te, których kategoria główna jest w poddrzewie (wynik: 0 wierszy). To ten sam koszt, który daje dział bez filtra 1,69–1,88 s, liniowy w członkostwach dodatkowych poddrzewa. Przypadek nie jest „rzadkim przecięciem”.
- *Rozwiązania:*
  1. **Kolumna `primary_category_id` w `catalog_product_category`** (kopia kategorii głównej produktu) i indeks pokrywający `(category_id) INCLUDE (product_id, primary_category_id)`: odrzucenie członkostw, których kategoria główna jest w poddrzewie, bez odczytu produktu (index-only, ok. 42 tys. wpisów).
     Zapis: tylko przy zmianie kategorii głównej albo dodatkowych produktu (rzadko), nie przy każdym zapisie produktu. Ryzyko: spójność kopii w każdym miejscu zapisu (repozytorium z ORM `ManyToMany`, generator `COPY`, `ProductPhotos`, migracje), więc zmiana mapowania na zapis ręczny: to „K2 lite” (jedna kolumna, bez kopii kluczy sortowania),
     z wymaganiami spójności z sekcji „Warianty” specyfikacji (test zgodności, `git grep` miejsc zapisu).
  2. Zostawić K1 bez zmian: dział bez filtra mieści się (1,69–1,88 s), z frazą częstą 1,92 s jest na granicy (4% zapasu); wynik na `server` rozstrzyga.
- *Wynik sprawdzenia (faza B):* tabela próbna `pc_probe` (200 194 członkostw z kolumną `primary_category_id`) z indeksem pokrywającym (13 MB): odrzucenie członkostw działu (9 produktów wyłącznie przez kategorie dodatkowe) **17 ms i 1 920 buforów wobec 115–120 ms i 137 073 buforów** złączenia z produktami (planista wybrał Seq Scan próbnej tabeli; z indeksem byłoby jeszcze mniej). Na `server` ok. 0,17 s zamiast 1,2 s: rozwiązanie 1 usuwa koszt gałęzi dodatkowych; zapis i ryzyko spójności jak w opisie powyżej.

**R2d. Dział + fraza średnia (4,0 s i 9,0 s; rozwiązanie niezmierzone).**
- *Zmierzone (faza B, K1; przed i po częściowych indeksach bez zmian):* **fraza rzadka** (`ravotar`, 2 980 trafień w katalogu) 119 ms i 30 825 buforów (1,2 s na `server`), licznik 17 ms: mieści się; **fraza średnia** (`snowboardowe`, 12 952 trafień) `name` 395–404 ms i 46,8 tys. buforów (4,0 s), **`-updatedAt` 896–902 ms i 223 tys. buforów (9,0 s)**, licznik 74–78 ms: nie mieści się (`variants/R/r-compare.md`).
- *Przyczyna:* gałęzie kategorii przechodzą indeks `(primary_category_id, updated_at, id)` z filtrem frazy przez cały dział (206 tys. wierszy; 223 tys. buforów), bo trafienia frazy są skupione dalej w tym porządku; koszt liniowy w wierszach działu (zasada 5: mniejsza z dwóch wielkości, działu albo kandydatów frazy; tu 13 tys. trafień, a gałąź i tak czyta cały dział). Planu tego przypadku nie zachowałem w dowodach (są liczby z `r-compare.md`).
- *Rozwiązanie niezmierzone (kandydat):* kandydaci frazy z GIN trygramów zawężeni do kategorii (koszt liniowy w trafieniach frazy: dla porównania F1 daje 0,14 s na 17 tys. trafień) zamiast gałęzi po kategorii, wybór po porównaniu rozmiaru działu z liczbą trafień (licznik GIN); bez nowego indeksu. Nie sprawdzone: zadanie 3 powinno zacząć od testu planu tego przypadku.

**R2b. Dział + szkice (10,7 s `-updatedAt`; `name` niestabilnie 1,4–12,6 s).**
- *Przyczyna z planu* (`K1.department.cat+drafts.p1.-updatedAt.out`): `Merge Append` 26 gałęzi kategorii, z których każda wykonuje `Index Scan Backward` po globalnym częściowym indeksie szkiców `catalog_product_draft_updated_at_id_idx` (nie po kategorii) i filtruje kategorię (`Rows Removed by Filter: 20108` na gałąź): 350 379 buforów, 1,29 s w tym przebiegu.
  Liniowe w (kategorie poddrzewa × szkice). Dla sortu `name` plan zależy od statystyk (1,4 s albo 12,6 s w różnych przebiegach); jego planu nie zachowałem, więc przyczyna `name` jest hipotezą tej samej natury.
- *Rozwiązania:*
  1. **Częściowe btree po kategorii** `(primary_category_id, name, id) WHERE status = 'draft'` i `(primary_category_id, updated_at, id) WHERE status = 'draft'`: gałąź czyta tylko szkice swojej kategorii (≤ 26). Rozmiar: tylko szkice (ok. 2% produktów; istniejące częściowe indeksy szkiców mają 2,3 MB i 1,2 MB, więc ok. 3 MB na `medium`, ok. 30 MB na `server`).
     Zapis: wpis tylko dla produktu-szkicu i przy zmianie statusu (produkt opublikowany nie dopisuje nic do tych indeksów, więc koszt dla zapisu opublikowanego produktu zero). Ryzyko niskie: predykat `p.status = 'draft'` jest już literałem w kodzie (tak działają dzisiejsze częściowe indeksy).
  2. Gałąź „najpierw szkice” (osobne zapytanie po 20 tys. szkicach, potem filtr kategorii): bez indeksów, ale koszt liniowy w liczbie szkiców (201 tys. na `server`, ok. 0,5 s); gorsze od 1.
- *Wynik sprawdzenia (faza B):* cztery częściowe indeksy po kategorii (szkice: 1,9 + 1,2 MB, bez zdjęć: 1,9 + 1,2 MB) zbudowane na `shop_proto` w poniżej sekundy. **Dział + szkice `-updatedAt` 1095 → 110 ms (350 379 → 21 936 buforów), `name` 160 → 109 ms, licznik 108 → 13 ms; mała kategoria + szkice 9,4 → 6,4 ms.** Na `server` ok. 1,1 s dla najgorszego przypadku (liniowo w gałęziach działu, 22 tys. buforów na `medium`).

**R2c. Dział + bez zdjęć, licznik (1,69 s; 2,17 s przed deduplikacją).**
- *Przyczyna (hipoteza; plan z pomiaru K1 nie jest w dowodach):* licznik przecina indeks kategorii (`primary_category_id`, dział ma ok. 214 tys. wpisów w planie z poprzedniego przebiegu) z częściowym indeksem bez zdjęć bitmapą i czyta strony sterty działu; koszt liniowy w wierszach działu. Potwierdza ją efekt rozwiązania (181 → 11 ms, 43 877 → 6 167 buforów, `r-compare.md`).
- *Rozwiązanie:* **częściowe btree po kategorii z tym samym predykatem co istniejący indeks** `(primary_category_id, name, id) WHERE images = '[]'::jsonb` (kod ma stałą `WITHOUT_IMAGES`); licznik i strona czytają tylko produkty bez zdjęć kategorii. Rozmiar i zapis jak szkice (ok. 2% produktów, wpis tylko dla produktu bez zdjęć).
  Ryzyko: predykat musi być tekstowo identyczny z zapytaniem (dziś jest). Dla kombinacji szkice + bez zdjęć (przecięcie dwóch częściowych) planista wybierze jeden z nich; do sprawdzenia w tej samej fazie.
- *Wynik sprawdzenia (faza B):* dział + bez zdjęć licznik **181 → 11 ms** (43 877 → 6 167 buforów), `name` 176 → 42 ms, `-updatedAt` 67 → 42 ms; dział + szkice + bez zdjęć 65–74 → 10–12 ms (planista użył częściowego indeksu bez zdjęć i filtru szkiców).

**Marka w R2.** Przypadek „dział + marka” w macierzy to fraza częsta (`%pro%`), czyli R2a (koszt gałęzi dodatkowych), nie przecięcie z rzadką frazą. Dla frazy rzadkiej w dziale ograniczeniem byłoby złożone indeksowanie kategorii razem z trygramami (rozszerzenie `btree_gin`, GIN na `(primary_category_id, name gin_trgm_ops)`):
rozmiar rzędu indeksu trygramów (248 MB na `medium`, ok. 2,5 GB na `server`), koszt zapisu jak przy F2 (+14 KB WAL, flush listy oczekujących) przy każdym zapisie produktu i nowe rozszerzenie (zależność); nie rekomenduję bez pomiaru przypadku „dział + fraza rzadka”.

### 10.3 Pytania do właściciela

1. **Zatwierdzenie wariantów K (K1), F (F1 + F2), Z (Z0, z Z1 w odwodzie) i M (M1)** z kosztami z sekcji 8–9: nowe indeksy ok. 1,5 GB (K1) + 0,64 GB (F2) na `server` (SZACUNEK; zadania 2–3 zmierzą rozmiar po generatorze), +15 KB WAL na zapis produktu (K1) i +14 KB (F2), brak zmiany zapisu zamówień przy Z0.
   Czy P8 jest akceptowalne? (Zapisy `catalog_product` robią tylko edycje produktu i generator; wpływ na W20 zmierzy zadanie 3.)
2. **Rozwiązania dla R1 i R2 zamiast wyjątków W7 (sekcja 10.2), zmierzone na `shop_proto` w fazie B:** czy zatwierdzić do zadań 2–3: dłuższy spacer index-only z budżetem rosnącym (R1: 0,9–1,6 s na `server`), cztery częściowe indeksy po kategorii (szkice i bez zdjęć, każdy dla `name` i `updated_at`; razem 6,2 MB na `medium`, ok. 60 MB na `server`; zapis tylko dla szkiców i produktów bez zdjęć)
   oraz kolumnę `primary_category_id` w `catalog_product_category` (R2a: 17 ms zamiast 115 ms; „K2 lite”, ryzyko spójności kopii)? Dział + fraza średnia (R2d: 4,0–9,0 s) wymaga jeszcze decyzji: kandydaci GIN zawężeni do kategorii (niezmierzone) czy inne rozwiązanie. Wszystko zostaje do potwierdzenia na `server`.
3. **Z0 czy Z1:** Z0 mieści P2 w pamięci w pomiarze izolowanym (zamówienia `#!#`/`---` 1,2–1,5 s na `server`, zapas 22–40%), ale **pod obciążeniem mieszanki klasa `orders_punct` miała na `medium` p95 630 ms i maks. 825 ms (mediana trzech przebiegów W7, ok. 1% żądań mieszanki)**; ta sama metoda
   skalowania (zamówienia ×5, liniowo) daje na `server` p95 ok. 3,2 s i maks. ok. 4,1 s (SZACUNEK górny: w pomiarze pod obciążeniem jest kolejkowanie, nie sam skan). Z0 nie jest więc potwierdzone dla W7 pod obciążeniem, a wpływ na P4 niezmierzony. Z1 usuwa ten koszt (0,1 ms), ale dodaje +24 KB WAL na cykl zamówienia
   (+42% względem syntetycznego zapisu, ścieżka W6/W11). Czy zostajemy przy Z0 do pomiaru na `server`, czy wybieramy Z1 od razu?
4. **Budżet zimnego obliczenia granic mapy strony:** propozycja 2000 ms; M1 szacunkowo 1,1–1,6 s. Zatwierdzić 2000 ms?
5. **Pomiar na `server` (R5):** P1, P2, P4 i czas z P5 uznajemy za spełnione wyłącznie według pomiaru na `server`. Czy zgoda na pomiar na worf.pl (tylko GET dla W7; P4 z koszykami to zapisy) po wdrożeniu, czy lokalny profil `server`? Dla oceny ograniczenia dyskiem
   przydałby się też pomiar przepustowości odczytu dysku worf.pl pod zapytaniami panelu (dziś zmierzony tylko dla generowania zamówień: 180–209 MB/s, iowait 0,19); bez niego „z dysku” zostaje założeniem.
6. **P4 przed zmianą nie spełnione** (sekcja 6: +13–41 ms na listingach i koszykach z panelem oraz niepowodzenia 409/400 w obu wariantach); po zmianach wynik zmierzy zadanie 7 tym samym scenariuszem. Czy kryterium „różnica median ≤ rozrzut bez panelu” zostaje w tej postaci, skoro rozrzut bez panelu wynosi 0,3–5 ms?
   Czy zmieniamy krok zmiany ilości koszyka (409, patrz sekcja 6), co wymaga powtórzenia pomiaru „przed”?

### 10.4 Zastrzeżenia do rekomendacji

- Wszystkie liczby `server` są SZACUNKIEM z `medium`; wybór wariantu opiera się na ekstrapolacji „w pamięci” (zapas dla K1 na dziale 6–15%, dla Z0 22–40%, dla M1 20–45%), więc **ostatecznie rozstrzyga pomiar na `server`** (zastrzeżenie 1). Ekstrapolacja „z dysku” jest założeniem (ograniczenie IO zmierzono na worf.pl tylko dla
  generowania zamówień) i nie służy jako dowód spełnienia ani niespełnienia W7: według niej niemal wszystkie plany liniowe zostałyby przekroczone, także te po zmianie.
- Pomiar P7 (równoważność) wykonany na prototypach SQL; test równoważności i testy planów w CI to zadania 2–5.
- K1 i F2 dodają losowe wpisy do indeksów przy każdym zapisie produktu; wpływ na W11 (zapisy panelu równolegle z zamówieniami), W18 (przeniesienie działu: reindeks poddrzewa) i W20 (generator) zmierzą zadania 3 i 7.

## 11. Polecenia do powtórzenia

```sh
# stos i dane (rewizja = czoło gałęzi epiku); API w prod: lokalne nadpisanie compose poza repo (APP_ENV=prod, APP_DEBUG=0, CLIENT_IP_HEADER=CF-Connecting-IP)
AO_COMPOSE_PROJECT=perf7 AO_PORT_BASE=28100 .ao/setup
make seed-scale PROFILE=small    # potem PROFILE=medium; z API w prod: RUN="docker compose exec -e APP_ENV=dev -e APP_DEBUG=0"
# macierz
D=docs/reports/admin-queries-baseline-2026-11-27
python3 -I $D/scripts/matrix.py gen --db shop --out $D/results/medium
python3 -I $D/scripts/matrix.py run --db shop --cases $D/results/medium/cases.json --out $D/results/medium --repeats 10
python3 -I $D/scripts/extrapolate.py --medium $D/results/medium --small $D/results/small
# H2
python3 -I $D/scripts/h2.py --api http://localhost:28102 --token-file <token.json> --path '/api/v1/admin/products?category=<id>&sort=name&perPage=25'
# W7 i P4 (jedno polecenie, load/README.md)
make load-panel                                                    # W7: 3 przebiegi
python3 -I load/baseline.py urls http://localhost:28102 http://localhost:28100 <plik> --listings 17000 --seed 11
make load-panel PANEL_MODE=p4 SHOP_URLS_FILE=<plik> SHOP_LISTING_RATE=15 SHOP_CART_RATE=5 PANEL_SECONDS=180
# warianty na kopii bazy (części 2b)
python3 -I $D/scripts/variants.py gen --db shop_proto --base $D/results/medium/cases.json --variant K1 --out $D/results/medium/variants/K1   # K0 K1 F1 F2 Z1
python3 -I $D/scripts/matrix.py run --db shop_proto --cases $D/results/medium/variants/K1/cases.json --out $D/results/medium/variants/K1 --repeats 10
python3 -I $D/scripts/compare.py --variant $D/results/medium/variants/K1 --base $D/results/medium     # F1, F2: scripts/f_table.py
python3 -I $D/scripts/equiv.py --db shop_proto --base $D/results/medium/cases.json --variant $D/results/medium/variants/K1/cases.json
docker exec -i perf7-postgres-1 psql -U postgres -d shop -At < $D/scripts/sitemap-m1.sql                                   # M1; M2: sitemap-m2.sql na shop_proto
# koszt zapisu na kopii bazy
docker exec perf7-postgres-1 psql -U postgres -c "CREATE DATABASE shop_proto TEMPLATE shop"
sh $D/scripts/write-cost-run.sh $D/results/medium/write-cost
```

Testy narzędzi: `make load-panel-check` (ścieżki błędu: zły tryb, brak stosu, brak Keycloaka, brak tokenu, API niedostępne, brak i zła mieszanka w k6, raport; 12 przypadków OK) oraz na żywym stosie
(`results/medium/error-paths.txt`): złe hasło, brak pliku mieszanki, zły tryb, P4 bez adresów sklepu, zatrzymane API; każdy kończy się kodem 2 z komunikatem.

### Co zawiera katalog dowodów i co odtwarzają skrypty

Katalog `docs/reports/admin-queries-baseline-2026-11-27/` został odchudzony (ok. 2,7 MB), bez utraty liczb raportu:
- `results/{small,medium}/cases.json`: przypadki z zapytaniami (pole `sql`) i zmienną kosztu; `results.json`: czasy i bufory przebiegów (pierwszy i ostatni przebieg w całości, środkowe tylko czas; z tego liczą wszystkie tabele: `extrapolate.py`, `matrix.py table`, `compare.py`, `f_table.py`).
  Wyjście `extrapolate.py` i `matrix.py table` jest po odchudzeniu identyczne bajt w bajt.
- `results/medium/variants/{K0,K1,F1,F2,Z1}/` (K1 z deduplikacją, `exact-count.txt`), `I3/` (frazy bez trygramu z trafieniami) i `R/` (sprawdzenia R1/R2: `before`, `after`, `r-compare.md`, `r-checks-sql.txt`): `cases.json`, `results.json`, `compare.md` / `f-table.md`, plany wybranych przypadków; `K1-first-form`, `K1-second-form` tylko `compare.md`. `equiv.txt` (wynik `equiv.py` na `medium`) w katalogu każdego rekomendowanego wariantu.
- Plany `EXPLAIN (ANALYZE, BUFFERS)` zostały tylko dla przypadków cytowanych w raporcie i tabelach (`results/medium/explain/`, `variants/*/explain/`). Pozostałe odtwarza `matrix.py run --db <baza> --cases <cases.json> --out <katalog> [--only <fragment id>]`
  (plan tekstowy ostatniego przebiegu w `explain/<id>.out`) na bazie z danymi profilu; pojedyncze zapytania można też uruchomić z pola `sql`. Pliki `sql/` z osobnymi zapytaniami zostały usunięte we wszystkich katalogach, także `variants/K1` (duplikaty `cases.json`).
- Dane `small` odtwarza `make seed-scale PROFILE=small`, `medium` `PROFILE=medium` (deterministyczne UUID; losowanie przypadków `matrix.py gen` nie ma ziarna, więc nowe losowanie da inne konkretne kategorie i frazy tych samych klas; `cases.json` zachowuje wylosowane).
- Mieszanka W7/P4: `results/medium/mix.json` (zachowana, `make load-panel PANEL_MIX=<plik>`); adresy sklepu w P4 odtwarza `python3 -I load/baseline.py urls http://localhost:28102 http://localhost:28100 <plik> --listings 17000 --products 50 --seed 11`
  (ostateczny P4; wcześniejszy plik 4000 adresów, użyty tylko do kalibracji, usunięty). Surowe przebiegi k6: `panel.json`, liczniki PostgreSQL i `report.txt` w `results/*/w7/` i `results/medium/p4/`; próbki co 5 s tylko dla ostatecznego P4. `token.json` nie jest w dowodach.

## 12. Odstępstwa od planu

- Faza B poprawek (17:43–18:05 UTC, host wolny): pomiary tylko na `shop_proto`. Syntetyczny produkt z dwiema kategoriami dodatkowymi, znaczniki `#!#`/`a.b`/`qx` w nazwach, cztery częściowe indeksy i tabela `pc_probe` zostały zdjęte po sprawdzeniu (liczba produktów 1 000 191, brak indeksów `proto_r2%`).
  K1 przeszedł dwie poprawki zapytania w fazie B (półzłączenie `IN` dawało te same wiersze 2× wolniej; ostatecznie `SELECT DISTINCT`), a jego tabela i `equiv.txt` zostały zmierzone od nowa.
- M2 zmierzony dla porównania, choć M1 wystarczał (reguła: bardziej złożonych nie prototypujemy); wynik nie wpływa na wybór.
- Postać K1: pierwsza (dodatkowa gałąź jako `IN`) i druga (złączenie bez odfiltrowania głównych) czytały za dużo; trzecia (finalna) w `variants/K1`, poprzednie w `K1-first-form`, `K1-second-form` (tylko `compare.md`).
- Baza prototypów `shop_proto` miała zapisy z pomiaru kosztu zapisu (3000 aktualizacji produktów, 500 nowych zamówień, 1000 aktualizacji klientów w każdym stanie), więc kursory stron 2 dla `-updatedAt` w obecnym SQL (rysowane na `shop`) różniły się od prototypu; `equiv.py` podstawia kursor prototypu do obecnego SQL.
- TDD nie dotyczy (zadanie pomiarowe, bez zmian kodu aplikacji); narzędzia w `load/` testowane na ścieżkach błędu (`make load-panel-check`, sekcja 11).
- Brak w mojej sesji narzędzia komunikacji z sesją prowadzącą: komunikaty „start/koniec okna” nie zostały wysłane przeze mnie; okna zostały uzgodnione z mojej strony wyłącznie przez raporty pośrednie.
- Przypadki macierzy dla `small` i `medium` są losowane z danych każdego profilu (te same klasy); „duży liść z typem na końcu alfabetu” nie istnieje w danych demo (20% członkostw dodatkowych), więc H1 rozstrzygnięta na liściach o największym miejscu 26. członka.
- API lokalnie w `APP_ENV=prod` i z `CLIENT_IP_HEADER` (lokalne nadpisanie compose poza repo), generator `medium` uruchomiony z `APP_ENV=dev`.
- P4 dopisany trzeci przebieg „z panelem” osobnym uruchomieniem (zabrakło adresów); pierwsza próba P4: w dowodach tylko `p4/attempt1/report.txt`; przebieg „bez panelu” z uruchomienia dodatkowego jest odrzucony i usunięty.
- Wynik na `server` (P1, P2, P4, czas z P5) niezmierzony (R5): decyzje o wariantach zapadają według `medium` i ekstrapolacji (SZACUNEK).
