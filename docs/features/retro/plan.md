# RETRO – plan zadań

Specyfikacja: `docs/features/retro/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | RETRO-1 | |
| 2 | RETRO-2 | |
| 3 | RETRO-3 | |
| 4 | RETRO-4 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "RETRO",
  "spec": "docs/features/retro/spec.md",
  "tasks": [
    {
      "id": "RETRO-1",
      "title": "retro.py collect: sources and incidents, ao_events.waits()",
      "scope": "Nowy `.claude/skills/retro/retro.py` (Python 3.9+, bez zależności) z komendą `collect <epik>` i `test_retro.py`: sekcje „Źródła” i „Incydenty” specyfikacji (epiki z `orchestrator.db` w trybie tylko do odczytu, tabele `epics`, `tasks`, `attempts`, `events.jsonl`, `night-decisions.log`, zapisany `report.md`, komentarz z pomiarem w issue epiku w starym formacie i w formacie `- PR #<nr>: <opis>`, raport `/sweep`, „Decyzje bez pytania” z PR zadań; wszystkie rodzaje incydentów z kluczami, dołączanie faktów, `escaped`/`no_sweep` tylko po kroku 12) oraz fakty „Statusu” `status/*` (sekcja „Status”) i pola `input.json` z kroku 2 przebiegu (rejestry i pozycje otwartego PR retro czytane, gdy istnieją; pusty stan to puste listy). Funkcja `waits()` w `.claude/skills/ao-run/ao_events.py` i `summarize` na niej, z testami w `test_ao_events.py`. Linia testów `retro` w `.github/workflows/ci.yml`. Komendy `gh` i `git` przez `next_epic.make_runner`; importy `next_epic`, `ao_events` bez kopii kodu. Nie obejmuje: `validate`, rejestrów i ich komend (RETRO-2), skilla, agenta i `/ao-run` (RETRO-3, RETRO-4). `ao` nie jest uruchamiane.",
      "acceptance_criteria": [
        "Źródła: tylko epiki `DONE` z `final_pr_number`; `ao-night`, przebieg w toku i baza bez tabeli `epics` nie są epikami; epik bez potrzebnych kolumn dostaje fakt „brak danych AO”; baza otwierana tylko do odczytu (plik bez zmian po `collect`); `ao` nie jest uruchamiane. Testy na kopiach prawdziwych danych (VISUAL-DESIGN, DISCOUNT-CODES, baza starszej wersji, `ao-night`).",
        "`collect`: każdy rodzaj incydentu (`wait`, `failed_attempt`, `finding`, `repair`, `manual_fix`, `escaped`, `no_sweep`, `unasked`) z kluczem ze specyfikacji; zdarzenie opisane w `events.jsonl`, `night-decisions.log` i raporcie AO daje jeden incydent; nieudana próba VD-5 #1 (koniec 5 s przed blokadą) należy do incydentu `wait`; ten sam odcisk znaleziska w kilku rundach (także z innym zapisem `` ` `` w tytule) to jeden incydent; stabilne ID przy dwóch uruchomieniach; `escaped` i `no_sweep` tylko dla epików zmergowanych po kroku 12.",
        "`collect` bez `events.jsonl`, bez raportu AO, bez komentarza z pomiarem i bez raportu `/sweep` daje fakty „brak …” bez błędu; „Ręczne poprawki” z PR, bez PR i z niezgodną liczbą (fakt „niezgodna liczba”).",
        "Fakty `status/in-progress`, `status/last-epic`, `status/agent-issues`, `status/pending-retro` w `input.json` z ID.",
        "Błąd `gh` albo `git` → kod 1.",
        "`ao_events.waits()`: okna jak w dotychczasowych testach `summary`; `summary` bez zmiany wyniku (istniejące testy przechodzą).",
        "`ci.yml` uruchamia `python3 -m unittest discover -s .claude/skills/retro -p \"test_*.py\"`; testy `retro` i `ao-run` przechodzą lokalnie i w CI."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "RETRO-2",
      "title": "retro.py validate, registers, pending, lock and issues",
      "scope": "Komendy `retro.py` z sekcji „Walidacja”, „PR” (`sync`), „Rejestry” i „Uruchamianie”: `validate`, `observe`, `reject`, `sync`, `pending` (z `RETRO_SINCE`), `defer`, `skip`, `lock`, `unlock`, `issues` (nowe issues z etykietami i komentarze do `existing_issue`), z testami w `test_retro.py`; rejestry w `~/.local/state/shop-retro/` (`state.json` z `decisions_open`, `observations.jsonl`, `rejected.jsonl`, `applied.jsonl`, `carried.jsonl`, `lock`). `pending` importuje `night_queue` tylko tam, gdzie potrzebny, bez kopii kodu. Nie obejmuje: instrukcji sesji i agenta (RETRO-3), zmian w `/ao-run` (RETRO-4).",
      "acceptance_criteria": [
        "`validate`: próg (dwa fakty jednego incydentu nie wystarczają, dwa incydenty wystarczają, jeden `corrective` wystarcza, `corrective` na incydencie bez działania człowieka to błąd, brak klasy to błąd, `planned` się nie liczy, `product` liczy się do dwóch), pola obowiązkowe, niedozwolony plik, nieznany incydent, limity 5 i 3, `key` z otwartego PR bez `updates`, odrzucenie bez nowego incydentu i z nowym, incydent odrzucony pod innym `key`, `previous_fix`, `carried` bez nowego incydentu, `existing_issue` spoza danych, `status` bez dowodu i bez planu bez manifestu.",
        "`sync`: PR zmergowany (z cofniętym commitem i bez) → `applied.jsonl`; PR zamknięty bez merge'u → `carried.jsonl`, nie `rejected.jsonl`; do `rejected.jsonl` trafiają tylko pozycje z `reject`.",
        "`pending`, `defer`, `skip`: kolejność po dacie merge'u, `RETRO_SINCE`, odroczenie zostawia epik w `pending` z próbą i powodem, `skip` z przyczyną usuwa go, odmowa dla stanu `done` i `skipped`.",
        "`lock`/`unlock`: cudza blokada młodsza niż 6 h → kod 2; starsza przejmowana z ostrzeżeniem; `unlock` usuwa tylko własną.",
        "`observe` dopisuje obserwacje oraz `key` i incydenty pozycji `fix` i `issue` do `observations.jsonl`.",
        "`issues`: tylko zatwierdzone numery, etykiety `tech-debt` i `agent` dla `shop`, komentarz dla `existing_issue`, błąd `gh` jednej pozycji nie zatrzymuje reszty, drugie wykonanie bez duplikatów.",
        "Błąd `gh` albo `git` → kod 1; testy `retro` przechodzą lokalnie i w CI."
      ],
      "risk": "STANDARD",
      "depends_on": ["RETRO-1"]
    },
    {
      "id": "RETRO-3",
      "title": "/retro skill and retro-analyst agent",
      "scope": "`.claude/skills/retro/SKILL.md` (`disable-model-invocation: true`): sekcje „Uruchamianie” (ręcznie, warunki przed startem w kolejności specyfikacji, wzorzec `pgrep`), „Przebieg” (kroki 1–8: blokada, `sync`, worktree, `collect`, agent, `validate` z jedną poprawką, PR na wspólnej gałęzi `retro/*` z commitem na poprawkę i „Statusem”, raport z `## Zużycie`, decyzje użytkownika, push) i „PR”. `.claude/agents/retro-analyst.md` (Opus, narzędzia jak w specyfikacji, zasady agenta z kroku 3 i sekcji „Interwencje naprawcze”). Zdanie o kolumnie „Status” w szablonie `.claude/skills/planner/SKILL.md` (sekcja „Manifest”). Nie obejmuje: uruchamiania retro przez `/ao-run` i dokumentacji procesu (RETRO-4).",
      "acceptance_criteria": [
        "Warunki startu na ścieżce błędu: limit (≥ 95% z `/ao-run`, ≥ 80% ręcznie; bez sprawdzania zasilania) i działający AO przy przebiegu ręcznym odraczają (epik w `pending`); wzorzec `pgrep` sprawdzony na prawdziwym procesie `ao run` i na monitorze `watch`; PR `CLOSED` pomija trwale z przyczyną; PR `OPEN` i zły argument odmawiają bez zapisu; cudza blokada odracza. Żadna ścieżka nie zmienia niczego w GitHubie. Pokazane.",
        "Prawdziwy przebieg `/retro visual-design`: incydent `no_sweep` w raporcie i krok 12 nie jest oznaczony jako zweryfikowany; problem `--add-qa-rounds` po `FINALIZE` zmieniającym tylko dokumentację (VISUAL-DESIGN raz, DISCOUNT-CODES raz) przechodzi próg i jest rozpoznany jako istniejące issue AO #46 (`existing_issue`), bez nowego issue; drugie `--add-qa-rounds` w VISUAL-DESIGN (po REPAIR-1 z merge'em `origin/main`) jest osobnym incydentem ocenianym niezależnie (`duplicate_check` z AO #45). Decyzje podejmuje użytkownik.",
        "Szablon `/planner` mówi: „Stan epiku prowadzonego przez AO jest w `ao status`, a plan usuwa `FINALIZE`” zamiast zdania o uzupełnianiu kolumny przez użytkownika; testy `planner` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["RETRO-2"]
    },
    {
      "id": "RETRO-4",
      "title": "/ao-run runs /retro after /sweep and process docs",
      "scope": "`.claude/skills/ao-run/SKILL.md` i `docs/features/ao-run/spec.md`: retro w kolejce przeglądów po `/sweep` (sekcja 7a), ponowienie odroczonych przez `retro.py pending` przy kolejnym merge'u, po końcu procesu AO i na starcie przebiegu, odroczone retro nie blokuje startu epiku, liczba epików w `pending` w pushu sekcji 8, komentarz z pomiarem (sekcja 7) w formacie „Ręczne poprawki: N” i po jednej linii `- PR #<nr>: <opis>`. Dokumentacja z sekcji „Zmiany w dokumentacji” specyfikacji: `docs/features/agentic-software-house/plan.md` (status i opis kroku 15, punkt 7 procesu, rola „Retrospektywa epiku”), `docs/adr/agentic-software-house.md` (wiersz „Pętla uczenia” wskazuje `/retro`, bez „po evalach”), `CLAUDE.md` (akapit o `/retro`).",
      "acceptance_criteria": [
        "`/ao-run`: retro w kolejce po `/sweep`; odroczone retro nie blokuje startu epiku i wraca przy następnym merge'u albo starcie przebiegu. Instrukcja w PR; zachowanie pokazane na pierwszym prawdziwym merge'u epiku po merge'u tego zadania.",
        "Komentarz z pomiarem w sekcji 7 `/ao-run` ma format „Ręczne poprawki: N” i po jednej linii `- PR #<nr>: <opis>` (albo `- <opis>`), który `retro.py collect` czyta.",
        "Plan Agentic Software House (status i opis kroku 15, punkt 7 procesu, rola), ADR (wiersz „Pętla uczenia” bez „po evalach”) i `CLAUDE.md` opisują `/retro` zgodnie ze specyfikacją; testy `ao-run` przechodzą."
      ],
      "risk": "STANDARD",
      "depends_on": ["RETRO-3"]
    }
  ]
}
```
