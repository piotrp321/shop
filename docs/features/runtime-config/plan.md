# PERF-1 (RUNTIME-CONFIG) – plan zadań

Specyfikacja: `docs/features/runtime-config/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami,
z jedną gałęzią epiku (poza `epic/*`) i szkicem PR do `main` od początku. Zadania jednej fali z listą `parallel_with`
mogą iść naraz. Plan jest dokumentem roboczym: po ukończeniu epiku usuń go.

**Warunki startu.** Raport PERF-0 na `main` (linia bazowa `server`: pamięć, CPU i dysk usług z Grafany, wynik
generatora i reindeksu). Zadania 2–5 ruszają dopiero po decyzjach D1–D7, które właściciel podejmuje na podstawie
raportu zadania 1. Wartości zależne od D2 i D3 czekają dodatkowo na raport PERF-2 etap A; do tego czasu zadanie 4
zostawia stertę i limit Elasticsearcha bez zmian. Wdrożenie na worf.pl i każda operacja na serwerze wyłącznie za osobną
zgodą właściciela.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | RUNTIME-CONFIG-1 | |
| – | decyzje D1–D7 | |
| 2 | RUNTIME-CONFIG-2, RUNTIME-CONFIG-3 | |
| 3 | RUNTIME-CONFIG-4, RUNTIME-CONFIG-5 | |
| 4 | RUNTIME-CONFIG-6 | |

## TDD i pomiar w zadaniach

TDD obowiązuje zadanie 3 (mapowanie przerwanego zapytania na 503, zakres limitu na procesy): RED i GREEN w raporcie.
Zadania konfiguracji (2, 4, 5) weryfikują się testami renderu chartu w `infra/k8s/tests`, `make -C infra/k8s check`
i komendami z kryteriów, także na ścieżce błędu, bez sztucznej fazy RED. Pomiary lokalne (zadania 1 i 6) nie biegną
równolegle z benchmarkami PERF-2 na tej samej maszynie.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "RUNTIME-CONFIG",
  "spec": "docs/features/runtime-config/spec.md",
  "tasks": [
    {
      "id": "RUNTIME-CONFIG-1",
      "title": "Pomiar przed zmianą i wartości do decyzji D1–D7",
      "scope": "Lokalny stos `medium` z obrazów produkcyjnych i zasobami `prod` (nadpisanie compose poza repo, jak w audycie z 2026-11-25), z `pg_stat_statements` i `track_io_timing` włączonymi tylko w nadpisaniu. Mieszanka adresów i scenariusze W3, W5, W6 z PERF-0. Zbiera natywnymi widokami: szczyt pamięci nieodzyskiwalnej każdej usługi (`memory.stat`: `anon`, `shmem`, `file`), `pg_stat_database` i `pg_statio_user_indexes`, top `pg_stat_statements` (czasy, `temp_blks_written`, `jit_*_time`), `pg_stat_checkpointer`, połączenia według ról w generatorze kont i przy logowaniach, `varnishstat`, `_nodes/stats/jvm,breaker`, `redis-cli info memory` i `MEMORY USAGE` sesji, pamięć API przy 16 równoległych najcięższych żądaniach, pamięć imgproxy przy 16 zimnych przetworzeniach, `pg_test_timing`. Z raportu PERF-0 bierze zużycie serwera przy `server`. Wynik: raport w `docs/reports/` z wartościami przed i z propozycjami D1–D7 (budżet pamięci, CPU, połączeń i dysku w liczbach). Bez zmian w repo poza raportem.",
      "acceptance_criteria": [
        "Raport podaje W3, W5, W6 lokalnie (trzy przebiegi, mediana i rozrzut) i W3 z worf.pl z PERF-0.",
        "Dla każdej usługi `prod` raport podaje szczyt pamięci nieodzyskiwalnej i źródło (lokalnie albo Grafana `server`) oraz proponowany request i limit według Z1.",
        "Raport podaje odsetek trafień bufora Postgresa przy W3 i budżet połączeń z pomiaru szczytu połączeń według ról.",
        "Każda wartość oznaczona „kandydat” w specyfikacji ma w raporcie pomiar albo powód, dla którego czeka na `server` lub PERF-2A.",
        "Komendy pomiaru są w raporcie i dają się powtórzyć."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": ["docs/reports/"],
      "parallel_with": []
    },
    {
      "id": "RUNTIME-CONFIG-2",
      "title": "Postgres: plik ustawień stałych, pamięć z wartości środowiska, `/dev/shm`, `pg_stat_statements`",
      "scope": "`infra/postgres/postgresql.conf` z ustawieniami stałymi ze specyfikacji (sekcja „Postgres”, „Kiedy: teraz” oraz rozstrzygnięte w D2 i zadaniu 1: `jit`, `max_wal_size`, `checkpoint_timeout`), montowany w compose i dowiązaniem w chartcie, z sumą kontrolną w adnotacji poda; wartości pamięci (`shared_buffers`, `effective_cache_size`) jako `-c` z `values.yaml` dla `prod`, `load` i `ci`; `emptyDir` `Memory` na `/dev/shm` w chartcie i `shm_size` w compose; rozszerzenie `pg_stat_statements` w bazie `postgres` dla nowej bazy (skrypt init) i dla istniejącej (krok istniejącej ścieżki z uprawnieniami superużytkownika); `KC_DB_POOL_MAX_SIZE` w chartcie i compose; budżet połączeń w komentarzu `values.yaml`; przy przyjętym D7 rola eksportera z `pg_monitor` (skrypt init i istniejąca baza) i jej sekret w `init-env.sh` i chartcie. Dokumentacja: `infra/k8s/CLAUDE.md` tylko jeśli zmienia się zasada.",
      "acceptance_criteria": [
        "Kryterium 1 specyfikacji: `SHOW` każdego ustawienia w compose i w renderze `prod`, `load` i `ci` daje wartość z tabeli; `SELECT count(*) FROM pg_stat_statements` działa w compose i k3d `load`.",
        "Test renderu chartu sprawdza obecność pliku ustawień, `-c` z wartości środowiska, `/dev/shm` i `KC_DB_POOL_MAX_SIZE`; zmiana pliku zmienia sumę kontrolną w adnotacji poda.",
        "Ścieżka błędu: błędna nazwa ustawienia w pliku zatrzymuje start Postgresa z czytelnym błędem w logu, a `make -C infra/k8s check` albo test wyłapuje brak dowiązania pliku w chartcie.",
        "Zapytanie równoległe z dużym hash join nie kończy się błędem `could not resize shared memory segment` (test na compose).",
        "`make check` i `make -C apps/api test` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["RUNTIME-CONFIG-1"],
      "paths": ["infra/postgres/", "infra/scripts/init-env.sh", "infra/docker-compose.yml", "infra/k8s/charts/shop/", "infra/k8s/environments/", "infra/k8s/tests/test_workloads.py", "infra/k8s/tests/test_secrets.py", "infra/k8s/tests/test_files.py"],
      "parallel_with": ["RUNTIME-CONFIG-3"]
    },
    {
      "id": "RUNTIME-CONFIG-3",
      "title": "Limity czasu w bazie dla procesów i 503 po przerwanym zapytaniu",
      "scope": "Limity z tabeli „Limity czasu w bazie” (wartości z zadania 1) mechanizmem wybranym według warunku ze specyfikacji (`PGOPTIONS` w podach albo ustawienie sesji zależne od `PHP_SAPI`); `lock_timeout` w migracjach; mapowanie `SQLSTATE 57014` w API na 503 z `Retry-After` i komunikatem po polsku w formacie błędów API. Jeśli mechanizm wymaga zmiennej w podach, ta sama zmiana w chartcie i compose (wtedy ta część przechodzi do zadania 4, a zadanie 3 dostarcza wartości i test zakresu). Dokumentacja: `apps/api/CLAUDE.md` tylko przy nowej zasadzie.",
      "acceptance_criteria": [
        "Kryterium 2 specyfikacji, testy integracyjne: żądanie HTTP z zapytaniem `pg_sleep` dłuższym niż limit daje 503 z komunikatem po polsku w czasie limitu (z tolerancją), połączenie i wątek obsługują kolejne żądanie; RED przed zmianą i GREEN po niej w raporcie.",
        "Komenda konsoli w kontenerze API (np. `dbal:run-sql \"SHOW statement_timeout\"`) widzi 0, worker widzi swój limit, żądanie HTTP swój.",
        "Transakcja bezczynna dłużej niż `idle_in_transaction_session_timeout` traci połączenie, a blokada `FOR UPDATE` jest zwolniona (test).",
        "Migracja czekająca na blokadę dłużej niż `lock_timeout` kończy się błędem zamiast zatrzymywać ruch (test albo komenda z wynikiem w raporcie).",
        "`make -C apps/api check` i `make -C apps/api test` przechodzą; E2E w CI przechodzi z limitem włączonym."
      ],
      "risk": "STANDARD",
      "depends_on": ["RUNTIME-CONFIG-1"],
      "paths": ["apps/api/config/", "apps/api/src/Shared/Infrastructure/", "apps/api/tests/", "apps/api/CLAUDE.md"],
      "parallel_with": ["RUNTIME-CONFIG-2"]
    },
    {
      "id": "RUNTIME-CONFIG-4",
      "title": "Zasoby usług według Z1 i Z2 oraz jawne ustawienia usług",
      "scope": "Requesty i limity pamięci oraz requesty CPU w `prod` (i `load`, które je odzwierciedla) według decyzji D1–D3 i raportu zadania 1; test `test_prod_fits_the_server` zmieniony na: suma requestów pamięci + zmierzony system ≤ pamięć węzła, suma requestów CPU ≤ 7 vCPU, limit pamięci ≥ request, limit ponad request tylko dla Postgresa i Elasticsearcha; sterta Elasticsearcha z D3 (bez zmiany, dopóki brak wyniku PERF-2A); `num_threads` 16 w `Caddyfile`; `IMGPROXY_WORKERS`, `VARNISH_SIZE`, `maxmemory` i `maxmemory-policy` Redisa w chartcie i compose; rozmiary PVC `prod` z pomiaru `server`; zmienna `-c` `effective_cache_size` spójna z limitem. Dokumentacja: komentarz nagłówka `environments/prod/values.yaml` (nowa reguła budżetu).",
      "acceptance_criteria": [
        "Kryterium 3 i 4 specyfikacji: test renderu `prod` sprawdza nową regułę budżetu, w tym przypadki obejścia (limit poniżej requestu, cache przyznany usłudze innej niż Postgres i Elasticsearch, suma requestów ponad pamięć węzła), i jawne wartości usług.",
        "Ścieżka błędu: wartość `values.yaml` łamiąca budżet daje czytelny błąd `make -C infra/k8s check` z nazwą usługi i sumą.",
        "Lokalnie na `medium` z zasobami `prod`: 16 równoległych najcięższych żądań API i 16 zimnych przetworzeń imgproxy bez OOM; Redis przy `maxmemory` usuwa klucze z TTL zamiast kończyć się OOM (test z małym `maxmemory`).",
        "Compose i chart mają te same jawne wartości usług (`num_threads`, `IMGPROXY_WORKERS`, `VARNISH_SIZE`, Redis).",
        "`make check` i testy dotkniętych aplikacji przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["RUNTIME-CONFIG-2", "RUNTIME-CONFIG-3"],
      "paths": ["infra/k8s/environments/", "infra/k8s/charts/shop/", "infra/k8s/tests/test_workloads.py", "infra/docker-compose.yml", "apps/api/docker/frankenphp/Caddyfile"],
      "parallel_with": ["RUNTIME-CONFIG-5"]
    },
    {
      "id": "RUNTIME-CONFIG-5",
      "title": "Metryki pamięci, PSI, Postgresa i Elasticsearcha w Grafanie",
      "scope": "Według D7, jeśli PERF-0 ich nie dodał: `container_memory_rss` i `container_memory_cache` (jeśli brak na liście dozwolonych), `node_pressure_*` węzła, komponenty Alloy `prometheus.exporter.postgres` (rola i sekret z zadania 2) i `prometheus.exporter.elasticsearch` z listą dozwolonych serii (ok. 40); liczba aktywnych serii po zmianie w planie darmowym. Dokumentacja: `docs/adr/backups-observability.md` i spec `deploy-ops` w części metryk.",
      "acceptance_criteria": [
        "Test monitoringu (jak `test_log_masking.py` i testy listy metryk) sprawdza listę dozwolonych serii nowych komponentów; liczba nowych serii w raporcie zadania.",
        "Rola eksportera Postgresa ma tylko `pg_monitor`, bez prawa odczytu tabel sklepu (test albo komenda z wynikiem).",
        "Ścieżka błędu: niedostępny Postgres albo Elasticsearch nie zatrzymuje Alloy ani zbierania pozostałych metryk (`monitoring-smoke` albo test komponentu).",
        "`make check` przechodzi."
      ],
      "risk": "STANDARD",
      "depends_on": ["RUNTIME-CONFIG-2"],
      "paths": ["infra/k8s/argocd/apps/monitoring.yaml", "infra/k8s/tests/test_monitoring.py", "infra/k8s/tests/test_argocd.py", "docs/adr/backups-observability.md", "docs/features/deploy-ops/spec.md"],
      "parallel_with": ["RUNTIME-CONFIG-4"]
    },
    {
      "id": "RUNTIME-CONFIG-6",
      "title": "Pomiar po zmianie, ADR i raport",
      "scope": "Ten sam pomiar co w zadaniu 1 na gałęzi epiku lokalnie; po wdrożeniu za zgodą właściciela W3 i W15 na worf.pl (GET i Grafana), W14 lokalnie albo na serwerze za osobną zgodą. ADR `docs/adr/runtime-resources.md`: budżet pamięci, CPU, połączeń i dysku z liczbami, zasady Z1–Z6, każde ustawienie inne niż domyślne z powodem i pomiarem. Aktualizacja `docs/features/performance-program/plan.md` („Status”) i W15 w `docs/vision.md`, jeśli właściciel przyjął D4.",
      "acceptance_criteria": [
        "Kryteria 5–7 specyfikacji: raport przed i po z W3, W5, W6, odsetkiem trafień bufora, iowait i pamięcią nieodzyskiwalną usług; różnice podane wobec rozrzutu przebiegów.",
        "W15 na `server` w definicji z D4 albo wpis „niezmierzone” z powodem (brak zgody na wdrożenie, profil niewygenerowany).",
        "ADR nie zawiera historii zmian i odwołuje się do raportu pomiaru."
      ],
      "risk": "FAST",
      "depends_on": ["RUNTIME-CONFIG-2", "RUNTIME-CONFIG-3", "RUNTIME-CONFIG-4", "RUNTIME-CONFIG-5"],
      "paths": ["docs/adr/runtime-resources.md", "docs/reports/", "docs/features/performance-program/plan.md", "docs/vision.md"],
      "parallel_with": []
    }
  ]
}
```
