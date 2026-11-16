# tools/metrics

Pomiar przebiegów epików AO (specyfikacja: `docs/features/metrics/spec.md`). Python 3.9+, tylko biblioteka standardowa.
Skrypt czyta dane AO, miary limitów nocnej kolejki i GitHub (`gh`, tylko odczyt), nie czyta sklepu. Baza AO jest
otwierana tylko do odczytu (`mode=ro`, `busy_timeout`), a `ao` nie jest nigdy uruchamiane.

Stan tego zakresu (METRICS-1 do METRICS-3): komendy `epic`, `backfill`, `trend` i `render` z rekordem z danych AO,
transkryptów prób i GitHuba. Pozostałe komendy i pola rekordu dodają kolejne zadania epiku (zob. „Pola jeszcze
nieobecne”).

## Komendy

```
python3 tools/metrics/metrics.py epic EPIK [--ao-home DIR] [--manual-fixes FILE] [--no-gh] [WSPÓLNE]
python3 tools/metrics/metrics.py backfill [--ao-root DIR] [WSPÓLNE]
python3 tools/metrics/metrics.py trend [--last N] [--epic EPIK] [--json] [--no-gh] [WSPÓLNE]
python3 tools/metrics/metrics.py render EPIK [--format report|comment] [WSPÓLNE]
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
- `trend`: tabela ostatnich `--last N` zmergowanych epików (domyślnie `trend_last`, 10; epik bez czasu merge'u nie wchodzi)
  z kolumnami na zadanie planu: `to_done`, `work`, `human_wait`, `review`, `gate_wait`, `repeat_runs` (godziny),
  `value` (USD wartości tokenów), `pp` (przyrost okna tygodniowego z `weekly_window_label` razy `share`, w pp),
  `failed` (próby nieudane), `repair`, `interventions`, `regressions` (liczby). Wartość nieznana (`null` w rekordzie albo
  epik bez zadań planu) jest `null`, nie zero. Pod tabelą: mediana każdej kolumny z pominięciem `null`, pozycja epiku
  `--epic` (domyślnie ostatniego; może być starszy niż tabela) względem tej mediany (różnica i procent) i adnotacje
  „wersja CLI: a → b” oraz „modele: + x − y”, gdy wersje CLI albo zestaw modeli różnią się od poprzedniego zmergowanego
  epiku (liczby bez korekty). Przed tabelą `trend` przelicza `regressions` i `found_after_merge` rekordów z otwartym oknem
  (`regressions.open`), bez tych pól albo z nieznanym składnikiem (`gh`; zapisuje rekordy; `--no-gh` pomija). Błąd `gh`
  zostawia zapisane wartości, tabela się wypisuje, kod wyjścia `3`. `--json` wypisuje tabelę jako JSON (`columns`, `epics`,
  `median`, `position`, `recomputed`). Bez rekordów kod `2` z podpowiedzią `backfill`.
- `render EPIK --format report|comment`: tekst z zapisanego rekordu i historii (`<state>/epics`), bez `gh`.
  `report` (raport odbioru, przed merge'em) zaczyna się od wąskich gardeł liczonych od nowa bez `review`, potem: czas do
  `epic_done` i rozkład, czas w próbach (z możliwymi powtórzeniami testów, ponowieniami API i odmowami), integracja, wartość
  tokenów (razem, role z dopiskiem „podział między subagentów szacowany”, modele, najdroższe zadanie; liczby szacowane z „≈”),
  subskrypcja w pp (osobno, nigdy w sumie z wartością tokenów; `share` przy nakładaniu), próby, REPAIR, FIX, sync, czekanie
  w bramce, interwencje, alarmy, wersje CLI i modele, CI, pozycja na tle mediany historii (epiki zmergowane przed tym) i braki
  danych (pełny tekst `gaps`). `comment` (po merge'u): linie `Interwencje człowieka: N`, `Czekanie na użytkownika: M czekania,
  razem K min` i `Ręczne poprawki: N` z listą `- PR #<nr>: <opis>` albo `- <opis>` (format, który czyta `retro.py collect`; test
  używa jego parsera), pusta linia i blok `### Pomiar epiku` z tymi samymi liczbami oraz `to_merge`, `review`, wąskimi gardłami
  z `review` z rekordu i regresjami. Bez `--manual-fixes` w rekordzie linia to `Ręczne poprawki: brak danych` (bez liczby, więc
  `retro.py` jej nie weźmie za pomiar). Komentarz idzie do publicznego issue, więc render przepuszcza tylko liczby (nie tekst
  i nie bool), identyfikatory (ID zadań, ról, modeli, wersji CLI, narzędzi i epików według `SAFE_NAME`, bez tego, co wygląda
  jak sekret), numery PR, stany PR z dużych liter i stałe słowa z kodu; reszta rekordu (teksty `gaps`, powody bramki
  poza kodami, komunikaty błędów, ścieżki, polecenia) odpada, a braki danych to liczba i nazwy pól. Jedyny tekst swobodny to
  lista ręcznych poprawek z `--manual-fixes` (pisze ją sesja `/ao-run`, nie dane przebiegu, a użytkownik widzi komentarz przed
  dodaniem): jedna linia, bez znaków sterujących, do 200 znaków, w pozycji bez PR bez dwukropka i z małej litery (parser czyta
  „Wielka litera…: ” jako następną etykietę). Brak rekordu epiku albo zły identyfikator: kod `2`.
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
| `subscription` | `share` i `share_estimated` (niżej), `windows`: surowy przyrost procentu każdego okna limitu w pp (`null` gdy okno odnowione w trakcie albo stan jednego z pomiarów inny niż `ok`, wtedy `{etykieta: null}`; gdy żaden pomiar nie ma stanu `ok`, nie da się ustalić etykiet okien i całe `windows` to `null`, z pozycją w `gaps`; reguły `night_queue._window_deltas`), `overlap`: epiki z nakładającym się przedziałem |
| `gate_wait` | `wait_s`: od pierwszego wpisu `waiting` epiku w `gate.jsonl` do `started_at` (0 bez czekania; `null`, gdy brak pliku albo wpisów epiku), `reasons`: różne powody czekania |
| `human` | `interventions` (`human_action`), `waits`, `wait_s` (suma zakończonych czekań, nakładające się liczone raz), `open` (czekania otwarte), `manual_fixes` (`[{pr, text}]` z `--manual-fixes` albo `null`); z `ao_events.summarize` |
| `in_attempts` | czas poleceń powłoki w próbach z transkryptów: `categories` (`read`, `test`, `e2e`, `check`, `selftest`, `stack`, `ci_watch`, `other`: `count`, `background`, `unfinished`, `seconds`), `repeat_runs` (`count`, `seconds`, `by_category`), `api_retry` (`count`, `delay_ms`), `permission_denials` (`{narzędzie: liczba}`); `null` bez żadnego transkryptu |
| `by_task` | `{zadanie: {kind, work, attempts}}`: `work` to suma `wall_s` prób zadania, także prób po `epic_done` |
| `versions` | `cli` (`[{version, attempts}]`, z `system/init`) i `models` (`[{model, attempts}]`, z wiadomości `assistant`); `null` bez transkryptów |
| `attempts` | lista prób: `task`, `kind`, `number`, `outcome`, `executor_status`, `wall_s`, `started_at`, `ended_at`, `value` (USD), `estimated`, `tokens` (`input`, `output`, `cache_read`, `cache_write`), `estimated_tokens`, `cli_result` (czy próba ma wynik CLI), `model_rates` (USD na ważony token wejścia i cache modelu, tylko próby z wynikiem CLI); próba bez transkryptu ma `null` w polach wartości |
| `value` | `total`, `by_role`, `by_model`, `by_task`, `by_role_model` (`{rola: {model: komórka}}`); komórka: `usd`, `estimated`, `tokens`, `estimated_tokens` i `incomplete`, gdy część wartości jest nieznana |
| `ci` | `final` i `tasks` (`{zadanie: ...}`): `pr`, `runs` (liczba przebiegów `ci_workflow` gałęzi `epic/<epik>` albo `task/<zadanie>` dla zdarzenia `pull_request`, czyli ponowienia), `checks_s` i `e2e_s` (czas ostatniego przebiegu: od pierwszego startu do ostatniego końca zadań o nazwie z `ci_jobs` albo z jej przedrostkiem i spacją, np. `checks part 1/3`; `null` bez takich zadań). Zadania bez numeru PR nie wchodzą. `null` z `gaps` bez `gh` albo przy błędzie |
| `regressions` | okno `window_days` (14) od merge'u końcowego PR: `until`, `open`, `checked_at`, `merge_commit`, `ci_red` (liczba czerwonych przebiegów `ci_workflow` ze zdarzeniem `push` na commicie merge'u; 0 przy zielonym), `reverts` (SHA commitów `main` w oknie, których pierwsza linia zaczyna się od `Revert`, a treść wymienia `#<numer końcowego PR>`), `issues` (numery issues `bug` z okna, których pole „Wprowadzone przez (epik albo PR, jeśli znane)” wskazuje ID epiku albo numer końcowego PR, od autora z prawem zapisu: `gh api repos/{owner}/{repo}/collaborators/{login}/permission` daje `write`, `maintain` albo `admin`; 404 to brak prawa, login niepasujący do wzorca loginu GitHuba nie jest sprawdzany; issues z etykietą `agent` nie liczą się tu), `count` (czerwone CI jako 1, plus reverty i issues; `null`, gdy któryś składnik nieznany). Składnik, którego źródło zawiodło, ma poprzednią zapisaną wartość albo `null`, z `gaps` |
| `found_after_merge` | `issues` i `count`: issues z etykietą `agent` utworzone w oknie, których sekcja „Źródło” ma linię `Przebieg `/sweep` <data>-<sha7>-<epik>` tego epiku (propozycje `/sweep`, osobno od regresji; etykietę `agent` ustawiają tylko osoby z prawem zapisu, więc bez sprawdzania autora) |
| `bottlenecks` | do `max_items` (3) pozycji o największej nadwyżce czasu ponad medianę historii, malejąco, nadwyżka co najmniej `min_excess_h` (0,5 h): `kind` (`breakdown`, `task`, `in_attempts`, `gate_wait`, `review`), `name`, `value_s`, `median_s`, `excess_h`, `share` i `share_of` (`to_done`, `gate_wait+to_done` albo `to_merge`), `rule` (klucz reguły decyzji: D2 dla zadania planu, D3 dla `gate_wait`, D4 dla `human_wait`, `ci_wait`, `limit_wait`, `in_attempts.ci_watch` i `review`, inaczej `null`), `repeat_h` (tylko `in_attempts`: ile z nadwyżki to `repeat_runs` tej kategorii, nie więcej niż nadwyżka). Potencjalny efekt reguły dodaje METRICS-5 |
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

### Wąskie gardła i historia

Kandydaci: kategorie `breakdown` poza `work` i kategorie `in_attempts` (nadwyżka = wartość − mediana na zadanie planu razy
liczba zadań planu tego epiku; udział w `to_done`), zadania (`work` zadania − mediana `work` zadań tego `kind`; udział
w `to_done`; kind bez historii nie jest kandydatem), `gate_wait` (udział w `gate_wait` + `to_done`; 0 bez czekania nie daje
pozycji, a brak wpisów w `gate.jsonl` pomija kandydata z pozycją `bottlenecks.gate_wait` w `gaps`) i `review` (udział w `to_merge`;
znany po merge'u, więc raport odbioru go pomija, a blok po merge'u ma). Historia to zapisane rekordy innych epików zmergowanych
przed tym (gdy ten nie ma czasu merge'u: wszystkie zmergowane); mediana ignoruje `null` i epiki bez zadań planu. Przy mniej
niż `min_history` (5) epikach mediana jest z dostępnych, z pozycją `bottlenecks` w `gaps`; bez historii lista jest pusta.
Rekord liczy wąskie gardła przy zapisie (`epic`, `backfill`), więc rekord starego epiku zapisany przed jego poprzednikami ma
je z mniejszej historii: `epic <epik>` liczy je od nowa. Szczegóły i progi: `config.json`, klucz `bottlenecks`.

### Pola jeszcze nieobecne

Rekord tego zakresu nie ma klucza `waste` (METRICS-4). Klucza nie ma, zamiast `null` z pozycją w `gaps`, więc dodanie go nie
zmienia istniejących pól (render pokazuje „Alarmy: brak danych”). Komendy `waste`, `decide` i `effect` dodają kolejne zadania.

### Cache

Rekord zapisuje się w `<state>/epics/<epik>.json`. Przejściowy błąd źródła nigdy nie zastępuje wartości niepustej
wartością `null`: dla `issue`, `merged_at`, `wall.to_merge`, `wall.review`, `retries.sync_prs`, `parallel.sync_prs`,
`human.manual_fixes`, `ci`, `regressions` i `found_after_merge` (błąd `gh`, `--no-gh`, brak `--manual-fixes`) zostaje
poprzednia wartość (składniki `regressions` osobno), a w `gaps` jest
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
| `bottlenecks` | `min_excess_h` (0,5), `max_items` (3), `min_history` (5): progi wąskich gardeł |
| `waste`, `decisions`, `evals` | progi alarmów i reguł decyzji, role i katalog wyników EVALS 2.0 (METRICS-4 i dalej) |
| `regression_window_days`, `trend_last` | okno regresji (14 dni), domyślna liczba epików w `trend` (10) |
| `ci_workflow`, `ci_jobs` | plik workflowu CI (`ci.yml`) i grupy zadań, których czas liczy `ci` (`checks`, `e2e`: zadania o tej nazwie i `<nazwa> …`) |
| `weekly_window_label` | etykieta okna limitu z `get_usage` brana do kolumny `pp` w `trend` (`Weekly · all models`) |

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
