---
name: brainstorm
description: Projektowanie funkcjonalności i zmian architektonicznych przed implementacją.
model: opus
effort: high
disable-model-invocation: true
---

Zaprojektuj najprostsze poprawne rozwiązanie spełniające wymagania.
Ten skill zastępuje superpowers:brainstorming, gdy użytkownik wywołuje /brainstorm. Nie uruchamiaj obu.

## Proces

1. Przeczytaj CLAUDE.md, istotną dokumentację i istniejący kod. Nie pytaj o fakty dostępne w projekcie.
2. Ustal cel, ograniczenia, wymagania niefunkcjonalne i kryteria sukcesu.
3. Dobierz zakres procesu:
   - Prosta zmiana: krótka rekomendacja bez zbędnej ceremonii.
   - Istotna zmiana: przeanalizuj alternatywy, zależności i ryzyka.
   - Niezależne funkcjonalności: zaproponuj podział i projektuj je osobno.
4. Niepewne założenia techniczne sprawdzaj małym, bezpiecznym eksperymentem zamiast zgadywać. Nie modyfikuj kodu produkcyjnego.
5. Zanim zaproponujesz własny mechanizm, sprawdź istniejące możliwości frameworka, standardów i sprawdzonych bibliotek.
6. Gdy rozwiązanie staje się złożone, zidentyfikuj założenia powodujące tę złożoność. Sprawdź, czy rzeczywiście są wymagane.
7. Porównuj 2–3 podejścia tylko przy istotnych kompromisach. Zawsze wskaż rekomendację.
8. Pytaj wyłącznie o decyzje istotnie wpływające na projekt. Najważniejsze najpierw, maksymalnie 2 pytania w jednej rundzie. Pozostałe kwestie rozstrzygaj samodzielnie jako jawne założenia.
9. Nie wracaj do zatwierdzonych decyzji bez nowego, konkretnego powodu.
10. Przed zakończeniem sprawdź spójność z architekturą i kompletność wymagań.

## Rezultat

Przedstaw zwięźle:
- Rekomendowane rozwiązanie i uzasadnienie.
- Główne komponenty i przepływ danych.
- Kryteria akceptacji i najważniejsze scenariusze testowe.
- Ryzyka, ograniczenia i zależności.
- Otwarte decyzje i przyjęte założenia.

Nie implementuj. Przedstaw projekt i poczekaj na akceptację.

Po akceptacji:
- Istotna funkcjonalność: zapisz lub zaktualizuj specyfikację zgodnie z CLAUDE.md.
- Drobna zmiana: wystarczy ustalenie w rozmowie, chyba że projekt wymaga dokumentacji.

Nie twórz ADR ani dodatkowych dokumentów bez potrzeby.
Nie rozpoczynaj implementacji automatycznie.

Pisz zwięźle po polsku.
