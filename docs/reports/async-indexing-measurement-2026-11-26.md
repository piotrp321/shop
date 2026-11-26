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

### Zmierzone na serwerze (z istniejących raportów, nie z tej sesji)

- Specyfikacja ASYNC-INDEXING: ok. 391 dok./s na profilu `server` (10 mln, 2026-11-26), ok. 880 dok./s przy 1 mln.
- `docs/reports/search-shards-worf-2026-11-25.md`: przebudowa 1 000 191 produktów na worf.pl (8 vCPU, 22,9 GiB RAM,
  4 shardy) 13:53–14:13 (20 min), 849 dok./s; szczyt w trakcie: Elasticsearch 0,26 rdzenia, Postgres 0,45 rdzenia.
  Chart: Job `reindex` ma `requests` 100m CPU i brak limitu CPU (`infra/k8s/environments/prod/values.yaml`).

## Hipotezy (niezmierzone albo niepotwierdzone)

- **H1: na serwerze odczyt z Postgresa czeka na dysk.** Poparcie: korelacja fizyczna ≈ 0 w każdej tabeli (pkt 4), ok. 88
  odczytów bloków na dokument lokalnie, a na serwerze niski CPU Postgresa (0,45 rdzenia) przy 849 dok./s; zbiór roboczy
  profilu `server` (ok. 10× większy niż `medium`) przekracza `shared_buffers` Postgresa 2 GiB i prawdopodobnie cache
  systemu. Brak: pomiaru I/O (iowait, `pg_stat_io`, `blks_read` w czasie przebiegu) na serwerze. Lokalnie nie odtworzyłem
  zimnej pamięci: restart Postgresa i zrzut cache maszyny wirtualnej Dockera nie spowolniły odczytu, bo cache gospodarza
  pozostaje, więc ten eksperyment jest nierozstrzygający.
- **H2: opóźnienie sieciowe między podami (API → Postgres, API → ES) przy pętli szeregowej.** Paczka robi kilka kolejnych
  zapytań i jeden bulk; przy większym RTT niż lokalnie czas rośnie bez wzrostu CPU. Brak pomiaru RTT w klastrze.
- **H3: słabszy procesor serwera** (współdzielony VPS, ES i Postgres obok sklepu) wydłuża część PHP (budowa dokumentów,
  dekodowanie JSON zapytania `page`). Lokalnie PHP to ok. 2% paczki, więc samo to nie tłumaczy 3,4×.
- **H4: zapis do ES staje się wąskim gardłem przy 10 mln** (większe segmenty, merge, heap 2 GiB). Lokalnie zapis to 13%
  paczki i nie rośnie z indeksem do 1 mln; dla 10 mln brak danych.
- Hipoteza z wcześniejszych prac, że przyczyną jest ES (4 shardy, heap) albo pamięć Postgresa, **nie ma poparcia w
  obserwacji procesora serwera** (ES 0,26 rdzenia), ale I/O ES nie mierzono.

## Wnioski i ograniczenia

- Lokalnie kod indeksowania jest ograniczony odczytem z Postgresa i ofertami (ok. 85% czasu paczki), nie zapisem do ES
  i nie PHP. Kilka procesów zapisu do osobnych indeksów daje kolejne dokumenty/s do co najmniej 4 procesów, aż do
  nasycenia Postgresa (203% CPU przy 4 procesach, spadek tempa na proces do 2430–2670).
- Różnica 3,4× między lokalnym (2922) a serwerem `medium` (849) dok./s nie jest wyjaśniona. Nie wiadomo, czy tę część
  czasu, której lokalnie nie ma, da się skrócić równoległością: jeśli to opóźnienie lub czekanie na dysk (H1, H2), więcej
  procesów zwykle pomaga do nasycenia dysku; jeśli to nasycony dysk serwera, nie.
- **Wnioski o skali 10 mln wymagają pomiaru na serwerze.** Lokalnego profilu `server` nie zbudowano (`docs/reports/
  demo-load-medium-2026-11-19.md`: pełny wymaga ok. 313 GB). Dane `medium` mieszczą się w pamięci podręcznej maszyny.
- Szum: równoległe stosy innych worktree na tej samej maszynie, jeden powtórzony przebieg tylko dla pkt 1 i 3, N=4 z
  nierównym startem. Wartości T poniżej nie wynikają z progów policzonych na małej próbie.

## Propozycja kryterium (R, B, T, C) do akceptacji

Założenia: równoległość pomaga w tym zakresie, w którym Postgres i dysk serwera nie są nasycone, a tego nie wiem; serwer ma
8 vCPU współdzielonych ze sklepem, więc ponad 4 procesy indeksujące nie mają uzasadnienia przy dzisiejszym profilu.

- **B = 500** (jak dziś): płasko w 250–1000, większe paczki wydłużają ponowienie i pamięć, mniejsze zwiększają narzut
  (pkt 5). Opcja `--batch-size` zostaje do strojenia w zadaniu 7.
- **R = 4** repliki `worker-indexing` (górna granica z pkt 3 i z liczby vCPU serwera).
- **T = 1200 dok./s** (mediana ze środkowych 80% przebiegu), czyli ok. 3× dzisiejsze 391. Uzasadnienie: lokalnie 4 procesy
  dają ok. 2,3× tempa jednego, a serwer nie jest nasycony procesorem; ponad 3× nie obiecuję bez pomiaru I/O serwera.
- **C = 2,5 godz.** dla 10 mln (10 000 000 / 1200 = 2,3 godz. plus finalizacja `verify` i usunięcie poprzedniego indeksu).
  Dziś ok. 7,1 godz.

Wariant ostrożny: T = 800 dok./s (2×), C = 3,5 godz.; wariant ambitny: T = 1600 dok./s (4×, liniowe skalowanie), C = 1,8 godz.
Rekomenduję środkowy, a zadanie 7 mierzy wynik względem niego i podaje spełnienie wprost.

## Brama: decyzja użytkownika

1. Zatwierdzić albo zmienić (R, B, T, C) powyżej (kryterium 12 specyfikacji).
2. Lokalnie wąskie gardło się skaluje, ale przyczyna niskiego tempa na serwerze jest nieustalona. Do wyboru:
   - (a) kontynuować z (R, B, T, C) jako celem i zmierzyć tempo na serwerze dopiero w zadaniu 7 (ryzyko: jeśli dysk serwera
     jest nasycony, cel T nie zostanie osiągnięty i trzeba wtedy zająć się zapytaniami odczytu, np. wspólna kolejność
     odczytu, a nie równoległością);
   - (b) przed zadaniem 2 dokonać odczytu tylko do odczytu z Grafany (CPU, I/O i opóźnienie Postgresa, CPU Joba) dla
     przebiegu 2026-11-25 oraz wskazać ewentualny przebieg diagnostyczny na serwerze. Wymaga Twojej zgody w rozmowie z
     nazwaną operacją i hostem.
3. Po zakończeniu PERF-2 etapu B zadanie 2 dostosuje specyfikację do rzeczywistego kontraktu w kodzie (obsługa 409,
   alias `products-rebuild`); nie jest częścią tego zadania.

Raport nie twierdzi, że wymagania wydajnościowe są spełnione.
