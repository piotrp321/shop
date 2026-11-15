**Werdykt: passed.** Brak zgłoszeń Critical i Important, są 2 zgłoszenia Minor.

SHA: `bbabb283efaf42ff19cafd1127da44fd0d4ef490`, sprawdzony przez `git rev-parse HEAD`, drzewo czyste, checkout nie zmienił się w trakcie review. Tryb: FULL, diff `63b6a57..bbabb28` (3 commity, 11 plików). Celowanych testów PHPUnit nie uruchamiałem, bo `make test` wykonuje `init-test`, a ten od nowa tworzy indeks `test_products`. Kolidowałoby to z trwającym pełnym przebiegiem orkiestratora. Zachowanie sprawdziłem tylko do odczytu: `curl` na działający stos (nieznany koszyk, więc 422 albo 404 bez zapisu w bazie) oraz porównanie deserializacji z `require_all_properties` i bez niego, samodzielnym `Serializer` w kontenerze `api`.

## Trzy kontrole

1. **Istniejące testy: bez uwag.** W `apps/api/tests` diff tylko dodaje linie. Żadna istniejąca asercja, wartość oczekiwana, `skip` ani konfiguracja się nie zmieniła. Dotychczasowe `assertViolation(...)` sprawdzają tylko `propertyPath`, więc zmiana komunikatów ich nie narusza. W testach, sklepie i e2e nie ma angielskich komunikatów („This value…”).
2. **Zakres: bez uwag.** Zmienione pliki to 3 DTO, 2 kontrolery (usunięte tylko `serializationContext` i nieużywany import `AbstractNormalizer`), `tests/Functional/Cart` i `docs/features/cart/spec.md`. Mapowania Inventory (`ReceiveStockController`, `CorrectStockController`) i Pricing (`ChangePriceController`) nadal mają `REQUIRE_ALL_PROPERTIES`. Tłumaczeń i locale nie ruszano.
3. **RED przed implementacją: bez uwag.**
   - RED (wpis 0) był na `head` 63b6a57, czyli na bazie. W `dirty` są tylko pliki testów, bez plików produkcyjnych. `output_tail` wymienia testy po nazwie, np. `GuestCartEndpointTest::testAnInvalidQuantityChangeGivesPolishViolations@quantity an array`. Wynik: 72 testy, 57 niepowodzeń asercji z angielskimi komunikatami i drugim naruszeniem.
   - Pierwsza próba GREEN (wpis 2) nie przeszła: wynik identyczny jak RED, prawdopodobnie przez cache.
   - GREEN (wpis 3): 69 testów OK. Wcześniej z zestawu usunięto 3 przypadki `""` (patrz Minor 2).
   - 15 przypadków przechodziło już w RED (np. ilość spoza 1–99, napis nie będący UUID). Służą jako zabezpieczenie przed regresją, to akceptowalne.
   - `tdd.status: verified` jest zgodny z dziennikiem.

## Zgodność z wymaganiami (sprawdzone)

- **Tabela komunikatów ze specyfikacji #82.** Wszystkie 9 komunikatów w DTO zgadza się co do znaku (`AddCartItemRequest.php`, `ChangeCartItemQuantityRequest.php`, `MergeGuestCartRequest.php`).
- **Kolejność ograniczeń.** `Assert\Sequentially` sprawdza NotNull, potem Type, potem Uuid albo Range. Uuid i Range nigdy nie dostają innego typu niż oczekują, więc nie ma `UnexpectedValueException`.
- **Jedno naruszenie na pole.** `assertViolations` (`CartEndpointTestTrait.php:112`) porównuje przez `assertSame` całą listę par `[propertyPath, title]` w kolejności. Wyklucza to zarówno drugie naruszenie, jak i komunikat angielski. Przypadki „empty object” i „both fields wrong” sprawdzają po jednym naruszeniu na każde z dwóch pól.
- **Pokrycie gościa i klienta.** Dodanie i zmiana ilości są testowane dla obu, scalenie dla klienta. Testy sprawdzają też, że stan się nie zmienia: brak koszyka klienta, linie bez zmian, koszyk gościa nadal `open`.
- **Skutki usunięcia `REQUIRE_ALL_PROPERTIES`.**
  - Ciało nie będące obiektem (`[1,2]`, `[]`, `5`, `"x"`, `null`, `true`, `[{...}]`, `{"0":1}`) dawało 422 przed zmianą (zebrane `MissingConstructorArguments`) i daje 422 teraz, z polskimi „…jest wymagany/wymagana” dla obu pól. Potwierdzone przez `curl` i porównanie serializera.
  - Nadmiarowe klucze są ignorowane tak samo jak przed zmianą.
  - Niepoprawny JSON i inny typ treści nadal są obsługiwane przez resolver (400 i 415).
  - Nigdzie nie ma 500.
- **Założenia kontrolera po walidacji.** `\assert(\is_string(...) && \is_int(...))` pozostaje bezpieczne: każde naruszenie kończy się 422 przed wywołaniem kontrolera, a `Sequentially` gwarantuje `string` i `int`.
- **Kontrakt OpenAPI.** Atrybuty `OA\Schema(required: …)` się nie zmieniły. Brak różnicy w eksporcie potwierdził orkiestrator.
- **Dokumentacja.** `docs/features/cart/spec.md` (sekcja API i opis testów) opisuje stan obecny: tabela komunikatów, mechanizm z `null` i `NotNull`, pola nadal `required`. Docblocki trzech DTO też opisują nowe działanie.
- **Złożoność.** Bez zbędnej złożoności: trait z danymi testowymi i jeden helper asercji, w kodzie produkcyjnym tylko atrybuty.

## Zgłoszenia

**Minor 1: opis pustego napisu w tabeli komunikatów jest nieprecyzyjny.**
- Gdzie: `docs/features/cart/spec.md:309` i `:314`, wiersze „`productId` / `guestCartId` | nie jest napisem albo nie jest UUID | … musi być UUID.”
- Problem: pusty napis `""` nie jest UUID, ale `Assert\Uuid` go przepuszcza (walidator pomija `null` i `''`). Odpowiedź to więc nie 422, tylko:
  - dodanie: 404 `ProductNotFound` (`AddCartItemHandler.php:40-44` zamienia `InvalidArgumentException` na `ProductNotFound`);
  - scalenie: 200 `nothing_to_merge` (`MergeGuestCartHandler::lockGuest`).
- Dowód: `curl -X POST …/guest-carts/<nieznany>/items -d '{"productId":"","quantity":1}'` daje 404 „Koszyk nie jest już dostępny.” zamiast 422.
- Ocena zakresu: pominięcie `""` w implementacji jest uzasadnione. Specyfikacja #82 wymaga „Kody odpowiedzi … bez zmian”, a wiersz „nie jest UUID” ma dopisek „(bez zmian)”, czyli dotyczy przypadków, które już dziś dają ten komunikat. Zamiana `""` na 422 zmieniłaby kod odpowiedzi. Nie ma 500, bo oba handlery obsługują zły identyfikator.
- Propozycja: tabela napisana w tym tasku powinna opisywać stan obecny. Wystarczy dopisać, że pusty napis przechodzi walidację i daje 404 (dodanie) albo `nothing_to_merge` (scalenie). Ewentualna zmiana na 422 to osobne zgłoszenie.

**Minor 2: usunięcie przypadków RED nie jest ujawnione w commicie.**
- Gdzie: commit caab46f.
- Problem: commit `test(api)` 6e48b02 zawierał przypadki `productId empty` i `guestCartId empty` (3 testy, oczekiwany komunikat „musi być UUID”). Commit `fix(api)` caab46f usuwa je z `CartRequestViolationCases.php`, a jego opis tego nie wspomina. To nowe testy, nie testy z bazy, więc nie jest to osłabienie istniejących testów, a decyzja ma uzasadnienie (Minor 1).
- Propozycja: napisać o tym wprost w raporcie i opisie PR (72 testy w RED, 69 w GREEN, powód).

## Pliki

- <home>/Development/claude/shop-CART-FIXES-3/apps/api/src/Cart/UI/Http/AddCartItemRequest.php
- <home>/Development/claude/shop-CART-FIXES-3/apps/api/src/Cart/UI/Http/ChangeCartItemQuantityRequest.php
- <home>/Development/claude/shop-CART-FIXES-3/apps/api/src/Cart/UI/Http/MergeGuestCartRequest.php
- <home>/Development/claude/shop-CART-FIXES-3/apps/api/src/Cart/UI/Http/GuestCartController.php
- <home>/Development/claude/shop-CART-FIXES-3/apps/api/src/Cart/UI/Http/CustomerCartController.php
- <home>/Development/claude/shop-CART-FIXES-3/apps/api/tests/Functional/Cart/CartRequestViolationCases.php
- <home>/Development/claude/shop-CART-FIXES-3/apps/api/tests/Functional/Cart/CartEndpointTestTrait.php
- <home>/Development/claude/shop-CART-FIXES-3/docs/features/cart/spec.md
- <home>/.local/state/ao-cart-fixes/tasks/CART-FIXES-3/delivery-state.json.evidence.jsonl