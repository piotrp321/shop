# PERF-1: konfiguracja uruchomieniowa i zasoby

Epik PERF-1 programu wydajności (`docs/features/performance-program/plan.md`). Ustawia pamięć, CPU, połączenia,
wątki, limity czasu i wolumeny usług tak, żeby obecny serwer (8 vCPU, 22,9 GiB RAM, 193 GB dysku) był wykorzystany
możliwie w całości, bez zwiększania jego zasobów i bez ryzyka OOM. Każde ustawienie odbiegające od domyślnego ma
uzasadnienie liczbowe albo regułę z dokumentacji technologii; reszta zostaje domyślna.

Podstawa: audyt `docs/reports/performance-audit-2026-11-25.md` (P4, P5, P14 i „Średnie i niskie”), raporty obszarów
`performance-audit-2026-11-25/findings/infra.md` i `postgres.md`, raport generowania `medium` na worf.pl
`docs/reports/demo-scale-medium-worf-2026-11-20.md`, pomiar #928 `docs/reports/search-shards-worf-2026-11-25.md`,
raport etapu A PERF-2 `docs/reports/perf-2-search-model-2026-11-25.md` (wartości Elasticsearcha mierzy etap B, [PERF-2B]). Twierdzenia o kodzie dotyczą `main`
`9d061e73`. Oznaczenia: **[PERF-0]** decyzja wymaga wyniku linii bazowej, **[server]** wymaga danych z generowania
profilu `server` na worf.pl, **[PERF-2B]** wymaga pomiaru Elasticsearcha z kryterium 2 etapu B PERF-2 (model R,
1 shard).

Wymagania programu, które epik zamyka: W15 (zasoby), warunek „zapytanie ponad limit czasu jest przerywane i zwalnia
wątek”, W3, W5 i W6 przed i po; ADR z liczbami.

Zakres: `infra/k8s/charts/shop`, `infra/k8s/environments`, `infra/k8s/tests`, `infra/k8s/argocd/apps/monitoring.yaml`,
`infra/docker-compose.yml`, `infra/postgres`, `infra/scripts/init-env.sh`, `apps/api/docker/frankenphp`, `apps/api/config`,
`apps/api/src/Shared/Infrastructure`, `apps/api/tests`, `docs/adr/runtime-resources.md`, `docs/reports/`.

## Granice z innymi epikami

| Poza PERF-1 | Epik |
|---|---|
| linia bazowa W1–W20, zasoby `server` z Grafany, wynik generowania i reindeksu `server`; PERF-1 ich nie powtarza | PERF-0 |
| model, mapping, liczba shardów, zapytania, request cache i `refresh_interval` Elasticsearcha; pomiar sterty, pamięci i gorących danych modelu R przy docelowym limicie (kryterium 2 etapu B), z którego PERF-1 ustala limit i stertę | PERF-2 |
| `max_wait_time` FrankenPHP, pule i kolejki wątków Elasticsearcha, limit współbieżności wyszukiwarki, grace, purge i obsługa 503 w Varnishu, limit czasu w sklepie, `inFlightReq` Traefika | PERF-3 |
| tryb worker FrankenPHP, trwałe połączenia z bazą | PERF-4 |
| repliki sklepu i API | PERF-5 |
| przetwarzanie wsadowe, równoległy reindeks (`worker-indexing`, `docs/features/async-indexing/`), usuwanie starego indeksu po zamianie aliasu | PERF-6 |
| plany zapytań i nowe indeksy panelu i mapy strony (ich rozmiar wchodzi w gorące dane D2) | PERF-7 |
| indeksy zapisu, autovacuum per tabela, retencja tabel kolejek i historii | PERF-8 |

PERF-1 nie zmienia liczby wątków FrankenPHP (zapisuje ją jawnie, Z5) ani pul wątków Elasticsearcha. Repliki
`worker-indexing` liczy w budżecie pamięci i połączeń z wartością z chartu. PERF-7 korzysta ze `statement_timeout`
i odpowiedzi 503 z PERF-1. Chart i compose zmieniają też PERF-2 B i ASYNC-INDEXING: kolejność merge'ów wspólnych plików
(`values.yaml`, `docker-compose.yml`) sesje uzgadniają przed zadaniami 2 i 4.

## Stan wyjściowy

| Obszar | Dziś | Dowód |
|---|---|---|
| Węzeł | 22,9 GiB, `allocatable` = pojemność (brak `system-reserved`, próg eksmisji domyślny 100 Mi), bez swapu; system, k3s i jądro ok. 2,1 GiB; pody ok. 5,9 GiB; dostępne minimum 14,0 GiB z 7 dni; cache plików węzła 13,8 GiB | Grafana 2026-11-25 (audyt 2.6, sekcja 4; `server-bootstrap.sh` bez rezerw) |
| Suma limitów `prod` | sklep 16,9 GiB + monitoring 1,4 GiB = 18,3 GiB z 20 GiB dopuszczonych testem; requesty CPU 4,9 + 0,36 z 7 vCPU | `environments/prod/values.yaml`, `test_workloads.py` `test_prod_fits_the_server` |
| Limity CPU | brak; 0 throttlingu, szczyt CPU węzła 64%, iowait do 18%; przy generowaniu `server` iowait do 23%, Postgres 2,3 rdzenia, Keycloak 3,8 | Grafana (audyt 2.6); [`runtime-config-baseline-2026-11-27.md`](../../reports/runtime-config-baseline-2026-11-27.md) §1.3–1.4 |
| Elasticsearch | sterta 2 GiB w limicie 4 GiB, request 1 vCPU; working set do 3589 MiB (88%) przy indeksie 1 GB. Model R przy 10 mln: gorące dane ok. 4,5 GB [SZACUNEK etapu A], nie mieszczą się w limicie 4 GiB. Na `server` (10 mln, 4 shardy, indeks 9,6 GB): working set 3,7–4,1 GiB z 4 GiB, RSS 2,9 GiB, a każdy zimny listing czyta 1–4 GiB z dysku | #928, audyt P5, raport etapu A sekcja 8, `server-profile-baseline-worf-2026-11-26.md`, `runtime-config-baseline-2026-11-27.md` §1.2 |
| Postgres | wyłącznie wartości domyślne PG 18 (`shared_buffers` 128 MB, `work_mem` 4 MB, `max_wal_size` 1 GB, `jit` on, bez `pg_stat_statements`, bez limitów czasu); limit 2 GiB; proces do 70 MiB, reszta limitu to cache plików; `/dev/shm` 64 MB; `max_connections` 100; kopia przez `pg_dump`, bez archiwizacji WAL i slotów | audyt P4, `postgres.md` K1–K3, `backup.sh` |
| Postgres przy generowaniu `medium` | 2,52 rdzenia, working set 357 MiB; lokalnie (nadpisanie `prod`): pamięć nieodzyskiwalna 242 MiB, cache plików 1983 z 2048 MiB, szczyt `pg_wal` 1056 MiB, 81 checkpointów wymuszonych i 8 czasowych w 58 min | raport z 2026-11-20; [`runtime-config-baseline-2026-11-27.md`](../../reports/runtime-config-baseline-2026-11-27.md) §2 |
| Postgres przy generowaniu `server` | cache plików cgroupy 1,83–1,99 GiB przez 24 h, czyli przy limicie, przy `MemAvailable` węzła 13,3–14,7 GiB; ok. 2 815 checkpointów wymuszonych rozmiarem WAL w ok. 10 h generowania (w szczycie co ok. 8 s); w reindeksie i weryfikacji pojedyncze strony 8 KB (4–10 tys. IOPS) przy 0,2–0,4 rdzenia; `demo-data:verify` miał ok. 6,8 h (limit 24 h liczy cały Job) i się nie skończył; bez eksportera Postgresa i metryk dysku urządzenia (`node_disk_*`, `node_pressure_*`, #976) | [`runtime-config-baseline-2026-11-27.md`](../../reports/runtime-config-baseline-2026-11-27.md) §1, §3 |
| API | tryb klasyczny, `num_threads` domyślne 2 × CPU = 16 (w k3d `load` 20), `memory_limit` 256M × 16 = 4 GiB przy limicie 2 GiB; 87 MiB w spoczynku | `Caddyfile`, `docker/php/conf.d`, raport z 2026-11-20 |
| Redis | bez `maxmemory` (polityka domyślna `noeviction` nie działa bez limitu), RDB domyślne, limit 512 MiB; zastosowania w sekcji „Redis” | `templates/redis.yaml`, kod (sekcja „Redis”) |
| Varnish | `malloc` 100 MB (domyślne obrazu), Transient bez limitu, limit poda 512 MiB | `templates/varnish.yaml` |
| Keycloak | sterta 512m–1g w 2 GiB, pula bazy domyślnie do 100 połączeń, 3,9 rdzenia przy generatorze kont; RSS 0,94 GiB przed importem i 1,36 GiB po imporcie 1 mln kont `server`; lokalnie szczyt 6 połączeń przy imporcie 100 tys. kont | `templates/keycloak.yaml`, audyt, [`runtime-config-baseline-2026-11-27.md`](../../reports/runtime-config-baseline-2026-11-27.md) §1.2, §2.3 |
| imgproxy | `IMGPROXY_WORKERS` domyślne 2 × CPU = 16 przy źródłach do 50 MP i limicie 1 GiB | `templates/imgproxy.yaml` |
| Wolumeny | `local-path` (rozmiar nieegzekwowany): Postgres 10Gi, Elasticsearch 10Gi; zmierzony szczyt użycia `/` przy generowaniu i reindeksie `server` (4 shardy, `max_wal_size` 1 GB) 103,1 GB z 207 GB (192,9 GiB), czyli 50%; przed generowaniem 34,2 GB | `charts/shop/values.yaml`, [`runtime-config-baseline-2026-11-27.md`](../../reports/runtime-config-baseline-2026-11-27.md) §1.6 |

## Zasady konfiguracji

**Z1. Pamięć: request to pamięć nieodzyskiwalna, limit to request plus zapas, a dla Postgresa i Elasticsearcha także
cache plików.** Kierunek przyjęty przez właściciela 2026-11-26; reguła węzła to decyzja **D1**.

Fakty, na których stoi zasada (cgroup v2, dokumentacja jądra i Kubernetesa):

- `memory.current` kontenera obejmuje pamięć anonimową, `shmem` (tmpfs, `/dev/shm`, `shared_buffers` Postgresa), cache
  plików i pamięć jądra przypisaną do cgroupy (slab, bufory gniazd, tablice stron). Przy limicie jądro odzyskuje cache
  plików i odzyskiwalny slab; OOM następuje, gdy reszta nie mieści się w limicie. Bez swapu `shmem` jest nieodzyskiwalny.
- cAdvisor na cgroup v2: `container_memory_rss` = `anon` (bez `shmem`), `container_memory_cache` = `file` (z `shmem`),
  `container_memory_working_set_bytes` = `memory.current` − `inactive_file` (z aktywnym cache i pamięcią jądra). Żadna
  seria nie podaje pamięci nieodzyskiwalnej wprost: lokalnie liczymy ją z `memory.stat` (`anon` + `shmem` +
  `slab_unreclaimable` + `kernel_stack` + `pagetables` + `sock`), na serwerze jako `rss` + `shmem` znany z konfiguracji
  (`shared_buffers`, zajętość `/dev/shm`) + zmierzony lokalnie narzut jądra. Zadanie 1 porównuje obie metody na tym
  samym kontenerze.
- Kubelet liczy `memory.available` węzła z `working_set`, więc aktywny cache plików liczy się jako zajęty. Poniżej progu
  `evictionHard` eksmituje najpierw pody, których zużycie najbardziej przekracza request, zanim jądro odbierze im cache.
  Pod z dużym cache ponad requestem (Postgres, Elasticsearch) jest pierwszym kandydatem. To udokumentowane zachowanie
  Kubernetesa (node-pressure eviction).

Reguła (rekomendacja D1, wariant A):

- `requests.memory` = szczyt pamięci nieodzyskiwalnej przy jednoczesnym obciążeniu usług (mieszanka nominalna z zapisami
  i jednocześnie generator albo reindeks), z zapasem 25%, nie mniej niż wynika z konfiguracji (sterta JVM,
  `shared_buffers`, `maintenance_work_mem` × procesy);
- `limits.memory` = request + zmierzone chwilowe alokacje (najcięższe żądania naraz, fork RDB); Postgres
  i Elasticsearch dodatkowo przyznany cache plików;
- warunek węzła (test chartu): suma **limitów** wszystkich podów + system (k3s, jądro poza cgroupami podów, Argo CD,
  kube-system; zmierzony) + rezerwa węzła (próg eksmisji i zapas, kandydat 1 GiB) ≤ pamięć węzła. Wtedy nawet wszystkie
  usługi naraz na swoich limitach mieszczą się w pamięci, każda cgroupa odzyskuje własny cache przy swoim limicie
  i węzeł nie dochodzi do progu eksmisji. Suma requestów sama nie gwarantuje braku OOM, bo nie obejmuje chwilowych
  alokacji usług działających naraz.

Wariant B (nadprzydział): suma limitów przekracza pamięć węzła o cache Postgresa i Elasticsearcha, żeby jeden mógł
zająć cache niewykorzystany przez drugi. Przy pełnej pamięci węzła kubelet eksmituje wtedy Postgresa albo
Elasticsearcha. Wariant wymaga rezerw kubeleta (`system-reserved`, `kube-reserved`), żeby limit cgroupy `kubepods`
leżał poniżej progu eksmisji i jądro odzyskiwało cache wcześniej [do potwierdzenia testem], czyli zmiany k3s celem
`bootstrap` za zgodą właściciela, oraz biegu W14 na `server` bez eksmisji. Stosujemy go tylko wtedy, gdy wariant A po
pomiarze na `server` nie daje dość cache (odsetek trafień bufora, iowait przy W1 i W3).

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

**Z6. Bez nowych usług i własnych narzędzi.** Bez PgBouncera, huge pages, eksportera Varnisha i Redisa, własnych
skryptów diagnostycznych. Pomiar natywnymi widokami (`pg_stat_*`, `_nodes/stats`, `varnishstat`, `memory.stat`,
`INFO`) lokalnie i natywnymi komponentami Alloy na serwerze (**D7**).

## Ustawienia

Kolumna „Kiedy”: **teraz** – wartość wynika z kodu i dokumentacji, zadanie ją wprowadza; **[…]** – zadanie ustala
wartość po wskazanym pomiarze; podana wartość to kandydat, punkt startu pomiaru.

### Postgres

| Ustawienie | Dziś | Proponowane | Uzasadnienie | Kiedy |
|---|---|---|---|---|
| `shared_preload_libraries` | – | `pg_stat_statements` | jedyne natywne źródło kosztu zapytań bez logowania parametrów; warunek pomiaru W3, W5, W6 | teraz |
| `pg_stat_statements.track_utility` | on | off | polecenia użytkowe (np. `ALTER ROLE … PASSWORD`) mogą trafić do widoku z literałami; koszt zapytań ich nie potrzebuje | teraz |
| `track_io_timing` | off | on | czas odczytów w `pg_stat_statements` i `pg_stat_database` odróżnia brak pamięci od kosztu CPU; narzut zależy od zegara, zadanie sprawdza go `pg_test_timing` lokalnie | teraz |
| `log_lock_waits` | off | on | blokady dłuższe niż `deadlock_timeout` (1 s) widoczne w logu; sam komunikat ma tylko numery procesów i typ blokady | teraz |
| `log_error_verbosity` | default | `terse` | bez linii DETAIL, HINT, QUERY i CONTEXT, które niosą wartości kluczy, wiersze `COPY` i treść zapytań zakleszczenia (sekcja „Dane w logach i metrykach”) | teraz |
| `log_min_error_statement` | `error` | `panic` | bez tekstu zapytania przy błędzie; zapytanie identyfikuje `pg_stat_statements` | teraz |
| `log_min_duration_statement` | -1 | bez zmiany | loguje parametry zapytań; źródłem wolnych zapytań jest `pg_stat_statements` | – |
| `wal_compression` | off | `lz4` | mniej zapisu WAL z pełnymi obrazami stron przy generatorze i reindeksie, mniej I/O na wspólnym dysku | teraz |
| `max_wal_size` | 1 GB | 8 GB (start) | rzadsze checkpointy wymuszone rozmiarem przy generatorze (4 procesy COPY; `medium`: 81 wymuszonych wobec 8 czasowych, raport zadania 1); miara: `pg_stat_checkpointer.num_requested` wobec `num_timed`. Limit miękki: przy ciężkim zapisie WAL rośnie ponad niego, dopóki checkpoint nie nadąży; budżet dysku liczy zmierzony szczyt `pg_wal` (D6) | wartość startowa; potwierdza ją pomiar na `server` |
| `checkpoint_timeout` | 5 min | 15 min (start) | razem z `max_wal_size`; dłuższe odtwarzanie po awarii jest dopuszczalne na etapie demo | wartość startowa; potwierdza ją pomiar na `server` |
| `shared_buffers` | 128 MB | 25% limitu pamięci Postgresa; start `prod` i `load`: 1 GB, `ci` i compose: 128 MB | gorące indeksy sklepu `server` to ok. 9,5 GB; bufory są `shmem` (nieodzyskiwalne), więc wchodzą w request; reszta gorących danych w cache plików; wariant startowy zmierzony na `medium` (raport zadania 1, sekcja 8) | wartość startowa; ostateczna w D2 [server] [PERF-2B] |
| `effective_cache_size` | 4 GB | `shared_buffers` + cache plików przyznany w limicie | tylko informacja dla planera, musi odpowiadać Z1 | razem z D2 |
| `work_mem` | 4 MB | bez zmiany, chyba że `pg_stat_statements.temp_blks_written` pokaże sortowania na dysku w ścieżkach W3–W6 | ścieżki sklepu sortują setki wierszy; `work_mem` × połączenia × węzły planu to ryzyko OOM | [PERF-0] |
| `maintenance_work_mem` | 64 MB | 256 MB | budowa indeksów i VACUUM na tabelach 10 mln wierszy; używane przez 1–4 procesy naraz; 512 MB × 4 procesy generatora zajęłoby sam request Postgresa | teraz (wchodzi w request) |
| `autovacuum_work_mem` | -1 (`maintenance_work_mem`) | 128 MB | `autovacuum_max_workers` (3) × 128 MB zamiast × 256 MB; VACUUM tabel 10 mln wierszy mieści listę martwych krotek | teraz (wchodzi w request) |
| `jit` | on | decyzja z `pg_stat_statements.jit_*_time` | wyłączamy, jeśli czas kompilacji JIT jest istotną częścią zapytań API; inaczej domyślne | [PERF-0] |
| `random_page_cost` | 4 | bez zmiany | zmienia plany; zmieniamy tylko po planie `EXPLAIN` pokazującym zły wybór (PERF-7, PERF-8) | – |
| `max_connections` | 100 | bez zmiany | budżet niżej mieści się z zapasem | – |
| `/dev/shm` | 64 MB | `emptyDir` `Memory` 1 GiB (chart), `shm_size` (compose) | zapytania równoległe używają `dynamic_shared_memory_type=posix`; za mały segment kończy zapytanie błędem; zajętość jest `shmem` poda i wchodzi w request | teraz |

Plik `infra/postgres/postgresql.conf` zastępuje plik katalogu danych (`config_file`), więc powtarza też to, co
zapisuje w nim initdb obrazu i co różni się od wartości domyślnych Postgresa: `listen_addresses`,
`default_text_search_config`, `timezone`, `log_timezone` i `lc_*`.

**Budżet połączeń** (zapisany w ADR i w komentarzu `values.yaml`): API 16 (połączenie na żądanie, najwyżej jedno na
wątek), workery 2, `worker-indexing` × repliki z chartu, joby cykliczne 3, reindeks albo generator 4, kopia
zapasowa 1, eksporter Alloy 1, Keycloak `KC_DB_POOL_MAX_SIZE` (kandydat 20; zmierzony szczyt w generatorze kont
i przy logowaniach lokalnie), rezerwa superużytkownika 3. Razem ok. 50 z 100 przy jednej replice `worker-indexing`.

### Limity czasu w bazie

| Proces | `statement_timeout` | `idle_in_transaction_session_timeout` | `lock_timeout` |
|---|---|---|---|
| API (żądania HTTP) | 3 s | 60 s | – (ograniczony przez `statement_timeout`) |
| API, trasy z wyjątkiem (lista niżej) | 90 s | 60 s | – |
| workery Messengera | 60 s | 60 s | – |
| migracje (`doctrine:migrations:*`) | 0 | 0 | 5 s (DDL czekający na blokadę nie zatrzymuje ruchu); bez limitu dla instrukcji `CONCURRENTLY` (niżej) |
| generator, reindeks, kopia, pozostałe komendy konsoli | 0 | 0 | – |

Wartości przyjął właściciel 2026-11-27 (wariant „3 s wszędzie, wyjątki”); limit nie może przerywać poprawnych operacji
panelu, eksportów, generatora, indeksowania ani zadań asynchronicznych i nie ogranicza funkcji ani współbieżności.

- `statement_timeout` dotyczy jednej instrukcji, nie całego żądania: żądanie złożone z wielu krótkich zapytań nie
  przekracza limitu, a instrukcja czekająca na blokadę kończy się tym samym limitem.
- Wartość 3 s: powyżej maksimum W7 z zapasem i powyżej 2,5 × najdłuższe `max_exec_time` ścieżek sklepu W3–W6 (≤ 4 ms na
  `medium`, [`runtime-config-baseline-2026-11-27.md`](../../reports/runtime-config-baseline-2026-11-27.md) §5.3). Wartość
  wyjątku 90 s to limit jednej instrukcji, nie żądania: żądanie może wykonać kilka instrukcji z limitem (lista produktów
  wykonuje dwie, stronę i licznik), więc 90 s nie gwarantuje odpowiedzi przed limitem Cloudflare (100 s), a jedynie
  ogranicza czas, przez który jedna instrukcja zajmuje połączenie i wątek. Zapytanie, którego klient już nie czeka, biegnie
  w Postgresie do swojego limitu, więc limit 0 nie zwalnia połączenia.
- Mechanizm: middleware sterownika DBAL (`App\Shared\Infrastructure\Persistence\Timeout`) ustawia na sesji
  `statement_timeout`, `idle_in_transaction_session_timeout` i `lock_timeout` przed następną instrukcją połączenia
  (i przed rozpoczęciem transakcji), jeśli sesja ma inne wartości niż wynikają z bieżącego profilu procesu. Profil
  wybierają zdarzenia: żądanie główne (po routerze, z nazwą trasy; koniec w `kernel.terminate`), `WorkerStartedEvent` i
  `WorkerStoppedEvent` Messengera, polecenie `doctrine:migrations:*` (`console.command`). Bez zdarzenia profil to
  `console`: 0. Profil żądania jest wybierany przy każdym żądaniu, więc połączenie używane przez kolejne żądania (tryb
  worker FrankenPHP) nie zachowuje limitu poprzedniego, także trasy z wyjątkiem. Ta sama reguła działa w compose,
  w podach i w testach, bez zmiennych środowiska i zmian chartu. Wartości, lista tras z wyjątkiem i profile są w jednym
  miejscu: parametry `app.database_timeouts*` w `apps/api/config/services.yaml`.
- Instrukcje `CONCURRENTLY` (`CREATE`/`DROP INDEX`, `REINDEX`, `DETACH PARTITION`) nie mają `lock_timeout`: czekają na
  każdą transakcję starszą od siebie (zapytanie panelu do 90 s, worker do 60 s, generator, reindeks i `pg_dump` bez limitu),
  a w czasie czekania nikogo nie blokują, więc limit zakończyłby je tylko błędem 55P03 z nieważnym (`INVALID`) indeksem,
  a ponowienia Joba `migrate` kończyłyby się tak samo (zmierzone: transakcja otwarta z `SELECT` na tabeli i
  `CREATE INDEX CONCURRENTLY` z `lock_timeout` 5 s kończy się po 5 s błędem i zostawia indeks `indisvalid = f`).
  Middleware rozpoznaje taką instrukcję po tekście SQL (`TimeoutConnection::isConcurrentDdl`), wysyła ją bez
  `lock_timeout` i przywraca limit przy następnej instrukcji, więc reguła obejmuje każdą obecną i przyszłą migrację,
  niezależnie od tego, kto ją uruchamia. Test przegląda wszystkie migracje i wymaga, żeby każda instrukcja
  `CONCURRENTLY` z `addSql()` była rozpoznana; test integracyjny sprawdza budowę indeksu czekającą na starszą
  transakcję dłużej niż `lock_timeout` migracji.
- Przerwana instrukcja (`SQLSTATE 57014`) daje w API 503 z `Retry-After: 5` i komunikatem po polsku w formacie błędów API
  (`detail`: „Zapytanie trwało zbyt długo i zostało przerwane. Spróbuj ponownie za chwilę."); także gdy wyjątek jest
  opakowany (`HandlerFailedException`, `previous`). Transakcja bezczynna dłużej niż limit traci połączenie i blokady
  (następna instrukcja dostaje błąd połączenia).

**Inwentarz tras HTTP.** Wyjątek (90 s) dostaje trasa, której zapytanie ma koszt rosnący z rozmiarem tabeli (pełny skan,
agregat bez ograniczenia, operacja masowa bez stałego limitu partii) albo zmierzona powyżej 1/2,5 limitu (1,2 s).
Trasa ograniczona stałym limitem albo indeksem wyjątku nie dostaje. Źródło: PM = pomiar PERF-7
(`admin-queries-baseline-2026-11-27.md`, ZM na `medium`, SZ szacunek dla `server`), K = odczyt kodu.

| Trasa | Wyjątek | Uzasadnienie |
|---|---|---|
| `catalog_backoffice_products` (`GET /api/v1/admin/products`) | tak | PM: mała kategoria z `sort=name` ZM 3,07 s / SZ ok. 31 s, frazy skorelowane z sortem ZM 2,4 s / SZ ok. 24 s, frazy bez trygramu ZM 1,5 s / SZ 6,5–15 s, pod obciążeniem W7 do 5,8 s. Do pomiaru po poprawkach PERF-7 |
| `inventory_backoffice_stock` (`GET /api/v1/admin/stock`) | tak | K: bez `level` woła to samo `SearchBackofficeProducts` co lista produktów (`QueryBusProductDirectory`), więc ma ten sam koszt; tryb `level` idzie po indeksie, ale trasa jest jedna |
| `pricing_backoffice_prices` (`GET /api/v1/admin/prices`) | tak | K: jak wyżej (`SearchBackofficeProducts`) |
| `ordering_backoffice_orders` (`GET /api/v1/admin/orders`) | tak | PM: `q` bez trygramu (`#!#`, `---`) skanuje całą tabelę zamówień (ZM 0,3 s, SZ 1,2–1,5 s; pod mieszanką W7 ZM do 0,83 s), więc koszt rośnie z tabelą i SZ jest powyżej 1,2 s |
| `ordering_backoffice_customers` (`GET /api/v1/admin/customers`) | tak | PM: fragment bez trygramu skanuje `ordering_customer` (ZM 25 ms, SZ 0,22 s); mieści się w 3 s, ale koszt rośnie z tabelą (880 tys. wierszy dziś) |
| `catalog_sitemap_product_chunks` (`GET /api/v1/sitemap/product-chunks`) | tak | PM: granice porcji to okno po całej tabeli produktów (M0: ZM 0,23 s, SZ ok. 2,3 s); wynik jest w cache 1 h, ale zimne wyliczenie jest skanem |
| `backoffice_dashboard` | nie | K: liczniki z limitem (do 1000), sprzedaż z `ordering_sales_hour` (stała liczba wierszy), liczba produktów z `pg_class`; limit dotyczy każdej instrukcji osobno. Nie mierzone przez PERF-7 |
| `catalog_backoffice_category_tree`, `catalog_category_tree`, `catalog_category_redirect`, `catalog_backoffice_tax_classes` | nie | K: tabele kategorii i klas VAT (rozmiar nie rośnie z produktami); liczniki produktów kategorii z limitem (`DbalCategoryProductCounts`, PM: ZM 10 ms) |
| `catalog_product_list`, `cart_product_offers`, `catalog_sitemap_products` | nie | K: wyszukiwanie w Elasticsearchu albo klucz główny z `LIMIT` (porcja mapy do `app.sitemap.row_limit`) |
| `catalog_product_details`, `catalog_backoffice_product`, edycja, tworzenie, publikacja, wycofanie, zdjęcia produktu, kategorie (tworzenie, edycja, przeniesienie, usunięcie) | nie | K: jedna encja po kluczu albo indeksie unikalnym; przeindeksowanie poddrzewa kategorii idzie asynchronicznie partiami po 500 (`ReindexCategoryBatchHandler`, id produktów z Elasticsearcha) |
| `pricing_promotion*`, `pricing_discount_code*`, `pricing_price_history`, `pricing_change_price` | nie | K: listy stronicowane z `LIMIT` i licznikiem z limitem; licznik wariantów promocji i użyć kodu po indeksie (PM: kody ZM 3 ms, SZ 16 ms); promocja ma najwyżej 1000 wariantów (`Promotion::MAX_VARIANTS`) |
| `inventory_stock_history`, `inventory_correct_stock`, `inventory_receive_stock` | nie | K: jeden wariant, stronicowanie kursorem |
| `ordering_backoffice_order`, `ordering_backoffice_customer`, zmiana statusu, numer przesyłki, zwrot | nie | K: jedno zamówienie albo klient po kluczu; historia zamówień klienta z indeksu po kliencie |
| `ordering_customer_*`, `ordering_guest_place`, koszyki (`cart_*`), płatności (`payment_*`), webhook, symulator płatności, `delivery_*`, `customer_me`, `backoffice_me`, `health` | nie | K: operacje na jednym koszyku, zamówieniu albo płatności po kluczu; zamówienie to jedna transakcja z blokadą rekordów stanu koszyka (rozmiar koszyka) |

Eksportów i operacji masowych o rozmiarze zależnym od tabeli nie ma w API HTTP (panel nie ma tras eksportu, a promocje
mają limit wariantów); komendy o takim rozmiarze (generator, reindeks, kopia, `ordering:expire-overdue`) są poleceniami
konsoli z limitem 0. Nowa trasa, która czyta całą tabelę, dopisuje się do `app.database_timeouts.long_routes`, ta
ograniczona limitem albo indeksem nie.

**Workery.** Każdy handler Messengera pracuje na jednej encji albo na partii o stałym rozmiarze, więc oba workery mają
limit 60 s: `outbox` i `events` (przekazanie zdarzenia, `IndexProduct` jednego produktu, wiadomości e-mail, odświeżenie
cache sklepu, `SyncStockRecordsOnProductChange` i `RepriceOnTaxClassChange` jednego produktu), `payment_simulator`,
`scheduled` (`ExpireUnpaidOrder` jednego zamówienia), `promotions` (`PromotionBoundaryReached` i
`RefreshPromotionProducts` do 1000 wariantów) i `jobs` (`ReindexCategoryBatch`: partia 500 produktów, a id podzbioru
dostarcza Elasticsearch). Żaden transport nie dostaje 0. Pomiar czasu najdłuższego zapytania workera po zmianie: zadanie 6.

### Elasticsearch

| Ustawienie | Dziś | Proponowane | Kiedy |
|---|---|---|---|
| sterta | 2 GiB (`-Xms2g -Xmx2g`) | najmniejsza, przy której po GC zajętość sterty ≤ 75% i brak wyłączników przy obciążeniu W8 na indeksie `server`; kandydat 2–3 GiB | [PERF-2B] (D3) |
| limit pamięci | 4 GiB | sterta + pamięć poza stertą (zmierzona) + cache indeksu (D2) | [PERF-2B] [server] |
| request CPU | 1 | kandydat 2,5 (Z2) | [PERF-0] |
| `node.processors`, pule wątków | domyślne | bez zmiany (brak limitu CPU; pule to PERF-3) | – |
| `bootstrap.memory_lock` | – | bez zmiany (węzeł bez swapu) | – |

PERF-1 nie mierzy Elasticsearcha osobno: stertę, pamięć poza stertą, gorące dane i odczyty z dysku bierze z pomiaru
etapu B PERF-2 na worf.pl. Dopóki go nie ma, sterta i limit zostają bez zmian, a zadanie 4 nie jest
zamknięte. Elasticsearch zaleca stertę najwyżej
połowę pamięci i resztę dla cache systemu plików; przy limicie kontenera ta reszta musi być w limicie (Z1).

### API (FrankenPHP)

- `num_threads` jawnie 16 (Z5); zmiana liczby to PERF-3 albo PERF-4.
- Pamięć: limit poda ≥ zmierzony szczyt przy 16 równoległych najcięższych żądaniach (porcja mapy strony, eksport
  panelu, przesłanie zdjęcia) + zapas; gdy przekracza budżet, zadanie obniża `memory_limit` dla tras, które go nie
  potrzebują, zamiast podnosić limit. `memory_limit` 256M zostaje jako limit pojedynczego żądania. **[PERF-0]** dla
  wartości.

### Redis

Zastosowania (kod `main`; cache OIDC i granic mapy strony są w puli plikowej API, nie w Redisie):

| Klucze | Kto | TTL | Skutek usunięcia |
|---|---|---|---|
| `session:<id>` | sklep, sesja klienta | czas życia refresh tokena | wylogowanie |
| `login:<id>` | sklep, transakcja logowania | 600 s | nieudane logowanie w toku |
| `login-rate:<klucz>`, blokada odświeżenia tokena | sklep | okno limitu, kilka sekund | otwarcie limitu logowań, podwójne odświeżenie |
| liczniki limiterów (`cache.rate_limiter`) | API | okno limitera | otwarcie limitu zamówień, płatności, webhooków, koszyków gościa, podglądu kodu |
| blokady limiterów | API | 5 s | równoległe żądania ponad limit |

Każdy klucz ma TTL, ale żaden nie jest cache: usunięcie dowolnego łamie gwarancję. Dlatego polityka usuwająca
(`volatile-lru` i każda inna) jest niedopuszczalna.

- `maxmemory-policy noeviction` jawnie i `maxmemory`. Bez `maxmemory` zapełnienie kończy się OOM-kill procesu: znikają
  wszystkie klucze, a po restarcie wraca ostatni zrzut RDB. Z `maxmemory` Redis odrzuca zapisy, a odczyty działają.
- Zachowanie aplikacji przy odrzuconym zapisie (z kodu; zadanie 4 sprawdza je testem z małym `maxmemory`): API nie
  zakłada blokady limitera i przepuszcza żądanie bez limitu z ostrzeżeniem (fail open, ta sama ścieżka co niedziałający
  Redis, decyzja D7 `hardening`); sklep odpowiada 503 na nowe logowanie, odświeżenie tokena i licznik logowań,
  a istniejące sesje czyta dalej. Stan ten wykrywa istniejący alert `RateLimitsOff` (CronJob `rate-limit-check`
  sprawdza zapis).
- Żadna polityka nie chroni liczników przy zalaniu Redisa kluczami (wiele adresów). Ochroną jest `maxmemory`
  z dużym zapasem wobec zmierzonego szczytu: `MEMORY USAGE` sesji i kluczy limiterów × docelowa liczba aktywnych
  sesji i kluczy. **[PERF-0]**
- RDB zostaje (sesje przeżywają restart). Zapis forkuje proces, a strony zmieniane w trakcie zapisu kopiują się
  (copy-on-write), w najgorszym razie do rozmiaru danych. Limit poda ≥ 2 × `maxmemory` + narzut (fragmentacja,
  bufory klientów); szczyt z `INFO` (`rdb_last_cow_size`, `mem_fragmentation_ratio`) w zadaniu 1.
  `stop-writes-on-bgsave-error yes` zostaje domyślne: nieudany zrzut daje tę samą ścieżkę co `maxmemory`.

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
- Budżet dysku (ADR): system i obrazy, baza, zmierzony szczyt `pg_wal` z zapasem (nie sama wartość `max_wal_size`),
  indeks × 2 przy przebudowie aliasu, kopia zapasowa (`pg_dump` na dysku węzła), Garage. Szczyt ≤ 80% dysku (próg
  `low` Elasticsearcha to 85%). Jeśli szczyt reindeksu `server` przekracza 80%, decyzja **D6**. **[server]**

## Dane w logach i metrykach

- Dziś Postgres loguje błędy z liniami DETAIL (wartości kluczy, np. adres e-mail przy naruszeniu unikalności), CONTEXT
  (np. wiersz `COPY`) i STATEMENT (tekst zapytania; parametry jako `$n`, wartości tylko w literałach). Alloy usuwa
  linie zaczynające się od `DETAIL:` i `STATEMENT:` i maskuje `Key (…)=(…)` (`test_log_masking.py`), ale przepuszcza
  CONTEXT i linie kontynuacji wielowierszowego DETAIL (zakleszczenie: `Process N: <zapytanie>`) oraz STATEMENT.
- PERF-1 dodaje takie wpisy (`log_lock_waits`, przerwania `statement_timeout`), więc usuwa je u źródła:
  `log_error_verbosity = terse` i `log_min_error_statement = panic`; `log_parameter_max_length_on_error` zostaje 0
  (domyślne). Maskowanie Alloy zostaje drugą warstwą.
- `terse` zostawia główny komunikat, a błędy danych (SQLSTATE klasy 22, np.
  `invalid input syntax for type uuid: "…"`, `invalid input value for enum`, `value "…" is out of range`) niosą w nim
  wartość wejścia. Alloy maskuje w liniach Postgresa literał w cudzysłowie po dwukropku i po `value`; nazwy ograniczeń
  i kolumn zostają.
- `pg_stat_statements` trzyma tekst znormalizowany (stałe jako `$n`); `track_utility = off` wyłącza polecenia użytkowe.
  Widok czyta tylko rola z `pg_read_all_stats` (eksporter, superużytkownik); tekst zapytań nie trafia do metryk.
- Eksportery Alloy (D7): `prometheus.exporter.postgres` bez kolektora `stat_statements` i bez tekstu zapytań, DSN
  z sekretu Kubernetesa jako wartość typu `secret`, rola tylko z `pg_monitor`; `prometheus.exporter.elasticsearch` tylko
  statystyki klastra i węzła. Etykiety serii to nazwy baz, ról, indeksów i węzłów; lista dozwolonych serii i ich
  etykiet jest w teście.

## Budżet pamięci węzła (kandydat, wariant A)

Liczby poza Postgresem i Elasticsearchem pochodzą z odczytu Grafany przy `medium`; tabela pokazuje metodę i rząd
wielkości, nie wartości do wdrożenia. Wartości ustala zadanie 4 z linii bazowej PERF-0 i pomiaru zadania 1.

| Pozycja | Request (nieodzyskiwalna z zapasem) | Limit |
|---|---|---|
| system, k3s, jądro, Argo CD, kube-system | – | ok. 2,8 GiB (zmierzone, poza podami sklepu) |
| rezerwa węzła | – | 1 GiB |
| monitoring | ok. 0,7 GiB | ok. 1,4 GiB |
| API, sklep, Keycloak, workery, Redis, RabbitMQ, Varnish, imgproxy, Garage, pozostałe (dziś zużywają razem ok. 1,6 GiB) | ok. 4 GiB | ok. 5 GiB (z chwilowymi alokacjami) |
| Postgres: `shared_buffers` 2 GiB + procesy + `maintenance_work_mem` | ok. 3 GiB | request + cache plików |
| Elasticsearch: sterta 2–3 GiB + poza stertą ok. 1 GiB | 3–4 GiB | request + cache plików |
| **Razem** | | **≤ 22,9 GiB; Postgres i Elasticsearch dostają łącznie ok. 6 GiB cache** |

Wniosek wstępny: na bufory i cache plików Postgres i Elasticsearch dostają łącznie ok. 8 GiB (`shared_buffers`
i cache) wobec ok. 3,5 GiB dziś i ok. 14 GB gorących danych `server` (9,5 GB indeksów sklepu w Postgresie z audytu,
ok. 4,5 GB indeksu w modelu R). Czy to wystarcza, pokażą `blks_hit` / `blks_read` i iowait przy W3 i W1 na `server`.
Jeśli nie, wariant B albo decyzja właściciela (program, zasada 7).

## Ryzyka

| Ryzyko | Mechanizm | Zabezpieczenie i pomiar |
|---|---|---|
| OOM węzła | pamięć nieodzyskiwalna i chwilowe alokacje usług działających naraz, pamięć jądra i system > pamięć węzła | Z1 wariant A: suma limitów + system + rezerwa ≤ pamięć węzła (test); W14 na `server` bez OOM |
| OOM kontenera | sterta JVM, `work_mem` × połączenia, 16 × `memory_limit`, imgproxy 16 × 50 MP, fork RDB | requesty i limity z pomiaru szczytu, `maxmemory` z zapasem na copy-on-write, `IMGPROXY_WORKERS` |
| eksmisja przez kubelet | udokumentowane: `working_set` z aktywnym cache liczy się jako zajęty; pod najbardziej ponad requestem idzie pierwszy | wariant A: cache zawsze w limitach mieszczących się w węźle; wariant B tylko z rezerwami kubeleta; W14 na `server` bez eksmisji |
| W15 a cache plików | `working_set` obejmuje aktywny cache, więc pełny cache (cel Z1) wygląda jak przekroczenie | **D4** |
| Redis pełny | odrzucone zapisy: limity API otwarte, logowania 503 | `maxmemory` z zapasem, `noeviction`, alert `RateLimitsOff` |
| dane osobowe w logach | DETAIL, CONTEXT i STATEMENT Postgresa, wartość w głównym komunikacie błędu danych | `terse`, `log_min_error_statement = panic`, maskowanie Alloy |
| blokady | zabity proces w transakcji zamówienia trzyma `FOR UPDATE` | `idle_in_transaction_session_timeout`; `log_lock_waits` |
| przeciążenie bazy | wolne zapytanie panelu trzyma wątek API i backend | `statement_timeout` dla API; 503 zamiast 500 |
| `too many clients` | pula Keycloaka do 100 przy `max_connections` 100 | `KC_DB_POOL_MAX_SIZE`, budżet połączeń |
| dysk | WAL ponad `max_wal_size` przy ciężkim zapisie, indeks × 2 przy reindeksie | budżet ze zmierzonego szczytu, alert `NodeDiskFull`, strażnik generatora |
| restart Postgresa przy wdrożeniu | `shared_preload_libraries` i `/dev/shm` wymagają restartu | dopuszczalne na etapie demo; wdrożenie za zgodą |

## Czego nie robimy (uproszczenia)

- Bez PgBouncera, replik odczytu, huge pages, partycjonowania (audyt: niepotrzebne przy jednej replice API).
- Bez limitów CPU dla Elasticsearcha, Postgresa, API i sklepu (throttling na ścieżce żądania).
- Bez `log_min_duration_statement` i bez zmian `random_page_cost` i `work_mem` bez pomiaru.
- Bez pliku `postgresql.conf` w ConfigMapie dla wartości pamięci: jeden plik dla ustawień stałych, `-c` dla zależnych
  od środowiska.
- Bez zmian k3s (`system-reserved`, progi eksmisji) w wariancie A.
- Bez eksportera Redisa i nowego alertu Redisa: stan „Redis nie przyjmuje zapisu” wykrywa `RateLimitsOff`.
- Bez własnych pomiarów Elasticsearcha i linii bazowej: dane z PERF-2 B i PERF-0.

## Pomiar

- Linię bazową W1–W20, zasoby `server` z Grafany i wynik generowania `server` bierzemy z raportu PERF-0, a stertę,
  pamięć i gorące dane Elasticsearcha z etapu B PERF-2. PERF-1 tych pomiarów nie powtarza; pomiar po zmianie używa
  tych samych poleceń.
- Lokalnie (`medium`, obraz produkcyjny, zasoby `prod`) tylko to, czego PERF-0 nie zbiera: `memory.stat` kontenerów,
  `pg_stat_statements`, `pg_stat_database`, `pg_statio_user_indexes`, `pg_stat_checkpointer`, szczyt `pg_wal`,
  `varnishstat`, `INFO` Redisa. Pomiary lokalne nie biegną równolegle z benchmarkami PERF-2 na tej samej maszynie;
  sesja epiku uzgadnia okno z właścicielem.
- Pomiar lokalny na `medium` weryfikuje działanie konfiguracji i daje porównanie względne. Nie zamyka kryteriów
  bezpieczeństwa zasobów dla `server` (pamięć nieodzyskiwalna, OOM, eksmisje, restarty, dostępna pamięć węzła, szczyt
  dysku). Te zamyka wyłącznie pomiar na worf.pl po wdrożeniu, a bieg W14 tam wymaga osobnej zgody właściciela. Bez
  zgody kryteria zostają w raporcie jako niepotwierdzone.
- Na worf.pl bez zgody tylko to, co program już dopuszcza: żądania GET (W3) i Grafana (W15).
- Grafana: `container_memory_rss` i `container_memory_cache` (zadanie 5 dopisuje je, jeśli nie zrobił tego PERF-0)
  oraz PSI węzła (`node_pressure_*`), żeby odróżnić rywalizację o pamięć, CPU i I/O od samego wykorzystania.
- Raport pomiaru w `docs/reports/` z wartościami przed i po; wynik obowiązujący to `server` na worf.pl.

## Decyzje właściciela

| ID | Decyzja | Rekomendacja | Wymaga |
|---|---|---|---|
| D1 | Reguła węzła dla Z1: wariant A (suma limitów + system + rezerwa ≤ pamięć węzła, cache tylko w limitach Postgresa i Elasticsearcha) albo wariant B (nadprzydział o cache z rezerwami kubeleta) | przyjęty wariant A (2026-11-26); B tylko, gdy pomiar na `server` pokaże za mało cache, za osobną decyzją | [PERF-0] [server] |
| D2 | Podział pamięci cache między Postgres i Elasticsearch | otwarta: według zmierzonych gorących danych: indeksy sklepu (z PERF-7) i odsetek trafień Postgresa, rozmiar indeksu i doc values modelu R | [server] [PERF-2B] |
| D3 | Sterta Elasticsearcha | otwarta: najmniejsza spełniająca kryterium z tabeli | [PERF-2B] |
| D4 | Pamięć w W15 mierzona osobno: pamięć nieodzyskiwalna kontenera ≤ request; cache plików bez progu. Kontrole zostają: 0 OOM, eksmisji i restartów, minimum dostępnej pamięci węzła ≥ rezerwa, PSI pamięci `full` nie wyżej niż w linii bazowej, iowait ≤ 5% | przyjęta 2026-11-26; zmienia się tylko miara pamięci w W15, wymagania wydajnościowe W1–W14 zostają; gdy nie da się ich spełnić w budżecie, decyzja właściciela (program, zasada 7) | zmiana definicji wymagania (program, zasada 10) |
| D5 | Redis: `noeviction` z `maxmemory`, RDB z limitem poda ≥ 2 × `maxmemory` + narzut | przyjęta 2026-11-26 (wartość `maxmemory` po pomiarze); alternatywa bez RDB usuwa ryzyko copy-on-write, ale restart wylogowuje wszystkich i zeruje limity | [PERF-0] (rozmiar) |
| D6 | Dysk, jeśli zmierzony szczyt reindeksu `server` (z `pg_wal` ponad `max_wal_size`) przekroczy 80%: mniejszy `max_wal_size`, reindeks bez równoległego generatora, albo usunięcie starego indeksu tuż po zamianie aliasu (PERF-6) | otwarta: według zmierzonego szczytu | [server] |
| D7 | Metryki Postgresa i Elasticsearcha na serwerze komponentami Alloy, jeśli nie dodał ich PERF-0 | przyjęta 2026-11-26 | – |

## Kryteria akceptacji

1. Postgres w `prod`, `load`, `ci` i compose ma ustawienia z tabeli (wartości z D2), `pg_stat_statements` działa
   (`SELECT count(*) FROM pg_stat_statements`), `/dev/shm` ma ustawiony rozmiar; `make -C infra/k8s check` i test
   renderu chartu to sprawdzają.
2. Zapytanie API dłuższe niż jego `statement_timeout` (test integracyjny z `pg_sleep`) kończy się 503 z komunikatem
   po polsku w czasie limitu, a wątek i połączenie są wolne dla kolejnego żądania; komenda konsoli w kontenerze API nie ma
   tego limitu; transakcja bezczynna dłużej niż limit traci połączenie i blokady (test).
3. Każda usługa `prod` ma request pamięci ≥ zmierzonemu szczytowi pamięci nieodzyskiwalnej i limit zgodny z Z1; test
   `infra/k8s/tests` sprawdza regułę węzła z D1 i sumę requestów CPU ≤ 7 vCPU.
4. `num_threads`, `IMGPROXY_WORKERS`, `VARNISH_SIZE`, `KC_DB_POOL_MAX_SIZE`, `maxmemory` i `maxmemory-policy noeviction`
   Redisa są jawne w chartcie i compose; budżet połączeń w ADR. Redis przy `maxmemory` nie usuwa kluczy, a API i sklep
   zachowują się jak w sekcji „Redis” (test).
5. Na compose naruszenie unikalności, zakleszczenie, czekanie na blokadę i przerwane zapytanie nie zostawiają w logu
   Postgresa wartości ani tekstu zapytania; wartość wejścia z głównego komunikatu błędu danych (klasa 22) nie przechodzi
   przez Alloy; seria eksporterów nie ma etykiety z tekstem zapytania (test).
6. Na `server` na worf.pl, po wdrożeniu za zgodą: W15 w definicji z D4 przy obciążeniu nominalnym i szczyt dysku
   w budżecie; W14 tam za osobną zgodą bez OOM, eksmisji i restartów. Bez zgody te kryteria są w raporcie jako
   niepotwierdzone; pomiar na `medium` ich nie zamyka.
7. W3, W5, W6 zmierzone po zmianie tymi samymi poleceniami co w PERF-0; odsetek trafień bufora Postgresa i iowait przy
   W3 przed i po.
8. ADR `docs/adr/runtime-resources.md` z budżetem pamięci, CPU, połączeń i dysku oraz uzasadnieniem każdego
   ustawienia innego niż domyślne; raport pomiaru w `docs/reports/`.
