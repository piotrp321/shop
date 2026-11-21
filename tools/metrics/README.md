# tools/metrics

Pomiar przebiegów epików AO i epików natywnych (specyfikacja: `docs/features/metrics/spec.md`). Python 3.9+, tylko
biblioteka standardowa. Skrypt czyta dane AO, miary limitów nocnej kolejki, plik przebiegu i transkrypty sesji Claude
Code epiku natywnego oraz GitHub (`gh`, tylko odczyt), nie czyta sklepu. Baza AO jest
otwierana tylko do odczytu (`mode=ro`, `busy_timeout`), a `ao` nie jest nigdy uruchamiane.

Komendy: `start`, `done` i `pause` (plik przebiegu epiku natywnego), `process` (wersja procesu: oczekiwane,
na dysku i załadowane instrukcje), `epic` (rekord przebiegu z danych AO, transkryptów prób
i GitHuba albo z pliku przebiegu i transkryptów sesji epiku natywnego), `backfill` (rekordy zmergowanych
przebiegów), `render` (raport odbioru i komentarz w issue), `trend` (tabela i mediany ostatnich epików), `decide`
(decyzje optymalizacyjne), `effect` (zmierzony efekt decyzji) i `waste` (alarmy bezproduktywnego zużycia w trakcie
przebiegu).

## Komendy

```
python3 tools/metrics/metrics.py start EPIK [--resume] [--at CZAS] [--worktree DIR] [--branch NAZWA] [--pr N]
    [--tasks N] [--session ID] [--projects DIR] [--user-dir DIR] [--accept-process-mismatch POWÓD] [WSPÓLNE]
    < odczyt get_usage
python3 tools/metrics/metrics.py done EPIK [--at CZAS] [--worktree DIR] [--projects DIR] [--user-dir DIR] [WSPÓLNE]
    < odczyt get_usage
python3 tools/metrics/metrics.py pause EPIK --from CZAS --to CZAS [--reason TEKST] [WSPÓLNE]
python3 tools/metrics/metrics.py process [EPIK] [--worktree DIR] [--base REF] [--user-dir DIR] [--projects DIR]
    [WSPÓLNE]
python3 tools/metrics/metrics.py epic EPIK [--ao-home DIR] [--projects DIR] [--worktree DIR] [--manual-fixes FILE]
    [--no-gh] [WSPÓLNE]
python3 tools/metrics/metrics.py backfill [--ao-root DIR] [WSPÓLNE]
python3 tools/metrics/metrics.py trend [--last N] [--epic EPIK] [--json] [--no-gh] [WSPÓLNE]
python3 tools/metrics/metrics.py render EPIK [--format report|comment] [WSPÓLNE]
python3 tools/metrics/metrics.py waste AO_HOME [--max-qa-rounds N] [WSPÓLNE]
python3 tools/metrics/metrics.py decide EPIK [--history] [WSPÓLNE]
python3 tools/metrics/metrics.py effect KLUCZ --at CZAS [WSPÓLNE]
python3 tools/metrics/fixture_scan.py [DIR]
```

Wspólne opcje: `--config FILE` (domyślnie `config.json` obok skryptu), `--state DIR` (rekordy; domyślnie
`$SHOP_METRICS_STATE` albo `~/.local/state/shop-metrics`), `--night DIR` (`measurements.jsonl` i `gate.jsonl`; domyślnie
`~/.local/state/ao-night`), `--busy-timeout-ms N` (domyślnie z `config.json`), `--repo OWNER/NAZWA` (przekazywane do
każdego `gh` jako `-R`; domyślnie repozytorium katalogu roboczego).

- `start EPIK`: zapisuje plik przebiegu epiku natywnego `<state>/runs/<epik>.json` (zapis atomowy) i wypisuje go jako
  jedną linię JSON (zob. „Epik natywny”). Nazwa epiku musi pasować do `^[a-z0-9][a-z0-9-]*$` (wzorzec `EPIC_NAME`
  z `/retro`, który inaczej nie rozpozna pliku przebiegu): wielkie litery, `.` i `_` dają kod `2` z komunikatem, bez
  zapisu; dotyczy też `--resume`, `done` i `pause`, a `epic` oraz pozostałych komend nie. `--resume` dopisuje nową sesję do istniejącego pliku.
  Identyfikator sesji, który jest już w pliku przebiegu innego epiku, daje w `start` i `--resume` kod `2` z nazwą
  tamtego epiku (jedna sesja, jeden epik). `start` zapisuje wersję procesu (`process`, zob. „Wersja procesu”)
  i odmawia przy różnicy (kod `2`, bez zapisu) bez `--accept-process-mismatch POWÓD` (pusty powód: kod `2`); `--resume`
  porównuje ją z zapisaną na starcie tak samo; `--user-dir`: katalog użytkownika Claude Code (domyślnie `~/.claude`). `--projects`: katalog
  projektów Claude Code (domyślnie `~/.claude/projects`). Odczyt limitów z wejścia standardowego: obiekt `plan`
  z `get_usage` albo cały obiekt `get_usage` (`{"plan": …, "context": …}`, brany jest `plan`; `plan`, który nie jest
  obiektem, daje `{"status": "unreadable"}`); terminal albo puste wejście to brak odczytu (`null`).
- `done EPIK`: wpisuje do pliku przebiegu `done_at` (teraz albo `--at`) i drugi odczyt limitów z wejścia (`usage_done`,
  te same kształty co w `start`); bez `--at` porównuje wersję procesu z zapisaną na starcie (`--worktree`, domyślnie
  bieżący katalog) i zapisuje zmianę albo nieudaną kontrolę w `process.changes` z ostrzeżeniem na stderr, nigdy nie
  odmawia.
- `pause EPIK --from CZAS --to CZAS [--reason TEKST]`: dopisuje przerwę komputera do `pauses` pliku przebiegu
  (`{from, to, reason}`; czas jak `--at`; powód bez znaków sterujących, pusty to `null`) i wypisuje plik. Brak pliku
  przebiegu, czas nie do odczytania albo `--from` nie przed `--to`: kod `2`, bez zapisu.
- `process [EPIK]`: wersja procesu (zob. „Wersja procesu”) jako jedna linia JSON. Bez epiku: odczyt `--worktree`
  (domyślnie bieżący katalog) względem `--base` (domyślnie `HEAD`), bez zapisu, z `active_epics`. Z epikiem: ponowna
  kontrola na danych pliku przebiegu (dysk względem startu, `loaded` z transkryptów sesji epiku pod `--projects`),
  zapis zmian w `process.changes` (raz na zmianę) i nowego `loaded`. Kod `0` przy zgodności (samo `unconfirmed` nie
  jest niezgodnością), `4` przy niezgodności (stan na stdout, podsumowanie na stderr), `2` poza repozytorium git, bez
  definicji, z nieznaną rewizją albo bez pliku przebiegu.
- `epic`: wypisuje rekord przebiegu jako jedną linię JSON i zapisuje go w `<state>/epics/<epik>.json` (zapis
  atomowy). `--worktree` (domyślnie bieżący katalog): repozytorium, z którego rekord natywny czyta treść instrukcji
  bazy dla `process.loaded`. Przebieg AO to `--ao-home` (domyślnie `ao-<epik>` pod `$SHOP_METRICS_AO_ROOT` albo `~/.local/state`); gdy
  tego katalogu nie ma, rekord powstaje z pliku przebiegu `<state>/runs/<epik>.json` i transkryptów sesji pod
  `--projects` (rekord natywny, `source: native`); bez obu kod `2` z komunikatem, którego źródła brakuje.
  `--manual-fixes FILE`: lista ręcznych poprawek od sesji `/ao-run` albo sesji prowadzącej epik natywny, jedna na linię (`- PR #12: tekst` albo `- tekst`;
  pusta linia i nagłówek `Ręczne poprawki: N` są pomijane). `--no-gh` pomija `gh`.
- `backfill`: rekord każdego przebiegu `ao-*` pod `--ao-root` (domyślnie jak wyżej), którego baza ma wiersz epiku
  z końcowym PR w stanie `MERGED` według `gh` (także przy stanie AO `INTEGRATING` i `BLOCKED`). Pomija epiki w toku
  (bez końcowego PR albo PR niezmergowany) i katalogi bez bazy albo bez wiersza epiku (np. `ao-night`). Wypisuje
  `{"recorded": [...], "skipped": [{"run", "reason"}]}`. Wymaga `gh` (nie ma `--no-gh`).
- `trend`: tabela ostatnich `--last N` zmergowanych epików (domyślnie `trend_last`, 10; epik bez czasu merge'u nie wchodzi)
  z kolumnami na zadanie planu: `to_done`, `work`, `human_wait`, `review`, `gate_wait`, `repeat_runs` (godziny),
  `value` (USD wartości tokenów), `pp` (przyrost okna tygodniowego z `weekly_window_label` razy `share`, w pp),
  `failed` (próby nieudane), `repair`, `interventions`, `regressions` (liczby). Wartość nieznana (`null` w rekordzie albo
  epik bez zadań planu) jest `null`, nie zero; `value` jest `null` także dla epiku z niepełną sumą wartości tokenów
  (`value.total.incomplete`: część prób bez wartości), bo zaniżona suma nie jest wartością. Pod tabelą: mediana każdej kolumny z pominięciem `null`, pozycja epiku
  `--epic` (domyślnie ostatniego; może być starszy niż tabela) względem tej mediany (różnica i procent) i adnotacje
  „wersja CLI: a → b” oraz „modele: + x − y”, gdy wersje CLI albo zestaw modeli różnią się od poprzedniego zmergowanego
  epiku (liczby bez korekty). Przed tabelą `trend` przelicza `regressions` i `found_after_merge` rekordów z otwartym oknem
  (`regressions.open`), bez tych pól albo z nieznanym składnikiem (`gh`; zapisuje rekordy; `--no-gh` pomija). Błąd `gh`
  zostawia zapisane wartości, tabela się wypisuje, kod wyjścia `3`. Kolumny `value` (gdy `value.total.estimated`) i `pp`
  (gdy `subscription.share_estimated`) oraz `repeat_runs` (gdy `in_attempts.incomplete`: dolna granica z części transkryptów) są szacowane: tabela i linie „wobec mediany” (jak „Pozycja na tle mediany” w
  `render`) pokazują je z „≈ ” przed liczbą, także medianę, w której jest choć jedna wartość szacowana; procent różnicy w linii „wobec mediany” i w pozycji ma „≈” (`≈ +50%`), gdy wartość albo mediana kolumny jest szacowana (kolumna
  jest na liście `position.estimated` albo `position.median_estimated`; tak samo `diff` i `diff_pct` w `--json`); kolumna bez
  wartości nie jest szacowana. `--json` wypisuje tabelę jako JSON (`columns`, `epics`, `median`, `position`,
  `recomputed`) i listy kolumn szacowanych: `epics[].estimated` (wartości epiku), `median_estimated` (mediany tabeli),
  `position.estimated` (wartości epiku z `position.epic`) i `position.median_estimated`; liczby są te same co bez
  oznaczenia. Bez rekordów kod `2` z podpowiedzią `backfill`.
  **Dwa źródła.** Tabela jest jedna dla rekordów AO (`source: ao`; rekord bez pola `source` jest AO) i natywnych
  (`source: native`): kolumna „źródło” przy każdym epiku, a mediana jest osobna dla każdego źródła (linia `mediana ao`
  i `mediana native`, z wierszy tabeli danego źródła; rekord jednego źródła nie zmienia mediany drugiego). Pozycja epiku
  „wobec mediany (źródło: …)” jest względem mediany jego źródła; źródło bez wierszy w tabeli ma medianę `null`
  („brak danych”). W wierszu natywnym kolumna bez danych (np. `gate_wait`, `failed`, `repair` – brak źródła
  w transkryptach) to „brak danych”, nigdy zero (w wierszu AO nieznana wartość zostaje `-`). Bez oznaczenia są
  porównywalne tylko kolumny `to_done`, `review` i `regressions` (jak liczba zadań, czas do gotowego PR i do merge'u,
  CI końcowego PR); pozostałe kolumny rekordu natywnego, które mają wartość (`work`, `human_wait`, `repeat_runs`,
  `value`, `pp`, `interventions`), mają „†” (liczone inaczej niż w AO: wartość z cennika zamiast kosztu z CLI,
  interwencje to każda wiadomość człowieka, rozkład czasu bez oczekiwania na limity, CI i GitHub, koszt subskrypcji
  z lokalnych transkryptów), także w medianie i w linii pozycji; pod tabelą jest legenda „† liczone inaczej niż w AO”,
  gdy jest choć jedno oznaczenie. `--json`: `epics[].source`, `epics[].different` (kolumny oznaczone „†”),
  `sources` (`{ao|native: {epics, median, median_estimated, median_different}}` dla źródeł z wierszami w tabeli),
  `median` i `median_estimated` (mediana źródła pozycji, `median_source`), `position.source`, `position.different`
  i `position.median_different`; klucze `epics` i `position`, które czyta `retro.py`, oraz `median` zostają.
- `render EPIK --format report|comment`: tekst z zapisanego rekordu i historii (`<state>/epics`), bez `gh`.
  `report` (raport odbioru, przed merge'em) zaczyna się od wąskich gardeł liczonych od nowa bez `review`, potem: czas do
  `epic_done` i rozkład, czas w próbach (z możliwymi powtórzeniami testów, ponowieniami API i odmowami; przy
  `in_attempts.incomplete` godziny z „≈” i dopiskiem „transkrypty tylko N z M prób”, także przy liczbach poleceń,
  ponowień i odmów), integracja (przy `integration.incomplete` z „≈” i „(dolna granica)”), wartość
  tokenów (razem, role z dopiskiem „podział między subagentów szacowany”, modele, najdroższe zadanie z dopiskiem „(wśród
  zadań ze znaną wartością)”, gdy któreś zadanie ma wartość nieznaną albo niepełną; liczby szacowane z „≈”),
  subskrypcja w pp (osobno, nigdy w sumie z wartością tokenów; `share` przy nakładaniu), próby, REPAIR, FIX, sync, czekanie
  w bramce, interwencje, alarmy, wersje CLI i modele, CI, pozycja na tle mediany historii (epiki zmergowane przed tym) i braki
  danych (pełny tekst `gaps`). Po wąskich gardłach jest linia „Źródło pomiaru: AO” albo „natywne”; historia (mediana wąskich
  gardeł, „Pozycja na tle mediany”) to epiki zmergowane przed tym i tego samego źródła („mediana z 5 epików AO”, „2 epiki
  natywne”; bez nich „brak historii”). Dla rekordu natywnego pola liczone inaczej niż w AO mają dopisek „(liczone inaczej niż
  w AO)”: rozkład czasu (jego `idle` to górna granica, bo `breakdown_incomplete: true` oznacza w rekordzie natywnym brak
  źródła kategorii `limit_wait`, `ci_wait`, `host_pause` i `github_wait`, które mają „brak danych”), integracja (rundy
  przeglądu), wartość (szacunek według cennika API zamiast kosztu z CLI), subskrypcja, FIX (rundy przeglądu), interwencje
  i czekania człowieka oraz kolumny linii pozycji; pola bez źródła (próby, REPAIR, sync, bramka, alarmy) to „brak danych”.
  `comment` (po merge'u): linie `Interwencje człowieka: N`, `Czekanie na użytkownika: M czekania,
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
- `waste`: nowe alarmy bezproduktywnego zużycia przebiegu `AO_HOME` (niżej), po jednej linii JSON; kod `0` także bez
  alarmów. `--max-qa-rounds` (domyślnie 3, tyle co w `/ao-run`) to runda QA, od której alarmuje W3.
- `decide EPIK [--history]`: kandydaci reguł D1–D4 dla zapisanego rekordu epiku (JSON; bezstanowe, nic nie zapisuje, bez
  `gh`; zob. „Decyzje optymalizacyjne”). `--history` dodaje kandydatów każdego zmergowanego epiku (wejście `/retro`).
- `effect KLUCZ --at CZAS`: miara reguły przed i po merge'u poprawki z zapisanych rekordów (JSON; zob. „Efekt decyzji”).
  `CZAS` to czas ISO 8601 z `Z` albo przesunięciem UTC.
- `fixture_scan.py`: skan fixture'ów (zob. niżej).

Kody wyjścia: `0` sukces; `2` nic nie zapisano ani nie wypisano (złe argumenty albo `config.json`, brak katalogu AO
i pliku przebiegu, nieczytelny plik przebiegu, nazwa epiku spoza wzorca w `start` albo `done`, `start` bez gałęzi, PR, liczby zadań albo transkryptu sesji, drugi `start`
bez `--resume`, `--resume` albo `done` bez pliku przebiegu,
nieczytelna albo zablokowana baza, brak wiersza epiku, nieczytelny plik `--manual-fixes`, stan niezapisywalny,
nieczytelny `waste.json` w `waste`, zły klucz albo `--at` w `effect`, sesja z pliku przebiegu innego epiku, różnica
wersji procesu w `start` albo `--resume` bez akceptacji, pusty powód akceptacji, zły czas w `pause`, `process` poza
repozytorium git; komunikat po polsku na stderr, stdout pusty); `3` rekord wypisano i zapisano, ale źródło zawiodło (błąd `gh`: brak programu, kod ≠ 0,
przekroczony czas, odpowiedź inna niż JSON; w `backfill` także pominięty przebieg z błędem bazy albo `gh`); `4`
`process` wypisał stan, a wersja procesu jest niezgodna (różnica na dysku, skill użytkownika przesłaniający skill
projektu albo załadowana treść inna niż w bazie). Błąd `gh`
nie zatrzymuje pomiaru: pola z GitHuba są `null` z pozycją w `gaps`, a poprzednie wartości zostają (zob. „Cache”).

## Rekord epiku

Czasy w sekundach, znaczniki czasu w UTC (`2026-11-12T21:56:10Z`). Brak źródła daje `null` i pozycję w `gaps`
(`pole: powód`), nigdy zero. Zero oznacza, że źródło jest i niczego nie pokazuje.

| Pole | Treść |
|---|---|
| `source` | `ao` (rekord z przebiegu AO, ta tabela) albo `native` (zob. „Epik natywny”) |
| `epic`, `issue`, `final_pr`, `tasks` | ID epiku; pierwszy issue zamykany przez końcowy PR (`gh pr view`, `closingIssuesReferences`); numer końcowego PR (`epics.final_pr_number`; gdy pusty, jedyny zmergowany PR `epic/<epik>` → `main` z `gh pr list`, z pozycją `final_pr` w `gaps`; brak albo kilka takich PR: `null`); liczba zadań planu (`tasks.kind = plan`) |
| `started_at`, `done_at`, `merged_at` | `epics.created_at`; pierwsze zdarzenie `epic_done` (bez niego `null`); `mergedAt` końcowego PR z `gh` |
| `wall` | `to_done` (start → `epic_done`), `to_merge` (start → merge), `review` (`epic_done` → merge) |
| `breakdown` | rozkład `to_done` na `work`, `human_wait`, `limit_wait`, `ci_wait`, `host_pause`, `github_wait`, `idle`; suma kategorii z wartością równa się `to_done`; bez `epic_done` całość `null`, chyba że końcowy PR jest zmergowany (epik zamknięty ręcznie, np. AO w `BLOCKED`): wtedy rozkład `to_merge` do merge'u z `breakdown_end: "merged_at"` i `breakdown_estimated: true` (każda kategoria szacowana, „≈”, pozycja `breakdown` w `gaps`) |
| `integration` | `work_s` (suma `wall_s` prób zadań `integration`, `fix`, `finalize`), `attempts`, `rounds` (`epics.round`); `incomplete: true`, gdy część prób nie ma czasu (suma to dolna granica, pozycja w `gaps`) |
| `retries` | `attempts` (`total`, `per_task`, klasy prób), `failed_by_outcome`, `repair` (`count`, `final_pr_conflict`, `ci_red`, `unknown`), `fix` (liczba zadań FIX), `sync_prs` (PR `sync/<epik>-main`: `number`, `state`) |
| `parallel` | `epics` (epiki, których przedział w `measurements.jsonl` przecinał się z tym epikiem), `repairs` (zadania REPAIR z próbą w czasie nakładania), `sync_prs` (numery PR sync utworzonych w czasie nakładania) |
| `subscription` | `share` i `share_estimated` (niżej), `windows`: surowy przyrost procentu każdego okna limitu w pp (`null` gdy okno odnowione w trakcie albo stan jednego z pomiarów inny niż `ok`, wtedy `{etykieta: null}`; gdy żaden pomiar nie ma stanu `ok`, nie da się ustalić etykiet okien i całe `windows` to `null`, z pozycją w `gaps`; reguły `night_queue._window_deltas`), `overlap`: epiki z nakładającym się przedziałem |
| `gate_wait` | `wait_s`: od decyzji o starcie (pierwszy wpis `gate --dir <epik>`, czyli `dir_arg` epiku, w `gate.jsonl` przed startem: `/ao-run` po prośbie albo „tak” użytkownika, w nocy `next`) do `started_at`; czekania z przeglądu tablicy (`gate` bez `--dir`) przed decyzją się nie liczą (0 bez wpisu `waiting` epiku między decyzją a startem; `null`, gdy brak pliku, wpisów epiku albo, przy czekaniu, wpisu decyzji), `reasons`: różne powody czekań po decyzji |
| `human` | `interventions` (`human_action`), `waits`, `wait_s` (suma zakończonych czekań, nakładające się liczone raz), `open` (czekania otwarte), `manual_fixes` (`[{pr, text}]` z `--manual-fixes` albo `null`); z `ao_events.summarize` |
| `in_attempts` | czas poleceń powłoki w próbach z transkryptów: `categories` (`read`, `test`, `e2e`, `check`, `selftest`, `stack`, `ci_watch`, `other`: `count`, `background`, `unfinished`, `seconds`), `repeat_runs` (`count`, `seconds`, `by_category`), `api_retry` (`count`, `delay_ms`), `permission_denials` (`{narzędzie: liczba}`); `null` bez żadnego transkryptu. Gdy część prób nie ma transkryptu: `incomplete: true`, `attempts_without_transcript` i `attempts` (sumy obejmują tylko próby z transkryptem, to dolne granice; pozycja w `gaps`) |
| `by_task` | `{zadanie: {kind, work, attempts}}`: `work` to suma `wall_s` prób zadania, także prób po `epic_done`; `incomplete: true` przy zadaniu, którego próba nie ma `wall_s` ani końca (pominięta w sumie: `work` to dolna granica, pozycja w `gaps`) |
| `versions` | `cli` (`[{version, attempts}]`, z `system/init`) i `models` (`[{model, attempts}]`, z wiadomości `assistant`); `null` bez transkryptów |
| `attempts` | lista prób: `task`, `kind`, `number`, `outcome`, `executor_status`, `wall_s`, `started_at`, `ended_at`, `value` (USD), `estimated`, `tokens` (`input`, `output`, `cache_read`, `cache_write`), `estimated_tokens`, `cli_result` (czy próba ma wynik CLI), `model_rates` (USD na ważony token wejścia i cache modelu, tylko próby z wynikiem CLI); próba bez transkryptu ma `null` w polach wartości |
| `value` | `total`, `by_role`, `by_model`, `by_task`, `by_role_model` (`{rola: {model: komórka}}`), `by_task_role` (`{zadanie: {rola: komórka}}`: wartość roli w zadaniu; rola, która w zadaniu nie działała, nie ma wpisu; wejście miary D1 w `effect`; zob. „Brakujące źródła i pola rekordu”); komórka: `usd`, `estimated`, `tokens`, `estimated_tokens` i `incomplete`, gdy część wartości jest nieznana |
| `ci` | `final` i `tasks` (`{zadanie: ...}`): `pr`, `runs` (liczba przebiegów `ci_workflow` gałęzi `epic/<epik>` albo `task/<zadanie>` dla zdarzenia `pull_request`, czyli ponowienia), `checks_s` i `e2e_s` (czas ostatniego przebiegu: od pierwszego startu do ostatniego końca zadań o nazwie z `ci_jobs` albo z jej przedrostkiem i spacją, np. `checks part 1/3`; `null` bez takich zadań). Zadania bez numeru PR nie wchodzą. `null` z `gaps` bez `gh` albo przy błędzie |
| `regressions` | okno `window_days` (14) od merge'u końcowego PR: `until`, `open` (czas okna nie minął albo ostatni przebieg miał zawiedzione źródło), `complete` (wszystkie źródła, także `found_after_merge`, odpowiedziały; okno zamyka się, `open: false`, dopiero po takim przebiegu; lista obcięta do limitu 200 issues albo 10 stron po 100 commitów (`reverts`: strony `page=1, 2, …` do pierwszej krótszej albo pustej; obcięta tylko przy dziesięciu pełnych stronach) też daje `complete: false`, a `trend` do tego czasu przelicza rekord), `checked_at`, `merge_commit`, `ci_red` (liczba czerwonych przebiegów `ci_workflow` ze zdarzeniem `push` na commicie merge'u; 0 przy zielonym), `reverts` (SHA commitów `main` w oknie, których pierwsza linia zaczyna się od `Revert`, a treść wymienia `#<numer końcowego PR>`), `issues` (numery issues `bug` z okna, których pole „Wprowadzone przez (epik albo PR, jeśli znane)” wskazuje ID epiku albo numer końcowego PR, założone albo ostatnio zmienione przez osobę z prawem zapisu: autor, a gdy go nie ma, ostatni edytor treści (`editor` z zapytania `gh api graphql`, tylko `query`, po jednym na issue, które wskazuje epik; bez `--repo` owner i nazwę ustala raz na proces `gh repo view --json owner,name` (błąd nie jest zapamiętany; podane `--repo` niepasujące do `OWNER/NAZWA` to błąd źródła z pozycją w `gaps`, nie pytanie o katalog roboczy), bo `gh api` nie podstawia `{owner}` i `{repo}` w polach `-f`) sprawdzani tym samym `gh api repos/{owner}/{repo}/collaborators/{login}/permission` (`write`, `maintain` albo `admin`); 404 to brak prawa, login niepasujący do wzorca loginu GitHuba nie jest sprawdzany; issues z etykietą `agent` nie liczą się tu), `count` (czerwone CI jako 1, plus reverty i issues; `null`, gdy któryś składnik nieznany). Składnik, którego źródło zawiodło, ma poprzednią zapisaną wartość albo `null`, z `gaps` |
| `found_after_merge` | `issues` i `count`: issues z etykietą `agent` utworzone w oknie, których sekcja „Źródło” ma linię `Przebieg `/sweep` <data>-<sha7>-<epik>` tego epiku (propozycje `/sweep`, osobno od regresji; etykietę `agent` ustawiają tylko osoby z prawem zapisu, więc bez sprawdzania autora) |
| `bottlenecks` | do `max_items` (3) pozycji o największej nadwyżce czasu ponad medianę historii, malejąco, nadwyżka co najmniej `min_excess_h` (0,5 h): `kind` (`breakdown`, `task`, `in_attempts`, `gate_wait`, `review`), `name`, `value_s`, `median_s`, `excess_h`, `share` i `share_of` (`to_done`, `gate_wait+to_done` albo `to_merge`), `rule` (klucz reguły decyzji: D2 dla zadania planu, D3 dla `gate_wait`, D4 dla `human_wait`, `ci_wait`, `limit_wait`, `in_attempts.ci_watch` i `review`, inaczej `null`), `estimated` (`true`, gdy wartość pozycji albo mediana, z której powstała nadwyżka, pochodzi z czasu niepełnego: `by_task.<zadanie>.incomplete`, `in_attempts.incomplete`, `breakdown_incomplete` albo `breakdown_upper_bound` w epiku lub w historii), `effect_estimated` (efekt: `estimated` pozycji albo `expected_effect.estimated` kandydata `decide`, z którego pochodzi `effect_h`), `repeat_h` (tylko `in_attempts`: ile z nadwyżki to `repeat_runs` tej kategorii, nie więcej niż nadwyżka). `effect_h` i `effect_rule`: potencjalny efekt optymalizacji (niżej, „Potencjalny efekt wąskich gardeł”) |
| `waste` | alarmy tego epiku z `<state>/waste.json` (jak w pliku: `key`, `rule`, `epic`, `task`, `attempt`, `at`, `push`, liczby); `[]`, gdy plik jest i nie ma alarmów epiku; `null` z pozycją w `gaps` (sam powód, bez ścieżki) bez pliku (komenda `waste` nie zapisała alarmów) albo gdy plik jest nieczytelny (`epic` nie kończy się wtedy błędem); zapisana wcześniej lista zostaje (zob. „Cache”) |
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
  Próba bez transkryptu nie ma komórek, więc może należeć do każdej roli i modelu: oznacza jako `estimated` i `incomplete`
  także `by_role`, `by_model` i `by_role_model` oraz role jej zadania w `by_task_role` (nie role innych zadań).
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

### Epik natywny: plik przebiegu, transkrypty sesji i cennik

Epik prowadzony przez główną sesję Claude Code (`docs/features/ash-v1/spec.md`, M1–M5). Szczegóły i tabela pól:
spec, „Epik natywny”. Katalog stanu (plik przebiegu i rekordy) wskazuje `--state` (albo `$SHOP_METRICS_STATE`), katalog
transkryptów `--projects`; pomiar epiku z przeszłości w osobnym katalogu stanu: `start` z `--at`, `--worktree`,
`--branch`, `--pr`, `--tasks`, `--session`, potem `done --at` i `epic`, każde z tym samym `--state`.

- **Plik przebiegu** `<state>/runs/<epik>.json`: `epic`, `started_at`, `base_sha` (`git rev-parse origin/main`, przy
  `--at` `git rev-list -1 --first-parent --before=<CZAS> origin/main`; błąd gita: `null` i ostrzeżenie na stderr),
  `branch` (`--branch` albo `git rev-parse --abbrev-ref HEAD` katalogu roboczego), `pr` (`--pr` albo `gh pr view
  <gałąź> --json number`), `tasks` (`--tasks` albo `night_queue.task_count` na
  `origin/main:docs/features/<epik>/plan.md`), `sessions` (`[{id, worktree, at}]`; `--session` albo najnowszy według
  czasu modyfikacji `<id>.jsonl` w `<projects>/<katalog roboczy z każdym znakiem innym niż litera i cyfra zamienionym na
  `-`>`), `usage_start`, `done_at`, `usage_done`, `process` (zob. niżej, „Wersja procesu”) i po `pause` `pauses`.
  Polecenia gita działają w `--worktree` (domyślnie bieżący katalog).
  Identyfikatory epiku i sesji: litery, cyfry, `.`, `_`, `-`, do 64 znaków.
- **Transkrypty:** dla każdej sesji z pliku przebiegu `<projects>/*/<id>.jsonl` i `<id>/subagents/agent-<agent>.jsonl`
  z `agent-<agent>.meta.json`; inne sesje nie wchodzą. Wiadomości od `started_at` do merge'u końcowego PR (z `gh`, a przy
  błędzie albo `--no-gh` z zapisanego rekordu; bez niego bez górnej granicy, pozycja `sessions` w `gaps`), każdy
  `message.id` raz (pierwsze wystąpienie w kolejności sesji, prowadzący przed subagentami). Wywołania narzędzi poza tym
  przedziałem nie wchodzą do `in_attempts`. Czytanie wierszami jak w transkryptach prób (`_read_line`), linia, która nie
  jest obiektem JSON, jest pomijana z pozycją `sessions` w `gaps` z ich liczbą (puste linie się nie liczą); transkrypt
  bez żadnej takiej linii jest nieczytelny; transkrypt bez wiadomości i linii w przedziale epiku nie daje wpisu.
  Transkrypt subagenta utracony (sam `meta.json`, plik pusty, uszkodzony albo nieczytelny, a także subagent
  uruchomiony w przedziale epiku przez sesję prowadzącą: wynik wywołania `Agent` z `toolUseResult.agentId`, bez
  żadnego pliku w katalogach sesji epiku) to pozycja `sessions` w `gaps` i pokrycie `partial`, jak brak transkryptu
  sesji.
- **Rola i zadanie:** prowadzący `orchestrator` bez zadania; subagent: rola `agentType`, zadanie to pierwsze
  `<EPIK>-<n>` w `description` (bez rozróżniania wielkości liter, zapisane wielkimi literami), bez niego `no_task`.
  `meta.json` brakujący albo nieczytelny daje `role`, `task`, `effort`, `finished` `null` i pozycję `attempts.<agent>`
  w `gaps`; brakujące pole tak samo dla tego pola. `description` nie trafia do rekordu. Pola `model` z `meta.json` nie
  czyta się (przebiegi uruchomione z domyślnymi ustawieniami go nie mają): `model` próby to najczęstszy model jej
  wiadomości, a gdy żadna go nie ma, `null` z pozycją `attempts.<agent>` w `gaps`.
- **Wartość:** suma po rodzajach tokenów wiadomości razy stawka `pricing.models.<model>` (USD za milion;
  `cache_write_5m` i `cache_write_1h` z `usage.cache_creation`). Każda komórka ma `estimated: true`, rekord ma
  `pricing` (`source`, `read_at`). Wartość nieznana (brak `message.usage`, `message.model`, stawek modelu, stawki
  użytego rodzaju albo rozbicia zapisu cache) trafia do przekrojów jako część nieznana (`usd: null` albo `incomplete:
  true`), a próba ma `value: null` (bez `message.usage` także `tokens: null`); powód w `gaps` (`value: sesja|agent <id>:
  …`). Wiadomość bez tokenów (np. model `<synthetic>`) jest pomijana.
- **Rozkład czasu i człowiek:** linie `user` i `assistant` z `timestamp` każdego transkryptu epiku, w kolejności czasu;
  przerwa przed linią asystenta albo `tool_result` to praca, przed linią z `origin.kind = "human"` czekanie na
  człowieka, przed inną linią użytkownika (raport subagenta `peer`, `task-notification`, wznowienie `coordinator`,
  linia meta) ani jedno, ani drugie. `breakdown` od `started_at` do `done_at`: `work` (unia pracy prowadzącego
  i subagentów), `human_wait` (przerwy przed wiadomością człowieka bez części pokrytej pracą), `idle` (reszta);
  `host_pause`: suma przerw z `pauses` (`native_pauses`: scalone, przycięte do `started_at`–`done_at`), które mają
  pierwszeństwo, więc ich czas nie wchodzi do `work`, `human_wait`, `idle` ani do `human.events[].wait_s`
  i `human.wait_s`; bez zapisanej przerwy `null` z pozycją w `gaps` (zły wpis przerwy: pozycja `pauses`);
  `limit_wait`, `ci_wait`, `github_wait` `null` (pozycje `breakdown.<kategoria>` w `gaps`),
  `breakdown_incomplete: true`; bez `started_at` albo `done_at` `breakdown: null`. `human`: `interventions`
  (wiadomości człowieka po `started_at` do merge'u końcowego PR, każda raz według `uuid`, `interventions_estimated:
  true`), `events` (`[{at, wait_s}]`, bez treści), `waits` (zdarzenia z `wait_s` > 0), `wait_s` (ich suma),
  `manual_fixes` (`--manual-fixes`; bez opcji `null` i pozycja `human.manual_fixes`). Żadna linia użytkownika sesji
  prowadzących bez pola `origin`: pola wiadomości człowieka i `breakdown.human_wait` `null` z pozycją w `gaps`.
- **Brak transkryptów** (`apply_transcript_coverage`): bez przeczytanego transkryptu żadnej sesji z pliku przebiegu
  pola z `TRANSCRIPT_PATHS` (`breakdown`, `reviews`, `integration`, pola zdarzeń `human`, `retries.fix`,
  `retries.failed_by_outcome`, `subscription.share`) są `null` z pozycją w `gaps`, a wartości zapisanego rekordu
  natywnego zostają, także `TRANSCRIPT_KEPT` (`attempts`, `by_task`, `value`, `in_attempts`, `versions`; pozycja
  „zachowano poprzednią wartość”); bez transkryptu części sesji albo subagentów (pokrycie `partial`) te pola oraz
  `attempts`, `by_task`, `value` i `in_attempts` mają pozycję w `gaps` o dolnej granicy. Rekord ma
  `transcripts_read` (przeczytane transkrypty sesji i subagentów); odświeżenie z mniejszą liczbą niż w zapisanym
  rekordzie natywnym zachowuje jego wartości tych pól i jego liczbę (`_keep_stored`, pozycja „zachowano poprzednią
  wartość”), a pozycja o dolnej granicy dotyczy tylko pól niezachowanych.
- **Przeglądy:** każde wywołanie `SubagentHandback` w transkrypcie roli `code-reviewer`, `qa-adversarial`,
  `architecture-reviewer`, `ux-reviewer` w przedziale epiku to wpis `reviews` `{task, role, agent_id, at, mode,
  verdict, blocking}`: `mode` `FULL` dla pierwszego raportu transkryptu, `DELTA` dla kolejnych; `verdict` to słowo
  werdyktu tuż po etykiecie „Werdykt”/„Verdict” (`verdict_token`; między nimi separatory, pogrubienie i jedno słowo
  `to`/`jest`/`is`): wygrywa pierwsza etykieta na początku linii (po znaczniku listy, nagłówka, cytatu lub pogrubienia),
  a gdy żadnej takiej nie ma, ostatnia etykieta w tekście poza cytatem (etykieta tuż po cudzysłowie albo odwróconym
  apostrofie się nie liczy); przyjęte tylko z kontraktu roli (`REVIEW_VERDICTS`),
  `blocking` dla `CHANGES_REQUIRED`, `BLOCK`, `REWORK`; bez rozpoznanego werdyktu (`ux-reviewer`) `null` i pozycja
  `reviews` w `gaps`. `integration`: `work_s`, `attempts` przebiegów `architecture-reviewer`, `qa-adversarial`,
  `ux-reviewer` i `rounds` (największa liczba raportów jednej z tych ról); bez nich `null`. `retries`: `fix` (przeglądy
  z zadaniem i werdyktem blokującym), `failed_by_outcome` (`{unfinished: n}` przebiegów z `finished: false`),
  `attempts`, `repair`, `sync_prs` `null`.
- **Kryteria akceptacji:** opis końcowego PR przez bramkę pochodzenia (`github_trust.Gate.prs(state='all',
  head=<gałąź>, fields=('body',))`, runner metryk przez `gate_runner`); w sekcji „Kryteria akceptacji” tabela `| Numer
  | Sposób | Wynik |`. `acceptance`: `{total, stack, ci, after_deploy, unverified}`. Autor bez prawa zapisu, brak PR,
  opisu, sekcji, tabeli albo wierszy i `--no-gh`: `null` z pozycją `acceptance` w `gaps`; błąd bramki także kod 3.
  Poprzednia wartość zostaje przy chwilowo niedostępnym źródle.
- **Subskrypcja:** bez `usage_start` albo `usage_done` `subscription: null`. Inaczej `windows` z `subscription_of`
  (`night_queue._window_deltas`; odczyty sprawdzane ponownie `night_queue.parse_usage`), `overlap: null`, `share`:
  tokeny epiku od `started_at` do `done_at` przez tokeny wszystkich wiadomości tego przedziału we wszystkich
  `<projects>/*/*.jsonl` i `<projects>/*/*/subagents/agent-*.jsonl` (każdy `message.id` raz, ważone `token_weights`;
  pliki zmodyfikowane przed `started_at` są pomijane), `share_estimated: true`.
- **Pola bez źródła:** `parallel`, `gate_wait`, `waste` (`brak źródła w przebiegu natywnym`) są `null` z pozycją
  w `gaps`.
- **Wersja procesu** (`process_version.py`, spec „Wersja procesu”): pole `process` pliku przebiegu i rekordu.
  `expected` (`ref`, `requested`, `describe`, `fingerprint`, `files`), `disk` (miejsca ładowania `worktree`
  i `main_checkout`: `path`, `head`, `branch`, `dirty`, `fingerprint`, `files`, `differs` ze stanem `changed`,
  `missing`, `extra`), `user_level` (`agents`, `skills`), `loaded` (`session`, `agents`, `skills`, wpisy ze `status`
  `confirmed`, `differs` albo `unconfirmed`, `version` `base`/`epic`/`null`, `origin`, `digest`, `reason`), `match`,
  `accepted`, `changes`, `status` (`checked`, `not_checked`, `check_failed`), `checked_at`, `checked_on`. Dowód
  załadowania: `instructions` i `nested_memory` (`CLAUDE.md`), `prompt_snapshot` (agent, pierwszy blok
  `systemPrompt` równy plikowi bez frontmatteru), linia `Base directory for this skill:` (skill, z argumentami
  w miejscu `$ARGUMENTS` i podobnych); ADR zawsze `unconfirmed`. Dowód sesji i przebiegi subagentów do merge'u
  końcowego PR (po czasie wpisu; bez czasu się liczy). `CLAUDE.md` wczytany z worktree sesji w treści innej niż baza
  (`EpicVersions`): wersja z commitów gałęzi epiku spoza bazy (`HEAD` worktree w kontroli, `origin/<branch>` albo
  `<branch>` w rekordzie) albo bieżący plik worktree to `confirmed` z `version: "epic"`; bez historii gałęzi
  `unconfirmed`; inaczej `differs`. Agenci i skille zawsze względem bazy. Rekord: `loaded` liczony od nowa
  z transkryptów epiku plus `summary` (z `confirmed_epic`) i `by_role`,
  `attempts[].instructions`, `disk_checked` oraz pozycje `process` w `gaps`. Plik przebiegu bez kontroli dysku (bez
  pola, `not_checked`, `check_failed`) z `base_sha` znanym w repozytorium `--worktree` (`unchecked_process`):
  `expected` z `base_sha` w chwili budowy rekordu, `disk: null`, `disk_checked: false`, `disk_reason`, `loaded` jak
  zwykle i jedna pozycja „dysk: nie sprawdzono” w `gaps`; plik przebiegu się nie zmienia; bez takiego `base_sha` pole
  jak w pliku przebiegu (bez niego `null`). `render` ma linię „Wersja procesu” (tylko rekord natywny; „dysk: nie
  sprawdzono” przy `disk_checked: false`; bez pola „brak danych”).
- **Prywatność:** rekord ma identyfikatory sesji i agentów, nazwy ról i modeli, werdykty oraz liczby; bez treści
  wiadomości, zleceń, raportów, opisu PR, poleceń i instrukcji (test: teksty z transkryptu i `meta.json` nie trafiają
  do wyjścia); ścieżki katalogów roboczych są tylko w `process` (pochodzenie instrukcji), a komentarz ich nie wypisuje.

### Wąskie gardła i historia

Kandydaci: kategorie `breakdown` poza `work` i kategorie `in_attempts` (nadwyżka = wartość − mediana na zadanie planu razy
liczba zadań planu tego epiku; udział w `to_done`), zadania (`work` zadania − mediana `work` zadań tego `kind`; udział
w `to_done`; kind bez historii nie jest kandydatem), `gate_wait` (udział w `gate_wait` + `to_done`; 0 bez czekania nie daje
pozycji, a brak wpisów w `gate.jsonl` pomija kandydata z pozycją `bottlenecks.gate_wait` w `gaps`) i `review` (udział w `to_merge`;
znany po merge'u, więc raport odbioru go pomija, a blok po merge'u ma). Historia to zapisane rekordy innych epików zmergowanych
przed tym (gdy ten nie ma czasu merge'u: wszystkie zmergowane) i tego samego źródła (`source`: epik natywny ma historię
tylko z epików natywnych, epik AO tylko z epików AO; to samo dotyczy `decide`); mediana ignoruje `null` i epiki bez zadań planu.
Zadania rekordu natywnego mają `kind: null`, więc nie są kandydatami wąskich gardeł, a `decide` pomija dla nich regułę D2
(z powodem w `skipped`); kategorie czasu, `review` i `in_attempts` działają jak dla AO. Przy mniej
niż `min_history` (5) epikach mediana jest z dostępnych, z pozycją `bottlenecks` w `gaps`; bez historii lista jest pusta.
Rekord liczy wąskie gardła przy zapisie (`epic`, `backfill`), więc rekord starego epiku zapisany przed jego poprzednikami ma
je z mniejszej historii: `epic <epik>` liczy je od nowa. Szczegóły i progi: `config.json`, klucz `bottlenecks`.

#### Potencjalny efekt wąskich gardeł

Każda pozycja `bottlenecks` ma `effect_h` (godziny) i `effect_rule`. Gdy `decide` dla tego epiku ma kandydata reguły
o tym samym przedmiocie, `effect_h` to jego oczekiwane skrócenie czasu zegarowego, a `effect_rule` jego klucz
(`human_wait`, `limit_wait` → `D4:human_wait`, `D4:limit_wait`; `ci_wait` i `in_attempts.ci_watch` → `D4:ci`;
`gate_wait` → `D3:gate_wait`; `review` → `D4:review`; zadanie planu → `D2:plan_split`, z jego własnym czasem ponad
medianę). Bez takiego kandydata (reguła nie zadziałała, przedmiot bez reguły) `effect_h` to nadwyżka jako górna granica,
a `effect_rule` to `null`, czyli „bez reguły decyzji”. `ci_wait` i `ci_watch` mają ten sam efekt (czas czekania na CI
łącznie), więc efektów pozycji nie należy sumować. `render --format report` pokazuje to obok kosztu czasowego:
`1. `human_wait`: +6,2 h ponad medianę (22% czasu), potencjalnie −4,1 h (D4)` albo `… potencjalnie −X h (bez reguły
decyzji)`; render wypisuje tylko liczbę i nazwę reguły z kodu (`D1`–`D9`), a rekord bez `effect_h` w `bottlenecks` ma
`, reguła D4`. Pozycja z `estimated: true` ma w renderze „≈ ” przed każdą liczbą (`1. `P-3`: ≈ +4,5 h ponad medianę (≈
12% czasu), potencjalnie ≈ −4,5 h (D2)`); przy samym `effect_estimated` „≈ ” ma tylko część „potencjalnie”.

### Brakujące źródła i pola rekordu

Brak źródła nie zatrzymuje pomiaru, a pole bez danych jest `null` z powodem w `gaps`, nigdy zerem:

- Kolumna, której nie ma w bazie AO (albo tabela), daje pozycję `tabela.kolumna: brak kolumny albo tabeli w bazie AO`
  (brak tabeli to brak wszystkich jej kolumn), a pola od niej zależne są `null` (pusta kolekcja jest tylko tam, gdzie
  lista po prostu nie ma elementów), nie zero ani klucz `null`:
  - bez `tasks.kind`: `tasks`, `retries.repair`, `retries.fix`, `integration.work_s`, `integration.attempts`
    i `parallel.repairs`; `decide` pomija D2 (rodzaje zadań nieznane);
  - bez `tasks.task_id`: `retries.repair`, `integration.work_s`, `integration.attempts`, `parallel.repairs`
    i `breakdown.github_wait`;
  - bez `attempts.task_id`: `by_task`, `value.by_task`, `value.by_task_role` (`decide` pomija D2 z powodem
    `brak danych: by_task`), `retries.attempts.per_task`, `integration.work_s`, `integration.attempts`
    i `parallel.repairs`; próby w `attempts` mają `task: null`, a bez zadania i numeru próby nie wiadomo, gdzie leży
    jej transkrypt (pozycja `attempts.transcript`, wartość prób `null`);
  - bez `attempts.number`: tylko transkrypty prób nie są znajdowane (jak wyżej);
  - bez `attempts.started_at` albo `attempts.ended_at`: `breakdown.work`, `parallel.repairs` i `subscription.share`
    (przy epiku nakładającym się z innym; `share` bez nakładania to nadal 1);
  - bez `attempts.wall_s` i bez początku albo końca prób: czas prób, czyli `by_task.<zadanie>.work` i
    `integration.work_s` (`integration.attempts` zostaje); sam brak `wall_s` nie przeszkadza, bo czas to koniec minus
    początek;
  - bez `attempts.outcome` albo `attempts.executor_status`: klasy prób w `retries.attempts` (`successful`, `blocked`,
    `failed`, `usage_limit`, `running`, `unclassified`) i `retries.failed_by_outcome`;
  - bez `events.data`: `breakdown.limit_wait`, `breakdown.host_pause` i `breakdown.github_wait`; bez `events.task_id`:
    `breakdown.github_wait`; bez `events.type`, `events.at` albo `events.data`: przyczyny REPAIR w `retries.repair`
    (`final_pr_conflict` i `ci_red` to `null`, `unknown` to wszystkie), a bez `events.type` albo `events.at` także
    cały `breakdown` (brak `epic_done`);
  - bez `epics.round`: `integration.rounds`;
  - bez tabeli `attempts`: wszystko powyższe z `attempts.*` oraz `retries.attempts.total`; przy braku samej kolumny
    `retries.attempts.total` zostaje (liczy wiersze tabeli).

  Kategoria rozkładu czasu bez źródła ma w `gaps` pozycję `breakdown.<kategoria>`: jej czas jest w innych kategoriach
  i w `idle` (tak samo `human_wait` bez `events.jsonl`).
  Przedział pominięty dla braku danych (`cooldown_set` bez `until` i `cooldown_released`, `host_pause` bez `from`/`to` i
  `seconds`, próba bez `started_at`; pozycja `breakdown.limit_wait`, `breakdown.host_pause`, `breakdown.work` w `gaps`)
  czyni kategorię dolną granicą, a każdą kategorię po najwcześniejszej pominiętej w `breakdown_order` i `idle` górną
  (pominięty czas trafia do pierwszej dalszej kategorii, która pokrywa przedział): rekord ma `breakdown_incomplete`
  (dolne granice) i `breakdown_upper_bound` (górne; listy kategorii, brak pól, gdy rozkład jest pełny). `render` pokazuje je
  jako `limit_wait ≈ 1,0 h (≈ 10%, dolna granica)` i `ci_wait ≈ 2,0 h (≈ 20%, górna granica)`. Oznaczenie dziedziczą
  wąskie gardła kategorii (`estimated`), kolumny `work` i `human_wait` w `trend`, D4 `human_wait`, `limit_wait` i `ci`
  (`ci_wait` i mianownik `work`) oraz okna `effect` `D4:human_wait`, `D4:limit_wait` i `D4:ci`. Tak samo dziedziczą
  oznaczenie `breakdown_estimated` (rozkład epiku zamkniętego ręcznie, do merge'u), które `render` pokazuje jako
  `Czas do epic_done: brak danych (epik zamknięty ręcznie); rozkład do merge'u (16,7 h): work ≈ 8,4 h (≈ 51%), …`.

  Komenda `waste` pomija reguły, którym brakuje kolumny, z pozycją w `gaps` wyjścia (`W1`–`W4`, osobno części
  `W3`): W1 i W4 potrzebują `attempts.ended_at`, `attempts.task_id`, `attempts.number`, `tasks.kind` i `tasks.task_id`;
  W2 do tego `attempts.started_at` (oraz `epics.status` albo `events.type`, żeby wiedzieć, czy przebieg trwa); W3 (pętla
  prób nieudanych) `attempts.outcome`, `attempts.executor_status`, `attempts.ended_at`, `attempts.task_id`,
  `attempts.number`; W3 (REPAIR bez zielonego CI)
  `tasks.kind`, `tasks.task_id`, `attempts.task_id`, `attempts.started_at` i `events.type`, `events.at`,
  `events.task_id`, `events.data`; W3 (runda QA) `epics.round`. `null` w istniejącej kolumnie to zwykły brak wartości,
  np. próba bez `outcome` po końcu przebiegu jest nieudana (`no_outcome`). Render pisze „brak danych” (`REPAIR`,
  `FIX`, `Integracja`, `Próby`, podział prób; przy nieznanych przyczynach `REPAIR: N (przyczyna nieznana)`), `decide`
  pomija w D2 kryterium nieudanych prób z pozycją w `gaps`, a `trend` ma `null` w kolumnach `repair` i `failed`.
- Rekord bez `waste` albo bez `waste.json` ma `waste: null` z pozycją w `gaps`, a render pokazuje „Alarmy (waste):
  brak danych”.
- Rekord bez `value.by_task_role` albo bez `effect_h` w `bottlenecks` (zapisany w innej wersji skryptu): `effect` pomija
  go w mierze D1 z pozycją w `gaps`, a przelicza go `epic <epik>`.

### Decyzje optymalizacyjne (`decide`)

`decide EPIK` czyta zapisany rekord epiku, historię (rekordy epików zmergowanych przed nim, jak w wąskich gardłach)
i, dla D1, wyniki EVALS 2.0. Wypisuje jeden obiekt JSON: `epic`, `candidates` (wszyscy kandydaci), `report` (pierwsze
`decisions.report_max`, 3: część do raportu odbioru), `skipped` (`[{key, reason, data?}]`: reguły pominięte z przyczyną,
nigdy kandydaci) i `gaps`. Kandydat to reguła, która zadziałała i ma propozycję:

| Pole | Treść |
|---|---|
| `key` | reguła i przedmiot, ten sam dla tej samej reguły i przedmiotu w każdym epiku: `D1:<rola>`, `D2:plan_split`, `D3:gate_wait`, `D4:human_wait`, `D4:ci`, `D4:limit_wait`, `D4:review` |
| `rule`, `data` | `D1`–`D4` i liczby reguły (sekundy, udziały, progi); wartości szacowane lub niepełne są w `data.estimated` (D1: `role_value`, `epic_total`; D2: `work`; D4 `limit_wait`: `limit_wait`; D4 `ci`: `in_attempts.ci_watch`, `work`; każdy kandydat ma listę, pustą gdy dokładne) |
| `proposal` | propozycja po polsku; D1 to tylko eksperyment porównania wariantów w EVALS 2.0, nigdy zmiana modelu |
| `expected_effect` | `wall_h` (skrócenie czasu zegarowego w godzinach albo `null`), `value_usd` (wartość tokenów w USD albo `null`), `estimated` |
| `verification` | miara reguły i komenda `effect` z kluczem |

Kolejność: `wall_h` malejąco, potem `value_usd` malejąco (nieznane to 0), potem klucz. Progi (`config.json`, `decisions`):

- **D1 model dla roli:** rola agenta ma co najmniej `d1_role_share` (15%) wartości tokenów epiku (`value.total`) na modelach
  droższych niż najtańszy używany w epiku. Stawki to mediany `model_rates` prób z wynikiem CLI ze wszystkich zapisanych
  rekordów (te same co w wycenie prób bez wyniku CLI); model bez stawki jest pominięty z pozycją w `gaps`, a przy mniej niż
  dwóch modelach ze stawką D1 nie działa (`skipped` z kluczem `D1`). Role nie będące agentami (`delivery`, `integration`,
  `other`, `subagent`: orkiestratory i reszta) nie są oceniane. Rola spoza `evals.roles` jest w `skipped` z przyczyną
  „bez evali” (bez czytania katalogu wyników). Dla roli z listy `decide` czyta z `<evals.results_dir>/*/runs/*/meta.json`
  (katalog z `~` jest rozwijany) **tylko** pola `status`, `mode` i `subagent_model` (nic innego z pliku, żadnych etykiet ani
  raportów; do wyniku nie trafia tekst przebiegu ani ścieżka, tylko nazwy podkatalogów pomiarów). Zaliczany przebieg to
  `status` `ok` i `mode` `replay`; `squash`, `infra_error`, `budget_stop` i każdy inny status nie są zaliczane. Przebieg
  należy do jedynej roli z `evals.roles` (`meta.json` nie wskazuje agenta), więc przy liście dłuższej niż jedna rola
  przebiegów nie da się przypisać (`skipped`). Wynik: brak zaliczanego przebiegu na najtańszym modelu epiku (porównanie
  dokładne z identyfikatorem modelu) to kandydat z propozycją eksperymentu porównania wariantów; są takie przebiegi to
  `skipped` z przyczyną „porównanie w EVALS 2.0, decyzja w jego raporcie” i `data` (`runs`, `measurements`). Brak katalogu
  albo czytelnego `meta.json` to `gaps` i `skipped` („brak wyników EVALS 2.0”); nieczytelny plik, plik bez `status` i `mode`
  albo przebieg `ok` w trybie `replay` bez `subagent_model` jest pomijany i liczony w `gaps`. Efekt: `value_usd` = wartość
  roli na każdym droższym modelu × (1 − stawka najtańszego / stawka tego modelu); wartość szacowana jest zaznaczona w
  `data.estimated`, w `expected_effect.estimated` i w propozycji, gdy szacowana albo niepełna jest komórka roli na
  droższym modelu albo gdy taka komórka nie ma wartości (`usd` `null`: wartość roli to dolna granica); w
  `data.estimated` jest wtedy `role_value`. Szacowana lub niepełna suma epiku (`value.total`, z której liczy się
  `share`) daje `epic_total` w `data.estimated` i zdanie w propozycji, ale nie `expected_effect.estimated`: `value_usd`
  powstaje tylko z komórek roli.
- **D2 podział planu:** zadanie planu z `work` > `d2_work_multiple` (2) × mediana `work` zadań planu w historii albo z co
  najmniej `d2_failed_attempts` (2) próbami nieudanymi (klasa `failed`, `blocked` i `usage_limit` się nie liczą; próby
  z `attempts` rekordu). Klucz jest jeden na epik, `D2:plan_split` (zadanie ma w każdym epiku inne ID, więc przedmiotem
  klucza jest sama reguła; zadania są w `data.tasks`). `wall_h` = suma czasu zadań ponad medianę (zadanie z samymi
  nieudanymi próbami dodaje 0). Bez historii kryterium czasu jest pominięte z pozycją w `gaps`. Zadanie z `incomplete` w
  `by_task` albo mediana z historii z takim zadaniem planu daje `estimated: true` zadania w `data.tasks`, w `data.estimated`
  (`["work"]`) i w `expected_effect.estimated`.
- **D3 kolejka pracy równoległej:** `gate_wait.wait_s` ≥ `d3_gate_wait_h` (1 h) z powodu reguły plików bramki. `gate_wait`
  liczy się od decyzji o starcie (pierwszy wpis `gate --dir <epik>` w `gate.jsonl`, `night_queue.append_gate_log`),
  a `reasons` to powody wpisów `waiting` epiku po niej. Reguła plików (`night_queue.check_files`, kroki 4 i 5) zapisuje wpis `waiting` tylko jako WAIT, gdy zakres epiku
  w toku jest nieczytelny: powód `<epik> w toku bez zakresu: …` (`MissingScope`); to jedyny powód, który `FILE_RULE_REASON`
  uznaje za regułę plików. Pominięcie (SKIP) przez wspólne pliki (`wspólne pliki z <epik>: …`) ma `waiting: null`, więc nie
  jest czekaniem epiku w rozumieniu specyfikacji, nie trafia do `gate_wait.reasons` i D3 go nie widzi. Inne powody WAIT
  (`dwa epiki aktywne`, `pamięć Dockera`, `stan limitów`, `równoległość wstrzymana`, `brak pomiaru`, okna limitów) to nie reguła
  plików: wtedy `skipped`. `wall_h` = `wait_s`.
- **D4 czekanie:** `human_wait` ≥ `d4_human_wait_share` (20%) `to_done`, `ci_wait` + `in_attempts.ci_watch` ≥ `d4_ci_share`
  (20%) `work`, `limit_wait` ≥ `d4_limit_wait_share` (10%) `to_done`, `review` ≥ `d4_review_share` (20%) `to_merge`
  (tylko po merge'u; przed nim `skipped`). `wall_h` = udział × mianownik (dla `human_wait` i `limit_wait` udział × `to_done`, dla
  `review` udział × `to_merge`); dla `ci` czas `ci_wait` + `ci_watch` (oszczędność nie przekracza czasu czekania); `ci` ma
  `data.estimated` `["in_attempts.ci_watch"]` i `expected_effect.estimated: true`, gdy `in_attempts.incomplete`. Znane
  źródło `null` (brak `to_done`, `work`, `in_attempts`) to `skipped` z nazwą brakującego pola, nie zero.

Progi są włącznie (dokładnie próg alarmuje, tuż poniżej milczy), z wyjątkiem D2 (ostro większe niż wielokrotność mediany).
Reguła poniżej progu nie ma wpisu nigdzie.

`--history` dodaje `history` (`[{epic, candidates, skipped, gaps}]` dla każdego zmergowanego epiku w kolejności merge'u, każdy
liczony z własną historią, czyli epikami zmergowanymi przed nim) i `keys` (`{klucz: [epiki, w których reguła zadziałała]}`:
liczba epików reguły dla raportu odbioru i progu „dwóch incydentów” w `/retro`). Epik z argumentu nie musi być zmergowany.
Katalog wyników EVALS 2.0 jest czytany raz na wywołanie.

### Efekt decyzji (`effect`)

`effect KLUCZ --at CZAS` liczy miarę reguły z klucza z zapisanych rekordów (nic nie zapisuje):

| Reguła | Miara | Poziom | Jednostka |
|---|---|---|---|
| `D1:<rola>` | wartość roli w zadaniu (`value.by_task_role`; 0, gdy rola w zadaniu nie działała; pominięte jest zadanie bez znanej wartości oraz zadanie, w którym rola działała, ale jej wartość jest nieznana, `usd` `null`) | zadania `kind` roli | USD |
| `D2:plan_split` | `work` zadania planu | zadania `plan` | h |
| `D3:gate_wait` | `gate_wait.wait_s` | epik | h |
| `D4:<human_wait\|ci\|limit_wait\|review>` | udział kategorii w jej mianowniku (jak w `decide`) | epik | pp |

Okna liczy się z rekordów jednego źródła (`ao` albo `native`, jak mediany `trend`): źródła większości epików
rozpoczętych po `CZAS` (przy remisie najwcześniej rozpoczętego; bez takich epików źródła ostatnio zmergowanego epiku).
Rekordy drugiego źródła nie wchodzą do żadnego okna, do `epic_time`, `spanning` ani `unmerged_after`; ich liczba i nazwy
są pozycją w `gaps`. Kształt wyniku się nie zmienia.
W oknach są tylko epiki zmergowane. Okno „po” to epiki z `started_at` > `CZAS` (ściśle), uporządkowane po starcie. Okno „przed” to
tyle samo epików zmergowanych najpóźniej w `CZAS` (włącznie), najbliższych tej chwili; gdy takich epików jest mniej niż w oknie „po”, okno „przed” ma
wszystkie, ma `estimated: true` i pozycję w `gaps` („okna nie są w pełni porównywalne”). Epik rozpoczęty w `CZAS` lub przed
nim i zmergowany po nim nie jest w żadnym oknie (`spanning`); epik rozpoczęty po `CZAS`, a niezmergowany, też (`unmerged_after`).
Dla D1 poziom to `kind` zadań (`level_kind`), w którym rola ma największą łączną wartość w obu oknach (rozstrzygnięcie
wykonawcy: spec mówi „zadania tego `kind`” bez wskazania, który; bez wartości roli `level_kind` to `null`); rekord bez
`value.by_task_role` jest pomijany z pozycją w `gaps`, nie liczony jako zero. D2 i `D4:ci` mają okno `estimated: true`, gdy
zadanie planu ma `by_task.<zadanie>.incomplete` (D2) albo epik ma `in_attempts.incomplete` (`D4:ci`).

Wynik (JSON): `before` i `after` (`epics`: epiki z pomiarem, `n`: próbki (zadania albo epiki), `n_epics`, `median`, `p25`,
`p75`; kwartyle metodą interpolacji liniowej między rangami; `estimated`: `true`, gdy któraś użyta próbka pochodzi
z komórki `value.by_task_role` z `estimated: true` albo `incomplete: true`, co w `decide` też oznacza wartość szacowaną, albo
z zadania, którego `value.by_task` jest `incomplete` albo nieznane (rola mogła mieć brakującą część), albo gdy
pominięto zadanie, w którym rola działała z nieznaną wartością (`usd` `null`), także gdy przez to epik nie ma żadnej próbki (jest pominięty, z pozycją w `gaps`): okno jest wtedy niepełne; tylko
D1, D2 (`work` zadania planu z `incomplete`), `D4:ci` (`in_attempts.incomplete` albo granica rozkładu czasu),
`D4:human_wait` i `D4:limit_wait` (granica rozkładu czasu) oraz okno „przed” krótsze niż „po”; pozostałe miary są
dokładne i mają `false`), `estimated` (na poziomie wyniku: `true`, gdy szacowane jest któreś okno;
dotyczy wtedy też `diff`; liczby szacowane są pokazywane z „≈”), `diff` (`value` w jednostce miary i `pct` w procentach różnicy
median; `pct` `null` przy medianie „przed” równej 0), `message` (`za mało danych` i wtedy `diff` `null`, a `reasons` mówi
dlaczego), `epic_time` (czas do `epic_done` na zadanie planu w godzinach: mediana trzech epików zmergowanych najbliżej
przed `CZAS`, niezależnie od liczebności okna, i trzech pierwszych epików okna po), `versions` (`cli` i `models`:
`added` i `removed` między oknami; okno bez `versions` to brak porównania z pozycją w `gaps`), `spanning`, `unmerged_after`,
`gaps`. „Za mało danych”: miary zadań (D1, D2) przy mniej niż `effect_min_tasks` (15) zadaniach albo mniej niż
`effect_min_task_epics` (3) epikach po zmianie; miary epiku (D3, D4) przy mniej niż `effect_min_epics` (3) epikach po zmianie;
także gdy okno „przed” nie ma pomiarów. Liczby okien są wtedy wypisane, bez wniosku. Zły klucz, zły `--at` (bez przesunięcia UTC,
zły format), brak zapisanych rekordów zmergowanych epików albo zły `config.json` kończą komendę kodem `2` z komunikatem.

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
| W4 koszt zadania | suma `value` **zakończonych** prób zadania (trwającej się nie dolicza; zadanie, w którym zakończona próba nie ma wartości, jest pomijane z pozycją w `gaps`) > `cost_multiple` × mediana tej sumy po zadaniach tego `kind` w historii (zadania, w których wszystkie zakończone próby mają wartość) **i** ≥ `cost_min_usd`; raz na zadanie; suma z próbą szacowaną (bez wyniku CLI) ma w `push` „≈”, a w alarmie `estimated: true`; zadanie z trwającą próbą ma w alarmie `incomplete: true`, a `push` mówi „zakończone próby kosztują” zamiast „kosztuje łącznie” (suma to dolna granica) | `W4:<epik>:<zadanie>` |

Alarm kosztowy wymaga obu warunków (dokładnie 3× mediany nie alarmuje, dokładnie 5 USD alarmuje), czasowy też (dokładnie
3× mediany i dokładnie 1 h nie alarmują). Mediana zerowa nie ma ilorazu (`ratio: null`, `push` bez „×”). Iloraz „N× mediany” ma w `push` „≈”, gdy kwota jest szacowana
(`estimated`) albo mediana pochodzi choć z jednej wartości szacowanej historii (`median_estimated: true` w alarmie W1 i W4;
historia niesie `estimated` prób zakończonych, reguła zachowawcza jak w `trend`). Zadanie historii z próbą bez `ended_at`
nie jest pomiarem W4 (suma byłaby częściowa).

Wyjście: jedna linia JSON na nowy alarm, np. `{"alarm": "W2", "key": "W2:deploy-ops:DEPLOY-OPS-4:3", "epic":
"deploy-ops", "task": "DEPLOY-OPS-4", "attempt": 3, "push": "deploy-ops: DEPLOY-OPS-4 próba 3 trwa 4,1 h (3,2× mediany)",
"seconds": 14760, "median": 4600, "ratio": 3.2}`. `push` ma najwyżej 200 znaków, liczby z przecinkiem dziesiętnym,
a z danych przebiegu tylko identyfikatory epiku i zadania (`[A-Za-z0-9._-]`, do 64 znaków, inaczej `?`) i liczby.

`<state>/waste.json` (`{"alarms": [{"key", "rule", "epic", "task", "attempt", "at", "push", ...liczby}]}`) i
`<state>/waste.lock`: odczyt pliku, wybór nowych kluczy, zapis atomowy (plik tymczasowy i `os.replace`) i wypisanie
dzieją się pod wyłącznym `flock` na `waste.lock`, więc dwie sesje wypisują każdy alarm raz. Zapis jest przed
wypisaniem: nieudany zapis nic nie wypisuje, a awaria wypisywania po zapisie gubi alarm, ale go nie duplikuje (alarm nigdy
dwa razy). Brak `waste.json` to pusty stan, a udane wywołanie bez nowych alarmów tworzy `{"alarms": []}` (pod tą samą blokadą), żeby rekord pokazywał `[]`, nie `null`; plik nieczytelny albo w innym kształcie kończy komendę kodem `2` bez zmiany
pliku (po usunięciu przyczyny wysłane alarmy nie wracają). Zajęta żywa baza: `busy_timeout`, potem ponowienia
(`DB_RETRIES` = 2, przerwa 0,5 s), na końcu kod `2` i komunikat `zablokowana`; nic nie jest wtedy zapisane.

### Cache

Rekord zapisuje się w `<state>/epics/<epik>.json`. Przejściowy błąd źródła nigdy nie zastępuje wartości niepustej
wartością `null`: dla `issue`, `merged_at`, `wall.to_merge`, `wall.review`, `retries.sync_prs`, `parallel.sync_prs`,
`human.manual_fixes`, `ci`, `regressions`, `found_after_merge` i `waste` (błąd `gh`, `--no-gh`, brak `--manual-fixes`,
brak albo nieczytelny `waste.json`) zostaje poprzednia wartość (składniki `regressions` osobno), a w `gaps` jest
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
| `waste` | progi alarmów `waste` |
| `decisions` | progi reguł `decide` (`d1_role_share`, `d2_work_multiple`, `d2_failed_attempts`, `d3_gate_wait_h`, `d4_*_share`; udział to ułamek z przedziału (0, 1]), `report_max` (kandydaci w części raportowej) i progi `effect` (`effect_min_tasks`, `effect_min_task_epics`, `effect_min_epics`; liczby całkowite od 1); złe wartości kończą komendę kodem 2 z nazwą klucza |
| `evals` | `roles` (role objęte EVALS 2.0: dziś `code-reviewer`) i `results_dir` (katalog wyników, z `~`) dla D1 |
| `regression_window_days`, `trend_last` | okno regresji (14 dni), domyślna liczba epików w `trend` (10) |
| `ci_workflow`, `ci_jobs` | plik workflowu CI (`ci.yml`) i grupy zadań, których czas liczy `ci` (`checks`, `e2e`: zadania o tej nazwie i `<nazwa> …`) |
| `weekly_window_label` | etykieta okna limitu z `get_usage` brana do kolumny `pp` w `trend` (`Weekly · all models`) |
| `pricing` | cennik API wartości epiku natywnego: `source` (strona cennika), `read_at` (data odczytu, `RRRR-MM-DD`), `models` (`{model: {input, output, cache_read, cache_write_5m, cache_write_1h}}`, USD za milion tokenów, liczby nieujemne; rodzaju może brakować). Dziś `claude-fable-5-2`, `claude-opus-5-5` i `claude-sonnet-5-5` ze wszystkimi pięcioma stawkami (odczyt 2026-11-19); test sprawdza też, że model każdej definicji agenta (`.claude/agents/*.md`, np. `opus`) ma stawki. Zmiana cennika to edycja tych liczb, źródła i daty |

## Testy i fixture'y

```
python3 -m unittest discover -s tools/metrics -p 'test_*.py'
```

CI uruchamia je przez `.github/scripts/script-tests.sh`. Testy nie wołają prawdziwego `gh` ani sieci (`gh` jest
podstawiany funkcją `run`, a test procesu używa atrapy na `PATH`) i nie dotykają prawdziwego `~/.local/state`
(`HOME` wskazuje katalog tymczasowy). `testkit.py` buduje katalog `ao-<epik>` z bazą z zrzutu SQL albo syntetyczną.

`fixtures/ao-data/` to oczyszczony pakiet danych przebiegów DEPLOY-OPS i CATALOG-ADMIN (zrzuty SQL tabel `epics`,
`tasks`, `attempts`, `events`, `events.jsonl`, `measurements.jsonl`, `gate.jsonl`): pola z listy dozwolonych, bez
tekstów, ścieżek bezwzględnych i poleceń.
`fixtures/transcripts/<epik>/tasks/<zadanie>/attempts/<n>/stdout.jsonl` to oczyszczone transkrypty prób (wszystkie próby
DEPLOY-OPS, CATALOG-ADMIN-INTEGRATION-1 i -2, CATALOG-ADMIN-4, ADMIN-DESIGN-3): pola z listy dozwolonych,
polecenia powłoki zastąpione kanonicznym poleceniem kategorii z numerem `# c<n>` (taki sam numer = ten sam klucz
`repeat_runs`). `testkit.py` ma też `Transcript`, który buduje syntetyczny transkrypt.
Epik natywny (`test_native.py`, `test_native_process.py`) ma syntetyczne transkrypty sesji budowane w teście
(`testkit.Native` z `human`, `notice`, `handback`, `NativeEnv`:
katalog projektów i plik przebiegu w katalogu tymczasowym), z samymi czytanymi polami; prawdziwe transkrypty nie trafiają
do repozytorium. `test_process_version.py` buduje w katalogu tymczasowym własne repozytorium git (główny checkout
z tagiem `ash-v1.0` i worktree w `.claude/worktrees/epic`) oraz transkrypty z wpisami `instructions`, `nested_memory`,
`prompt_snapshot` i wczytaniem skilla; `test_pause.py` sprawdza `pause` i `host_pause`.
`fixture_scan.py` (test: `test_fixture_scan.py`) sprawdza każdy plik pod `fixtures/`: brak ścieżek bezwzględnych
(`/Users/`, `/private/`, `~`), adresów e-mail, wzorców tokenów (`ghp_`, `gho_`, `github_pat_`, `sk-`, `Bearer`,
`Basic`), ciągów base64 od 32 znaków (poza SHA i UUID; ciąg musi mieć cyfrę albo mieszane litery, a ścieżka ze samych
słów jest dozwolona) i wartości tekstowych dłuższych niż 120 znaków. Jedyny wyjątek, tylko od reguły base64: nazwy kluczy
`cacheCreationEphemeral1hInputTokens` i `cacheCreationEphemeral5mInputTokens` w obiekcie modelu najwyższego poziomu
`modelUsage` linii JSON (`modelUsage.<model>.<klucz>`, kształt wyniku CLI w transkrypcie). Ta sama nazwa jako wartość, jako
klucz gdzie indziej, w pliku nie-JSON albo w surowym tekście SQL jest wykrywana, tak samo jak base64 będący wartością tych
kluczy; pozostałe reguły działają na nich jak na każdym kluczu. Nowe fixture'y (np. transkrypty) muszą
przejść ten skan.
