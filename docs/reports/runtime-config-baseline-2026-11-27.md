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

## 2. Generowanie `medium` na lokalnym stosie [medium]

Stos `perf1`: [`perf1.override.yaml`](runtime-config-baseline-2026-11-27/perf1.override.yaml) (obraz `prod` API
i workerów, `STORE_MODE=production`, limity pamięci i sterty z `environments/prod/values.yaml`, Postgres z domyślną
konfiguracją, czyli 2 GiB i `shared_buffers` 128 MB, oraz `pg_stat_statements` i `track_io_timing`; API ma 4 GiB jak
Job `demo-data` w `prod`). Rewizja `main` 98ac711b; późniejszy #977 zmienia tylko ostrzeżenie `catalog:setup` o definicji indeksu, nie generator, weryfikator ani Postgresa. Host: Mac, Docker
Desktop, VM z dyskiem na SSD hosta; czasy nie przenoszą się 1:1 na `server`, porównywalne są proporcje faz i pamięć.
Próbki: [`sample.sh`](runtime-config-baseline-2026-11-27/sample.sh) co 10 s.

```sh
make up STORE_MODE=production           # z COMPOSE_FILE i PERF1_API_MEMORY=4g w lokalnym .env
make seed-scale PROFILE=medium PROCESSES="4 --force-reset"
```

### 2.1 Fazy (UTC, 2026-11-27)

| Faza | Od – do | Czas |
|---|---|---|
| produkty i warianty (1 mln / 3 mln) | 13:28:40 – 13:31:29 | 3 min |
| zdjęcia, ceny i historia cen, konta (100 tys.) | 13:31:29 – 13:40:23 | 9 min |
| zamówienia (1 mln, 1,9 mln linii) | 13:40:23 – 13:58:03 | 18 min |
| stany, modele odczytu, `ANALYZE` | 13:58:03 – 14:00:53 | 3 min |
| reindeks (1 000 191 dokumentów, ok. 1 780/s) | 14:00:53 – 14:10:16 | 9,4 min |
| `demo-data:verify` | 14:10:16 – 14:27:10 | **16,9 min** |
| razem | | 58,5 min, kod wyjścia 0 |

- **Potwierdzone:** weryfikacja jest najdłuższą fazą po zamówieniach i trwa dłużej niż reindeks (16,9 wobec 9,4 min).
  Na `server` (10× więcej produktów i 50× więcej zamówień) przerwał ją limit Joba po ok. 6,8 h (§1.7).

### 2.2 Pamięć nieodzyskiwalna w generowaniu

Maksimum z próbek, MiB: nieodzyskiwalna = `anon + shmem + slab_unreclaimable + kernel_stack + pagetables + sock`
(reguła Z1 specyfikacji), obok `anon`, `shmem` i cache plików (`file`).

| Kontener | nieodzyskiwalna | anon | shmem | file | limit |
|---|---|---|---|---|---|
| elasticsearch | 2847 | 2834 | 0 | 1103 | 4096 |
| keycloak | 1161 | 1156 | 0 | 29 | 2048 |
| rabbitmq | 395 | 369 | 21 | 32 | 1024 |
| api (generator) | 304 | 288 | 13 | 2443 | 4096 |
| postgres | 242 | 93 | 144 | 1983 | 2048 |
| store | 88 | 84 | 0 | 40 | 1536 |
| worker | 44 | 43 | 0 | 0 | 640 |
| imgproxy | 35 | 33 | 0 | 39 | 1024 |
| worker-commands | 26 | 25 | 0 | 1 | 384 |
| redis | 21 | 20 | 0 | 8 | 512 |
| varnish | 19 | 13 | 0 | 52 | 512 |
| mailpit | 17 | 16 | 0 | 5 | 256 |

- **Potwierdzone:** jak na `server`, cache plików Postgresa dochodzi do limitu (1983 z 2048 MiB), a jego pamięć
  nieodzyskiwalna to 242 MiB (w tym 144 MiB `shmem`: `shared_buffers` i pamięć współdzielona).
- Elasticsearch: 2,8 GiB nieodzyskiwalnej przy stercie 2 GiB (tu 1 shard i 1 mln dokumentów; D3 ustala PERF-2B).
- Cache plików API (2,4 GiB) to pliki tymczasowe generatora; po zakończeniu Joba znika.

### 2.3 Postgres w generowaniu

| Miara | Wartość |
|---|---|
| szczyt `pg_wal` (`pg_ls_waldir()`) | 1056 MiB |
| checkpointy: wymuszone / czasowe (`pg_stat_checkpointer`) | 81 / 8 w 58 min |
| odsetek trafień bufora `shop` (`pg_stat_database`) | 84,27% |
| pliki tymczasowe `shop` | 483 pliki, 1,25 GB |
| szczyt połączeń klienta | `shop` 6, `keycloak` 6, `postgres` 1 |
| rozmiar bazy `shop` | 10 097 MB |

- **Potwierdzone:** szczyt `pg_wal` ok. 1 GiB, czyli przy `max_wal_size` 1 GB; checkpointy są w większości wymuszone
  rozmiarem WAL (81 z 89), jak na `server` (§1.5).
- Szczyt połączeń przy 4 procesach generatora to 6 na `shop`; budżet połączeń (sekcja 5) liczy szczyt z obciążenia
  w sekcji 4.

## 3. Weryfikator danych (`demo-data:verify`) [medium]

Sam weryfikator, bez reindeksu i bez innego ruchu na stosie, na danych z sekcji 2:
[`verify-run.sh`](runtime-config-baseline-2026-11-27/verify-run.sh) (`--no-accounts`, Keycloak nie jest mierzony).
Przebieg zimny: Postgres utworzony od nowa z konfiguracją z `.env` i opróżniony cache stron maszyny wirtualnej Dockera;
ciepły: zaraz po zimnym, bez restartu. Na hoście w czasie bloków stały tylko bezczynne stosy innych sesji (najwyżej ok.
1 rdzeń z 16, zapis obciążenia co 15 s obok wyników). Konfiguracje Postgresa przez zmienne `PERF1_PG_*` nadpisania:

| Konfiguracja | limit pamięci | `shared_buffers` |
|---|---|---|
| A (`prod` dziś) | 2 GiB | 128 MB |
| B1 | 6 GiB | 128 MB |
| B2 | 6 GiB | 1536 MB |

```sh
# w .env: PERF1_AUTO_EXPLAIN_MS=10000 (plany zapytań dłuższych niż 10 s w logu), PERF1_PG_MEMORY, PERF1_PG_SHARED_BUFFERS
sh docs/reports/runtime-config-baseline-2026-11-27/verify-run.sh <katalog> cold
sh docs/reports/runtime-config-baseline-2026-11-27/verify-run.sh <katalog> warm
```

### 3.1 Wyniki

| Przebieg | czas | bloki z dysku albo cache systemu (`blks_read`) | trafienia bufora | czas odczytu | ponowne wczytania stron cgroupy (`workingset_refault_file`) |
|---|---|---|---|---|---|
| A zimny | 280 s | 22,17 mln | 71,8% | 194 s | 8,9 mln |
| A ciepły | 536 s, powtórzony 515 s | 22,17 mln | 71,8% | 410 s | 25–28 mln |
| B1 zimny | 80 s | 22,17 mln | 72,1% | 34 s | 0,26 mln |
| B1 ciepły | 74 s | 22,17 mln | 71,8% | 28 s | 1,1 mln |
| B2 zimny | 63 s | 4,05 mln | 94,9% | 14 s | 0,19 mln |
| B2 ciepły | 62 s | 4,11 mln | 94,8% | 13 s | 1,7 mln |

Wszystkie przebiegi kończą się „the generated data is consistent”. W generowaniu (sekcja 2) ten sam weryfikator przy A
trwał 16,9 min. **Hipoteza:** wtedy host był współdzielony z testami innych sesji (uzgodnione, bo generowania nie
mierzyliśmy czasem); obciążenia hosta w tym czasie nie zapisano.

- **Potwierdzone:** cztery zapytania dają 87% (zimny) i 96% (ciepły) czasu weryfikatora przy A (pełne plany: [`verifier-plans-a-cold.txt`](runtime-config-baseline-2026-11-27/verifier-plans-a-cold.txt), wyniki przebiegów: [`verifier-runs.tsv`](runtime-config-baseline-2026-11-27/verifier-runs.tsv)):

  | Zapytanie (plik) | co sprawdza | A zimny | bloki odczytane | pętle |
  |---|---|---|---|---|
  | `PricingVerifier.php:106` | bieżąca cena wobec ostatniego wpisu historii (co trzeci wariant) | 142 s | 7,5 mln | 3,0 mln sond indeksu cen, 1,0 mln historii |
  | `PricingVerifier.php:77` | warianty bez historii cen | 66 s | 5,2 mln | 1,0 mln wariantów, 3,0 mln historii |
  | `OrdersVerifier.php:62` | stan wobec ostatniego ruchu magazynowego | 22 s | 4,5 mln | 3,0 mln sond ruchów |
  | `CatalogVerifier.php:56` | opublikowane produkty bez wariantów | 14 s | 1,6 mln | 1,0 mln sond wariantów |

  Każde ma `ORDER BY … LIMIT 11` (`Listing::SHOWN + 1`), a planista wybiera pętlę zagnieżdżoną: przechodzi całą tabelę
  produktów indeksem `slug` (porządek `ORDER BY`, więc losowy dostęp do sterty) i dla każdego wiersza wykonuje osobne
  wyszukiwanie w indeksie drugiej tabeli. Plan jest tani, gdy naruszeń jest dużo (koniec po 11 wierszach), a przy
  poprawnych danych wynik ma 0 wierszy, więc pętla przechodzi wszystkie wiersze: 22 mln odczytów bloków na bazę 10 GB.
- **Potwierdzone:** te same wyniki (0 wierszy) innym planem, w konfiguracji A po zimnym starcie, z `enable_nestloop = off`
  (hash anti join, każda tabela czytana raz sekwencyjnie; [`verifier-plans-alternative.txt`](runtime-config-baseline-2026-11-27/verifier-plans-alternative.txt)): produkty bez wariantów 0,57 s zamiast 14 s (ciepły 80 s),
  warianty bez historii 0,74 s zamiast 66 s (ciepły 152 s). Dla dwóch zapytań „ostatni wpis” (`LATERAL … LIMIT 1`)
  zapis z `DISTINCT ON` nie pomógł (142 s i 126 s): potrzebny jest inny zapis, którego tu nie szukano.
- **Potwierdzone:** przy A różnica między przebiegiem zimnym i ciepłym bierze się z cache plików cgroupy Postgresa
  (limit 2 GiB przy bazie 10 GB): liczba odczytów bloków jest ta sama, a ponownych wczytań stron w przebiegu ciepłym jest
  3× więcej. **Hipoteza:** stan listy LRU sprzed przebiegu decyduje, ile odczytów trafia w cache; nie sprawdzano.
- **Potwierdzone:** większy limit Postgresa (B1) skraca weryfikator 3,5–7× przy tej samej liczbie odczytów bloków: odczyty
  trafiają w cache plików cgroupy (1,3–1,5 µs na blok wobec 9–18 µs przy A). `shared_buffers` 1536 MB (B2) zmniejsza odczyty
  o 82% i czas o dalsze ok. 20%.
- **Ograniczenie:** opróżnienie cache dotyczy maszyny wirtualnej Dockera, nie systemu hosta pod nią (macOS, bez `purge`),
  więc „zimny” odczyt to odczyt z cache hosta albo z SSD; czasy odczytu bloku (6–18 µs) są krótsze niż na dysku `server`.

### 3.2 Przyczyna niezakończenia weryfikacji na `server`

- **Potwierdzone (sekcja 1):** na `server` weryfikator miał ok. 6,8 h, bo limit 24 h dotyczył całego Joba; w tym czasie
  Postgres czytał pojedyncze strony (5–10 tys. IOPS po 8 KB) przy 0,2–0,4 rdzenia, bez zapisów.
- **Potwierdzone (sekcja 3.1):** ten sam wzorzec na `medium`: zapytania z pętlą zagnieżdżoną po całej tabeli czytają 22 mln
  bloków dla wyniku z 0 wierszy, a wynik z tymi samymi zapytaniami innym planem czyta każdą tabelę raz.
- **Hipoteza (szacunek, niesprawdzony na `server`):** `server` ma 10× więcej produktów i wariantów, więc te pętle wykonują ok.
  10× więcej sond, czyli ok. 200 mln losowych odczytów stron; przy 5–10 tys. IOPS to 5,5–11 h. To zgadza się z brakiem
  końca w 6,8 h, ale nie zostało zmierzone. Większa pamięć Postgresa skraca czas na `medium`, bo baza 10 GB mieści się
  prawie cała w 6 GiB cache; przy bazie 63 GB na `server` taki efekt jest niepewny. Główną przyczyną jest konstrukcja
  zapytań weryfikatora; pamięć Postgresa jest czynnikiem drugim. Osobne zgłoszenie z tymi dowodami: #980 (sekcja 7).

## 4. Wszystkie usługi naraz [medium]

Konfiguracja `prod` (Postgres 2 GiB i 128 MB, API 2 GiB), 2026-11-27 19:15–19:33 UTC. Mieszanka adresów PERF-0
(`load/baseline.py urls`, stałe ziarno, zapisana lista), tylko GET, więc dane się nie zmieniają; równolegle
`catalog:reindex` (1 000 191 dokumentów). Próbki `sample.sh` co 5 s. Kolejno:

1. W3: strony produktów (300 adresów sklepu i 300 API) w trybie `single`, sam ten ruch.
2. Reindeks bez innego ruchu (pierwsza próba fazy 3: mieszanka nie wystartowała, bo tryb otwarty potrzebuje
   niewykorzystanych adresów na cały przebieg, a lista miała 900 z 1080; reindeks przeszedł sam, wynik zostawiony jako
   pomiar reindeksu bez ruchu).
3. Reindeks i mieszanka (1600 adresów, tempo otwarte 2 żądania/s, 540 s, 1080 próbek, 0 błędów) naraz.
4. 16 równoległych porcji mapy strony (`/api/v1/sitemap/products?chunk=…`, najcięższe żądanie API bez logowania),
   pamięć API co 1 s.
5. 16 równoległych zimnych przetworzeń imgproxy (16 różnych oryginałów, największy rozmiar `webp` strony produktu),
   pamięć imgproxy co 1 s.
6. `varnishstat`, `INFO` Redisa, `pg_test_timing`.

Cały przebieg: [`concurrent-run.sh`](runtime-config-baseline-2026-11-27/concurrent-run.sh) (lista adresów 1600, jak w powtórzonej fazie 3); plany alternatywne z sekcji 3: [`verifier-alternative-plans.sql`](runtime-config-baseline-2026-11-27/verifier-alternative-plans.sql).

```sh
python3 load/baseline.py urls http://localhost:<API_PORT> http://localhost:<STORE_PORT> urls.json --listings 400 --products 400 --seed 20261128
sh docs/reports/runtime-config-baseline-2026-11-27/sample.sh samples.tsv 5 &
docker compose exec -T api bin/console catalog:reindex &
python3 load/baseline.py run urls.json mix.jsonl --mode open --rate 2 --duration 540
```

### 4.1 Pamięć nieodzyskiwalna i chwilowe alokacje

Maksimum z próbek, MiB. „Nieodzyskiwalna” według Z1; `anon` to `container_memory_rss` cAdvisora, różnica to `shmem`
i pamięć jądra, których cAdvisor w `rss` nie pokazuje. Kolumna `server`: RSS z §1.2 (Grafana, 24 h generowania
i pomiarów PERF-0).

| Usługa | generowanie | faza 3 (naraz) | chwilowe (faza 4–5) | nieodzyskiwalna − `anon` | `server` RSS |
|---|---|---|---|---|---|
| elasticsearch | 2847 | 2832 | – | 13–14 | 2906 |
| keycloak | 1161 | 1129 | – | 6 | 1355 |
| store | 88 | 407 | – | 4–8 | 387 |
| rabbitmq | 395 | 300 | – | 15–26 | 156 |
| api | 304 (generator w kontenerze) | 316 (z reindeksem w kontenerze) | 242 `anon` przy 16 porcjach mapy strony (memory.peak 444 z cache) | 16–31 | 67 |
| postgres | 242 | 170 | – | 150–152 (`shmem` 144) | 218 |
| imgproxy | 35 | 36 | 248 `anon` przy 16 zimnych przetworzeniach | 2 | 34 |
| worker | 44 | 40 | – | 1–2 | 39 |
| worker-commands | 26 | 31 | – | 1 | 25 |
| varnish | 19 | 27 | – | 6 | 36 |
| redis | 21 | 15 | – | 1–2 | 9 |
| mailpit | 17 | 16 | – | 1 | 12 |

- **Potwierdzone:** różnica metod jest istotna tylko dla Postgresa: cAdvisor (`rss`) nie pokazuje `shmem`
  (`shared_buffers` i pamięć współdzielona, tu 144 MiB przy 128 MB buforów), czyli całej części, która rośnie z D2.
  Na `server` pamięć nieodzyskiwalną Postgresa trzeba liczyć jako `rss` + `shared_buffers` + narzut jądra (ok. 8 MiB).
- **Potwierdzone:** w kontenerze API lokalnie biegną też generator i reindeks (`docker compose exec`), w `prod` to Joby;
  sama ścieżka żądań API to 242 MiB `anon` przy 16 najcięższych żądaniach. Eksportu panelu i przesłania zdjęcia nie
  mierzono (wymagają logowania do panelu): mierzy je kryterium zadania 4.
- imgproxy: 16 zimnych przetworzeń przy domyślnych wątkach (VM ma 16 CPU) daje 248 MiB `anon`, 0,12–0,21 s na obraz.
  Oryginały puli demo, nie źródła 50 MP; `IMGPROXY_WORKERS` 4 (kandydat) ogranicza równoległość.
- Monitoringu (Alloy) nie ma w compose; jego budżet zostaje z §1 specyfikacji.

### 4.2 Postgres przy jednoczesnym obciążeniu

| Miara | W3 (produkty) | reindeks bez ruchu | reindeks i mieszanka |
|---|---|---|---|
| odsetek trafień bufora `shop` | 99,68% | 81,43% | 81,87% |
| bloki odczytane | 4 882 | 9,30 mln | 9,39 mln |
| najdłuższe zapytanie ścieżek sklepu (`max_exec_time`) | 4 ms | – | 1 ms |
| `temp_blks_written` (wszystkie zapytania) | 0 | 0 | 0 |
| czas JIT (wszystkie zapytania) | 0 | 0 | 0 |
| checkpointy wymuszone / czasowe | 0 / 0 | 0 / 1 | 0 / 2 |
| szczyt połączeń: `shop` / `keycloak` | 3 / 2 | – | 5 / 2 |

- **Potwierdzone:** reindeks czyta losowo indeksy główne cen i stanów (`pricing_variant_price_pkey`,
  `inventory_stock_item_pkey`: po 2,2 mln odczytów bloków, 74,7% trafień): 22 tys. zapytań `variant_id = ANY(…)`
  dają 59% czasu Postgresa w reindeksie. To ten sam wzorzec co na `server` (pojedyncze strony przy 0,2–0,3 rdzenia, §1.4).
- **Ograniczenie:** W3 biegło po planach alternatywnych z sekcji 3, które przeczytały tabele katalogu sekwencyjnie, więc
  99,68% to górna granica; odsetek trafień przy W3 na `server` pokaże dopiero eksporter Postgresa (zadanie 5).
- Mieszanka nie ma zapisów (W5, W6: koszyk i zamówienie), bo zmieniłyby dane zachowane do pomiarów „po”; zapisy mierzy
  pomiar akceptacyjny epiku.
- Połączenia: szczyt `shop` 6 przy 4 procesach generatora, 5 przy reindeksie z ruchem; `keycloak` 6 przy imporcie
  100 tys. kont. Logowań klientów nie mierzono (mieszanka PERF-0 jest bez logowania).

### 4.3 Redis, Varnish, zegar

- Redis: `used_memory` 1,5 MB, RSS 14 MB, `mem_fragmentation_ratio` 9,7 (mała baza), `rdb_last_cow_size` 0, 0 kluczy
  (bez logowań i zapisów nie powstają sesje ani liczniki limiterów). `MEMORY USAGE` sesji i kluczy limiterów nie
  zmierzono: wymaga logowań i zapisów, mierzy je test zadania 4 z małym `maxmemory`. Na `server` RSS Redisa 9 MiB.
- Varnish (`malloc` 100 MB): 600 obiektów, `SMA.s0.g_bytes` 0,9 MB, `MAIN.n_lru_nuked` 0, Transient 0 B na końcu
  (15,7 MB przydzielone i zwolnione). Mieszanka 1600 adresów nie zbliża się do 100 MB; na `server` RSS 36 MiB.
- `pg_test_timing`: 15,4 ns na pomiar, 98,5% poniżej 1 µs, więc narzut `track_io_timing` jest pomijalny (lokalnie).

## 5. Propozycje D1–D6 (wartości do decyzji)

Reguła Z1 (D1, wariant A): request = szczyt nieodzyskiwalnej z §4.1 i §1.2 (większy z `medium` i `server`) × 1,25, nie
mniej niż konfiguracja; limit = request + chwilowe; cache tylko w limitach Postgresa i Elasticsearcha. Budżet węzła:
22,9 GiB − system 2,8 − rezerwa 1 − monitoring 1,4 = **17,7 GiB na pody sklepu** (system i monitoring ze
specyfikacji, nie mierzone tu).

### 5.1 Usługi poza Postgresem i Elasticsearchem

| Usługa | szczyt (źródło) | request | limit | dziś (limit) | uwagi |
|---|---|---|---|---|---|
| keycloak | 1355 MiB (`server`, po imporcie 1 mln kont) | 1,75 GiB | 1,75 GiB | 2 GiB | sterta do 1 GiB; chwilowych przy logowaniach nie mierzono |
| store | 407 MiB (`medium`, naraz) | 512 MiB | 1 GiB | 1,5 GiB | szczyt z obu źródeł ≤ 410 MiB |
| api | 242 MiB przy 16 najcięższych (bez panelu) | 384 MiB | 2 GiB (bez zmiany) | 2 GiB | limit po pomiarze eksportu i przesłania zdjęcia (zadanie 4); `memory_limit` 256M × 16 = 4 GiB > limit (Z3 b) |
| rabbitmq | 395 MiB (`medium`, generowanie) | 512 MiB | 768 MiB | 1 GiB | |
| demo-data (Job) | 304 MiB (`medium`), 182 (`server`) | 384 MiB | 1 GiB | 4 GiB | 2,4 GiB cache to pliki tymczasowe, odzyskiwalne |
| imgproxy | 36 MiB naraz, 248 MiB przy 16 zimnych | 64 MiB | 512 MiB | 1 GiB | z `IMGPROXY_WORKERS` 4 |
| garage | 8 MiB RSS (`server`) | 64 MiB | 512 MiB | 1 GiB | cache 0,95 GiB odzyskiwalny |
| worker | 44 MiB | 64 MiB | 640 MiB (bez zmiany) | 640 MiB | chwilowych nie mierzono |
| worker-commands | 31 MiB | 48 MiB | 384 MiB (bez zmiany) | 384 MiB | |
| redis | 21 MiB | 64 MiB | 512 MiB (bez zmiany) | 512 MiB | `maxmemory` 128 MB do testu zadania 4; limit ≥ 2 × 128 MB + narzut (D5) |
| varnish | 27 MiB (`medium`), 36 RSS (`server`) | 64 MiB | 512 MiB (bez zmiany) | 512 MiB | `VARNISH_SIZE` 256M (kandydat zostaje, szczyt `g_bytes` 0,9 MB) |
| mailpit, admin | 17 i 11 MiB | 32 i 16 MiB | 256 i 128 MiB (bez zmiany) | | |
| **razem** | | **ok. 3,9 GiB** | **ok. 9,9 GiB** | | |

Na Postgres i Elasticsearch zostaje więc 17,7 − 9,9 = **ok. 7,8 GiB** limitów (dziś 6 GiB).

### 5.2 Postgres i Elasticsearch (D2, D3)

- **Potwierdzone [medium]:** limit 6 GiB zamiast 2 GiB skraca weryfikator 3,5–7× przy bazie 10 GB, a `shared_buffers`
  1536 MB zamiast 128 MB obcina odczyty bloków o 82% (§3.1); reindeks przy 2 GiB ma 81% trafień i czyta losowo indeksy
  cen i stanów (§4.2).
- **Hipoteza [server]:** baza 63 GB i ok. 9,5 GB gorących indeksów sklepu (+2,1 GB indeksów PERF-7, szacunek z
  `medium`) nie zmieszczą się w żadnym podziale 7,8 GiB; skutek większego cache na `server` jest niepewny i mierzy go
  W8/W3 po wdrożeniu (D4, kontrola iowait ≤ 5%).
- Elasticsearch: model R ma ok. 4,5 GB gorących danych (szacunek etapu A), dziś limit 4 GiB; stertę i limit ustala
  PERF-2B (D3), więc zadanie 4 nie jest zamknięte bez niego.
- **Propozycja startowa D2** (do przeliczenia po PERF-2B): Elasticsearch 4 GiB bez zmiany, Postgres 3,75 GiB:
  `shared_buffers` 1 GiB (ok. 25%), `effective_cache_size` 3 GiB, request ok. 2,6 GiB według reguły „nie mniej niż
  konfiguracja”: bufory 1 GiB + procesy 0,25 GiB + autovacuum 3 × 128 MB + `maintenance_work_mem` × 4 procesy
  generatora (przy 256 MB: 1 GiB; przy kandydacie 512 MB: 2 GiB, czyli request 3,6 GiB i prawie bez cache). Jeśli PERF-2B pokaże, że Elasticsearch potrzebuje 5 GiB, Postgres
  zostaje przy ok. 2,75 GiB albo trzeba zmniejszyć inne limity (Keycloak, sklep) albo przejść na wariant B.

### 5.3 Ustawienia Postgresa

| Ustawienie | kandydat w spec | wynik zadania 1 | propozycja |
|---|---|---|---|
| `shared_buffers` | 25% limitu | −82% odczytów przy 1536 MB na `medium` (§3.1) | 25% limitu z D2 (propozycja 1 GiB) |
| `effective_cache_size` | bufory + cache w limicie | – | limit − procesy (propozycja 3 GiB) |
| `max_wal_size` | 8 GB | szczyt `pg_wal` 1056 MiB przy 1 GB; 81 z 89 checkpointów wymuszonych na `medium`, ok. 2 815 w 10 h na `server` | 8 GB; sprawdzenie na `server` (`num_requested` / `num_timed`, szczyt `pg_wal`) po wdrożeniu |
| `checkpoint_timeout` | 15 min | 12 checkpointów czasowych na godzinę przy bezczynności (`server`) | 15 min |
| `work_mem` | bez zmiany | `temp_blks_written` 0 w ścieżkach sklepu | 4 MB bez zmiany |
| `maintenance_work_mem` | 512 MB | nie mierzono budowy indeksów | 256 MB (× 4 procesy = 1 GiB w requeście zamiast 2 GiB) i `autovacuum_work_mem` 128 MB (3 procesy autovacuum); wartość z konfiguracji, bez pomiaru |
| `jit` | decyzja z `jit_*_time` | 0 ms w ścieżkach sklepu; 7–30 ms w pojedynczych zapytaniach weryfikatora | domyślne (on) |
| `statement_timeout` API | 5 s | `max_exec_time` ścieżek sklepu na `medium` ≤ 4 ms, więc reguła daje 3 s | 3 s według reguły; wartość z `server` po włączeniu `pg_stat_statements` (zadanie 2) |
| `track_io_timing` | on | `pg_test_timing` 15,4 ns | on |

### 5.4 Połączenia, CPU, dysk

- Połączenia: zmierzone szczyty (`shop` 6, `keycloak` 6, superużytkownik 1) mieszczą się w budżecie specyfikacji
  (ok. 50 ze 100); `KC_DB_POOL_MAX_SIZE` 20 zostaje jako górna granica (zmierzone 6 przy imporcie kont).
- CPU (Z2, wagi): szczyty z §1.3. Propozycja requestów: Elasticsearch 2,4, Postgres 1,25, API 1, sklep 0,5, Job
  demo-data 0,25, Keycloak 0,25, imgproxy 0,25, oba workery po 0,1, RabbitMQ, Varnish, Garage po 0,1, Redis, Mailpit,
  admin po 0,05; razem 6,55 + monitoring 0,36 = 6,91 z 7 vCPU.
- Dysk (D6): szczyt `server` 103,1 z 207 GB (50%) przy `max_wal_size` 1 GB; 8 GB dodaje do ok. 7 GB `pg_wal`, czyli
  ok. 53%, poniżej 80%, więc D6 nie jest potrzebne (hipoteza: szczyt `pg_wal` może przekroczyć `max_wal_size` przy
  ciężkim zapisie; mierzy to kolejne generowanie `server`).

## 6. Wartości „kandydat” specyfikacji

| Wartość | stan |
|---|---|
| `max_wal_size` 8 GB, `checkpoint_timeout` 15 min | pomiar `medium` i dane `server` w §5.3; potwierdzenie na `server` po wdrożeniu |
| `shared_buffers` 25% | pomiar w §3.1; wartość czeka na D2 (PERF-2B) |
| `maintenance_work_mem` 512 MB | bez pomiaru budowy indeksu (budowa indeksów to PERF-7 i PERF-8); propozycja 256 MB z budżetu requestu (§5.3) |
| `KC_DB_POOL_MAX_SIZE` 20 | szczyt 6 przy imporcie kont (§4.2); logowań nie mierzono |
| `statement_timeout` API 5 s | reguła daje 3 s z `medium` (§5.3); wartość z `server` czeka na `pg_stat_statements` |
| `statement_timeout` workerów 60 s | najdłuższe zapytanie reindeksu 100 ms (§4.2); bez zmiany |
| sterta Elasticsearcha 2–3 GiB, request CPU 2,5 | czeka na PERF-2B (D3) |
| rezerwa węzła 1 GiB | bez pomiaru; system 2,8 GiB ze specyfikacji, PSI węzła brak (#976) |
| `VARNISH_SIZE` 256M | szczyt `g_bytes` 0,9 MB na `medium` (§4.3); na `server` więcej obiektów, wartość zostaje |
| `IMGPROXY_WORKERS` 4 | 248 MiB przy 16 równoległych z wątkami domyślnymi (§4.1) |
| Redis `maxmemory` | `MEMORY USAGE` kluczy czeka na test zadania 4 (wymaga logowań i zapisów) |
| imgproxy jako kandydat limitu CPU | nie mierzono wpływu na ścieżkę żądania; czeka na W14 na `server` |

## 7. Zgłoszenie: zapytania weryfikatora

Konstrukcja zapytań weryfikatora jest poza PERF-1. Zgłoszenie z dowodami z sekcji 3: #980.

## 8. Wariant startowy: Postgres 3,75 GiB, `shared_buffers` 1 GiB [medium]

Blok C (2026-11-27, 23:01–23:15 UTC), na życzenie właściciela po przyjęciu ustawień z sekcji 5.3 jako konfiguracji
startowej. Wynik dla 6 GiB z sekcji 3 nie potwierdza konfiguracji docelowej.

Konfiguracja i procedura:

- **Postgres:** limit 3840 MiB (`memory.max` 4026531840). Ustawienia: `shared_buffers` 1 GB, `effective_cache_size`
  3 GB, `maintenance_work_mem` 256 MB, `autovacuum_work_mem` 128 MB, `max_wal_size` 8 GB, `checkpoint_timeout`
  15 min, `wal_compression` lz4. `jit` i `work_mem` mają wartości domyślne, co potwierdza `SHOW`. Wartości ustawiają
  zmienne `PERF1_PG_*` nadpisania. Reszta stosu jak w sekcji 4: zasoby `prod`, API 2 GiB.
- **Kolejność:**
  1. świeży start stosu i zrzucenie cache maszyny Dockera;
  2. `concurrent-run.sh <katalog> pg`: kroki 0–2 bloku 3, czyli W3 i reindeks z mieszanką, z tymi samymi adresami
     (ziarno 20261128, 1600 adresów);
  3. `verify-run.sh` zimny, z restartem Postgresa i zrzuceniem cache;
  4. `verify-run.sh` ciepły.
- **Sąsiedzi:** pozostałe stosy hosta były bezczynne. W migawkach `docker stats` przed blokiem, w trakcie i po nim
  żaden kontener nie przekroczył 4% CPU. Powtórki nie były potrzebne.

| Miara | A: 2 GiB, `shared_buffers` 128 MB (sekcje 3–4) | C: 3,75 GiB, `shared_buffers` 1 GiB |
|---|---|---|
| weryfikator zimny / ciepły | 280 s / 515–536 s | 99 s / 108 s |
| weryfikator: bloki odczytane, trafienia | 22,2 mln, 71,8% | 6,3 mln, 92,0% |
| W3: trafienia bufora `shop` | 99,68% | 99,73% |
| W3: p95 API i sklepu, produkt zimny | – | 10 ms / 36 ms |
| reindeks z mieszanką: czas | 7 min 3 s | 3 min 36 s |
| reindeks z mieszanką: trafienia bufora `shop`, bloki odczytane | 81,87%, 9,39 mln | 95,28%, 2,41 mln |
| indeksy główne cen i stanów w reindeksie: trafienia | 74,7% | 99,8% |
| mieszanka: błędy, p95 klas API | 0 | 0; 26–169 ms |
| checkpointy wymuszone / czasowe (reindeks z mieszanką) | 0 / 2 | 0 / 0 |

Wnioski:

- **Potwierdzone na `medium`:**
  - Wariant startowy skraca weryfikator 2,8–5 razy, a reindeks z ruchem dwukrotnie.
  - Indeksy cen i stanów, które reindeks czyta losowo, mieszczą się w buforach.
  - Do 6 GiB z `shared_buffers` 1536 MB (62–63 s, sekcja 3) brakuje weryfikatorowi 36–45 s.
  - Ciepły przebieg nie jest już wolniejszy od zimnego (A: 536 s wobec 280 s).
- **Hipoteza:** na `server` baza ma 63 GB, a gorące indeksy sklepu ok. 9,5 GB, więc 1 GiB buforów i 3 GiB cache pokryją
  mniejszą część gorących danych niż na `medium` (baza 10 GB). Efekt na `server` pokażą odsetek trafień i iowait
  z eksportera (zadanie 5) po wdrożeniu za zgodą właściciela.
- **Weryfikator:** czas na `server` nadal wyznacza głównie konstrukcja zapytań (#980, sekcja 3.2), nie pamięć.

Dane surowe: `verifier-runs.tsv` (wiersze C-cold, C-warm). Liczniki `pg_stat_*` bloku pochodzą z tych samych zapytań co
w `concurrent-run.sh` i `verify-run.sh`.
