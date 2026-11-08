# DISCOUNT-CODES – plan zadań

Specyfikacja: `docs/features/discount-codes/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | DISCOUNT-CODES-1 | |
| 2 | DISCOUNT-CODES-2 | |
| 3 | DISCOUNT-CODES-3 | |
| 4 | DISCOUNT-CODES-4, DISCOUNT-CODES-5 | |
| 5 | DISCOUNT-CODES-6 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "DISCOUNT-CODES",
  "spec": "docs/features/discount-codes/spec.md",
  "tasks": [
    {
      "id": "DISCOUNT-CODES-1",
      "title": "Discount code aggregate, usages and the admin discount codes API",
      "scope": "apps/api: Pricing Domain (DiscountCodeValue, DiscountValue percent|amount with PercentOff from PROMOTIONS, MinimumOrder, ValidityPeriod with optional end, UsageLimit, DiscountCode with create/revise/rejectionFor and the Q3 edit rules incl. a limit added only to a planned code and a new end later than now, DiscountCodeRejection {reason, minimumOrderGross} with the effective minimum max(minimum, ceil(100/p)) of Q7, the reason order invalid → used_up → below_minimum and the problem type and Polish detail of each reason as the one place of these texts, DiscountCodeUsage as its own aggregate with a repository, DiscountCodeTaken, DiscountCodeNotEditable, DiscountCodeModifiedConcurrently), Application (CreateDiscountCode, ReviseDiscountCode under PESSIMISTIC_WRITE with active uses read under the lock, ListDiscountCodes keyset by (created_at DESC, id) with status and q filters, GetDiscountCode, both with activeUses), Infrastructure (records and migration of pricing_discount_code and pricing_discount_code_usage with the CHECK constraints, the unique code and order_id and the partial index on active uses; DiscountCodeVoter: admin and marketing write, every staff member reads), UI (GET/POST /api/v1/admin/discount-codes, GET/PUT /api/v1/admin/discount-codes/{id}; request bodies, check order 401/403/400/404/422/409 and 422 messages of spec section „API”); openapi-admin.json and the admin client. Spec sections „Model domenowy (Pricing)” (Kod rabatowy, Użycie kodu), „Warstwa aplikacji (Pricing)” (Odczyty: panel; Komendy: Create/Revise), „Persystencja” (Pricing tables), „API” (panel), decisions D1, Q3, Q4, Q7, A1. Not included: QuoteBasket, Redeem and Release commands, cart and order changes, the admin view, the seed.",
      "acceptance_criteria": [
        "Unit tests of the value objects: code format 3–32 [A-Z0-9-] case-insensitive, percent 1–90, amount 1–10 000 zł, amount code requires a minimum greater than its amount, minimum up to 1 000 000 zł, limit 1–1 000 000, startsAt < endsAt, open end",
        "Unit tests of DiscountCode: statuses disabled/planned/active/expired with disabled first; rejectionFor gives invalid, used_up and below_minimum in this order, below_minimum carries the effective minimum (percent below ceil(100/p) is below_minimum, equal gives a discount ≥ 1 grosz, a larger code minimum wins); revise of a planned code changes everything but the code text; after start only end, enabled and an existing limit change (limit not below active uses, removal allowed), other changes are DiscountCodeNotEditable; adding a limit to an active unlimited code is DiscountCodeNotEditable; a changed end not later than now is 422",
        "Integration tests (PostgreSQL): repositories of codes and usages, the unique code text (case-insensitive) mapped to 409 discount-code-taken, the unique order_id of a usage, active uses counted with the partial index",
        "Functional tests of every admin endpoint: 200/201, 400 bad status, q or cursor, 401 without a token and with a shop token, 403 for support and warehouse on writes, 404, 409 (version, discount-code-taken, discount-code-not-editable), 422 with the Polish messages of the spec; every staff member reads, admin and marketing write",
        "Deptrac has no new rules; bypass attempts give errors: Pricing_* → Ordering_*, Pricing_* → Cart_*",
        "openapi-admin.json and the admin client are regenerated; docs/features/pricing/spec.md describes discount codes, usages and the panel API",
        "make -C apps/api check, make -C apps/api test, make -C apps/admin check pass"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "DISCOUNT-CODES-2",
      "title": "Basket quote in Pricing and the cart view priced by it, with the code preview",
      "scope": "apps/api: Pricing Domain (BasketQuote::of: effective prices, subtotal, the discount of a percent or amount code, the largest-remainder allocation computed with ext-gmp with ties by cart position, line net and VAT from the line gross after the discount through the new PriceBreakdown::ofLineGross(Money, VatRate) with the formula of fromGross; GrossPrice unchanged), Application (QuoteBasket query: 1–50 lines, the effective prices read like GetCurrentPrices without Omnibus, code by normalised text, active uses only for a limited code, blank code = no code, malformed text = invalid, > 64 characters InvalidBasketQuery); Cart (port CartQuote with adapter QueryBusCartQuote, CartViewBuilder: purchasability from CartPrices as today, unitPrice, lineTotal = gross before discount and the totals of purchasable lines only from the quote, no quote and discount null without purchasable lines; GetCart with discountCode; CartTotals subtotalGross, discountGross, gross, currency, itemCount; CartView.discount applied {code, amountGross, kind, value} or rejected {code, reason, detail, minimumOrderGross}); UI: optional discountCode on GET /api/v1/guest-carts/{cartId} and GET /api/v1/me/cart (blank = none, > 64 characters 400); openapi.json and the store client; the store's typed cart consumers keep compiling (apps/store/src/cart, apps/store/app/api/cart) without new UI. Spec sections „Wycena koszyka i rozkład rabatu”, „Warstwa aplikacji (Pricing)” (QuoteBasket), „Cart”, „API” (sklep: cart rows), decisions D2, D8 (cart part), D9, Q2, Q7, A2. Not included: order placement, the checkout code field.",
      "acceptance_criteria": [
        "Unit tests of BasketQuote: without a code line net from the line gross (not unit net × quantity); percent and amount codes; allocation sums to the discount with remainders and the tie rule; a 1-grosz line; the maximum basket (50 lines × 99 × GrossPrice::MAX at 90%) computed exactly; a promoted line keeps its promotion copy fields; a rejected code gives the quote without discount and the rejection",
        "Unit tests of PriceBreakdown::ofLineGross: 0 grosze and the maximum line gross, net + VAT = gross, same result as fromGross within the GrossPrice range",
        "Unit tests of CartViewBuilder: lineTotal is the gross before discount, totals of purchasable lines only, excluded lines keep lineTotal null, no purchasable lines gives zero totals and discount null even with a code",
        "Functional tests: guest and customer cart with an applied code, each rejection reason with its detail and minimumOrderGross, blank code as none, a 65-character code is 400; cart responses without a code keep their values except the new totals fields",
        "openapi.json and the store client are regenerated; docs/features/cart/spec.md describes the quote, discountCode and the new totals",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check, make -C apps/store test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DISCOUNT-CODES-1"
      ]
    },
    {
      "id": "DISCOUNT-CODES-3",
      "title": "Orders with a discount code: placement, usage, release on expiry, email and order views",
      "scope": "apps/api: Pricing Application (RedeemDiscountCode: the code record read anew, PESSIMISTIC_WRITE only for a limited code, rejectionFor again, DiscountCodeRejected, the usage written through a Doctrine record; ReleaseDiscountCodeUsage(orderId): idempotent releasedAt, no code lock); Ordering Domain (OrderLine with discount, line net/vat/gross from the quote and PromotionCopy; Order with OrderDiscount and the invariant sum of line discounts = discount amount; reconstitute reads stored line values; DiscountCodeRejected with the three 409 types taking type and detail from Pricing's rejection; RequestFingerprint with the code appended only when not null), Application (PlaceOrder with discountCode: step 4 QuoteBasket through OrderPrices, rejection before the total check, step 5a redeem through the new port DiscountCodeRedemptions after the order id is assigned, lock order cart → code → stock; ExpireUnpaidOrder releases the usage after the reservation, also for orders without a code; staff cancel and return do not release; confirmation email lines with the gross before discount, the line „Kod <KOD>: −X zł” and the total after discount; OrderView, CustomerOrderView and BackofficeOrderView with line grossBeforeDiscount and discountGross, order discount, backoffice line promotion), Infrastructure (adapters, migration of ordering_order discount columns and ordering_order_line discount_gross default 0 and promotion columns with CHECKs, no recomputation of existing orders), UI (discountCode on POST /api/v1/orders and /api/v1/me/orders, 422 at discountCode, the three 409 in OrderConflictProblem); openapi.json, openapi-admin.json and both clients; the typed consumers keep compiling. Spec sections „Ordering”, „Zasady transakcji”, „Współbieżność…”, „Persystencja” (Ordering), „API”, decisions D4–D8, Q1, Q5 (API part), A2–A4. Not included: store and panel UI, the seed, E2E.",
      "acceptance_criteria": [
        "Unit tests of PlaceOrder: an applied code gives an order with the discount copy, the allocated line discounts and the usage; a code rejected in the quote and in redeem is 409 of its type with nothing placed; a replay with the same key returns the order without a second usage; the fingerprint without a code equals the fingerprint before this change",
        "Unit tests of Order and OrderLine: line gross = gross before discount − discount, net + VAT = gross, the sum of line discounts equals the discount amount, reconstitute keeps stored values",
        "Unit tests of ExpireUnpaidOrder: the usage is released after the reservation, an order without a code calls release without effect, a repeated expiry changes nothing; staff cancel and return do not release",
        "Unit tests of the confirmation email with and without a code (D5)",
        "Integration test: the migration keeps the amounts of existing orders and gives discount_gross 0 and null promotion and discount",
        "Concurrency tests (5 rounds): two placements for the last free usage of a limited code give one order and 409 discount-code-used-up; disabling an unlimited code in parallel with placements ends every placement with an order and a usage or 409 discount-code-invalid without order and usage (usages = orders with the code), without deadlock and without the edit waiting for placements; expiry in parallel with a placement using the same code without deadlock",
        "Functional tests: place with a code (201, each 409, 422 at discountCode, blank as none, replay), the payment amount is the total after discount, expiry releases the usage (activeUses drops), customer and backoffice order views show the discount and line values",
        "openapi.json, openapi-admin.json and both clients are regenerated; docs/features/checkout/spec.md (code in placement, steps 4–5a, new 409, line values from the quote, email; out of scope without discount codes) and docs/features/order-management/spec.md (expiry releases the usage, order details with discount and promotion) are updated; apps/api/CLAUDE.md describes the lock order cart → code → stock, RedeemDiscountCode with the ORM exception (A3), ReleaseDiscountCodeUsage and the new problem types",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check, make -C apps/admin check pass"
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "DISCOUNT-CODES-2"
      ]
    },
    {
      "id": "DISCOUNT-CODES-4",
      "title": "Store: discount code in the checkout and the discount in the customer's order",
      "scope": "apps/store: src/checkout (field „Kod rabatowy” with „Zastosuj” and „Usuń kod”, maxLength 64, the summary reloaded through the cart BFF with discountCode, the line „Kod <KOD>: −X zł” and „Razem” after discount, the rejection detail at the field, expectedTotalGross = totals.gross of the summary, discountCode in CUSTOMER_ORDER_FIELDS, the three 409 types in problems.ts, a new Idempotency-Key after applying or removing a code and after a 409 of a code, Q6: after a 409 of a code the code is removed and the summary reloaded without it, nothing is placed automatically; after 409 OrderTotalChanged the summary reloads with the same code; the code does not survive a reload), src/cart and app/api/cart (the BFF passes discountCode), src/account (order details: line values before discount, the code line, totals after discount). Spec section „Sklep (apps/store)”, decisions D3, D6, Q5, Q6. Not included: the cart page look (unchanged).",
      "acceptance_criteria": [
        "Vitest: applying a code shows the discount line and the total after discount; each of the three rejection messages at the field with the summary without discount; removing the code; a 400 of a too long code",
        "Vitest: placing an order sends discountCode and the discounted expectedTotalGross; applying or removing a code and a 409 of a code give a new Idempotency-Key; a 409 of a code removes the code, reloads the summary and places nothing until the next click; a 409 OrderTotalChanged reloads the summary with the same code",
        "Vitest: the BFF passes discountCode to the cart and the order and drops other fields",
        "Vitest: the customer's order details show line values before discount, the code line and totals after discount; an order without a code looks as before",
        "apps/store/CLAUDE.md describes the code in the checkout",
        "make -C apps/store check and make -C apps/store test pass"
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DISCOUNT-CODES-3"
      ]
    },
    {
      "id": "DISCOUNT-CODES-5",
      "title": "Panel: discount codes view and the discount in the order details",
      "scope": "apps/admin: menu „Kody rabatowe”, route /discount-codes, src/discount-codes and its views (list with status filter, code prefix search and cursors; columns code, discount, minimum, period in Europe/Warsaw, activeUses / limit, status; form with code only on create, kind and value, minimum, start, optional end, optional limit, enabled; fields locked by the Q3 edit rules; 409 discount-code-taken at the code field, other 409 detail and refresh); change buttons only for admin and marketing; src/orders and src/views/OrderDetailView.vue (line value before discount, the promotion copy at the line, the code line, totals after discount). Spec section „Panel (apps/admin)”, decisions D1, Q3, Q5. Not included: a list of orders per code.",
      "acceptance_criteria": [
        "Vitest: list (filter, search, cursors), form (create, revise with version, fields locked for a started code, a limit cannot be added to an active unlimited code, Europe/Warsaw times), 409 discount-code-taken at the field, 409 version refreshes",
        "Buttons that change codes are visible only to admin and marketing; other staff see the list read-only",
        "Vitest: the order details show line values before discount, the promotion copy, the code line and totals after discount; an order without a code looks as before",
        "The menu has „Kody rabatowe” (AppMenu test updated); apps/admin/CLAUDE.md describes the discount codes view",
        "make -C apps/admin check and make -C apps/admin test pass"
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DISCOUNT-CODES-1",
        "DISCOUNT-CODES-3"
      ]
    },
    {
      "id": "DISCOUNT-CODES-6",
      "title": "Demo discount codes and the E2E test of the checkout with a code",
      "scope": "apps/api: fixtures/discount-codes.yaml (WITAJ10, MINUS50, LATO15 with times relative to the seed), pricing:seed-demo loads them through CreateDiscountCode with actor null after prices and promotions, the file validated before the reset, DbalPricingReset clears codes and usages; e2e: discount-codes.spec.ts and a helper in e2e/support creating a code with a unique text per run through the admin API as marketing. Spec sections „Dane demo (make seed)”, „Testy” (E2E). Not included: demo orders with codes.",
      "acceptance_criteria": [
        "After make seed WITAJ10 and MINUS50 apply in the checkout and LATO15 is rejected as invalid",
        "An invalid fixtures file ends the seed with an error and no data change; the reset clears codes and usages",
        "E2E discount-codes.spec.ts: a guest sees the below-minimum message, then the applied code, places the order, pays the discounted amount with the simulator and gets the confirmation email with the code line in Mailpit; the test can run twice on the same stack",
        "apps/api/CLAUDE.md describes pricing:seed-demo with discount codes",
        "make -C apps/api check, make -C apps/api test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": [
        "DISCOUNT-CODES-4",
        "DISCOUNT-CODES-5"
      ]
    }
  ]
}
```
