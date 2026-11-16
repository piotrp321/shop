Zrecenzuj (FULL) task CART-FIXES-3 „Polish validation messages of the cart requests (#82)” w checkoucie `{{CHECKOUT}}`, na zamrożonym HEAD `bbabb283efaf42ff19cafd1127da44fd0d4ef490`. Diff taska: `git diff 63b6a5761efcd207fe588a7db1fa4273ae873002..bbabb28` (merge-base z `origin/epic/cart-fixes`). Nie modyfikuj plików.

Specyfikacja: sekcja „#82” w `docs/features/cart-fixes/spec.md`; zadanie CART-FIXES-3 (scope i acceptance_criteria) w `docs/features/cart-fixes/plan.md`. Zasady: `CLAUDE.md`, `apps/api/CLAUDE.md`.

Scope: Cart request DTO (`AddCartItemRequest`, `ChangeCartItemQuantityRequest`, `MergeGuestCartRequest`: komunikaty, `Assert\Sequentially`, docblocki), usunięcie `REQUIRE_ALL_PROPERTIES` z pięciu mapowań w `GuestCartController`/`CustomerCartController`, testy `apps/api/tests/Functional/Cart`, `docs/features/cart/spec.md` (sekcja API, 422). Poza: Inventory, Pricing, tłumaczenia, locale.

tdd.status: verified. Dziennik dowodów: `{{INPUT_DIR}}/tdd-evidence.jsonl` (RED na 63b6a57 z czystym `src/`, 57 niepowodzeń asercji; GREEN po implementacji). Orkiestrator już uruchomił: `make -C apps/api check` = 0, `make -C apps/api openapi` + `git diff --exit-code apps/api/openapi*.json` = bez różnic; pełny `make -C apps/api test` trwa (developer zgłosił OK 1933 testów). Nie uruchamiaj ponownie pełnego zestawu; celowane testy możesz (`make -C apps/api test PHPUNIT_ARGS='--filter ...'`, stos Dockera działa; po zmianie atrybutów może być potrzebne `docker compose exec -T api bin/console cache:clear --env=test`).

Sprawdź w szczególności: zgodność z tabelą komunikatów i kryteriami; czy usunięcie `REQUIRE_ALL_PROPERTIES` nie zmienia innych zachowań (np. dodatkowe/nieznane klucze, ciało nie będące obiektem JSON — tablica, skalar — wynik 400/422 tak jak przed zmianą, brak 500); czy kontroler nadal bezpiecznie zakłada typy po walidacji; czy testy faktycznie wykluczają drugie naruszenie i komunikaty angielskie; czy istniejące oczekiwania testów nie zostały zmienione; czy dokumentacja opisuje stan obecny; zbędna złożoność. Developer świadomie pominął przypadek pustego napisu `""` w polach UUID (Symfony `Uuid` go przepuszcza, dziś daje 404/`nothing_to_merge`, specyfikacja go nie obejmuje) — oceń, czy to rzeczywiście poza zakresem.

Wynik: lista zgłoszeń z ważnością (Critical/Important/Minor), plik:linia, dowód; albo „brak zgłoszeń”. Werdykt: passed / findings.

Uwaga do tego przebiegu (dopisana przez ewaluację, nie przez orkiestratora): stos Dockera nie działa i nie ma sieci. Nie uruchamiaj testów, `make` ani `docker`; pracuj na odczycie kodu i dziennika dowodów.
