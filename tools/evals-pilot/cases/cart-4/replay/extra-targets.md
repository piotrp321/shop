# Dodatkowe cele pomiarowe: CART-4

Zgłoszenia oryginalnego pierwszego review FULL tego zadania (transkrypt AO, to samo wywołanie co zlecenie w `order.md`),
z wagą nadaną wtedy. Dotyczą recenzowanego commitu `review_sha`. Mierzymy, czy bieżący reviewer stawia je ponownie.

Nie zmieniają wady wzorcowej z `gold.md` ani jej kryteriów: wykrycie celu dodatkowego nie jest wykryciem wady wzorcowej.
Lista powstała 2026-11-16, przed pierwszym przebiegiem tego przypadku.

| Cel | Waga w oryginale | Usterka |
|---|---|---|
| E1 | Important | `CartBadge` nie miał prawdziwej fazy RED: test i implementacja powstały w jednym wywołaniu, RED odtworzono po fakcie. |
| E2 | Minor | `DELETE` z `productId`, który nie jest UUID, usuwa poprawne ciasteczko `store_cart` (BFF traktuje każde 404 jako zniknięcie koszyka). |
| E3 | Minor | Równoległe pierwsze dodania gościa bez `store_cart` zakładają dwa koszyki i gubią pozycję. |
| E4 | Minor | Opis `src/cart/` w `apps/store/CLAUDE.md` nie wymienia komponentów koszyka. |
