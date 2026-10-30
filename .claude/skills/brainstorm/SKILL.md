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
2. Business discovery przed projektowaniem. Przeanalizuj: cel i kryteria sukcesu, zakres względem MVP i zależności
   od istniejących funkcjonalności, kto korzysta i jak się zachowuje, kto co może (uprawnienia), reguły i limity
   danych (unikalność, limity, cykl życia, retencja), co widzi użytkownik, gdy coś się nie uda, oraz przypadki brzegowe
   widoczne dla użytkownika. Dopiero potem DDD i architektura.
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
8. Nie wymyślaj istotnych wymagań biznesowych. Jeśli zakres, limity, reguły albo zachowanie użytkownika wpływają
   na projekt, uzyskaj decyzję. Zadaj wszystkie takie pytania zbiorczo, w jednej wiadomości, pogrupowane według
   ważności, każde z rekomendowanym wariantem. Umożliw zbiorczą akceptację rekomendacji jedną odpowiedzią.
   Liczba pytań wynika z niepewności, nie z limitu. Nie pytaj o nieistotne szczegóły ani o to, co wynika z prośby,
   dokumentacji albo kodu.
   Wiadomość z pytaniami zawiera krótkie podsumowanie rozumienia i pytania, bez projektu. Każde założenie biznesowe
   pokaż jako osobne pytanie z rekomendacją, nie ukrywaj go w domyślnej odpowiedzi ani w założeniach projektu.
   Nie zawężaj zakresu funkcji bez pytania: prostota dotyczy rozwiązania, nie wymagań. Gdy nie ma otwartych decyzji, przejdź od razu do projektu. Kolejną rundę pytań zadaj
   tylko wtedy, gdy odpowiedzi otworzyły nową istotną decyzję.
   Kwestie techniczne (klasy, ORM, wzorce, szczegóły implementacji) rozstrzygaj sam jako jawne założenia. Pytaj o nie
   tylko wtedy, gdy zmieniają istotną decyzję biznesową albo architektoniczną (np. nowa zależność, zmiana zasady projektu).
9. Nie wracaj do zatwierdzonych decyzji bez nowego, konkretnego powodu.
10. Kontrola jakości przed przedstawieniem projektu. Sprawdź projekt z ustalonymi wymaganiami (każda decyzja
    użytkownika ma odzwierciedlenie), z CLAUDE.md (głównym i aplikacji) i z rzeczywistym kodem: kontrakty i kody błędów,
    autoryzację, granice modułów (Deptrac), wzorce, na które się powołujesz. Każde twierdzenie o kodzie sprawdź w pliku
    i podaj go przy istotnych decyzjach.
11. Sprawdź zgodność wymagań, API, UI i testów oraz przypadki brzegowe: autoryzację, współbieżność, propagację błędów
    aż do UI (czy UI odróżni przypadki), paginację, brakujące dane. Nie dodawaj mechanizmów dla hipotetycznych przypadków.

## Rezultat

Przedstaw zwięźle:
- Rekomendowane rozwiązanie i uzasadnienie. Ważne decyzje architektoniczne i DDD uzasadnij krótko, także w specyfikacji.
- Główne komponenty i przepływ danych.
- Kryteria akceptacji i najważniejsze scenariusze testowe.
- Ryzyka, ograniczenia i zależności.
- Otwarte decyzje i przyjęte założenia techniczne.

Nie implementuj. Po discovery przedstaw kompletny projekt w jednej wiadomości, bez zatwierdzania sekcji osobno,
i poczekaj na akceptację.
Odpowiedzi na pytania ani przyjęcie rekomendacji (pojedynczej albo zbiorczo) nie są akceptacją projektu. Akceptacja jest jedna,
jednoznaczna i dotyczy całego projektu.
Przed akceptacją projektu nie zmieniaj żadnych plików w repozytorium.

Po akceptacji:
- Istotna funkcjonalność: zapisz lub zaktualizuj tylko specyfikację zgodnie z CLAUDE.md. Pozostałe zmiany dokumentacji
  (`docs/vision.md`, `CLAUDE.md` i inne) wpisz w specyfikacji jako zadania wykonywane razem z implementacją.
  Po zapisie porównaj specyfikację z zaakceptowanym projektem. Jeśli coś zmieniasz względem niego, pokaż zmianę.
- Zmiana samej dokumentacji: edytuj pliki objęte zatwierdzoną zmianą.
- Drobna zmiana: wystarczy ustalenie w rozmowie, chyba że projekt wymaga dokumentacji.

Nie twórz ADR ani dodatkowych dokumentów bez potrzeby.
Nie rozpoczynaj implementacji automatycznie.

Pisz zwięźle po polsku.
