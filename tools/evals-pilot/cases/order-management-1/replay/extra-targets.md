# Dodatkowe cele pomiarowe: ORDER-MANAGEMENT-1

Zgłoszenia oryginalnego pierwszego review FULL tego zadania (transkrypt AO, to samo wywołanie co zlecenie w `order.md`),
z wagą nadaną wtedy. Dotyczą recenzowanego commitu `review_sha`; część została naprawiona w rundzie poprawek, więc
w squashu ich nie ma. Mierzymy, czy bieżący reviewer stawia je ponownie.

Nie zmieniają wady wzorcowej z `gold.md` ani jej kryteriów: wykrycie celu dodatkowego nie jest wykryciem wady wzorcowej.
Lista powstała 2026-11-16, po pierwszym przebiegu odtworzenia, a przed drugim.

| Cel | Waga w oryginale | Usterka |
|---|---|---|
| E1 | Minor | Ponowienia `inProgress` zużywają limit ponowień transportu `events`. |
| E2 | Minor | `release` nie sprawdza, kto trzyma zajęcie. |
| E3 | Minor | `docs/features/payment/spec.md` zbyt stanowczo opisuje, kiedy możliwy jest drugi e-mail. |
| E4 | Minor | Okno czasowe w `SendOrderEmailsConcurrencyTest` (`usleep`) może być łamliwe. |
