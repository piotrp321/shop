## Code review CART-1: wynik

- **SHA:** 43467b599869788598a74accf457bf33d207708c. Potwierdzone `git rev-parse HEAD` na początku i na końcu review, worktree czysty.
- **Tryb:** FULL, diff `8c51468d...43467b59` (102 pliki).
- **Werdykt:** passed. Nie ma zgłoszeń Critical ani Important. Są 4 zgłoszenia Minor.
- **Co uruchomiłem:** tylko `make -C apps/api test-unit PHPUNIT_ARGS='tests/Unit/Cart'`, wynik OK (123 testy, 311 asercji).
- **Czego nie uruchamiałem:** testów integracyjnych, funkcjonalnych, K3/K4/K7, Deptraca i `check`. Ich wynik jest w Twoim równoległym `make test`.

### Blokady (zakres CRITICAL)

**Odstępstwo od spec jest poprawne i realizuje jej intencję.** Sprawdziłem kod Doctrine w kontenerze:
- `UnitOfWork::doRefresh` (vendor/doctrine/orm/src/UnitOfWork.php:2133-2139) najpierw wywołuje `cascadeRefresh`, a dopiero potem `persister->refresh` rodzica.
- Kaskada robi `unwrap()` starej kolekcji (linie 2161-2169), więc odświeża i blokuje stare encje linii przed wierszem koszyka. To odwraca kolejność blokad względem innych transakcji i grozi zakleszczeniem. Linii dodanych przez inne transakcje kaskada w ogóle nie widzi.
- `createEntity` przy refresh wstrzykuje nową, niezainicjalizowaną `PersistentCollection` i aktualizuje `originalEntityData` (linie 2784-2812). Leniwe wczytanie daje więc aktualny zestaw linii.
- Jawny `refresh($line)` po blokadzie jest potrzebny, bo hydracja bez `HINT_REFRESH` nie nadpisuje encji, które już są w identity map.
- `SELECT 1 … FOR UPDATE` przed refresh jest uzasadniony. `BasicEntityPersister::refresh` przy zerowej liczbie wierszy zostawia nieaktualny rekord, a ścieżka usuniętego wiersza zwraca null (pokrywa ją test).
- `CartRecord.lines` ma tylko `cascade: ['persist']`, więc kaskady refresh faktycznie nie ma.

**Stan jest czytany po blokadzie.**
- Gdy rekordu nie ma w identity map, `find(..., PESSIMISTIC_WRITE)` blokuje, a linie wczytują się leniwie później, w nowym snapshocie READ COMMITTED.
- Gdy rekord jest w identity map, kolejność to: blokada, refresh, linie.
- Test `testALockOfACartAlreadyInTheIdentityMapReadsItAsCommittedByOthers` sprawdza ilość, usunięcie i dodanie linii, `expires_at` oraz `NOWAIT` (55P03) z drugiego połączenia.

**`lockOrCreateForCustomer` jest poprawny.**
- `INSERT … ON CONFLICT DO NOTHING` czeka tylko na niezatwierdzony insert tego samego `customer_id`. Blokada `FOR UPDATE` innej transakcji (xmax tylko jako blokada) nie wstrzymuje DO NOTHING.
- Następny `SELECT id` w READ COMMITTED widzi zatwierdzony wiersz.
- Błąd po insercie (np. `CartLineNotFound` przy PUT klienta bez koszyka) wycofuje założenie koszyka razem z transakcją `command.bus`.

**Zakleszczenia: brak ryzyka w CART-1.**
- Każda komenda blokuje jeden wiersz `cart_cart`.
- `FOR KEY SHARE` z klucza obcego `cart_line` dotyczy wiersza, który transakcja już trzyma.
- Porty (Catalog, Pricing, Inventory) tylko czytają w tej samej transakcji.

**Wygasły koszyk klienta i ponowne dodanie tego samego produktu są obsłużone.**
- `write()` dopasowuje linie po produkcie, więc linia jest aktualizowana w miejscu i nie trafia na unikalny indeks `(cart_id, product_id)`. Pokrywa to test `testSavingUpdatesLinesInPlace…AfterAClear`.
- Kolejność linii (`added_at`, a przy remisie `id` UUIDv7) zostaje zachowana. W pierwszej zmianie po wygaśnięciu ponownie używany jest najwyżej jeden rekord linii, a kolejne linie mają nowsze `id`. Zaokrąglanie `TIMESTAMP(0)` nie psuje kolejności, bo jest monotoniczne.

**K3, K4 i K7 faktycznie wykrywają błędy.**
- Bez blokady K3 dostałby utracone aktualizacje albo 23505 przy wspólnym produkcie.
- Bez `ON CONFLICT` K4 dostałby 23505.
- Bez blokady K7 miałby więcej niż 2 sukcesy.
- Każdy uczestnik to osobny proces z własnym jądrem (`ConcurrentCommands`, `pcntl_fork`, bariera na advisory locku), 5 rund, sprawdzany niezmiennik.

### Pozostałe obszary: zgodne

- **Domena:** kolejność błędów w `add`, zmniejszenie ilości zawsze dozwolone, `remove` idempotentne, `merged` daje 404, wygasły gość daje `CartNotFound`, `clearIfExpired` i `touch` na 30/180 dni. Komunikaty `detail` są dosłownie jak w spec, `InsufficientStock` nie podaje liczby sztuk.
- **`CartViewBuilder`:** priorytet stanów, `lineTotal` i `gross` tylko dla `purchasable`, `itemCount` ze wszystkich linii, `hasExcludedLines`. W odpowiedzi nie ma `available`.
- **Bezpieczeństwo:**
  - `LockedCarts` i `ReadableCarts` odrzucają id koszyka klienta podane jako koszyk gościa (404 bez zmian, sprawdza to test funkcjonalny).
  - `/me/cart…` bez tokena, z tokenem backoffice i z tokenem `abc` daje 401 (test na 5 operacjach).
  - Nagłówek `private, no-store` ustawia subskrybent `kernel.response` (-200) także dla 401 firewalla i 404 bez trasy. `assertProblem` sprawdza go na błędach.
- **Migracja:** zgodna ze spec (CHECK, częściowy unikalny indeks `customer_id`, indeks `expires_at`, FK z `CASCADE`, CHECK 1–99). Test odrzucenia bezpośredniego SQL sprawdza kody 23514, 23505 i 23503.
- **Deptrac:** warstwy i reguły zgodne z tabelą spec, z komentarzami.
- **`GetProductSummaries`:** jedno zapytanie `id = ANY(uuid[])`, walidacja 1–100 id, rozmiar `listing` przez wydzielony `ListingImage`, bez nowego rozmiaru. `ListProductsHandler` bez zmiany zachowania.
- **TDD:** w evidence są prawdziwe fazy RED (exit 2, „Class … not found”, w `dirty` są tylko testy) dla domeny, `GetProductSummaries` i aplikacji, a potem GREEN. Czasy modyfikacji plików `src` (np. domena 22:11:30, `Cart.php` 22:11:59) są późniejsze niż RED (22:11:03), więc RED nie był odtwarzany po implementacji.

### Zgłoszenia Minor

1. **Spec opisuje inny mechanizm niż kod.**
   - `docs/features/cart/spec.md:158` mówi „`refresh(record, PESSIMISTIC_WRITE)` (z kaskadą `refresh` na linie)”.
   - Kod (`DoctrineCartRepository.php:150-174`) i `apps/api/CLAUDE.md` robią `SELECT 1 FOR UPDATE`, refresh rekordu, a potem refresh linii bez kaskady.
   - Wpływ: implementer CART-2 (scalanie, dwie blokady) czytający spec dosłownie mógłby dodać `cascade: ['refresh']`. To odwraca kolejność blokad (patrz UnitOfWork.php:2133).
   - Zmiana spec wymaga decyzji orkiestratora i użytkownika (Ruling), dlatego nie traktuję tego jako błędu kodu.
2. **Wyścig z przyszłym `cart:purge-expired` (dotyczy CART-3).**
   - `DoctrineCartRepository.php:83`: jeśli między `INSERT … DO NOTHING` (konflikt z istniejącym, wygasłym koszykiem) a `SELECT id` lub `FOR UPDATE` czyszczenie usunie ten koszyk, `lockForCustomer` zwróci null. Wtedy leci `LogicException`, czyli 500 zamiast założenia nowego koszyka.
   - W CART-1 nic nie usuwa koszyków, więc problem nie da się odtworzyć. Warto przekazać do CART-3: jedno ponowienie insertu albo `DELETE … WHERE expires_at < now()` sprawdzane na poziomie wiersza.
3. **Brak dowodu prób obejścia Deptraca w evidence.**
   - Kryterium akceptacji wymaga, żeby Deptrac odrzucał próby obejścia ze spec („Testy – Architektura”). W `delivery-state.json.evidence.jsonl` nie ma ich uruchomień, a ja w trybie read-only nie mogłem tworzyć plików tymczasowych.
   - Trzeba to pokazać w raporcie z wynikami albo uruchomić przed PR.
4. **Drobne luki w testach funkcjonalnych (logikę pokrywają testy jednostkowe).**
   - Brak testu HTTP 404 `ProductNotFound` przy zwiększeniu ilości (PUT) produktu nieznanego Catalogowi. W `CartHandlersTest` jest `testIncreaseOfALineWhoseProductCatalogForgotIsNotFound`.
   - Dla `/me/cart` 415 jest sprawdzane tylko na PUT, a 400 tylko na POST.

**Uwaga bez wpływu na wynik:** `refresh($managed, LockMode::PESSIMISTIC_WRITE)` po `SELECT 1 … FOR UPDATE` blokuje ten sam wiersz drugi raz. Jest to nieszkodliwe.

### Kluczowe pliki
- <home>/Development/claude/shop-CART-1/apps/api/src/Cart/Infrastructure/Persistence/Doctrine/DoctrineCartRepository.php
- <home>/Development/claude/shop-CART-1/apps/api/src/Cart/Application/LockedCarts.php
- <home>/Development/claude/shop-CART-1/apps/api/src/Cart/Domain/Cart.php
- <home>/Development/claude/shop-CART-1/apps/api/migrations/Version20261103120000CreateCartTables.php
- <home>/Development/claude/shop-CART-1/apps/api/src/Cart/UI/Http/CartResponseCacheSubscriber.php
- <home>/Development/claude/shop-CART-1/apps/api/tests/Integration/Cart/Concurrency/CartConcurrencyTest.php
- <home>/Development/claude/shop-CART-1/apps/api/tests/Integration/Cart/Persistence/DoctrineCartRepositoryTest.php
- <home>/Development/claude/shop-CART-1/docs/features/cart/spec.md (linia 158)