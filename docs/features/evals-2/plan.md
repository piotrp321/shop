# EVALS 2.0: plan

Specyfikacja: `spec.md`. Każdy etap zaczyna się od propozycji z zakresem, limitem kosztu i kryteriami akceptacji,
którą zatwierdza właściciel. AO nie prowadzi tych prac.

## Etapy

1. **Pilot** na `code-reviewer`: 5 prawdziwych przypadków po 3 przebiegi w trybie `squash`, etykiety właściciela.
2. **2A. Odtworzenie prawdziwego review** (bez wywołań modelu): recenzowany commit, oryginalne zlecenie z transkryptu
   AO, `code-reviewer` jako subagent, kontrola dokładnie jednego wywołania, dowód wady wzorcowej w recenzowanym diffie.
3. **2B. Pomiar w trybie `replay`** na tych samych 5 przypadkach, bez stosu Dockera, z limitem 25 USD łącznie
   i 3 USD na przebieg.
4. **2C. Powiększenie zestawu:** lista kandydatów (wady niewymienione w prompcie reviewera, przypadki czyste do
   potwierdzenia przez właściciela). Nowe przypadki wchodzą do zestawu po osobnej decyzji.

## Status

- Etap 1: zrobiony. Raport: `docs/reports/evals-2-pilot-code-reviewer-2026-11-15.md`.
- Etap 2A: zrobiony (tryb `replay` w `tools/evals-pilot/run.py`, dane odtworzenia 5 przypadków, testy).
- Etap 2B: zrobiony w zakresie 2 przebiegów na przypadek (budżet 25 USD nie mieścił trzech). Raport:
  `docs/reports/evals-2-replay-code-reviewer-2026-11-16.md`. Prace nad runnerem są zakończone.
- Etap 2C: lista kandydatów przygotowana poza repo; czeka na decyzję właściciela, które przypadki dodać.
- Eksperyment A/B (punkt „Przepływ wartości wejściowych” w `code-reviewer`): zrobiony, zmiana nie spełniła kryteriów
  przyjęcia. Raport: `docs/reports/evals-2-ab-code-reviewer-value-flow-2026-11-16.md`. Wariant promptu zostaje na gałęzi
  `evals/code-reviewer-value-flow` i nie jest mergowany.
- Następny krok: decyzja właściciela o kolejnej próbie poprawy `code-reviewer`.
