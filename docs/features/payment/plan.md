# PAYMENT – plan zadań

Specyfikacja: `docs/features/payment/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | PAYMENT-1, PAYMENT-2 | |
| 2 | PAYMENT-3 | |
| 3 | PAYMENT-4 | |
| 4 | PAYMENT-5 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PAYMENT",
  "spec": "docs/features/payment/spec.md",
  "tasks": [
    {
      "id": "PAYMENT-1",
      "title": "Order payment lifecycle in Ordering: deadline, guest access token, paid and cancelled, delayed expiry with stock release, payment emails",
      "scope": "apps/api Ordering, messenger config, infra, docs. Spec sections „Ordering: zmiany” (Model, Komendy i zapytania, E-maile, API Ordering, Persystencja), „Transporty scheduled i payment_simulator” (only the scheduled transport), „Dane demo” (scheduled queue in ordering:seed-demo), decisions P1, P4, A2 (Ordering side), A4, A5, A6. OrderStatus new/paid/cancelled; Order fields paymentDeadline, accessToken (guest only), paidAt, paymentId, cancelledAt, cancellationReason, notification markers; grantsAccess, markPaid (with executedAt, amount and currency, every result), expireUnpaid (cancelled/tooEarly/notApplicable), isPayable. Ordering_Application: GetOrderForPayment(orderId, access) returning OrderForPayment or null for every no-access case; MarkOrderPaid returning MarkOrderPaidResult, with OrderToPayNotFound and OrderChangedConcurrently in Ordering_Application; ExpireUnpaidOrder on the new Doctrine transport scheduled (redeliver_timeout 300, retries 5/1 s/x3) releasing the reservation through Inventory ReleaseReservation (StockReservations::release) in the same transaction, rescheduling on tooEarly, ignoring unknown orders. PlaceOrder dispatches ExpireUnpaidOrder with DelayStamp to paymentDeadline + 5 min in its transaction (not on replay). OrderPaid and OrderCancelled events with SendPaymentReceived and SendOrderCancelled emails; confirmation email line with the deadline. OrderView gets paymentDeadline and accessToken. Migration (columns, CHECKs, backfill of deadline and guest tokens). ordering:seed-demo also deletes the scheduled queue. Worker consumes scheduled (infra/docker-compose.yml command and healthcheck); test and test_messaging messenger configs. OpenAPI export and regenerated store client in the same PR. Not included: Payment context, simulator, store UI. Docs: apps/api/CLAUDE.md (Ordering payment lifecycle, scheduled transport, ordering:seed-demo), docs/features/checkout/spec.md (statuses after placement, OrderView fields, confirmation email text, removal of the risk that the reservation never expires).",
      "acceptance_criteria": [
        "Unit tests (TDD, RED and GREEN runs in the report): markPaid for every result including executedAt equal to the deadline and one second after it and a wrong currency, expireUnpaid for every status and before/after deadline + 5 min, grantsAccess for guest token, wrong token, customer and other customer, isPayable, ExpireUnpaidOrder (release only on cancel, reschedule on tooEarly, unknown order), MarkOrderPaid (translation of the version conflict), GetOrderForPayment (every access case gives null), email texts with the deadline in Europe/Warsaw",
        "Integration: PlaceOrder stores ExpireUnpaidOrder with the delay in its transaction and a rolled back placement stores none; ExpireUnpaidOrder releases the reservation (reserved decreases) and writes OrderCancelled; delivered twice it changes nothing and reserved changes once; it survives a worker restart (first consume before the deadline, available_at moved by SQL, second process cancels); repository CHECKs and the migration backfill",
        "SendPaymentReceived and SendOrderCancelled send one email each to Mailpit, also when the event is delivered twice in a row",
        "Functional: placement responses contain paymentDeadline and accessToken (null for a customer, the same token on a replay)",
        "ordering:seed-demo deletes only the scheduled queue besides the order tables; outbox and failed stay",
        "openapi.json and the store client regenerated; the worker consumes scheduled on the running stack",
        "apps/api/CLAUDE.md and docs/features/checkout/spec.md describe the changes",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "PAYMENT-2",
      "title": "Payment simulator of two fake providers with signed webhook delivery, provider secrets and the Unavailable problem marker",
      "scope": "apps/api PaymentSimulator and Shared, infra, Makefile, docs. Spec sections „PaymentSimulator”, „Transporty scheduled i payment_simulator” (only payment_simulator), „Shared: marker Unavailable”, „Port PaymentProvider i adaptery” (provider-side API contracts of the table: create, cancel, webhook bodies and signatures, amount and time formats), „Dane demo” (payment-simulator:seed-demo), „Testy wywołań HTTP dostawców” (the MockHttpClient that forwards to a separate test kernel instance, in tests/Support), decisions P3, P7 (simulator link back), A3, T1. Flat module with one Deptrac layer PaymentSimulator (Native, Vendor, Shared_Infrastructure; no dependency to or from any context). Table payment_simulator_transaction with row lock for decision and cancel; API endpoints of demo-transfer (Bearer) and demo-card (Basic) for create and cancel; payment page (HTML, escaped, CSRF, closed and expired states, link back, 404 page); decision POST storing decided_at and DeliverSimulatedWebhook in one transaction; delivery to PAYMENT_WEBHOOK_BASE_URL with the provider signature (fresh t for demo-card), 2xx and 4xx end, 5xx and network errors retry on the Doctrine transport payment_simulator (redeliver_timeout 300, retries 5/1 s/x3). Env: PAYMENT_PROVIDER_BASE_URL, PAYMENT_SIMULATOR_PUBLIC_URL, PAYMENT_WEBHOOK_BASE_URL in compose for api and worker and in phpunit.dist.xml; four provider secrets generated by infra/scripts/init-env.sh, which appends missing appendable keys to an existing .env.local. Worker consumes payment_simulator. payment-simulator:seed-demo and its place in make seed. Shared marker Unavailable mapped to a generic 503. Not included: Payment context and its adapters, webhook endpoint of the shop. Docs: apps/api/CLAUDE.md (simulator, payment_simulator transport, Unavailable marker, seed), CLAUDE.md is not changed.",
      "acceptance_criteria": [
        "Functional tests of the simulator: 401 for a wrong API key of each provider, create and cancel of both providers (open, cancelled again, paid, rejected), payment page open, paid, rejected, cancelled, expired and unknown id, CSRF rejection, a second decision only redirects",
        "Integration: the decision stores the transaction state and one DeliverSimulatedWebhook in one transaction; delivery through the payment_simulator transport signs the body as specified (shared test vector) and retries on 5xx and network error, stops on 2xx and 4xx",
        "Concurrency: cancel and pay of the same transaction in parallel give exactly one final state",
        "Functional test of the Unavailable marker: 503 without detail",
        "init-env.sh generates the four provider secrets, appends them to an existing .env.local that lacks them and still fails for a missing non-appendable key (both paths shown)",
        "Deptrac forbids dependencies from the simulator to any context and from any context to the simulator (attempts shown failing)",
        "payment-simulator:seed-demo clears only its table and the payment_simulator queue and refuses in prod without --force-reset; make seed runs it",
        "apps/api/CLAUDE.md describes the simulator, its transport and the marker",
        "make -C apps/api check, make -C apps/api test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "PAYMENT-3",
      "title": "Payment context: start payment with provider sessions, payment status and the two provider adapters",
      "scope": "apps/api Payment, security, Deptrac, OpenAPI, docs. Spec sections „Payment” (Value objecty, Agregat Payment, Port PaymentProvider i adaptery except parseWebhook, Rozpoczęcie płatności, Status płatności, Persystencja), „API sklepu” (payment-providers, both start endpoints, both status endpoints, conflict types and messages), „Dane demo” (payment:seed-demo), „Testy wywołań HTTP dostawców”, decisions P2 (API side), P5, A1, A2 (GetOrderForPayment), A5. New layers Payment_* with the Deptrac rules of the spec (Payment_Infrastructure → Ordering_Application only). Payment aggregate without version, statuses pending/succeeded/failed/cancelled, refund reason column (set later by webhooks), partial unique index of one pending payment per order. PaymentProvider createSession and cancelSession in both adapters over HTTP to the simulator (amount formats, auth, 5 s timeout, PaymentProviderUnavailable). StartPayment with access check before the replay, cancel of the pending payment at the provider under its row lock, new session with expiresAt = paymentDeadline. GetPaymentStatus with resumeUrl and canStartPayment. Endpoints, security.yaml rules, PaymentConflictProblem schema in nelmio_api_doc.yaml, OpenAPI export and regenerated store client. payment:seed-demo in make seed. Not included: webhook endpoint and MarkOrderPaid (PAYMENT-4), store UI (PAYMENT-5). Docs: apps/api/CLAUDE.md (Payment context, start payment, new problem types, payment:seed-demo).",
      "acceptance_criteria": [
        "Unit tests (TDD, RED and GREEN runs in the report): Payment transitions (start, attachProviderSession, fail, cancel, resumeUrl), StartPayment for every error, replay and every cancelSession result, GetPaymentStatus, demo-card amount parsing and formatting",
        "Functional tests of every endpoint of the table except the webhook: success, replay, replay with the same key without token, with another token and by another customer (404), 400, 401, 404 for every access case, 409 for every type, 415, 422 for provider, 503 for an unavailable provider, Cache-Control private, no-store",
        "Integration with the simulator through the forwarding MockHttpClient: start creates a session, a second start cancels the pending one, an already paid session gives 409 PaymentInProgress",
        "Concurrency: 10 parallel starts with the same key give one payment; 2 parallel starts with different keys give one pending payment and one 409 PaymentInProgress",
        "Deptrac rules of Payment, including forbidden Payment → Ordering_Domain and context → Payment (attempts shown failing)",
        "openapi.json and the store client regenerated; openapi-admin.json unchanged",
        "apps/api/CLAUDE.md describes the Payment context and start payment",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["PAYMENT-1", "PAYMENT-2"]
    },
    {
      "id": "PAYMENT-4",
      "title": "Provider webhooks: signature check, deduplication, amount check, order paid in one transaction and refund marking",
      "scope": "apps/api Payment, OpenAPI, docs. Spec sections „Webhook (HandleProviderWebhook)”, „Port PaymentProvider i adaptery” (parseWebhook of both adapters, signatures, tolerance, executedAt), „Persystencja” (payment_webhook_event), „Scenariusze błędów” (webhook rows), decisions P1 (executedAt), P5 (refund marking), A2. Controller verifies and parses through the adapter (404 unknown provider, 400 invalid webhook, 204 other event type); HandleProviderWebhook locks the payment, deduplicates by (provider, eventId), checks the amount, succeeds or fails the payment, calls MarkOrderPaid nested and marks refunds for orderCancelled, paidAfterDeadline and paidByAnotherPayment; version conflict of the order gives OrderBusy (503) and a full rollback. security.yaml rule for the webhook path, OpenAPI export and regenerated store client. Not included: store UI. Docs: apps/api/CLAUDE.md (webhooks, transaction with MarkOrderPaid, refund marking).",
      "acceptance_criteria": [
        "Unit tests (TDD, RED and GREEN runs in the report): signature of both adapters (valid, wrong, missing, demo-card timestamp out of tolerance), parsing of amount and executedAt, status mapping, HandleProviderWebhook deduplication, amount mismatch, every MarkOrderPaid result with its refund reason, OrderBusy and another error",
        "Functional: every signature case gives 400 without changes, unknown provider and unknown payment 404, amount mismatch 422, duplicate 204 without changes, version conflict 503",
        "Integration: full flow start → simulator decision → DeliverSimulatedWebhook → webhook → order paid with OrderPaid in the outbox; forced MarkOrderPaid error gives 5xx, payment pending and no deduplication row, the retried delivery pays the order; payment executed before the deadline and received 3 minutes after it pays the order; executed after the deadline marks paid_after_deadline; success for a cancelled order gives 204, order_cancelled and the order stays cancelled with the reservation released",
        "Concurrency (5 rounds): W1 the same success event 10 times in parallel, W2 success and failure events of one payment in parallel, W3 success webhook against ExpireUnpaidOrder (exactly one final state, reservation released only when cancelled), W6 new attempt against the simulator decision (never two succeeded payments)",
        "apps/api/CLAUDE.md describes webhooks and refund marking",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "CRITICAL",
      "depends_on": ["PAYMENT-3"]
    },
    {
      "id": "PAYMENT-5",
      "title": "Store checkout with payment: provider choice, redirect, confirmation page with payment status, resume and retry",
      "scope": "apps/store, e2e, docs. Spec sections „Sklep (apps/store)” (BFF and UI), „Scenariusze błędów” (store rows), decisions P2, P6, P7 (store link), A5 (endpoint choice by the cookie). POST /api/checkout with provider (422 without API for missing or unknown provider, 503 without the provider list), cookie store_order_confirmation with orderId and accessToken, start payment with the same key, redirectUrl or null; POST and GET /api/checkout/payment with endpoint choice by accessToken; client.ts functions; /checkout provider radio group, unavailable list, redirect, link „Opłać zamówienie” on an empty cart with the cookie; /checkout/confirmation states of the table (paid, pending before deadline with resume and retry after 30 s, after deadline, failed/cancelled/no payment, cancelled, read failure, 401, 404), deadline formatted in Europe/Warsaw, pay results table, keys. E2E: checkout-guest.spec.ts and checkout-customer.spec.ts with payment, new payment-resume.spec.ts, reservation comments in e2e and apps/api/fixtures/stock.yaml. Not included: API changes. Docs: apps/store/CLAUDE.md (checkout with payment, BFF /api/checkout/payment), docs/features/checkout/spec.md (confirmation page, decision P7 of CHECKOUT: the guest reads the payment status with the token).",
      "acceptance_criteria": [
        "Vitest: BFF /api/checkout (missing and unknown provider without API, placement plus start, start failure, same key after a provider change), BFF /api/checkout/payment (POST and GET, no cookie, endpoint choice by accessToken with and without session, 401 and 404 of the status read, every row of the pay results table), CheckoutPage (provider choice, unavailable list, redirect, link on an empty cart with the cookie), confirmation page (every state of the table, polling, resume, pay another way after 30 s, retry, keys, time in Europe/Warsaw), client.test.ts (startPayment, getPaymentStatus)",
        "E2E checkout-guest.spec.ts: the guest pays with „Szybki przelew (demo)” and sees „Płatność przyjęta”; Mailpit has the confirmation and the payment email",
        "E2E checkout-customer.spec.ts: Jan rejects the payment with „Karta płatnicza (demo)”, chooses again, pays and sees „Płatność przyjęta”",
        "E2E payment-resume.spec.ts: back from the simulator page to /checkout, link „Opłać zamówienie R-…”, „Wróć do płatności” opens the same session, payment succeeds",
        "apps/store/CLAUDE.md and docs/features/checkout/spec.md describe the payment flow",
        "make -C apps/store check, make -C apps/store test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": ["PAYMENT-4"]
    }
  ]
}
```
