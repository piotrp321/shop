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
2. Ustal cel, ograniczenia, wymagania niefunkcjonalne i kryteria sukcesu. Najpierw biznes: cel, zakres względem MVP,
   zachowanie użytkownika, zależności od istniejących funkcjonalności. Dopiero potem DDD i architektura.
   Jeśli funkcja wykracza poza `docs/vision.md` albo zależy od funkcji, których jeszcze nie ma, sprawdź, czy budować ją teraz.
3. Dobierz zakres procesu:
   - Prosta zmiana: krótka rekomendacja bez zbędnej ceremonii.
   - Istotna zmiana: przeanalizuj alternatywy, zależności i ryzyka.
   - Niezależne funkcjonalności: zaproponuj podział i projektuj je osobno.
4. Niepewne założenia techniczne sprawdzaj małym, bezpiecznym eksperymentem zamiast zgadywać. Nie modyfikuj kodu produkcyjnego.
5. Zanim zaproponujesz własny mechanizm, sprawdź istniejące możliwości frameworka, standardów i sprawdzonych bibliotek.
6. Gdy rozwiązanie staje się złożone, zidentyfikuj założenia powodujące tę złożoność. Sprawdź, czy rzeczywiście są wymagane.
   Każda istotna dodatkowa złożoność (nowy moduł, warstwa, mechanizm) wymaga konkretnego uzasadnienia.
7. Porównuj 2–3 podejścia tylko przy istotnych kompromisach. Zawsze wskaż rekomendację.
8. Pytaj wyłącznie o decyzje istotnie wpływające na projekt. Najważniejsze najpierw, maksymalnie 2 pytania w jednej rundzie. Pozostałe kwestie rozstrzygaj samodzielnie jako jawne założenia.
   Możesz przedstawić wstępny projekt z pytaniami na końcu. Gdy brakuje kluczowej decyzji biznesowej, oznacz projekt
   wyraźnie jako warunkowy i wskaż, które jego części od niej zależą.
9. Nie wracaj do zatwierdzonych decyzji bez nowego, konkretnego powodu.
10. Przed zakończeniem sprawdź spójność z architekturą, z CLAUDE.md (głównym i aplikacji) i kompletność wymagań.
    Nie formułuj niesprawdzonych twierdzeń o kodzie, Deptrac i architekturze. Przy istotnych decyzjach podaj plik.
11. Sprawdź zgodność wymagań, API, UI i testów oraz przypadki brzegowe: autoryzację, współbieżność, propagację błędów
    aż do UI (czy UI odróżni przypadki), paginację, brakujące dane. Nie dodawaj mechanizmów dla hipotetycznych przypadków.

## Rezultat

Przedstaw zwięźle:
- Rekomendowane rozwiązanie i uzasadnienie. Ważne decyzje architektoniczne i DDD uzasadnij krótko, także w specyfikacji.
- Główne komponenty i przepływ danych.
- Kryteria akceptacji i najważniejsze scenariusze testowe.
- Ryzyka, ograniczenia i zależności.
- Otwarte decyzje i przyjęte założenia.

Nie implementuj. Przedstaw projekt i poczekaj na akceptację.
Odpowiedzi na pytania ani przyjęcie pojedynczej rekomendacji nie są akceptacją projektu. Po odpowiedziach pokaż zmiany
w projekcie (kompletny projekt, jeśli był warunkowy) i poczekaj na jednoznaczną akceptację przed zapisem specyfikacji.
Przed akceptacją projektu nie zmieniaj żadnych plików w repozytorium.

Po akceptacji:
- Istotna funkcjonalność: zapisz lub zaktualizuj tylko specyfikację zgodnie z CLAUDE.md. Pozostałe zmiany dokumentacji
  (`docs/vision.md`, `CLAUDE.md` i inne) wpisz w specyfikacji jako zadania wykonywane razem z implementacją.
- Zmiana samej dokumentacji: edytuj pliki objęte zatwierdzoną zmianą.
- Drobna zmiana: wystarczy ustalenie w rozmowie, chyba że projekt wymaga dokumentacji.

Nie twórz ADR ani dodatkowych dokumentów bez potrzeby.
Nie rozpoczynaj implementacji automatycznie.

Pisz zwięźle po polsku.
