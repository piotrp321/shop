# ASH: poprawki procesu po serii v1.0, plan

Specyfikacja: [spec.md](spec.md). Zmiana poza epikiem: jedna gałąź (`claude/ash-process-fixes`), jeden PR do `main`,
merge po zgodzie właściciela. Plan jest dokumentem roboczym i znika przed oznaczeniem PR jako gotowego.

| Zadanie | Zakres | Ryzyko | Zależy od | Wykonawca |
|---|---|---|---|---|
| 1 | `metrics.py`: `process`, zapis wersji w `start`/`--resume`/`done`, pole `process` rekordu, odmowa drugiego epiku w tej samej sesji, `pause` i `host_pause` (W1–W4, E1, T3); `docs/features/metrics/spec.md`, `tools/metrics/README.md` | STANDARD | – | `developer`, TDD |
| 2 | `retro.py`: pełna treść punktów i dowodów, brak cichego ucinania na dalszych etapach, komentarz „Przekazanie do retro” i wskazania materiałów w `input.json` (R1–R4, P3); `docs/features/retro/spec.md`, `SKILL.md` i `retro-analyst.md` w części o tych polach | STANDARD | – | `developer`, TDD |
| 3 | `retro.py collect`: kontrola wersji przez `metrics.py process` i fakt z `process` rekordu (W4, W7) | STANDARD | 1, 2 | `developer`, TDD |
| 4 | Instrukcje: ADR „Przebieg epiku”, `CLAUDE.md`, `docs/vision.md`, `apps/api/CLAUDE.md`, agenci, `/brainstorm`, `/planner`, `/retro` (W5–W7, E2, P1, P2, P4, O, S, D, C, T1, T2); „Status” planu ASH, `docs/features/ash-v1/spec.md` (V2) | FAST | 1–3 (nazwy komend) | sesja prowadząca |
| 5 | Przegląd całej zmiany w świeżym kontekście (`code-reviewer` na zadaniach 1–3, `qa-adversarial` na komendach narzędzi na stanie tymczasowym), poprawki, kryteria akceptacji | – | 1–4 | reviewerzy z plikiem roli z worktree (W6) |

Zadania 1 i 2 idą równolegle (osobne pliki). Reviewerzy tej zmiany dostają w zleceniu plik roli z worktree, bo
główny checkout ma starsze definicje.

## Status

- 2026-11-21: specyfikacja i plan; zadania 1 i 2 w toku.
