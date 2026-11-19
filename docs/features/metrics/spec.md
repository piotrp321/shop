# METRICS: pomiar, koszty i optymalizacja przebiegów

Krok 18 planu Agentic Software House (`docs/features/agentic-software-house/plan.md`), komponent „Metryki i evale”
ADR `docs/adr/agentic-software-house.md`. Epik daje każdemu przebiegowi epiku przez AO pełny, powtarzalny pomiar
(czas zegarowy i jego rozkład, wartość tokenów i koszt subskrypcji, ponowienia, naprawy, konflikty, testy,
interwencje, regresje po merge'u), pokazuje wąskie gardła czasu zegarowego, wykrywa bezproduktywne zużycie w trakcie
przebiegu z pushem i kończy raport odbioru oraz `/retro` decyzjami optymalizacyjnymi z mierzonym efektem. Telemetria
Claude Code w lokalnym stosie na Macu to osobny epik TELEMETRY (#577, część 2 kroku 18, po AGENT-SECURITY); METRICS od niej
nie zależy.

Issues: #496
Zakres: `tools/metrics`, `.claude/skills/ao-run`, `.claude/skills/retro`, `.claude/agents/retro-analyst.md`, `.github/ISSUE_TEMPLATE/bug.yml`, `docs/features/ao-run/spec.md`, `docs/features/retro/spec.md`, `docs/adr/agentic-software-house.md`, `docs/features/agentic-software-house/plan.md`

## Cel i kryteria sukcesu

- Priorytetem jest skrócenie rzeczywistego czasu realizacji epików, nie zbieranie większej liczby metryk. Każda
  liczba w raporcie służy jednemu z trzech celów: pokazać wąskie gardło czasu zegarowego, wykryć marnotrawstwo albo
  zmierzyć efekt decyzji optymalizacyjnej.
- Optymalizujemy czas zegarowy epiku (miara główna), pilnując jakości, napraw, interwencji człowieka i zużycia
  limitów. Każdy epik ma te liczby w jednym formacie, policzone skryptem z danych przebiegu, a nie ręcznie.
- Raport każdego epiku wskazuje konkretne wąskie gardła: najwyżej trzy pozycje (kategoria czasu, zadanie, rodzaj
  poleceń w próbach, czekanie w bramce albo odbiór) z największą nadwyżką czasu ponad medianę historii, w godzinach.
- Wartość tokenów według cen API (szacunek CLI) i faktyczny koszt subskrypcji (punkty procentowe okien limitu) są
  zawsze pokazane osobno i nigdy nie są sumowane.
- Bezproduktywne zużycie (zadanie zużywa wielokrotność mediany, pętla prób) jest widoczne w trakcie przebiegu jako
  push, a nie dopiero rano w raporcie.
- Raport odbioru i raport `/retro` kończą się konkretnymi decyzjami optymalizacyjnymi: co zmienić, oczekiwany efekt
  w liczbach, dane i miara, po której kolejne epiki pokażą, czy zmiana zadziałała. Decyzję podejmuje użytkownik.
- Efekt zastosowanej decyzji jest mierzony na podobnych zadaniach przed i po merge'u zmiany (mediana, rozrzut
  i liczebność próby, ze zmianami modeli i wersji CLI w oknie), a obok zostaje czas zegarowy całego epiku.
- Liczby szacowane są zawsze oznaczone i nigdy nie są pokazane jak dokładne.
- Trend obejmuje wszystkie zmergowane epiki z danymi AO, więc pierwszy raport po wdrożeniu ma już tło porównawcze.
- Rozwiązanie jest przenośne: `tools/metrics` czyta dane AO, Claude Code i GitHuba, nie sklepu.

Sukces epiku: raport odbioru pierwszego epiku po merge'u ma sekcje „Pomiar” (z wąskimi gardłami) i „Decyzje
optymalizacyjne”, a `metrics.py trend` pokazuje wszystkie zmergowane epiki.

## Decyzje użytkownika

Zatwierdzone 2026-11-15 (rekomendacje przyjęte w całości, z doprecyzowaniami):

- Q0. Krok 18 podzielony na dwa niezależne epiki: METRICS (ta specyfikacja: pomiar, wąskie gardła, alarmy, decyzje,
  raport, `/retro`) i TELEMETRY (#577: telemetria Claude Code w lokalnym stosie na Macu, po AGENT-SECURITY). METRICS nie
  czeka na telemetrię ani na AGENT-SECURITY.
- Q1. Decyzje optymalizacyjne przechodzą istniejący cykl `/retro` (próg, rejestry, skuteczność); raport odbioru
  pokazuje kandydatów z rekomendacją, bez osobnego rejestru decyzji.
- Q2. Alarm bezproduktywnego zużycia to tylko push. Alarmy kosztowe (W1, W4) wymagają przekroczenia 3× mediany
  i co najmniej 5 USD, alarm czasowy (W2) 3× mediany i ponad 1 h, a W3 (pętla prób) działa niezależnie od tych
  progów. Bez powiadomień o nieistotnych różnicach kosztu i bez szacowania kosztu trwającej próby.
- Q3. Regresja po merge'u: czerwone CI `main` na commicie merge'u, revert, issue `bug` z polem „Wprowadzone przez”
  od autora z prawem zapisu; okno 14 dni; propozycje `/sweep` liczone osobno jako „znalezione po merge'u”.
- Q4. Koszt subskrypcji tylko w pp, bez przeliczania na pieniądze.
- Q8. Trend od pierwszego epiku z danymi AO (INVENTORY), także epiki zmergowane bez `epic_done`.
- Q9. Pakiet oczyszczonych fixture'ów przygotowuje sesja poza sandboxem przed `/ao-run metrics` („Wymagane przed
  startem”).

Decyzje Q5–Q7 (stos telemetrii, sesje interaktywne, dashboard) dotyczą epiku TELEMETRY i są zapisane w #577.

Korekty po przeglądzie praktyk zewnętrznych (zatwierdzone 2026-11-15; priorytet: prostota, niezawodny pomiar,
rzeczywiste skrócenie czasu AO):

- R1. Tokeny i wartość według reguł liczenia z dokumentacji Claude Code (Agent SDK, „Track cost and usage”), wartości
  dokładne i szacowane rozróżnione (sekcja „Dokładność”).
- R2. Czas krytyczny zadań: odłożony do kroku 19b. W historii AO (294 kolejne pary prób) żadne dwie próby epiku nie
  biegły naraz, więc dziś czas krytyczny zadania równa się jego `work`; kandydat zadania i D2 używają `work`.
- R3. Efekt decyzji na podobnych zadaniach (mediana, rozrzut, liczebność), z wersjami CLI i modeli; czas całego epiku
  zostaje.
- R4. Bez alarmu kosztu trwającej próby.
- R5. W próbach: ponowienia API, odmowy uprawnień i powtórzone testy (to samo polecenie bez zmiany kodu); bez nowej
  reguły decyzji.
- R6. Czas odbioru i czekanie w bramce jako wąskie gardła.
- R7. Bez integracji zewnętrznych, dashboardów i detektorów opartych na LLM.

## Pojęcia

- **Przebieg epiku:** katalog `~/.local/state/ao-<x>/` z `orchestrator.db`, w którym tabela `epics` ma wiersz
  `epic_id = <x>` z `final_pr_number`. Do trendu wchodzi, gdy końcowy PR jest `MERGED` (`gh`), niezależnie od stanu
  epiku w AO (PRODUCT-PAGE i DEPLOY mają `INTEGRATING`, PAYMENT `BLOCKED`). `/retro` ma węższą definicję (`DONE`) na
  swoje potrzeby i jej nie zmieniamy. Bazę czytamy tylko do odczytu (`file:…?mode=ro`, `busy_timeout`), `ao` nie
  jest uruchamiane (`ao report` nadpisuje pliki, a komenda w katalogu bez bazy zakłada nową).
- **Przebieg natywny:** epik prowadzony przez główną sesję Claude Code z natywnymi subagentami
  (`docs/features/ash-v1/spec.md`). Źródłem jest plik przebiegu `runs/<epik>.json` w stanie pomiaru i transkrypty
  sesji w nim zapisanych (sekcja „Epik natywny”). Rekord przebiegu AO ma `source: ao`, rekord natywny `source: native`.
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
  cen cennikowych (`costBasis: list`), nie opłata. Ostatni wynik z zerowym `total_cost_usd` i zerowym `modelUsage`
  przy niezerowych tokenach wiadomości (wynik po awarii) to próba bez wyniku CLI; `costBasis: unknown` daje pozycję
  w `gaps`.
- **Koszt subskrypcji:** przyrost procentu okien limitu z `get_usage` (`measurements.jsonl`, w epiku natywnym
  odczyty z pliku przebiegu), w punktach procentowych (pp).
- **Rekord epiku:** wynik `metrics.py epic` (JSON, format niżej), liczony z danych przebiegu.

## Pomiar epiku

`tools/metrics/metrics.py` (Python 3.9+, biblioteka standardowa) z `tools/metrics/config.json` (kategorie poleceń,
wagi tokenów, progi alarmów i reguł decyzji, role i katalog wyników EVALS 2.0 dla D1, cennik API epików natywnych;
zmiana progu nie wymaga zmiany kodu). Stan lokalny w
`~/.local/state/shop-metrics/` (poza katalogami `ao-*`, które są `AO_HOME` epików): `epics/<epik>.json` (rekordy),
`runs/<epik>.json` (pliki przebiegu epików natywnych), `waste.json` (wysłane alarmy), `waste.lock`.

Kod wspólny przez import, bez kopii reguł: interwały czekania na człowieka z `ao_events.waits`, przyrosty okien
z `night_queue._window_deltas` i przedziały epików z `night_queue` (jak robi to `pairs.py`); `pairs.py` (tymczasowy)
nie jest używany.

### Źródła

| Dane | Źródło |
|---|---|
| Start, `epic_done`, statusy, CI, REPAIR, rundy QA, cooldown, pauzy hosta, GitHub | `orchestrator.db` (`epics`, `tasks`, `attempts`, `events`) |
| Czekanie na człowieka, decyzje | `events.jsonl` przebiegu (`ao_events.waits`) |
| Tokeny i wartość na rolę, model i próbę; czas poleceń, ponowienia API i odmowy w próbach; wersja CLI | `tasks/<id>/attempts/<n>/stdout.jsonl` (wiadomości `assistant`: `subagent_type`, `parent_tool_use_id`, `message.id`, `message.model`, `message.usage`, `timestamp`; `tool_use` powłoki, `Edit`, `Write`, `NotebookEdit` z `id` i `tool_result` powłoki z `tool_use_id`; `system/init` z `claude_code_version`; `system/api_retry` z `retry_delay_ms`; `system/permission_denied` z `tool_name`; każdy `result` z `usage`, ostatni z `modelUsage`) |
| Koszt subskrypcji, przedziały epików, nakładanie | `~/.local/state/ao-night/measurements.jsonl` (`start`, `done`) |
| Czekanie w bramce pracy równoległej | `~/.local/state/ao-night/gate.jsonl` |
| Merge końcowego PR, CI PR, PR `sync/<epik>-main`, regresje | `gh` (tylko odczyt) |
| Ręczne poprawki | lista podana przez sesję `/ao-run` przy merge'u (`--manual-fixes FILE`; jak dziś do komentarza) |
| Epik natywny: start, gotowość PR, gałąź, PR, sesje, odczyty limitów | plik przebiegu `runs/<epik>.json` (`metrics.py start`, `done`) |
| Epik natywny: tokeny, wartość, przebiegi subagentów, polecenia, wersja CLI, rozkład czasu, wiadomości człowieka, przeglądy | transkrypty sesji z pliku przebiegu w `~/.claude/projects/*/` i ich `subagents/` (sekcja „Epik natywny”), cennik `pricing` w `config.json` |
| Epik natywny: kryteria akceptacji | opis końcowego PR przez bramkę pochodzenia `tools/agent-security/github_trust.py` |
| Epik natywny: udział w limitach | transkrypty wszystkich sesji lokalnych w `~/.claude/projects` z przedziału epiku |

Brak źródła nie zatrzymuje pomiaru: pole dostaje `null` i powód w `gaps` (np. „brak `events.jsonl`” dla CART,
„próba bez wyniku CLI”, „błąd `gh`”). Zera bez danych są zakazane. Zapis rekordu nie zastępuje wartości niepustej
wartością `null` z błędu źródła (przejściowy błąd `gh`, `--no-gh`): zostaje poprzednia, a błąd trafia do `gaps`.

### Rekord epiku

`metrics.py epic <epik> [--ao-home DIR] [--manual-fixes FILE] [--no-gh]` wypisuje jedną linię JSON i zapisuje rekord.
Źródło: przebieg AO, gdy istnieje katalog `ao-<epik>` (albo `--ao-home`), inaczej plik przebiegu natywnego
`runs/<epik>.json` (sekcja „Epik natywny”); bez obu kod 2 z komunikatem, którego źródła brakuje, i bez zapisu.
`backfill` dotyczy tylko przebiegów AO. Poniżej pola rekordu AO; rekord natywny ma ten sam format. Czasy w sekundach,
kwoty w USD, przyrosty limitu w pp:

- `epic`, `source` (`ao`), `issue`, `final_pr`, `tasks` (liczba zadań planu), `started_at` (`epics.created_at`), `done_at`
  (pierwsze `epic_done`; bez niego `null` i pozycja w `gaps`), `merged_at`;
- `wall` (miara główna): `to_done` (start → `epic_done`), `to_merge` (start → merge), `review` (`epic_done` → merge,
  odbiór użytkownika);
- `breakdown`: podział `to_done` na rozłączne kategorie, które sumują się do `to_done`; każda chwila trafia do
  pierwszej pasującej (bez `done_at` cały `breakdown` to `null`, poza epikiem zamkniętym ręcznie, niżej):

  | Kategoria | Przedział |
  |---|---|
  | `work` | trwa co najmniej jedna próba (`started_at`–`ended_at`; próba bez końca do `epic_done`) |
  | `human_wait` | otwarte czekanie z `ao_events.waits` (bloker, awaria, `epic_blocked`, `queue_held`) |
  | `limit_wait` | `cooldown_set` (`from`) → `until` albo `cooldown_released`, co wcześniej |
  | `ci_wait` | `epic_ci_pending` → następne `epic_ci_green`, `epic_ci_inherited`, `epic_ci_inherited_red`, `epic_ci_timeout` albo `epic_repairing`; `final_pr_created` → `final_pr_ci` |
  | `host_pause` | `host_pause` (`from`–`to`) |
  | `github_wait` | `github_wait` → następne zdarzenie epiku inne niż `github_wait` i `gh_call`, najwyżej 30 min od ostatniego `github_wait` |
  | `idle` | reszta (AO nie działa, brak gotowych zadań) |

  Brak źródła kategorii (np. `events.jsonl`) przenosi jej czas do `idle` z pozycją w `gaps`. Pominięty jest też
  przedział, któremu brakuje danych: `cooldown_set` bez `until` i bez `cooldown_released` (`limit_wait`), `host_pause`
  bez `from`/`to` i `seconds` (`host_pause`) i próba bez `started_at` (`work`). Taka kategoria jest dolną granicą, a
  pominięty czas trafia do kategorii, która stoi dalej w `breakdown_order` i pokrywa ten przedział (każda chwila należy do
  pierwszej pokrywającej kategorii), albo do `idle`. Górną granicą jest więc każda kategoria po najwcześniejszej
  pominiętej w `breakdown_order` i `idle`. Rekord ma dolne granice w `breakdown_incomplete`, górne w
  `breakdown_upper_bound`, pozycję w `gaps`, a render pokazuje je z „≈” i dopiskiem „dolna granica” albo „górna granica”.
  Oznaczenie przechodzi do wąskich gardeł, `trend` (`work`, `human_wait`), D4 (`human_wait`, `limit_wait`, `ci`
  z `ci_wait` i mianownikiem `work`) i okien `effect` tych miar.

  Epik zamknięty ręcznie (AO bez `epic_done`, np. w `BLOCKED`, a końcowy PR zmergowany) ma rozkład od startu do
  merge'u końcowego PR (`to_merge`), z `breakdown_end: "merged_at"`, `breakdown_estimated: true` i pozycją
  `breakdown` w `gaps`: koniec jest przyjęty, więc każda kategoria jest szacunkiem z „≈” (w render „rozkład do
  merge'u”) i oznaczenie przechodzi dalej jak wyżej. `done_at`, `to_done` i `review` zostają `null`. Gdy AO nie zna
  końcowego PR (`epics.final_pr_number` pusty), `final_pr` to jedyny zmergowany PR `epic/<epik>` → `main` według `gh`,
  z pozycją `final_pr` w `gaps`; brak takiego PR albo kilka dają `null`.
- `in_attempts`: czas poleceń powłoki w próbach (nazwy narzędzi powłoki w `config.json`: `Bash`, `bash`) według
  kategorii z `config.json` (`test`, `e2e`, `check`, `selftest`, `stack` dla `make up` i `.ao/setup`, `ci_watch` dla
  `gh pr checks --watch` i `gh run watch`, `read` dla poleceń tylko do odczytu, `other` dla reszty), od `tool_use` do
  `tool_result` o tym samym identyfikatorze; polecenia w tle (`run_in_background`) liczone tylko jako liczba; do tego:
  - `repeat_runs` (heurystyka, dolna granica): liczba i czas uruchomień polecenia z kategorii `test`, `e2e` albo `check`, którego
    klucz jest taki sam jak wcześniejszego uruchomienia w tej samej próbie, bez zmiany kodu pomiędzy nimi. Klucz to
    kategoria i fragment polecenia dopasowany wzorcem kategorii z `config.json` (np. `make -C apps/api test` z jego
    argumentami, do najbliższego operatora powłoki `|`, `;`, `&&`, `>`), więc doklejone `| tail`, przekierowanie do
    pliku albo `; echo $?` nie zmieniają klucza. Zmiana kodu to dowolny `Edit`, `Write` albo `NotebookEdit` w próbie
    (każdej roli) albo polecenie kategorii `other`; kategorie `read`, `ci_watch`, `stack` i kategorie testów zmiany
    kodu nie oznaczają. Uruchomienie liczy się tylko, gdy brak zmiany kodu jest pewny: gdy między uruchomieniami
    trwało polecenie w tle, wywołanie narzędzia bez `tool_result` albo subagent bez końca w transkrypcie, uruchomienie
    nie wchodzi do `repeat_runs`. Raport nazywa je „możliwe powtórzenia testów”, nie zbędną pracą. Treść polecenia
    służy tylko do porównania i nie trafia do rekordu;
  - `api_retry`: liczba zdarzeń `system/api_retry` i suma `retry_delay_ms` (tylko w rekordzie i raporcie, nie jako
    wąskie gardło: w historii to pojedyncze sekundy);
  - `permission_denials`: liczba zdarzeń `system/permission_denied` według nazwy narzędzia (obejmuje subagentów
    i próby bez wyniku; pole `permission_denials` wyniku dotyczy tylko odcinka i nie jest używane);
- `by_task`: dla każdego zadania `work` (suma `wall_s` jego prób, także prób po `epic_done`, np. REPAIR konfliktu
  końcowego PR w czasie odbioru);
- `versions`: wersje CLI z `system/init` i modele z wiadomości `assistant` w próbach epiku (lista z liczbą prób;
  modele bez wiadomości, np. wewnętrzny Haiku CLI, nie wchodzą);
- `integration`: łączny czas prób zadań `integration`, `fix` i `finalize` i liczba rund (`epics.round`);
- `ci`: dla każdego PR zadania i końcowego PR czas ostatniego przebiegu `checks` i `e2e` oraz liczba przebiegów CI
  jego gałęzi (ponowienia);
- `attempts`: lista prób (`task`, `kind`, `number`, `outcome`, `executor_status`, `wall_s`, `value`, `estimated`,
  tokeny), z której liczy się historia median;
- `value`: wartość tokenów razem i w przekrojach `by_role`, `by_model`, `by_task`, `by_role_model`, `by_task_role`; tokeny (`input`,
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
- `gate_wait`: czas od decyzji o starcie epiku do jego startu, gdy bramka kazała mu czekać, i powody czekania.
  Decyzja to pierwszy wpis `gate --dir <epik>` (`dir_arg`) w `gate.jsonl` przed startem: `/ao-run` wywołuje go
  zaraz po prośbie użytkownika o start albo jego „tak” (sekcja 1), a w nocy zapisuje go `next` dla kandydata
  zatwierdzonej nocy. Czekania z przeglądu tablicy przed decyzją (`gate` bez `--dir`, propozycja „Zacząć epik?”) się
  nie liczą. Bez wpisu `waiting` epiku między decyzją a startem 0; czekania bez wpisu decyzji dają `null` z pozycją
  w `gaps`;
- `human`: liczba decyzji (`human_action`), liczba czekań i ich suma, czekania otwarte, ręczne poprawki;
- `waste`: alarmy z przebiegu (`waste.json`);
- `regressions`, `found_after_merge`: sekcja „Regresje po merge'u”;
- `bottlenecks`: sekcja „Wąskie gardła”;
- `gaps`: braki danych.

### Dokładność tokenów i wartości

Reguły liczenia z dokumentacji Claude Code (Agent SDK, „Track cost and usage”), sprawdzone na danych AO:
`output_tokens` w wiadomościach `assistant` to wartość zastępcza (na fixture'ach to 1–10% prawdziwego wyjścia);
`usage` wyniku `result` obejmuje tylko główną pętlę próby i tylko odcinek od poprzedniego wyniku; `modelUsage`
i `total_cost_usd` obejmują całe drzewo z subagentami, narastająco. Wiadomości `assistant` o tym samym `message.id`
liczą się raz (stream-json powtarza `usage` dla każdego bloku treści). Rola wiadomości: jej pole `subagent_type`;
wiadomość bez `parent_tool_use_id` należy do orkiestratora próby.

| Liczba | Źródło | Oznaczenie |
|---|---|---|
| Wartość próby i wartość modelu w próbie | `total_cost_usd`, `modelUsage.costUSD` ostatniego wyniku | dokładna (szacunek CLI, `costBasis`) |
| Tokeny modelu w próbie (wszystkie rodzaje) | `modelUsage` ostatniego wyniku | dokładne |
| Wejście, zapis i odczyt cache roli | deduplikowane wiadomości roli | dokładne |
| Wyjście orkiestratora próby | suma `usage.output_tokens` wszystkich wyników `result` próby | dokładne |
| Wyjście subagentów razem na modelu | `modelUsage.outputTokens` modelu − wyjście orkiestratora na tym modelu (orkiestrator używa jednego modelu: modelu z `system/init`) | dokładne |
| Wyjście jednego typu subagenta | wyjście subagentów razem na modelu × udział unikalnych `message.id` typu wśród subagentów na tym modelu | szacowane |
| Wartość roli | `costUSD` modelu × udział tokenów ważonych roli w tokenach ważonych modelu (wagi: wejście 1, zapis cache 5 min 1,25, zapis cache 1 h 2, odczyt cache 0,1, wyjście 5; proporcje cennika API w `config.json`, bez cen w repo) | dokładna tylko, gdy rola jest jedyną na tym modelu w próbie; inaczej szacowana |
| Wartość próby bez wyniku CLI (`timeout`, przerwanie, wynik po awarii) | tokeny wejścia i cache z wiadomości × mediana z historii stawki modelu: pełne `costUSD` modelu (z wyjściem) przez jego tokeny ważone wejścia i cache | szacowana |

Każda wartość w rekordzie ma `estimated: true`, gdy choć jeden jej składnik jest szacowany. Render pokazuje liczby
szacowane z „≈”, a przekrój ról z dopiskiem „podział między subagentów szacowany”. Gdy orkiestrator próby używa
więcej niż jednego modelu, wyjście subagentów na tych modelach jest szacowane (proporcja `message.id`), z pozycją
w `gaps`. Wiadomości i `modelUsage` czasem się rozchodzą (kompakcja, wywołania wewnętrzne CLI; w historii 7 z 574
par próba–model): nadwyżka `modelUsage` nad wiadomościami trafia do roli `other`, a niedobór oznacza role tego
modelu jako szacowane, z pozycją w `gaps`.

Reguła zachowawcza: liczba szacowana, niepełna albo wyprowadzona z takich danych (średnia, mediana, iloraz, różnica,
udział, próg) ma `estimated: true`, a gdy brakuje jej części, także `incomplete: true` albo pozycję w `gaps`, i każde
jej przedstawienie (JSON, raport, komentarz, push, `/retro`) pokazuje ją z „≈” (suma niepełna: „≈” i dopisek „suma
niepełna” albo „dolna granica”). Próba bez transkryptu może należeć do każdej roli i modelu, więc oznacza niepełne
wszystkie przekroje `value`, do których mogła należeć (role zadania w `by_task_role` tylko jej zadania). Czas zadania,
czas integracji i czas poleceń w próbach (`in_attempts`) bez części prób (brak czasu albo transkryptu) to dolna granica:
`incomplete: true` i pozycja w `gaps`; render dopisuje „dolna granica”, a przy `in_attempts` „transkrypty tylko N z M
prób” i „≈” przy każdej liczbie. Wąskie gardło, kandydat `decide`, okno `effect` i alarm `waste` (`estimated` albo
`median_estimated`) zbudowane z takiej liczby (także z mediany historii, w której jest choć jedna) są oznaczone jako
szacowane; prognoza `expected_effect` jest szacowana tylko wtedy, gdy dane, z których wynika, są szacowane lub niepełne.

### Epik natywny

Epik prowadzony natywnie (`docs/features/ash-v1/spec.md`, M1–M5) nie ma bazy AO. Punkt odniesienia zapisuje sesja
prowadząca komendami `start` i `done`, a rekord powstaje z transkryptów jej sesji.

**Plik przebiegu.** `metrics.py start <epik>` zapisuje `runs/<epik>.json`: `started_at` (teraz albo `--at`), `base_sha`
(SHA `origin/main`: `git rev-parse`, przy `--at` ostatni commit `origin/main` sprzed tej chwili; błąd gita daje `null`
i ostrzeżenie), `branch` (gałąź epiku: `--branch` albo bieżąca gałąź katalogu roboczego), `pr` (numer draft PR: `--pr`
albo `gh pr view <gałąź>`), `tasks` (`--tasks` albo liczba zadań manifestu `origin/main:docs/features/<epik>/plan.md`,
ta sama funkcja co `night_queue.py start`), `sessions` (`[{id, worktree, at}]`: identyfikator sesji prowadzącej,
`--session` albo najnowszy transkrypt `<id>.jsonl` w katalogu sesji katalogu roboczego `--worktree`, domyślnie
bieżącego), `usage_start` (odczyt limitów z wejścia standardowego czytany jak w `night_queue.py start`: obiekt `plan`
z `get_usage` albo cały obiekt `get_usage` `{"plan": …, "context": …}`, z którego brany jest `plan`; terminal albo puste
wejście to `null`), `done_at` i `usage_done` (`null`). `metrics.py done <epik>` wpisuje czas
oznaczenia PR jako gotowy (teraz albo `--at`; kolejne wywołanie go zastępuje) i drugi odczyt limitów. `start --resume`
dopisuje do `sessions` nową sesję z jej katalogiem roboczym (raz). Bez gałęzi, PR, liczby zadań albo transkryptu sesji
`start` kończy się kodem 2 z nazwą opcji, która to poda; drugi `start` bez `--resume` i `--resume` albo `done` bez pliku
przebiegu też dają kod 2, bez zapisu. Opcje `--at`, `--worktree`, `--branch`, `--pr`, `--tasks` i `--session` pozwalają
zapisać plik po fakcie, także gdy `plan.md` już nie istnieje.

**Transkrypty epiku.** Tylko sesje z pliku przebiegu: `<id>.jsonl` w katalogu projektów Claude Code
(`~/.claude/projects/*/`, opcja `--projects`) i ich subagenci `<id>/subagents/agent-<agent>.jsonl` z
`agent-<agent>.meta.json`. Inna sesja w tym samym katalogu roboczym do epiku nie należy. Liczą się wiadomości od
`started_at` do merge'u końcowego PR (praca po merge'u nie wchodzi; bez znanego merge'u do końca transkryptów,
z pozycją `sessions` w `gaps`), każdy `message.id` raz: pierwsze wystąpienie w kolejności sesji z pliku przebiegu
(prowadzący, potem subagenci), także w sesji wznowionej albo rozgałęzionej. Wiadomość bez `timestamp` się liczy,
z pozycją w `gaps`. Czytane pola: wiadomości `assistant` (`message.id`, `message.model`, `message.usage`, `timestamp`,
bloki `tool_use`, w tym `SubagentHandback` z tekstem raportu), `tool_result` w wiadomościach użytkownika, `origin.kind`
i `uuid` linii użytkownika, `timestamp` linii i `version` (wersja CLI); z `meta.json` `agentType`, `description`,
`model`, `effort`, `finished`. Opis zlecenia służy tylko do ustalenia zadania, a tekst raportu tylko do odczytu
werdyktu; żaden nie trafia do rekordu.

**Rola i zadanie (D4).** Sesja prowadząca to rola `orchestrator` bez zadania; przebieg subagenta to rola z `agentType`
i zadanie, którego ID (`<EPIK>-<n>`, wielkość liter bez znaczenia) występuje w `description` jako pierwsze. Bez ID
przebieg należy do pozycji `no_task` („bez zadania”), tak jak sesja prowadząca.

**Wartość (D3).** Transkrypt sesji natywnej nie ma kosztu policzonego przez CLI, więc wartość wiadomości to suma tokenów
każdego rodzaju razy stawka cennika API z `config.json` (`pricing`: dla każdego modelu USD za milion tokenów wejścia,
wyjścia, odczytu cache, zapisu cache 5 min i zapisu cache 1 h; ze źródłem i datą odczytu, zapisanymi w rekordzie jako
`pricing`). Zapis cache dzieli `usage.cache_creation` (`ephemeral_5m_input_tokens`, `ephemeral_1h_input_tokens`).
Wartość jest zawsze szacunkiem (`estimated: true` w każdej komórce), nieporównywalnym co do centa z kosztem z CLI
w epikach AO. Wiadomość bez `message.usage`, bez modelu, z modelem bez stawek, z rodzajem tokenów bez stawki albo
z zapisem cache bez rozbicia ma wartość nieznaną: komórka `usd: null` albo przekrój z `incomplete: true` (znana część
w sumie) i pozycja `value` w `gaps`; wiadomość bez tokenów (np. `<synthetic>`) stawki nie potrzebuje.

**Pola rekordu natywnego.**

| Pole | Źródło natywne |
|---|---|
| `source` | `native` |
| `tasks`, `started_at`, `done_at` | plik przebiegu (brak: `null` i pozycja w `gaps`) |
| `final_pr`, `issue`, `merged_at`, `wall` | `pr` z pliku przebiegu i `gh pr view`, jak w AO; `to_done` to `started_at` → `done_at` |
| `value` | przekroje jak w AO (`total`, `by_role`, `by_model`, `by_task`, `by_role_model`, `by_task_role`), wartość z cennika |
| `attempts` | jeden wpis na przebieg subagenta: `agent_id`, `task`, `role` (`agentType`), `model` (najczęstszy model jego wiadomości), `effort`, `started_at` i `ended_at` (pierwsza i ostatnia wiadomość w przedziale epiku), `wall_s`, `tokens`, `value`, `estimated`, `finished` (z `meta.json`); bez `meta.json` rola, zadanie, effort i `finished` to `null` z pozycją w `gaps` (w przekrojach `value` rola `subagent`) |
| `by_task` | `{zadanie: {kind: null, work, attempts}}`: suma `wall_s` i liczba przebiegów na zadanie (`no_task` dla przebiegów bez zadania) |
| `in_attempts`, `versions` | polecenia powłoki i wersja CLI ze wszystkich transkryptów epiku (prowadzący i subagenci), reguły jak w AO |
| `ci` | `final`: przebiegi CI gałęzi `branch` z pliku przebiegu i PR `pr`; `tasks`: `null` |
| `regressions`, `found_after_merge`, `bottlenecks` | jak w AO |
| `sessions` | identyfikatory sesji z pliku przebiegu (bez katalogów roboczych) |
| `pricing` | `source` i `read_at` cennika, z którego policzono wartość |
| `breakdown` | `work`, `human_wait`, `idle` od `started_at` do `done_at` (niżej, „Czas i człowiek”); `limit_wait`, `ci_wait`, `host_pause`, `github_wait` `null` z pozycją w `gaps`, rekord ma `breakdown_incomplete: true`; bez `started_at` albo `done_at` całe `null` |
| `human` | `interventions` (szacunek, `interventions_estimated: true`), `waits`, `wait_s`, `events` (`[{at, wait_s}]`), `manual_fixes` z `--manual-fixes` (bez opcji `null` z pozycją w `gaps`) |
| `reviews` | lista przeglądów (niżej, „Przeglądy”); pusta bez przebiegów ról przeglądowych |
| `integration` | `work_s` (suma `wall_s`) i `attempts` przebiegów `architecture-reviewer`, `qa-adversarial`, `ux-reviewer`; `rounds`: największa liczba raportów jednej z tych ról; bez takich przebiegów `null` z pozycją w `gaps` |
| `retries` | `fix`: przeglądy zadań (`reviews` z zadaniem) z werdyktem blokującym; `failed_by_outcome`: `{unfinished: n}` przebiegów z `finished: false` (`{}` bez nich; przebieg bez `finished` to pozycja w `gaps`, liczba jest dolną granicą); `attempts`, `repair`, `sync_prs` `null` z pozycją w `gaps` |
| `acceptance` | liczby z tabeli kryteriów opisu końcowego PR (niżej, „Kryteria akceptacji”); brak: `null` z pozycją w `gaps` |
| `subscription` | `windows`: przyrost każdego okna limitu między `usage_start` i `usage_done` (reguły `night_queue._window_deltas`: okno odnowione między odczytami albo stan inny niż `ok` daje `null` z pozycją w `gaps`); `share`: udział tokenów epiku w tokenach sesji lokalnych (niżej), `share_estimated: true`; `overlap` `null` z pozycją w `gaps`; bez obu odczytów całe `null` z pozycją w `gaps` |
| `parallel`, `gate_wait`, `waste` | `null`, pozycja w `gaps` („brak źródła w przebiegu natywnym”) |

**Czas i człowiek.** Każda linia użytkownika albo asystenta z `timestamp` mówi, co działo się w przerwie przed nią,
od poprzedniej linii tego samego transkryptu: wiadomość asystenta albo `tool_result` to praca (prowadzący albo subagent
działał), wiadomość z `origin.kind = "human"` to przerwa zakończona wiadomością człowieka, każda inna linia użytkownika
(raport subagenta, powiadomienie zadania w tle, wznowienie subagenta, linia meta) to czekanie, które nie jest pracą.
`work` to długość sumy (unii) przedziałów pracy wszystkich transkryptów epiku, `human_wait` to przerwy przed
wiadomością człowieka bez części pokrytej pracą (pytanie o postęp, gdy subagenci pracują, nie jest czekaniem), `idle` to reszta czasu
`started_at` → `done_at`. `human.interventions` liczy wiadomości człowieka po `started_at` (polecenie startu jest
przed `metrics.py start`) do merge'u końcowego PR, każdą raz (`uuid` linii, bez niego czas; sesja wznowiona powtarza
linie), więc jest górną granicą interwencji koniecznych. Zdarzenie w `events` ma czas wiadomości i `wait_s`: część
przerwy przed nią od `started_at`, której nie pokrywa praca; `waits` to zdarzenia z `wait_s` > 0, `wait_s` ich suma
(zdarzenia po `done_at` liczą się tu, ale nie w `breakdown`). Wiadomość człowieka bez `timestamp` jest pomijana
z pozycją `human` w `gaps`.

**Przeglądy (M4).** Raport to wywołanie `SubagentHandback` w transkrypcie subagenta roli `code-reviewer`,
`qa-adversarial`, `architecture-reviewer` albo `ux-reviewer`, w przedziale epiku. Wpis: `task` (zadanie według D4,
`null` dla przeglądu całej zmiany), `role`, `agent_id`, `at`, `mode` (pierwszy raport transkryptu `FULL`, każdy
kolejny, po wznowieniu subagenta, `DELTA`), `verdict` i `blocking`. Werdykt to pierwsze słowo werdyktu tuż po
„Werdykt” albo „Verdict” w tekście raportu, przyjęte tylko z kontraktu roli: `code-reviewer` `PASS` /
`CHANGES_REQUIRED`, `qa-adversarial` `PASS` / `INVESTIGATE` / `BLOCK`, `architecture-reviewer` `KEEP` / `SIMPLIFY` /
`REWORK`; blokują `CHANGES_REQUIRED`, `BLOCK` i `REWORK`. Raport bez rozpoznanego werdyktu (dziś zawsze `ux-reviewer`,
który nie ma werdyktu) ma `verdict` i `blocking` `null` z pozycją `reviews` w `gaps`; przebieg roli przeglądowej bez
`SubagentHandback` też jest pozycją w `gaps`. Lista jest w kolejności czasu. Tekst raportu nie trafia do rekordu.

**Kryteria akceptacji (M5).** Opis końcowego PR rekord czyta wyłącznie przez bramkę pochodzenia
(`tools/agent-security/github_trust.py`, `prs --head <gałąź> --state all`, pole `body`): od autora bez prawa zapisu
bramka nie podaje opisu i `acceptance` ma `null` z pozycją w `gaps`. W opisie liczy się tabela `| Numer | Sposób |
Wynik |` w sekcji z nagłówkiem „Kryteria akceptacji” (do następnego nagłówka; format: `docs/adr/agentic-software-house.md`,
„Przebieg epiku”, krok 7). `acceptance`: `total` (wiersze tabeli), `stack`, `ci`, `after_deploy`, `unverified`
(kolumna „Sposób” zaczyna się od „stos”, „CI”, „po wdrożeniu”, „niezweryfikowane”, wielkość liter, odwrócone apostrofy
i pogrubienie bez znaczenia). Wiersz z innym sposobem liczy się tylko w `total`, z pozycją w `gaps`. Brak sekcji,
tabeli albo wierszy, opis pusty, PR niezwrócony przez bramkę albo `--no-gh` dają `null` z pozycją w `gaps`; błąd bramki
także kod 3. Rekord zapisuje same liczby; wartość zapisana wcześniej zostaje, gdy źródło jest chwilowo niedostępne.

**Udział w limitach (`share`).** Limity subskrypcji są na konto, więc mianownikiem są tokeny wszystkich lokalnych
sesji i ich subagentów ze wszystkich projektów w katalogu `--projects` (`~/.claude/projects`) od `started_at` do
`done_at`, także równoległych sesji innych prac; licznikiem tokeny epiku z tego przedziału. Każdy `message.id` liczy
się raz, tokeny są ważone `token_weights` (wejście 1, zapis cache 5 min 1,25 i 1 h 2, odczyt cache 0,1, wyjście 5).
Transkrypt zmodyfikowany ostatnio przed `started_at` nie jest czytany. Użycia z innych urządzeń nie widać, więc `share`
jest szacunkiem (`share_estimated: true`); bez tokenów w przedziale `share` ma `null` z pozycją w `gaps`.

Rekord natywny nie zawiera treści wiadomości, zleceń, raportów, opisu PR, poleceń ani ścieżek katalogów roboczych. Format transkryptów nie
jest stabilnym interfejsem CLI: brak pola daje `null` i pozycję w `gaps`, nie błąd. Testy używają syntetycznych
transkryptów z samymi czytanymi polami; treść prawdziwych transkryptów nie trafia do repozytorium.

### Historia i trend

- `metrics.py backfill`: rekord każdego przebiegu epiku w `~/.local/state/ao-*` z końcowym PR `MERGED`; braki
  starszych epików w `gaps`. Uruchamia go sesja `/ao-run`, gdy katalog rekordów jest pusty.
- `metrics.py trend [--last N]`: tabela zmergowanych epików (domyślnie 10 ostatnich) z wartościami na zadanie planu:
  czas do `epic_done`, `work`, `human_wait`, `review`, `gate_wait`, `repeat_runs`, wartość tokenów, pp okna tygodniowego (`share`), próby nieudane, REPAIR,
  interwencje, regresje; mediana każdej kolumny (z pominięciem `null`) i pozycja wskazanego epiku względem niej.
  Wartość tokenów, pp i `repeat_runs` z szacowanego albo niepełnego źródła (`estimated`, `share_estimated`,
  `in_attempts.incomplete`) mają „≈” w tabeli, w pozycji względem mediany razem z procentem różnicy (także w raporcie odbioru)
  i znacznik w `--json`, tak samo jak w `render`.
- Rekordy z otwartym oknem regresji przelicza każde `trend` (merge kolejnego epiku, start `/ao-run`).
- Epik z inną wersją CLI albo innym zestawem modeli niż poprzedni ma w `trend` adnotację zmiany (bez korekty liczb).

### Wąskie gardła

`bottlenecks` rekordu: najwyżej 3 pozycje z największą nadwyżką czasu ponad medianę historii, w godzinach,
uszeregowane malejąco; pozycja wchodzi, gdy nadwyżka jest co najmniej 0,5 h (próg w `config.json`). Kandydaci:

- każda kategoria `breakdown` poza `work`: nadwyżka = (wartość na zadanie planu − mediana historii na zadanie planu)
  × liczba zadań planu;
- każde zadanie: `work` zadania − mediana `work` zadań tego `kind` w historii;
- każda kategoria `in_attempts`: jak kategorie `breakdown`, na zadanie planu; pozycja pokazuje obok, ile z nadwyżki
  to `repeat_runs` tej kategorii („w tym możliwe powtórzenia: X h”), bez osobnej pozycji dla powtórzeń;
- `gate_wait` i `review`: jak kategorie `breakdown`, na zadanie planu. Przejście bramki bez czekania daje 0, brak
  wpisów epiku w `gate.jsonl` (albo wpisu decyzji o starcie przy czekaniu) daje `null` z pozycją w `gaps` (kandydat pominięty). `review` jest znany dopiero po
  merge'u, więc wchodzi do bloku po merge'u, `trend` i `/retro`, nie do raportu odbioru.

Każda pozycja ma:

- rodzaj i nazwę (np. `human_wait`, `DEPLOY-OPS-4`, `in_attempts.e2e`), wartość i medianę;
- koszt czasowy: nadwyżkę w godzinach i jej udział w `to_done` (procent czasu zegarowego epiku), dla `gate_wait`
  udział w `gate_wait` + `to_done`, a dla `review` udział w `to_merge`;
- klucz reguły decyzji, która jej dotyczy (D2 dla zadania, D3 dla `gate_wait`, D4 dla kategorii czekania i `review`;
  `null`, gdy żadna);
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
- **W2 trwająca próba (czasowy):** czas > 3× mediana `wall_s` prób tego `kind` i > 1 h;
- **W3 pętla prób (niezależna od progów mediany i kwoty):** zadanie zaczyna trzecią próbę po dwóch nieudanych
  (zablokowane się nie liczą), drugi REPAIR epiku z rzędu albo runda QA równa `--max-qa-rounds`;
- **W4 koszt zadania (kosztowy):** łączna wartość prób zadania > 3× mediana wartości zadań tego `kind` i ≥ 5 USD.
  Zadanie z trwającą próbą ma sumę zakończonych prób, czyli dolną granicę: alarm ma `incomplete: true`, a push mówi
  „zakończone próby kosztują”. Zadanie historii z próbą bez `ended_at` nie jest pomiarem W4 (suma byłaby częściowa).

Mediana wymaga co najmniej 5 pomiarów w historii; bez nich W1, W2 i W4 nie alarmują (pozycja w `gaps`). Sesja
`/ao-run` wywołuje `waste` przy każdym wybudzeniu przez zdarzenie AO i przy każdym ponownym uzbrojeniu Monitora
(najrzadziej co 30 minut). Alarm to push (do 200 znaków), np. „deploy-ops: DEPLOY-OPS-4 próba 3 trwa 4,1 h (3,2×
mediany); 3. próba po 2 nieudanych”, i pozycja w sekcji „Pomiar” raportu odbioru. Sesja niczego nie zatrzymuje:
`ao release` i inne komendy wykonuje tylko na polecenie użytkownika. Limity AO (`--max-budget-usd`, `--timeout`,
`--epic-max-attempts`) działają jak dziś.

### Decyzje optymalizacyjne

`metrics.py decide <epik> [--history]` jest bezstanowy: buduje kandydatów decyzji z rekordu epiku, trendu i wyników
EVALS 2.0 (dla D1, opcjonalnie), według reguł z `config.json`. Każdy kandydat ma klucz (reguła i przedmiot, np. `D1:code-reviewer`), dane, propozycję, oczekiwany
efekt i miarę weryfikacji. `--history` wypisuje kandydatów każdego zmergowanego epiku (wejście `/retro`).

- **D1 model dla roli:** rola agenta ma ≥ 15% wartości tokenów epiku na modelu droższym niż najtańszy używany
  w epiku. D1 nigdy sam nie proponuje zmiany modelu: METRICS nie ocenia jakości agenta, a EVALS 2.0 nie zapisuje
  przyjęcia wariantu w formie do odczytu (decyzja właściciela jest w raporcie `docs/reports/evals-2-…`). Wynik D1
  zależy od wyników EVALS 2.0 dla tej roli i tańszego modelu:
  - **rola bez evali** (spoza listy ról EVALS 2.0 w `config.json`; dziś tylko `code-reviewer`, bo `tools/evals-pilot`
    ocenia wyłącznie `.claude/agents/code-reviewer.md`): bez propozycji, przyczyna „bez evali”;
  - **brak porównania** (żadnego zaliczanego przebiegu roli na tańszym modelu): propozycja eksperymentu porównania
    wariantów EVALS 2.0 (`docs/features/evals-2/spec.md`, „Porównanie wariantów agenta”: wariant to commit ze zmianą
    `model:` w pliku agenta, `--agent-rev`; płatny pomiar wymaga zgody właściciela), nie zmiany modelu;
  - **porównanie wykonane** (są zaliczane przebiegi roli na tańszym modelu): bez propozycji, przyczyna „porównanie
    w EVALS 2.0, decyzja w jego raporcie”, w danych katalogi wyników i liczba przebiegów.

  Kandydatem (kolejność, raport odbioru, `--history`) jest tylko wynik z propozycją; pozostałe są w wyniku `decide`
  na liście pominiętych reguł z przyczyną. Efekt kandydata to potencjalna oszczędność, gdy porównanie wypadnie
  dobrze: wartość roli × (1 − stosunek mediany stawki tańszego modelu do droższego z historii).

  **Wyniki EVALS 2.0** (format sprawdzony 2026-11-16 na 41 przebiegach): surowe wyniki są poza repozytorium,
  w katalogu z `config.json` (`evals_results`, domyślnie `~/.local/state/shop-evals-pilot`), po jednym podkatalogu
  pomiaru. D1 czyta tylko `<katalog>/*/runs/*/meta.json` i z niego tylko pola `status`, `mode` i `subagent_model`
  (model subagenta rozwiązany przez CLI, np. `claude-opus-5-5`, porównywany dokładnie z identyfikatorami modeli
  z rekordu). Zaliczany przebieg ma `status` `ok` i `mode` `replay`. Tryb `squash` (`mode` puste, agent jako wątek
  główny, bez `subagent_model`) różni się od produkcji bardziej i nie jest zaliczany; tak samo `infra_error`
  i `budget_stop`. `meta.json` nie wskazuje agenta, więc przebieg należy do jedynej roli z listy w `config.json`.
  Etykiet (`labels.json`) ani raportów D1 nie czyta. Brak katalogu albo brak czytelnego `meta.json`: D1 nie
  powstaje, przyczyna w `gaps`; nieczytelny plik albo plik bez tych pól jest pomijany i liczony w `gaps`. Brak
  wyników EVALS 2.0 nigdy nie blokuje METRICS ani innych reguł.
- **D2 podział planu:** zadanie planu z `work` > 2× mediany zadań planu w historii albo z co najmniej 2 próbami
  nieudanymi. Propozycja: reguła podziału dla `/planner` z cechami zadania (liczba ścieżek `scope`, ryzyko, aplikacje,
  liczba zmienionych plików). Efekt: czas ponad medianę.
- **D3 kolejka pracy równoległej:** `gate_wait` ≥ 1 h z powodu reguły plików bramki. Propozycja: zmiana linii
  `Zakres:` przy przygotowaniu albo reguły plików (`docs/features/parallel-work/spec.md`, „Bramka”). Efekt: `gate_wait`.
  Decyzji o 19b nie dotyczy: jej kryterium jest w planie (pomiar par, `pairs.py`).
- **D4 czekanie:** `human_wait` ≥ 20% `to_done` (pytania przed startem, godzina startu), `ci_wait` +
  `in_attempts.ci_watch` ≥ 20% `work` (CI), `limit_wait` ≥ 10% `to_done` (planowanie nocy), `review` ≥ 20% `to_merge`
  (pora oddania do odbioru, rozmiar końcowego PR; tylko po merge'u). Efekt: udział × `to_done` (dla `review`:
  × `to_merge`).

Kolejność: oczekiwane skrócenie czasu zegarowego, potem wartość tokenów. Wartości szacowane (sekcja „Dokładność”)
w danych kandydata mają oznaczenie; D1 na wartości roli szacowanej podaje to w propozycji.

**Efekt decyzji:** `metrics.py effect <klucz> --at <czas merge'u poprawki>` liczy miarę weryfikacji reguły:

| Reguła | Miara | Poziom | Jednostka |
|---|---|---|---|
| D1 | wartość roli na zadanie | zadania tego `kind` | USD |
| D2 | `work` zadania | zadania `plan` | h |
| D3 | `gate_wait` | epiki | h |
| D4 | udział kategorii czekania z reguły w jej mianowniku | epiki | pp |

Okno „po” to epiki rozpoczęte po `--at` (`started_at > --at`); epiki rozpoczęte przed `--at` i zmergowane po nim
pomijamy, bo pracowały na gałęzi sprzed poprawki. Okno „przed” to tyle samo epików zmergowanych przed `--at`,
najbliższych tej chwili; gdy zmergowanych przed `--at` jest mniej niż w oknie „po”, okno „przed” ma wszystkie, jest
szacowane (`estimated`, „≈”) i ma pozycję w `gaps`. Wynik: mediana, p25–p75 i liczebność obu okien, różnica median w jednostce miary
i w procentach. Obok zawsze czas do `epic_done` na zadanie planu jako mediana 3 epików przed i 3 po. „Za mało danych”
(bez wniosku): dla miar zadań mniej niż 15 zadań z co najmniej 3 epików po zmianie, dla miar epiku mniej niż 3 epiki
po zmianie. Wersje CLI i modele, które różnią okno przed od okna po (z `versions`), są wypisane obok efektu, bez
korekty liczb.

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
`tech-debt` (zmiana modelu roli objętej EVALS 2.0 wymaga przed merge'em porównania wariantów EVALS 2.0 i decyzji
właściciela). Raport `/retro` kończy się sekcją
„Decyzje optymalizacyjne”: pozycje z incydentów `optimization` w tym przebiegu, obserwacje (reguła w jednym epiku),
i dla kluczy z `applied.jsonl` efekt z `metrics.py effect` (`--at`: merge PR z poprawką), przepisany bez własnych
obliczeń agenta: mediana, rozrzut i liczebność przed i po, czas epiku, zmiany wersji albo „za mało danych”.

### Raport odbioru i komentarz w issue

- Raport odbioru (`/ao-run`, punkt 5) dostaje sekcję „Pomiar” z `metrics.py render <epik> --format report`: czas do
  `epic_done` i rozkład, czas w próbach według kategorii (z powtórzonymi testami,
  ponowieniami API i odmowami), integracja, wartość tokenów (razem, role, modele, najdroższe zadanie; szacowane
  z „≈”), koszt subskrypcji w pp (z `share` przy nakładaniu), próby, REPAIR, FIX, sync, czekanie w bramce,
  interwencje, alarmy, wersje CLI i modele, pozycja na tle mediany, braki danych; na początku wąskie gardła. Istniejące pozycje raportu
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

1. Komentarz pomiaru albo rekord ujawnia treści z przebiegu. Zamyka: `render --format comment` przyjmuje tylko pola
   liczbowe, ID zadań, nazwy ról i modeli, wersje CLI oraz numery PR, a treść poleceń Bash służy tylko do porównania
   w `repeat_runs` i nie trafia do rekordu. Sprawdzenie: test renderu na rekordzie z polami tekstowymi z danych
   przebiegu (komunikat błędu, ścieżka `/Users/`, polecenie) i test rekordu z transkryptu z poleceniem zawierającym
   ścieżkę `/Users/` (brak treści polecenia w rekordzie).
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
    identyfikatorów, stanów, liczb i czasów (w tym `tasks.depends_on`: lista JSON identyfikatorów zadań); usunięte kolumny tekstowe i ścieżki (`result`, `error`, `warnings`,
    `answers`, `blockers`, `reason`, `command`, `dir`, `workdir`, `state_file`, `spec_path`, `brief_path`, `repo`,
    `config`, `plan_path`); `events.data` tylko z kluczami liczbowymi, czasowymi i identyfikatorami (`from`, `to`,
    `until`, `seconds`, `sha`, `task_id`, `epic_id`, `final_pr`, `status`), bez `error`, `what`, `reason`
    i adresów URL;
  - `stdout.jsonl` wybranych prób (wszystkie próby DEPLOY-OPS, w tym próba `timeout` DEPLOY-OPS-4 nr 3;
    CATALOG-ADMIN-INTEGRATION-2 z 9 wynikami i subagentami; CATALOG-ADMIN-INTEGRATION-1 z odmowami uprawnień;
    CATALOG-ADMIN-4 z powtórzonymi testami i 22 wynikami; ADMIN-DESIGN-3 z ponowieniami API): pola `type`, `subtype`, `subagent_type`, `message.id`, `message.model`,
    `message.usage`, `timestamp`, `parent_tool_use_id`, nazwa narzędzia, `run_in_background`, `is_error`,
    `modelUsage`, `total_cost_usd`, `usage` wyniku, `model` i `claude_code_version` z `system/init`,
    `retry_delay_ms`, `attempt` i `error_status` z `system/api_retry`, `tool_name` z `system/permission_denied` (bez
    `message` i `decision_reason`), `id` bloku `tool_use` i `tool_use_id` bloku `tool_result` (numerowane od nowa
    w pliku); polecenie powłoki zastąpione kanonicznym poleceniem swojej kategorii z `config.json` (także `read`;
    polecenie `other` jako `true`) z numerem (`# c<n>`), który jest taki sam dla poleceń o tym samym kluczu
    `repeat_runs`, więc powtórzenia zostają rozpoznawalne bez treści; bez treści wiadomości, promptów, parametrów innych narzędzi, wyników narzędzi, `cwd`, ścieżek z `system/init`
    i tekstu `result`;
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
  `/ao-run metrics` (Q9; zgoda udzielona 2026-11-15).
- Reguła D1 czyta wyniki EVALS 2.0 (`tools/evals-pilot`, surowe wyniki poza repo), gdy są; bez nich D1 nie powstaje
  (pozycja w `gaps`), więc METRICS nie czeka na EVALS 2.0. `## Zależności` issue #496 nie wymienia ID innego epiku
  (lista nocy traktuje każde wymienione ID otwartego epiku jako zależność): telemetria jest tam opisana numerem #577.

Sekretów, kont i usług zewnętrznych epik nie potrzebuje: czyta lokalne dane AO i publiczny GitHub przez `gh`
użytkownika.

## Po merge'u

`metrics.py backfill` uruchamia sesja `/ao-run` przy pierwszym starcie po merge'u (pusty katalog rekordów).

## Poza zakresem

- Telemetria Claude Code w lokalnym stosie na Macu: epik TELEMETRY (#577).
- Monitoring produkcji i dyżur agenta (krok 11), dokumentacja i studium przypadku (krok 21).
- Pomiar `/sweep` i `/retro` w rekordzie epiku: mają własne sekcje `## Zużycie` (pp i czas).
- Automatyczne zatrzymywanie prób przy alarmie i zmiany AO (zamrożone).
- Decyzja o 19b (kryterium w planie, pomiar par `pairs.py`) i liczba zadań naraz po 19b.
- Evale innych ról i zmiany EVALS 2.0 (format wyników, zapis decyzji o wariancie): D1 obejmie rolę po dopisaniu jej
  do listy ról EVALS 2.0 w `config.json`, gdy EVALS 2.0 będzie ją oceniać.
- Gotowe narzędzia jako źródło (`ccusage` i podobne czytają sesje z `~/.claude/projects`, nie znają prób i ról AO),
  integracje z zewnętrznymi systemami obserwowalności (Langfuse, Phoenix, własny eksport OTel), dashboardy
  i detektory pętli oparte na LLM.
- Reguła decyzji dla powtórzonych testów (zasada w instrukcji roli): najpierw dane z `repeat_runs`.
- Trafność alarmów (ile alarmów dotyczyło prób zakończonych porażką, REPAIR albo interwencją): odłożona.
- Czas krytyczny zadań i podział `work` na pracę jednej i kilku prób naraz: do kroku 19b próby epiku nie biegną
  naraz (w historii ani razu), więc kandydat zadania i D2 używają `work` zadania. Wraca z 19b, wtedy prostym
  algorytmem z zależności i równoległości.

## Zmiany dokumentacji (razem z implementacją)

- `docs/features/ao-run/spec.md` i `.claude/skills/ao-run/SKILL.md`: sekcje „Pomiar” (z wąskimi gardłami) i „Decyzje
  optymalizacyjne” raportu odbioru, blok „Pomiar epiku” w komentarzu po merge'u, `waste` przy wybudzeniach, `trend`
  przy merge'u i starcie, `backfill` przy pustym katalogu rekordów.
- `docs/features/retro/spec.md`, `.claude/skills/retro/SKILL.md`, `.claude/agents/retro-analyst.md`: incydenty
  `optimization`, fakty z pomiaru w `input.json`, sekcja „Decyzje optymalizacyjne” raportu z efektem przed i po.
- `.github/ISSUE_TEMPLATE/bug.yml`: pole „Wprowadzone przez (epik albo PR, jeśli znane)”.
- `docs/adr/agentic-software-house.md`: komponent „Metryki i evale” wskazuje `tools/metrics` i `tools/evals-pilot`.
- `docs/features/agentic-software-house/plan.md`: krok 18 (podział na METRICS i TELEMETRY #577) i rola „Pomiar
  czasu, kosztu i jakości” ze wskazaniem `tools/metrics/README.md`; punkt 5 procesu (komentarz w issue epiku to blok
  „Pomiar epiku”).
- `tools/metrics/README.md`: komendy, format rekordu, reguły alarmów i decyzji, konfiguracja.

## Kryteria akceptacji

1. `metrics.py epic` na fixture DEPLOY-OPS: kategorie `breakdown` sumują się do `to_done`; `value.by_role` sumuje się do sumy wartości prób; próba `timeout` (DEPLOY-OPS-4 nr 3)
   ma tokeny i wartość `estimated`; każda kategoria `breakdown` ma test z przedziałem z tabeli (w tym `github_wait`
   z limitem 30 min).
2. Próba z kilkoma wynikami `result` (fixture CATALOG-ADMIN-INTEGRATION-2) ma wartość z ostatniego, równą
   `attempts.cost_usd`; wiadomości o tym samym `message.id` liczą się raz; model bez wiadomości trafia do roli `other`.
3. Tokeny według sekcji „Dokładność”: wyjście orkiestratora próby równa się sumie `usage.output_tokens` wszystkich
   wyników (testy na prawdziwych fixture'ach prób z subagentami i z kilkoma wynikami `result`, w tym próbie z oboma
   naraz; żaden token nie jest liczony dwa razy: suma ról każdego rodzaju tokenów na modelu nie przekracza
   `modelUsage`), suma wyjścia ról na modelu równa się `modelUsage.outputTokens` tego modelu,
   a wejście i cache ról sumują się do `modelUsage` w próbie bez rozbieżności (nadwyżka `modelUsage` trafia do
   `other`, niedobór daje role szacowane);
   wyjście typu subagenta i wartość roli dzielącej model z inną rolą mają `estimated: true`, a rola jedyna na modelu
   ma wartość dokładną; wynik po awarii (zerowy `modelUsage` przy niezerowych tokenach) daje próbę bez wyniku CLI,
   a `costBasis: unknown` pozycję w `gaps`; render pokazuje liczby szacowane z „≈”.
4. Brak źródła (bez `events.jsonl`, `measurements.jsonl`, `gh`, `epic_done`) daje `null` i pozycję w `gaps`, nie
   zero; błąd `gh` nie nadpisuje zapisanych wartości.
5. `backfill` liczy rekordy przebiegów z końcowym PR `MERGED` (także `INTEGRATING` i `BLOCKED` w AO), pomija epiki
   w toku i katalogi bez wiersza epiku.
6. `trend`: mediana z pominięciem `null`, pozycja epiku, przeliczenie regresji w otwartym oknie, adnotacja zmiany
   wersji CLI albo modeli.
7. `subscription`: okno odnowione w trakcie → `null`; `share` przy nakładaniu i 1 bez niego.
8. `in_attempts`: czas od `tool_use` do `tool_result` według kategorii; polecenie w tle tylko w liczbie;
   `repeat_runs` liczy polecenie testów o tym samym kluczu (także z innym doklejonym `| tail` albo przekierowaniem)
   bez zmiany kodu pomiędzy, a nie liczy go po `Edit`, `Write`, `NotebookEdit` albo poleceniu `other` ani dla innego
   celu tej samej kategorii; `api_retry` i `permission_denials` (ze zdarzeń `system/permission_denied`, także
   subagentów) z fixture'ów; uruchomienie z poleceniem w tle, wywołaniem bez wyniku albo niezakończonym subagentem
   pomiędzy nie wchodzi do `repeat_runs`; rekord nie zawiera treści polecenia.
9. `bottlenecks`: najwyżej 3 pozycje malejąco po nadwyżce, nadwyżka poniżej 0,5 h nie wchodzi, każdy rodzaj kandydata
    (kategoria, zadanie, `in_attempts` z udziałem powtórzeń, `gate_wait`, `review`) ma test; pozycja ma koszt czasowy
    (godziny i udział w `to_done`, dla `gate_wait` w `gate_wait` + `to_done`, dla `review` w `to_merge`); `gate_wait`
    bez wpisów epiku jest pominięty z pozycją w `gaps`; klucz reguły decyzji albo `null`
    i potencjalny efekt (z reguły albo nadwyżka z dopiskiem „bez reguły decyzji”); `review` nie trafia do raportu
    odbioru, a jest w bloku po merge'u.
10. `waste`: każda reguła na fixture alarmuje raz, także przy dwóch równoległych wywołaniach; alarm kosztowy (W1, W4)
    nie powstaje przy 3× mediany poniżej 5 USD ani przy 5 USD poniżej 3× mediany; alarm czasowy (W2) nie powstaje
    poniżej 1 h; W3 alarmuje bez względu na koszt i czas; poniżej 5 pomiarów W1, W2 i W4 nie alarmują; próby
    zablokowane i `usage_limit` nie wchodzą do W3.
11. `decide`: D1–D4 (także D4 dla `review`) na fixture z progami z `config.json`, kolejność, najwyżej 3 w raporcie.
    D1 na katalogach wyników EVALS 2.0 z polami jak w prawdziwym `meta.json`: brak katalogu albo brak `meta.json`
    daje pozycję w `gaps` bez D1; same przebiegi na droższym modelu dają propozycję eksperymentu; zaliczany przebieg
    na tańszym modelu daje „porównanie wykonane” bez propozycji; przebiegi `squash`, `infra_error` i `budget_stop`
    na tańszym modelu nie są zaliczane; rola spoza listy daje „bez evali”; nieczytelny `meta.json` jest pomijany
    i liczony w `gaps`; żaden wynik D1 nie proponuje zmiany modelu.
12. `effect`: na fixture z historią przed i po `--at` miara z tabeli reguł, mediana, p25–p75 i liczebność obu okien,
    różnica w jednostce miary i procentach, czas epiku z 3 epików przed i po, zmiany wersji w oknach; epik rozpoczęty
    przed `--at` i zmergowany po nim nie wchodzi do żadnego okna; poniżej progu liczebności „za mało danych”.
13. `render --format comment` zawiera `Ręczne poprawki: N` w formacie, który czyta `retro.py collect` (test z jego
    parserem), i żadnego tekstu z danych przebiegu.
14. Regresje: test z podstawionym `gh` dla czerwonego CI na commicie merge'u, revertu i issue `bug` z polem
    „Wprowadzone przez” od autora `write` (liczy się) i `read` (nie liczy się); poza oknem 14 dni się nie liczą;
    propozycje `/sweep` trafiają do `found_after_merge`.
15. Raport odbioru `/ao-run` ma sekcje „Pomiar” (zaczynającą się od wąskich gardeł) i „Decyzje optymalizacyjne”,
    a komentarz po merge'u blok „Pomiar epiku”.
16. `/retro`: `input.json` ma incydenty `optimization` i fakty z pomiaru; pozycja oparta na dwóch incydentach
    `optimization` przechodzi `retro.py validate`, na jednym nie; raport kończy się sekcją „Decyzje optymalizacyjne”
    z efektem z `metrics.py effect` dla kluczy z `applied.jsonl` albo „za mało danych”.
17. Skrypty mają testy ścieżek błędu (nieczytelna albo zajęta baza, brak katalogu, błąd `gh`, nieczytelny
    `waste.json`, zły `config.json`) z kodem i komunikatem; skan fixture'ów w repo przechodzi.

## Ryzyka

- Format stream-json zależy od wersji CLI (AO przypina wersję). Zmiana pinu może zmienić pola; `gaps` zamiast zer nie
  ukryje braku.
- Podział wartości na role według wag tokenów to przybliżenie (CLI podaje koszt tylko na model i próbę), a wyjście
  pojedynczego typu subagenta jest szacowane; oba są oznaczone. Dokładny podział na role da dopiero telemetria
  (#577, zdarzenie `api_request` z identyfikatorem agenta), od której METRICS nie zależy.
- `repeat_runs` to dolna granica: zeruje go każde polecenie kategorii `other` (większość wywołań narzędzi to
  polecenia powłoki), a polecenie zaliczone do `read`, które jednak zmienia pliki, dałoby fałszywe powtórzenie; lista
  `read` w `config.json` jest więc krótka i zawiera tylko polecenia tylko do odczytu.
- `share` przy nakładaniu epików to szacunek; surowy przyrost pp jest obok.
- Reguły decyzji i wąskie gardła to heurystyki: wartość dają dopiero z oceną użytkownika w `/retro` i efektem
  zmierzonym przed i po; przy małej liczbie epików po zmianie wniosku nie ma.
- Epiki różnią się wielkością i rodzajem, więc mediana „na zadanie planu” porównuje niejednorodne prace; raport podaje
  obok wartości bezwzględne.
