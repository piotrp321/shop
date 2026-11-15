Review taska ORDER-MANAGEMENT-3 (Admin API for orders), tryb FULL, pierwsze review.

- base_sha: {{BASE}}
- head_sha: {{HEAD}}
- Tryb: FULL
- Specyfikacja: `docs/features/order-management/spec.md` (w checkoucie; przeczytaj też `CLAUDE.md` i `apps/api/CLAUDE.md`).
- Kryteria akceptacji:
  1. Functional tests of every endpoint: no token, customer token, marketing 403 everywhere, warehouse 403 on cancel, return and refund completion, support 403 on ship and deliver, 404 for an unknown order and a non-UUID id, 400 for bad query parameters, 415, 422 with violations, 409 order-transition-not-allowed, refund-not-pending and a stale version
  2. The order of checks for status changes follows the spec: body 422 before the role for the transition, then 404, then 409
  3. List: exact search by number and by email regardless of letter case, other q is an empty list, q over 254 characters and a bad cursor are 400, status filter, refund=pending sorted by due date with overdue and combined with status, keyset paging without duplicates
  4. Detail: contact, address, lines, totals, status dates, paidAt and paymentId, history oldest first, refund with due date, overdue and completion, version; allowedTransitions and canCompleteRefund depend on the roles of the staff member
  5. Responses are private, no-store; 409 of the panel use BackofficeOrderConflictProblem and openapi.json is unchanged
  6. openapi-admin.json exported and the admin client generated in the same PR; Deptrac passes
  7. apps/api/CLAUDE.md describes the admin order endpoints and the voter
  8. make -C apps/api check, make -C apps/api test and make -C apps/admin check pass
- `scope` taska: apps/api: Ordering Application (ListBackofficeOrders, GetBackofficeOrder via DBAL), Infrastructure (OrderVoter with VIEW_ORDERS, CHANGE_ORDER_STATUS with the transition as subject, COMPLETE_REFUND), UI (GET /api/v1/admin/orders, GET /api/v1/admin/orders/{orderId}, POST …/status-changes, POST …/refund-completion, allowedTransitions and canCompleteRefund filtered by the voter), deptrac.yaml (Ordering_UI and Ordering_Infrastructure → Backoffice_Application), nelmio_api_doc.yaml (BackofficeOrderConflictProblem in the admin area), indexes for the list in a migration, openapi-admin.json; apps/admin: generated schema.d.ts; docs. Spec sections „Komendy i zapytania” (panel queries, role rule), „API” („Panel”), „Persystencja” (indexes), decisions P6, P9, P13. Not included: panel screens.
- tdd.status: verified
- Dziennik dowodów TDD: `{{INPUT_DIR}}/tdd-evidence.jsonl`. Wpisy `head` i `dirty` dziennika odnoszą się do commitów gałęzi taska; w tym checkoucie zadanie jest jednym commitem `head_sha` na `base_sha`, więc RED sprawdzasz po nazwach testów w `output_tail` i po plikach w `dirty`.

Sprawdź diff taska (`git diff {{BASE}} {{HEAD}}`). Nie zmieniaj plików.
