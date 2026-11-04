# CHECKOUT – plan zadań

Specyfikacja: `docs/features/checkout/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | CHECKOUT-1 | |
| 2 | CHECKOUT-2, CHECKOUT-3 | |
| 3 | CHECKOUT-4 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "CHECKOUT",
  "spec": "docs/features/checkout/spec.md",
  "tasks": [
    {
      "id": "CHECKOUT-1",
      "title": "Ordering context: PlaceOrder in one transaction with cart lock, price copies and stock reservation",
      "scope": "apps/api only, no HTTP endpoint. New bounded context Ordering: value objects (OrderId, OrderNumber, OrderOwner, IdempotencyKey, EmailAddress, Contact, ShippingAddress, Money, PriceCopy, OrderLine, OrderTotals), aggregate Order (place, isReplayOf, markConfirmationSent, reconstitute, version) with event OrderPlaced recorded to the outbox, domain exceptions with the Polish detail texts. Command PlaceOrder on command.bus with steps 0-9 of spec section „Złożenie zamówienia (PlaceOrder)”: fast-path replay, request fingerprint with cartRef, ports OrderCart, OrderPrices, OrderCatalog, StockReservations, OrderNumbers and their adapters through query.bus and command.bus (Catalog GetVariantSummaries, Pricing GetCurrentPrices, Inventory ReserveStock with key orderId), unique idempotency_key violation mapped to IdempotencyKeyReused. Cart: commands LockCartForOrder and ClearCartAfterOrder and Cart::clearAfterOrder (section „Komendy Cart dla Ordering”). Persistence: migration with ordering_order, ordering_order_line, CHECK constraints, unique indexes and sequence ordering_order_number_seq START 100001, Doctrine records, DoctrineOrderRepository (add, save, get, findByIdempotencyKey). Deptrac layers and rules for Ordering (section „Bounded contexty i zależności”). Command ordering:seed-demo (DbalOrderingReset, --force-reset in prod) run by make seed before inventory:seed-demo. Docs in this task: docs/features/cart/spec.md (Cart commands, clearAfterOrder, Ordering as the only context depending on Cart, Deptrac, out-of-scope without checkout), docs/features/inventory/spec.md (contract for Ordering realised), apps/api/CLAUDE.md (Ordering transaction rules: nested commands, never catch a nested exception and keep writing, lock order, number sequence through DBAL; ordering:seed-demo in make seed). Not included: HTTP endpoints and OpenAPI (CHECKOUT-2), the confirmation email and symfony/mailer (CHECKOUT-3), the store and the „Przejdź do kasy” button (CHECKOUT-4).",
      "acceptance_criteria": [
        "Unit tests written first (RED, then GREEN) from spec section „Testy / Jednostkowe”: value objects (phone normalisation, postal code, trimmed lengths, OrderNumber), OrderLine and OrderTotals (net + vat = gross, maximum 50 × 99 × 99 999 999 fits in int), Order (place records OrderPlaced with status new, line limits, isReplayOf, markConfirmationSent idempotent), PlaceOrder (empty cart, variant without price and unknown, total mismatch, shortage from Inventory, replay without changes and without the cart lock also for a merged or expired guest cart, same key with other content, other guest cart with the same data and other customer giving 409, amounts always from OrderPrices whatever expectedTotalGross is, step order), Cart clearAfterOrder and LockCartForOrder (merged and expired guest cart, expired customer cart)",
        "Integration on PostgreSQL: repository mapping, CHECK constraints and unique indexes reject direct SQL breaking the rules, idempotency_key violation gives IdempotencyKeyReused, number sequence starts at 100001; PlaceOrder success writes the order, its lines, a reservation with key orderId, an empty cart and an outbox row; a shortage and a forced order-save failure roll back everything (cart lines kept, no reservation, no order, no outbox row); port adapters through the buses",
        "Atomic rollback: a cart with 3 lines where only the last one is short leaves reserved and on_hand of all three variants, the cart lines and expiresAt unchanged, with no reservation, order, line or outbox row; the same for a forced outbox write failure. OrderPlaced survives a round trip through the events transport serializer",
        "Concurrency tests O1-O7 from the spec pass in 5 rounds (incl. parallel replays after the guest cart was merged or expired and the same key from two guest carts or two customers, whose 409 carries no data of the other order), with reserved of every variant equal to the sum of active reservations and every order having a reservation with key orderId and the same lines",
        "Deptrac: Ordering rules from the spec; forbidden dependencies (any other context → Ordering, Ordering_Domain → other contexts and Vendor, Ordering_Application → other contexts, Ordering_Infrastructure → other contexts' Domain and Infrastructure, Ordering_UI → Customer_Infrastructure, Cart → Ordering) are rejected",
        "ordering:seed-demo clears orders and lines; in prod without --force-reset it refuses without changes; make seed after placed orders leaves zero orders and zero reservations",
        "doctrine:schema:validate and the migration diff show no differences",
        "docs/features/cart/spec.md, docs/features/inventory/spec.md and apps/api/CLAUDE.md describe Ordering as in the spec",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "CHECKOUT-2",
      "title": "Order placement endpoints for guests and customers with Idempotency-Key",
      "scope": "apps/api Ordering_UI: POST /api/v1/orders (guest, PUBLIC_ACCESS rule in security.yaml before ROLE_CUSTOMER, cartId in the body) and POST /api/v1/me/orders (customer, email from AuthenticatedCustomer), Idempotency-Key header (missing or invalid: 400), request DTOs with mixed fields and Assert\\Sequentially giving the 422 messages of the spec table, 201 OrderView and 200 on replay, 404/409/415/401/503 mapping, Cache-Control: private, no-store on every response including errors, invalid token on /orders gives 401 (spec section „API”). OpenAPI export (apps/api/openapi.json) and the regenerated store client (make api-client) committed with the change. Docs: apps/api/CLAUDE.md (Idempotency-Key of order placement, replay returns 200 with the same order). Not included: the store BFF and pages (CHECKOUT-4), email (CHECKOUT-3).",
      "acceptance_criteria": [
        "Functional tests for both endpoints: 201, 200 on replay with the same order and no second reservation, 400 (missing key, invalid key, invalid JSON), 401 (/me/orders without a token and with a backoffice token; invalid token on /orders), 404 (unknown, expired and merged guest cart, customer cart id on /orders), 409 for every cause (CartEmpty, CartNotOrderable, InsufficientStockForOrder, OrderTotalChanged, IdempotencyKeyReused), 415, 422 for every row of the message table with the exact violations list, Cache-Control: private, no-store",
        "The response body matches the OrderView shape of the spec; /me/orders takes the email from the token",
        "Server-side prices: a body with extra fields (lines with other quantities, totals, unitGross, price) gives an order with cart quantities and Pricing prices; expectedTotalGross lower (also 0) or higher than the total gives 409 OrderTotalChanged with no order and no reservation",
        "409 IdempotencyKeyReused carries no orderId, number or email of the order that used the key; a replay after the guest cart was merged or expired gives 200",
        "apps/api/openapi.json and the generated store client are updated in the same PR; apps/api/openapi-admin.json is unchanged",
        "apps/api/CLAUDE.md describes the Idempotency-Key of order placement",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check and make -C apps/store test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["CHECKOUT-1"]
    },
    {
      "id": "CHECKOUT-3",
      "title": "Order confirmation email through OrderPlaced and Symfony Mailer to Mailpit",
      "scope": "apps/api and infra: new dependency symfony/mailer (approved in spec decision T1; review Flex recipe changes and drop what the task does not need), framework.mailer.message_bus: false, MAILER_DSN smtp://mailpit:1025 and MAILER_FROM for api and worker in infra/docker-compose.yml, test configuration and CI. Event handler SendOrderConfirmation on event.bus from transport events (unknown order: UnrecoverableMessageHandlingException; confirmationSentAt set: nothing; otherwise send, then markConfirmationSent and save with the version), port OrderConfirmationMailer with adapter SymfonyOrderConfirmationMailer (synchronous send), OrderConfirmationText in Ordering_Application with the Polish plain-text content, subject and amount format of spec section „E-mail z potwierdzeniem”. Docs: apps/api/CLAUDE.md (MAILER_DSN, sending without Messenger). Not included: endpoints (CHECKOUT-2), store and E2E (CHECKOUT-4).",
      "acceptance_criteria": [
        "Unit tests written first (RED, then GREEN): SendOrderConfirmation sends and marks, a second delivery does not send, unknown order is unrecoverable; OrderConfirmationText content, amounts as 1 234,56 zł, date and time in Europe/Warsaw, default variant without a label and without a separator",
        "Integration: SymfonyOrderConfirmationMailer delivers to Mailpit synchronously (read through the Mailpit API); unavailable SMTP throws in the handler and no message is dispatched to command.bus",
        "A placed order produces exactly one email in Mailpit through outbox, RabbitMQ and the worker, also when OrderPlaced is delivered twice one after the other",
        "Redelivery: an SMTP failure on the first attempt gives a retry and one email once SMTP is back; after the retries the message is in failed and the order has no marker; two parallel handlers of the same event: one marker save wins, the other gets a version conflict and its retry sends nothing; a version conflict in the unit test rethrows",
        "composer.json gains only symfony/mailer (and its required packages); no Flex leftovers such as secrets in .env",
        "apps/api/CLAUDE.md describes MAILER_DSN and the send without Messenger",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["CHECKOUT-1"]
    },
    {
      "id": "CHECKOUT-4",
      "title": "Store checkout: BFF, checkout and confirmation pages, cart button and E2E",
      "scope": "apps/store and e2e (spec section „Sklep (apps/store)”): /auth/session returns {name, email} and AccountSession with its consumers gets the field; placeOrder in src/api/client.ts; BFF POST /api/checkout (Origin and Content-Type checks, Idempotency-Key from the browser, session → /me/orders after merging store_cart, guest → /orders with cartId from store_cart, no store_cart → 409 without the API, signed HttpOnly cookie store_order_confirmation {number, email, totalGross, currency} for 1 hour on 201 and 200, guest 404 clears store_cart, 401 ends the session, 503 mapping, no-store); /cart button „Przejdź do kasy” (inactive with hasExcludedLines); /checkout (noindex, Client Component, summary from /api/cart, form for guest and customer, „Zaloguj się” link, button „Zamawiam z obowiązkiem zapłaty”, Idempotency-Key reuse and renewal rules, UI error table); /checkout/confirmation (noindex, Server Component reading the signed cookie). E2E checkout-guest.spec.ts and checkout-customer.spec.ts with the Mailpit check by address and order number, on high-stock variants with a comment in fixtures/stock.yaml. Docs: apps/store/CLAUDE.md (checkout BFF, store_order_confirmation, /checkout pages, /auth/session with email), docs/features/cart/spec.md (button „Przejdź do kasy” on /cart). Not included: API changes (CHECKOUT-2), email content (CHECKOUT-3).",
      "acceptance_criteria": [
        "Vitest: BFF route (guest, session with store_cart merge, no store_cart, 403 for Origin and Content-Type, missing Idempotency-Key, key forwarded, only contract fields forwarded to the API (extra browser fields such as prices dropped), 201 and 200 set store_order_confirmation with currency, guest 404 clears store_cart, 409, 422, 401, 503, no-store); /auth/session returns {name, email}; CheckoutPage (guest and customer form, summary, button locked during the request, every row of the UI table, retry with the same key, new key after every 409 including IdempotencyKeyReused and after a form change, empty cart, hasExcludedLines); /checkout/confirmation with the cookie, without it and with an invalid signature; CartPage button active and inactive; client placeOrder",
        "E2E guest: variant chosen on the tile, 2 pieces, „Przejdź do kasy” on /cart, form, order placed; the confirmation page shows R-…, the email and the cart total; cart counter 0; Mailpit has an email to that address with that number",
        "E2E customer: Jan logs in, empties the cart, adds a variant and places an order without an email field; Mailpit has an email to Jan's address with that order's number",
        "Checkout pages have noindex, nofollow; the store does no price or total calculation",
        "apps/store/CLAUDE.md and docs/features/cart/spec.md describe the checkout as in the spec",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["CHECKOUT-2", "CHECKOUT-3"]
    }
  ]
}
```
