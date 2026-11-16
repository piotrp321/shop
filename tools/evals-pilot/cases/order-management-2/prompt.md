Review taska ORDER-MANAGEMENT-2 (Order lifecycle: staff transitions, status history, refunds and new emails), tryb FULL, pierwsze review.

- base_sha: {{BASE}}
- head_sha: {{HEAD}}
- Tryb: FULL
- Specyfikacja: `docs/features/order-management/spec.md` (w checkoucie; przeczytaj też `CLAUDE.md` i `apps/api/CLAUDE.md`).
- Kryteria akceptacji:
  1. Unit tests of Order: every row of the transition table and every transition outside it (OrderTransitionNotAllowed without changes); ship from paid sets shippedAt and records OrderShipped; cancel from paid sets reason staff, creates a refund of the gross total due in 14 days and records OrderCancelled; return from shipped and from delivered creates a refund and records OrderReturned; completeRefund completes a pending refund once, otherwise RefundNotPending; history entries with actor and note; note rules (required for cancel and return, 1–500 characters, no control characters, empty means none for the others)
  2. markPaid on shipped, delivered and returned answers alreadyPaid or paidByAnotherPayment without changes; expireUnpaid on them is notApplicable
  3. ChangeOrderStatus ship commits the reservation (physical and reserved of the variants decrease), cancel releases it, both with the history entry and the event in the outbox; a stale expectedVersion is OrderModifiedConcurrently without changes (integration tests)
  4. Concurrency on PostgreSQL: parallel ship and cancel with the same version end with one success and one OrderModifiedConcurrently, the reservation matches the winner, no ReservationStateConflict; ExpireUnpaidOrder and a staff change do not deadlock
  5. CompleteRefund with a stale version is 409; a repeated completion is RefundNotPending
  6. GetOrderForPayment answers statusForPayment paid for shipped, delivered and returned; GetPaymentStatus of such orders shows paid (existing Payment tests pass)
  7. Emails: shipped with the address, cancelled by staff „Anulowaliśmy Twoje zamówienie. Zwrócimy płatność.” without the payment-timeout text, returned „Zamówienie … wróciło do nas. Zwrócimy płatność.”; payment_timeout unchanged; one email per event (unit tests of the texts and handlers)
  8. Migration: the new CHECKs reject a shipped order without paid_at, a returned order without returned_at and a refund without its reason, and accept cancelled after paid; existing orders get history from placed_at, paid_at and cancelled_at
  9. docs/vision.md (Ordering without Symfony Workflow), apps/api/CLAUDE.md (order lifecycle, lock order, history, refund, port commit), docs/features/payment/spec.md (A6, statuses, statusForPayment) and docs/features/checkout/spec.md (status enum) describe the new state
  10. make -C apps/api check and make -C apps/api test pass
- `scope` taska: apps/api: Ordering Domain (OrderStatus shipped, delivered, returned; StaffTransition with allowedStaffTransitions; ship, deliver, cancel, registerReturn, completeRefund; Refund; OrderStatusChange and StatusActor; CancellationReason staff; markPaid for the new statuses; OrderTransitionNotAllowed, RefundNotPending), Application (commands ChangeOrderStatus and CompleteRefund returning BackofficeOrderView, port StockReservations::commit, ExpireUnpaidOrderHandler starting with lock, GetOrderForPayment with statusForPayment, SendOrderShipped, SendReturnReceived, SendOrderCancelled by reason, texts), Infrastructure (OrderRepository::lock with PESSIMISTIC_WRITE and refresh, records, adapter CommandBusStockReservations::commit, DbalOrderingReset), migration; Payment Infrastructure (QueryBusPayableOrders reads statusForPayment only); docs. Spec sections „Model (Ordering Domain)”, „Komendy i zapytania” (commands and GetOrderForPayment only), „E-maile” („Treści”), „Persystencja”, decisions P1–P5, P7, P14, A1–A3. Not included: HTTP endpoints, voter, list and detail queries for the panel and the customer (ORDER-MANAGEMENT-3, ORDER-MANAGEMENT-5).
- tdd.status: verified
- Dziennik dowodów TDD: `{{INPUT_DIR}}/tdd-evidence.jsonl`. Wpisy `head` i `dirty` dziennika odnoszą się do commitów gałęzi taska; w tym checkoucie zadanie jest jednym commitem `head_sha` na `base_sha`, więc RED sprawdzasz po nazwach testów w `output_tail` i po plikach w `dirty`.

Sprawdź diff taska (`git diff {{BASE}} {{HEAD}}`). Nie zmieniaj plików.
