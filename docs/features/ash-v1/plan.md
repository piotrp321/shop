# ASH-V1 – plan zadań

Specyfikacja: `docs/features/ash-v1/spec.md`. Epik prowadzi główna sesja Claude Code z natywnymi subagentami. Epik ma
jedną gałąź (poza `epic/*`) z PR-em do `main` otwartym od początku jako szkic. Każde zadanie realizuje subagent we
własnym worktree na gałęzi zadania; sesja orkiestrująca merguje gotowe gałęzie zadań do gałęzi epiku i wypycha ją.
Zadania nie mają własnych PR. Zadania jednej fali z listą `parallel_with` mogą iść naraz. Plan jest dokumentem
roboczym: po ukończeniu epiku usuń go.

## Status

Epik w toku od 2026-11-19 (gałąź `feat/ash-v1`).

| Fala | Zadania | Status |
|---|---|---|
| 1 | ASH-V1-1, ASH-V1-2, ASH-V1-3 | ASH-V1-2 zmergowane (6d8f002e), ASH-V1-1 zmergowane (90c30b1f); 3 w toku |
| 2 | ASH-V1-4, ASH-V1-5 | ASH-V1-4 w toku |
| 3 | ASH-V1-6 | |

Kolumnę „Status” (zmergowane do gałęzi epiku, commit) uzupełnia sesja orkiestrująca po merge'u gałęzi zadania.

Warunki przed startem (kroki użytkownika, nie zadania): merge PR #764 i #757 (gałąź epiku powstaje od `origin/main`
po obu merge'ach; zadanie 2 nie powtarza zmian #764), wyłączenie wtyczki Superpowers na koncie, wklejenie bloku
z `python3 tools/telemetry/telemetry.py snippet` do `~/.claude/settings.json` i nowa sesja (T1; sesja sprawdza go
`telemetry.py check`). Żadne zadanie nie zmienia ustawień użytkownika.

Łańcuchy zależności:
- plik przebiegu, transkrypty i pole `source` (1) → pola czasu, człowieka, przeglądów i limitów (4) oraz trend i raport
  (5);
- sekcje opisu końcowego PR i tabela kryteriów zapisane w ADR (2) → odczyt kryteriów w rekordzie (4) i incydenty
  `/retro` z opisu PR (6);
- pola rekordu natywnego (1, 4) → incydenty `/retro` (6).

Odbiór przez sesję prowadzącą (krok 6 „Przebiegu epiku”, na danych lokalnych, wynik w tabeli kryteriów opisu PR):
- kryterium 1: plik przebiegu SMALL-FIXES zapisany po fakcie (`metrics.py start small-fixes` z `--at`, `--worktree`,
  `--branch`, `--pr 752`, `--tasks 8`, `--session` z identyfikatorem sesji prowadzącej SMALL-FIXES, potem `done`), następnie `metrics.py epic small-fixes`;
- kryterium 3: jednorazowe `metrics.py backfill`, potem `metrics.py trend`;
- kryterium 5: `retro.py pending` i `retro.py collect small-fixes --out <katalog tymczasowy>`;
- kryterium 14: po T1 sesja prowadząca w Prometheusie pod swoim `session_id`, zdarzenia subagenta w Lokim z `agent_id`
  z rekordu; inny wynik i ograniczenie sesja wpisuje do `docs/features/telemetry/spec.md` (krok 7).

Po merge'u końcowego PR, w tej kolejności: użytkownik uruchamia `/retro small-fixes` jako odbiór ścieżki natywnej;
biegnie ono przed tagiem, więc bez zamrożenia (V3). Potem na polecenie użytkownika sesja zakłada tag `ash-v1.0` (V1);
od tagu seria jest w toku do retrospektywy trzeciego epiku. Kryterium 8 w praktyce sprawdza retrospektywa pierwszego
epiku serii. Ocena po serii (V4) jest poza tym epikiem.

## Praca szeregowa

- ASH-V1-4, ASH-V1-5: `tools/metrics`, `docs/features/metrics/spec.md`; oba zmieniają jedyny moduł narzędzia pomiaru i jego specyfikację (budowa rekordu i raport z rekordów są w jednym pliku `metrics.py`, bez granicy modułu)

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ASH-V1",
  "spec": "docs/features/ash-v1/spec.md",
  "tasks": [
    {
      "id": "ASH-V1-1",
      "title": "Metryki: plik przebiegu i rekord natywny z transkryptów sesji",
      "scope": "Spec: D1–D4, M1, M2, M3 (bez wierszy `breakdown`, `human`, `integration`, `retries`, `reviews`, `acceptance`, `subscription` – zadanie 4), M7, T2–T4; kryteria 1 (część), 2, 4, 11 (część), 12. `tools/metrics`: komendy `start` (z `--resume`, `--at`, `--worktree`, `--branch`, `--pr`, `--tasks`, `--session`) i `done` zapisujące `<state>/runs/<EPIK>.json`; identyfikator sesji prowadzącej z najnowszego transkryptu w katalogu sesji dla bieżącego katalogu roboczego, `--session` podaje go wprost (M1); odczyt limitów z wejścia przez istniejące `night_queue.parse_usage`, liczba zadań manifestu `plan.md` jak w `night_queue.py start` (import, bez zmian w `.claude/skills/ao-run`). `epic`: wybór źródła M2 (`ao-<EPIK>` → AO jak dziś, inaczej plik przebiegu, bez obu kod 2), `backfill` tylko AO. Rekord natywny w dzisiejszym formacie z polem `source` (`native`; rekordy AO `ao`), zapis istniejącym `write_record`, braki przez istniejące `Gaps` („brak źródła daje `null`, nigdy zero”). Przypisanie transkryptów do epiku: wyłącznie sesje z identyfikatorami z pliku przebiegu i ich `subagents/agent-<id>.jsonl` z `meta.json`; inna sesja w tym samym katalogu roboczym nie wchodzi. Bez podwójnego liczenia: wiadomości od startu do merge'u końcowego PR (praca po merge'u nie wchodzi), każdy identyfikator wiadomości raz (pierwsze wystąpienie, także w sesji wznowionej albo rozgałęzionej). Tokeny z istniejącego parsera (`read_transcript`/`_read_line`) i sekcji `value`; rola `orchestrator` albo `agentType`, zadanie według D4 (ID `<EPIK>-<n>` w `description`, inaczej „bez zadania”). Cennik D3 w `tools/metrics/config.json`: dla każdego modelu z transkryptów SMALL-FIXES i definicji agentów aktualne stawki dostawcy za milion tokenów osobno dla wejścia, wyjścia, odczytu cache, zapisu cache 5 min i zapisu cache 1 h, ze źródłem (strona cennika) i datą odczytu, wpisane z cennika przy implementacji, nie wyliczane z `token_weights`. Pola: `tasks`, `started_at`, `done_at`, `final_pr`, `issue`, `merged_at`, `wall`, `value` i tokeny, `attempts` (identyfikator agenta, zadanie, rola, model, effort, czas, tokeny, wartość, czy zakończony), `by_task`, `ci` gałęzi z pliku przebiegu (`tasks: null`), `in_attempts`, `versions`, `regressions`, `found_after_merge`, `bottlenecks` jak dziś, `parallel`/`gate_wait`/`waste` `null` z wpisem w `gaps`, lista sesji epiku (T2). Dokumentacja: `docs/features/metrics/spec.md` i `tools/metrics/README.md` (źródło natywne, plik przebiegu, cennik), `docs/features/telemetry/spec.md` (T4). Nie obejmuje: trendu i raportu (5), błędów tylko danych AO (#703, #704), eksportu rekordów do stosu, dashboardów i konfiguracji stosu telemetrii.",
      "acceptance_criteria": [
        "`metrics.py start <EPIK>` zapisuje `<state>/runs/<EPIK>.json` z czasem startu, SHA `origin/main`, gałęzią epiku, numerem draft PR, katalogiem roboczym, identyfikatorem sesji prowadzącej (z najnowszego transkryptu w katalogu sesji dla bieżącego katalogu roboczego albo z `--session`), liczbą zadań manifestu `plan.md` i odczytem limitów z wejścia; `done` dopisuje czas oznaczenia PR jako gotowy i drugi odczyt; `start --resume` dopisuje identyfikator i katalog nowej sesji; `--at`, `--worktree`, `--branch`, `--pr`, `--tasks` i `--session` zapisują plik po fakcie także bez `plan.md`.",
        "`metrics.py epic <EPIK>` bez katalogu `ao-<EPIK>` i bez pliku przebiegu kończy się kodem 2 z komunikatem, którego źródła brakuje, i nie zapisuje rekordu (kryterium 2); z katalogiem `ao-<EPIK>` buduje rekord AO jak dziś z `source: ao` (testy komunikatu braku katalogu AO i pola `source` zmieniają się razem z tą zmianą).",
        "Test na syntetycznym przebiegu w kształcie SMALL-FIXES (8 zadań, 16 przebiegów subagentów: 8 `developer`, 5 `code-reviewer`, po jednym `ux-reviewer`, `qa-adversarial`, `general-purpose`): rekord z `source: native`, `tasks` 8, 16 wpisów `attempts` z rolą z `agentType`, modelem zgodnym z `meta.json`, zadaniem według D4 i identyfikatorem agenta, wartość oznaczona jako szacunek (część kryterium 1).",
        "Wartość liczona ze stawek cennika osobno dla wejścia, wyjścia, odczytu cache, zapisu cache 5 min i 1 h (test rozróżnia oba zapisy cache); każdy model z transkryptów SMALL-FIXES i definicji agentów ma w `config.json` stawki wszystkich pięciu rodzajów ze źródłem i datą; model albo rodzaj tokenów bez stawki daje `null` i wpis w `gaps`.",
        "Rekord dwóch sesji z nakładającymi się wiadomościami (sesja wznowiona) liczy każdą wiadomość raz, sesja spoza pliku przebiegu w tym samym katalogu roboczym nie wchodzi do rekordu, a wiadomości po merge'u końcowego PR nie są liczone (kryterium 12).",
        "Rekord z transkryptu bez pola `usage`, bez `meta.json` albo z nieznanym modelem ma `null` i wpisy w `gaps`, a komenda kończy się kodem 0 (kryterium 4); brak innego pola transkryptu też daje `null` i wpis w `gaps`, nie błąd.",
        "Rekord wymienia identyfikatory sesji epiku, nie zawiera treści wiadomości ani zleceń; repozytorium nie zawiera treści prawdziwych transkryptów (fixture'y mają tylko pola czytane przez narzędzie).",
        "`make check` i testy `tools/metrics` przechodzą; `docs/features/metrics/spec.md` i `tools/metrics/README.md` opisują źródło natywne, plik przebiegu i cennik, a `docs/features/telemetry/spec.md` opisuje rozpoznanie epiku (`session_id` z pliku przebiegu), zadania i subagenta (`agent_id` z `attempts`) oraz modelu dla sesji natywnej."
      ],
      "risk": "STANDARD",
      "depends_on": [],
      "paths": [
        "tools/metrics/",
        "docs/features/metrics/spec.md",
        "docs/features/telemetry/spec.md"
      ],
      "parallel_with": [
        "ASH-V1-2",
        "ASH-V1-3"
      ]
    },
    {
      "id": "ASH-V1-2",
      "title": "Przebieg epiku: punkt odniesienia, opis PR, odbiór kryteriów, zamrożenie serii i `/retro` poza listą zamrożonych",
      "scope": "Spec: F1–F4, A1–A4, R8, V2, V3, D7, D8; kryterium 9. `docs/adr/agentic-software-house.md`, „Przebieg epiku”: krok 1 – po otwarciu draft PR `metrics.py start` z odczytem limitów, krok 7 – po oznaczeniu PR jako gotowy `metrics.py done` (F1); stałe sekcje opisu końcowego PR z wpisem „brak” dla pustej (F2); `description` zlecenia subagenta zaczyna się od ID zadania, przegląd całej zmiany ma nazwę epiku i rolę (F3); sprawdzenie wolnej pamięci Dockera i projektów Compose innych sesji przed falą, mniejsza równoległość zapisana w „Problemach i zastojach” (F4); krok 6 zaczyna od numerowanych kryteriów akceptacji, `qa-adversarial` sprawdza je na działającym stosie przed szukaniem błędów, bez QA sprawdza sesja (A1); tabela kryteriów w opisie PR: numer, sposób („stos”, „CI”, „po wdrożeniu”, „niezweryfikowane” z powodem), wynik (A2); tryb `STORE_MODE=production` albo E2E z CI wskazany z nazwy (A3); kryteria „niezweryfikowane” na początku opisu PR, „po wdrożeniu” w komentarzu PR po wdrożeniu zleconym przez użytkownika (A4); reguła zamrożenia plików procesu do końca serii (V2). `.claude/agents/qa-adversarial.md`: kryteria akceptacji w zleceniu (A1). `docs/features/agentic-software-house/plan.md`, „Status”: seria ABUSE-LIMITS (#181), ASYNC-RELIABILITY (#768), DELIVERY etap 1 (#120), jej stan (w toku od założenia tagu `ash-v1.0` do retrospektywy trzeciego epiku) i zmergowane epiki serii (V3). R8: główny `CLAUDE.md`, decyzja o zamrożeniu w ADR i „Status” planu ASH wymieniają jako zamrożone tylko `/ao-run`, `/delivery` i `/sweep`. Nazwy sekcji i kolumny tabeli kryteriów są kontraktem dla zadań 4 i 6. Zmiany z PR #764 (commit RED, odblokowanie worktree, miejsce punktu odniesienia) już są na `main` i nie są powtarzane. Nie obejmuje: `/retro` (6), `tools/metrics` (1, 4, 5), innych zasad głównego `CLAUDE.md` niż lista skilli zamrożonych.",
      "acceptance_criteria": [
        "„Przebieg epiku” w ADR zawiera F1 (kroki 1 i 7), F2, F3, F4, A1–A4 i V2 (kryterium 9).",
        "`qa-adversarial.md` wymaga kryteriów akceptacji w zleceniu i każe sprawdzić je na działającym stosie przed szukaniem błędów (kryterium 9).",
        "„Status” planu ASH pokazuje serię trzech epików z D8, jej stan (w toku od założenia tagu `ash-v1.0` do retrospektywy trzeciego epiku) i zmergowane epiki serii (V3).",
        "Główny `CLAUDE.md`, decyzja o zamrożeniu w ADR i „Status” planu ASH wymieniają jako zamrożone tylko `/ao-run`, `/delivery` i `/sweep`; żaden z nich nie nazywa `/retro` skillem zamrożonym (R8).",
        "Opis sekcji opisu PR i tabeli kryteriów w ADR używa nazw z F2 i sposobów z A2 bez zmian."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "CLAUDE.md",
        "docs/adr/agentic-software-house.md",
        ".claude/agents/qa-adversarial.md",
        "docs/features/agentic-software-house/plan.md"
      ],
      "parallel_with": [
        "ASH-V1-1",
        "ASH-V1-3",
        "ASH-V1-5"
      ]
    },
    {
      "id": "ASH-V1-3",
      "title": "Testy API przy zimnym cache bez przekroczenia limitu pamięci (#762)",
      "scope": "Spec: F5; kryterium 10. `apps/api`: cel `test` w `Makefile` i konfiguracja testów (PHPUnit, bootstrap testów, konfiguracja środowiska `test`), tak żeby `make -C apps/api test` przechodził po usunięciu `var/cache/test*`. Poprawka błędu: test RED to uruchomienie przy zimnym cache przed zmianą. Nie obejmuje kodu `apps/api/src`, obrazu PHP ani workflowów CI.",
      "acceptance_criteria": [
        "Przed zmianą `make -C apps/api test` po usunięciu `var/cache/test*` kończy się przekroczeniem limitu pamięci (wynik w raporcie), po zmianie przechodzi przy zimnym i przy ciepłym cache (kryterium 10).",
        "`make -C apps/api check` przechodzi; zmiana dotyczy tylko `Makefile` i konfiguracji testów `apps/api`."
      ],
      "risk": "FAST",
      "depends_on": [],
      "paths": [
        "apps/api/Makefile",
        "apps/api/phpunit.dist.xml",
        "apps/api/tests/bootstrap.php",
        "apps/api/config/packages/test/"
      ],
      "parallel_with": [
        "ASH-V1-1",
        "ASH-V1-2",
        "ASH-V1-4",
        "ASH-V1-5",
        "ASH-V1-6"
      ]
    },
    {
      "id": "ASH-V1-4",
      "title": "Metryki: czas, człowiek, przeglądy, kryteria akceptacji i limity w rekordzie natywnym",
      "scope": "Spec: M3 (wiersze `breakdown`, `human`, `integration`, `retries`, `reviews`, `acceptance`, `subscription`), M4, M5, M7; kryteria 1 (część), 11 (część), 13. Rozkład czasu: `work` (suma przedziałów pracy prowadzącego albo subagenta), `human_wait` (przerwy zakończone wiadomością z `origin.kind = \"human\"`), `idle` do `done_at`; pozostałe kategorie `null` i `breakdown_incomplete: true`. `human`: `interventions` (wiadomości człowieka po starcie bez polecenia startu, oznaczone jako szacunek), `waits`, `wait_s`, lista zdarzeń z czasem i długością czekania bez treści, `manual_fixes` z `--manual-fixes`. `integration` i `rounds` z przebiegów `architecture-reviewer`, `qa-adversarial`, `ux-reviewer`. `retries`: `fix` z przeglądów zadań z werdyktem blokującym, `failed_by_outcome` z niezakończonych przebiegów, `repair` i `sync_prs` `null`. `reviews` według M4 (raport oddany sesji, w SMALL-FIXES `SubagentHandback`; tryb `FULL`/`DELTA`; werdykty z kontraktów agentów; blokują `CHANGES_REQUIRED`, `BLOCK`, `REWORK`; sam werdykt bez treści). `acceptance` według M5 z tabeli „Kryteria akceptacji” opisu końcowego PR w formacie A2 zapisanym w ADR (zadanie 2); opis PR czytany przez bramkę pochodzenia (`tools/agent-security/github_trust.py`, bez jej zmian), w rekordzie same liczby. `subscription`: przyrost każdego okna między odczytami `start` i `done` istniejącym `night_queue._window_deltas`; okno odnowione między odczytami (po `resetsAt`) daje `null` i wpis w `gaps`; `share` – udział tokenów epiku w tokenach wszystkich lokalnych sesji z przedziału ze wszystkich projektów w `~/.claude/projects` (limity są na konto), także równoległych sesji innych prac, oznaczony jako szacunek; użycia z innych urządzeń nie widać. Dokumentacja tych pól w `docs/features/metrics/spec.md` i `tools/metrics/README.md`. Nie obejmuje: trendu i raportu (5).",
      "acceptance_criteria": [
        "Test na syntetycznym przebiegu w kształcie SMALL-FIXES: `reviews` ma siedem raportów `code-reviewer`, w tym dwa `CHANGES_REQUIRED` (zadania 4 i 7), pierwszy raport transkryptu w trybie `FULL`, kolejne `DELTA`; `retries.fix` równa się liczbie przeglądów zadań z werdyktem blokującym; rekord nie zawiera treści raportów (część kryterium 1).",
        "Werdykty `qa-adversarial` (`PASS`/`INVESTIGATE`/`BLOCK`) i `architecture-reviewer` (`KEEP`/`SIMPLIFY`/`REWORK`) są rozpoznane, `CHANGES_REQUIRED`, `BLOCK` i `REWORK` blokują; raport `ux-reviewer` bez rozpoznanego werdyktu ma werdykt `null` i wpis w `gaps`.",
        "`breakdown` ma `work`, `human_wait` i `idle` według M3, `limit_wait`, `ci_wait`, `host_pause`, `github_wait` `null` i `breakdown_incomplete: true`; `human.interventions` liczy każdą wiadomość człowieka po starcie bez polecenia startu i jest oznaczone jako szacunek, zdarzenia mają czas i długość czekania bez treści.",
        "`integration` jest `null` bez przebiegów ról przeglądowych, a `rounds` to największa liczba raportów jednej z tych ról; `failed_by_outcome` liczy niezakończone przebiegi subagentów.",
        "`acceptance` ma liczby kryteriów: wszystkie, „stos”, „CI”, „po wdrożeniu”, „niezweryfikowane”; opis PR jest czytany przez bramkę pochodzenia, a rekord zapisuje same liczby bez treści opisu; opis PR bez tabeli albo niedostępny przez bramkę daje `null` i wpis w `gaps`.",
        "Rekord z odczytami limitów, między którymi okno się odnowiło, ma dla tego okna `null` i wpis w `gaps`, a pozostałe okna przyrost (kryterium 13); plik przebiegu bez odczytów daje `subscription` `null` z wpisem w `gaps` (część kryterium 1); `share` uwzględnia w mianowniku tokeny lokalnych sesji z przedziału ze wszystkich projektów w `~/.claude/projects` (test z sesją innego projektu) i jest oznaczony jako szacunek.",
        "Brak pola w transkrypcie albo opisie PR daje `null` i wpis w `gaps`, nie błąd; `make check` i testy `tools/metrics` przechodzą; `docs/features/metrics/spec.md` i `tools/metrics/README.md` opisują te pola dla źródła natywnego."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ASH-V1-1",
        "ASH-V1-2"
      ],
      "paths": [
        "tools/metrics/",
        "docs/features/metrics/spec.md"
      ],
      "parallel_with": [
        "ASH-V1-3"
      ]
    },
    {
      "id": "ASH-V1-5",
      "title": "Metryki: trend i raport rekordów AO i natywnych bez zrównywania pól",
      "scope": "Spec: M6, D3 (oznaczenie szacunku); kryteria 3 i 11 (część). Istniejące `trend` (tabela i `--json`) i `render` pokazują rekordy AO i natywne w jednej tabeli z oznaczeniem źródła; kolumny bez danych w rekordzie natywnym mają „brak danych”, nie zero. Mediany, pozycja epiku na tle historii i wąskie gardła (`bottlenecks`) liczone osobno dla każdego źródła. Bez oznaczenia porównywalne są tylko: liczba zadań, czas do gotowego PR i do merge'u, CI końcowego PR, regresje; przy rekordzie natywnym oznaczenie „liczone inaczej niż w AO” mają: wartość w USD (cennik zamiast kosztu z CLI, oznaczona jako szacunek), interwencje, rozkład czasu, rundy przeglądu i koszt subskrypcji. Klucze `epics` i `position` z `trend --json`, które czyta `retro.py`, zostają. Dokumentacja w `docs/features/metrics/spec.md` i `tools/metrics/README.md`. Nie obejmuje: budowy rekordu (1, 4), uruchomienia `backfill` na danych lokalnych (odbiór).",
      "acceptance_criteria": [
        "Test: `trend` i `render` z rekordami AO i natywnym pokazują jedną tabelę z oznaczeniem źródła, a kolumny bez danych w rekordzie natywnym mają „brak danych”, nigdy zero.",
        "Mediany, pozycja epiku i wąskie gardła są liczone osobno dla każdego źródła: rekord natywny nie zmienia mediany AO ani AO mediany natywnej.",
        "Przy rekordzie natywnym wartość w USD (oznaczona jako szacunek), interwencje, rozkład czasu, rundy przeglądu i koszt subskrypcji mają oznaczenie pól liczonych inaczej niż w AO; liczba zadań, czasy do gotowego PR i do merge'u, CI końcowego PR i regresje nie mają tego oznaczenia (kryterium 3 na danych testowych).",
        "`trend --json` zachowuje klucze `epics` i `position`; wynik dla samych rekordów AO zmienia się tylko o oznaczenie źródła, a testy `trend` i `render` zmieniają się razem z tym oznaczeniem.",
        "`make check` i testy `tools/metrics` przechodzą; `docs/features/metrics/spec.md` i `tools/metrics/README.md` opisują trend i raport dla dwóch źródeł."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ASH-V1-1"
      ],
      "paths": [
        "tools/metrics/",
        "docs/features/metrics/spec.md"
      ],
      "parallel_with": [
        "ASH-V1-2",
        "ASH-V1-3",
        "ASH-V1-6"
      ]
    },
    {
      "id": "ASH-V1-6",
      "title": "/retro dla epiku natywnego, skrypt pomiaru z worktree i zamrożenie na czas serii",
      "scope": "Spec: D5, D6, R1–R7; kryteria 5 (część), 6, 7, 8, 11 (część). `retro.py`: `pending` i `collect` znają epik natywny z plikiem przebiegu metryk (`<state>/runs/<EPIK>.json`) i zmergowanym końcowym PR; `collect` najpierw odświeża rekord (`metrics.py epic`); odmowa (kod 2) dla epiku bez przebiegu AO i bez pliku przebiegu (R1). Incydenty R2 z rekordu (`human`, `reviews`, `attempts`, `regressions`) i z sekcji opisu końcowego PR czytanego przez bramkę `github_trust.py` („Decyzje bez pytania”, „Problemy i zastoje”, „Luki w instrukcjach” według F2 w ADR); nowy rodzaj `reported`; bez `repair`, `manual_fix`, `no_sweep` dla epiku natywnego; brak sekcji albo pola to fakt braku danych. Progi, limity, rejestry i walidacja bez zmian poza `reported` liczonym do progu (R3, D5). `collect` uruchamia `metrics.py` z worktree `origin/main`, dane AO i stan pomiaru z dotychczasowych miejsc (R4, #679). `docs/adr/agentic-software-house.md` na liście plików dozwolonych dla `fix` (R5). `SKILL.md`: rozpoznanie epiku natywnego zamiast odmowy, zamrożenie przy serii w toku według „Statusu” planu ASH (R6), `/retro <epik>` uruchamia użytkownik po merge'u końcowego PR (R7). `retro-analyst.md` i `docs/features/retro/spec.md` opisują incydenty epiku natywnego (R7). Nie obejmuje: incydentów epików AO, `/ao-run`, `/sweep`, `tools/metrics`.",
      "acceptance_criteria": [
        "Test: `retro.py pending` wymienia epik natywny z plikiem przebiegu i zmergowanym końcowym PR; `collect` dla niego uruchamia `metrics.py epic`, kończy się kodem 0, a `input.json` ma incydenty `finding` dla przeglądów z werdyktem blokującym, `wait`, `failed_attempt`, `escaped`, `unasked`, `reported` (każdy punkt „Problemów i zastojów” i „Luk w instrukcjach”) i `optimization` według R2, bez `repair`, `manual_fix` i `no_sweep`, oraz fakt braku danych dla opisu PR bez sekcji „Luki w instrukcjach” (kryterium 5 na danych testowych).",
        "Opis końcowego PR trafia do `collect` przez bramkę pochodzenia; od autora bez prawa zapisu `input.json` ma tylko numer, autora i link.",
        "`retro.py collect` dla epiku bez przebiegu AO i bez pliku przebiegu kończy się kodem 2 i nie zapisuje `input.json` (kryterium 6); skill odmawia takiemu epikowi bez zapisu.",
        "Test regresyjny #679 (RED przed poprawką): `collect` z nieaktualnym głównym checkoutem i aktualnym worktree ma dane pomiaru (kryterium 7); testy runnera pomiaru zmieniają się razem ze zmianą jego katalogu.",
        "`validate`: `reported` liczy się do progu jak każdy incydent, klasę naprawczą mają nadal tylko `wait` z działaniem człowieka, `manual_fix` i `repair`; pozycja `fix` z `docs/adr/agentic-software-house.md` w `files` przechodzi walidację.",
        "`SKILL.md` przy serii w toku według „Statusu” planu ASH każe zapisać pozycje `fix` przez `observe`, wymienić je w raporcie jako odroczone, zachować commit „Statusu” i propozycje zgłoszeń, a pozycję oznaczoną jako blokada albo zagrożenie wprowadzić dopiero po „tak” użytkownika wskazującym tę pozycję (kryterium 8; pierwsze sprawdzenie w praktyce to retrospektywa pierwszego epiku serii).",
        "Dla epików AO incydenty, fakty i odmowy są bez zmian; `make check` i testy `retro.py` przechodzą; `retro-analyst.md` i `docs/features/retro/spec.md` opisują incydenty epiku natywnego i uruchomienie przez użytkownika po merge'u."
      ],
      "risk": "STANDARD",
      "depends_on": [
        "ASH-V1-1",
        "ASH-V1-2",
        "ASH-V1-4"
      ],
      "paths": [
        ".claude/skills/retro/",
        ".claude/agents/retro-analyst.md",
        "docs/features/retro/spec.md"
      ],
      "parallel_with": [
        "ASH-V1-3",
        "ASH-V1-5"
      ]
    }
  ]
}
```
