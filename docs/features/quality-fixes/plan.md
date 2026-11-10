# QUALITY-FIXES – plan zadań

Specyfikacja: `docs/features/quality-fixes/spec.md`. Każde zadanie realizuje `/delivery` jako osobny PR. Plan jest
dokumentem roboczym: po ukończeniu epica usuń go.

## Status

| Fala | Zadania | Status |
|---|---|---|
| 1 | QUALITY-FIXES-1, QUALITY-FIXES-2, QUALITY-FIXES-3, QUALITY-FIXES-4, QUALITY-FIXES-5, QUALITY-FIXES-6, QUALITY-FIXES-7, QUALITY-FIXES-8, QUALITY-FIXES-9, QUALITY-FIXES-10 | |

Stan prób zadań zgłoszonych do AO: `ao status`. Kolumnę „Status” (PR, zmergowane) uzupełnia użytkownik przy
integracji zadań fali.

## Zadania

```json
{
  "schema_version": 1,
  "epic": "QUALITY-FIXES",
  "spec": "docs/features/quality-fixes/spec.md",
  "tasks": [
    {
      "id": "QUALITY-FIXES-1",
      "title": "Cart API: one violation for any malformed UUID in cart requests (#113)",
      "scope": "Spec section #113. apps/api/src/Cart/UI/Http (AddCartItemRequest.variantId, MergeGuestCartRequest.guestCartId) and apps/api/tests/Functional/Cart (CartRequestViolationCases). No OpenAPI change. Other UUID validations outside Cart requests are out of scope.",
      "acceptance_criteria": [
        "Nil, max, wrong version and variant, and a one-character-too-long UUID with such bits in variantId and guestCartId each give 422 with exactly one violation of the field, with the Polish message of an invalid UUID and the right propertyPath.",
        "The cases are in apps/api/tests/Functional/Cart/CartRequestViolationCases.php for both fields; valid UUIDs behave as today."
      ],
      "risk": "FAST",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-2",
      "title": "Catalog API: category filter of the store list ignores the case of the UUID (#197)",
      "scope": "Spec section #197. apps/api/src/Catalog/Application/Query/ListProductsHandler.php normalises the category once with CategoryId::fromString(...)->toString() and uses only that value (facets, tree lookup, index query); functional tests in apps/api/tests/Functional/Catalog. SearchBackofficeProductsHandler unchanged (already normalises).",
      "acceptance_criteria": [
        "GET /api/v1/products?category=<UUID in upper case> gives the same products, total and facets as the same UUID in lower case (test RED before the change).",
        "A regression test of the backoffice product list with an upper-case category passes; the task report says it passed before the change too.",
        "An invalid category UUID and an unknown category behave as today."
      ],
      "risk": "FAST",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-3",
      "title": "Payment: refuse provider webhooks when the webhook secret is empty (#213)",
      "scope": "Spec section #213. apps/api/src/Payment (DemoCardProvider, DemoTransferProvider, InvalidWebhook with a new reason), tests in apps/api/tests/Unit/Payment (Demo*WebhookTest) and, if the controller response needs it, apps/api/tests/Functional/Payment; docs/features/payment/spec.md gets the rule. Container start, chart and init-env.sh unchanged.",
      "acceptance_criteria": [
        "With an empty webhook secret each demo adapter refuses every webhook with InvalidWebhook carrying a distinct 'empty webhook secret' reason, before checking the signature; a webhook signed with an empty key is refused.",
        "WebhookController logs that reason at warning level like other reasons and answers 400 as for a bad signature; the payment is not changed.",
        "With a non-empty secret the adapters behave as today.",
        "docs/features/payment/spec.md describes the rule."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-4",
      "title": "Catalog: control characters and Unicode whitespace in product texts (#247)",
      "scope": "Spec section #247 and the #247 decision. One text-rule class in apps/api/src/Catalog/Domain/Product (own copy, modelled on Ordering ControlCharacters) used by ProductName, ShortDescription, ProductImage, VariantAxes, VariantValues and by apps/api/src/Catalog/UI/Http/Validation/ValidProductWriteValidator, which takes length limits from public domain constants. Tests in apps/api/tests/Unit/Catalog and apps/api/tests/Functional/Catalog; docs/features/catalog-admin/spec.md describes the text rules. No change to the unique key of variants (QUALITY-FIXES-5).",
      "acceptance_criteria": [
        "NUL, BEL, U+2028 and invalid UTF-8 in name, shortDescription, images[].alt, variantAxes[] or a variant value give 422 at that field with a Polish 'contains forbidden characters' message on POST and PUT of a product; LF is allowed only in shortDescription and is saved.",
        "Only Unicode whitespace (e.g. NBSP) in name, images[].alt, an axis or a variant value gives 422 'required' at that field; in shortDescription it is trimmed to empty and saved.",
        "Value objects trim Unicode whitespace and refuse forbidden characters with the same rule as the validator (unit tests of the rule class and value objects).",
        "ValidProductWriteValidator has no length literals duplicating domain limits.",
        "docs/features/catalog-admin/spec.md describes the text rules."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-5",
      "title": "Catalog: variant uniqueness key computed by the domain, not by PostgreSQL lower() (#248)",
      "scope": "Spec section #248 and the #248 decision. New migration in apps/api/migrations (values_key DROP EXPRESSION and DROP NOT NULL, unique index kept, down() restores the generated column), apps/api/src/Catalog/Infrastructure/Persistence (ProductVariantRecord maps values_key as writable; DoctrineProductRepository writes json_encode(comparisonKey(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) for every variant), tests in apps/api/tests/Integration/Catalog and apps/api/tests/Functional/Catalog; docs/features/variants/spec.md and docs/features/walking-skeleton/spec.md updated. NOT NULL on values_key is out of scope.",
      "acceptance_criteria": [
        "Saving a product with variant pairs [\"İ\"]/[\"i\"] or [\"ΑΣ\"]/[\"ασ\"] does not give 500 (repository integration test and functional test, RED before the change).",
        "A pair differing only in ASCII case still gives 422 DuplicateVariantValues.",
        "The migration keeps existing values, does not rewrite the table, allows NULL, keeps the unique index; down() restores the generated column; the schema test of Catalog passes.",
        "An SQL insert of a variant without values_key (apps/api/tests/Integration/Inventory/CatalogFixture.php) still works.",
        "docs/features/variants/spec.md and docs/features/walking-skeleton/spec.md no longer describe a generated column nor database rejection of a duplicate by direct SQL, and describe the version-compatibility window."
      ],
      "risk": "CRITICAL",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-6",
      "title": "Ordering: Unicode line separators and whitespace in staff notes and order texts (#256)",
      "scope": "Spec section #256 and the #256 decision. apps/api/src/Ordering/Domain (ControlCharacters with /u, U+0085, U+2028, U+2029, invalid UTF-8 refused; StaffNote trims Unicode whitespace), apps/api/src/Ordering/UI/Http (ChangeOrderStatusRequest, CompleteRefundRequest use the same rule), tests in apps/api/tests/Unit/Ordering and apps/api/tests/Functional/Ordering; docs/features/order-management/spec.md and docs/features/checkout/spec.md describe the rule. Missing-key handling is QUALITY-FIXES-7.",
      "acceptance_criteria": [
        "A staff note of only NBSP is no note: cancel and return give 422 'required' as for an empty note; in the domain and in both requests.",
        "A note with U+2028, U+2029 or U+0085 gives 422 at note, in the domain and in both requests.",
        "Single-line Ordering fields using ControlCharacters (contact and address of an order) refuse U+0085, U+2028, U+2029 and invalid UTF-8 with their existing messages.",
        "docs/features/order-management/spec.md and docs/features/checkout/spec.md describe the rule."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-7",
      "title": "API: Polish 'required' violation for a missing body key in seven backoffice endpoints (#257)",
      "scope": "Spec section #257 and the #257 decision. One mechanism in apps/api/src/Shared/UI/Http used instead of REQUIRE_ALL_PROPERTIES by ChangeOrderStatusController and CompleteRefundController (Ordering), CorrectStockController and ReceiveStockController (Inventory), ChangePriceController, PlanPromotionController and RevisePromotionController (Pricing); Inventory DTOs get Polish messages and Assert\\Sequentially. Tests in apps/api/tests/Unit/Shared and apps/api/tests/Functional/{Ordering,Inventory,Pricing}; apps/api/CLAUDE.md describes the mechanism. OpenAPI unchanged; DiscountCodeBodyReader unchanged.",
      "acceptance_criteria": [
        "In each of the seven endpoints, each body key omitted on its own gives 422 with one violation 'To pole jest wymagane.' at that key, also for keys that accept null; {} gives that violation for every key.",
        "A key sent as null behaves as today; malformed JSON gives 400 and another format 415 as today.",
        "In CorrectStock and ReceiveStock, null and a wrong type of each field give exactly one Polish violation.",
        "Unit test of the mechanism covers a missing key, null, malformed JSON and another format.",
        "The exported OpenAPI files do not change; apps/api/CLAUDE.md describes the mechanism."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-8",
      "title": "E2E: find and fix the cause of the flaky checkout-customer payment test (#339)",
      "scope": "Spec section #339. e2e/tests/checkout-customer.spec.ts and e2e/support; when the cause is in the product, the fix goes to apps/store/src/checkout or apps/api/src/Payment with a test. Raising the timeout without an established cause is not a fix.",
      "acceptance_criteria": [
        "The PR describes the established cause (webhook handling time in the worker, refresh of the payment state on the confirmation page after a rejection and a new choice, or order of events) and fixes it in code or in the test.",
        "checkout-customer.spec.ts passes with --repeat-each 20 on the production build (STORE_MODE=production) after make seed before the run; the result is in the task report.",
        "A product fix has its own test in the affected application."
      ],
      "risk": "STANDARD",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-9",
      "title": "Store checkout: status region does not claim a code that came off the order (#340)",
      "scope": "Spec section #340. apps/store/src/checkout (CheckoutPage and its component test) and e2e/tests/discount-codes.spec.ts. BFF and API unchanged.",
      "acceptance_criteria": [
        "When the code comes off the order other than through 'Usuń kod' and the preview is read without it (discount_code_rejected, total_changed), checkout-code-status is empty and the field error is announced by its alert.",
        "When the preview cannot be read and the code stays, checkout-code-status still says the code is applied.",
        "Component test and E2E in e2e/tests/discount-codes.spec.ts: after 409 discount_code_rejected (code below minimum, disabled code) checkout-code-status does not contain 'Zastosowano'."
      ],
      "risk": "FAST",
      "depends_on": []
    },
    {
      "id": "QUALITY-FIXES-10",
      "title": "Admin: heading of an existing discount code, promotion and product that failed to load (#341)",
      "scope": "Spec section #341 and the #341 decision. apps/admin/src/views (DiscountCodeFormView, PromotionFormView, ProductFormView) and their tests in apps/admin/tests. No API change.",
      "acceptance_criteria": [
        "On a route with an id, before load and on 404 or 500, the headings are 'Kod rabatowy', 'Promocja' and 'Produkt', never 'Nowy …'/'Nowa …'.",
        "After a successful load the headings are as today (code or name); the create routes still show 'Nowy kod rabatowy', 'Nowa promocja' and 'Nowy produkt'.",
        "Component tests of the three views cover 404 and 500 on a route with an id and the create route."
      ],
      "risk": "FAST",
      "depends_on": []
    }
  ]
}
```
