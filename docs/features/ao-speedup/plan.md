# Przyspieszenie epików AO: plan

Trzy zmiany z raportu `docs/reports/ao-performance-agent-security-sentry-2026-11-15.md`, zaakceptowane przez
użytkownika 2026-11-15 (decyzje D1–D4). Bez przebudowy AO i bez dodatkowych mechanizmów. Priorytet: krótszy czas
zegarowy epiku przy tej samej jakości. Plan usuwamy po pomiarze na dwóch epikach.

## Zadania

1. **`/integration`: testy z CI pusha i fallback e2e w częściach** (PR w `shop`).
   - `delta.py ci` czeka na run pusha dla dokładnie HEAD: budżet 10 min w `DELTA`, 20 min w `FULL`, liczony od
     pierwszego wywołania w kroku bramek; jedno wywołanie najwyżej 540 s. Zielone joby `checks` i `e2e` zastępują
     lokalne `make test` i `make e2e` w obu trybach. Czerwony wymagany job to czerwona bramka bez lokalnego obejścia
     i bez reruna. Brak runu, run w toku po budżecie, anulowanie albo błąd `gh`: lokalny fallback.
   - Fallback e2e w częściach jak w CI (liczba części i wagi z `ci.yml`), `make seed` przed każdą, każda poniżej
     `timeout 570`; ponawiana raz tylko część, która padła.
   - `make check` zostaje lokalnie. Walidator `integration_result.py` przyjmuje wpisy `ci:` w obu trybach i czerwone CI.
2. **AO #50: dwie próby naraz** (PR w AO, potem PR w `shop`).
   - AO: `run --max-parallel N` (domyślnie 1). Druga próba tylko dla zadań `plan`, gdy kandydat i każde trwające
     zadanie mają się nawzajem na listach `parallel_with` manifestu (brak pola: szeregowo); integracja, FIX, FINALIZE
     i REPAIR zawsze same. Procesy zamkniętych prób blokują każdy nowy start (D1). Merge'e po kolei, awaria jednej
     próby nie zatrzymuje drugiej, osobny worktree, stos i blok portów.
   - `shop`: pola zadania `paths` i `parallel_with` w manifeście; `plan_manifest.py` wymaga symetrii i odrzuca parę
     z tym samym plikiem albo wspólnym kodem aplikacji według `parallel_gate` (wyjątki reguły 19a bez zmian, D2);
     `/planner` je wypełnia; `/ao-run` przekazuje `--max-parallel 2`; bramka 19a rezerwuje dwa stosy na epik;
     specyfikacja 19b w `docs/features/parallel-work/spec.md`.
3. **Eksperyment Sonnet/Opus developera** (bez zmian w repo poza raportem). SENTRY-1, SENTRY-2, AGENT-SECURITY-1 od
   bazy zadania, Opus i Sonnet po jednym przebiegu, bez push, PR i CI; ocena testami z prawdziwego PR, `make check`
   i testami aplikacji, niezależnym review, wykryciem F1, rundami poprawek, kosztem i czasem. Limit $100 razem,
   przebieg najwyżej 2× koszt oryginału i $20; start tylko przy wolnych zasobach i oknie limitu ≤ 80%, bez
   opóźniania aktywnych epików (D4). Bez zmiany modelu przed decyzją użytkownika.
4. **Pomiar** na dwóch kolejnych epikach po zadaniach 1 i 2: czas zegarowy osobno od tokenów, wobec bazy z raportu;
   wynik jako nowy raport w `docs/reports/`.

## Status

- Zadanie 1: zrobione (ten PR), działa w epikach zaczętych po merge'u.
- Zadanie 2: w toku (PR w AO i PR w `shop` po review).
- Zadanie 3: do zrobienia (czeka na wolne zasoby).
- Zadanie 4: do zrobienia (po zadaniach 1 i 2).
