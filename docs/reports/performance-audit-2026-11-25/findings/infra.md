# Audyt wydajności – obszar: infrastruktura i konfiguracja usług

Rewizja: main 2b4258c7. Analiza statyczna plików repo, bez pomiarów i bez dostępu do serwera. Liczby z Grafana Cloud
(7 dni) podała sesja prowadząca; oznaczam je jako [POMIAR-GC]. Nie czytałem `docs/reports/` ani `docs/experiments/`.

Klasy dowodu: [KOD] plik:linia, [POMIAR-GC] metryka od sesji prowadzącej, [HIPOTEZA] do zmierzenia, [SZACUNEK] ekstrapolacja.

## 0. Najważniejsze wnioski (TL;DR)

1. **Zimny listing to ~2–2,8 rdzenio-sekundy CPU w ES; serwer 8 vCPU obsłuży najwyżej ~2–3 zimne listingi/s, a 8
   równoczesnych przekracza 2 s timeoutu klienta ES → 503 z API → 500 w sklepie.** Żadna konfiguracja infrastruktury tego
   nie naprawi więcej niż o kilkadziesiąt procent; przyczyną jest koszt zapytania (obszar wyszukiwarki). Infrastruktura
   może tylko: (a) dać ES więcej udziału w CPU przy rywalizacji, (b) odcinać nadmiar szybko zamiast kaskady, (c) nie
   zamieniać krótkiego przeciążenia w dwuminutową utratę coalescingu w Varnishu.
2. **Limity pamięci kontenerów obcinają page cache ES i Postgresa** (cgroup v2 wlicza cache plików do `memory.max`):
   ES ma ~1,5 GiB na cache indeksu (limit 4 GiB − heap 2 GiB − off-heap), Postgres ~1,7 GiB cache (limit 2 GiB,
   `shared_buffers` 128 MB domyślnie) – przy 14 GiB wolnej pamięci węzła, której te procesy nie mogą użyć. Dla profilu
   medium (indeks 1 GB) to jeszcze działa; dla server (indeks ~11 GB, baza 60–90 GB) oznacza odczyty z dysku (iowait już
   18% [POMIAR-GC]).
3. **Postgres działa na czystych ustawieniach domyślnych** (żadnych `-c`): `shared_buffers=128MB`, `work_mem=4MB`,
   `max_wal_size=1GB`, `random_page_cost=4`, `jit=on`, brak `pg_stat_statements`, a pod ma `/dev/shm` 64 MB (zapytania
   równoległe mogą padać). To najtańsza i najpewniejsza poprawa w tym obszarze.
4. **Żadna warstwa nie stosuje backpressure**: Traefik bez `inFlightReq`, Varnish bez `max_connections`, FrankenPHP bez
   `max_wait_time` (kolejka bez limitu), sklep bez timeoutu fetch do API, Postgres bez `statement_timeout`. Pod
   przeciążeniem wszystko zwalnia naraz, a pierwszym twardym ogranicznikiem jest 2 s timeout klienta ES.
5. **Varnish: 100 MB cache (domyślne obrazu), grace 10 s, TTL listingu 60 s i hit-for-miss 120 s po każdej odpowiedzi
   503** – po pierwszym timeoucie dany URL traci coalescing żądań na 2 minuty, więc przeciążenie się samo wzmacnia.
6. **Skalowanie poziome jest zablokowane w chartcie**: `replicas: 1` na sztywno w helperach; sklep ma cache ISR w pamięci
   procesu i rewalidację wysyłaną jednym żądaniem przez Service (trafi do jednej repliki). API, workery i limitery są
   gotowe na >1 replikę (Redis, blokady doradcze PG).
7. **Środowisko `make -C infra/k8s load` nie mierzy problemu**: zbiór URL-i jest mały (60 produktów, kategorie bez
   filtrów i stron, frazy z 60 nazw), jest rozgrzewka, k6 dzieli CPU z klastrem w VM Dockera (≥10 CPU zamiast 8, inna
   architektura i dysk). Wynik opisuje ciepłą ścieżkę, nie długi ogon zimnych listingów.
8. **8 vCPU / 23 GiB nie wystarcza dla profilu server przy równoległym ruchu** [SZACUNEK]; minimalnie sensowne to
   ~16 vCPU / 64 GiB / NVMe na jednym węźle albo rozdział ES | Postgres | aplikacje na 2–3 węzły – ale bez zmniejszenia
   kosztu zimnego listingu nawet to daje tylko ~5–7 zimnych listingów/s.

## 1. Zasoby workloadów w prod

Źródło: `infra/k8s/environments/prod/values.yaml:113-134`, domyślne w `charts/shop/values.yaml:250-273`, helper
`charts/shop/templates/_helpers.tpl:234-346` (`replicas: 1`, brak limitów CPU). [KOD]

| Usługa | Rodzaj | Repl. | req CPU | req mem | lim CPU | lim mem | Uwagi |
|---|---|---|---|---|---|---|---|
| postgres | STS | 1 | 500m | 1Gi | – | 2Gi | PVC 10Gi (nienadpisane w prod), domyślne parametry |
| elasticsearch | STS | 1 | 1 | 3Gi | – | 4Gi | heap 2g, 4 shardy, 0 replik |
| redis | STS | 1 | 100m | 128Mi | – | 512Mi | bez `maxmemory`, RDB domyślne |
| rabbitmq | STS | 1 | 250m | 512Mi | – | 1Gi | |
| garage | STS | 1 | 100m | 256Mi | – | 1Gi | PVC data 20Gi |
| keycloak | Deploy (Recreate) | 1 | 500m | 1536Mi | – | 2Gi | heap 512m–1g |
| api | Deploy | 1 | 1 | 512Mi | – | 2Gi | FrankenPHP 16 wątków |
| worker | Deploy | 1 | 300m | 320Mi | – | 640Mi | |
| worker-commands | Deploy | 1 | 200m | 192Mi | – | 384Mi | |
| store | Deploy | 1 | 500m | 512Mi | – | 1536Mi | 1 proces Node |
| admin | Deploy | 1 | 50m | 64Mi | – | 128Mi | |
| imgproxy | Deploy | 1 | 250m | 256Mi | – | 1Gi | domyślnie 2×CPU = 16 równoległych przetworzeń |
| varnish | Deploy | 1 | 100m | 256Mi | – | 512Mi | malloc 100M (domyślne obrazu) |
| mailpit | Deploy | 1 | 50m | 64Mi | – | 256Mi | |
| **Suma sklepu** | | | **4,9 vCPU** | **8,5 GiB** | – | **16,9 GiB** | |
| monitoring (Alloy, KSM, node-exporter, operator, reloader) | | | 0,36 | 0,66 GiB | 1,85 | 1,38 GiB | `argocd/apps/monitoring.yaml:253-290` |
| k3s (traefik, coredns, metrics-server, local-path), Argo CD, sealed-secrets | | | ~0,2 | ~0,2 GiB | – | brak (Argo CD BestEffort) | + proces k3s/containerd ~0,7–1 GiB poza podami [SZACUNEK] |
| Joby cykliczne (co 5 min: rate-limit-check, queue-check, order-expiry; co godz. cart-purge) | | | 100m | 256Mi | – | 1Gi | każdy startuje pełny obraz PHP |
| Generator demo (tylko w trakcie) | | | 500m | 1Gi | – | 4Gi | bez limitu CPU |

Wnioski:

- **QoS: wszystko Burstable.** Brak `system-reserved`/`kube-reserved` w `/etc/rancher/k3s/config.yaml`
  (`scripts/server-bootstrap.sh:150-154`) – allocatable = pojemność − 100 Mi progu eksmisji. Suma limitów pamięci
  (16,9 + 1,4 GiB) + system (~2–3 GiB) ≈ 20–21 GiB z 22,9 GiB – mieści się, ale bez rezerwy na Joby (generator 4 GiB,
  reindeks) i bez swapu. Przy realnym użyciu daleko od tego (min. dostępna pamięć węzła 14 GiB [POMIAR-GC]), więc ryzyko
  OOM na poziomie węzła jest dziś niskie; ryzyko OOMKill **pojedynczego kontenera** jest realne w ES, API i Redisie (niżej).
- **Brak limitów CPU = brak throttlingu, ale udział w CPU przy rywalizacji wyznaczają requesty** (`cpu.weight`). ES ma
  1000m z ~5,5 vCPU sumy requestów, czyli przy pełnej rywalizacji gwarantowane ~1,45 rdzenia; Keycloak (500m) potrafi
  wziąć 3,9 rdzenia [POMIAR-GC] w czasie generatora kont. Waga: **Wysoka**, konfiguracja. Rekomendacja: podnieść request
  ES do 3–4 (odzwierciedla jego priorytet), dać **limit CPU** procesom wsadowym i hałaśliwym (Keycloak np. 2, generator
  demo i reindeks np. 2–3), nigdy usługom krytycznym dla opóźnień (ES, API, sklep, PG). Koszt S.
- Requesty sklepu (4,9 vCPU) i check w `make check` („sum ≤ 7 vCPU, limits ≤ 20 GB”, komentarz `prod/values.yaml:1-2`)
  pilnują planowania, nie wydajności – to dobre ograniczenie, nie ruszać.

### 1.1 Elasticsearch: CPU, pule wątków, heap, page cache

- `node.processors` nieustawione, brak limitu CPU → JVM widzi 8 procesorów → pula `search` = int(8·3/2)+1 = **13 wątków**,
  kolejka 1000 ([KOD] brak ustawień w `templates/elasticsearch.yaml:6-26`; formuła:
  https://www.elastic.co/guide/en/elasticsearch/reference/current/modules-threadpool.html). Przy 4 shardach jeden listing
  zajmuje 4 wątki, więc ~3 równoczesne zimne listingi zapełniają pulę, a reszta czeka w kolejce. Kolejka 1000 nie odrzuca
  (brak `es_rejected_execution` 429), więc żądania czekają aż klient PHP zrezygnuje po 2 s
  (`apps/api/config/services.yaml:41-45`, brak ponowień – dobrze). **Uwaga na przyszłość:** dodanie limitu CPU do ES
  zmniejszy `availableProcessors` (JVM respektuje kwotę cgroup) i pule – wtedy jawnie ustawić `node.processors`.
- Heap 2 GiB przy limicie 4 GiB [KOD `prod/values.yaml:103,116`]. Off-heap ES: pamięć bezpośrednia (domyślnie do połowy
  heapu), metaspace, stosy wątków, Lucene – typowo 0,5–1,2 GiB. Working set 3589 MiB / 4096 [POMIAR-GC] zawiera aktywny
  cache plików. **Skutek:** cache indeksu w tej cgroup ma tylko ~1–1,5 GiB. Elastic zaleca heap ≤ 50% RAM, reszta dla
  cache systemu plików (https://www.elastic.co/guide/en/elasticsearch/reference/current/advanced-configuration.html#set-jvm-heap-size,
  https://www.elastic.co/guide/en/elasticsearch/reference/current/tune-for-search-speed.html). Cgroup v2 rozlicza cache
  plików w `memory.current` i odzyskuje go przy `memory.max`
  (https://docs.kernel.org/admin-guide/cgroup-v2.html#memory-interface-files).
  - medium (indeks 1 GB): mieści się prawie w całości – ES dziś jest CPU-bound, nie IO-bound [HIPOTEZA].
  - server (~11 GB): mieści się ~10–15% indeksu → zimne listingi czytają z dysku; doc values pola sortowania i filtrów
    dla 10 mln dokumentów to kilka GB [SZACUNEK].
  - Waga **Krytyczna dla profilu server**, konfiguracja/sprzęt. Rekomendacja: limit pamięci ES = heap + ~1,5 GiB +
    docelowy cache (dla server: heap 3–4 GiB, limit 10–12 GiB) albo wyłącznie request bez limitu, jeśli węzeł ma zapas.
    Koszt S (wartości), efekt zależny od sprzętu (sekcja 8).
  - Ryzyko OOMKill: anon (heap 2 + direct do 1 + reszta) ≈ 3,3–3,6 GiB przy limicie 4 GiB [HIPOTEZA]. Pomiar:
    `kubectl exec elasticsearch-0 -- cat /sys/fs/cgroup/memory.stat | grep -E '^(anon|file|active_file) '` i
    `GET _nodes/stats/jvm,breaker`.
- Shardy 4 / repliki 0 (`services.yaml:13-14`, `prod/values.yaml:106`): replik 0 na jednym węźle – dobrze (zielony stan,
  brak podwójnych zapisów). 4 shardy skracają opóźnienie **pojedynczego** zapytania, ale nie zmniejszają łącznego CPU
  (dodają narzut per shard: merge wyników, agregacje per shard), więc **zmniejszają przepustowość pod współbieżnością**
  [HIPOTEZA]. Pomiar: k6 z 8 i 16 VU na unikalnych zimnych URL-ach na indeksie 1 vs 4 shardy, metryka: zimne listingi/s przy
  p95 < 2 s i suma `GET _nodes/stats/thread_pool/search` `completed`.
- `vm.max_map_count=262144` ustawia bootstrap (`scripts/server-bootstrap.sh:122-129`) – dobrze, nie ruszać.
- Watermarki dysku (domyślne 85/90/95%): przy dysku 193 GiB nowy indeks nie zostanie zaalokowany przy <29 GiB wolnego, a
  przy <9,7 GiB indeksy dostają blokadę `read_only_allow_delete` (zapisy katalogu padają). Reindeks z aliasem dla server
  wymaga ~2×11 GB + zapas na merge (do ~1× rozmiaru segmentów) [SZACUNEK]. Próg generatora 32 GB (`prod/values.yaml:92`)
  jest spójny z watermarkiem low – dobrze.

### 1.2 API (FrankenPHP)

- Tryb klasyczny (`php_server`, bez `worker`) [KOD `apps/api/docker/frankenphp/Caddyfile:31-42`]: każde żądanie bootuje
  kernel Symfony (opcache jest, preload nie). `num_threads` nieustawione → **2 × CPU = 16 wątków** (potwierdza to
  `infra/k8s/scripts/load.sh:807-810`; doc: https://frankenphp.dev/docs/config/). `max_wait_time` nieustawione → kolejka
  żądań czekających na wątek bez limitu i bez czasu.
- API dziś nie jest wąskim gardłem (szczyt 0,26 rdzenia [POMIAR-GC]), ale **wątki są blokowane czekaniem na ES**: 8
  zimnych listingów + offers + reszta ruchu zajmuje ~10 z 16 wątków na ≥2 s. Przy ~16 równoczesnych wolnych żądaniach
  kolejka rośnie, a w niej czeka też `/health` (readiness, `templates/api.yaml:134-140`: 20×5 s) → po ~100 s przeciążenia
  jedyny pod API wypada z endpointów → Varnish i sklep dostają odmowę połączenia (pełna awaria zamiast degradacji)
  [HIPOTEZA, ścieżka z KOD]. Waga **Średnia**.
- `memory_limit=256M` × 16 wątków = 4 GiB teoretycznie przy limicie poda 2 GiB [KOD `app.ini:2`]. Ciężkie równoległe żądania
  (mapa strony 10 000 produktów/porcję, eksporty panelu) mogą wywołać OOMKill całego API [HIPOTEZA]. Waga Średnia.
- Rekomendacje (S): `max_wait_time` (np. 2–3 s) – szybkie odrzucenie zamiast nieskończonej kolejki; readiness przez
  odpowiedź Caddy bez PHP (np. `handle /healthz { respond 200 }`) albo wyższy `failureThreshold` – przy jednej replice
  wyjęcie poda z endpointów nigdy nie pomaga; tryb worker FrankenPHP rozważyć dopiero po pomiarze kosztu bootu (to zmiana
  aplikacji: stan między żądaniami). Pomiar: włączyć metryki Caddy (`frankenphp_busy_threads`, `frankenphp_queue_depth`,
  https://frankenphp.dev/docs/metrics/) i obserwować w teście 8/16 zimnych listingów.

### 1.3 Sklep (Next.js)

- Jeden proces `node server.js` [KOD `apps/store/Dockerfile:58`] = jedna pętla zdarzeń, SSR listingów (dynamicznych,
  `cache: "no-store"`, `apps/store/src/catalog/load-catalog.ts:15-20`) na jednym rdzeniu. Dziś 0,43 rdzenia [POMIAR-GC];
  sufit ~1 rdzeń na replikę.
- Fetch do API/Varnisha **bez timeoutu** (brak `AbortSignal` w `src/api/client.ts`, `load-catalog.ts`) – undici czeka do
  300 s na nagłówki, Cloudflare przerywa po 100 s (524). Każde wiszące żądanie trzyma pamięć i gniazdo. Waga Średnia,
  kod. Rekomendacja: `AbortSignal.timeout(~3 s)` dla odczytów katalogu (S).
- Cache ISR w pamięci 128 MB (`next.config.ts:22`) – rozsądne, ograniczone; ale stanowe per proces (sekcja 4).

## 2. Konfiguracja usług wobec 10 mln produktów

### 2.1 Postgres – Wysoka/Krytyczna

[KOD] `templates/postgres.yaml` nie przekazuje żadnych `args`; compose też nie (`infra/docker-compose.yml:12-27`);
`infra/postgres/init/10-create-databases.sh` tylko tworzy bazy. Więc obowiązują domyślne PG 18:

| Parametr | Teraz | Problem przy 10 mln / 5 mln zamówień | Propozycja dla obecnego serwera (server) |
|---|---|---|---|
| shared_buffers | 128MB | hot set indeksów produktów/wariantów/cen nie mieści się | 3–4GB (i limit poda ≥ 7–8 GiB) |
| effective_cache_size | 4GB | planer nie wie o cache | 8–10GB |
| work_mem | 4MB | sortowania/hash w panelu i raportach na dysk | 16–32MB (×16 wątków API + workery) |
| maintenance_work_mem | 64MB | budowa indeksów na 10 mln wierszy w generatorze i migracjach wolna | 512MB–1GB |
| max_wal_size | 1GB | częste checkpointy przy generowaniu → pełne obrazy stron → ogromny WAL (36 GB WAL na medium wg zlecenia) | 8–16GB, `checkpoint_timeout=15min`, `wal_compression=lz4` |
| random_page_cost | 4 | zawyżone dla SSD → planer unika indeksów | 1.1–1.5 [HIPOTEZA: zależy od dysku VPS] |
| jit | on | JIT kompilacja potrafi dodać setki ms do średnich zapytań OLTP | off |
| shared_preload_libraries | – | brak `pg_stat_statements` = brak diagnozy | `pg_stat_statements` |
| max_connections | 100 | 16 (API) + workery + Joby + pula Keycloaka (domyślnie do 100) + generator | 100 wystarczy, ale ograniczyć `KC_DB_POOL_MAX_SIZE` do ~20 |
| statement_timeout | 0 | wiszące zapytanie trzyma wątek PHP bez końca | per rola `shop` np. 5–10 s (`ALTER ROLE`), dłużej dla Jobów |
| autovacuum_*_scale_factor | 0.2/0.1 | na 10 mln wierszy vacuum po 2 mln martwych krotkach | 0.02/0.01 dla dużych tabel (per tabela) |

- **`/dev/shm` = 64 MB** (domyślne containerd, brak `emptyDir: {medium: Memory}` w `postgres.yaml`). PG używa
  `dynamic_shared_memory_type=posix` dla zapytań równoległych; parallel hash join na dużych tabelach może skończyć się
  błędem „could not resize shared memory segment … No space left on device” → 500 [HIPOTEZA, mechanizm znany].
  Pomiar: `kubectl exec postgres-0 -- df -h /dev/shm`; `EXPLAIN ANALYZE` ciężkiego zapytania panelu z
  `max_parallel_workers_per_gather=2`. Naprawa: wolumen `emptyDir` Memory 1Gi na `/dev/shm` (S).
- Jak przekazać: `args: ["-c","shared_buffers=4GB", ...]` w kontenerze STS (obraz `postgres` przyjmuje flagi), wartości
  w `values.yaml` per profil; to samo w compose (zasada „chart odzwierciedla compose”). Koszt S, efekt: Wysoki dla
  profilu server [SZACUNEK].
- PVC `storage.postgres: 10Gi` w prod nienadpisane (`charts/shop/values.yaml:211`). local-path tego nie egzekwuje
  (katalog na hoście), więc to nie limit, tylko nieprawdziwa deklaracja; prawdziwym limitem jest dysk węzła. Niska, ale
  warto ustawić realne rozmiary (90Gi/30Gi) dla czytelności i przyszłej migracji na egzekwujący provisioner.
- Waga całości: **Krytyczna dla server** (wszystkie odczyty ofert/cen/stanów listingu i zapisy zamówień idą przez PG,
  2,5 rdzenia szczytu już na medium [POMIAR-GC]), przyczyna: konfiguracja. Pomiar na medium: `SELECT
  blks_hit::float/(blks_hit+blks_read) FROM pg_stat_database WHERE datname='shop'`, `pg_stat_bgwriter`/
  `pg_stat_checkpointer` (checkpoints_req vs timed), `pg_stat_statements` top 20 po `total_exec_time` przy teście k6.

### 2.2 Redis – Średnia

- Brak konfiguracji [KOD `templates/redis.yaml`, compose]: `maxmemory 0` (bez limitu), domyślne RDB (`save 3600 1 300 100
  60 10000`), `appendonly no`, limit poda 512 MiB. Role: sesje BFF sklepu i limiter logowania
  (`apps/store/src/auth/session-store.ts`, `login-rate-limit.ts`), limitery i ich blokady API
  (`apps/api/config/packages/rate_limiter.yaml:15-23`, `lock.yaml`). Brak cache aplikacyjnego w Redisie.
- Skutek: przy wzroście liczby sesji (1 mln klientów; sesja z tokenami OIDC ~3–5 KB [SZACUNEK]) Redis przekroczy limit →
  **OOMKill zamiast eksmisji**; fork RDB przy dużym zbiorze podwaja strony kopiowane przy zapisie. Utrata Redisa = utrata
  sesji (wylogowanie) i chwilowe wyłączenie limitów (fail-open – świadoma decyzja, dobrze).
- Rekomendacja (S): `--maxmemory 384mb --maxmemory-policy volatile-lru` (wszystkie klucze sesji i limiterów mają TTL –
  [HIPOTEZA] do sprawdzenia `redis-cli --scan | head; TTL`), rozważyć `--save ""` jeśli utrata sesji przy restarcie jest
  akceptowalna. Pomiar: `redis-cli info memory` i `info keyspace` po teście z logowaniem.

### 2.3 RabbitMQ – Niska

Domyślna konfiguracja; obsługuje tylko zdarzenia (outbox → events); komendy są w Postgresie. Watermark pamięci 0,4 limitu
cgroup (RabbitMQ 4 wykrywa limit) ≈ 410 MiB – wystarczy. Brak metryk (plugin `rabbitmq_prometheus` na 15692 jest w
obrazie domyślnie włączony – do zbierania, sekcja 6). Nie ruszać.

### 2.4 Varnish – Wysoka

[KOD] `templates/varnish.yaml:3-5`: tylko `-t 0`. `infra/varnish/default.vcl`:
- **Rozmiar cache 100 MB** – domyślne `VARNISH_SIZE` oficjalnego obrazu (https://hub.docker.com/_/varnish: „usually a bit
  too small”). Limit poda 512 MiB pozwala na ~300 MB. Przy długim ogonie listingów (kategorie × filtry × sortowania ×
  strony) i 10 mln kart produktów LRU wyrzuca obiekty [HIPOTEZA]. Pomiar: `varnishstat -1 -f SMA.s0.g_bytes -f
  SMA.s0.g_space -f MAIN.n_lru_nuked -f MAIN.cache_hit -f MAIN.cache_miss -f MAIN.cache_hitmiss`.
- **TTL listingu 60 s** (`ListProductsController.php:136-137`: `public, max-age=60`, bez `s-maxage`), grace domyślne
  10 s. Po 70 s bez ruchu każdy listing jest znowu zimny – dla długiego ogona cache prawie nic nie daje.
- **Hit-for-miss po 503**: API zwraca 503 z `private, no-store` (`PublicReadErrorCacheSubscriber.php:22-42`); wbudowany
  VCL (`call vcl_builtin_backend_response`, `default.vcl` sub `vcl_backend_response`) tworzy wtedy obiekt hit-for-miss na
  **120 s** – kolejne żądania tego URL-a nie czekają na liście oczekujących (brak coalescingu), tylko równolegle idą do API.
  Czyli dokładnie w chwili przeciążenia ES popularny URL zaczyna generować N równoległych zapytań zamiast jednego.
  [KOD + znane zachowanie builtin.vcl: https://varnish-cache.org/docs/trunk/users-guide/vcl-built-in-code.html]. Waga
  **Wysoka**, konfiguracja. Rekomendacja (S): w `vcl_backend_response` dla `beresp.status >= 500` → `return (abandon)`
  przy `bereq.is_bgfetch` (serwuj stare) albo krótkie `beresp.ttl=1s; uncacheable` zamiast 120 s; dłuższe
  `beresp.grace` (np. 1 h) dla listingów i kart, żeby popularne URL-e odświeżały się w tle zamiast zimnym żądaniem.
  To nie jest leczenie kosztu zapytania cache'em, tylko usunięcie wzmacniacza przeciążenia.
- Backend bez `.max_connections`, `first_byte_timeout` domyślne 60 s – Varnish nie ogranicza równoległości do API.
- Varnish stoi tylko przed odczytami katalogu API (store → `API_CATALOG_URL`); strony HTML sklepu idą Cloudflare →
  Traefik → store bez cache po drodze (poza ISR kart produktu). To świadomy projekt.

### 2.5 Keycloak – Średnia

Heap 512m–1g, limit 2 GiB, brak limitu CPU, pula DB domyślna. 3,9 rdzenia w czasie generatora kont [POMIAR-GC] –
hashowanie haseł. Przy prawdziwym ruchu logowań 1 mln klientów Keycloak będzie konkurował z ES o CPU. Rekomendacja: limit
CPU (np. 2), `KC_DB_POOL_MAX_SIZE≈20`. (S)

### 2.6 imgproxy – Średnia

Brak `IMGPROXY_WORKERS` (domyślnie 2×CPU = 16 równoległych przetworzeń) przy limicie 1 GiB i źródłach do 50 MP
(`templates/imgproxy.yaml:24-28`). 16 jednoczesnych zimnych wariantów dużych zdjęć może przekroczyć 1 GiB → OOMKill, a
równolegle zjada CPU węzła [HIPOTEZA]. Cache: URL-e kończą się rozszerzeniem `.webp`/`.avif`
(`ImgproxyUrlGenerator.php:43`), więc Cloudflare je cache'uje (domyślne rozszerzenia) – dobrze. Rekomendacja:
`IMGPROXY_WORKERS=4`, `IMGPROXY_MAX_CLIENTS` z kolejką, limit CPU ~2. (S)

### 2.7 Garage – Niska

LMDB, replikacja 1, PVC data 20Gi (local-path, nieegzekwowane). Brak problemów wydajnościowych w ścieżce listingu (zdjęcia
przez imgproxy + Cloudflare). Rozmiar puli zdjęć dla server nieznany [HIPOTEZA] – wliczyć do budżetu dysku.

## 3. Łańcuch żądania, limity czasu, backpressure

| Warstwa | Timeout / limit | Kolejka / odrzucanie | Źródło |
|---|---|---|---|
| Cloudflare | 100 s do originu → 524 | – | domyślne CF |
| Traefik (k3s) | `readTimeout` 60 s (v3), brak `responseHeaderTimeout` do backendu, brak retry | brak `inFlightReq`, `rateLimit` | `argocd/install/traefik.yaml` (tylko `externalTrafficPolicy`), `templates/middlewares.yaml` |
| store (Next) | fetch bez timeoutu (undici 300 s) | brak limitu współbieżności | `load-catalog.ts` |
| Varnish | connect 3,5 s, first byte 60 s | brak `max_connections`; coalescing tylko dla obiektów cache'owalnych | `default.vcl` |
| api (Caddy) | `read_header 10s`, `read_body 60s`; brak write/idle; PHP `max_execution_time` 30 (domyślne, brak php.ini) [HIPOTEZA] | wątki 16, kolejka bez limitu (`max_wait_time` off) | `Caddyfile:9-14` |
| ES (klient PHP) | connect 1 s, odpowiedź 2 s, 0 ponowień → `SearchUnavailable` → 503 | pula search 13, kolejka 1000 | `services.yaml:40-45`, `ElasticsearchClientFactory.php` |
| Postgres | brak `statement_timeout`, `lock_timeout` | `max_connections` 100 | domyślne |
| Redis | 1 s connect/read, fail-open | – | `rate_limiter.yaml:15` |

**Gdzie powstaje 500/503 przy 8 zimnych listingach:** ES przekracza 2 s (sekcja 7) → `SearchUnavailable` →
`ServiceUnavailableHttpException` 503 (`ListProductsController.php:126-131`) → Varnish przekazuje 503 i ustawia
hit-for-miss 120 s → `loadCatalog` rzuca dla statusu ≠ 400/404 (`load-catalog.ts:84-88`) → `app/error.tsx` → **500
strony sklepu**. To jedyny „odrzucający” element i działa przypadkiem (timeout), nie jako kontrola przyjęcia: ES dalej
liczy porzucone zapytania (anulowanie przy zamknięciu połączenia istnieje, ale fazy shardów sprawdzają je okresowo
[HIPOTEZA]), więc CPU jest marnowane na odpowiedzi, których nikt nie odbierze.

**Kaskada zamiast backpressure.** Rekomendacja minimalna (wszystko S, bez nowych usług):
1. Varnish: grace + brak hit-for-miss na 5xx (wyżej) – stare odpowiedzi zamiast 503 dla popularnych URL-i.
2. FrankenPHP `max_wait_time` ~2 s – szybki 503 zamiast kolejki, która blokuje też `/health`.
3. Store: `AbortSignal.timeout` ~3 s dla odczytów katalogu i strona błędu ze statusem 503 + `Retry-After` zamiast 500.
4. Ewentualnie Traefik `inFlightReq` per host sklepu jako bezpiecznik (nie jako rozwiązanie).
5. Kontrola współbieżności zimnych zapytań ES po stronie API (semafor w Redisie/PG albo mniejsza pula) to już zmiana
   aplikacji – zgłaszam obszarowi API/wyszukiwarki.

**Probes i rollout:** brak `livenessProbe` gdziekolwiek – przeciążony pod nie jest restartowany (dobrze, nie dodawać
liveness opartego na HTTP aplikacji). Readiness API przez PHP – ryzyko opisane w 1.2. Rollout: `maxUnavailable 0,
maxSurge 1` (`_helpers.tpl:249-257`) – poprawny dla bezstanowych; Keycloak `Recreate` (uzasadnione); ES/PG jako STS
z jedną repliką = przerwa przy restarcie (dopuszczalne na etapie demo).

## 4. Skalowanie poziome

- **`replicas: 1` na sztywno** w `shop.deployment` i `shop.statefulset` (`_helpers.tpl:245,305`), brak HPA i
  `PodDisruptionBudget`. Na jednym węźle HPA nie dodaje mocy, ale dodaje procesy (sklep: 1 proces = 1 rdzeń). Waga
  Średnia; rekomendacja: `replicas` jako wartość per usługa w `values.yaml` (S).
- Co jest stanowe w procesie:
  - **Sklep – ISR w pamięci** (`next.config.ts:22-30`) i rewalidacja przez **jedno** żądanie `POST
    http://store:3000/api/revalidate` (`STORE_INTERNAL_URL`, `templates/api.yaml:73-74`) – Service skieruje je do jednej
    repliki, pozostałe serwują stare karty produktu do 1 h (`app/p/[slug]/page.tsx:10`). **Blokuje >1 replikę sklepu.**
    Naprawa: rozesłanie rewalidacji do wszystkich podów (headless Service + pętla po adresach) albo wspólny
    `cacheHandler` (np. w istniejącym Redisie). M. Listingi (dynamiczne) tego problemu nie mają.
  - API: pule `cache.oidc` i `cache.sitemap` na `cache.app` = system plików poda (`framework.yaml:13-18`) – per replika,
    bez problemu poprawności (TTL ≤ 1 h). Doctrine `result_cache_pool` na `cache.app` (`doctrine.yaml` `when@prod`) –
    per replika, [HIPOTEZA] czy jest używany. Blokady: doradcze Postgresa (`Postgres*Lock.php`) i Redis (limitery) –
    gotowe na wiele replik. Workery: transporty Doctrine/RabbitMQ – wiele konsumentów OK.
  - Varnish: purge wysyłany pod `http://varnish` (`templates/api.yaml:71`) – przy >1 replice ten sam problem co ISR.
  - ES/PG/Redis/RabbitMQ: pojedyncze instancje (skalowanie to osobny projekt; na etapie demo nie warto).
- Wniosek: najtańsze „poziome” zyski to 2 repliki API (po `max_wait_time`) i 2 sklepu (po fan-out rewalidacji) – ale
  na jednym węźle 8 vCPU zysk jest niewielki, bo wąskim gardłem jest CPU ES, nie procesy aplikacji.

## 5. Dysk

- Wszystkie PVC na `local-path` (`values.yaml:209-218`) – katalogi na jednym dysku OVH VPS; PG (fsync WAL), ES (merge,
  translog fsync) i Garage dzielą to samo urządzenie. iowait do 18% [POMIAR-GC] przy medium. Dysk VPS jest najpewniej
  sieciowy/współdzielony [HIPOTEZA]. Pomiar: `pg_test_fsync` w podzie PG i `fio --rw=randread --bs=8k --iodepth=16
  --direct=1` na katalogu local-path (osobna zgoda – to operacja na serwerze; ewentualnie na medium lokalnie nie da się).
- Budżet dla server [SZACUNEK]: system + obrazy ~15–20 GB, baza 60–90 GB, WAL w szczycie generowania (`max_wal_size` 1 GB
  domyślnie ogranicza retencję, ale checkpointy co ~1 GB WAL podbijają wolumen zapisu; zmierzone 36 GB WAL na medium to
  najpewniej wolumen wygenerowany, nie zatrzymany) 2–16 GB, indeks 11 GB ×2 przy reindeksie + merge ~5 GB, Garage
  (pula zdjęć) ?, kopia `backupWork` 25 GiB po włączeniu backupu, logi kontenerów ~50 MB/kontener (kubelet 10 Mi × 5).
  Razem ~125–175 GB z 193 → przy górnym końcu **przekroczony watermark low ES (85%) i alert NodeDiskFull** w trakcie
  reindeksu. Waga Wysoka dla server, ograniczenie sprzętu. Rekomendacja: `wal_compression`, realne rozmiary PVC,
  usuwanie starego indeksu zaraz po przepięciu aliasu (sprawdzić w `catalog:reindex`), dysk ≥ 300–400 GB NVMe.

## 6. Obserwowalność wydajności

Dozwolone metryki (`argocd/apps/monitoring.yaml:84-117`): domyślna lista chartu k8s-monitoring (kube-state-metrics,
cAdvisor/kubelet, node-exporter) + 12 metryk KSM pod alerty/hypercare + `node_filesystem_*`. Brak jakichkolwiek
metryk aplikacyjnych. Alerty (`templates/00-alerts.yaml`): backup, limiter, kolejki, wygaszanie zamówień, dysk, cisza
klastra, gotowość workloadów – **zero alertów wydajnościowych** (5xx, opóźnienie, OOMKill, presja pamięci/CPU).

Brakuje do diagnozy i jak dodać najtaniej (Alloy już działa, darmowy plan 10k serii):
1. **`pg_stat_statements`** – `shared_preload_libraries` + `CREATE EXTENSION`; czytany ręcznie psql, 0 serii. S, najwyższy
   zwrot.
2. **Traefik** – k3s Traefik ma metryki Prometheus (port 9100); scrape przez Alloy z keep-listą
   `traefik_service_requests_total`, `traefik_service_request_duration_seconds_bucket` (≈7 usług × ~10 kubełków × kody ≈
   kilkaset serii). Daje 5xx i p95 per usługa → alert. S.
3. **PSI węzła** – `node_pressure_cpu_waiting_seconds_total`, `node_pressure_io_stalled_seconds_total`,
   `node_pressure_memory_stalled_seconds_total` (node-exporter, kilka serii) – mierzy rywalizację o CPU/IO lepiej niż %CPU.
   Plus `kube_pod_container_status_last_terminated_reason` (OOMKilled). S.
4. **Elasticsearch** – komponent `prometheus.exporter.elasticsearch` wbudowany w Alloy (bez nowego poda) z keep-listą:
   `elasticsearch_thread_pool_{queue,rejected,active}_count{type="search"}`, `elasticsearch_jvm_memory_used_bytes`,
   `elasticsearch_jvm_gc_collection_seconds_*`, `elasticsearch_indices_search_query_time_seconds`,
   `elasticsearch_breakers_tripped` (~30 serii). S–M. Doraźnie: `GET _cat/thread_pool/search?v`, `GET _nodes/hot_threads`.
5. **FrankenPHP** – metryki Caddy (`metrics` + osobny `handle /metrics` na wewnętrznym porcie, bo `admin off`):
   `frankenphp_busy_threads`, `frankenphp_queue_depth`, `frankenphp_total_threads` (https://frankenphp.dev/docs/metrics/). S.
6. **Postgres** – `prometheus.exporter.postgres` w Alloy z keep-listą (`pg_stat_database_blks_*`, `pg_stat_activity_count`,
   checkpointy, ~30 serii). S.
7. **RabbitMQ** – wbudowany plugin prometheus (15692), 5–10 serii kolejek. Niski priorytet.
8. **Varnish** – brak eksportera w Alloy; najtaniej doraźnie `varnishstat -1`, ewentualnie `X-Cache` w logu dostępu sklepu.
Proponowane alerty: odsetek 5xx Traefika > 1% przez 5 min, p95 sklepu > 2 s, OOMKilled, PSI CPU/IO, kolejka search ES > 0
przez 2 min.

## 7. Budżet CPU dla zimnego listingu [SZACUNEK]

Założenia: zimny listing całego katalogu ≈ 0,7 s na shard × 4 shardy ≈ **2,0–2,8 rdzenio-sekundy CPU w ES** (dane ze
zlecenia; dla 1 mln produktów). Pozostałe koszty jednego listingu: API (boot Symfony + serializacja) ~0,03–0,08 rdz-s,
offers w PG ~0,02–0,1, render SSR ~0,03–0,08 [SZACUNEK] – pomijalne wobec ES.

- CPU dostępne dla ES na węźle 8 vCPU: 8 − tło (k3s, Alloy, Argo CD, Traefik ~0,4–0,6) − PG/API/sklep przy tym ruchu
  (~1–1,5) ≈ **6 rdzeni**.
- Maksymalna przepustowość zimnych listingów (ES nasycony): 6 / 2,4 ≈ **2,5/s** (zakres 2,1–3,0/s). Przy rozsądnym
  wykorzystaniu 70% (żeby kolejka nie rosła nieliniowo): **~1,7 zimnego listingu/s ≈ 100/min**.
- 8 równoczesnych zimnych listingów = 8 × 2,4 ≈ 19 rdz-s pracy; przy 6 rdzeniach ostatni skończy się po ≥ 3,2 s, a pula
  13 wątków i 32 zadania shardów dają kolejkowanie. Średnio połowa przekracza 2 s timeoutu klienta → 503/500. **To dokładnie
  obserwowany objaw** – spójne z twierdzeniem, że przyczyną jest koszt zapytania × timeout 2 s, a nie awaria infrastruktury.
- Przy 10 mln produktów (koszt liniowy w liczbie pasujących dokumentów dla sortowania/agregacji po całym katalogu,
  [HIPOTEZA] – obszar wyszukiwarki): ~20–28 rdz-s na zimny listing całego katalogu → **0,2–0,3/s**, a pojedyncze żądanie
  bez współbieżności (28 rdz-s / 4 shardy = ~7 s) przekracza 2 s nawet w spoczynku. Żadna konfiguracja sprzętowa w
  rozsądnym budżecie tego nie uratuje – potrzebna zmiana zapytania (np. bez `track_total_hits: true`
  `ProductSearchQuery.php:36`, agregacje tylko gdy potrzebne, pre-sort indeksu) – przekazuję obszarowi wyszukiwarki.
- Dźwignie infrastrukturalne (łącznie +20–40% przepustowości, nie rzędy wielkości): request CPU ES 3–4 i limity CPU
  Keycloak/Jobów (ES dostaje te 6 rdzeni także pod presją), page cache dla indeksu (eliminuje IO przy server), mniejsza
  liczba shardów przy wysokiej współbieżności (mniej narzutu – do zmierzenia).

## 8. Podział zasobów i sprzęt dla profilu server [SZACUNEK]

### 8.1 Docelowy podział na obecnym serwerze (8 vCPU / 22,9 GiB) dla server

| Składnik | CPU request | Pamięć (limit) | Z czego cache |
|---|---|---|---|
| System, k3s, containerd, Argo CD, Traefik, monitoring | (0,6) | ~3,0 GiB | – |
| Elasticsearch | 3 | 9 GiB (heap 3 GiB) | ~4,5–5 GiB page cache |
| Postgres | 1,5 | 7 GiB (`shared_buffers` 2,5 GiB) | ~4 GiB page cache |
| API (16 wątków) | 1 | 2 GiB | – |
| Sklep | 0,5 | 1,5 GiB | ISR 128 MB |
| Keycloak (limit CPU 2) | 0,3 | 1,5 GiB (heap 768m) | – |
| Redis, RabbitMQ, Varnish (300 MB), imgproxy, Garage, workery, mailpit, admin | 1,2 | ~4 GiB | – |
| Rezerwa (Joby, reindeks, generator, skoki) | – | ~1 GiB | – |
| **Razem** | ~7,5 | ~29 GiB limitów, ~22 GiB realnego użycia | |

Wniosek: **nie mieści się**. Nawet po przeniesieniu pamięci z rezerw zostaje ~4–5 GiB cache na indeks 11 GB i ~4 GiB
cache + 2,5 GiB buforów na bazę 60–90 GB. Hot set listingu (doc values pól sortowania/filtrów dla 10 mln dokumentów,
indeksy produktów/wariantów/cen/stanów w PG) oceniam na 4–8 GB w ES i 5–10 GB w PG [SZACUNEK] – część odczytów trafi na
dysk, który już ma iowait 18%. CPU jest jeszcze ciaśniejsze (sekcja 7). **Odpowiedź: 8 vCPU / 23 GiB nie wystarcza dla
profilu server przy równoległym ruchu**; wystarcza do pokazania danych przy pojedynczych użytkownikach po naprawie
kosztu zapytania.

### 8.2 Minimalna sensowna konfiguracja dla 10 mln produktów przy umiarkowanym ruchu

Założenie „umiarkowany ruch”: ~20–50 żądań stron/s, w tym ~10–20% zimnych listingów, po zmniejszeniu kosztu zimnego
listingu do ≤ 0,5 rdz-s (cel dla obszaru wyszukiwarki) [SZACUNEK].

- **Jeden węzeł: 16 vCPU (dedykowane, nie współdzielone), 64 GiB RAM, NVMe lokalny ≥ 400 GB.** Uzasadnienie: ES heap
  4–6 GiB + 12–16 GiB cache (cały indeks + zapas na reindeks), PG `shared_buffers` 8 GiB + 16 GiB cache (indeksy i
  gorące tabele 5 mln zamówień), aplikacje ~8 GiB, system 4 GiB. CPU: ES 6–8 rdzeni → przy 0,5 rdz-s/zimny listing
  ~12–16/s; PG 3–4; aplikacje 3–4.
- Jeżeli koszt zimnego listingu zostanie na 20–28 rdz-s (10 mln), nawet 32 vCPU daje ~1 zimny listing/s – sprzęt nie jest
  rozwiązaniem.

### 8.3 Co zmienia podział na 2–3 węzły

- **ES na osobnym węźle** (np. 8 vCPU / 32 GiB): izolacja od Keycloaka, generatora i PG (koniec walki o CPU i page cache),
  własny dysk dla merge/fsync. Przepustowość zimnych listingów ~ +30–40% względem dzisiejszego (8 zamiast ~6 rdzeni),
  ale nie rzędy wielkości.
- **PG na osobnym węźle** (8 vCPU / 32 GiB): własny cache i dysk WAL; WAL generatora nie dusi ES.
- **Aplikacje** (API, sklep, Keycloak, Varnish, imgproxy) na trzecim (8 vCPU / 16 GiB): tu dopiero ma sens >1 replika
  API/sklepu (wymaga fan-out rewalidacji ISR i Varnisha).
- Koszty: local-path przypina PV do węzła (brak przeniesienia poda przy awarii węzła), więcej operacji, ruch sieciowy
  między węzłami (~0,1–0,3 ms – pomijalny). Brak redundancji – to nadal nie jest HA.
- Wniosek: przy umiarkowanym ruchu jeden większy węzeł (16/64/NVMe) jest prostszy i wystarczy; podział na węzły ma sens
  dla izolacji ES/PG od reszty, gdy sam węzeł 16 vCPU przestanie wystarczać.

## 9. Środowisko pomiarowe `make -C infra/k8s load` i `load/`

[KOD] `infra/k8s/environments/load/values.yaml`, `infra/k8s/scripts/load.sh`, `load/README.md`, `load/lib/*.js`.

Różnice wobec prod:
- k3d w VM Dockera na hoście (Mac: zwykle arm64, dysk VM to plik na APFS – inna charakterystyka fsync), minimum 10 CPU i
  20 GiB (`load.sh:81,112`) zamiast 8 vCPU → ES widzi więcej procesorów (pula search 16 zamiast 13), FrankenPHP 20 wątków.
- k6 działa **w tej samej VM** co klaster (`load.sh:586`, `docker run` w sieci k3d) – zabiera CPU mierzonemu systemowi.
- Brak Cloudflare i TLS; k6 idzie przez Traefik po HTTP. Indeks: `elasticsearch.shards` nie jest nadpisany w
  `environments/load/values.yaml` (tylko `javaOpts`) ani w `ci/values.yaml:80-81`, więc obowiązuje domyślne
  `shards: 1` z `charts/shop/values.yaml:224` [KOD], podczas gdy prod ma 4 – pomiar load mierzy inny układ indeksu niż
  produkcja (test porównujący zasoby z prod nie obejmuje `elasticsearch.shards`).
- Zasoby podów = prod (pilnowane testem) – dobrze.

Scenariusze vs problem:
- Zbiór URL-i jest mały: kategorie z drzewa bez filtrów i stron (`flows.js:21-23`), `/` bez parametrów, 60 produktów
  (`DISCOVER_PRODUCTS` 60), frazy z ich nazw, filtry z facetów 3 kategorii, strona najwyżej 2. Po `warmup.js` większość
  odczytów trafia w Varnish (TTL 60 s) i ISR – **mierzona jest ciepła ścieżka**. Długi ogon zimnych listingów (głębokie
  strony, kombinacje filtrów, różne kategorie × sortowania) nie jest generowany, a to on daje 500/503.
- Wynik `saturate.js` (próg p95 2 s / 1% 5xx) na takim miksie przeszacuje przepustowość produkcji dla realnego ruchu
  botów/klientów o szerokim rozkładzie URL-i.
- `make load-smoke` – jedna iteracja, bez progów wydajności: test poprawności ścieżek, nie wydajności. Dobrze, że jest.

Rekomendacja (S–M): dodać scenariusz „cold-tail” – unikalne URL-e listingu na iterację (losowa kategoria × sort × strona
1–50 × filtr), z `constant-arrival-rate` 0,5/1/2/4 na s i osobną metryką zimnych odpowiedzi (`X-Cache: MISS` z API przez
Varnish nie jest widoczne na stronie sklepu – np. losowy parametr, który omija cache, albo bezpośrednio
`GET /api/v1/products` przez Varnish). Tylko tak wynik będzie przenaszalny na problem z prod.

## 10. Co jest dobre i czego nie ruszać

- Brak livenessProbe opartych na aplikacji (brak restartów pod przeciążeniem; 0 restartów [POMIAR-GC]).
- Klient ES bez ponowień i z krótkim timeoutem – brak burzy ponowień (choć 2 s to dziś źródło 503 – zmieniać dopiero po
  zmniejszeniu kosztu zapytania).
- Limitery fail-open z timeoutem 1 s; blokady w PG (doradcze) – gotowe na repliki.
- Traefik `buffering` tylko na trasach JSON, `read_header`/`read_body` w Caddy – ochrona przed wolnymi klientami.
- ES: 0 replik na jednym węźle, `vm.max_map_count` w bootstrapie, polski analizator w obrazie (bez pobierania przy starcie).
- ISR w pamięci z limitem 128 MB i `ephemeral-storage` sklepu – ograniczony wzrost.
- Rollout `maxUnavailable 0`; Keycloak `Recreate` uzasadniony.
- Lista dozwolonych metryk pod darmowy plan – słuszna zasada; dokładać metryki selektywnie (keep-list), nie zdejmować listy.
- Joby wsadowe z `activeDeadlineSeconds` i straż wolnego miejsca generatora (32 GB) spójna z watermarkiem ES.

Uproszczenia: nic istotnego do usunięcia. Jedyne do rozważenia: osobne Joby cykliczne co 5 min (3 starty pełnego obrazu
PHP co 5 min) można zebrać w jeden (mniej skoków CPU/pamięci) – niski priorytet.

## 11. Lista znalezisk (waga, klasa, rodzaj, skala, koszt)

| # | Znalezisko | Waga | Dowód | Rodzaj | Wzrost z danymi / współbieżnością | Koszt |
|---|---|---|---|---|---|---|
| F1 | Zimny listing 2–2,8 rdz-s w ES → maks. ~2,5/s na serwerze; 8 równoczesnych > 2 s → 503/500 | Krytyczna | [SZACUNEK] + [KOD] timeout 2 s | architektura zapytania (nie infra) | liniowy z liczbą dokumentów; liniowy w CPU z współbieżnością, potem skokowo timeouty | – (obszar search) |
| F2 | Limity pamięci obcinają page cache ES (~1,5 GiB) i PG (~1,7 GiB) | Krytyczna (server) / Średnia (medium) | [KOD] + mechanizm cgroup | konfiguracja | liniowy z rozmiarem indeksu/bazy | S |
| F3 | Postgres na domyślnych parametrach, brak pg_stat_statements, `/dev/shm` 64 MB, brak statement_timeout | Wysoka | [KOD]; shm [HIPOTEZA] | konfiguracja | gorzej niż liniowo (spill, checkpointy, vacuum) | S |
| F4 | Varnish: 100 MB, grace 10 s, TTL 60 s, hit-for-miss 120 s po 503 (utrata coalescingu w przeciążeniu) | Wysoka | [KOD] | konfiguracja | wzmacnia przeciążenie z współbieżnością | S |
| F5 | Brak backpressure w łańcuchu (Traefik, Varnish, FrankenPHP, store fetch, PG) | Wysoka | [KOD] | konfiguracja | kaskada przy współbieżności | S |
| F6 | Udział CPU przy rywalizacji: ES request 1, Keycloak/generator bez limitu CPU (3,9 rdz.) | Wysoka | [KOD] + [POMIAR-GC] | konfiguracja | – | S |
| F7 | Readiness API przez PHP: przy kolejce > 100 s jedyny pod wypada z endpointów | Średnia | [KOD] + [HIPOTEZA] | konfiguracja | przy współbieżności | S |
| F8 | `replicas: 1` na sztywno; ISR i purge Varnisha nie obsługują >1 repliki | Średnia | [KOD] | architektura | – | S/M |
| F9 | Redis bez `maxmemory` przy limicie 512 MiB → OOMKill zamiast eksmisji | Średnia | [KOD] + [SZACUNEK] | konfiguracja | liniowy z liczbą sesji | S |
| F10 | API: 16 × 256M PHP vs limit 2 GiB | Średnia | [KOD] / [HIPOTEZA] | konfiguracja | z współbieżnością ciężkich żądań | S |
| F11 | imgproxy 16 równoległych przetworzeń 50 MP przy 1 GiB i bez limitu CPU | Średnia | [KOD] / [HIPOTEZA] | konfiguracja | z zimnymi zdjęciami | S |
| F12 | Dysk server: ~125–175 GB z 193 przy reindeksie → watermark ES, NodeDiskFull | Wysoka (server) | [SZACUNEK] | sprzęt | liniowy | – / sprzęt |
| F13 | Brak metryk i alertów wydajności (ES, PG, FrankenPHP, Traefik, PSI, OOMKill) | Wysoka (diagnostyka) | [KOD] | obserwowalność | – | S |
| F14 | Środowisko load mierzy ciepłą ścieżkę na mocniejszym CPU z k6 w tej samej VM | Wysoka (decyzje) | [KOD] | metodologia | – | S/M |
| F15 | 8 vCPU/23 GiB nie wystarcza dla server; minimum 16 vCPU/64 GiB/NVMe po naprawie zapytania | Wysoka | [SZACUNEK] | sprzęt | – | L |
| F16 | Store fetch bez timeoutu, Keycloak pula DB do 100 przy `max_connections` 100 | Średnia | [KOD] / [HIPOTEZA] | konfiguracja | z współbieżnością | S |

## 12. Pomiary do wykonania na stosie medium (najważniejsze najpierw)

1. **Koszt CPU zimnego listingu i nasycenie ES:** 1, 2, 4, 8, 16 równoczesnych unikalnych zapytań `GET
   /api/v1/products?category=<root>&page=<losowa>&sort=<losowy>` bezpośrednio do API (z pominięciem Varnisha) przy
   indeksie 4 i 1 shard; zbierać `GET _nodes/stats/thread_pool/search,indices/search` przed/po (różnica `query_time_in_millis`
   / liczba zapytań = rdz-ms na zapytanie), `_cat/thread_pool/search?v&h=active,queue,rejected` co 1 s,
   `GET _nodes/hot_threads`. Potwierdza/obala F1, liczbę z sekcji 7 i wpływ shardów.
2. **Page cache ES/PG:** `cat /sys/fs/cgroup/memory.stat` w podach ES i PG (anon vs file vs active_file) przed i po teście z
   pkt 1; `pg_stat_database` blks_hit/blks_read; `vmstat 1` na węźle (wa). Potwierdza F2.
3. **Postgres:** włączyć `pg_stat_statements` (`-c shared_preload_libraries=pg_stat_statements`), test k6 steady, top 20 po
   `total_exec_time`; `df -h /dev/shm` w podzie; `pg_stat_checkpointer` (checkpoints_req) w trakcie generatora;
   `SELECT usename, count(*) FROM pg_stat_activity GROUP BY 1` w trakcie generatora kont. F3, F16.
4. **Varnish:** `varnishstat -1 -f MAIN.cache_hit -f MAIN.cache_miss -f MAIN.cache_hitmiss -f MAIN.n_lru_nuked -f
   SMA.s0.g_bytes -f SMA.s0.g_space` przed/po teście z pkt 1 przez Varnish; po wymuszonym 503 sprawdzić
   `varnishlog -g request -q 'ReqURL ~ "/api/v1/products"'` (HitMiss). F4.
5. **Kolejka FrankenPHP:** włączyć metryki Caddy albo liczyć czas `/health` (`curl -w %{time_total}`) w trakcie 16
   równoczesnych zimnych listingów. F5, F7.
6. **Rywalizacja CPU:** test z pkt 1 równolegle z obciążeniem Keycloaka (logowania) – porównać przepustowość ES; PSI
   `cat /proc/pressure/cpu` na węźle. F6.
7. **Pamięć anon ES** pod obciążeniem agregacji (memory.stat `anon` vs 4 GiB). F2/OOM.
8. **k6 „cold-tail”** (sekcja 9) na k3d z `LOAD_MIN_CPUS=8` i k6 poza VM – porównać z obecnym `saturate.js`. F14.
9. Redis `info memory` po teście logowań; imgproxy `kubectl top` przy 16 zimnych wariantach dużych zdjęć. F9, F11.
