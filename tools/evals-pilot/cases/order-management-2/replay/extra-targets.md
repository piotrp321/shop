# Dodatkowe cele pomiarowe: ORDER-MANAGEMENT-2

Zgłoszenia oryginalnego pierwszego review FULL tego zadania (transkrypt AO, to samo wywołanie co zlecenie w `order.md`),
z wagą nadaną wtedy. Dotyczą recenzowanego commitu `review_sha`; część została naprawiona w rundzie poprawek, więc
w squashu ich nie ma. Mierzymy, czy bieżący reviewer stawia je ponownie.

Nie zmieniają wady wzorcowej z `gold.md` ani jej kryteriów: wykrycie celu dodatkowego nie jest wykryciem wady wzorcowej.
Lista powstała 2026-11-16, po pierwszym przebiegu odtworzenia, a przed drugim.

| Cel | Waga w oryginale | Usterka |
|---|---|---|
| E1 | Minor | Kontrakt OpenAPI odpowiedzi złożenia zamówienia ma `status` z enum `new|paid|cancelled`, a powtórzenie złożenia może zwrócić nowe statusy. |
| E2 | Minor | Luka w dowodach TDD: poprawka `DoctrineOrderRepository::save()` nie ma wpisu RED. |
| E3 | Minor | Ochronę przed naruszeniem PK historii przy przegranym wyścigu pokrywa tylko niedeterministyczny `WebhookConcurrencyTest` W3. |
