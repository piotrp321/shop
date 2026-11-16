Review taska ORDER-MANAGEMENT-1 (Email notification markers outside the Order aggregate with a sending lease), tryb FULL, pierwsze review.

- base_sha: {{BASE}}
- head_sha: {{HEAD}}
- Tryb: FULL
- Specyfikacja: `docs/features/order-management/spec.md` (w checkoucie; przeczytaj też `CLAUDE.md` i `apps/api/CLAUDE.md`).
- Kryteria akceptacji:
  1. Sending an email no longer changes the version of the order (integration test: the version before and after SendPaymentReceived is equal)
  2. Unit tests of the three handlers with an in-memory port: already sent does nothing; held claim throws RecoverableMessageHandlingException with the delay to the end of the lease + 1 s; a send failure releases the claim and rethrows, the retry sends once; an expired lease sends; an unknown order behaves as today
  3. Integration tests of the adapter on PostgreSQL: concurrent claims from several connections give exactly one claimed; claim after markSent is alreadySent; after release and after the lease expires it is claimed again
  4. Integration test of Messenger retries (test_messaging, a transport with the retry policy of events): a held claim comes back after the lease and ends with one email; an SMTP failure is retried and ends with one email
  5. Two parallel deliveries of the same event send one email
  6. Migration test: markers of existing orders are in the notification table and no email goes out a second time; DbalOrderingReset clears the table
  7. The SMTP timeout is shorter than the lease (checked in the configuration of MAILER_DSN)
  8. apps/api/CLAUDE.md (markers in the notification table, DBAL exception, lease), docs/features/payment/spec.md and docs/features/checkout/spec.md (markers) describe the new state
  9. make -C apps/api check and make -C apps/api test pass
- `scope` taska: apps/api: Ordering Application (port OrderNotifications, handlers SendOrderConfirmation, SendPaymentReceived, SendOrderCancelled), Domain (confirmationSentAt, paymentNotificationSentAt, cancellationNotificationSentAt removed from Order), Infrastructure (DBAL adapter, schema-only record, DbalOrderingReset, repository mapping), migration, MAILER_DSN timeout in infra/docker-compose.yml and phpunit.dist.xml when needed; docs. Spec section „E-maile (P5, A4)”, subsection „Znaczniki wysyłki poza agregatem”, decision A4. Table ordering_order_notification (order_id, kind, claimed_until, sent_at), PK (order_id, kind), kinds confirmation, payment_received, cancelled, shipped, return_received; claim / markSent / release with a 5 min lease via INSERT … ON CONFLICT … DO UPDATE … WHERE … RETURNING; inProgress is a RecoverableMessageHandlingException with a retry delay to the end of the lease + 1 s. Migration moves the existing markers and drops the three columns. Email contents unchanged. Not included: new statuses and new emails (ORDER-MANAGEMENT-2).
- tdd.status: verified
- Dziennik dowodów TDD: `{{INPUT_DIR}}/tdd-evidence.jsonl`. Wpisy `head` i `dirty` dziennika odnoszą się do commitów gałęzi taska; w tym checkoucie zadanie jest jednym commitem `head_sha` na `base_sha`, więc RED sprawdzasz po nazwach testów w `output_tail` i po plikach w `dirty`.

Sprawdź diff taska (`git diff {{BASE}} {{HEAD}}`). Nie zmieniaj plików.
