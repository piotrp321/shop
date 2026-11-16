# Dodatkowe cele pomiarowe: CART-FIXES-3

Zgłoszenia oryginalnego pierwszego review FULL tego zadania (transkrypt AO, to samo wywołanie co zlecenie w `order.md`),
z wagą nadaną wtedy. Dotyczą recenzowanego commitu `review_sha`; część została naprawiona w rundzie poprawek, więc
w squashu ich nie ma. Mierzymy, czy bieżący reviewer stawia je ponownie.

Nie zmieniają wady wzorcowej z `gold.md` ani jej kryteriów: wykrycie celu dodatkowego nie jest wykryciem wady wzorcowej.
Lista powstała 2026-11-16, po pierwszym przebiegu odtworzenia, a przed drugim.

| Cel | Waga w oryginale | Usterka |
|---|---|---|
| E1 | Minor | `docs/features/cart/spec.md`: tabela komunikatów podaje 422 dla „nie jest UUID”, a pusty napis `""` przechodzi walidację i daje 404 (dodanie) albo 200 `nothing_to_merge` (scalenie). |
| E2 | Minor | Commit `caab46f` usuwa przypadki testowe `productId empty` i `guestCartId empty` dodane w RED, bez ujawnienia w opisie. |
