## Review ORDER-MANAGEMENT-2: FULL, werdykt PASS

- **SHA:** HEAD `541d06e9c6185b52d16286823b2e0dc7611ff008`, potwierdzony na początku i na końcu review. Drzewo czyste. Diff `74e25e92..541d06e9`: 5 commitów, 72 pliki.
- **Ryzyko:** CRITICAL.
- **Werdykt:** PASS. Nie ma zgłoszeń BLOCKER ani IMPORTANT, są tylko Minor i sugestie. Trzecia kontrola ma lukę w dzienniku (pkt 3); oceniłem ją jako Minor, a uzasadnienie jest tam.
- **Co uruchomiłem:** tylko `make -C apps/api test-unit PHPUNIT_ARGS=tests/Unit/Ordering` na HEAD: OK, 330 testów, 1244 asercje. Testów integracyjnych ani funkcjonalnych nie uruchamiałem.

### Trzy kontrole obowiązkowe

**1. Istniejące testy: bez uwag.** Diff nie usuwa testów (brak plików D/R), nie dodaje `skip`/`only`/`markTestSkipped` i nie zmienia konfiguracji phpunit. Zmienione testy z `base_sha`:
- `GetOrderForPaymentHandlerTest`: zmiana `status` na `statusForPayment` (A3), te same wartości oczekiwane.
- `OrderStatusTextsTest`: test tokenu objął 5 treści zamiast 2.
- `SendOrderEmailsTest`: gałąź `default => fail` zastąpiona jawnymi przypadkami.
- `OrderEmailMarkersMigrationTest`: `runMigration` opakowuje migrację cyklu, bo nowa migracja jest późniejsza.
- `SendOrderEmailsConcurrencyTest`: nowy konstruktor mailera.
- `SeedDemoOrdersTest` i `ExpireUnpaidOrderHandlerTest`: dodatkowe asercje.
- `SendOrderStatusEmailsFlowTest::emails()`: parametr z domyślnym 2.

Żadna asercja nie została osłabiona.

**2. Zakres: bez uwag.** Wszystkie pliki mieszczą się w `scope` taska i w liście „Zakres” specyfikacji: Ordering src i testy, `Payment/Infrastructure/Ordering`, `tests/Functional/Payment`, migracja, docs. Nie ma endpointów, votera, zapytań panelu ani zmian OpenAPI.

**3. RED przed implementacją: w zasadzie spełnione, z jedną luką w dzienniku.**
- Każda faza ma RED zapisany przed GREEN, a pliki produkcyjne danego zachowania nie były wtedy w `dirty`:
  - domena: wpis 0, sam test (`StaffTransition not found`, `undefined method ship()`);
  - persystencja: wpis 4, w `dirty` tylko domena, bez repozytorium i migracji;
  - komendy: wpisy 9 i 10, w tym `ExpireUnpaidOrderHandlerTest` z „the order is locked before the release”;
  - `statusForPayment`: wpis 17;
  - e-maile: wpis 20.
- **Luka:** poprawka `DoctrineOrderRepository::save()` (najpierw versioned UPDATE, potem osobny flush historii) nie ma wpisu RED. Wpis 7 to tylko GREEN `WebhookConcurrencyTest|DoctrineOrderRepositoryTest`, bez nazwy testu w tail. Developer to ujawnił.
- **Dlaczego Minor, a nie IMPORTANT:** `WebhookConcurrencyTest` istniał w base i padł na niezacommitowanym kodzie tego samego zachowania. To regresja w fazie GREEN persystencji (jej RED to wpis 4), a nie nowy test zachowania. Jeśli traktujecie zabezpieczenie przed naruszeniem PK historii jako osobne zachowanie, podnieście to do IMPORTANT. W obu przypadkach pomaga ustalenie 3.

### Weryfikacja obszarów, o które prosiłeś (bez uwag)

- **Tabela przejść:** `OrderStatus::allowedStaffTransitions()` jest zgodna ze spec (paid: ship, cancel; shipped: deliver, return; delivered: return; pozostałe: brak). Testy obejmują 5 dozwolonych wierszy i wszystkie przejścia spoza tabeli, łącznie z wygaśniętym i anulowanym przez pracownika, ze snapshotem „bez zmian”.
- **Notatka:** `StaffNote` odrzuca znaki sterujące przed przycięciem, przyjmuje 1–500 znaków `mb_strlen` (zgodne z `varchar(500)`), wymaga notatki przy cancel i return, a pusta przy pozostałych oznacza brak. Walidacja notatki przed sprawdzeniem przejścia daje 422 przed 409, zgodnie z kolejnością z sekcji „API”.
- **Blokady:** `StaffOrderLock` robi kolejno `lock`, porównanie wersji pod blokadą, metodę domeny, Inventory przed `save`. Inventory działa przez DBAL, a agregat pisze do rekordu dopiero w `save`, więc flush zagnieżdżonego dispatchu nie zapisuje niczego z Ordering. `ExpireUnpaidOrderHandler` używa teraz `lock`. Webhook bierze blokadę płatności, potem UPDATE zamówienia i nie dotyka Inventory, więc nie ma cyklu blokad.
- **`lock()`:** ścieżka z rekordem w identity map robi `SELECT 1 … FOR UPDATE`, potem `refresh(PESSIMISTIC_WRITE)`. Refresh wstrzykuje niezainicjalizowaną kolekcję historii, a `testLockReadsTheOrderAsCommitted…` potwierdza 2 wpisy.
- **Wersje i historia w `save()`:** UPDATE z wersją idzie przed INSERT historii, więc przegrany wyścig (np. `MarkOrderPaid` bez blokady przeciw `ExpireUnpaidOrder`) kończy się `OptimisticLockException`, a nie naruszeniem PK `(order_id, position)`.
  - Liczba zapisanych wpisów pochodzi z kolekcji wczytanej przy tej samej wersji, a każda zmiana historii podnosi wersję, więc wpis nie może zginąć ani się zdublować.
  - `get()` z `HINT_REFRESH` też wstrzykuje kolekcję od nowa.
  - Zmiana kolekcji po stronie OneToMany nie podnosi wersji drugi raz; testy potwierdzają wersję 3 po `ship`.
- **Migracja:** CHECK-i są zgodne ze spec lub od niej ostrzejsze.
  - `paid_at ⇔ (paid…returned OR staff)`: ostrzej, ale domena nigdy nie tworzy innego stanu.
  - Pozostałe zgodnie: `shipped_at ⇔`, `delivered_at ⇒`, `returned_at ⇔`, refund `amount ⇔ due ⇔ (returned OR staff)`, wykonanie zwrotu razem i tylko przy zwrocie, notatka tylko przy wykonaniu.
  - Uzupełnienie historii daje pozycję 0 `customer` oraz pozycję 1 `paid` lub `cancelled` `system`. Stara reguła wykluczała jednoczesne `paid_at` i `cancelled_at`, więc pozycje się nie zderzają.
  - `down` usuwa historię i kolumny i przywraca starą regułę. Komentarz uczciwie mówi, że przy danych z nowymi statusami się nie powiedzie.
- **Płatności:** `statusForPayment` mapuje się przez `match` (shipped, delivered, returned → `paid`), a `QueryBusPayableOrders` czyta tylko to pole. `markPaid` przez `isPaidFor()` daje `alreadyPaid` lub `paidByAnotherPayment`, anulowane przez pracownika daje `orderCancelled`, `expireUnpaid` poza `new` daje `notApplicable`.
- **E-maile:** routing idzie przez interfejs `DomainEvent → outbox → events`. Nowe handlery mają `fromTransport: 'events'` i rodzaje `shipped` oraz `return_received` z lease z ORDER-MANAGEMENT-1. `SendOrderCancelled` wybiera treść po `cancellationReason` w jednym rodzaju `cancelled`. Treści są zgodne ze spec (P5, P14) i nie zawierają notatki ani terminu.
- **Dokumentacja:** `vision.md`, `apps/api/CLAUDE.md` (cykl, blokady, historia, refund, `commit`), `payment/spec.md` (A6, statusy, `statusForPayment`) i `checkout/spec.md` są zaktualizowane.

### Ustalenia

1. **Minor, poza zakresem tego taska.** Kontrakt OpenAPI odpowiedzi złożenia ma nadal `status` z enum `new|paid|cancelled`.
   - Źródło: `apps/api/config/packages/nelmio_api_doc.yaml:396`, wyeksportowane do `apps/api/openapi.json` (ok. linii 2797).
   - Powtórzenie złożenia (200 z bieżącym stanem) może teraz zwrócić `shipped`, `delivered` albo `returned`.
   - `docs/features/checkout/spec.md` (ok. linii 329) już podaje pełny enum, więc dokumentacja wyprzedza kontrakt.
   - Spec przypisuje to do „API › Sklep” („`OrderView` ma `status` z pełnym enum”), czyli do ORDER-MANAGEMENT-5. Jego kryteria tego wprost nie wymieniają, więc łatwo to pominąć.
   - Poprawka: dopisać do ORDER-MANAGEMENT-5 (albo `/integration`) aktualizację enum w nelmio i `openapi.json` oraz wygenerowanie klienta sklepu.
2. **Minor, luka w dowodach TDD** (opis w kontroli 3).
3. **Minor, test.** Ochronę przed naruszeniem PK historii przy przegranym wyścigu pokrywa tylko niedeterministyczny `WebhookConcurrencyTest` W3.
   - Na poziomie repozytorium da się to sprawdzić deterministycznie. W `DoctrineOrderRepositoryTest`:
     1. `get()` zamówienia;
     2. z drugiego, zatwierdzającego połączenia `UPDATE … version = version + 1` i `INSERT` wpisu historii na pozycji N;
     3. na odczytanym agregacie np. `markPaid` i `save()`;
     4. oczekiwane `OrderModifiedConcurrently`, nie `UniqueConstraintViolationException`.
   - Bez poprawki ten test padłby na PK, więc daje prawdziwy RED.
4. **Sugestia.** `apps/api/src/Ordering/Application/Command/ChangeOrderStatusHandler.php:51-66` używa `switch` po `StaffTransition` bez `default`. Nowy przypadek enum przeszedłby bez zmiany i zapisał zamówienie bez skutku. `match` z wyrażeniem wymusza wyczerpanie przypadków przez PHP i PHPStan.
5. **Sugestia, testy.** Trzy luki w pokryciu:
   - Wycofanie całości przy błędzie Inventory jest pokryte tylko jednostkowo (`testAFailureOfInventoryIsThrownAndNothingIsSaved`). Brakuje testu integracyjnego, że po `ReservationStateConflict` zamówienie, historia i outbox zostają bez zmian. Kryterium tego nie wymaga.
   - `ExpireUnpaidOrderHandlerTest:68` sprawdza, że `lock` był wołany, ale nie że przed `release`. Wystarczy wpis `lock` w journalu `VersionedOrders`.
   - `testTheExpiryAndAChangeOfStaffOfAnOverdueOrderDoNotDeadlock` spełnia kryterium. Zmiana pracownika na `new` kończy się jednak `OrderTransitionNotAllowed` przed Inventory, więc test nie ćwiczy kolejności blokad Inventory. Zgodnie ze spec ta kolejność nie może tu dać cyklu, więc to tylko informacja.

### Pliki
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-2/apps/api/src/Ordering/Infrastructure/Persistence/Doctrine/DoctrineOrderRepository.php`
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-2/apps/api/src/Ordering/Application/Command/ChangeOrderStatusHandler.php`
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-2/apps/api/migrations/Version20261107140000AddOrderLifecycle.php`
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-2/apps/api/config/packages/nelmio_api_doc.yaml`
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-2/docs/features/checkout/spec.md`
- `<home>/Development/claude/shop-ORDER-MANAGEMENT-2/apps/api/tests/Unit/Ordering/Application/ExpireUnpaidOrderHandlerTest.php`
- `<home>/.local/state/ao-order-management/tasks/ORDER-MANAGEMENT-2/delivery-state.json.evidence.jsonl`