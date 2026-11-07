# ORDER-MANAGEMENT – plan zadań

Specyfikacja: `docs/features/order-management/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | ORDER-MANAGEMENT-1 | |
| 2 | ORDER-MANAGEMENT-2 | |
| 3 | ORDER-MANAGEMENT-3, ORDER-MANAGEMENT-5 | |
| 4 | ORDER-MANAGEMENT-4 | |
| 5 | ORDER-MANAGEMENT-6 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "ORDER-MANAGEMENT",
  "spec": "docs/features/order-management/spec.md",
  "tasks": [
    {
      "id": "ORDER-MANAGEMENT-1",
      "title": "Email notification markers outside the Order aggregate with a sending lease",
      "scope": "apps/api: Ordering Application (port OrderNotifications, handlers SendOrderConfirmation, SendPaymentReceived, SendOrderCancelled), Domain (confirmationSentAt, paymentNotificationSentAt, cancellationNotificationSentAt removed from Order), Infrastructure (DBAL adapter, schema-only record, DbalOrderingReset, repository mapping), migration, MAILER_DSN timeout in infra/docker-compose.yml and phpunit.dist.xml when needed; docs. Spec section „E-maile (P5, A4)”, subsection „Znaczniki wysyłki poza agregatem”, decision A4. Table ordering_order_notification (order_id, kind, claimed_until, sent_at), PK (order_id, kind), kinds confirmation, payment_received, cancelled, shipped, return_received; claim / markSent / release with a 5 min lease via INSERT … ON CONFLICT … DO UPDATE … WHERE … RETURNING; inProgress is a RecoverableMessageHandlingException with a retry delay to the end of the lease + 1 s. Migration moves the existing markers and drops the three columns. Email contents unchanged. Not included: new statuses and new emails (ORDER-MANAGEMENT-2).",
      "acceptance_criteria": [
        "Sending an email no longer changes the version of the order (integration test: the version before and after SendPaymentReceived is equal)",
        "Unit tests of the three handlers with an in-memory port: already sent does nothing; held claim throws RecoverableMessageHandlingException with the delay to the end of the lease + 1 s; a send failure releases the claim and rethrows, the retry sends once; an expired lease sends; an unknown order behaves as today",
        "Integration tests of the adapter on PostgreSQL: concurrent claims from several connections give exactly one claimed; claim after markSent is alreadySent; after release and after the lease expires it is claimed again",
        "Integration test of Messenger retries (test_messaging, a transport with the retry policy of events): a held claim comes back after the lease and ends with one email; an SMTP failure is retried and ends with one email",
        "Two parallel deliveries of the same event send one email",
        "Migration test: markers of existing orders are in the notification table and no email goes out a second time; DbalOrderingReset clears the table",
        "The SMTP timeout is shorter than the lease (checked in the configuration of MAILER_DSN)",
        "apps/api/CLAUDE.md (markers in the notification table, DBAL exception, lease), docs/features/payment/spec.md and docs/features/checkout/spec.md (markers) describe the new state",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "ORDER-MANAGEMENT-2",
      "title": "Order lifecycle: staff transitions, status history, refunds and new emails",
      "scope": "apps/api: Ordering Domain (OrderStatus shipped, delivered, returned; StaffTransition with allowedStaffTransitions; ship, deliver, cancel, registerReturn, completeRefund; Refund; OrderStatusChange and StatusActor; CancellationReason staff; markPaid for the new statuses; OrderTransitionNotAllowed, RefundNotPending), Application (commands ChangeOrderStatus and CompleteRefund returning BackofficeOrderView, port StockReservations::commit, ExpireUnpaidOrderHandler starting with lock, GetOrderForPayment with statusForPayment, SendOrderShipped, SendReturnReceived, SendOrderCancelled by reason, texts), Infrastructure (OrderRepository::lock with PESSIMISTIC_WRITE and refresh, records, adapter CommandBusStockReservations::commit, DbalOrderingReset), migration; Payment Infrastructure (QueryBusPayableOrders reads statusForPayment only); docs. Spec sections „Model (Ordering Domain)”, „Komendy i zapytania” (commands and GetOrderForPayment only), „E-maile” („Treści”), „Persystencja”, decisions P1–P5, P7, P14, A1–A3. Not included: HTTP endpoints, voter, list and detail queries for the panel and the customer (ORDER-MANAGEMENT-3, ORDER-MANAGEMENT-5).",
      "acceptance_criteria": [
        "Unit tests of Order: every row of the transition table and every transition outside it (OrderTransitionNotAllowed without changes); ship from paid sets shippedAt and records OrderShipped; cancel from paid sets reason staff, creates a refund of the gross total due in 14 days and records OrderCancelled; return from shipped and from delivered creates a refund and records OrderReturned; completeRefund completes a pending refund once, otherwise RefundNotPending; history entries with actor and note; note rules (required for cancel and return, 1–500 characters, no control characters, empty means none for the others)",
        "markPaid on shipped, delivered and returned answers alreadyPaid or paidByAnotherPayment without changes; expireUnpaid on them is notApplicable",
        "ChangeOrderStatus ship commits the reservation (physical and reserved of the variants decrease), cancel releases it, both with the history entry and the event in the outbox; a stale expectedVersion is OrderModifiedConcurrently without changes (integration tests)",
        "Concurrency on PostgreSQL: parallel ship and cancel with the same version end with one success and one OrderModifiedConcurrently, the reservation matches the winner, no ReservationStateConflict; ExpireUnpaidOrder and a staff change do not deadlock",
        "CompleteRefund with a stale version is 409; a repeated completion is RefundNotPending",
        "GetOrderForPayment answers statusForPayment paid for shipped, delivered and returned; GetPaymentStatus of such orders shows paid (existing Payment tests pass)",
        "Emails: shipped with the address, cancelled by staff „Anulowaliśmy Twoje zamówienie. Zwrócimy płatność.” without the payment-timeout text, returned „Zamówienie … wróciło do nas. Zwrócimy płatność.”; payment_timeout unchanged; one email per event (unit tests of the texts and handlers)",
        "Migration: the new CHECKs reject a shipped order without paid_at, a returned order without returned_at and a refund without its reason, and accept cancelled after paid; existing orders get history from placed_at, paid_at and cancelled_at",
        "docs/vision.md (Ordering without Symfony Workflow), apps/api/CLAUDE.md (order lifecycle, lock order, history, refund, port commit), docs/features/payment/spec.md (A6, statuses, statusForPayment) and docs/features/checkout/spec.md (status enum) describe the new state",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["ORDER-MANAGEMENT-1"]
    },
    {
      "id": "ORDER-MANAGEMENT-3",
      "title": "Admin API for orders",
      "scope": "apps/api: Ordering Application (ListBackofficeOrders, GetBackofficeOrder via DBAL), Infrastructure (OrderVoter with VIEW_ORDERS, CHANGE_ORDER_STATUS with the transition as subject, COMPLETE_REFUND), UI (GET /api/v1/admin/orders, GET /api/v1/admin/orders/{orderId}, POST …/status-changes, POST …/refund-completion, allowedTransitions and canCompleteRefund filtered by the voter), deptrac.yaml (Ordering_UI and Ordering_Infrastructure → Backoffice_Application), nelmio_api_doc.yaml (BackofficeOrderConflictProblem in the admin area), indexes for the list in a migration, openapi-admin.json; apps/admin: generated schema.d.ts; docs. Spec sections „Komendy i zapytania” (panel queries, role rule), „API” („Panel”), „Persystencja” (indexes), decisions P6, P9, P13. Not included: panel screens.",
      "acceptance_criteria": [
        "Functional tests of every endpoint: no token, customer token, marketing 403 everywhere, warehouse 403 on cancel, return and refund completion, support 403 on ship and deliver, 404 for an unknown order and a non-UUID id, 400 for bad query parameters, 415, 422 with violations, 409 order-transition-not-allowed, refund-not-pending and a stale version",
        "The order of checks for status changes follows the spec: body 422 before the role for the transition, then 404, then 409",
        "List: exact search by number and by email regardless of letter case, other q is an empty list, q over 254 characters and a bad cursor are 400, status filter, refund=pending sorted by due date with overdue and combined with status, keyset paging without duplicates",
        "Detail: contact, address, lines, totals, status dates, paidAt and paymentId, history oldest first, refund with due date, overdue and completion, version; allowedTransitions and canCompleteRefund depend on the roles of the staff member",
        "Responses are private, no-store; 409 of the panel use BackofficeOrderConflictProblem and openapi.json is unchanged",
        "openapi-admin.json exported and the admin client generated in the same PR; Deptrac passes",
        "apps/api/CLAUDE.md describes the admin order endpoints and the voter",
        "make -C apps/api check, make -C apps/api test and make -C apps/admin check pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["ORDER-MANAGEMENT-2"]
    },
    {
      "id": "ORDER-MANAGEMENT-4",
      "title": "Panel: order list and order detail with status actions",
      "scope": "apps/admin: menu, routes, src/views (OrdersView, OrderDetailView), src/orders (useOrderList, useOrderDetail, format.ts), tests including AppMenu.test.ts and router.test.ts; docs. Spec section „Panel (apps/admin)”, decisions P3, P6, P7, P9, P13. List with status filter, „Zwroty do wykonania” switch, search on Enter, cursor stack, empty and error states; detail with history, refund section, payment id, action buttons from allowedTransitions and canCompleteRefund, confirmation dialog with a one-line note, version sent, 409, 422, 403 and 404 handling. The panel computes no rules.",
      "acceptance_criteria": [
        "Vitest: useOrderList (status filter, refund switch, search, cursor stack, empty list texts „Brak zamówień.” and „Nie znaleziono zamówień.”, error with retry, 403 „Nie masz dostępu do zamówień.”)",
        "Vitest: useOrderDetail (buttons only from allowedTransitions and canCompleteRefund; note required for cancel and return; sends the version of the view; 409 reloads the detail and shows the conflict message; 422 at the note field; 403 in the dialog; 404 with a link to the list)",
        "The menu has „Zamówienia” for admin, support and warehouse (AppMenu test updated)",
        "apps/admin/CLAUDE.md describes src/orders and the new views",
        "make -C apps/admin check and make -C apps/admin test pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["ORDER-MANAGEMENT-3"]
    },
    {
      "id": "ORDER-MANAGEMENT-5",
      "title": "Customer order history in the store",
      "scope": "apps/api: Ordering Application (ListCustomerOrders, GetCustomerOrder), UI (GET /api/v1/me/orders, GET /api/v1/me/orders/{orderId}), customer list index in a migration, openapi.json; apps/store: generated schema.d.ts, src/api/client.ts, BFF app/api/account/orders and app/api/account/orders/[orderId], pages app/konto/zamowienia and app/konto/zamowienia/[orderId] (client rendered, noindex), src/account (history components, „Moje zamówienia” in AccountMenu), PaymentConfirmation neutral cancelled text; docs. Spec sections „Komendy i zapytania” (customer queries), „API” („Sklep”), „Sklep (apps/store)”, decisions P8, P10, P12. Not included: payment from the history.",
      "acceptance_criteria": [
        "Functional tests: a customer sees only own orders newest first with paging; an order of another customer, a guest order, an unknown order and a non-UUID id are 404; no token and a backoffice token are 401; responses are private, no-store; paymentDeadlinePassed comes from the API",
        "Store tests: BFF routes (session required, 401 from the API redirects to /auth/login with a sanitized returnTo, 503 is the generic error), list with „Pokaż więcej” keeping loaded items on a failed next page, empty list text, detail with lines, totals, address and status dates, „Czeka na płatność do …” and „Termin płatności minął”, 404 text",
        "AccountMenu shows „Moje zamówienia” for a signed-in customer",
        "PaymentConfirmation shows „Zamówienie zostało anulowane.” for a cancelled order",
        "openapi.json exported and the store client generated in the same PR",
        "apps/store/CLAUDE.md (account pages and BFF), apps/api/CLAUDE.md (customer order endpoints) and docs/features/payment/spec.md (confirmation text) describe the new state",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check and make -C apps/store test pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["ORDER-MANAGEMENT-2"]
    },
    {
      "id": "ORDER-MANAGEMENT-6",
      "title": "E2E: customer order history and order handling in the panel",
      "scope": "e2e: new spec files and e2e/support helpers (customer and staff logins, payment, Mailpit). Spec section „Kryteria akceptacji” (E2E) and path 2 of docs/vision.md. Orders created by the test itself, no assumption about the number of existing orders.",
      "acceptance_criteria": [
        "A customer logs in through Keycloak, places and pays an order, „Moje zamówienia” shows it as „Opłacone” and its detail",
        "warehouse marks the order as shipped in the panel; the customer history shows „Wysłane”; Mailpit has the „wysłane” email",
        "support cancels another paid order with a note; the panel shows the refund to do, „Zwrot pieniędzy wykonany” removes it from „Zwroty do wykonania”",
        "make e2e passes locally and the e2e job passes in CI"
      ],
      "risk": "STANDARD",
      "depends_on": ["ORDER-MANAGEMENT-4", "ORDER-MANAGEMENT-5"]
    }
  ]
}
```
