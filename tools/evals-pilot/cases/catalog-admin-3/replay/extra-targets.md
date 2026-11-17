# Dodatkowe cele pomiarowe: CATALOG-ADMIN-3

Zgłoszenia oryginalnego pierwszego review FULL tego zadania (transkrypt AO, to samo wywołanie co zlecenie w `order.md`),
z wagą nadaną wtedy. Dotyczą recenzowanego commitu `review_sha`; część została naprawiona w rundzie poprawek, więc
w squashu ich nie ma. Mierzymy, czy bieżący reviewer stawia je ponownie.

Nie zmieniają wady wzorcowej z `gold.md` ani jej kryteriów: wykrycie celu dodatkowego nie jest wykryciem wady wzorcowej.
Lista powstała 2026-11-16, po pierwszym przebiegu odtworzenia, a przed drugim.

| Cel | Waga w oryginale | Usterka |
|---|---|---|
| E1 | Minor | Okno wyścigu przy tworzeniu pierwszej ceny wariantu równolegle ze zmianą klasy podatkowej, nieopisane w spec (ten sam wyścig co w `known.md`). |
| E2 | Minor | Nieaktualne opisy zależności Pricing → Catalog w `docs/features/pricing/spec.md` (i komentarzu Deptraca). |
| E3 | Minor | `PriceReconciler` tworzony przez `new` w konstruktorach `ReconcileTax` i `RepriceProductPrices`. |
