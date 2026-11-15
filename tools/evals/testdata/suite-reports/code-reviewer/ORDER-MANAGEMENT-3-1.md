## Review ORDER-MANAGEMENT-3 „Admin API for orders”

- **SHA:** HEAD `836a88a49eff094b35aeb7da335f32d5736bf276`, potwierdzony (`git rev-parse HEAD`, drzewo czyste przez cały review). Diff: `405a368b..836a88a`, 6 commitów (eb78987, a387613, 8506747, f3fa67c, 5db6642, 836a88a).
- **Tryb:** FULL
- **Werdykt: PASS.** Nie ma BLOCKER-ów ani IMPORTANT-ów, są trzy uwagi Minor.

Testów ani poleceń w Dockerze nie uruchamiałem. Ocena opiera się na kodzie, gicie i dzienniku dowodów.

### Trzy kontrole obowiązkowe

1. **Istniejące testy: bez uwag.** Wszystkie 13 plików testów w diffie to nowe pliki (status `A`). Żaden test z `base_sha` nie został zmieniony, usunięty ani wyłączony. W diffie nie ma `skip`, `only` ani zmian w konfiguracji PHPUnit.
2. **Zakres: bez uwag.** Wszystkie pliki mieszczą się w `scope` z manifestu:
   - Ordering Application, Infrastructure i UI;
   - migracja i `OrderRecord`;
   - `deptrac.yaml`, `nelmio_api_doc.yaml`;
   - `openapi-admin.json` i `apps/admin/src/api/schema.d.ts`;
   - `apps/api/CLAUDE.md`.

   `apps/api/openapi.json` i `apps/store` nie są zmienione. Indeks klienta `(customer_id, …)` celowo pominięto, należy do ORDER-MANAGEMENT-5.
3. **RED przed implementacją: zgodnie z TDD.** Dziennik ma 17 wpisów.
   - Każdy z pięciu RED (voter, handler szczegółów, handler listy i kursor, adapter DBAL, testy funkcjonalne) ma `dirty` zawierające tylko pliki testów.
   - `head` każdego RED to commit poprzedniego kroku, który nie zawiera kodu produkcyjnego testowanego zachowania. Sprawdziłem `git show --stat` dla eb78987, a387613, 8506747 i f3fa67c.
   - Przyczyny RED: `Class ... not found` z nazwami testów (np. `OrderVoterTest::testAnonymousIsDeniedEverything`) oraz brak trasy dla testów funkcjonalnych.
   - GREEN dla każdego RED jest zapisany później.
   - Uwaga: końcówka wyjścia RED testów funkcjonalnych (wpis 12) pokazuje ścieżki plików z numerem linii (`GetBackofficeOrderEndpointTest.php:134`, `ListBackofficeOrdersEndpointTest.php:210`), a nie nazwy metod. Jest tam „Tests: 122, Failures: 122”. Klasy testów da się zidentyfikować, więc uznaję to za wystarczające.
   - Commit infrastrukturalny eb78987 (migracja, indeksy w mapowaniu, `OrderingSchemaTest`) nie ma wpisów test w dzienniku. Według `CLAUDE.md` infrastrukturę weryfikuje się komendami, bez RED, więc to nie jest zgłoszenie. Zielony `OrderingSchemaTest` potwierdzi dopiero trwający `make -C apps/api test`.

### Pytania orkiestratora

1. **Kolejność sprawdzeń: zgodna ze specyfikacją i pokryta testami.**
   - Zmiana statusu: `#[IsGranted(VIEW_ORDERS)]` działa przed mapowaniem treści. Potwierdza to `testMarketingIsForbiddenBeforeTheBodyIsRead`: 403 przy złym JSON, 422-owej treści i `text/plain`.
   - Potem 400/415/422 (`testTheBodyIsCheckedBeforeTheRoleOfTheTransition`: warehouse+cancel bez notatki daje 422, nie 403).
   - Potem 403 dla przejścia (`ChangeOrderStatusController.php:325`, `testTheRoleOfTheTransitionIsCheckedBeforeTheOrderIsLookedFor`), następnie 404 i 409.
   - Wykonanie zwrotu: `#[IsGranted(COMPLETE_REFUND)]` jest przed treścią i przed 404 (`testOnlyAdminAndSupportCompleteRefundsAndTheRoleIsCheckedFirst`).
2. **Reguła ról (P6): tylko w `OrderVoter`.** Plik: `apps/api/src/Ordering/Infrastructure/Security/OrderVoter.php`.
   - Kontrolery i `BackofficeOrderResponder` znają tylko nazwy atrybutów.
   - Przypadek CHANGE_ORDER_STATUS bez subjectu ma `supports() = false`, czyli odmowę.
   - Próby obejścia pokryte testami:
     - support/ship i support/deliver;
     - warehouse/cancel, warehouse/return i wykonanie zwrotu;
     - marketing na każdej trasie, także z błędnym body;
     - kombinacje warehouse+marketing;
     - brak ról;
     - token klienta (401 z firewalla).
   - `allowedTransitions` i `canCompleteRefund` są filtrowane voterem także w odpowiedziach POST. Przykłady: warehouse po ship dostaje `['deliver']`, support po cancel dostaje `canCompleteRefund: true`.
3. **Lista: poprawna.**
   - `q`: `Length(1..254, normalizer: trim)` i zakaz NUL dają 400.
   - Handler: `^[Rr]-[0-9]+$` jest normalizowane do `R-…`; wartość z `@` to e-mail; każda inna to pusta lista bez odczytu.
   - Porównanie `lower(o.email) = lower(:email)` odpowiada indeksowi `lower((email)::text)`.
   - Keyset: DESC `(placed_at,id) < (…)` z `ORDER BY placed_at DESC, id DESC`; ASC `(refund_due_at,id) > (…)` z `ORDER BY … ASC`. Jest poprawny, a remisy rozstrzyga `id`. Testy na PostgreSQL sprawdzają brak duplikatów i pominięć.
   - Kursor ma pole sortu `s`, więc kursor drugiego sortu daje 400. Moment jest w UTC z mikrosekundami, UUID tylko małymi literami.
   - Pozostałe punkty pokryte: `perPage` 1–100, `refund=pending` w połączeniu ze `status` i `q`, `overdue` liczone z zegara API.
4. **Szczegóły: komplet pól.** `paymentId` (P13), historia od najstarszego (`OrderBy position ASC`), zwrot z wykonaniem (data, pracownik, notatka), `version`, daty statusów. Pokrywa to `GetBackofficeOrderEndpointTest`.
5. **Kontrakt OpenAPI: spójny.**
   - Każda odpowiedź ma `private, no-store`: kontrolery i `OrderResponseCacheSubscriber` z regexem rozszerzonym o `admin/orders`.
   - `BackofficeOrderConflictProblem` jest tylko w obszarze `admin`.
   - `openapi.json` jest niezmieniony.
   - W `openapi-admin.json` żadna istniejąca ścieżka ani schemat nie zniknęły; doszły tylko cztery nowe ścieżki.
   - `schema.d.ts` ma nowe operacje i schematy.
6. **Migracja, architektura i bezpieczeństwo: bez uwag.**
   - Migracja ma `down` w odwrotnej kolejności niż `up`. Mapowanie zgadza się z migracją, pilnują tego `OrderingSchemaTest` i porównanie `pg_indexes`.
   - Deptrac ma dokładnie dwie krawędzie ze specyfikacji.
   - Adapter DBAL stoi za portem `BackofficeOrderReader` w Application.
   - SQL składa się ze stałych fragmentów, a całe wejście trafia do zapytania jako parametry, więc nie ma wektora SQL injection.
   - Indeks `ordering_order_line(order_id)` istnieje, więc podzapytanie `item_count` go używa.
   - Komunikat błędu kursora nie zawiera przesłanej wartości (test `assertStringNotContainsString('garbage')`).
7. **GetBackofficeOrder przez ORM zamiast „via DBAL”: nie jest problemem.** Szczegóły w Minor 1.

### Ustalenia

**Minor 1: rozbieżność z brzmieniem manifestu.**
- Pliki: `apps/api/src/Ordering/Application/Query/GetBackofficeOrderHandler.php:39-40`.
- `scope` mówi „GetBackofficeOrder via DBAL”, a implementacja używa `OrderRepository::get` i `BackofficeOrderView::of`.
- Specyfikacja, która jest źródłem prawdy (sekcja „Komendy i zapytania”, linie 151 i 159), wymaga DBAL tylko dla `ListBackofficeOrders`.
- Jest precedens w Ordering: `GetOrderForPaymentHandler` też czyta przez repozytorium.
- Ten sam widok co odpowiedzi komend gwarantuje identyczny kształt GET i POST.
- Odczyt jest bez blokady i dotyczy jednego agregatu, więc nie ma wpływu na skalę.
- Rozwiązanie jest opisane w `apps/api/CLAUDE.md`.
- Sugestia: wpis `Ruling:` w ledgerze, żeby udokumentować odejście od brzmienia manifestu. Zmiana kodu nie jest potrzebna.

**Minor 2: ręcznie powielone listy wartości enumów w UI, bez testu, który by je pilnował.**
- `apps/api/src/Ordering/UI/Http/BackofficeOrderListQuery.php:17` (`STATUSES`) wobec `OrderStatus`, z `OrderStatus::from()` w `BackofficeOrdersController.php:209`.
- `apps/api/src/Ordering/UI/Http/ChangeOrderStatusRequest.php:24` (`TRANSITIONS`) wobec `StaffTransition`, z `StaffTransition::from()` w `ChangeOrderStatusController.php:324`.
- Scenariusze rozjazdu:
  - wartość jest w stałej, ale nie w enumie: walidacja ją przepuści, a `from()` rzuci `ValueError`, czyli 500;
  - nowy status jest w enumie, ale nie w stałej: filtr panelu zwróci dla niego 400.
- Dziś listy są zgodne. Sugestia: `Assert\Choice(callback: …)` z `OrderStatus::cases()` albo test jednostkowy porównujący stałe z `cases()`.

**Minor 3: reguła „po terminie” powielona poza domeną.**
- Plik: `apps/api/src/Ordering/Application/Query/ListBackofficeOrdersHandler.php:613`.
- `null === completedAt && now > dueAt` powtarza `Refund::isOverdue()`. Jest to opisane w komentarzu i pokryte testami granicznymi („at its due date” daje false), więc dziś działa poprawnie.
- Ryzyko: zmiana reguły w domenie nie obejmie listy. Dla modelu odczytu DBAL to akceptowalne.

### Pliki
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-3/apps/api/src/Ordering/Application/Query/GetBackofficeOrderHandler.php`
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-3/apps/api/src/Ordering/Application/Query/ListBackofficeOrdersHandler.php`
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-3/apps/api/src/Ordering/UI/Http/BackofficeOrderListQuery.php`
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-3/apps/api/src/Ordering/UI/Http/ChangeOrderStatusRequest.php`
- `<home>/.local/state/ao-order-management/tasks/ORDER-MANAGEMENT-3/delivery-state.json.evidence.jsonl`