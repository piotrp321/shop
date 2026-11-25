# PERF-1: konfiguracja uruchomieniowa i zasoby

Epik PERF-1 programu wydajności (`docs/features/performance-program/plan.md`). Ustawia pamięć, CPU, połączenia,
wątki, limity czasu i wolumeny usług tak, żeby obecny serwer (8 vCPU, 22,9 GiB RAM, 193 GB dysku) był wykorzystany
możliwie w całości, bez zwiększania jego zasobów i bez ryzyka OOM. Każde ustawienie odbiegające od domyślnego ma
uzasadnienie liczbowe albo regułę z dokumentacji technologii; reszta zostaje domyślna.

Podstawa: audyt `docs/reports/performance-audit-2026-11-25.md` (P4, P5, P14 i „Średnie i niskie”), raporty obszarów
`performance-audit-2026-11-25/findings/infra.md` i `postgres.md`, raport generowania `medium` na worf.pl
`docs/reports/demo-scale-medium-worf-2026-11-20.md`, pomiar #928 `docs/reports/search-shards-worf-2026-11-25.md`.
Twierdzenia o kodzie dotyczą `main` `ef6f6473`. Oznaczenia: **[PERF-0]** decyzja wymaga wyniku linii bazowej,
**[server]** wymaga danych z generowania profilu `server` na worf.pl, **[PERF-2A]** wymaga wyniku eksperymentu modeli
wyszukiwania.

Wymagania programu, które epik zamyka: W15 (zasoby), warunek „zapytanie ponad limit czasu jest przerywane i zwalnia
wątek”, W3, W5 i W6 przed i po; ADR z liczbami.

Zakres: `infra/k8s/charts/shop`, `infra/k8s/environments`, `infra/k8s/tests`, `infra/k8s/argocd/apps/monitoring.yaml`,
`infra/docker-compose.yml`, `infra/postgres`, `infra/scripts/init-env.sh`, `apps/api/docker/frankenphp`, `apps/api/config`,
`apps/api/src/Shared/Infrastructure`, `apps/api/tests`, `docs/adr/runtime-resources.md`, `docs/reports/`.

## Granice z innymi epikami

| Poza PERF-1 | Epik |
|---|---|
| liczba shardów, mapping, zapytania, request cache Elasticsearcha | PERF-2 |
| `max_wait_time` FrankenPHP, pule i kolejki wątków Elasticsearcha, limit współbieżności wyszukiwarki, grace, purge i obsługa 503 w Varnishu, limit czasu w sklepie, `inFlightReq` Traefika | PERF-3 |
| tryb worker FrankenPHP, trwałe połączenia z bazą | PERF-4 |
| repliki sklepu i API | PERF-5 |
| przetwarzanie wsadowe, równoległy reindeks, usuwanie starego indeksu po zamianie aliasu | PERF-6 |
| plany zapytań panelu i mapy strony | PERF-7 |
| indeksy zapisu, autovacuum per tabela, retencja tabel kolejek i historii | PERF-8 |

PERF-1 nie zmienia liczby wątków FrankenPHP (zapisuje ją jawnie, Z5) ani pul wątków Elasticsearcha.

## Stan wyjściowy

| Obszar | Dziś | Dowód |
|---|---|---|
| Węzeł | 22,9 GiB, `allocatable` = pojemność (brak `system-reserved`), bez swapu; system, k3s i jądro ok. 2,1 GiB; pody ok. 5,9 GiB; dostępne minimum 14,0 GiB z 7 dni; cache plików węzła 13,8 GiB | Grafana 2026-11-25 (audyt 2.6, sekcja 4; `server-bootstrap.sh` bez rezerw) |
| Suma limitów `prod` | sklep 16,9 GiB + monitoring 1,4 GiB = 18,3 GiB z 20 GiB dopuszczonych testem; requesty CPU 4,9 + 0,36 z 7 vCPU | `environments/prod/values.yaml`, `test_workloads.py` `test_prod_fits_the_server` |
| Limity CPU | brak; 0 throttlingu, szczyt CPU węzła 64%, iowait do 18% | Grafana (audyt 2.6) |
| Elasticsearch | sterta 2 GiB w limicie 4 GiB, request 1 vCPU; proces 2885 MiB, working set do 3589 MiB (88%) przy indeksie 1 GB; na cache indeksu zostaje ok. 1–1,5 GiB | #928, audyt P5 |
| Postgres | wyłącznie wartości domyślne PG 18 (`shared_buffers` 128 MB, `work_mem` 4 MB, `max_wal_size` 1 GB, `jit` on, bez `pg_stat_statements`, bez limitów czasu); limit 2 GiB; proces do 70 MiB, reszta limitu to cache plików; `/dev/shm` 64 MB; `max_connections` 100 | audyt P4, `postgres.md` K1–K3 |
| Postgres przy generowaniu `medium` | 2,52 rdzenia, working set 357 MiB | raport z 2026-11-20 |
| API | tryb klasyczny, `num_threads` domyślne 2 × CPU = 16 (w k3d `load` 20), `memory_limit` 256M × 16 = 4 GiB przy limicie 2 GiB; 87 MiB w spoczynku | `Caddyfile`, `docker/php/conf.d`, raport z 2026-11-20 |
| Redis | bez `maxmemory`, RDB domyślne, limit 512 MiB | `templates/redis.yaml` |
| Varnish | `malloc` 100 MB (domyślne obrazu), Transient bez limitu, limit poda 512 MiB | `templates/varnish.yaml` |
| Keycloak | sterta 512m–1g w 2 GiB, pula bazy domyślnie do 100 połączeń, 3,9 rdzenia przy generatorze kont | `templates/keycloak.yaml`, audyt |
| imgproxy | `IMGPROXY_WORKERS` domyślne 2 × CPU = 16 przy źródłach do 50 MP i limicie 1 GiB | `templates/imgproxy.yaml` |
| Wolumeny | `local-path` (rozmiar nieegzekwowany): Postgres 10Gi, Elasticsearch 10Gi; szczyt dysku `server` szacowany na 96 GB (strażnik generatora 120 GB wolnego), z reindeksem 125–175 GB z 193 | `charts/shop/values.yaml`, `test_generate_script.py`, audyt P14 |

## Zasady konfiguracji

**Z1. Pamięć: request to pamięć nieodzyskiwalna, limit to request plus cache plików.** Cgroup v2 wlicza cache plików
do limitu kontenera i odzyskuje go przy limicie, zanim dojdzie do OOM. OOM grozi tylko wtedy, gdy pamięć
nieodzyskiwalna (anonimowa, `shmem`: także `shared_buffers` Postgresa, bo węzeł nie ma swapu) przekroczy limit
kontenera albo pamięć węzła. Dlatego:

- `requests.memory` każdej usługi = zmierzona pamięć nieodzyskiwalna w szczycie (`anon` + `shmem` z `memory.stat`
  albo `container_memory_rss`) z zapasem 25%, nie mniej niż wynika z konfiguracji (sterta JVM, `shared_buffers`);
- `limits.memory` = request + przeznaczony cache plików; cache dostają tylko Postgres i Elasticsearch, pozostałe usługi
  mają limit blisko requestu (zapas na skoki);
- suma requestów pamięci + system (zmierzony, z zapasem) ≤ pamięć węzła; to jest warunek braku OOM węzła i zastępuje
  w teście warunek „suma limitów ≤ 20 GiB”;
- suma limitów może przekroczyć pamięć węzła wyłącznie o cache plików Postgresa i Elasticsearcha. To nadprzydział
  limitów, nie pamięci: przy braku pamięci jądro odbiera cache wszystkim cgroupom. Decyzja **D1**.

**Z2. CPU: requesty jako wagi pierwszeństwa, bez limitów na ścieżce żądania.** Bez limitów CPU nie ma throttlingu, a przy
rywalizacji udział w CPU wyznacza `cpu.weight` z requestu. Usługi ścieżki żądania (Elasticsearch, Postgres, API, sklep)
mają wysokie requesty; usługi wsadowe i hałaśliwe (Keycloak, workery, joby, imgproxy) niskie. Limit CPU tylko tam,
gdzie pomiar pokaże, że sama waga nie chroni ścieżki żądania (kandydat: imgproxy). Suma requestów ≤ 7 vCPU zostaje.

**Z3. Domyślne, chyba że pomiar albo dokumentacja mówią inaczej.** Ustawienie zmieniamy, gdy (a) pomiar pokazuje
problem, który ono usuwa, albo (b) domyślna wartość jest sprzeczna z limitem kontenera (np. `memory_limit` × wątki >
limit poda) albo zależy od liczby CPU widzianych przez proces. Każde zmienione ustawienie ma w ADR wartość, powód
i pomiar.

**Z4. Jedno miejsce na ustawienie.** Ustawienia Postgresa niezależne od pamięci są w jednym pliku
`infra/postgres/postgresql.conf` montowanym w compose i (dowiązaniem jak `default.vcl`) w chartcie; wartości zależne od
pamięci przekazuje `-c` z `values.yaml` środowiska. Zmiana pliku restartuje pod (suma kontrolna w adnotacji jak
w Varnishu).

**Z5. Jawne wartości tam, gdzie domyślne zależą od liczby CPU.** `num_threads` FrankenPHP i `IMGPROXY_WORKERS` są
zapisane w konfiguracji, więc `prod`, `load` (k3d z ≥ 10 CPU) i compose działają tak samo.

**Z6. Bez nowych usług i własnych narzędzi.** Bez PgBouncera, huge pages, eksportera Varnisha, własnych skryptów
diagnostycznych. Pomiar wewnętrzny natywnymi widokami (`pg_stat_*`, `_nodes/stats`, `varnishstat`, `memory.stat`)
lokalnie i natywnymi komponentami Alloy na serwerze (**D7**).

## Ustawienia

Kolumna „Kiedy”: **teraz** – wartość wynika z kodu i dokumentacji, zadanie ją wprowadza; **[…]** – zadanie ustala
wartość po wskazanym pomiarze; podana wartość to kandydat, punkt startu pomiaru.

### Postgres

| Ustawienie | Dziś | Proponowane | Uzasadnienie | Kiedy |
|---|---|---|---|---|
| `shared_preload_libraries` | – | `pg_stat_statements` | jedyne natywne źródło kosztu zapytań bez logowania parametrów; warunek pomiaru W3, W5, W6 | teraz |
| `track_io_timing` | off | on | czas odczytów w `pg_stat_statements` i `pg_stat_database` odróżnia brak pamięci od kosztu CPU; narzut zależy od zegara, zadanie sprawdza go `pg_test_timing` lokalnie | teraz |
| `log_lock_waits` | off | on | blokady dłuższe niż `deadlock_timeout` (1 s) widoczne w logu; log nie zawiera wartości parametrów | teraz |
| `log_min_duration_statement` | -1 | bez zmiany | loguje parametry zapytań (dane osobowe w Loki, wbrew maskowaniu logów O7); źródłem wolnych zapytań jest `pg_stat_statements` | – |
| `wal_compression` | off | `lz4` | mniej zapisu WAL z pełnymi obrazami stron przy generatorze i reindeksie, mniej I/O na wspólnym dysku | teraz |
| `max_wal_size` | 1 GB | kandydat 8 GB | rzadsze checkpointy wymuszone rozmiarem przy generatorze (4 procesy COPY); miara: `pg_stat_checkpointer.num_requested` wobec `num_timed`; zajmuje do tyle dysku | [server] (budżet dysku, D6) |
| `checkpoint_timeout` | 5 min | kandydat 15 min | razem z `max_wal_size`; dłuższe odtwarzanie po awarii jest dopuszczalne na etapie demo | [server] |
| `shared_buffers` | 128 MB | kandydat 25% limitu pamięci Postgresa (np. 2 GiB) | gorące indeksy sklepu `server` to ok. 9,5 GB; bufory są `shmem` (nieodzyskiwalne), więc wchodzą w request; reszta gorących danych w cache plików | [PERF-0] [server] (D2) |
| `effective_cache_size` | 4 GB | `shared_buffers` + cache plików przyznany w limicie | tylko informacja dla planera, musi odpowiadać Z1 | razem z D2 |
| `work_mem` | 4 MB | bez zmiany, chyba że `pg_stat_statements.temp_blks_written` pokaże sortowania na dysku w ścieżkach W3–W6 | ścieżki sklepu sortują setki wierszy; `work_mem` × połączenia × węzły planu to ryzyko OOM | [PERF-0] |
| `maintenance_work_mem` | 64 MB | kandydat 512 MB | budowa indeksów i VACUUM na tabelach 10 mln wierszy; używane przez 1–4 procesy naraz | teraz (wchodzi w request) |
| `jit` | on | decyzja z `pg_stat_statements.jit_*_time` | wyłączamy, jeśli czas kompilacji JIT jest istotną częścią zapytań API; inaczej domyślne | [PERF-0] |
| `random_page_cost` | 4 | bez zmiany | zmienia plany; zmieniamy tylko po planie `EXPLAIN` pokazującym zły wybór (PERF-7, PERF-8) | – |
| `max_connections` | 100 | bez zmiany | budżet niżej mieści się z zapasem | – |
| `/dev/shm` | 64 MB | `emptyDir` `Memory` 1 GiB (chart), `shm_size` (compose) | zapytania równoległe używają `dynamic_shared_memory_type=posix`; za mały segment kończy zapytanie błędem; wlicza się do pamięci poda | teraz |

**Budżet połączeń** (zapisany w ADR i w komentarzu `values.yaml`): API 16 (połączenie na żądanie, najwyżej jedno na
wątek), workery 2, joby cykliczne 3, reindeks albo generator 4, kopia zapasowa 1, eksporter Alloy 1, Keycloak
`KC_DB_POOL_MAX_SIZE` (kandydat 20; zmierzony szczyt w generatorze kont i przy logowaniach lokalnie), rezerwa
superużytkownika 3. Razem ok. 50 z 100.

### Limity czasu w bazie

| Proces | `statement_timeout` | `idle_in_transaction_session_timeout` | `lock_timeout` |
|---|---|---|---|
| API (żądania HTTP) | kandydat 5 s | 60 s | – (ograniczony przez `statement_timeout`) |
| workery Messengera | kandydat 60 s | 60 s | – |
| migracje | 0 | 0 | 5 s (DDL czekający na blokadę nie zatrzymuje ruchu) |
| generator, reindeks, kopia, pozostałe joby | 0 | 0 | – |

- Wartość dla API: powyżej najwolniejszego zapytania, które W1–W7 dopuszczają (W7: 2 s maksimum), i poniżej limitu
  Cloudflare (100 s). Zapytania panelu, które dziś trwają dłużej (filtr kategorii, frazy 1–2-znakowe), skończą się
  błędem zamiast zajmować wątek wspólny ze sklepem; poprawia je PERF-7. Wartość ostateczna: większa z dwóch liczb:
  3 s (maksimum W7 z zapasem) i 2,5 × najdłuższe `max_exec_time` zapytań ścieżek sklepu W3–W6 z `pg_stat_statements`
  (zadanie 1, scenariusze PERF-0); zapytania panelu dłuższe niż W7 jej nie wyznaczają. **[PERF-0]**
- Mechanizm: limit dotyczy tylko procesów, które go potrzebują. Zadanie wybiera prostszy z dwóch natywnych sposobów:
  `PGOPTIONS` w środowisku poda (k8s rozdziela API, workery i joby na osobne pody; libpq czyta zmienną przy
  połączeniu) albo ustawienie sesji przy połączeniu w Doctrine zależne od `PHP_SAPI`. Warunek wyboru: ta sama reguła
  w compose (CI i E2E widzą limit) i brak limitu dla komend konsoli uruchamianych w kontenerze API (generator, testy,
  migracje).
- Przerwane zapytanie (`SQLSTATE 57014`) daje w API 503 z `Retry-After` i komunikatem po polsku, nie 500; idle
  w transakcji zrywa połączenie i zwalnia blokady zamówienia.

### Elasticsearch

| Ustawienie | Dziś | Proponowane | Kiedy |
|---|---|---|---|
| sterta | 2 GiB (`-Xms2g -Xmx2g`) | najmniejsza, przy której po GC zajętość sterty ≤ 75% i brak wyłączników przy obciążeniu W8 na indeksie `server`; kandydat 2–3 GiB | [PERF-2A] [server] (D3) |
| limit pamięci | 4 GiB | sterta + pamięć poza stertą (zmierzona) + cache indeksu (D2) | [PERF-0] [server] |
| request CPU | 1 | kandydat 2,5 (Z2) | [PERF-0] |
| `node.processors`, pule wątków | domyślne | bez zmiany (brak limitu CPU; pule to PERF-3) | – |
| `bootstrap.memory_lock` | – | bez zmiany (węzeł bez swapu) | – |

Elasticsearch zaleca stertę najwyżej połowę pamięci i resztę dla cache systemu plików; przy limicie kontenera ta
reszta musi być w limicie (Z1).

### API (FrankenPHP)

- `num_threads` jawnie 16 (Z5); zmiana liczby to PERF-3 albo PERF-4.
- Pamięć: limit poda ≥ zmierzony szczyt przy 16 równoległych najcięższych żądaniach (porcja mapy strony, eksport
  panelu, przesłanie zdjęcia) + zapas; gdy przekracza budżet, zadanie obniża `memory_limit` dla tras, które go nie
  potrzebują, zamiast podnosić limit. `memory_limit` 256M zostaje jako limit pojedynczego żądania. **[PERF-0]** dla
  wartości.

### Redis

- `maxmemory` kandydat 75% limitu poda, `maxmemory-policy volatile-lru`: przy zapełnieniu Redis usuwa najdawniej
  używane klucze z TTL (sesje, liczniki limiterów) zamiast zostać zabity przez OOM. Zadanie sprawdza, że każdy klucz
  sesji, limitera i blokady ma TTL (`redis-cli --scan` + `TTL` lokalnie po scenariuszu logowania).
- Rozmiar: zmierzony `MEMORY USAGE` sesji × docelowa liczba aktywnych sesji (D5). RDB zostaje, a `fork` przy zapisie
  wchodzi w zapas limitu (D5).

### Varnish

- `VARNISH_SIZE` jawnie: kandydat 256M przy limicie 512 MiB; wartość z `varnishstat` (`SMA.s0.g_bytes`,
  `MAIN.n_lru_nuked`, liczba obiektów) przy mieszance adresów PERF-0 lokalnie. **[PERF-0]**
- Transient: `SMA.Transient.g_bytes` w tym samym pomiarze; ograniczamy go tylko, gdy szczyt zagraża limitowi poda.

### Keycloak i imgproxy

- Keycloak: `KC_DB_POOL_MAX_SIZE` (kandydat 20), niski request CPU (Z2). Sterta bez zmiany, limit pamięci według
  zmierzonej pamięci procesu.
- imgproxy: `IMGPROXY_WORKERS` jawnie, kandydat 4; zmierzona pamięć przy 16 równoległych zimnych przetworzeniach
  największego źródła z puli zdjęć demo mieści się w limicie; zadanie dodaje limit CPU tylko, jeśli pomiar pokaże wpływ
  na ścieżkę żądania (Z2).

### Wolumeny i dysk

- Rozmiary PVC w `prod` odpowiadają zmierzonym rozmiarom `server` z zapasem (`local-path` ich nie egzekwuje; to opis
  dla czytelności i przyszłej klasy pamięci). **[server]**
- Budżet dysku (ADR): system i obrazy, baza, WAL do `max_wal_size`, indeks × 2 przy przebudowie aliasu, kopia
  zapasowa, Garage. Szczyt ≤ 80% dysku (próg `low` Elasticsearcha to 85%). Jeśli szczyt reindeksu `server` przekracza
  80%, decyzja **D6**. **[server]**

## Budżet pamięci węzła (kandydat)

Liczby nieodzyskiwalne poza Postgresem i Elasticsearchem pochodzą z odczytu Grafany przy `medium`; tabela pokazuje
metodę i rząd wielkości, nie wartości do wdrożenia. Wartości ustala zadanie 4 z pomiarów linii bazowej.

| Pozycja | Nieodzyskiwalne (request) | Cache plików w limicie |
|---|---|---|
| system, k3s, jądro, Argo CD, kube-system | ok. 2,8 GiB (poza podami sklepu) | – |
| monitoring | ok. 0,7 GiB | – |
| Elasticsearch: sterta 2–3 GiB + poza stertą ok. 1 GiB | 3–4 GiB | 4–6 GiB |
| Postgres: `shared_buffers` 2 GiB + procesy + `maintenance_work_mem` | ok. 3 GiB | 6–8 GiB |
| API, sklep, Keycloak, workery, Redis, RabbitMQ, Varnish, imgproxy, Garage, pozostałe (dziś razem ok. 1,6 GiB) | ok. 4–5 GiB (szczyty pod obciążeniem z zapasem) | – |
| **Razem** | **ok. 14–15 GiB z 22,9** | **ok. 8 GiB z pozostałych** |

Wniosek wstępny: przy Z1 Postgres i Elasticsearch mogą dostać łącznie ok. 10 GiB na bufory i cache plików wobec
ok. 13,5 GB gorących danych `server` szacowanych w audycie (9,5 GB indeksów sklepu w Postgresie, ok. 4 GB indeksu);
dziś dostają ok. 3,5 GiB. Czy to wystarcza, pokażą `blks_hit` / `blks_read`
i iowait przy W3 i W1 na `server`. Jeśli nie, decyzja należy do właściciela (program, zasada 7).

## Ryzyka

| Ryzyko | Mechanizm | Zabezpieczenie i pomiar |
|---|---|---|
| OOM węzła | suma pamięci nieodzyskiwalnej > pamięć | Z1: test sumy requestów; W14 (2 h) bez OOM; `kube_pod_container_status_last_terminated_reason` |
| OOM kontenera | sterta JVM, `work_mem` × połączenia, 16 × `memory_limit`, Redis bez `maxmemory`, imgproxy 16 × 50 MP | requesty z pomiaru szczytu, `maxmemory`, `IMGPROXY_WORKERS`, pomiar równoległych ciężkich żądań |
| eksmisja przez kubelet | kubelet liczy aktywny cache plików do `working_set`; przy `memory.available` < 100 Mi wybiera pod najbardziej ponad requestem, czyli Elasticsearch albo Postgres z dużym cache [HIPOTEZA] | W14 bez eksmisji; jeśli wystąpi: rezerwy i próg eksmisji k3s w `server-bootstrap.sh` (cel `bootstrap`, zgoda właściciela) |
| W15 a cache plików | W15 mierzy pamięć kontenera wobec limitu; `working_set` obejmuje aktywny cache, więc pełny cache (cel Z1) wygląda jak przekroczenie | **D4**: W15 dla pamięci liczone z pamięci nieodzyskiwalnej |
| blokady | zabity proces w transakcji zamówienia trzyma `FOR UPDATE` | `idle_in_transaction_session_timeout`; `log_lock_waits` |
| przeciążenie bazy | wolne zapytanie panelu trzyma wątek API i backend | `statement_timeout` dla API; 503 zamiast 500 |
| `too many clients` | pula Keycloaka do 100 przy `max_connections` 100 | `KC_DB_POOL_MAX_SIZE`, budżet połączeń |
| dysk | WAL do `max_wal_size`, indeks × 2 przy reindeksie | budżet dysku, alert `NodeDiskFull`, strażnik generatora |
| restart Postgresa przy wdrożeniu | `shared_preload_libraries` i `/dev/shm` wymagają restartu | dopuszczalne na etapie demo; wdrożenie za zgodą |

## Czego nie robimy (uproszczenia)

- Bez PgBouncera, replik odczytu, huge pages, partycjonowania (audyt: niepotrzebne przy jednej replice API).
- Bez limitów CPU dla Elasticsearcha, Postgresa, API i sklepu (throttling na ścieżce żądania).
- Bez `log_min_duration_statement` i bez zmian `random_page_cost` i `work_mem` bez pomiaru.
- Bez pliku `postgresql.conf` w ConfigMapie dla wartości pamięci: jeden plik dla ustawień stałych, `-c` dla zależnych
  od środowiska.
- Bez zmian k3s (`system-reserved`, progi eksmisji), dopóki W14 nie pokaże eksmisji.

## Pomiar

- Na worf.pl tylko to, co program już dopuszcza: żądania GET (W3) i Grafana (W15). Wdrożenie zmian i każda operacja na
  serwerze za osobną zgodą właściciela.
- Lokalnie na `medium` (obraz produkcyjny, zasoby `prod`): W3, W5, W6 przed i po, `memory.stat` kontenerów,
  `pg_stat_database`, `pg_statio_user_indexes`, `pg_stat_statements`, `pg_stat_checkpointer`, `varnishstat`,
  `_nodes/stats/jvm,breaker`, `redis-cli info memory`. Pomiary lokalne nie biegną równolegle z benchmarkami PERF-2 na
  tej samej maszynie; sesja epiku uzgadnia okno z właścicielem.
- Grafana: `container_memory_rss` i `container_memory_cache` (jeśli ich nie ma na liście dozwolonych metryk, zadanie 1
  je dopisuje; `shared_buffers` Postgresa cAdvisor liczy jako cache, więc dla Postgresa pamięć nieodzyskiwalna to RSS
  + `shared_buffers`) oraz PSI węzła (`node_pressure_*`), żeby odróżnić rywalizację o CPU i I/O od samego wykorzystania.
- Raport pomiaru w `docs/reports/` z wartościami przed i po; wynik obowiązujący to `server` na worf.pl.

## Decyzje właściciela

| ID | Decyzja | Rekomendacja | Wymaga |
|---|---|---|---|
| D1 | Zasada Z1: request = pamięć nieodzyskiwalna, suma limitów może przekroczyć pamięć węzła o cache plików Postgresa i Elasticsearcha; test „suma limitów ≤ 20 GiB” zastępuje „suma requestów + system ≤ pamięć węzła” | przyjąć: inaczej 14 GiB pamięci węzła zostaje niewykorzystane przez bazę i indeks | [PERF-0] (zmierzona pamięć nieodzyskiwalna usług) |
| D2 | Podział pamięci cache między Postgres i Elasticsearch | według zmierzonych gorących danych: rozmiar indeksów sklepu i odsetek trafień Postgresa, rozmiar indeksu i doc values wybranego modelu | [server] [PERF-2A] |
| D3 | Sterta Elasticsearcha | najmniejsza spełniająca kryterium z tabeli | [PERF-2A] [server] |
| D4 | W15 dla pamięci liczone z pamięci nieodzyskiwalnej (Z1) wobec requestu (≤ 85%), a nie z `working_set` wobec limitu; dla Postgresa i Elasticsearcha dodatkowo iowait ≤ 5% (już w W15) | przyjąć: inaczej W15 zabrania celu Z1 | zmiana definicji wymagania (program, zasada 10) |
| D5 | Sesje w Redisie: usuwanie najdawniej używanych przy zapełnieniu (wylogowanie) i zachowanie RDB | przyjąć `volatile-lru` z RDB; rozmiar z liczby aktywnych sesji | – |
| D6 | Dysk, jeśli szczyt reindeksu `server` przekroczy 80%: krótszy `max_wal_size`, reindeks bez równoległego generatora, albo usunięcie starego indeksu tuż po zamianie aliasu (PERF-6) | według zmierzonego szczytu | [server] |
| D7 | Metryki Postgresa i Elasticsearcha na serwerze komponentami Alloy (`prometheus.exporter.postgres` z rolą `pg_monitor`, `prometheus.exporter.elasticsearch`, lista ok. 40 serii), jeśli nie dodał ich PERF-0 | przyjąć: W14, W15 i PERF-9 potrzebują ich na serwerze, a agent nie czyta `pg_stat_*` na worf.pl inaczej | – |

## Kryteria akceptacji

1. Postgres w `prod`, `load`, `ci` i compose ma ustawienia z tabeli (wartości z D2), `pg_stat_statements` działa
   (`SELECT count(*) FROM pg_stat_statements`), `/dev/shm` ma ustawiony rozmiar; `make -C infra/k8s check` i test
   renderu chartu to sprawdzają.
2. Zapytanie API dłuższe niż jego `statement_timeout` (test integracyjny z `pg_sleep`) kończy się 503 z komunikatem
   po polsku w czasie limitu, a wątek i połączenie są wolne dla kolejnego żądania; komenda konsoli w kontenerze API nie ma
   tego limitu; transakcja bezczynna dłużej niż limit traci połączenie i blokady (test).
3. Każda usługa `prod` ma request pamięci ≥ zmierzonemu szczytowi pamięci nieodzyskiwalnej i limit zgodny z Z1; test
   `infra/k8s/tests` sprawdza sumę requestów pamięci + system ≤ pamięć węzła i sumę requestów CPU ≤ 7 vCPU.
4. `num_threads`, `IMGPROXY_WORKERS`, `VARNISH_SIZE`, `KC_DB_POOL_MAX_SIZE` i `maxmemory` Redisa są jawne w chartcie
   i compose; budżet połączeń w ADR.
5. W15 spełnione na `server` na worf.pl w definicji z D4 przy obciążeniu nominalnym; W14 (lokalnie albo na serwerze
   za zgodą) bez OOM, eksmisji i restartów.
6. W3, W5, W6 zmierzone przed i po tą samą mieszanką; odsetek trafień bufora Postgresa i iowait przy W3 przed i po.
7. ADR `docs/adr/runtime-resources.md` z budżetem pamięci, CPU, połączeń i dysku oraz uzasadnieniem każdego
   ustawienia innego niż domyślne; raport pomiaru w `docs/reports/`.
