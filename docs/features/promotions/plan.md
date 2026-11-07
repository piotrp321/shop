# PROMOTIONS – plan zadań

Specyfikacja: `docs/features/promotions/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | PROMOTIONS-1 | |
| 2 | PROMOTIONS-2, PROMOTIONS-3, PROMOTIONS-5 | |
| 3 | PROMOTIONS-4 | |
| 4 | PROMOTIONS-6 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "PROMOTIONS",
  "spec": "docs/features/promotions/spec.md",
  "tasks": [
    {
      "id": "PROMOTIONS-1",
      "title": "Promotion aggregate, admin promotions API and the price lock",
      "scope": "apps/api: Pricing Domain (PercentOff, PromotionPeriod, Promotion with plan/revise/assertDeletable and startOfNow, PromotionalPrice, PromotionNotEditable, PromotionOverlap with conflictingVariantIds, PriceLockedByPromotion), Application (PlanPromotion, RevisePromotion, DeletePromotion, ListPromotions, GetPromotion; Catalog GetVariantSummaries in batches of 100 through a Pricing port; newly added variants must exist and have a current price), Infrastructure (records and migration for pricing_promotion and pricing_promotion_variant, repository, global advisory transaction lock via DBAL, PromotionVoter), UI (admin endpoints /api/v1/admin/promotions with PricingConflictProblem), SetVariantPrice blocked for a variant in an active promotion with the shared advisory lock and now taken after the lock; openapi-admin.json and apps/admin/src/api/schema.d.ts regenerated; docs. Spec sections „Cena skuteczna i promocja” (aggregate, invariant Q4, lock Q13), „Komendy” (promotion commands), „Persystencja” (promotion tables), „API” (panel promotions, problem schema), decisions Q1–Q5, Q13, A1. Not included: effective price in GetCurrentPrices and Omnibus (PROMOTIONS-2), boundary messages and events (PROMOTIONS-3; the command handlers call the PromotionBoundaries port, which this task defines with a no-op adapter replaced in PROMOTIONS-3) and discount codes (DISCOUNT-CODES #225).",
      "acceptance_criteria": [
        "Unit tests of Promotion: statuses from time, plan with startsAt ≤ now moved to startOfNow (now truncated to the second + 1 s), revise of planned changes everything, revise of active changes only name and end (other fields: PromotionNotEditable), endsAt ≤ now on an active promotion ends it at startOfNow, ended is not editable, only planned is deletable, 90-day and 1–1000 variant limits, actor and updatedAt recorded",
        "Unit tests of PromotionalPrice: catalog − floor(catalog × p / 100) for p 1 and 90, 1 grosz, maximum gross",
        "Overlapping periods of promotions with a common variant are rejected with 409 promotion-overlap listing conflictingVariantIds; a newly added variant unknown to Catalog or without a current price is 422 at variantIds; a deleted variant already in the promotion does not block revise",
        "PUT /api/v1/admin/variants/{id}/price of a variant in an active promotion is 409 price-locked-by-promotion before the version check; with a planned or ended promotion the price changes; pricing:reconcile-tax --apply still reprices such a variant",
        "Concurrency test (5 rounds): two parallel overlapping promotion writes give one success and 409; a price change in parallel with a promotion „od teraz” on the variant ends with changed_at < startsAt or 409, never a change inside the active promotion",
        "Functional tests of every promotions endpoint: 200/201/204, 400 bad list parameters or cursor, 401 without a token and with a shop token, 403 for support and warehouse, 404, 409 (version, promotion-not-editable, promotion-overlap), 422; admin and marketing may write, every staff member may read",
        "Deptrac bypass attempts give errors: Pricing_* → Cart_*, Pricing_* → Ordering_*",
        "openapi-admin.json and the admin client are regenerated; docs/features/pricing/spec.md describes promotions in the model, panel API and the price lock; apps/api/CLAUDE.md describes the advisory lock exception",
        "make -C apps/api check, make -C apps/api test, make -C apps/admin check pass"
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "PROMOTIONS-2",
      "title": "Effective price and Omnibus in Pricing and in the product offers",
      "scope": "apps/api: Pricing Domain (EffectivePrice, LowestPriorPrice), Application (GetCurrentPrices returns the effective net/vat/gross (cart and order pay it without code changes), regularGross and promotion {promotionId, name, percentOff, endsAt, lowestPriorGross|null}; lowestPriorGross only with the withLowestPriorPrice flag; GetPromotion uses it and has promotionalGross null for ended), Infrastructure (queries of active promotions and of history and promotions for the Omnibus window), Cart (CartPrices and ProductOffer carry the new fields; GetProductOffers with the flag, true for the endpoint and false in QueryBusProductOfferFacts; variant regularGross and promotion; ProductOfferSummary.fromPromotion), UI (GET /api/v1/product-offers fields); openapi.json and apps/store/src/api/schema.d.ts regenerated; docs. Spec sections „Cena skuteczna i promocja” (EffectivePrice), „Cena Omnibus”, „Odczyty” (GetCurrentPrices), „Cart” (offer), „API” (sklep, product-offers), decisions A2 (read-time price), A3, Q11 rule for null. Not included: cart view changes (the view keeps CartPrices and gets the effective price unchanged), the store UI (PROMOTIONS-4), cache invalidation (PROMOTIONS-3).",
      "acceptance_criteria": [
        "Unit tests of LowestPriorPrice: no change in the window, a raise before the promotion, an earlier promotion in the window, a promotion started before the window and ending in it, a catalog change in the window, the first price inside the window (only the priced part counts), no price before start gives null, an entry exactly on the window boundary, an entry with an outdated VAT rate counts",
        "GetCurrentPrices of a variant in an active promotion returns the promotional gross with net and VAT from PriceBreakdown, regularGross and the promotion; without the flag it computes no Omnibus and makes no Omnibus queries; outside the promotion period the catalog price",
        "Integration test: the Omnibus queries for many promoted variants in one call",
        "A guest cart line and a placed order of a promoted variant carry the promotional price (existing cart and checkout tests extended, no code change in Cart view or Ordering)",
        "GET /api/v1/product-offers: variant regularGross and promotion {endsAt, lowestPriorGross: int|null}, offer fromPromotion for the variant of fromGross (tie: first in position order); the search index gets the effective price through ProductOfferFacts without Omnibus",
        "GetPromotion shows catalog and promotional prices, promotionalGross null for an ended promotion",
        "openapi.json and the store client are regenerated; docs/features/pricing/spec.md, docs/features/cart/spec.md and docs/features/checkout/spec.md (order copies the effective price; out of scope now DISCOUNT-CODES #225) describe the effective price, Omnibus and the offer fields",
        "make -C apps/api check, make -C apps/api test, make -C apps/store check pass"
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "PROMOTIONS-1"
      ]
    },
    {
      "id": "PROMOTIONS-3",
      "title": "Promotion boundaries: transport, messages and storefront refresh per product",
      "scope": "apps/api: Messenger transport promotions (Doctrine, queue_name=promotions, options of scheduled; in-memory in test, real in test_messaging), PromotionBoundaries adapter (DelayStamp) replacing the no-op adapter of PROMOTIONS-1, messages PromotionBoundaryReached(promotionId, at) for every boundary ≥ now and RefreshPromotionProducts(promotionId) for revise of an active promotion moving endsAt to another future time, their handler (stale message: no effect, early: back to at + 1 s, products of the promotion's variants via Catalog GetVariantSummaries in batches, unknown variants skipped, one PromotionPricesChanged {productId, source, occurredAt} per product in the outbox), consumers ReindexProductOnPromotionChange and InvalidateStorefrontOnPromotionChange in Pricing_Infrastructure (source seed acked without effect); infra/docker-compose.yml worker command and healthcheck consume promotions; docs. Spec sections „Granice promocji i unieważnianie (A2)”, decision A2. Not included: seed of promotions (PROMOTIONS-6).",
      "acceptance_criteria": [
        "Unit tests of the handler: a message whose at differs from the current startsAt/endsAt or of an unknown promotion has no effect; an early message is redelivered at at + 1 s; a promotion of 20 variants of one product publishes one event; RefreshPromotionProducts of an ended or unknown promotion has no effect",
        "Plan „od teraz” and „Zakończ teraz” send a boundary message, a future boundary a delayed one, revise moving the end of an active promotion a RefreshPromotionProducts; deleting a planned promotion publishes nothing; the command handlers neither read Catalog nor publish events",
        "Integration test on test_messaging: a delayed PromotionBoundaryReached on the promotions transport is handled at its time",
        "The consumers reindex the product and invalidate its storefront cache; source seed is acked without effect; StorefrontCache and search errors are retried like the existing consumers",
        "The worker consumes outbox events scheduled payment_simulator promotions; apps/api/CLAUDE.md describes the transport, the messages and the PromotionPricesChanged consumers",
        "make -C apps/api check and make -C apps/api test pass"
      ],
      "risk": "CRITICAL",
      "depends_on": [
        "PROMOTIONS-1"
      ]
    },
    {
      "id": "PROMOTIONS-4",
      "title": "Store: promotional price and Omnibus on the tile and the product page",
      "scope": "apps/store: src/catalog (ProductTile, TileOffer, ProductDetailsView, product-json-ld, fixtures and tests), app/p if needed; docs. Spec section „Sklep (apps/store)” (tile, product page, JSON-LD, cart without the badge), decision Q11. The badge „Promocja” always comes with the line „Najniższa cena z 30 dni przed obniżką: X zł”; lowestPriorGross null shows the promotional price without both; JSON-LD price is the effective price and priceValidUntil the Europe/Warsaw date of endsAt − 1 s. Not included: cart and checkout (unchanged).",
      "acceptance_criteria": [
        "Vitest: tile with fromPromotion shows the badge and the Omnibus line, after choosing a promoted variant in the tile its Omnibus line, without promotion neither; lowestPriorGross null shows neither",
        "Vitest: product page before and after variant choice; JSON-LD offers with the effective price and priceValidUntil, including an end at 00:00 Warsaw time giving the previous day",
        "docs/features/product-page/spec.md and apps/store/CLAUDE.md describe the promotional price, Omnibus and priceValidUntil",
        "make -C apps/store check and make -C apps/store test pass"
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PROMOTIONS-2"
      ]
    },
    {
      "id": "PROMOTIONS-5",
      "title": "Panel: promotions view and the price lock in the prices view",
      "scope": "apps/admin: menu „Promocje”, route /promotions, src/views (PromotionsView and the promotion form or details), src/promotions (list with status filter and cursors, form with name, percent, start with „od teraz”, end, Europe/Warsaw times, variant picker over GET /api/v1/admin/prices with variants or whole products, details with catalog and promotional prices and deleted variants, „Zakończ teraz”, „Usuń”, fields locked for an active promotion, 409 by type including conflictingVariantIds), PricesView (409 price-locked-by-promotion shows detail at the field without the concurrent-change message and without refreshing the row), tests including AppMenu.test.ts and PricesView.test.ts; docs. Spec section „Panel (apps/admin)” (promotions, prices), decisions Q1, Q5, Q13. Not included: discount codes (DISCOUNT-CODES #225).",
      "acceptance_criteria": [
        "Vitest: promotion list (filter, cursors), form (create, revise with version, fields locked for an active promotion, „Zakończ teraz” sends PUT with endsAt now, delete of a planned one, Europe/Warsaw conversion), variant picker (product with all variants, single variants, removal), 409 promotion-overlap marks the conflicting variants, 409 version refreshes",
        "Buttons that change promotions are visible only to admin and marketing; other staff see the list and details read-only",
        "PricesView: 409 price-locked-by-promotion shows the detail at the field, other 409 behave as before",
        "The menu has „Promocje” (AppMenu test updated); apps/admin/CLAUDE.md describes the promotions view",
        "make -C apps/admin check and make -C apps/admin test pass"
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PROMOTIONS-1"
      ]
    },
    {
      "id": "PROMOTIONS-6",
      "title": "Demo promotions and the E2E test of a promotion",
      "scope": "apps/api: fixtures/promotions.yaml (3–4 promotions on variant key patterns, start at seed time, end in 30 days, avoiding the E2E products and the category Bieganie > Buty biegowe), pricing:seed-demo loads them through PlanPromotion with actor null and source seed after the prices, validated before the reset, DbalPricingReset clears promotions and the promotions queue, a fixtures contract test of the exclusion list; e2e: tests/promotions.spec.ts on its own demo product and helpers in e2e/support (end an active promotion of the test product through the panel API at start and in finally); docs. Spec sections „Dane demo (make seed)” (promotions), „Testy” (E2E promotion part), acceptance criteria 1–3 and 7. Not included: discount codes (DISCOUNT-CODES #225).",
      "acceptance_criteria": [
        "After make seed the store has active promotions with the badge and the Omnibus line equal to the catalog price",
        "A pattern without a variant ends the seed with an error and no data change; the reset clears promotions and the promotions queue; the fixtures contract test fails for a promotion on an E2E product",
        "E2E promotions.spec.ts: marketing creates a promotion „od teraz” on the test product in the panel, the product page and the listing show the promotional price and the Omnibus line equal to lowestPriorGross from GET /api/v1/product-offers within 70 s; the test can run twice on the same stack",
        "apps/api/CLAUDE.md describes pricing:seed-demo with promotions",
        "make -C apps/api check, make -C apps/api test and make e2e pass"
      ],
      "risk": "STANDARD",
      "depends_on": [
        "PROMOTIONS-3",
        "PROMOTIONS-4",
        "PROMOTIONS-5"
      ]
    }
  ]
}
```
