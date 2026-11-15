## Review VARIANTS-1 (FULL)

- **SHA:** HEAD `4fd2a9928796a947a6c4d543f6b1c90190e17282`, base (merge-base) `bcbd1d7d32768e4f554cdad30ca6977410a6953f`. HEAD was checked at the start and end of the review, it did not change, and the tree is clean. Diff: 66 files, 2 commits (31baad3, 4fd2a99).
- **Mode:** FULL
- **Verdict: PASS.** There are no BLOCKER or IMPORTANT findings, only 5 Minor ones. I did not change any code. I did not run tests myself and relied on the orchestrator's results (check, test, schema:validate, migrations diff, seed).

### Three required checks

**1. Existing tests: no problems.** These tests existed in base and the diff changes them:
- DbalCatalogResetTest
- DbalProductSearchSourceTest
- TaxClassMigrationTest
- ProductVersionTest
- DoctrineProductRepositoryTest
- Inventory/CatalogFixture
- EventFlowTest
- GetCatalogStatisticsHandlerTest
- ProductTest
- CreateProductHandlerTest
- DemoCatalogExpanderTest
- DemoCatalogLoaderTest
- ProductFactory
- InMemoryProductRepository

Every change does one of three things:
- adapts the test to the new signature of `Product::create`/`reconstitute`/`CreateProduct`;
- adds the `variant_axes` column (NOT NULL, no default) to direct INSERTs;
- adds assertions (DbalCatalogResetTest also checks that `catalog_product_variant` is cleared).

No test was removed or disabled (no skip/only/markTestSkipped), and no expected value changed. One thing to note: `testDuplicateSlugThrowsWithThatSlug` itself did not change, but it now goes through a different branch of the code (Minor M1).

**2. Scope: no IMPORTANT findings.** Changes outside "apps/api Catalog" all have a reason:
- `docs/features/walking-skeleton/spec.md` is a documentation update required by CLAUDE.md ("dokumentacja w tym samym commicie").
- `tests/Integration/Inventory/CatalogFixture.php` and `tests/Integration/Shared/Messaging/EventFlowTest.php` had to be adjusted to the NOT NULL column and the `CreateProduct` signature.
- `config/services.yaml` wires the port.

Nothing from Pricing, Inventory, Cart, store or admin was touched. The old Catalog queries were not removed.

**3. RED before implementation: passed for domain and application logic.** I checked the log (19 entries):
- Domain (0 → 1): no production file was dirty during RED, and the RED output names the tests.
- CreateProductHandler (2 → 3): the handler was not dirty during RED.
- Migration (4 → 6): the migration file did not exist during RED.
- DoctrineProductRepository/schema (5 → 6): the repository, record and migration were not dirty.
- Expander/loader (7 → 9): the expander, DemoProduct and Loader were not dirty.
- Query unit tests (11 → 13): head 31baad3, nothing dirty.

No RED was recreated after the fact. There are two gaps in the infrastructure tests, which CLAUDE.md does not require TDD for. Both are reported as Minor M4.

### Findings

**M1 (Minor) – the duplicate-slug check in the expander can no longer be reached, and the existing test changed meaning without anyone noticing.**
- Where: `apps/api/src/Catalog/Infrastructure/Demo/DemoCatalogExpander.php:52-60`.
- The variant key is checked before the slug. In this task the key is always `<slug>/standard`, so a repeated slug always hits the variant key check first, and the `product slug ... is produced more than once` branch never runs.
- `testDuplicateSlugThrowsWithThatSlug` (`tests/Unit/Catalog/Demo/DemoCatalogExpanderTest.php:101-111`) still passes only because the variant key message also contains the slug. Proof: RED entry 7 shows the old slug message, and after the change the slug test asserts the same substring.
- The new `testRepeatedVariantKeyThrowsWithThatKey` cannot tell a repeated key apart from a repeated slug.
- Suggestion: check the slug first. Then a key-uniqueness test only makes sense with axes (VARIANTS-5), or with two different slugs giving the same key.

**M2 (Minor) – the key for a label whose slug is empty is not defined.**
- Where: `apps/api/src/Catalog/Domain/Product/VariantKey.php:25`.
- If a label has no letters or digits after `Slug::slugify`, the result is `<slug>/` (for example values `—`, `?`, `+`, `№`). Letters with diacritics other than Polish disappear: `Café` becomes `caf`, and `Ü` alone becomes an empty string.
- The spec does not cover this case, and `VariantKeyTest` does not test it.
- One such variant gets the key `p/` and UUID v5 of `variant:p/`. Two such variants in one product only collide when the expander checks for repeated keys (VARIANTS-5).
- This does not affect this task, since only default variants exist. Suggestion: reject an empty label slug in `VariantKey::of` or in the expander, and add a test before VARIANTS-5.

**M3 (Minor) – the comment overstates how far PHP's and PostgreSQL's lowercasing agree.**
- Where: `apps/api/src/Catalog/Domain/Product/VariantValues.php:89-90`.
- The comment says `mb_strtolower` "agrees with the lower() of PostgreSQL". That is shown only for Polish and German letters (`testDatabaseLowerCaseAgreesWithTheDomainComparison`). PHP uses full Unicode case mapping, while PostgreSQL's `lower()` depends on the glibc locale of the database (en_US.utf8 in `postgres:18.6`).
- The two differ for letters such as `İ`, and for the Greek final sigma (`ΑΣ` becomes `ας` in PHP but `ασ` in PG). In that case the domain accepts the variant, the `values_key` index rejects it, and the repository throws a raw `UniqueConstraintViolationException` (500, not mapped to `DuplicateVariantValues`).
- The spec only requires Polish letters and only the seed writes data, so this is Minor. Suggestion: narrow the comment to what the test actually proves.

**M4 (Minor, process) – gaps in the evidence log for the integration tests.**
- (a) The RED for `Integration.Catalog.Query.VariantQueriesTest` (entry 14) is a container build failure in `init-test` (`Cannot autowire ... VariantSource`). Its output contains no test names. `DbalVariantSource` did not exist then, so the RED was real, but it was not at test level.
- (b) The last logged run of `DoctrineProductRepositoryTest` (entry 6) ended with exit 2. The failure was `EntityIdentityCollisionException` in `testVariantIdOfAnotherProductThrowsVariantAlreadyExistsAndLeavesNoRows`. The fix (`refuseVariantIdOfAnotherKnownProduct`, `DoctrineProductRepository.php:44,113-122`) came after that run, and the log has no GREEN entry for the suite.
- Only the orchestrator's full `make -C apps/api test` (2028 tests) confirms the suite now passes.
- This is not a missing RED in domain or application logic, so it is not IMPORTANT.

**M5 (Minor) – variants read back from the database are validated again.**
- Where: `GetVariantSummariesHandler.php:62`, `ListProductVariantsHandler.php:62`, `ListVariantIdentitiesHandler.php:33`.
- These handlers rebuild `VariantValues::fromList` from database data to compute `label`/`key`. A row written around the domain (for example a value over 40 characters) makes a read query fail with `InvalidVariantValues` (InvalidInput, so 422 through the API in VARIANTS-2..4).
- This follows the existing pattern of rebuilding through the domain, and the domain is the only writer. Mentioned for the contracts of VARIANTS-2..4. No change needed now.

### Checked, no findings
- **Migration** (`migrations/Version20261104120000CreateCatalogProductVariants.php`):
  - `ADD ... DEFAULT '[]'` followed by `DROP DEFAULT` matches the mapping, which has no database default.
  - `values_key GENERATED ALWAYS AS (lower(option_values::text)) STORED`, the unique indexes `(product_id, position)` and `(product_id, values_key)`, and the FK with `ON DELETE CASCADE` all match the spec.
  - It inserts a default variant with `id = product_id`, `position 0` and `[]` for every product. The PK name `catalog_product_variant_pkey` is PostgreSQL's default name.
  - `down` drops the table and the column, which loses variant data by nature; its test checks that the products stay.
  - The migration test checks every product column (`to_jsonb(p) - 'variant_axes'`), including `version`, and checks that categories and their assignments are unchanged.
- **DoctrineProductRepository:**
  - Saving the same product again with unchanged variants writes nothing.
  - A change deletes the old rows first (orphanRemoval + flush), then inserts the new ones. A reused id, position or combination works, which a test confirms.
  - If only the variants changed, the version goes up through `incrementVersion`.
  - Exception mapping: slug, then the variant PK (`catalog_product_variant_pkey` does not contain `catalog_product_pkey`), then the product PK. Violations of `values_key`/`position` are rethrown as-is, as the spec intends. Both paths are tested: the identity-map path and the competing-commit path.
  - When the product is read back, variants come in position order (`OrderBy` plus `addOrderBy` in `all()`).
- **Domain:** 0–2 axes and 1–100 variants; the number of values equals the number of axes; without axes exactly one variant. Duplicates are detected after trimming and ignoring case (`json_encode` of the comparison key, so `["a b","c"]` and `["a","b c"]` do not collide). There is no framework in Domain.
- **Queries:**
  - Each makes one statement per call: `= ANY(uuid[])` on the PK, `ORDER BY p.id, v.position` on the unique index, and a keyset over `v.id` with two variants so the index stays usable. Tests count statements for 100 ids.
  - Results follow the order of the request; repeated ids are returned once, and ids not found are left out.
  - The shapes match the spec: `GetVariant` returns `{variantId, productId, taxClass}|null`; `ListProductVariants` returns `{productId, variantAxes, variants[{variantId, label}]}`; `ListVariantIdentities` returns `{variantId, key, taxClass}`.
  - The tests use variant ids different from product ids, including checks that a product id is not accepted as a variant id.
  - `label` and `key` each come from one function (`ProductVariant::label` delegating to `VariantValues::label`; `VariantKey::of`, used by both the expander and the query).
- `DbalCatalogReset` truncates the variants.
- `CatalogSchemaTest` plus the orchestrator's schema:validate and empty migrations diff cover the mapping of the generated column.

### Relevant files
- `<home>/Development/claude/shop-VARIANTS-1/apps/api/src/Catalog/Infrastructure/Demo/DemoCatalogExpander.php`
- `<home>/Development/claude/shop-VARIANTS-1/apps/api/src/Catalog/Domain/Product/VariantKey.php`
- `<home>/Development/claude/shop-VARIANTS-1/apps/api/src/Catalog/Domain/Product/VariantValues.php`
- `<home>/Development/claude/shop-VARIANTS-1/apps/api/src/Catalog/Infrastructure/Persistence/Doctrine/DoctrineProductRepository.php`
- `<home>/Development/claude/shop-VARIANTS-1/apps/api/migrations/Version20261104120000CreateCatalogProductVariants.php`
- `<home>/Development/claude/shop-VARIANTS-1/apps/api/tests/Unit/Catalog/Demo/DemoCatalogExpanderTest.php`
- `<home>/.local/state/ao-variants/tasks/VARIANTS-1/delivery-state.json.evidence.jsonl`