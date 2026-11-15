# METRICS: pomiar, koszty i optymalizacja przebiegów

Krok 18 planu Agentic Software House (`docs/features/agentic-software-house/plan.md`), komponent „Metryki i evale”
ADR `docs/adr/agentic-software-house.md`. Epik daje każdemu przebiegowi epiku przez AO pełny, powtarzalny pomiar
(czas zegarowy i jego rozkład, wartość tokenów i koszt subskrypcji, ponowienia, naprawy, konflikty, testy,
interwencje, regresje po merge'u), pokazuje wąskie gardła czasu zegarowego, wykrywa bezproduktywne zużycie w trakcie
przebiegu z pushem i kończy raport odbioru oraz `/retro` decyzjami optymalizacyjnymi z mierzonym efektem. Telemetria
Claude Code w Grafana Cloud to osobny epik TELEMETRY (#577, część 2 kroku 18, po AGENT-SECURITY); METRICS od niej
nie zależy.

Issues: #496
Zakres: `tools/metrics`, `.claude/skills/ao-run`, `.claude/skills/retro`, `.claude/agents/retro-analyst.md`, `.github/ISSUE_TEMPLATE/bug.yml`, `docs/features/ao-run/spec.md`, `docs/features/retro/spec.md`, `docs/adr/agentic-software-house.md`, `docs/features/agentic-software-house/plan.md`

## Cel i kryteria sukcesu

- Priorytetem jest skrócenie rzeczywistego czasu realizacji epików, nie zbieranie większej liczby metryk. Każda
  liczba w raporcie służy jednemu z trzech celów: pokazać wąskie gardło czasu zegarowego, wykryć marnotrawstwo albo
  zmierzyć efekt decyzji optymalizacyjnej.
- Optymalizujemy czas zegarowy epiku (miara główna), pilnując jakości, napraw, interwencji człowieka i zużycia
  limitów. Każdy epik ma te liczby w jednym formacie, policzone skryptem z danych przebiegu, a nie ręcznie.
- Raport każdego epiku wskazuje konkretne wąskie gardła: najwyżej trzy pozycje (kategoria czasu, zadanie albo rodzaj
  poleceń w próbach) z największą nadwyżką czasu ponad medianę historii, w godzinach.
- Wartość tokenów według cen API (szacunek CLI) i faktyczny koszt subskrypcji (punkty procentowe okien limitu) są
  zawsze pokazane osobno i nigdy nie są sumowane.
- Bezproduktywne zużycie (zadanie zużywa wielokrotność mediany, pętla prób) jest widoczne w trakcie przebiegu jako
  push, a nie dopiero rano w raporcie.
- Raport odbioru i raport `/retro` kończą się konkretnymi decyzjami optymalizacyjnymi: co zmienić, oczekiwany efekt
  w liczbach, dane i miara, po której kolejne epiki pokażą, czy zmiana zadziałała. Decyzję podejmuje użytkownik.
- Efekt zastosowanej decyzji jest mierzony: mediana czasu zegarowego na zadanie planu (i miary samej decyzji) przed
  i po merge'u zmiany.
- Trend obejmuje wszystkie zmergowane epiki z danymi AO, więc pierwszy raport po wdrożeniu ma już tło porównawcze.
- Rozwiązanie jest przenośne: `tools/metrics` czyta dane AO, Claude Code i GitHuba, nie sklepu.

Sukces epiku: raport odbioru pierwszego epiku po merge'u ma sekcje „Pomiar” (z wąskimi gardłami) i „Decyzje
optymalizacyjne”, a `metrics.py trend` pokazuje wszystkie zmergowane epiki.

## Decyzje użytkownika

Zatwierdzone 2026-11-15 (rekomendacje przyjęte w całości, z doprecyzowaniami):

- Q0. Krok 18 podzielony na dwa niezależne epiki: METRICS (ta specyfikacja: pomiar, wąskie gardła, alarmy, decyzje,
  raport, `/retro`) i TELEMETRY (#577: telemetria Claude Code w Grafana Cloud, po AGENT-SECURITY). METRICS nie
  czeka na telemetrię ani na AGENT-SECURITY.
- Q1. Decyzje optymalizacyjne przechodzą istniejący cykl `/retro` (próg, rejestry, skuteczność); raport odbioru
  pokazuje kandydatów z rekomendacją, bez osobnego rejestru decyzji.
- Q2. Alarm bezproduktywnego zużycia to tylko push. Alarmy kosztowe (W1, W2 w wariancie kosztowym, W4) wymagają
  przekroczenia 3× mediany i co najmniej 5 USD, alarmy czasowe (W2 w wariancie czasowym) 3× mediany i ponad 1 h, a W3
  (pętla prób) działa niezależnie od tych progów. Bez powiadomień o nieistotnych różnicach kosztu.
- Q3. Regresja po merge'u: czerwone CI `main` na commicie merge'u, revert, issue `bug` z polem „Wprowadzone przez”
  od autora z prawem zapisu; okno 14 dni; propozycje `/sweep` liczone osobno jako „znalezione po merge'u”.
- Q4. Koszt subskrypcji tylko w pp, bez przeliczania na pieniądze.
- Q8. Trend od pierwszego epiku z danymi AO (INVENTORY), także epiki zmergowane bez `epic_done`.
- Q9. Pakiet oczyszczonych fixture'ów przygotowuje sesja poza sandboxem przed `/ao-run metrics` („Wymagane przed
  startem”).

Decyzje Q5–Q7 (stos Grafana Cloud, sesje interaktywne, dashboard) dotyczą epiku TELEMETRY i są zapisane w #577.

## Pojęcia

- **Przebieg epiku:** katalog `~/.local/state/ao-<x>/` z `orchestrator.db`, w którym tabela `epics` ma wiersz
  `epic_id = <x>` z `final_pr_number`. Do trendu wchodzi, gdy końcowy PR jest `MERGED` (`gh`), niezależnie od stanu
  epiku w AO (PRODUCT-PAGE i DEPLOY mają `INTEGRATING`, PAYMENT `BLOCKED`). `/retro` ma węższą definicję (`DONE`) na
  swoje potrzeby i jej nie zmieniamy. Bazę czytamy tylko do odczytu (`file:…?mode=ro`, `busy_timeout`), `ao` nie
  jest uruchamiane (`ao report` nadpisuje pliki, a komenda w katalogu bez bazy zakłada nową).
- **Próba:** wiersz tabeli `attempts`; **zadanie:** wiersz `tasks` (`kind`: `plan`, `repair`, `integration`, `fix`,
  `finalize`).
- **Próba udana:** `outcome = result` i `executor_status` różny od `FAILED` i `BLOCKED`. **Próba zablokowana:**
  `executor_status = BLOCKED` (czekanie na człowieka, nie porażka). **Próba nieudana:** każda inna z `outcome`
  w `timeout`, `invalid_output`, `crash`, `policy_blocked`, `result` z `FAILED` albo pustym `outcome` po końcu
  przebiegu. `usage_limit` nie jest ani udana, ani nieudana (limit subskrypcji).
- **Rola:** kto zużył tokeny w próbie: subagent (`subagent_type` wiadomości w `stdout.jsonl`, np. `developer`,
  `code-reviewer`, `qa-adversarial`), orkiestrator próby (`delivery` dla zadań `plan`, `repair`, `fix`, `finalize`;
  `integration` dla `integration`) albo `other` (model w `modelUsage` bez wiadomości `assistant`, np. Haiku
  narzędzi wewnętrznych CLI).
- **Wartość tokenów:** `costUSD` z `modelUsage` ostatniego wyniku `result` próby (narastająco: próba ma czasem kilka
  wyników, np. CATALOG-ADMIN-INTEGRATION-2 ma 9, a ostatni równa się `attempts.cost_usd`). To szacunek CLI według
  cen cennikowych (`costBasis: list`), nie opłata.
- **Koszt subskrypcji:** przyrost procentu okien limitu z `get_usage` (`measurements.jsonl`), w punktach
  procentowych (pp).
- **Rekord epiku:** wynik `metrics.py epic` (JSON, format niżej), liczony z danych przebiegu.

## Pomiar epiku

`tools/metrics/metrics.py` (Python 3.9+, biblioteka standardowa) z `tools/metrics/config.json` (kategorie poleceń,
wagi tokenów, progi alarmów i reguł decyzji; zmiana progu nie wymaga zmiany kodu). Stan lokalny w
`~/.local/state/shop-metrics/` (poza katalogami `ao-*`, które są `AO_HOME` epików): `epics/<epik>.json` (rekordy),
`waste.json` (wysłane alarmy), `waste.lock`.

Kod wspólny przez import, bez kopii reguł: interwały czekania na człowieka z `ao_events.waits`, przyrosty okien
z `night_queue._window_deltas` i przedziały epików z `night_queue` (jak robi to `pairs.py`); `pairs.py` (tymczasowy)
nie jest używany.

### Źródła

| Dane | Źródło |
|---|---|
| Start, `epic_done`, statusy, CI, REPAIR, rundy QA, cooldown, pauzy hosta, GitHub | `orchestrator.db` (`epics`, `tasks`, `attempts`, `events`) |
| Czekanie na człowieka, decyzje | `events.jsonl` przebiegu (`ao_events.waits`) |
| Tokeny i wartość na rolę, model i próbę; czas poleceń w próbach | `tasks/<id>/attempts/<n>/stdout.jsonl` (wiadomości `assistant`: `subagent_type`, `message.id`, `message.model`, `message.usage`, `timestamp`; `tool_use` Bash i jego `tool_result`; ostatni `result` z `modelUsage`) |
| Koszt subskrypcji, przedziały epików, nakładanie | `~/.local/state/ao-night/measurements.jsonl` (`start`, `done`) |
| Czekanie w bramce pracy równoległej | `~/.local/state/ao-night/gate.jsonl` |
| Merge końcowego PR, CI PR, PR `sync/<epik>-main`, regresje | `gh` (tylko odczyt) |
| Ręczne poprawki | lista podana przez sesję `/ao-run` przy merge'u (`--manual-fixes FILE`; jak dziś do komentarza) |

Brak źródła nie zatrzymuje pomiaru: pole dostaje `null` i powód w `gaps` (np. „brak `events.jsonl`” dla CART,
„próba bez wyniku CLI”, „błąd `gh`”). Zera bez danych są zakazane. Zapis rekordu nie zastępuje wartości niepustej
wartością `null` z błędu źródła (przejściowy błąd `gh`, `--no-gh`): zostaje poprzednia, a błąd trafia do `gaps`.

### Rekord epiku

`metrics.py epic <epik> [--ao-home DIR] [--manual-fixes FILE] [--no-gh]` wypisuje jedną linię JSON i zapisuje rekord.
Czasy w sekundach, kwoty w USD, przyrosty limitu w pp:

- `epic`, `issue`, `final_pr`, `tasks` (liczba zadań planu), `started_at` (`epics.created_at`), `done_at`
  (pierwsze `epic_done`; bez niego `null` i pozycja w `gaps`), `merged_at`;
- `wall` (miara główna): `to_done` (start → `epic_done`), `to_merge` (start → merge), `review` (`epic_done` → merge,
  odbiór użytkownika);
- `breakdown`: podział `to_done` na rozłączne kategorie, które sumują się do `to_done`; każda chwila trafia do
  pierwszej pasującej (bez `done_at` cały `breakdown` to `null`):

  | Kategoria | Przedział |
  |---|---|
  | `work` | trwa co najmniej jedna próba (`started_at`–`ended_at`; próba bez końca do `epic_done`) |
  | `human_wait` | otwarte czekanie z `ao_events.waits` (bloker, awaria, `epic_blocked`, `queue_held`) |
  | `limit_wait` | `cooldown_set` (`from`) → `until` albo `cooldown_released`, co wcześniej |
  | `ci_wait` | `epic_ci_pending` → następne `epic_ci_green`, `epic_ci_inherited`, `epic_ci_inherited_red`, `epic_ci_timeout` albo `epic_repairing`; `final_pr_created` → `final_pr_ci` |
  | `host_pause` | `host_pause` (`from`–`to`) |
  | `github_wait` | `github_wait` → następne zdarzenie epiku inne niż `github_wait` i `gh_call`, najwyżej 30 min od ostatniego `github_wait` |
  | `idle` | reszta (AO nie działa, brak gotowych zadań) |

  Brak źródła kategorii (np. `events.jsonl`) przenosi jej czas do `idle` z pozycją w `gaps`.
- `in_attempts`: czas poleceń Bash w próbach według kategorii z `config.json` (`test`, `e2e`, `check`, `selftest`,
  `stack` dla `make up` i `.ao/setup`, `ci_watch` dla `gh pr checks --watch` i `gh run watch`), od `tool_use` do jego
  `tool_result`; polecenia w tle (`run_in_background`) liczone tylko jako liczba;
- `integration`: łączny czas prób zadań `integration`, `fix` i `finalize` i liczba rund (`epics.round`);
- `ci`: dla każdego PR zadania i końcowego PR czas ostatniego przebiegu `checks` i `e2e` oraz liczba przebiegów CI
  jego gałęzi (ponowienia);
- `attempts`: lista prób (`task`, `kind`, `number`, `outcome`, `executor_status`, `wall_s`, `value`, `estimated`,
  tokeny), z której liczy się historia median;
- `value`: wartość tokenów razem i w przekrojach `by_role`, `by_model`, `by_task`, `by_role_model`; tokeny (`input`,
  `output`, `cache_read`, `cache_write`) w tych samych przekrojach;
- `subscription`: przyrost pp każdego okna (reguły `night_queue._window_deltas`: okno odnowione w trakcie albo stan
  inny niż `ok` daje `null`), `overlap` (epiki, z którymi przedział się przecinał) i `share`: szacunek udziału tego
  epiku (jego wartość tokenów we wspólnym przedziale przez łączną wartość obu epików w tym przedziale; bez nakładania
  1). Surowy przyrost zostaje obok;
- `retries`: próby na zadanie, nieudane według `outcome`, zablokowane, zadania REPAIR (przyczyna z danych zdarzenia:
  `final_pr` w `epic_repairing` to konflikt końcowego PR, inaczej czerwone CI gałęzi), zadania FIX, PR
  `sync/<epik>-main`;
- `parallel`: epiki, których przedział w `measurements.jsonl` przecinał się z tym epikiem, i REPAIR oraz sync
  w czasie nakładania (konflikty pary);
- `gate_wait`: czas od pierwszego wpisu `waiting` tego epiku w `gate.jsonl` do jego startu i powody czekania;
- `human`: liczba decyzji (`human_action`), liczba czekań i ich suma, czekania otwarte, ręczne poprawki;
- `waste`: alarmy z przebiegu (`waste.json`);
- `regressions`, `found_after_merge`: sekcja „Regresje po merge'u”;
- `bottlenecks`: sekcja „Wąskie gardła”;
- `gaps`: braki danych.

Podział wartości na role: `costUSD` modelu z ostatniego wyniku próby dzieli się między role według wag tokenów tego
modelu w próbie (wejście 1, zapis cache 5 min 1,25, zapis cache 1 h 2, odczyt cache 0,1, wyjście 5; proporcje
cennika API w `config.json`, bez cen w repo). Wiadomości `assistant` o tym samym `message.id` liczą się raz
(stream-json powtarza `usage` dla każdego bloku treści). Próba bez wyniku CLI (`timeout`, przerwanie) ma tokeny
z wiadomości, a jej wartość to tokeny ważone × mediana USD na token ważony tego modelu z historii, z `estimated: true`
i pozycją w `gaps`.

### Historia i trend

- `metrics.py backfill`: rekord każdego przebiegu epiku w `~/.local/state/ao-*` z końcowym PR `MERGED`; braki
  starszych epików w `gaps`. Uruchamia go sesja `/ao-run`, gdy katalog rekordów jest pusty.
- `metrics.py trend [--last N]`: tabela zmergowanych epików (domyślnie 10 ostatnich) z wartościami na zadanie planu:
  czas do `epic_done`, `work`, `human_wait`, wartość tokenów, pp okna tygodniowego (`share`), próby nieudane, REPAIR,
  interwencje, regresje; mediana każdej kolumny (z pominięciem `null`) i pozycja wskazanego epiku względem niej.
- Rekordy z otwartym oknem regresji przelicza każde `trend` (merge kolejnego epiku, start `/ao-run`).

### Wąskie gardła

`bottlenecks` rekordu: najwyżej 3 pozycje z największą nadwyżką czasu ponad medianę historii, w godzinach,
uszeregowane malejąco; pozycja wchodzi, gdy nadwyżka jest co najmniej 0,5 h (próg w `config.json`). Kandydaci:

- każda kategoria `breakdown` poza `work`: nadwyżka = (wartość na zadanie planu − mediana historii na zadanie planu)
  × liczba zadań planu;
- każde zadanie: `work` zadania (suma `wall_s` jego prób) − mediana `work` zadań tego `kind` w historii;
- każda kategoria `in_attempts`: jak kategorie `breakdown`, na zadanie planu.

Każda pozycja ma:

- rodzaj i nazwę (np. `human_wait`, `DEPLOY-OPS-4`, `in_attempts.e2e`), wartość i medianę;
- koszt czasowy: nadwyżkę w godzinach i jej udział w `to_done` (procent czasu zegarowego epiku);
- klucz reguły decyzji, która jej dotyczy (D2 dla zadania, D3 dla `gate_wait`, D4 dla kategorii czekania; `null`,
  gdy żadna);
- potencjalny efekt optymalizacji: oczekiwane skrócenie czasu zegarowego z reguły decyzji (w godzinach), a bez
  reguły nadwyżka jako górna granica z dopiskiem „bez reguły decyzji”.

Bez co najmniej 5 epików w historii mediana jest z dostępnych, a rekord ma pozycję w `gaps`. Raport odbioru zaczyna
sekcję „Pomiar” od wąskich gardeł, przed pozostałymi liczbami, np. „1. `human_wait`: +6,2 h ponad medianę (22%
czasu), potencjalnie −4,1 h (D4)”.

### Regresje po merge'u

W oknie 14 dni od merge'u końcowego PR (`regressions.until`):

- czerwony przebieg CI na `main` dla commita merge'u epiku (`gh run list --commit`);
- commit na `main`, który wycofuje końcowy PR (`Revert` z jego numerem);
- issue `bug`, którego nowe pole „Wprowadzone przez (epik albo PR, jeśli znane)” w formularzu `bug`
  (`.github/ISSUE_TEMPLATE/bug.yml`) wskazuje ID epiku albo numer jego PR, założone albo ostatnio zmienione przez
  autora z prawem zapisu do repozytorium (`gh api repos/{owner}/{repo}/collaborators/{login}/permission`: `write`,
  `maintain`, `admin`). Issue od innych autorów nie liczy się (formularz jest publiczny).

`found_after_merge`: issues z propozycji `/sweep` tego epiku (raport przeglądu z nazwą epiku) w tym samym oknie,
osobno od regresji, bo przegląd znajduje też stare problemy.

### Bezproduktywne zużycie

`metrics.py waste <AO_HOME>` czyta bieżący stan przebiegu (`busy_timeout`, ponowienie przy zajętej bazie) i historię
rekordów i wypisuje nowe alarmy (JSON, po jednym w linii). `waste.json` zapisuje wysłane alarmy pod blokadą
`waste.lock` (dwie sesje `/ao-run` przy pracy równoległej), więc każdy alarm idzie raz. Reguły (progi
w `config.json`, Q2). Alarm kosztowy wymaga obu warunków: > 3× mediany i ≥ 5 USD; alarm czasowy: > 3× mediany
i > 1 h:

- **W1 koszt próby (kosztowy):** próba zakończona o wartości > 3× mediana wartości zakończonych prób tego samego
  `kind` w historii i ≥ 5 USD;
- **W2a trwająca próba, czas (czasowy):** czas > 3× mediana `wall_s` prób tego `kind` i > 1 h;
- **W2b trwająca próba, koszt (kosztowy):** bieżąca wartość z `stdout.jsonl` (tokeny ważone × mediana stawki modelu)
  > 3× mediana wartości prób tego `kind` i ≥ 5 USD;
- **W3 pętla prób (niezależna od progów mediany i kwoty):** zadanie zaczyna trzecią próbę po dwóch nieudanych
  (zablokowane się nie liczą), drugi REPAIR epiku z rzędu albo runda QA równa `--max-qa-rounds`;
- **W4 koszt zadania (kosztowy):** łączna wartość prób zadania > 3× mediana wartości zadań tego `kind` i ≥ 5 USD.

Mediana wymaga co najmniej 5 pomiarów w historii; bez nich W1, W2a, W2b i W4 nie alarmują (pozycja w `gaps`). Sesja
`/ao-run` wywołuje `waste` przy każdym wybudzeniu przez zdarzenie AO i przy każdym ponownym uzbrojeniu Monitora
(najrzadziej co 30 minut). Alarm to push (do 200 znaków), np. „deploy-ops: DEPLOY-OPS-4 próba 3 trwa 4,1 h (3,2×
mediany); 3. próba po 2 nieudanych”, i pozycja w sekcji „Pomiar” raportu odbioru. Sesja niczego nie zatrzymuje:
`ao release` i inne komendy wykonuje tylko na polecenie użytkownika. Limity AO (`--max-budget-usd`, `--timeout`,
`--epic-max-attempts`) działają jak dziś.

### Decyzje optymalizacyjne

`metrics.py decide <epik> [--history]` jest bezstanowy: buduje kandydatów decyzji z rekordu epiku, trendu i wyników
evali (`tools/evals/history/`, format EVALS; brak katalogu: D1 nie powstaje, pozycja w `gaps`), według reguł
z `config.json`. Każdy kandydat ma klucz (reguła i przedmiot, np. `D1:code-reviewer`), dane, propozycję, oczekiwany
efekt i miarę weryfikacji. `--history` wypisuje kandydatów każdego zmergowanego epiku (wejście `/retro`).

- **D1 model dla roli:** rola agenta z zestawem evali ma ≥ 15% wartości tokenów epiku na modelu droższym niż
  najtańszy używany w epiku. Propozycja: PR zmieniający model w pliku agenta; EVALS zmierzy zestaw na tym PR
  i zablokuje merge do `main` przy regresji. Efekt: wartość roli × (1 − stosunek mediany stawki tańszego modelu do
  droższego z historii). Rola bez zestawu evali (np. `developer` przed EVALS-BENCH) nie dostaje D1; raport wymienia ją
  jako „bez evali”.
- **D2 podział planu:** zadanie planu z `work` > 2× mediany zadań planu w historii albo z co najmniej 2 próbami
  nieudanymi. Propozycja: reguła podziału dla `/planner` z cechami zadania (liczba ścieżek `scope`, ryzyko, aplikacje,
  liczba zmienionych plików). Efekt: czas ponad medianę.
- **D3 kolejka pracy równoległej:** `gate_wait` ≥ 1 h z powodu reguły plików bramki. Propozycja: zmiana linii
  `Zakres:` przy przygotowaniu albo reguły plików (`docs/features/parallel-work/spec.md`, „Bramka”). Efekt: `gate_wait`.
  Decyzji o 19b nie dotyczy: jej kryterium jest w planie (pomiar par, `pairs.py`).
- **D4 czekanie:** `human_wait` ≥ 20% `to_done` (pytania przed startem, godzina startu), `ci_wait` +
  `in_attempts.ci_watch` ≥ 20% `work` (CI), `limit_wait` ≥ 10% `to_done` (planowanie nocy). Efekt: udział × `to_done`.

Kolejność: oczekiwane skrócenie czasu zegarowego, potem wartość tokenów.

**Raport odbioru** (`/ao-run`, punkt 5) kończy się sekcją „Decyzje optymalizacyjne”: najwyżej 3 kandydaci z
`decide`, każdy z rekomendacją, efektem i liczbą epików, w których reguła zadziałała (z `--history`). Decyzji
w raporcie nie zapisujemy: zapada w `/retro`, które `/ao-run` uruchamia po merge'u (Q1). Sekcja jest też bez
kandydatów („brak: żadna reguła nie zadziałała”, z regułami pominiętymi z braku danych).

**`/retro`:** `retro.py collect` dołącza wynik `metrics.py decide --history` jako incydenty rodzaju `optimization`
(ID `<epik>/optimization/<klucz>`, np. `deploy-ops/optimization/D1:code-reviewer`), a rekord retrospektywowanego
epiku i `trend` jako fakty w `input.json`. Incydenty `optimization` przechodzą istniejący cykl `/retro` bez zmian:
próg (co najmniej dwa różne incydenty, czyli reguła zadziałała w dwóch epikach), rejestry `rejected`, `applied`,
`carried`, `previous_fix` (problem, który wraca po zastosowanej poprawce, oznacza ją jako nieskuteczną) i limity 5
poprawek i 3 issues. Poprawka instrukcji to pozycja `fix`, zmiana kodu albo modelu agenta to propozycja issue
`tech-debt` (zmiana modelu agenta z zestawem evali przechodzi EVALS w swoim PR). Raport `/retro` kończy się sekcją
„Decyzje optymalizacyjne”: pozycje z incydentów `optimization` w tym przebiegu, obserwacje (reguła w jednym epiku),
i dla kluczy z `applied.jsonl` efekt: miara weryfikacji decyzji i czas do `epic_done` na zadanie planu z `trend`,
każda jako mediana 3 epików przed merge'em PR z poprawką i 3 epików po nim, z różnicą w godzinach i procentach
(mniej epików po: „za mało danych”, bez wniosku).

### Raport odbioru i komentarz w issue

- Raport odbioru (`/ao-run`, punkt 5) dostaje sekcję „Pomiar” z `metrics.py render <epik> --format report`: czas do
  `epic_done` i rozkład, czas w próbach według kategorii, integracja, wartość tokenów (razem, role, modele,
  najdroższe zadanie), koszt subskrypcji w pp (z `share` przy nakładaniu), próby, REPAIR, FIX, sync, czekanie
  w bramce, interwencje, alarmy, pozycja na tle mediany, braki danych; na początku wąskie gardła. Istniejące pozycje raportu
  (interwencje, czas, koszt, czekanie) pochodzą z rekordu.
- Komentarz po merge'u (`/ao-run`, punkt 6) to blok „Pomiar epiku” z `render --format comment`: te same liczby po
  merge'u (z `to_merge` i `review`) i dotychczasowe linie (interwencje, czekanie, `Ręczne poprawki: N` z listą
  w formacie czytanym przez `retro.py collect`). Render przyjmuje tylko pola liczbowe, ID zadań, nazwy ról i modeli
  oraz numery PR; żaden tekst z danych przebiegu (komunikaty błędów, `result`, polecenia) nie trafia do publicznego
  komentarza.
- Pomiar par 19a (`pairs.py`, blok „Para równoległa”) zostaje bez zmian do decyzji o 19b.

## Model zagrożeń

Aktywa i strony:

- Treść pracy agentów (prompty, odpowiedzi, polecenia, ścieżki, komunikaty błędów, wyniki narzędzi) z danych
  przebiegu: nie trafia do publicznego komentarza w issue ani do repozytorium.
- Rzetelność pomiaru i decyzji: dane z publicznego GitHuba (issues od dowolnego autora) nie mogą fałszować regresji.

Drogi ataku w zakresie:

1. Komentarz pomiaru ujawnia treści z przebiegu. Zamyka: `render --format comment` przyjmuje tylko pola liczbowe,
   ID zadań, nazwy ról i modeli oraz numery PR. Sprawdzenie: test renderu na rekordzie z polami tekstowymi z danych
   przebiegu (komunikat błędu, ścieżka `/Users/`, polecenie).
2. Obcy autor zawyża regresje epiku publicznym issue `bug`. Zamyka: liczą się tylko issues od autorów z prawem
   zapisu. Sprawdzenie: test z podstawionym `gh` (autor `read` i `write`).
3. Fixture'y w repozytorium ujawniają dane z przebiegów. Zamyka: do repo trafiają tylko fixture'y z pakietu
   oczyszczonego według listy pól z „Wymaganych przed startem” albo dane syntetyczne. Sprawdzenie: test skanu
   fixture'ów `tools/metrics` (brak `/Users/`, adresów e-mail i wzorców tokenów).

Poza zakresem: telemetria i jej poświadczenia (TELEMETRY #577); zapis do `~/.local/state/shop-metrics/` przez inne
procesy użytkownika (ten sam poziom zaufania co stan AO).

## Wymagane przed startem

- Pakiet oczyszczonych fixture'ów w `~/.local/state/ao-metrics/tasks/<ID zadania>/fixtures/` (METRICS-1: baza,
  zdarzenia, pomiar limitów, bramka; METRICS-2: transkrypty; pozostałe zadania korzystają z fixture'ów w repo
  i danych syntetycznych) z `MANIFEST.json`. Oczyszczanie przez listę dozwolonych pól w każdym pliku (pole spoza
  listy nie przechodzi), bez wyjątków:
  - zrzut SQL baz AO DEPLOY-OPS i CATALOG-ADMIN: tabele `epics`, `tasks`, `attempts`, `events`; tylko kolumny
    identyfikatorów, stanów, liczb i czasów; usunięte kolumny tekstowe i ścieżki (`result`, `error`, `warnings`,
    `answers`, `blockers`, `reason`, `command`, `dir`, `workdir`, `state_file`, `spec_path`, `brief_path`, `repo`,
    `config`, `plan_path`); `events.data` tylko z kluczami liczbowymi, czasowymi i identyfikatorami (`from`, `to`,
    `until`, `seconds`, `sha`, `task_id`, `epic_id`, `final_pr`, `status`), bez `error`, `what`, `reason`
    i adresów URL;
  - `stdout.jsonl` wybranych prób (w tym próba `timeout` DEPLOY-OPS-4 nr 3 i próba z 9 wynikami
    CATALOG-ADMIN-INTEGRATION-2): pola `type`, `subtype`, `subagent_type`, `message.id`, `message.model`,
    `message.usage`, `timestamp`, `parent_tool_use_id`, nazwa narzędzia, kategoria polecenia Bash z `config.json`
    (bez treści polecenia), `run_in_background`, `is_error`, `modelUsage`, `total_cost_usd`; bez treści wiadomości,
    promptów, wyników narzędzi i `result`;
  - `events.jsonl` tych epików: `type`, czas, `task`, `epic`, `status`, `action`, numery PR; bez pytań blokerów,
    odpowiedzi, komunikatów i adresów URL;
  - `measurements.jsonl`: `event`, `epic`, `tasks`, `at`, `docker` (liczby), `usage.status` i okna (`label`,
    `percentUsed`, `resetsAt`); bez `plan`, `extraUsage` i innych pól konta;
  - `gate.jsonl`: czas, katalog i ID epiku, wynik, `waiting`, kod powodu i ścieżki repozytorium (względne); bez
    tekstu powodu, ścieżek bezwzględnych i nazw sesji.

  Skan całego pakietu po oczyszczeniu: żadnej ścieżki bezwzględnej (`/Users/`, `/private/`, `~`), adresu e-mail,
  wzorca tokenu (`ghp_`, `gho_`, `github_pat_`, `sk-`, `Bearer`, `Basic`, ciąg base64 ≥ 32 znaków poza SHA
  i identyfikatorami UUID) ani wartości tekstowej dłuższej niż 120 znaków. `MANIFEST.json` wymienia każdy plik,
  źródło, listę pól, SHA-256 i wynik skanu. Sesja przygotowania pokazuje użytkownikowi wynik skanu przed
  `/ao-run metrics`; skan pakietu `tools/metrics` w repo powtarza test (METRICS-1). Po co: testy zadań na
  prawdziwym formacie (po AGENT-SECURITY wykonawca w sandboxie nie czyta `~/.local/state` poza katalogiem zadania).
  Źródło: użytkownik: zgoda na sesję przygotowania danych poza sandboxem (lista i reguły z tej sekcji) przed
  `/ao-run metrics`, jak w EVALS (Q9; zgoda udzielona 2026-11-15).
- Reguła D1 czyta format `tools/evals/history/` z EVALS (#495), gdy jest na `main`; bez katalogu D1 nie powstaje
  (pozycja w `gaps`), więc METRICS nie czeka na EVALS do startu. `## Zależności` issue #496 nie wymienia ID innego
  epiku (lista nocy traktuje każde wymienione ID otwartego epiku jako zależność): evale są tam opisane numerem #495,
  a telemetria numerem #577. Zakresy METRICS i EVALS nie są rozłączne (`.claude/skills/retro`), więc bramka pracy
  równoległej nie uruchomi ich naraz.

Sekretów, kont i usług zewnętrznych epik nie potrzebuje: czyta lokalne dane AO i publiczny GitHub przez `gh`
użytkownika.

## Po merge'u

`metrics.py backfill` uruchamia sesja `/ao-run` przy pierwszym starcie po merge'u (pusty katalog rekordów).

## Poza zakresem

- Telemetria Claude Code w Grafana Cloud: epik TELEMETRY (#577).
- Monitoring produkcji i dyżur agenta (krok 11), dokumentacja i studium przypadku (krok 21).
- Pomiar `/sweep`, `/retro` i `/prepare-epic` w rekordzie epiku: mają własne sekcje `## Zużycie` (pp i czas).
- Automatyczne zatrzymywanie prób przy alarmie i zmiany AO (zamrożone).
- Decyzja o 19b (kryterium w planie, pomiar par `pairs.py`) i liczba zadań naraz po 19b.
- Benchmark `/delivery` (EVALS-BENCH, #500): D1 obejmie `developer`, gdy będzie jego zestaw.

## Zmiany dokumentacji (razem z implementacją)

- `docs/features/ao-run/spec.md` i `.claude/skills/ao-run/SKILL.md`: sekcje „Pomiar” (z wąskimi gardłami) i „Decyzje
  optymalizacyjne” raportu odbioru, blok „Pomiar epiku” w komentarzu po merge'u, `waste` przy wybudzeniach, `trend`
  przy merge'u i starcie, `backfill` przy pustym katalogu rekordów.
- `docs/features/retro/spec.md`, `.claude/skills/retro/SKILL.md`, `.claude/agents/retro-analyst.md`: incydenty
  `optimization`, fakty z pomiaru w `input.json`, sekcja „Decyzje optymalizacyjne” raportu z efektem przed i po.
- `.github/ISSUE_TEMPLATE/bug.yml`: pole „Wprowadzone przez (epik albo PR, jeśli znane)”.
- `docs/adr/agentic-software-house.md`: komponent „Metryki i evale” wskazuje `tools/metrics` i `tools/evals`.
- `docs/features/agentic-software-house/plan.md`: krok 18 (podział na METRICS i TELEMETRY #577) i rola „Pomiar
  czasu, kosztu i jakości” ze wskazaniem `tools/metrics/README.md`; punkt 5 procesu (komentarz w issue epiku to blok
  „Pomiar epiku”).
- `tools/metrics/README.md`: komendy, format rekordu, reguły alarmów i decyzji, konfiguracja.

## Kryteria akceptacji

1. `metrics.py epic` na fixture DEPLOY-OPS: kategorie `breakdown` sumują się do `to_done`; `value.by_role` sumuje się
   do sumy wartości prób; próba `timeout` (DEPLOY-OPS-4 nr 3) ma tokeny i wartość `estimated`; każda kategoria
   `breakdown` ma test z przedziałem z tabeli (w tym `github_wait` z limitem 30 min).
2. Próba z kilkoma wynikami `result` (fixture CATALOG-ADMIN-INTEGRATION-2) ma wartość z ostatniego, równą
   `attempts.cost_usd`; wiadomości o tym samym `message.id` liczą się raz; model bez wiadomości trafia do roli `other`.
3. Brak źródła (bez `events.jsonl`, `measurements.jsonl`, `gh`, `epic_done`) daje `null` i pozycję w `gaps`, nie
   zero; błąd `gh` nie nadpisuje zapisanych wartości.
4. `backfill` liczy rekordy przebiegów z końcowym PR `MERGED` (także `INTEGRATING` i `BLOCKED` w AO), pomija epiki
   w toku i katalogi bez wiersza epiku.
5. `trend`: mediana z pominięciem `null`, pozycja epiku, przeliczenie regresji w otwartym oknie.
6. `subscription`: okno odnowione w trakcie → `null`; `share` przy nakładaniu i 1 bez niego.
7. `in_attempts`: czas od `tool_use` do `tool_result` według kategorii; polecenie w tle tylko w liczbie.
8. `bottlenecks`: najwyżej 3 pozycje malejąco po nadwyżce, nadwyżka poniżej 0,5 h nie wchodzi, każdy rodzaj kandydata
   (kategoria, zadanie, `in_attempts`) ma test; pozycja ma koszt czasowy (godziny i udział w `to_done`), klucz
   reguły decyzji albo `null` i potencjalny efekt (z reguły albo nadwyżka z dopiskiem „bez reguły decyzji”).
9. `waste`: każda reguła na fixture alarmuje raz, także przy dwóch równoległych wywołaniach; alarm kosztowy (W1, W2b,
   W4) nie powstaje przy 3× mediany poniżej 5 USD ani przy 5 USD poniżej 3× mediany; alarm czasowy (W2a) nie powstaje
   poniżej 1 h; W3 alarmuje bez względu na koszt i czas; poniżej 5 pomiarów W1, W2a, W2b i W4 nie alarmują; próby
   zablokowane i `usage_limit` nie wchodzą do W3.
10. `decide`: D1–D4 na fixture z progami z `config.json`, kolejność, najwyżej 3 w raporcie; bez historii evali D1 nie
    powstaje.
11. `render --format comment` zawiera `Ręczne poprawki: N` w formacie, który czyta `retro.py collect` (test z jego
    parserem), i żadnego tekstu z danych przebiegu.
12. Regresje: test z podstawionym `gh` dla czerwonego CI na commicie merge'u, revertu i issue `bug` z polem
    „Wprowadzone przez” od autora `write` (liczy się) i `read` (nie liczy się); poza oknem 14 dni się nie liczą;
    propozycje `/sweep` trafiają do `found_after_merge`.
13. Raport odbioru `/ao-run` ma sekcje „Pomiar” (zaczynającą się od wąskich gardeł) i „Decyzje optymalizacyjne”,
    a komentarz po merge'u blok „Pomiar epiku”.
14. `/retro`: `input.json` ma incydenty `optimization` i fakty z pomiaru; pozycja oparta na dwóch incydentach
    `optimization` przechodzi `retro.py validate`, na jednym nie; raport kończy się sekcją „Decyzje optymalizacyjne”
    z efektem przed i po dla kluczy z `applied.jsonl` (różnica w godzinach i procentach albo „za mało danych”).
15. Skrypty mają testy ścieżek błędu (nieczytelna albo zajęta baza, brak katalogu, błąd `gh`, nieczytelny
    `waste.json`, zły `config.json`) z kodem i komunikatem; skan fixture'ów w repo przechodzi.

## Ryzyka

- Format stream-json zależy od wersji CLI (AO przypina wersję). Zmiana pinu może zmienić pola; `gaps` zamiast zer nie
  ukryje braku.
- Podział wartości na role według wag tokenów to przybliżenie (CLI podaje koszt tylko na model i próbę).
- `share` przy nakładaniu epików to szacunek; surowy przyrost pp jest obok.
- Reguły decyzji i wąskie gardła to heurystyki: wartość dają dopiero z oceną użytkownika w `/retro` i efektem
  zmierzonym przed i po; przy małej liczbie epików po zmianie wniosku nie ma.
- Epiki różnią się wielkością i rodzajem, więc mediana „na zadanie planu” porównuje niejednorodne prace; raport podaje
  obok wartości bezwzględne.
