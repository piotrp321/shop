Review taska CATALOG-ADMIN-3 (Reprice variant prices after a tax class change), tryb FULL, pierwsze review.

- base_sha: {{BASE}}
- head_sha: {{HEAD}}
- Tryb: FULL
- Specyfikacja: `docs/features/catalog-admin/spec.md` (w checkoucie; przeczytaj też `CLAUDE.md` i `apps/api/CLAUDE.md`).
- Kryteria akceptacji:
  1. After a tax class change the prices of the product's variants have the new rate and the same gross and a history entry with source tax_reconciliation (integration test)
  2. A → B → A with the events handled in either order, and a repeated event, end with prices in class A; handling when prices already have the current class writes nothing (unit tests)
  3. A class without a rate sends the message to failed with a message; a second version conflict throws for a retry; an unknown product is handled without changes
  4. pricing:reconcile-tax keeps its behaviour on the extracted loop (existing tests pass)
  5. docs/features/pricing/spec.md and apps/api/CLAUDE.md describe the repricing
  6. make -C apps/api check and make -C apps/api test pass
- `scope` taska: apps/api: Pricing Application (loop extracted from ReconcileTax) and Infrastructure (consumer and port adapter), docs. Spec section „Pricing: przeliczenie przy zmianie klasy (K6)”, decision K6. Consumer RepriceOnTaxClassChange on the events transport for the integration ProductChanged: variants and class from Catalog ListProductVariants([productId], includeDrafts: true); every price with a class other than the product's is repriced (same gross, source tax_reconciliation, VariantPriceChanged) by the reprice-save-retry loop shared with pricing:reconcile-tax. Not included: Catalog changes.
- tdd.status: verified
- Dziennik dowodów TDD: `{{INPUT_DIR}}/tdd-evidence.jsonl`. Wpisy `head` i `dirty` dziennika odnoszą się do commitów gałęzi taska; w tym checkoucie zadanie jest jednym commitem `head_sha` na `base_sha`, więc RED sprawdzasz po nazwach testów w `output_tail` i po plikach w `dirty`.

Sprawdź diff taska (`git diff {{BASE}} {{HEAD}}`). Nie zmieniaj plików.
