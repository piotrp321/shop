# tools/metrics

Pomiar przebiegów epików AO (specyfikacja: `docs/features/metrics/spec.md`). Python 3.9+, tylko biblioteka standardowa.
Skrypt czyta dane AO, miary limitów nocnej kolejki i GitHub (`gh`, tylko odczyt), nie czyta sklepu. Baza AO jest
otwierana tylko do odczytu (`mode=ro`, `busy_timeout`), a `ao` nie jest nigdy uruchamiane.

Stan tego zakresu (METRICS-1): komendy `epic` i `backfill`. Pozostałe komendy i pola rekordu dodają kolejne zadania
epiku (zob. „Pola jeszcze nieobecne”).

## Komendy

```
python3 tools/metrics/metrics.py epic EPIK [--ao-home DIR] [--manual-fixes FILE] [--no-gh] [WSPÓLNE]
python3 tools/metrics/metrics.py backfill [--ao-root DIR] [WSPÓLNE]
python3 tools/metrics/fixture_scan.py [DIR]
```

Wspólne opcje: `--config FILE` (domyślnie `config.json` obok skryptu), `--state DIR` (rekordy; domyślnie
`$SHOP_METRICS_STATE` albo `~/.local/state/shop-metrics`), `--night DIR` (`measurements.jsonl` i `gate.jsonl`; domyślnie
`~/.local/state/ao-night`), `--busy-timeout-ms N` (domyślnie z `config.json`), `--repo OWNER/NAZWA` (przekazywane do
każdego `gh` jako `-R`; domyślnie repozytorium katalogu roboczego).

- `epic`: wypisuje rekord przebiegu jako jedną linię JSON i zapisuje go w `<state>/epics/<epik>.json` (zapis
  atomowy). Przebieg to `--ao-home` (domyślnie `ao-<epik>` pod `$SHOP_METRICS_AO_ROOT` albo `~/.local/state`).
  `--manual-fixes FILE`: lista ręcznych poprawek od sesji `/ao-run`, jedna na linię (`- PR #12: tekst` albo `- tekst`;
  pusta linia i nagłówek `Ręczne poprawki: N` są pomijane). `--no-gh` pomija `gh`.
- `backfill`: rekord każdego przebiegu `ao-*` pod `--ao-root` (domyślnie jak wyżej), którego baza ma wiersz epiku
  z końcowym PR w stanie `MERGED` według `gh` (także przy stanie AO `INTEGRATING` i `BLOCKED`). Pomija epiki w toku
  (bez końcowego PR albo PR niezmergowany) i katalogi bez bazy albo bez wiersza epiku (np. `ao-night`). Wypisuje
  `{"recorded": [...], "skipped": [{"run", "reason"}]}`. Wymaga `gh` (nie ma `--no-gh`).
- `fixture_scan.py`: skan fixture'ów (zob. niżej).

Kody wyjścia: `0` sukces; `2` nic nie zapisano (złe argumenty albo `config.json`, brak katalogu AO, nieczytelna albo
zablokowana baza, brak wiersza epiku, nieczytelny plik `--manual-fixes`, stan niezapisywalny; komunikat po polsku na
stderr, stdout pusty); `3` rekord wypisano i zapisano, ale źródło zawiodło (błąd `gh`: brak programu, kod ≠ 0,
przekroczony czas, odpowiedź inna niż JSON; w `backfill` także pominięty przebieg z błędem bazy albo `gh`). Błąd `gh`
nie zatrzymuje pomiaru: pola z GitHuba są `null` z pozycją w `gaps`, a poprzednie wartości zostają (zob. „Cache”).

## Rekord epiku

Czasy w sekundach, znaczniki czasu w UTC (`2026-11-12T21:56:10Z`). Brak źródła daje `null` i pozycję w `gaps`
(`pole: powód`), nigdy zero. Zero oznacza, że źródło jest i niczego nie pokazuje.

| Pole | Treść |
|---|---|
| `epic`, `issue`, `final_pr`, `tasks` | ID epiku; pierwszy issue zamykany przez końcowy PR (`gh pr view`, `closingIssuesReferences`); numer końcowego PR (`epics.final_pr_number`); liczba zadań planu (`tasks.kind = plan`) |
| `started_at`, `done_at`, `merged_at` | `epics.created_at`; pierwsze zdarzenie `epic_done` (bez niego `null`); `mergedAt` końcowego PR z `gh` |
| `wall` | `to_done` (start → `epic_done`), `to_merge` (start → merge), `review` (`epic_done` → merge) |
| `breakdown` | rozkład `to_done` na `work`, `human_wait`, `limit_wait`, `ci_wait`, `host_pause`, `github_wait`, `idle`; suma kategorii z wartością równa się `to_done`; bez `epic_done` całość `null` |
| `integration` | `work_s` (suma `wall_s` prób zadań `integration`, `fix`, `finalize`), `attempts`, `rounds` (`epics.round`) |
| `retries` | `attempts` (`total`, `per_task`, klasy prób), `failed_by_outcome`, `repair` (`count`, `final_pr_conflict`, `ci_red`, `unknown`), `fix` (liczba zadań FIX), `sync_prs` (PR `sync/<epik>-main`: `number`, `state`) |
| `parallel` | `epics` (epiki, których przedział w `measurements.jsonl` przecinał się z tym epikiem), `repairs` (zadania REPAIR z próbą w czasie nakładania), `sync_prs` (numery PR sync utworzonych w czasie nakładania) |
| `subscription` | `windows`: surowy przyrost procentu każdego okna limitu w pp (`null` gdy okno odnowione w trakcie albo stan jednego z pomiarów inny niż `ok`, wtedy `{etykieta: null}`; gdy żaden pomiar nie ma stanu `ok`, nie da się ustalić etykiet okien i całe `windows` to `null`, z pozycją w `gaps`; reguły `night_queue._window_deltas`), `overlap`: epiki z nakładającym się przedziałem |
| `gate_wait` | `wait_s`: od pierwszego wpisu `waiting` epiku w `gate.jsonl` do `started_at` (0 bez czekania; `null`, gdy brak pliku albo wpisów epiku), `reasons`: różne powody czekania |
| `human` | `interventions` (`human_action`), `waits`, `wait_s` (suma zakończonych czekań, nakładające się liczone raz), `open` (czekania otwarte), `manual_fixes` (`[{pr, text}]` z `--manual-fixes` albo `null`); z `ao_events.summarize` |
| `gaps` | braki danych |

Źródła i ich pierwszeństwo w `breakdown` (każda chwila trafia do pierwszej pasującej kategorii, kolejność w
`breakdown_order`):

| Kategoria | Przedział (zdarzenia z tabeli `events` bazy AO, o ile nie podano inaczej) |
|---|---|
| `work` | trwa co najmniej jedna próba (`started_at`–`ended_at`; próba bez końca do `epic_done`) |
| `human_wait` | czekania z `ao_events.waits` nad `events.jsonl` (bloker, awaria, `epic_blocked`, `queue_held`); otwarte do `epic_done` |
| `limit_wait` | `cooldown_set` (`from`) → `until` albo `cooldown_released`, co wcześniej; bez końca pominięty z pozycją w `gaps` |
| `ci_wait` | `epic_ci_pending` → `epic_ci_green`, `epic_ci_inherited`, `epic_ci_inherited_red`, `epic_ci_timeout` albo `epic_repairing`; `final_pr_created` → `final_pr_ci` |
| `host_pause` | `host_pause` (`from`–`to`, bez nich `seconds` przed zdarzeniem) |
| `github_wait` | `github_wait` → następne zdarzenie epiku (z `task_id` zadania epiku albo z `data.epic_id` tego epiku) inne niż `github_wait` i `gh_call`; zdarzenia hosta bez epiku (`host_on_battery`, `usage_warning` bez epiku) i innych epików go nie kończą; najwyżej `github_wait_cap_s` (30 min) od ostatniego `github_wait` serii |
| `idle` | reszta |

Brak `events.jsonl` daje `human_wait: null` (jego czas jest w `idle`) i `human` z samymi `null`.

Klasy prób (`retries.attempts`; definicje: spec, „Pojęcia”):

- `blocked`: `executor_status = BLOCKED` (czekanie na człowieka, nie porażka);
- `usage_limit`: `outcome = usage_limit` (ani udana, ani nieudana);
- `successful`: `outcome = result` i `executor_status` inny niż `FAILED` i `BLOCKED`;
- `failed`: `outcome` `timeout`, `invalid_output`, `crash`, `policy_blocked`, `result` z `FAILED` albo pusty `outcome`
  po końcu przebiegu (jest `epic_done`, stan AO `DONE` albo końcowy PR zmergowany); klucze `failed_by_outcome`:
  nazwa `outcome`, `result_failed`, `no_outcome`;
- `running`: pusty `outcome` w przebiegu, który trwa; `unclassified`: nieznany `outcome` (z pozycją w `gaps`).

Przyczyna zadania REPAIR: `final_pr` w danych `epic_repairing` zadania to konflikt końcowego PR, inaczej czerwone CI
gałęzi; zadanie bez zdarzenia ma przyczynę `unknown` (pozycja w `gaps`).

Przedział epiku w `measurements.jsonl`: od pierwszego `start` do ostatniego `done` epiku, które leżą w czasie przebiegu.
Nakładanie jak w `night_queue.measurements`: przedział innego epiku, który przecina ten, albo jego otwarty `start`
w środku (otwarty `start` sprzed przedziału nie liczy się). Przyrost okien liczy `night_queue._window_deltas`
(importowane z `.claude/skills/ao-run`, nie kopiowane), czekania `ao_events.waits`.

### Pola jeszcze nieobecne

Rekord tego zakresu nie ma kluczy: `in_attempts`, `by_task`, `versions`, `attempts`, `value`, `share` w
`subscription` (METRICS-2), `ci`, `regressions`, `found_after_merge`, `bottlenecks` (METRICS-3), `waste` (METRICS-4).
Kluczy nie ma, zamiast `null` z pozycją w `gaps`, więc dodanie ich nie zmienia istniejących pól. Komendy `trend`,
`render`, `waste`, `decide` i `effect` dodają kolejne zadania.

### Cache

Rekord zapisuje się w `<state>/epics/<epik>.json`. Przejściowy błąd źródła nigdy nie zastępuje wartości niepustej
wartością `null`: dla `issue`, `merged_at`, `wall.to_merge`, `wall.review`, `retries.sync_prs`, `parallel.sync_prs`
i `human.manual_fixes` (błąd `gh`, `--no-gh`, brak `--manual-fixes`) zostaje poprzednia wartość, a w `gaps` jest
`pole: źródło niedostępne: zachowano poprzednią wartość` obok powodu. Pozostałe pola liczą się od nowa. Nieczytelny
zapisany rekord jest pomijany.

## Konfiguracja

`config.json` (zmiana progu nie wymaga zmiany kodu; złe wartości kończą komendę kodem 2 z nazwą klucza):

| Klucz | Znaczenie |
|---|---|
| `breakdown_order` | kolejność kategorii `breakdown` (pierwszeństwo; `idle` zawsze liczy resztę) |
| `github_wait_cap_s` | najdłuższy `github_wait` od ostatniego zdarzenia serii (1800 s) |
| `busy_timeout_ms`, `gh_timeout_s` | czas oczekiwania na zajętą bazę (5000 ms) i na `gh` (60 s) |
| `shell_tools`, `command_categories` | nazwy narzędzi powłoki i wzorce kategorii poleceń (używa je METRICS-2) |
| `token_weights` | proporcje cennika do podziału wartości na role (METRICS-2) |
| `bottlenecks`, `waste`, `decisions`, `evals` | progi wąskich gardeł, alarmów i reguł decyzji, role i katalog wyników EVALS 2.0 (METRICS-3 i dalej) |
| `regression_window_days`, `trend_last` | okno regresji (14 dni), domyślna liczba epików w `trend` (10) |

## Testy i fixture'y

```
python3 -m unittest discover -s tools/metrics -p 'test_*.py'
```

CI uruchamia je przez `.github/scripts/script-tests.sh`. Testy nie wołają prawdziwego `gh` ani sieci (`gh` jest
podstawiany funkcją `run`, a test procesu używa atrapy na `PATH`) i nie dotykają prawdziwego `~/.local/state`
(`HOME` wskazuje katalog tymczasowy). `testkit.py` buduje katalog `ao-<epik>` z bazą z zrzutu SQL albo syntetyczną.

`fixtures/ao-data/` to oczyszczony pakiet danych przebiegów DEPLOY-OPS i CATALOG-ADMIN (zrzuty SQL tabel `epics`,
`tasks`, `attempts`, `events`, `events.jsonl`, `measurements.jsonl`, `gate.jsonl`): pola z listy dozwolonych, bez
tekstów, ścieżek bezwzględnych i poleceń. Pliki są skopiowane bez zmian z pakietu przygotowanego przed startem epiku.
`fixture_scan.py` (test: `test_fixture_scan.py`) sprawdza każdy plik pod `fixtures/`: brak ścieżek bezwzględnych
(`/Users/`, `/private/`, `~`), adresów e-mail, wzorców tokenów (`ghp_`, `gho_`, `github_pat_`, `sk-`, `Bearer`,
`Basic`), ciągów base64 od 32 znaków (poza SHA i UUID; ciąg musi mieć cyfrę albo mieszane litery, a ścieżka ze samych
słów jest dozwolona) i wartości tekstowych dłuższych niż 120 znaków. Nowe fixture'y (np. transkrypty METRICS-2) muszą
przejść ten skan.
