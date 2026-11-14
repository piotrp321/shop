# Przebieg AO epiku ADMIN-DESIGN: czas i propozycje przyspieszenia

Rewizja: końcowy PR #503 zmergowany jako `53bd073` (2026-11-13), AO `a178eaf`, Claude Code 2.1.311.
Źródła: `run.log`, `events.jsonl` i transkrypty prób AO (`~/.local/state/ao-admin-design`), czasy PR i CI z GitHuba.
Oznaczenia: **[z]** zmierzone, **[s]** szacowane.

## Wynik

| Pozycja | Wartość |
|---|---|
| Zadania | 6 zadań planu, 1 poprawka QA (FIX-1), 2 rundy `/integration` (FULL, DELTA), FINALIZE, REPAIR-1 |
| PR zadań | #480, #483, #487, #489, #491, #492, #497, #502; końcowy #503 |
| Od startu do końcowego PR [z] | 712 min (10:40–22:32), z tego próby 673 min |
| Koszt [z] | $98,68 (bez REPAIR-1), szacunek Claude CLI, nie opłata w subskrypcji |
| Interwencje człowieka | 1 (odpowiedź na bloker po awarii API) |
| QA | runda 1: 0 Critical, 2 Important (Nunito, fokus po nawigacji), 4 Minor; runda 2: 5 Minor |
| Koniec w AO | `BLOCKED (epic_timeout)`: CI pusha gałęzi epiku czerwone na `k8s-selftest`, REPAIR-1 przerwany po 4 h; końcowy PR zmergował użytkownik, poprawka testu na `main` w #505 |

## Próby [z]

Czasy w minutach. „Developer” obejmuje rundy poprawek, „e2e” to lokalne `make e2e` developera i orkiestratora.

| Próba | Razem | Developer | Review (przebiegi / min) | e2e | Od PR do merge'u | Uwagi |
|---|---|---|---|---|---|---|
| 1, próba 1 | 36,8 | 33,0 | – | – | – | błąd API 403 od 7. minuty, potem BLOCKED |
| 1, próba 2 | 58,1 | 17,5 | 2 / 13,3 | 6 | 22,3 | niewymagany `smoke` (k3d) 22 min |
| 2 | 89,0 | 48,3 | 2 / 9,3 | 22 | 19,6 | 6 pełnych `make e2e` |
| 3 | 56,7 | 29,7 | 2 / 5,5 | 12 | 14,2 | |
| 4 | 68,0 | 38,9 | 2 / 10,5 | 16 | 13,0 | |
| 5, próba 1 | 94,2 | 56,7 | 3 / 9,1 | 21 | 13,3 | `invalid_output` po zielonym CI |
| 5, próba 2 | 1,0 | – | – | – | – | poprawiony tylko wynik |
| 6 | 90,2 | 53,2 | 3 / 8,4 | 45 | 15,0 | |
| FIX-1 | 71,5 | 31,8 | 1 / 6,6 | 21 | 19,1 | |
| INTEGRATION-1 (FULL) | 52,4 | – | arch+QA 8,5, UX 22,5 | 17 | – | |
| INTEGRATION-2 (DELTA) | 42,0 | – | arch+QA 7,6, UX 16,3 | 14 | – | |
| FINALIZE | 12,6 | 8,3 | – | – | 0,5 | |

## Gdzie poszedł czas [z]

| Pozycja | Minuty | Udział |
|---|---|---|
| Implementacja developera z rundami poprawek (w tym ok. 115 min lokalnego e2e) | 284 | 40% |
| Czekanie na CI po utworzeniu PR zadania | 117 | 16% |
| Dwie rundy `/integration` | 94 | 13% |
| Review `code-reviewer` (15 przebiegów w 7 zadaniach) | 63 | 9% |
| Awaria API 403 i czekanie na człowieka | 59 | 8% |
| Ponowne bramki orkiestratora po developerze | 42 | 6% |
| Praca modelu orkiestratora, merge i setup między próbami | 55 | 8% |

- Lokalny `make e2e` w całym epiku: około 30 pełnych przebiegów, 187 min (26%).
- Fala 3 (zadania 3, 4, 5 zależne tylko od 2) szła szeregowo, bo AO uruchamia jedną próbę naraz
  (`orchestrator/store.py`, `active INTEGER UNIQUE CHECK (active = 1)`). To cecha projektu, nie ustawienie.
- `invalid_output` w zadaniu 5 kosztował około 2 min, bo PR i zielone CI powstały w próbie 1.
- Po końcowym PR: czerwony `k8s-selftest` (hook `apps/admin/docker/40-admin-config.sh` nie był kopiowany do drzewa
  selftestu), jeden rerun, REPAIR-1 przez 4 h bez PR i przekroczony limit epiku 16 h.

## Propozycje przyspieszenia

| # | Zmiana | Gdzie | Zysk | Ryzyko |
|---|---|---|---|---|
| 1 | Zadania jednej fali równolegle (`--max-parallel 2–3`) | AO (#50, krok 19b planu) | [s] 100–120 min na fali 3 | okno limitu, pamięć 2–3 stosów, konflikty wspólnych plików |
| 2 | Developer uruchamia celowane spece e2e, pełny `make e2e` raz przed PR | `/delivery`, agent `developer` | [s] 60–80 min | regresja wychodzi dopiero w CI (ok. 15 min i runda poprawek) |
| 3 | Błąd API lub uwierzytelnienia subagenta od razu jako bloker `technical` z powiadomieniem | `/delivery` | [z] ok. 30 min | niskie |
| 4 | Niewymagany `smoke` nie wstrzymuje `READY_FOR_INTEGRATION` | `/delivery` | [z] 15 min | czerwony smoke wychodzi po merge'u do gałęzi epiku |
| 5 | DELTA przyjmuje CI PR o tym samym drzewie zamiast powtarzać testy | `/integration` | [z] 14 min na rundę | niskie |
| 6 | Bramki `make check` i testy jednostkowe razem z agentami arch/QA | `/integration` | [s] 6–7 min na rundę | obciążenie stosu, niestabilne e2e |
| 7 | Mniej zadań UI dotykających tego samego speca e2e | `/prepare-epic`, `/planner` | [s] 20–40 min | większe PR |
| 8 | `k8s-selftest` uruchamiany już w CI PR zadań, które zmieniają pliki czytane przez testy `infra/k8s` (w tym epiku pojawił się dopiero na pushu gałęzi epiku i na końcowym PR) | CI (`.github/scripts/scope.sh`, `ci.yml`) | [s] uniknięcie REPAIR po końcowym PR | dłuższe CI takich PR |
