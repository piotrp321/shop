# PERF-1, zadanie 1: pomiar przed zmianą konfiguracji (2026-11-27)

Zadanie RUNTIME-CONFIG-1 (`docs/features/runtime-config/plan.md`). Linia bazowa `server`: raport PERF-0
[`server-profile-baseline-worf-2026-11-26.md`](server-profile-baseline-worf-2026-11-26.md) (PR #972), przyjęty przez
właściciela jako częściowa linia bazowa tego zadania. Raport go nie powtarza; uzupełnia go o dane historyczne
z Grafany i Lokiego (sekcja 1) i o pomiar lokalny na `medium` (sekcje 2–).

Oznaczenia źródła: **[server/Grafana]**, **[server/Loki]** – odczyt tylko do odczytu z Grafana Cloud, okno generowania
profilu `server` na worf.pl, rewizja `a5056dbc`, konfiguracja domyślna (Postgres 2 GiB, Elasticsearch 4 GiB, sterta
2 GiB, 4 shardy); **[medium]** – lokalny stos; **[PERF-0]** – raport #972. Wyjaśnienia, których nie sprawdzono, są
oznaczone jako hipotezy.

## 1. Dane historyczne `server` z Grafany i Lokiego

Okno: 2026-11-25 19:40 – 2026-11-26 19:40 UTC (Job generatora z limitem 24 h) i 4 h po nim (pomiary PERF-0).
Fazy generatora według raportu PERF-0, §2.

### 1.1 Dostępność metryk

| Potrzebne do PERF-1 | Jest w Grafana Cloud | Uwagi |
|---|---|---|
| RSS i cache kontenerów | tak | `container_memory_rss`, `container_memory_cache`, `container_memory_working_set_bytes` (cAdvisor) |
| iowait | tak | `node_cpu_seconds_total{mode="iowait"}` |
| IOPS i przepustowość | tylko per kontener | `container_fs_{reads,writes}{,_bytes}_total`; bez metryk urządzenia (`node_disk_*`) |
| opóźnienia dysku | **nie** | brak `node_disk_read_time_seconds_total` i pokrewnych na liście dozwolonych |
| PSI węzła | **nie** | brak `node_pressure_*` (zadanie 5, D7) |
| odsetek trafień bufora Postgresa, `pg_stat_checkpointer`, rozmiar `pg_wal` | **nie** | brak eksportera Postgresa (zadanie 5, D7) |
| checkpointy | tak, z logów | `log_checkpoints` jest domyślnie włączone w PG 18; linie `checkpoint starting/complete` w Lokim |

### 1.2 Pamięć usług [server/Grafana]

Maksimum w oknie 24 h, MiB. Limity `prod` z `environments/prod/values.yaml`.

| Kontener | RSS | cache | working set | limit |
|---|---|---|---|---|
| postgres | 218 | 1957 | 562 | 2048 |
| elasticsearch | 2906 | 1161 | 3921 | 4096 |
| keycloak | 1355 | 166 | 1365 | 2048 |
| store | 387 | 0 | 399 | 1536 |
| rabbitmq | 156 | 41 | 193 | 1024 |
| demo-data (Job) | 182 | 1261 | 195 | 4096 |
| api | 67 | 67 | 136 | 2048 |
| garage | 8 | 977 | 57 | 1024 |
| varnish | 36 | 82 | 72 | 512 |
| imgproxy | 34 | 40 | 73 | 1024 |
| worker | 39 | 1 | 43 | – |
| worker-commands | 25 | 0 | 27 | – |
| redis | 9 | 14 | 25 | 512 |
| mailpit | 12 | 8 | 21 | 256 |
| admin | 6 | 3 | 11 | – |

- **Potwierdzone:** cache plików w cgroupie Postgresa przez całe okno wynosił 1,83–1,99 GiB (próbki co 2 h), czyli
  przy limicie 2 GiB; RSS procesów Postgresa 17–218 MiB. Postgres przez 24 h pracował z ok. 2 GiB pamięci na
  `shared_buffers` (128 MB) i cache plików, przy bazie 63 GB [PERF-0].
- **Potwierdzone:** w tym samym czasie węzeł miał `MemAvailable` 13,3–14,7 GiB, `MemFree` 3,7–5,9 GiB, a cache plików
  węzła (`Cached`) 8–10 GiB. Pamięć węzła była wolna, ale cgroupa Postgresa nie mogła jej użyć.
- **Hipoteza (niesprawdzona):** większy cache Postgresa skróciłby generowanie, reindeks i weryfikację. Przy 63 GB bazy
  i losowym dostępie (§1.4) nie wiadomo, jaka część odczytów trafiłaby w cache; sprawdza to pomiar na `medium` (sekcja
  2) i decyzja D2.
- Elasticsearch: working set 2,9 GiB w generowaniu, 3,2 GiB w reindeksie, 3,7–3,9 GiB po nim i przy pomiarach PERF-0
  (RSS 2,9 GiB, czyli sterta 2 GiB i ok. 0,9 GiB poza nią). Dotyczy 4 shardów i obecnego modelu; D3 ustala PERF-2B.
- Keycloak: RSS 0,94 GiB przed importem kont i 1,36 GiB po imporcie 1 mln kont; zostaje na tym poziomie.

### 1.3 CPU, restarty [server/Grafana]

- Szczyt CPU (średnia 5 min): Keycloak 3,78 rdzenia (import kont), Elasticsearch 3,2, Postgres 2,31, generator 0,8,
  sklep 0,38, RabbitMQ 0,38, API 0,35.
- Restarty w 26 h: `worker` 1, `worker-commands` 1 (rotacja co 24 h), pozostałe 0. Bez OOM.

### 1.4 I/O w fazach [server/Grafana]

Średnie z 15 min, próbki co godzinę. iowait to średnia z 8 rdzeni. Odczyt i zapis dotyczą kontenera `postgres`.

| Faza (UTC) | iowait % | CPU węzła % | CPU Postgresa | odczyt IOPS | odczyt MB/s | zapis MB/s |
|---|---|---|---|---|---|---|
| produkty, 25.11 20:40 | 22,2 | 54 | 1,40 | 12 527 | 149 | 243 (16 147 IOPS) |
| zamówienia, 26.11 01:40–02:40 | 19,6–20,0 | 51 | 1,25 | ok. 20 800 | 173–175 | 38–45 |
| stany i modele odczytu, 03:40–04:40 | 7,0–7,4 | 23–24 | 0,45–0,54 | 5 258–5 667 | 59–67 | 92–131 |
| reindeks, 05:40–12:40 | 4,9–6,6 | 23–25 | 0,29–0,32 | 3 946–4 615 | 31–36 | 0,6–0,7 |
| weryfikacja, 13:40–19:40 | 8,3–11,3 | 22–27 | 0,21–0,40 | 4 979–10 086 | 39–132 | 0 |
| pomiary PERF-0, 20:40–21:40 | do 5,5 | do 56 | 0,0–0,1 | do 961 | do 8 | 0 |

- **Potwierdzone:** maksimum iowait 23,0% (średnia 5 min); szczyt odczytu Postgresa 21 832 IOPS, Elasticsearcha 4 092.
- **Potwierdzone:** w reindeksie i weryfikacji odczyt to średnio ok. 8 KB na operację (MB/s ÷ IOPS), czyli pojedyncze
  strony Postgresa, przy 0,2–0,4 rdzenia CPU Postgresa. W reindeksie Elasticsearch zużywał 0,13–0,22 rdzenia
  i zapisywał 1,6–2,9 MB/s, więc w tej fazie nie on ogranicza tempo.
- **Hipoteza:** w obu fazach jedno połączenie czeka na kolejne losowe odczyty stron (praca ograniczona opóźnieniem dysku,
  nie jego przepustowością: w fazie zamówień ten sam dysk dał ok. 21 tys. IOPS). Opóźnień dysku nie ma w metrykach.

### 1.5 Checkpointy [server/Loki]

Linie `checkpoint starting: wal` (wymuszone rozmiarem WAL) w oknach 2 h, liczone `count_over_time`:

| Okno 2 h kończące się o (UTC) | 25.11 20:00 | 22:00 | 26.11 00:00 | 02:00 | 04:00 | 06:00 | 08:00–20:00 |
|---|---|---|---|---|---|---|---|
| wymuszone WAL | 270 | 858 | 667 | 102 | 469 | 449 | 0 |

- **Potwierdzone:** ok. 2 815 checkpointów wymuszonych rozmiarem WAL w ok. 10 h generowania, w szczycie co ok. 8 s;
  prawie każdy z ostrzeżeniem `checkpoints are occurring too frequently` (1 665 w pierwszych 5 000 liniach). Odległość
  między checkpointami do 585 MB (przy `max_wal_size` 1 GB i domyślnym `checkpoint_completion_target` 0,9), faza zapisu
  checkpointu do 270 s. W fazie zapisu checkpointu było 0 nowych plików WAL, do 7 usuniętych i do 33 odzyskanych.
  Checkpointy czasowe: 12 na godzinę przy bezczynności.
- **Nie zmierzono:** szczytu `pg_wal` (brak metryki). Szacunek z liczby odzyskanych segmentów (33 × 16 MB) to ok.
  0,5–1 GB; dokładny szczyt mierzy sekcja 2 lokalnie (`pg_ls_waldir()`).
- **Hipoteza:** częste checkpointy zwiększają zapis (pełne obrazy stron po każdym checkpoincie) i czas generowania;
  wpływu na czas nie zmierzono.

### 1.6 Dysk [server/Grafana]

Użyte miejsce na `/` (rozmiar 192,9 GiB = 207 GB): 34,2 GB przed generowaniem, 103,0 GB po reindeksie (13:40), szczyt
103,1 GB, czyli 50% dysku. Specyfikacja podaje „193 GB”: to te same 192,9 GiB.

### 1.7 Czas weryfikacji danych

Limit 24 h dotyczył całego Joba (start 19:40:21). `demo-data:verify` zaczął się po reindeksie, ok. 12:50 UTC, więc do
`DeadlineExceeded` o 19:40:21 miał ok. **6,8 h**, nie 24 h. W tym czasie Postgres czytał 5–10 tys. IOPS po ok. 8 KB przy
0,2–0,4 rdzenia CPU (§1.4), bez zapisów. Diagnoza zapytań weryfikatora: sekcja 3.

### 1.8 Zapytania (powtarzalność)

W Grafana Cloud, w Explore: źródło `grafanacloud-prom` (Prometheus) i `grafanacloud-logs` (Loki). Okno jak wyżej.

```text
max by (container) (max_over_time(container_memory_rss{namespace="shop",container!="",container!="POD"}[24h]))   # też _cache, _working_set_bytes
max by (container) (max_over_time(rate(container_cpu_usage_seconds_total{namespace="shop",container!="",container!="POD"}[5m])[26h:5m]))
max by (container) (max_over_time(rate(container_fs_reads_total{namespace="shop",container!="",container!="POD"}[5m])[26h:5m]))
sum by (container) (increase(kube_pod_container_status_restarts_total{namespace="shop"}[26h]))
avg(rate(node_cpu_seconds_total{mode="iowait"}[15m]))*100
min_over_time(node_memory_MemAvailable_bytes[15m]); max(node_memory_Cached_bytes); max(node_memory_MemFree_bytes)
sum(rate(container_fs_reads_total{namespace="shop",container="postgres"}[15m]))          # też _reads_bytes_, _writes_
max(node_filesystem_size_bytes{mountpoint="/"} - node_filesystem_avail_bytes{mountpoint="/"})
sum(count_over_time({namespace="shop", container="postgres"} |= "checkpoint starting: wal" [2h]))
max(max_over_time({namespace="shop", container="postgres"} |= "checkpoint complete" | regexp "distance=(?P<d>[0-9]+) kB" | unwrap d [2h]))
```
