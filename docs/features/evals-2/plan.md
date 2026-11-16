# EVALS 2.0: plan

Specyfikacja: `spec.md`. Każdy etap zaczyna się od propozycji z zakresem, limitem kosztu i kryteriami akceptacji,
którą zatwierdza właściciel. AO nie prowadzi tych prac.

## Etapy

1. **Pilot** na `code-reviewer`: 5 prawdziwych przypadków po 3 przebiegi w trybie `squash`, etykiety właściciela.
2. **2A. Odtworzenie prawdziwego review** (bez wywołań modelu): recenzowany commit, oryginalne zlecenie z transkryptu
   AO, `code-reviewer` jako subagent, kontrola dokładnie jednego wywołania, dowód wady wzorcowej w recenzowanym diffie.
3. **2B. Pomiar porównawczy** w trybie `replay` na tych samych 5 przypadkach, bez stosu Dockera. Pytania: czy przy
   prawdziwym zleceniu znikają fałszywe alarmy i zgłoszenia nierozstrzygnięte z pilota, czy zmienia się wykrywanie
   #113 i #197, czy werdykty są stabilniejsze. Propozycja limitu: 25 USD łącznie, 3 USD na przebieg; najpierw
   5 przebiegów i etykiety, potem decyzja o pozostałych 10.
4. **2C. Powiększenie zestawu:** lista kandydatów (wady niewymienione w prompcie reviewera, przypadki czyste do
   potwierdzenia przez właściciela). Nowe przypadki wchodzą do zestawu po osobnej decyzji.

## Status

- Etap 1: zrobiony. Raport: `docs/reports/evals-2-pilot-code-reviewer-2026-11-15.md`.
- Etap 2A: zrobiony (tryb `replay` w `tools/evals-pilot/run.py`, dane odtworzenia 5 przypadków, testy).
  Trybu nie uruchomiono jeszcze na prawdziwym modelu.
- Etap 2B: czeka na osobną zgodę właściciela na płatny pomiar.
- Etap 2C: lista kandydatów przygotowana poza repo; czeka na decyzję właściciela, które przypadki dodać.
