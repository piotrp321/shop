# PERF-7-1. Zapytania panelu i mapy strony: pomiar przed zmianą i wybór wariantów (SZKIC)

Zadanie PERF-7-1 epiku PERF-7 (`docs/features/admin-queries/`). Zadanie pomiarowe: bez zmian kodu aplikacji. Stan szkicu: bloki 1 i 2a
(macierz, H1, H2, fragmenty bez trygramu, W7, P4, koszt zapisu i rozmiar prototypowych indeksów) są zmierzone; **sekcja wariantów (K, F, Z, M),
wybór i pytania do właściciela czekają na część 2b** i są oznaczone „DO UZUPEŁNIENIA”. Dowody (zapytania, plany, mieszanka, surowe wyniki k6,
skrypty, polecenia): katalog `docs/reports/admin-queries-baseline-2026-11-27/`.

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
  jedzie wtedy na ok. 10,5 rdzenia. Zero błędów i opuszczonych żądań.
- **H1 potwierdzona częściowo** (mechanizm inny niż w hipotezie), **H2 potwierdzona** (zapytanie biegnie w Postgresie ≥ 7 s po przerwaniu żądania przez klienta).
- **Koszt zapisu nowych indeksów** (prototypy na kopii `medium`, WAL na zapis): K1 +15 KB na zapis produktu (+40%), F2 +15 KB (+22%), Z1 +24 KB na cykl zamówienia (+42%) i +9 KB na zapis klienta (+45%).
- **Warianty, wybór i pytania do właściciela: DO UZUPEŁNIENIA po części 2b.**

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
- Obciążenie spoza `perf7` zapisane w `results/medium/host-load-*.txt`: 15:06:47 `priceless_thompson` 240% (tuż przy końcu 3. przebiegu W7); 15:13 `promtool` (`great_visvesvaraya`) 94% podczas pierwszej, odrzuconej próby P4;
  15:54 po drugim P4 i 16:00 przed przebiegiem dodatkowym `async-idx-m1-rabbitmq` 84–89% (przebieg „bez panelu” tego uruchomienia jest odrzucony; przebieg „z panelem” 16:05–16:08 zakończył się bez obciążenia spoza `perf7` powyżej 5%).
  Macierz `medium` i W7 poszły bez obciążenia spoza `perf7` powyżej 5% na wejściu, z wyjątkiem zdarzenia 15:06:47.

## 3. Macierz przypadków przed zmianą: `small` i `medium`

Pełne tabele (czas, bufory, wiersze, zmienna kosztu, `small` i `medium`; 258 wierszy): `results/medium/tables/matrix-small-medium.md`, z ekstrapolacją `results/medium/tables/matrix-extrapolation.md`;
plany `EXPLAIN (ANALYZE, BUFFERS)` w `results/{small,medium}/explain/`, zapytania w `results/{small,medium}/sql/`. Wybrane przypadki (po jednym z każdej klasy; „server” = SZACUNEK):

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
- Frazy: z trygramem i mało trafień: GIN, 8–19 ms (`zupa`, `bagażnik`, polskie znaki, wielkość liter działają); fraza średnia i skorelowana z sortem (`snowboardowe`, 17 200 trafień): 2,4 s i 608 tys. buforów; bez trygramu: patrz sekcja 4.3.
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
wykonuje `SELECT … FROM catalog_product` (`state = active`) do t = 8,1 s, czyli ponad 7 s po przerwaniu (prawdopodobnie strona, a po niej zapytanie licznika). Kontrola bez przerwania: to samo zapytanie 2,95 s, HTTP 200 po 3,29 s.
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

Wniosek: klasa „bez trygramu” to **fragmenty z samej interpunkcji (`#!#`, `---`) w zamówieniach, klientach i produktach oraz dla produktów `a.b` i frazy 2-znakowe rzadkie** (frazy 2-znakowe częste przechodzą spacerem po indeksie).
Zamówienia i klienci są w niej tańsze niż produkty (czytają tabelę podsumowań albo 1 mln zamówień).

## 5. W7: panel pod obciążeniem (przed zmianą)

Mieszanka (`results/medium/mix.json`, generowana z danych przez `load/panel_mix.py`; 259 żądań w 35 klasach): listy domyślne we wszystkich sortowaniach, strony głębokie z prawdziwymi kursorami, frazy według częstości, kategorie według rozmiaru,
kombinacje z filtrami, Ceny, Stany, zamówienia, klienci, kody rabatowe, dashboard, drzewo kategorii. 5 żądań/s przez 300 s (model otwarty), 3 przebiegi; mediana i (najniższy–najwyższy):

| zbiór | żądań/przebieg | p50 ms | p95 ms | p99 ms | maks. ms | błędy 5xx | 4xx |
|---|---|---|---|---|---|---|---|
| `small` | 1601 | 22 (20,1–28,6) | 76 (54–109) | 117 (73–181) | 407 (111–473) | 0 | 0 |
| `medium` | 1600 | 43 (27–78) | **2288 (2058–3081)** | 4146 (3161–5251) | **5812 (3922–6849)** | 0 | 4 (błąd generatora: fraza 1-znakowa, poprawiony) |

`medium`, klasy powyżej maksimum 2000 ms (mediana maksimów trzech przebiegów): `products_category_small` 5812, `products_phrase_type` 5424, `products_phrase_brand` 3440, `products_combo` 3420, `products_phrase_twochar` 3287, `products_phrase_punct` 3201,
`products_category_medium` 2117. Klasy zamówień, klientów, kodów rabatowych, dashboardu, drzewa kategorii, stanów, cen i domyślnych list: p95 poniżej ok. 150 ms (`orders_punct`: p95 630 ms, maks. 825 ms). Rozrzut: p50 i p95 rosną w kolejnych przebiegach
(27→43→78 ms, 2,06→2,29→3,08 s); 3. przebieg kończył się razem z obciążeniem spoza `perf7` (sekcja 2.4), wniosek (p95 i maksimum poza W7, dominują klasy znane z macierzy) jest odporny na ten szum. Wyniki: `results/{small,medium}/w7/`.

**W7 na `medium`: niespełnione. Przyczyna: ta sama co w macierzy (kategoria mała i średnia, fraza skorelowana z sortem i bez trygramu).** Wynik na `server` nie jest zmierzony (R5).

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
| błędy sklepu (5xx, 4xx, opuszczone) | 0 | 0 | | tak |

Zasoby PostgreSQL na przebieg: bez panelu 1,0–1,2 aktywnej sesji, CPU Postgresa ok. 15%, 6 mln trafień buforów, 30–38 tys. odczytów, 0,24–0,31 GB odczytu, `temp_bytes` 0; z panelem 10,5 aktywnej sesji (maks. 14), CPU Postgresa 1036–1057%
(≈ 10 rdzeni), 113 mln trafień i 363 mln odczytów buforów (2,9 TB z cache systemu), `temp_bytes` 0; WAL bez zmiany (2,2–2,4 MB wobec 2,7–3,6 MB). Różnicę powodują 8 wątków najcięższych zapytań panelu (klasy z macierzy powyżej 2 s).
W15 (CPU węzła do 70%): lokalnie nie oceniam (host 16 rdzeni, współdzielony).

**P4 na `medium`: kryterium z specyfikacji niespełnione we wszystkich sześciu miarach (różnica ponad rozrzut, który bez panelu jest bardzo mały), wpływ bezwzględny kilkanaście milisekund.** Pierwsza próba P4 (odrzucona: odnowienie tokenu odmówione w 6. przebiegu,
za mało adresów, brak rozgrzewki) jest w `results/medium/p4/attempt1/`. Wynik na `server` niezmierzony; zależy od PERF-2B (zastrzeżenie 5).

## 7. Mapa strony: stan obecny (M0)

`DbalSitemapProductSource::boundaries` (`row_number()` po całym kluczu głównym): 225 ms mediany (254 ms pierwszy), 840 453 bufory, 2 000 382 wiersze, na `medium`; zmienna kosztu: produkty (każdy poprawny wariant czyta wszystkie klucze).
Na `server` SZACUNEK: ok. **2,25 s w pamięci** (powyżej budżetu 2000 ms z propozycji) i 8,4 mln buforów (z dysku według założeń sekcji 2.3 wielokrotnie więcej; patrz zastrzeżenie 1). Porcja (`publishedInRange`): 4 bufory, 0,0 ms (stała, ograniczona `row_limit`).
Zimne obliczenie granic w P4 (co 30 s, cache wyczyszczony) jest częścią obciążenia „z panelem”. Warianty M1 i M2: DO UZUPEŁNIENIA po części 2b.

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

Efekt wariantu (zimny, razem z flush): **K1 +15 KB na zapis produktu (+40%)**: dwa indeksy z losowym liściem na każdą nie-HOT aktualizację; **F2 +15 KB (+22%)** przez flush listy oczekujących GIN; **Z1 +24 KB na cykl zamówienia (+42%) i +9 KB na zapis klienta (+45%)**.
Zapisy „gorące” (strony już w WAL od checkpointu): różnice małe (produkt 5,6–6,1 KB). Czas na zapis jest zaszumiony obciążeniem hosta (0,2–5 ms); wiarygodny jest WAL. Licznik obrazów stron (`pg_stat_wal.wal_fpi`) nie odświeża się w pętli, więc obrazy
wynikają z różnicy zimny/gorący. Surowe wyniki: `results/medium/write-cost/`, tabela: `results/medium/tables/write-cost.md`. Pomiary W6, W11 i W20 z nowymi indeksami: zadanie 7 (po wdrożeniu).

## 9. Warianty (K, F, Z, M): DO UZUPEŁNIENIA po części 2b

Zaślepka. Zakres części 2b: K0 → K1 (K2/K3 tylko gdy K1 nie spełnia P2, z przeglądem `git grep` miejsc zapisu), F1 → F2, Z1, M1 → M2; dla każdego obszaru tabela: koszt odczytu przypadków obszaru z licznikiem pierwszej strony, zmienna kosztu, ekstrapolacja
do `server` (SZACUNEK, dwa warianty pamięci, z zastrzeżeniem 1), koszt zapisu (WAL i czas, sekcja 8 już zmierzona dla K1, F2, Z1), rozmiar, równoważność wyników prototypu z obecnym SQL (`scripts/equiv.py`; na `small` K0 i K1: 24 pary bez różnic). Wstępna obserwacja do sprawdzenia:
obecny plan dla działu (206 849 produktów) jest szybki (0,2 ms p1 `name`, 17 ms p1 `-updatedAt`, licznik 66 ms), więc K0 (odczyt i sortowanie całego poddrzewa, liniowo w jego produktach) może być dla działów i dużych liści gorszy od stanu obecnego; wynik zmierzy blok 4.

## 10. Wybór wariantów, przypadki niespełnione, pytania do właściciela: DO UZUPEŁNIENIA po części 2b

Zaślepka. Wstępnie (z bloków 1 i 2a, bez wariantów):

- Przypadki, które na `medium` już nie mieszczą się w W7 i wymagają wariantu: filtr małej i średniej kategorii (strona i licznik), fraza skorelowana z sortem (R1), frazy bez trygramu w produktach (F2), granice mapy strony (każdy wariant liniowy).
- Zamówienia i klienci (Z0): w pamięci mieszczą się w W7 w każdym przypadku macierzy; fragmenty bez trygramu `#!#` i `---` w zamówieniach kosztują 1,2–1,5 s w pamięci na `server` (SZACUNEK), czyli blisko budżetu; decyzja Z0/Z1 z liczbami po 2b.
- Pytania do właściciela (po 2b): zatwierdzenie K, F, Z i M; budżet czasu obliczenia granic mapy strony (propozycja 2000 ms; M0 ma ok. 2,25 s w pamięci na `server`); wyjątki W7 z liczbami (R1/R2/R5), jeśli jakiś przypadek nie ma wariantu spełniającego P2.

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
# koszt zapisu na kopii bazy
docker exec perf7-postgres-1 psql -U postgres -c "CREATE DATABASE shop_proto TEMPLATE shop"
sh $D/scripts/write-cost-run.sh $D/results/medium/write-cost
```

Testy narzędzi: `make load-panel-check` (ścieżki błędu: zły tryb, brak stosu, brak Keycloaka, brak tokenu, API niedostępne, brak i zła mieszanka w k6, raport; 12 przypadków OK) oraz na żywym stosie
(`results/medium/error-paths.txt`): złe hasło, brak pliku mieszanki, zły tryb, P4 bez adresów sklepu, zatrzymane API; każdy kończy się kodem 2 z komunikatem.

## 12. Odstępstwa od planu

- Brak w mojej sesji narzędzia komunikacji z sesją prowadzącą: komunikaty „start/koniec okna” nie zostały wysłane przeze mnie; okna zostały uzgodnione z mojej strony wyłącznie przez raporty pośrednie.
- Przypadki macierzy dla `small` i `medium` są losowane z danych każdego profilu (te same klasy); „duży liść z typem na końcu alfabetu” nie istnieje w danych demo (20% członkostw dodatkowych), więc H1 rozstrzygnięta na liściach o największym miejscu 26. członka.
- API lokalnie w `APP_ENV=prod` i z `CLIENT_IP_HEADER` (lokalne nadpisanie compose poza repo), generator `medium` uruchomiony z `APP_ENV=dev`.
- P4 dopisany trzeci przebieg „z panelem” osobnym uruchomieniem (zabrakło adresów); pierwsza próba P4 zachowana jako `attempt1`.
- Wynik na `server` (P1, P2, P4, czas z P5) niezmierzony (R5): decyzje o wariantach zapadają według `medium` i ekstrapolacji (SZACUNEK).
