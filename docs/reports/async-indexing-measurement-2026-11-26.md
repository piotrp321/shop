# ASYNC-INDEXING, zadanie 1: pomiar przyczyny tempa indeksowania i propozycja kryterium (2026-11-26)

Raport przypięty do rewizji `9d061e73` (main po PR #959 i #961; PERF-2 etap B nie jest zmergowany, więc klasy
`ProductSearchIndexRebuilder` i `ElasticsearchSearchIndexMaintenance` są w wersji sprzed jego zmian). Bez zmian kodu
aplikacji. Pomiary lokalne, na stosie Docker tego worktree; na worf.pl ani na serwerze nic nie uruchamiano, Grafany
serwera nie otwierano. Dane serwera pochodzą wyłącznie z istniejących raportów w repo (zob. „Zmierzone na serwerze”).

**Główny wniosek:** lokalnie ten sam kod indeksuje 1 mln produktów w tempie ok. 2900 dok./s (serwer: 849 dok./s na
`medium`, 391 dok./s na `server`), a równoległe procesy zapisu do osobnych indeksów dają ok. 1,8× (2 procesy) i ok.
2,3× (4 procesy, start nierówny) tempa jednego. Zasób lokalnie się skaluje. **Przyczyna niskiego tempa na serwerze
nie jest ustalona**: lokalnie jej nie odtworzyłem, a z danych serwera mam tylko obserwację, że Elasticsearch i Postgres nie
były wtedy nasycone procesorem. Szczegóły i decyzja dla użytkownika w „Brama”.

## Metoda

Stos: `make up` w worktree (projekt Compose `async-idx-m1`), z lokalnym, niezacommitowanym nadpisaniem compose
(ES `-Xms2g -Xmx2g`, limit 4 GiB; Postgres limit 2 GiB) i `SEARCH_SHARDS=4`, żeby zbliżyć się do serwera (ES 4 GiB/heap
2 GiB, Postgres 2 GiB, 4 shardy). Postgres ma ustawienia domyślne (`shared_buffers` 128 MB, `effective_cache_size` 4 GB).
Docker: 16 CPU, 31 GB RAM, dysk SSD Maca; **nie** jest to sprzęt serwera (OVH VPS, 8 vCPU, 22,9 GiB).

Dane: `make seed-scale PROFILE=medium` (1 000 191 produktów, 3 009 350 wariantów). Generator zatrzymał się na kroku
`ordering:read-models:rebuild` (`memory_limit` 256 MB w trybie `dev`), po kroku produktów i zamówień, a przed
`demo-data:analyze` i `catalog:reindex`; `analyze` wykonano ręcznie. Dane produktów, cen i stanów były kompletne.

Pomiary w trybie `prod` (`APP_ENV=prod APP_DEBUG=0`): w trybie `dev` pełna przebudowa kończy się wyczerpaniem pamięci PHP
(logger gromadzi wpisy), więc nie nadaje się do pomiaru.

Narzędzia: log postępu `search_rebuild` (`documents_per_second`), `pg_stat_database`, `EXPLAIN (ANALYZE, BUFFERS)`,
`docker stats`, oraz tymczasowy skrypt `scripts/measure.php` (kernel aplikacji z publicznymi usługami
`ProductSearchSource`, `ProductOfferFacts`, `ProductDocumentBuilder`, `SearchIndexMaintenance`; mierzy czas każdej składowej
paczki i zapisuje do własnego nowego indeksu). Skrypt nie jest częścią aplikacji; dołączony tylko po to, by pomiar dało
się powtórzyć. SPX nie był potrzebny: podział czasu dały pomiary składowych.

Komendy:

```bash
make seed-scale PROFILE=medium
docker compose exec -T api bin/console demo-data:analyze
docker compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 api bin/console catalog:reindex            # baza
docker compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 api php var/measure/measure.php components 500 40 <offset>
docker compose exec -T -e START_AT=<epoch> -e APP_ENV=prod -e APP_DEBUG=0 api \
  php var/measure/measure.php writer 500 400 <offset>   # N procesów naraz, rozłączne zakresy, własne indeksy
```

Środowisko w trakcie: lokalnie działały też stosy `api-input-s2` (jego RabbitMQ zajmował ok. 100% CPU) i `sp-epic`;
żadna sesja PERF nie była uruchomiona (`isRunning: false` dla PERF-0, PERF-1, PERF-2 etap B). Obciążenie hosta w
trakcie: load average 4,5–6,9 na 16 CPU. To szum, którego nie wyeliminowano; nie zmierzono jego wpływu.

## Zmierzone

### 1. Przebieg bazowy (1 proces, pełne 1 000 191 produktów, paczka 500, 4 shardy)

`catalog:reindex` w trybie prod: 12:39:19–12:45:01 UTC (ok. 342 s), kończy się `succeeded`, alias przełączony. Tempo
narastająco: 2831 dok./s po 50 tys., 2871 po 250 tys., 2924 po 500 tys., 2922 po 750 tys., 2920 po 950 tys.; końcowe
2922 dok./s (`results/baseline-progress.txt`). Tempo jest stabilne, bez spadku wraz ze wzrostem indeksu do 1 mln.

### 2. Składowe jednej paczki 500 (1 proces, `components` i `writer`, średnia z 40–400 paczek)

| Składowa | ms / paczka 500 | Udział |
|---|---|---|
| `ProductSearchSource::page` | 55–60 | ok. 33% |
| `ProductOfferFacts::of` (query.bus, `GetProductOffers`) | 81–115 | ok. 52% |
| `ProductDocumentBuilder::build` | ok. 2,5 | ok. 1,5% |
| `SearchIndexMaintenance::write` (bulk) | 22–27 | ok. 13% |

Przebieg `writer` 1 proces × 400 paczek: 200 000 dok. w 64–68 s, 2927–3101 dok./s (dwa niezależne przebiegi).
Czas odczytu (page + oferty) to ok. 85% paczki, zapis do ES ok. 13%. Lokalnie ES nie jest wąskim gardłem.

### 3. Skalowanie: N procesów naraz, rozłączne zakresy, osobne indeksy (4 shardy każdy)

Każdy proces: 400 paczek po 500 (200 000 dok.), `results/scaling-1-2-4.jsonl`. Start zsynchronizowany `START_AT`
(przy N=4 jeden proces wystartował z opóźnieniem ok. 38 s, więc nakładanie jest tylko częściowe).

| N | tempo na proces (dok./s) | łącznie (dok. / okno od pierwszego startu do ostatniego końca) | CPU w próbce w połowie przebiegu |
|---|---|---|---|
| 1 | 3101 | 3101 | Postgres 61%, ES 25%, API 24% |
| 2 | 2862, 2839 | ok. 5680 (400 000 / 70,5 s) | Postgres 110%, ES 50%, API 57% |
| 4 | 2432, 2669, 2477, 2423 | ok. 7080 (800 000 / 113 s, start nierówny) | Postgres 203%, ES 106%, API 75% |

Tempo na proces spada o ok. 8% przy N=2 i o 14–22% przy N=4 (mniej przy częściowym nakładaniu); łączna przepustowość rośnie
prawie liniowo do N=2 i do ok. 2,3× przy N=4. Wzrost czasu paczki przy N=4 jest w odczycie (`page` 58→67–70 ms, oferty
81→100 ms) i w zapisie (22→27 ms). **Powtórzenia nie wykonano**; różnice między procesami (±5%) pokazują rząd szumu.

### 4. Odczyt z Postgresa: dostęp losowy względem klucza kursora

`pg_stats.correlation` kolumn użytych do wybierania paczki względem kolejności fizycznej: `catalog_product.id` −0,008,
`catalog_product_variant.product_id` −0,009, `pricing_variant_price.variant_id` −0,004,
`inventory_stock_item.variant_id` 0,006. Kursor `id > :after ORDER BY id` czyta więc wiersze rozproszone po całej tabeli.
`EXPLAIN (ANALYZE, BUFFERS)` zapytania `page` na paczce 500 (`results/page.sql`): 11 208 buforów (10 215 trafień, 993 odczytów
spoza `shared_buffers`), z czego 493 odczyty to heap `catalog_product` (jeden na wiersz) i 497 to `catalog_product_variant`.
`pg_stat_database` w trakcie 40 paczek: ok. 1,77 mln odczytów i 19,5 mln trafień na 20 000 dokumentów, czyli ok. 88 odczytów
bloków spoza `shared_buffers` na dokument. Zapytanie `page` odpowiada za ok. 2 z nich na dokument, więc resztę (ok. 86)
**wnioskuję przez odjęcie** jako koszt `GetProductOffers` (ceny, stany, promocje); tego zapytania nie rozbiłem osobno.
Rozmiary tabel (`medium`): `catalog_product` 1,58 GB, `catalog_product_variant` 0,77 GB, `pricing_variant_price` 0,39 GB,
`inventory_stock_item` 0,38 GB (razem ok. 3,1 GB z indeksami).

### 5. Wpływ rozmiaru paczki (1 proces, `writer`, 40 000 dok. na rozmiar)

| B | 100 | 250 | 500 | 1000 | 2000 | 5000 |
|---|---|---|---|---|---|---|
| dok./s | 3943 | 4414 | 4538 | 4574 | 4121 | 4084 |

Zakres odczytywany w tej serii częściowo pokrywał się z wcześniejszymi przebiegami (cieplejsza pamięć, stąd wyższe
wartości niż w pkt 1–3), więc **liczy się tylko kształt**: płasko między 250 a 1000, spadek o ok. 10–13% przy 100 i przy
2000–5000. Czas paczki rośnie liniowo z B (np. 5000: ok. 1,2 s, pamięć paczki większa).

### 6. Zmierzone na serwerze: odczyt istniejących metryk Grafany (2026-11-26, tylko do odczytu)

Za zgodą użytkownika w rozmowie odczytano istniejące metryki Prometheus i logi Loki w Grafana Cloud (host
`cosmiccinnamon1835.grafana.net`, zapytania do źródeł `grafanacloud-prom` i `grafanacloud-logs`). Nic nie uruchamiano,
nie instalowano i nie zmieniano na serwerze. Okna czasowe z logów `search_rebuild` (pierwsza i ostatnia linia postępu):

| Przebieg | Okno (UTC) | Czas | Tempo (dok./s) |
|---|---|---|---|
| 1 mln, 20.11 | 13:53:20 + 998 s | 16,6 min | 1000 (początek 1020) |
| 1 mln, 25.11 (4 shardy) | 13:53:37 + 1177 s | 19,6 min | 849 |
| **10 mln, 26.11** | 05:35:54 + 26 178 s | **7,27 godz.** | **382–393, spada liniowo z 393 do 382** |

Tempo 10 mln jest płaskie: 393 po pierwszych 500 tys., 382 na końcu (−3%). Nie ma więc załamania przy dużym indeksie
(segmenty, merge, GC w ES nie wydłużają przebiegu w sposób widoczny w tempie). Spadek względem 1 mln (849) jest skokowy,
a nie narastający w czasie.

Średnie z okna przebiegu (rate 3 min, krok 60 s), pody `postgres-0`, `elasticsearch-0` i Job `demo-data-run-*`
(wykonuje przebudowę):

| Wielkość | 1 mln, 20.11 | 1 mln, 25.11 | 10 mln, 26.11 |
|---|---|---|---|
| Postgres: odczyty z dysku (IOPS, container_fs_reads_total) | 3480 | 4444 | **4436** (szczyt 5894) |
| Postgres: odczyt MB/s | 40,1 | 42,8 | 36,9 (szczyt 79,3) |
| Odczyty z dysku na dokument (IOPS / dok./s) | 3,5 | 5,2 | **11,4** |
| Średni rozmiar odczytu | ok. 11,5 KB | ok. 9,6 KB | ok. 8,3 KB |
| Postgres CPU (rdzenie; szczyt 10 mln) | 0,47 | 0,41 | 0,31 (0,90) |
| Elasticsearch CPU (rdzenie; szczyt 10 mln) | 0,24 | 0,25 | 0,14 (1,30) |
| Job (PHP) CPU (rdzenie) | 0,21 | brak w top | 0,105 (szczyt 0,12) |
| Węzeł: CPU zajęty / iowait (z 8 vCPU) | 0,24 / 0,031 | 0,26 / 0,049 | 0,24 / 0,064 (iowait szczyt 0,079) |

Pozostałe pomiary z okna 10 mln:
- Postgres: zapis średnio 0,8 MB/s i 55 IOPS; pamięć robocza kontenera 0,36 GiB przy limicie 2 GiB.
- Elasticsearch: odczyt 1,2 MB/s (3 IOPS), zapis 2,3 MB/s (27 IOPS), pamięć robocza 3,83 GiB przy limicie 4 GiB.
- Throttling CPU: brak metryk dla tych podów (pody bez limitu CPU nie raportują okresów throttlingu; throttling dotyczy tylko
  `monitoring-*`). Postgres, ES i Job mają limit tylko pamięci (2, 4 i 4 GiB).
- Pamięć węzła: dostępne min. 14,1 GiB z 22,9, cache systemu ok. 9,4 GiB. Brak swapu.
- Logi Loki z okna 10 mln: 0 linii dopasowanych do `gc|overhead`, `reject|circuit|429` w `elasticsearch-0` oraz do
  `duration:`, `error|fatal|checkpoint` w `postgres-0`. Nie sprawdzono, czy logi tych podów są w ogóle zbierane w pełni,
  więc to nie dowodzi braku zdarzeń.

**Czego w Grafanie brakuje (nie zgaduję):** metryk urządzenia (`node_disk_*`: IOPS i opóźnienie urządzenia, kolejka,
zajętość), PSI (`node_pressure_*`), eksportera Postgresa (`pg_stat_database` blks_read/blks_hit, cache hit ratio,
`pg_stat_statements`, czasy zapytań), metryk ES (indexing latency, merge, GC JVM, thread_pool rejected). Niemożliwe jest
więc ustalenie opóźnienia pojedynczego odczytu, głębokości kolejki ani limitu IOPS dysku VPS; podaję tylko to, co daje
`container_fs_*` i `node_cpu_seconds_total{mode="iowait"}`.

## Hipotezy: weryfikacja po odczycie z serwera

- **H1: odczyt z Postgresa czeka na dysk. Mocno poparta (nadal nie dowiedziona co do limitu dysku).** W każdym z trzech
  przebiegów Postgres czyta z dysku ok. 3,5–4,4 tys. IOPS, a ES, Job i PHP są prawie bezczynne (Job 0,1 rdzenia). Tempo
  dok./s jest odwrotnie proporcjonalne do liczby odczytów na dokument: 1 mln → 5,2 odczytu/dok. i 849 dok./s, 10 mln →
  11,4 odczytu/dok. i 390 dok./s, przy niemal tym samym IOPS (4,4 tys.). Iowait węzła rośnie z 0,031 do 0,064 rdzenia.
  Nie wiadomo, czy 4,4 tys. IOPS to limit dysku (szczyt 5,9 tys. jest o 33% wyższy, więc to nie sztywny sufit), ani
  jakie jest opóźnienie odczytu.
- **H5 (nowa): pamięć podręczna Postgresa jest za mała dla 10 mln.** Pod ma limit 2 GiB (cgroup), pamięć robocza 0,36 GiB,
  a węzeł ma 14 GiB wolne i 9,4 GiB cache; odczyty z dysku rosną z 5,2 do 11,4 na dokument wraz z rozmiarem danych.
  Zgodne z tym, że zbiór roboczy zapytania `page` i ofert nie mieści się w `shared_buffers` ani w cache objętym limitem
  kontenera. Niezmierzone (brak `blks_hit/blks_read` i `shared_buffers` w Grafanie). To jest sprawa PERF-1/PERF-2, nie
  tego zadania; zmiana limitu pamięci serwera nie jest częścią pomiaru.
- **H2: opóźnienie sieciowe między podami: odrzucona jako główna przyczyna** (nie wykluczona). Gdyby czekanie było w sieci,
  Postgres nie czytałby ciągle 4,4 tys. IOPS na dokument rosnący z rozmiarem danych. Sieci nie mierzono.
- **H3: słabszy procesor serwera: odrzucona.** Job zużywa 0,1 rdzenia, więc PHP prawie nie pracuje; czas nie jest po
  stronie CPU żadnego z podów.
- **H4: zapis do ES wąskim gardłem przy 10 mln: odrzucona.** ES zużywa 0,14 rdzenia średnio, zapis 27 IOPS, tempo płaskie
  przez 7 godzin. Szczyt ES 1,30 rdzenia był krótki (merge); w logach nie ma odrzuceń ani GC, ale metryk ES brak.
- Hipoteza z wcześniejszych prac, że przyczyną jest ES (4 shardy, heap) albo CPU, **nie ma poparcia**.

## Wnioski i ograniczenia

- Zmierzone na serwerze: wąskim gardłem przebudowy jest odczyt z Postgresa (losowy dostęp, patrz pkt 4), nie zapis do ES
  i nie PHP. Lokalnie to samo: odczyt i oferty to ok. 85% czasu paczki.
- Różnica 3,4× między lokalnym (2922) a serwerem `medium` (849) dok./s pasuje do H1: lokalnie dane `medium` mieszczą się w
  cache maszyny, na serwerze nie. Nie jest to zmierzone wprost.
- **Czy równoległość pomoże, nie jest rozstrzygnięte.** Pętla jest szeregowa (jedno zapytanie naraz). Jeśli dysk VPS ma zapas
  IOPS (szczyty 5,9 tys. sugerują, że nie jest sztywno nasycony), więcej procesów podniesie IOPS i tempo, a lokalnie
  skalowanie 2,3× przy 4 procesach jest zmierzone. Jeśli 4,4–5,9 tys. IOPS to limit urządzenia, równoległość da mało, a
  poprawić trzeba liczbę odczytów na dokument (kolejność odczytu zgodna z fizycznym układem, rozmiar cache Postgresa).
- Tempo 10 mln (390) jest ok. 2,2× niższe niż 1 mln (849) przy tym samym IOPS. Skoro odczyty/dok. rosną z rozmiarem danych,
  cel T nie może być wyprowadzony z 1 mln.
- Ograniczenia: wyniki lokalne z innego sprzętu; pojedyncze przebiegi (po jednym 10 mln); metryki CPU pochodzą z kontenerów
  (cadvisor), z rozdzielczością 60 s; brak metryk urządzenia i eksporterów (wyżej); kod w momencie przebiegów serwera
  mógł różnić się od rewizji `9d061e73` (nie sprawdzano, który obraz działał 26.11).

## Propozycja kryterium (R, B, T, C) do akceptacji

Po odczycie z serwera zmieniam T i C na ostrożniejsze, bo zmierzono, że nic poza odczytem z dysku Postgresa nie ogranicza
tempa, a nie zmierzono limitu dysku:

- **B = 500** (płasko w 250–1000, pkt 5; nic z serwera tego nie zmienia).
- **R = 4** repliki `worker-indexing` jako górna granica (8 vCPU serwera; Job użył 0,1 rdzenia, więc CPU nie ogranicza).
- **T = 800 dok./s** (mediana ze środkowych 80% przebiegu 10 mln), czyli ok. 2× dzisiejsze 391. Ambitny cel 1200 (3×)
  zależy od tego, czy IOPS dysku serwera rośnie z liczbą procesów; to wymaga potwierdzenia w zadaniu 7 na 2 i 4 replikach.
- **C = 3,5 godz.** dla 10 mln (10 000 000 / 800 = 3,5 godz. plus finalizacja `verify` i usunięcie poprzedniego indeksu).
  Dziś 7,27 godz. (zmierzone 26.11).

Wariant ambitny: T = 1200, C = 2,5 godz. Wariant zachowawczy (równoległość bez efektu, np. 4,4 tys. IOPS to sufit): T
bez zmian (ok. 400), a kryterium trzeba osiągać przez mniejszą liczbę odczytów na dokument, nie przez R.

## Brama: decyzja użytkownika

1. Zatwierdzić albo zmienić (R, B, T, C) powyżej (kryterium 12 specyfikacji). Rekomendacja: R=4, B=500, T=800, C=3,5 godz.
2. Z czym wejść w zadanie 2: odczyt z Grafany potwierdził H1 (odczyt z dysku Postgresa), ale nie odpowiada na pytanie, czy
   dysk serwera ma zapas IOPS. Rozstrzygnie to dopiero pomiar na serwerze z 2 i 4 procesami (zadanie 7) albo diagnostyczny
   przebieg na serwerze, który wymaga Twojej zgody (cel `infra/k8s/Makefile` i nazwana operacja). Rozważ też, czy H5
   (pamięć Postgresa, `shared_buffers`) rozstrzygnie PERF-1/PERF-2 przed implementacją.
3. Po zakończeniu PERF-2 etapu B zadanie 2 dostosuje specyfikację do rzeczywistego kontraktu w kodzie (obsługa 409,
   alias `products-rebuild`); nie jest częścią tego zadania.

Raport nie twierdzi, że wymagania wydajnościowe są spełnione.

## Decyzja użytkownika (2026-11-26)

Zatwierdzono R = 4, B = 500, T = 800 dok./s, C = 3 godz. 45 min; cel ambitny T = 1200. Wartości są w kryterium 12
specyfikacji. Hipoteza H5 (pamięć Postgresa) idzie do sesji PERF, poza zakresem ASYNC-INDEXING. Po implementacji: pomiar
1, 2 i 4 workerów na tym samym zbiorze 10 mln i tej samej konfiguracji infrastruktury.
