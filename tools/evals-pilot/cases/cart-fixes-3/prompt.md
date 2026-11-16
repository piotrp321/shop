Review taska CART-FIXES-3 (Polish validation messages of the cart requests (#82)), tryb FULL, pierwsze review.

- base_sha: {{BASE}}
- head_sha: {{HEAD}}
- Tryb: FULL
- Specyfikacja: `docs/features/cart-fixes/spec.md` (w checkoucie; przeczytaj też `CLAUDE.md` i `apps/api/CLAUDE.md`).
- Kryteria akceptacji:
  1. Functional tests for guest and customer cover every case of the message table in spec section „#82” for each of the three requests, checking violations[*].title and propertyPath; no message is in English
  2. One field gives at most one violation
  3. Other cart tests pass without changed expected values
  4. make -C apps/api openapi leaves apps/api/openapi.json unchanged (request fields still required, no nullable or default)
  5. docs/features/cart/spec.md section API lists the violations messages
  6. make -C apps/api check and make -C apps/api test pass
- `scope` taska: apps/api: src/Cart/UI/Http/AddCartItemRequest.php, ChangeCartItemQuantityRequest.php, MergeGuestCartRequest.php (messages, Assert\Sequentially, docblocks), GuestCartController.php and CustomerCartController.php (REQUIRE_ALL_PROPERTIES removed from the five Cart MapRequestPayload mappings), tests/Functional/Cart; docs: docs/features/cart/spec.md (section API, 422). Spec section „#82”. Not included: Inventory and Pricing requests, translator component, locale.
- tdd.status: verified
- Dziennik dowodów TDD: `{{INPUT_DIR}}/tdd-evidence.jsonl`. Wpisy `head` i `dirty` dziennika odnoszą się do commitów gałęzi taska; w tym checkoucie zadanie jest jednym commitem `head_sha` na `base_sha`, więc RED sprawdzasz po nazwach testów w `output_tail` i po plikach w `dirty`.

Sprawdź diff taska (`git diff {{BASE}} {{HEAD}}`). Nie zmieniaj plików.
