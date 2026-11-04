# CART-FIXES – plan zadań

Specyfikacja: `docs/features/cart-fixes/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | CART-FIXES-1, CART-FIXES-2, CART-FIXES-3, CART-FIXES-4, CART-FIXES-5 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "CART-FIXES",
  "spec": "docs/features/cart-fixes/spec.md",
  "tasks": [
    {
      "id": "CART-FIXES-1",
      "title": "Serialize cart writes across browser tabs with Web Locks (#80)",
      "scope": "apps/store: src/api/client.ts (queueWrite wraps each write in navigator.locks.request when available, per-tab queue stays as the only path without it), src/api/client.test.ts; e2e: test of two tabs adding at once without store_cart; docs: docs/features/cart/spec.md (BFF queue, scenario of parallel adds, E2E, risks incl. the read race with a stale store_cart) and apps/store/CLAUDE.md (section Koszyk). Spec section „#80” and the user decision for #80. Not included: BFF (src/cart/bff.ts), API, locking of reads.",
      "acceptance_criteria": [
        "Vitest with navigator.locks set explicitly by vi.stubGlobal: with a double, every write runs inside request with one lock name and reads do not; a failed write does not hold back the next one; a rejected request gives failed without calling fetch; without navigator.locks writes go through the per-tab queue and the existing queue tests pass in that variant",
        "Write functions never reject their promise",
        "E2E: two tabs of one browser context without store_cart click „Do koszyka” on two different products at once; after a reload the badge shows 2 and /cart has both products",
        "docs/features/cart/spec.md and apps/store/CLAUDE.md describe the cross-tab lock, that the guarantee holds only with Web Locks API in a secure context, the fallback with today's limitation, and the read race with a stale store_cart",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "CART-FIXES-2",
      "title": "Say a line is gone only when the reloaded cart lacks it (#81)",
      "scope": "apps/store: src/cart/CartPage.tsx (not_found branch of change()), src/cart/CartPage.test.tsx; docs: docs/features/cart/spec.md (UI table, row of a 404 of a line). Spec section „#81”. Not included: BFF, API, disabling the quantity field.",
      "acceptance_criteria": [
        "Line unavailable_product, quantity 1 → 2, not_found with detail, reloaded cart still has the line: the detail is shown at the line, no „Pozycja została już usunięta”, the quantity field shows 1 (also when the reloaded quantity is unchanged), „Usuń” is there",
        "Line still in the cart and not_found without detail: „Nie udało się zapisać zmian. Spróbuj ponownie.” at the line",
        "not_found and the reload answers unauthorized: the session-expired message; not_found and the reload fails otherwise: the save-failed message at the line, quantity of the last view",
        "The existing test „reloads the cart and says the line is gone on a 404” passes unchanged",
        "The quantity field of an unavailable_product line stays enabled",
        "docs/features/cart/spec.md UI table describes these cases",
        "make -C apps/store check and make -C apps/store test pass"
      ],
      "risk": "FAST",
      "depends_on": []
    },
    {
      "id": "CART-FIXES-3",
      "title": "Polish validation messages of the cart requests (#82)",
      "scope": "apps/api: src/Cart/UI/Http/AddCartItemRequest.php, ChangeCartItemQuantityRequest.php, MergeGuestCartRequest.php (messages, Assert\\Sequentially, docblocks), GuestCartController.php and CustomerCartController.php (REQUIRE_ALL_PROPERTIES removed from the five Cart MapRequestPayload mappings), tests/Functional/Cart; docs: docs/features/cart/spec.md (section API, 422). Spec section „#82”. Not included: Inventory and Pricing requests, translator component, locale.",
      "acceptance_criteria": [
        "Functional tests for guest and customer cover every case of the message table in spec section „#82” for each of the three requests, checking violations[*].title and propertyPath; no message is in English",
        "One field gives at most one violation",
        "Other cart tests pass without changed expected values",
        "make -C apps/api openapi leaves apps/api/openapi.json unchanged (request fields still required, no nullable or default)",
        "docs/features/cart/spec.md section API lists the violations messages",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "CART-FIXES-4",
      "title": "One implementation of the purchasable rule in ProductOffer (#84)",
      "scope": "apps/api: src/Cart/Domain/ProductOffer.php (purchasability), new enum src/Cart/Domain/Purchasability.php, src/Cart/Domain/Cart.php (assertPurchasable), src/Cart/Application/View/CartViewBuilder.php (states from ProductData::offerFor, docblock), tests/Unit/Cart; docs: docs/features/cart/spec.md (Value objecty, Widok koszyka). Spec section „#84” and the user decision for #84. Not included: behaviour, HTTP codes, Deptrac rules.",
      "acceptance_criteria": [
        "Unit tests of ProductOffer::purchasability written first (RED, then GREEN): no price before available = 0, bounds quantity = available and available + 1",
        "Existing Cart and CartViewBuilder tests pass without changed expected values",
        "No other place in Cart compares quantity with availability or checks a missing price for a line state or a purchase error",
        "docs/features/cart/spec.md and the CartViewBuilder docblock name ProductOffer as the only place of the purchase rule",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "CART-FIXES-5",
      "title": "Document why the cart_line cart_id index stays (#85)",
      "scope": "docs/features/cart/spec.md (Persystencja i transakcje, cart_line) and the comment at #[ORM\\Index] in apps/api/src/Cart/Infrastructure/Persistence/Doctrine/Record/CartLineRecord.php. Spec section „#85” and the user decision for #85. Not included: migrations, schema or mapping changes.",
      "acceptance_criteria": [
        "docs/features/cart/spec.md explains that Doctrine DBAL needs its own index on the foreign key column cart_id and the unique (cart_id, product_id) index does not count",
        "The comment in CartLineRecord states the same reason",
        "No migration; the schema is unchanged",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "FAST",
      "depends_on": []
    }
  ]
}
```
