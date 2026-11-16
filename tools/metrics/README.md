# tools/metrics

Pomiar przebiegów epików AO (specyfikacja: `docs/features/metrics/spec.md`). Python 3.9+, tylko biblioteka standardowa.
Skrypt czyta dane AO, miary limitów nocnej kolejki i GitHub (`gh`, tylko odczyt), nie czyta sklepu. Baza AO jest
otwierana tylko do odczytu (`mode=ro`, `busy_timeout`), a `ao` nie jest nigdy uruchamiane.

Stan tego zakresu (METRICS-1, METRICS-2 i METRICS-4): komendy `epic` i `backfill` z rekordem z danych AO i z transkryptów
prób oraz `waste` (alarmy bezproduktywnego zużycia w trakcie przebiegu). Pozostałe komendy i pola rekordu dodają kolejne
zadania epiku (zob. „Pola jeszcze nieobecne”).

## Komendy

```
python3 tools/metrics/metrics.py epic EPIK [--ao-home DIR] [--manual-fixes FILE] [--no-gh] [WSPÓLNE]
python3 tools/metrics/metrics.py backfill [--ao-root DIR] [WSPÓLNE]
python3 tools/metrics/metrics.py waste AO_HOME [--max-qa-rounds N] [WSPÓLNE]
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
- `waste`: nowe alarmy bezproduktywnego zużycia przebiegu `AO_HOME` (niżej), po jednej linii JSON; kod `0` także bez
  alarmów. `--max-qa-rounds` (domyślnie 3, tyle co w `/ao-run`) to runda QA, od której alarmuje W3.
- `fixture_scan.py`: skan fixture'ów (zob. niżej).

Kody wyjścia: `0` sukces; `2` nic nie zapisano ani nie wypisano (złe argumenty albo `config.json`, brak katalogu AO,
nieczytelna albo zablokowana baza, brak wiersza epiku, nieczytelny plik `--manual-fixes`, stan niezapisywalny,
nieczytelny `waste.json` w `waste`; komunikat po polsku na stderr, stdout pusty); `3` rekord wypisano i zapisano, ale źródło zawiodło (błąd `gh`: brak programu, kod ≠ 0,
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
| `subscription` | `share` i `share_estimated` (niżej), `windows`: surowy przyrost procentu każdego okna limitu w pp (`null` gdy okno odnowione w trakcie albo stan jednego z pomiarów inny niż `ok`, wtedy `{etykieta: null}`; gdy żaden pomiar nie ma stanu `ok`, nie da się ustalić etykiet okien i całe `windows` to `null`, z pozycją w `gaps`; reguły `night_queue._window_deltas`), `overlap`: epiki z nakładającym się przedziałem |
| `gate_wait` | `wait_s`: od pierwszego wpisu `waiting` epiku w `gate.jsonl` do `started_at` (0 bez czekania; `null`, gdy brak pliku albo wpisów epiku), `reasons`: różne powody czekania |
| `human` | `interventions` (`human_action`), `waits`, `wait_s` (suma zakończonych czekań, nakładające się liczone raz), `open` (czekania otwarte), `manual_fixes` (`[{pr, text}]` z `--manual-fixes` albo `null`); z `ao_events.summarize` |
| `in_attempts` | czas poleceń powłoki w próbach z transkryptów: `categories` (`read`, `test`, `e2e`, `check`, `selftest`, `stack`, `ci_watch`, `other`: `count`, `background`, `unfinished`, `seconds`), `repeat_runs` (`count`, `seconds`, `by_category`), `api_retry` (`count`, `delay_ms`), `permission_denials` (`{narzędzie: liczba}`); `null` bez żadnego transkryptu |
| `by_task` | `{zadanie: {kind, work, attempts}}`: `work` to suma `wall_s` prób zadania, także prób po `epic_done` |
| `versions` | `cli` (`[{version, attempts}]`, z `system/init`) i `models` (`[{model, attempts}]`, z wiadomości `assistant`); `null` bez transkryptów |
| `attempts` | lista prób: `task`, `kind`, `number`, `outcome`, `executor_status`, `wall_s`, `started_at`, `ended_at`, `value` (USD), `estimated`, `tokens` (`input`, `output`, `cache_read`, `cache_write`), `estimated_tokens`, `cli_result` (czy próba ma wynik CLI), `model_rates` (USD na ważony token wejścia i cache modelu, tylko próby z wynikiem CLI); próba bez transkryptu ma `null` w polach wartości |
| `value` | `total`, `by_role`, `by_model`, `by_task`, `by_role_model` (`{rola: {model: komórka}}`); komórka: `usd`, `estimated`, `tokens`, `estimated_tokens` i `incomplete`, gdy część wartości jest nieznana |
| `waste` | alarmy tego epiku z `<state>/waste.json` (jak w pliku: `key`, `rule`, `epic`, `task`, `attempt`, `at`, `push`, liczby); `[]`, gdy plik jest i nie ma alarmów epiku; `null` z pozycją w `gaps` bez pliku (komenda `waste` jeszcze nie działała) albo gdy plik jest nieczytelny (`epic` nie kończy się wtedy błędem) |
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

### Transkrypty prób: tokeny, wartość, polecenia

Źródło: `<AO_HOME>/tasks/<zadanie>/attempts/<numer>/stdout.jsonl` (czytane wierszami; ostatni, uciętej próby, jest
pomijany). Brak pliku to pozycja w `gaps` (`attempts.transcript`) i `null`, nigdy zero. Treść polecenia powłoki służy
tylko do porównania w pamięci i nigdy nie trafia do rekordu (test: polecenie ze ścieżką `/Users/`).

- **Rola:** `subagent_type` wiadomości `assistant`; wiadomość bez `parent_tool_use_id` to orkiestrator próby (`delivery` dla
  zadań `plan`, `repair`, `fix`, `finalize`, `integration` dla `integration`); model w `modelUsage` bez wiadomości i
  nadwyżka `modelUsage` nad wiadomościami to `other`; wiadomość subagenta bez `subagent_type` to rola `subagent`.
  Wiadomości o tym samym `message.id` liczą się raz (pierwszy wiersz).
- **Tokeny:** wejście i cache roli z wiadomości (dokładne). Wyjście orkiestratora to suma `usage.output_tokens` wszystkich
  `result` próby (każdy obejmuje tylko odcinek od poprzedniego), wyjście subagentów razem na modelu to `outputTokens`
  modelu z ostatniego `result` minus wyjście orkiestratora na nim (dokładne), a między typy subagentów dzieli je liczba
  unikalnych `message.id` metodą największej reszty (szacowane, gdy typów jest kilka). Niedobór `modelUsage` względem
  wiadomości: role modelu są zmniejszane proporcjonalnie do `modelUsage` i szacowane, z pozycją w `gaps` (suma ról nigdy nie
  przekracza `modelUsage`). Orkiestrator na więcej niż jednym modelu (`system/init` albo wiadomości): jego wyjście na
  modelach i wyjście subagentów są szacowane, z pozycją w `gaps`.
- **Wartość:** `costUSD` modelu z ostatniego `result` dzielone między role wagami `token_weights`; dokładna tylko dla roli
  jedynej na modelu (przy kilku rolach, niedoborze albo szacowanym wyjściu `estimated: true`). Wartość próby, modelu i
  zadania (`total`, `by_model`, `by_task`) jest dokładna dla prób z wynikiem CLI, podział na role (`by_role`,
  `by_role_model`) szacowany tam, gdzie rola dzieli model. `costBasis: unknown` modelu to `null` i pozycja w `gaps`.
- **Próba bez wyniku CLI** (timeout, przerwanie, awaria): nie ma żadnego `result` albo ostatni jest zerowy (zerowe
  `total_cost_usd` i `modelUsage`) przy niezerowych tokenach wiadomości. Tokeny z wiadomości (wyjście to wartość zastępcza
  wiadomości, szacowana), wartość `estimated: true`: ważone tokeny wejścia i cache × mediana stawki historycznej modelu.
  Stawka próby z wynikiem CLI (`model_rates`) to pełne `costUSD` modelu (z wyjściem) przez jego ważone tokeny wejścia i
  cache; mediana z prób z wynikiem CLI zapisanych rekordów innych epików (`<state>/epics/*.json`) i bieżącego przebiegu.
  Bez historii stawki modelu wartość to `null` z pozycją w `gaps`.
- **Przekroje:** sumy komórek po próbach; `incomplete`, gdy część prób nie ma wartości (suma obejmuje znane).
  Wartości zapisane z dokładnością 1e-8 USD.
- **Polecenia** (`in_attempts`): narzędzia powłoki z `shell_tools`; czas od `tool_use` do `tool_result` o tym samym
  identyfikatorze, według pierwszej pasującej kategorii z `command_categories` (`read` jest pierwsza, bo pasuje tylko do
  całego polecenia tylko do odczytu; brak dopasowania to `other`). Polecenie w tle (`run_in_background`) jest tylko w
  `count` i `background`, bez wyniku w `count` i `unfinished`. Dotyczy każdej roli.
- **`repeat_runs`** (heurystyka, dolna granica; „możliwe powtórzenia testów”, nie zbędna praca): uruchomienie z kategorii
  `repeat_categories` (`test`, `e2e`, `check`), którego klucz (kategoria i fragment polecenia dopasowany wzorcem do
  najbliższego operatora `|`, `;`, `&&`, `>`) jest taki sam jak poprzedniego uruchomienia w tej samej próbie, bez zmiany
  kodu pomiędzy. Zmiana kodu: `Edit`, `Write`, `NotebookEdit` (`code_change_tools`) w dowolnej roli albo polecenie `other`.
  Nie zmieniają kodu `read`, `ci_watch`, `stack` ani inne kategorie testów (także `selftest`). Uruchomienie nie wchodzi do
  `repeat_runs`, gdy pomiędzy trwało polecenie w tle, wywołanie narzędzia bez `tool_result` (także niezakończony
  subagent) albo gdy samo nie ma wyniku; takie wywołanie zeruje też pamięć uruchomień.
- **`api_retry`:** liczba zdarzeń `system/api_retry` i suma `retry_delay_ms`; **`permission_denials`:** zdarzenia
  `system/permission_denied` według `tool_name` (także subagentów i prób bez wyniku). `tool_name`, który nie jest identyfikatorem (reguła jak w `versions`), liczy się jako `unknown`; `subagent_type`, który nie jest identyfikatorem, jest traktowany jak brak roli (rola `subagent`).
- **`versions`:** wersje CLI z `system/init` i modele z wiadomości z liczbą prób; nazwa, która nie jest identyfikatorem
  (`[A-Za-z0-9._+-[]]`, do 64 znaków), nie trafia do rekordu.
- **`subscription.share`:** wartość tokenów epiku we wspólnym przedziale przez łączną wartość tokenów epiku i epików,
  które się w nim nakładały, w tym przedziale. Wspólny przedział to część przedziału pomiaru epiku (od `start` do `done`
  w `measurements.jsonl`), w której nakładał się z innym epikiem (przy kilku epikach suma ich części wspólnych); wartość
  nakładającego się epiku liczy się w jego własnej części wspólnej. Wartość próby jest przypisana do przedziału
  równomiernie w czasie trwania próby (próba częściowo poza przedziałem wnosi proporcjonalną część); wartość innych
  epików z ich zapisanych rekordów (`attempts` z `started_at`, `ended_at`, `value`), a gdy rekordu nie ma, liczona
  z ich katalogu przebiegu `ao-<epik>` (obok katalogu epiku, w korzeniu AO; baza tylko do odczytu i transkrypty, tymi
  samymi funkcjami co własna wartość, próba bez wyniku CLI ze stawki historycznej), więc przy `backfill` oba epiki pary
  dostają `share` niezależnie od kolejności. Epik 10 h z godzinną nakładką w tym samym tempie ma `share` około 0,5.
  Bez nakładania `1` (`share_estimated: false`); przy nakładaniu `share_estimated: true`, a gdy nakładający się epik nie
  ma ani rekordu, ani katalogu przebiegu, jego próby nie mają wartości albo własna wartość jest nieznana: `null`
  z pozycją w `gaps`. Surowy przyrost (`windows`) zostaje obok i obejmuje cały przedział pomiaru.

### Pola jeszcze nieobecne

Rekord tego zakresu nie ma kluczy: `ci`, `regressions`, `found_after_merge`, `bottlenecks` (METRICS-3). Kluczy nie ma,
zamiast `null` z pozycją w `gaps`, więc dodanie ich nie zmienia istniejących pól. Komendy `trend`, `render`, `decide`
i `effect` dodają kolejne zadania.

### Alarmy bezproduktywnego zużycia (`waste`)

`waste AO_HOME` uruchamia się wielokrotnie w trakcie przebiegu (sesja `/ao-run` przy zdarzeniach AO i przy
uzbrojeniu Monitora). Stan bieżący to baza `AO_HOME` (tylko do odczytu; epik to wiersz tabeli `epics`, przy katalogu
`ao-<epik>` ten, który nazywa katalog; próby bez `ended_at` trwają) i transkrypty zakończonych prób. Historia to zapisane
rekordy `<state>/epics/*.json` poza rekordem bieżącego epiku; pomiar to jedna próba (W1, W2) albo jedno zadanie (W4).
Próg medianowy wymaga `waste.min_history` (5) pomiarów tego samego `kind`; bez nich reguła nie alarmuje, a wyjście kończy
linia `{"gaps": [...]}` z pozycją `W1`, `W2` albo `W4` (tylko gdy wielkość przekracza próg bezwzględny, bo inaczej brak
historii niczego nie zmienia). Linia `gaps` nie ma klucza `push`: sesja `/ao-run` pushuje wyłącznie linie z kluczem `push`.

| Reguła | Warunek (progi w `config.json`, sekcja `waste`) | Klucz alarmu |
|---|---|---|
| W1 koszt próby | próba zakończona: `value` > `cost_multiple` (3) × mediana `value` zakończonych prób tego `kind` z wartością **i** `value` ≥ `cost_min_usd` (5 USD); próba bez wartości nie alarmuje (pozycja w `gaps`); wartość szacowana (próba bez wyniku CLI) może alarmować i ma w `push` „≈” | `W1:<epik>:<zadanie>:<próba>` |
| W2 trwająca próba | próba bez `ended_at` i bez `outcome` w epiku, który się nie skończył: `now − started_at` > `time_multiple` (3) × mediana `wall_s` zakończonych prób tego `kind` **i** > `time_min_h` (1) h; bez szacowania kosztu trwającej próby | `W2:<epik>:<zadanie>:<próba>` |
| W3 pętla prób (bez mediany i bez kwoty) | (a) próba zadania rozpoczęta po co najmniej `loop_failed_attempts` (2) nieudanych próbach tego samego zadania (klasa `failed`; `blocked` i `usage_limit` się nie liczą); alarmuje każda kolejna próba z własnym kluczem; (b) kolejny REPAIR epiku z rzędu: zadanie `repair` rozpoczęte, gdy od startu poprzedniego zadania `repair` (kolejność pierwszych prób) nie było zdarzenia `epic_ci_green` ani `epic_ci_inherited` epiku; trzeci REPAIR bez zielonego CI alarmuje też; (c) `epics.round` równe `--max-qa-rounds` | (a) `W3:<epik>:<zadanie>:<próba>`, (b) `W3:<epik>:repair:<zadanie>`, (c) `W3:<epik>:qa:<runda>` |
| W4 koszt zadania | suma `value` **zakończonych** prób zadania (trwającej się nie dolicza; zadanie, w którym zakończona próba nie ma wartości, jest pomijane z pozycją w `gaps`) > `cost_multiple` × mediana tej sumy po zadaniach tego `kind` w historii (zadania, w których wszystkie zakończone próby mają wartość) **i** ≥ `cost_min_usd`; raz na zadanie | `W4:<epik>:<zadanie>` |

Alarm kosztowy wymaga obu warunków (dokładnie 3× mediany nie alarmuje, dokładnie 5 USD alarmuje), czasowy też (dokładnie
3× mediany i dokładnie 1 h nie alarmują). Mediana zerowa nie ma ilorazu (`ratio: null`, `push` bez „×”).

Wyjście: jedna linia JSON na nowy alarm, np. `{"alarm": "W2", "key": "W2:deploy-ops:DEPLOY-OPS-4:3", "epic":
"deploy-ops", "task": "DEPLOY-OPS-4", "attempt": 3, "push": "deploy-ops: DEPLOY-OPS-4 próba 3 trwa 4,1 h (3,2× mediany)",
"seconds": 14760, "median": 4600, "ratio": 3.2}`. `push` ma najwyżej 200 znaków, liczby z przecinkiem dziesiętnym,
a z danych przebiegu tylko identyfikatory epiku i zadania (`[A-Za-z0-9._-]`, do 64 znaków, inaczej `?`) i liczby.

`<state>/waste.json` (`{"alarms": [{"key", "rule", "epic", "task", "attempt", "at", "push", ...liczby}]}`) i
`<state>/waste.lock`: odczyt pliku, wybór nowych kluczy, zapis atomowy (plik tymczasowy i `os.replace`) i wypisanie
dzieją się pod wyłącznym `flock` na `waste.lock`, więc dwie sesje wypisują każdy alarm raz. Zapis jest przed
wypisaniem: nieudany zapis nic nie wypisuje, a awaria wypisywania po zapisie gubi alarm, ale go nie duplikuje (alarm nigdy
dwa razy). Brak `waste.json` to pusty stan; plik nieczytelny albo w innym kształcie kończy komendę kodem `2` bez zmiany
pliku (po usunięciu przyczyny wysłane alarmy nie wracają). Zajęta żywa baza: `busy_timeout`, potem ponowienia
(`DB_RETRIES` = 2, przerwa 0,5 s), na końcu kod `2` i komunikat `zablokowana`; nic nie jest wtedy zapisane.

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
| `shell_tools`, `command_categories` | nazwy narzędzi powłoki i wzorce kategorii poleceń (kolejność ma znaczenie: pierwsza pasująca kategoria; `read` jest pierwsza); `read` pasuje tylko do całego polecenia tylko do odczytu (`git status`, `diff`, `log`, `show`, `rev-parse`, `ls`, `cat`, `head`, `tail`, `grep`, `rg`, `wc`, `pwd`) bez operatorów powłoki, przekierowań, `$` i odwrotnych apostrofów, więc `repeat_runs` jest dolną granicą |
| `code_change_tools`, `repeat_categories` | narzędzia, które zmieniają kod (`Edit`, `Write`, `NotebookEdit`), i kategorie poleceń liczone w `repeat_runs` (`test`, `e2e`, `check`; każda musi być w `command_categories`) |
| `token_weights` | proporcje cennika do podziału wartości na role: wejście 1, zapis cache 5 min 1,25, zapis cache 1 h 2, odczyt cache 0,1, wyjście 5 |
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
`fixtures/transcripts/<epik>/tasks/<zadanie>/attempts/<n>/stdout.jsonl` to oczyszczone transkrypty prób (pakiet METRICS-2:
wszystkie próby DEPLOY-OPS, CATALOG-ADMIN-INTEGRATION-1 i -2, CATALOG-ADMIN-4, ADMIN-DESIGN-3): pola z listy dozwolonych,
polecenia powłoki zastąpione kanonicznym poleceniem kategorii z numerem `# c<n>` (taki sam numer = ten sam klucz
`repeat_runs`). `testkit.py` ma też `Transcript`, który buduje syntetyczny transkrypt.
`fixture_scan.py` (test: `test_fixture_scan.py`) sprawdza każdy plik pod `fixtures/`: brak ścieżek bezwzględnych
(`/Users/`, `/private/`, `~`), adresów e-mail, wzorców tokenów (`ghp_`, `gho_`, `github_pat_`, `sk-`, `Bearer`,
`Basic`), ciągów base64 od 32 znaków (poza SHA i UUID; ciąg musi mieć cyfrę albo mieszane litery, a ścieżka ze samych
słów jest dozwolona) i wartości tekstowych dłuższych niż 120 znaków. Jedyny wyjątek, tylko od reguły base64: nazwy kluczy
`cacheCreationEphemeral1hInputTokens` i `cacheCreationEphemeral5mInputTokens` w obiekcie modelu najwyższego poziomu
`modelUsage` linii JSON (`modelUsage.<model>.<klucz>`, kształt wyniku CLI w transkrypcie). Ta sama nazwa jako wartość, jako
klucz gdzie indziej, w pliku nie-JSON albo w surowym tekście SQL jest wykrywana, tak samo jak base64 będący wartością tych
kluczy; pozostałe reguły działają na nich jak na każdym kluczu. Nowe fixture'y (np. transkrypty METRICS-2) muszą
przejść ten skan.
