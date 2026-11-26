<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Copy\Sql;
use App\DemoData\Verify\PricingVerifier;
use App\DemoData\Verify\SeedCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * demo-data:verify on the generated catalog of the profile small and on violations injected into it.
 */
final class VerifyStepTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    public function testItPassesOnTheGeneratedData(): void
    {
        $this->allSteps();

        $tester = $this->verify();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('consistent', $tester->getDisplay());
    }

    public function testItListsAPublishedProductWithoutAVariant(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        $row = $connection->fetchAssociative("SELECT id, slug FROM catalog_product WHERE status = 'published' ORDER BY id LIMIT 1");
        self::assertIsArray($row);
        $connection->executeStatement('DELETE FROM catalog_product_variant WHERE product_id = ?', [$row['id']]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A published product has no variant', $tester->getDisplay());
        self::assertStringContainsString(Sql::string($row['slug']), $tester->getDisplay());
    }

    public function testADraftWithoutAVariantIsNotAViolation(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        $id = $connection->fetchOne("SELECT id FROM catalog_product WHERE status = 'draft' ORDER BY id LIMIT 1");
        // The prices of the variants go with them (a price without a variant is a violation of its own).
        $connection->executeStatement('DELETE FROM pricing_price_history WHERE variant_id IN (SELECT id FROM catalog_product_variant WHERE product_id = ?)', [$id]);
        $connection->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id IN (SELECT id FROM catalog_product_variant WHERE product_id = ?)', [$id]);
        $connection->executeStatement('DELETE FROM catalog_product_variant WHERE product_id = ?', [$id]);

        self::assertSame(Command::SUCCESS, $this->verify()->getStatusCode());
    }

    public function testItListsAProductCountThatDiffersFromTheProfile(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement('DELETE FROM catalog_product WHERE id = (SELECT id FROM catalog_product ORDER BY id LIMIT 1)');

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('has 2000 products of the generator, the table has 1999', $tester->getDisplay());
    }

    public function testItListsAProductOfTheTailWithoutTheNumberInItsSlug(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement("UPDATE catalog_product SET slug = 'bez-numeru' WHERE id = (SELECT id FROM catalog_product ORDER BY id LIMIT 1)");

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('without the number of the product', $tester->getDisplay());
    }

    public function testItListsASlugNumberThatRepeats(): void
    {
        $this->allSteps();
        $ids = $this->connection()->fetchFirstColumn('SELECT id FROM catalog_product ORDER BY id LIMIT 2');
        $this->connection()->executeStatement("UPDATE catalog_product SET slug = 'powtorzony-a-17' WHERE id = ?", [$ids[0]]);
        $this->connection()->executeStatement("UPDATE catalog_product SET slug = 'powtorzony-b-17' WHERE id = ?", [$ids[1]]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('repeat a product number', $tester->getDisplay());
    }

    public function testItListsAGeneratedProductThatTookTheSlugOfASeedProduct(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement("UPDATE catalog_product SET slug = 'buty-szosowe-tarvik-asfalt-200' WHERE id = (SELECT id FROM catalog_product ORDER BY id LIMIT 1)");

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The slug of a catalog.yaml product belongs to another product: buty-szosowe-tarvik-asfalt-200', $tester->getDisplay());
    }

    public function testASeedProductBesideTheGeneratedOnesIsNotCountedAsGeneratedAndIsNotAViolation(): void
    {
        $this->allSteps();
        $seedId = '9f0d1c7c-6a0b-5ad7-8ce1-8b0d6f4d3a21';
        $category = $this->connection()->fetchOne('SELECT id FROM catalog_category LIMIT 1');
        $this->connection()->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, variant_axes, status, primary_category_id, updated_at) VALUES (?, 'buty-szosowe-tarvik-asfalt-200', 'x', '', 'standard', '[]', '[]', 'draft', ?, now())",
            [$seedId, $category],
        );

        $tester = $this->verify();

        // The id is not the one the seed gives the product, so it is reported as taken by another product.
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('belongs to another product', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, string}> the statement that spoils the product with the lowest id (":product" is its id, the product of a variant too), the violation that names it
     */
    public static function spoiledContent(): iterable
    {
        yield 'a product without a brand' => ["UPDATE catalog_product SET brand = '' WHERE id = :product", 'A generated product has no brand'];
        yield 'a product without a description' => ["UPDATE catalog_product SET description = '' WHERE id = :product", 'A generated product has no description'];
        yield 'a product without parameters' => ["UPDATE catalog_product SET parameters = '[]'::jsonb WHERE id = :product", 'A generated product has no parameters'];
        yield 'a variant without a SKU' => ['UPDATE catalog_product_variant SET sku = NULL WHERE id = (SELECT id FROM catalog_product_variant WHERE product_id = :product ORDER BY position LIMIT 1)', 'A generated variant has no SKU'];
        yield 'a variant without an EAN' => ['UPDATE catalog_product_variant SET ean = NULL WHERE id = (SELECT id FROM catalog_product_variant WHERE product_id = :product ORDER BY position LIMIT 1)', 'A generated variant has no EAN'];

        // The API gives no code as an empty text (the receiver does not tell NULL from ''), so a generator fault that writes
        // an empty or a blank text is a missing value as well: the rule of the texts (T3), not the ASCII `btrim`.
        $firstVariant = '(SELECT id FROM catalog_product_variant WHERE product_id = :product ORDER BY position LIMIT 1)';
        yield 'a variant with an empty SKU' => ["UPDATE catalog_product_variant SET sku = '' WHERE id = $firstVariant", 'A generated variant has no SKU'];
        yield 'a variant with an empty EAN' => ["UPDATE catalog_product_variant SET ean = '' WHERE id = $firstVariant", 'A generated variant has no EAN'];
        yield 'a variant with a SKU of spaces' => ["UPDATE catalog_product_variant SET sku = '   ' WHERE id = $firstVariant", 'A generated variant has no SKU'];
        yield 'a variant with a SKU of no-break spaces' => ["UPDATE catalog_product_variant SET sku = '\u{00A0}\u{00A0}' WHERE id = $firstVariant", 'A generated variant has no SKU'];
        yield 'a variant with an EAN of an em space and a zero width space' => ["UPDATE catalog_product_variant SET ean = '\u{2003}\u{200B}' WHERE id = $firstVariant", 'A generated variant has no EAN'];
        yield 'a product with a brand of a space' => ["UPDATE catalog_product SET brand = ' ' WHERE id = :product", 'A generated product has no brand'];
        yield 'a product with a brand of a no-break space' => ["UPDATE catalog_product SET brand = '\u{00A0}' WHERE id = :product", 'A generated product has no brand'];
        yield 'a product with a brand of invisible characters' => ["UPDATE catalog_product SET brand = '\u{3000}\u{200D}\u{FE0F}\u{3164}' WHERE id = :product", 'A generated product has no brand'];
        yield 'a product with a description of a no-break space' => ["UPDATE catalog_product SET description = '\u{00A0}' WHERE id = :product", 'A generated product has no description'];
        yield 'a product with a parameter without a name' => ['UPDATE catalog_product SET parameters = \'[{"name":"","value":"2,4 kg"}]\'::jsonb WHERE id = :product', 'A generated product has a parameter without a name or a value'];
        yield 'a product with a parameter of a blank value' => ["UPDATE catalog_product SET parameters = '[{\"name\":\"Waga\",\"value\":\"\u{00A0}\"}]'::jsonb WHERE id = :product", 'A generated product has a parameter without a name or a value'];
        yield 'a product with a parameter without the key of a value' => ['UPDATE catalog_product SET parameters = \'[{"name":"Waga"}]\'::jsonb WHERE id = :product', 'A generated product has a parameter without a name or a value'];

        // A name or a value that is no JSON string (->> would turn a number or a boolean into a visible text, and a missing
        // key into SQL NULL, which a plain <> would not count).
        $parameter = 'A generated product has a parameter without a name or a value';
        $withParameters = static fn (string $json): string => "UPDATE catalog_product SET parameters = '$json'::jsonb WHERE id = :product";
        yield 'a parameter with a number for a name' => [$withParameters('[{"name":5,"value":"2,4 kg"}]'), $parameter];
        yield 'a parameter with a number for a value' => [$withParameters('[{"name":"Waga","value":5}]'), $parameter];
        yield 'a parameter with a boolean for a value' => [$withParameters('[{"name":"Waga","value":true}]'), $parameter];
        yield 'a parameter with an object for a value' => [$withParameters('[{"name":"Waga","value":{"kg":"2,4"}}]'), $parameter];
        yield 'a parameter with an array for a name' => [$withParameters('[{"name":["Waga"],"value":"2,4 kg"}]'), $parameter];
        yield 'a parameter with a JSON null for a name' => [$withParameters('[{"name":null,"value":"2,4 kg"}]'), $parameter];
        yield 'a parameter with a JSON null for a value' => [$withParameters('[{"name":"Waga","value":null}]'), $parameter];
        yield 'a parameter without the key of a name' => [$withParameters('[{"value":"2,4 kg"}]'), $parameter];
        yield 'parameters as an object' => [$withParameters('{}'), 'A generated product has no parameters'];
        yield 'parameters as a JSON null' => [$withParameters('null'), 'A generated product has no parameters'];
        yield 'a parameter that is no object' => [$withParameters('[5]'), $parameter];
    }

    #[DataProvider('spoiledContent')]
    public function testItListsAGeneratedProductOrVariantWithoutTheContentFieldsAndNamesTheViolation(string $statement, string $violation): void
    {
        $this->allSteps();
        $connection = $this->connection();
        $row = $connection->fetchAssociative('SELECT id, slug FROM catalog_product ORDER BY id LIMIT 1');
        self::assertIsArray($row);
        $connection->executeStatement(str_replace(':product', "'".Sql::string($row['id'])."'", $statement));

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1 violation(s)', $tester->getDisplay(), 'Only the spoiled row is a violation.');
        self::assertStringContainsString($violation.': '.Sql::string($row['slug']), $tester->getDisplay());
    }

    public function testASeedProductAndItsVariantsWithoutTheContentFieldsAreNotViolations(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        // A product of fixtures/catalog.yaml, with the id the seed gives it, is not a generated one: the seed writes its
        // own content (and a variant of the seed has the codes of the seed), whatever the verification of the tail asks.
        $seed = new SeedCatalog(\dirname(__DIR__, 3).'/fixtures/catalog.yaml')->products();
        $slug = array_key_first($seed);
        self::assertIsString($slug);
        $category = $connection->fetchOne('SELECT id FROM catalog_category LIMIT 1');
        $connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, brand, description, parameters, tax_class, images, variant_axes, status, primary_category_id, updated_at) VALUES (?, ?, 'x', '', '', '', '[]', 'standard', '[]', '[]', 'draft', ?, now())",
            [$seed[$slug], $slug, $category],
        );
        $connection->executeStatement("INSERT INTO catalog_product_variant (id, product_id, position, option_values, values_key, sku, ean) VALUES (gen_random_uuid(), ?, 0, '[]', '[]', NULL, NULL)", [$seed[$slug]]);

        $display = $this->verify()->getDisplay();

        foreach (['has no brand', 'has no description', 'has no parameters', 'has no SKU', 'has no EAN'] as $violation) {
            self::assertStringNotContainsString($violation, $display);
        }
    }

    public function testItListsAnIncompleteCategoryTree(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement("UPDATE catalog_product SET primary_category_id = (SELECT id FROM catalog_category WHERE slug = 'startowe') WHERE true");
        $this->connection()->executeStatement('DELETE FROM catalog_product_category');
        $this->connection()->executeStatement("DELETE FROM catalog_category WHERE slug = 'kolce'");
        $this->connection()->executeStatement("DELETE FROM catalog_category WHERE slug = 'kolce'");

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('category tree of the generator is incomplete', $tester->getDisplay());
    }

    public function testItListsAVariantWithoutACurrentPrice(): void
    {
        $this->allSteps();
        $row = $this->connection()->fetchAssociative('SELECT v.id, p.slug FROM catalog_product_variant v JOIN catalog_product p ON p.id = v.product_id ORDER BY v.id LIMIT 1');
        self::assertIsArray($row);
        $this->connection()->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id = ?', [$row['id']]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A variant has no current price', $tester->getDisplay());
        self::assertStringContainsString(Sql::string($row['slug']), $tester->getDisplay());
    }

    public function testItListsAVariantWithoutAHistoryEntry(): void
    {
        $this->allSteps();
        $id = $this->connection()->fetchOne('SELECT variant_id FROM pricing_variant_price ORDER BY variant_id LIMIT 1');
        $this->connection()->executeStatement('DELETE FROM pricing_price_history WHERE variant_id = ?', [$id]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A variant has no price history entry', $tester->getDisplay());
    }

    public function testItListsACurrentPriceThatIsNotTheLastHistoryEntry(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement('UPDATE pricing_variant_price SET gross_amount = gross_amount + 100, net_amount = net_amount + 81, vat_amount = vat_amount + 19 WHERE variant_id = (SELECT variant_id FROM pricing_variant_price ORDER BY variant_id LIMIT 1)');

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The current price of a variant is not the last entry of its history', $tester->getDisplay());
    }

    public function testTheSampleConditionDoesNotOverflowOnTheSmallestHash(): void
    {
        // hashtext() can return -2147483648, whose abs() does not fit an integer.
        $smallest = '(-2147483648)::int';
        $connection = $this->connection();

        self::assertSame(1, $this->int($connection->fetchOne('SELECT 1 WHERE '.PricingVerifier::inSample($smallest, 2))));
        self::assertFalse($connection->fetchOne('SELECT 1 WHERE '.PricingVerifier::inSample($smallest, 3)));
        self::assertSame(1, $this->int($connection->fetchOne('SELECT 1 WHERE '.PricingVerifier::inSample('(2147483647)::int', 2147483647))));
    }

    public function testALargeProfileChecksTheLastEntryOfASampleOfTheVariants(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        // --products 600000 makes the sample one variant in two; the counts of the profile then differ, which is not what is looked at.
        $inSample = $connection->fetchOne('SELECT variant_id FROM pricing_variant_price WHERE '.PricingVerifier::inSample('hashtext(variant_id::text)', 2).' ORDER BY variant_id LIMIT 1');
        $outOfSample = $connection->fetchOne('SELECT variant_id FROM pricing_variant_price WHERE NOT '.PricingVerifier::inSample('hashtext(variant_id::text)', 2).' ORDER BY variant_id LIMIT 1');
        $connection->executeStatement('UPDATE pricing_variant_price SET gross_amount = gross_amount + 100, net_amount = net_amount + 81, vat_amount = vat_amount + 19 WHERE variant_id = ?', [$outOfSample]);
        self::assertStringNotContainsString('is not the last entry of its history', $this->verify(['--products' => 600_000])->getDisplay());

        $connection->executeStatement('UPDATE pricing_variant_price SET gross_amount = gross_amount + 100, net_amount = net_amount + 81, vat_amount = vat_amount + 19 WHERE variant_id = ?', [$inSample]);

        self::assertStringContainsString('is not the last entry of its history', $this->verify(['--products' => 600_000])->getDisplay());
    }

    public function testItListsAPriceThatDoesNotBreakDownByThePricingRule(): void
    {
        $this->allSteps();
        $id = $this->connection()->fetchOne('SELECT variant_id FROM pricing_variant_price ORDER BY variant_id LIMIT 1');
        $this->connection()->executeStatement('UPDATE pricing_variant_price SET net_amount = net_amount + 1, vat_amount = vat_amount - 1 WHERE variant_id = ?', [$id]);
        $this->connection()->executeStatement('UPDATE pricing_price_history SET net_amount = net_amount + 1, vat_amount = vat_amount - 1 WHERE variant_id = ?', [$id]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not break down by the rule of Pricing', $tester->getDisplay());
    }

    public function testItListsAPriceOfAVariantThatDoesNotExist(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement('INSERT INTO pricing_variant_price (variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at) SELECT gen_random_uuid(), currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at FROM pricing_variant_price LIMIT 1');

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A price belongs to a variant that does not exist', $tester->getDisplay());
    }

    public function testItListsAGeneratedPromotionThatHasNotEnded(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement("UPDATE pricing_promotion SET ends_at = ?::timestamptz + interval '1 day' WHERE id = (SELECT id FROM pricing_promotion ORDER BY id LIMIT 1)", [self::ANCHOR]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A generated promotion has not ended before the anchor', $tester->getDisplay());
    }

    public function testItListsAGeneratedPromotionWithMoreThanAThousandVariants(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) SELECT (SELECT id FROM pricing_promotion ORDER BY id LIMIT 1), gen_random_uuid() FROM generate_series(1, 1001)');

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A generated promotion has more than 1000 variants', $tester->getDisplay());
    }

    public function testItListsAVariantInTwoPromotionsWithOverlappingPeriods(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        $first = $connection->fetchAssociative('SELECT id, starts_at, ends_at FROM pricing_promotion ORDER BY id LIMIT 1');
        $second = $connection->fetchOne('SELECT id FROM pricing_promotion ORDER BY id DESC LIMIT 1');
        self::assertIsArray($first);
        $connection->executeStatement('UPDATE pricing_promotion SET starts_at = ?, ends_at = ? WHERE id = ?', [$first['starts_at'], $first['ends_at'], $second]);
        $connection->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) SELECT ?, variant_id FROM pricing_promotion_variant WHERE promotion_id = ? ON CONFLICT DO NOTHING', [$second, $first['id']]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A variant is in two generated promotions with overlapping periods', $tester->getDisplay());
    }

    public function testItListsAPromotedVariantThatHadNoPriceWhenThePromotionStarted(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        $variantId = $connection->fetchOne('SELECT variant_id FROM pricing_promotion_variant ORDER BY variant_id LIMIT 1');
        $connection->executeStatement("UPDATE pricing_price_history SET changed_at = ?::timestamptz - interval '1 hour' WHERE variant_id = ? AND source = 'seed'", [self::ANCHOR, $variantId]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A promoted variant has no price when its promotion starts', $tester->getDisplay());
    }

    public function testItListsAPriceChangedWhileItsVariantWasInAPromotion(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        $row = $connection->fetchAssociative('SELECT v.variant_id, p.starts_at FROM pricing_promotion_variant v JOIN pricing_promotion p ON p.id = v.promotion_id ORDER BY v.variant_id LIMIT 1');
        self::assertIsArray($row);
        $connection->executeStatement("INSERT INTO pricing_price_history (id, variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, changed_at, source, actor_id, actor_name) SELECT gen_random_uuid(), variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, ?::timestamptz + interval '1 hour', 'staff', gen_random_uuid()::text, 'Pracownik demo 1' FROM pricing_price_history WHERE variant_id = ? LIMIT 1", [$row['starts_at'], $row['variant_id']]);

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A price was changed while its variant was in a promotion', $tester->getDisplay());
    }

    public function testItListsAPromotionCountThatDiffersFromThePlan(): void
    {
        $this->allSteps();
        $this->connection()->executeStatement('DELETE FROM pricing_promotion WHERE id = (SELECT id FROM pricing_promotion ORDER BY id LIMIT 1)');

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The generator plans 40 promotions, the table has 39', $tester->getDisplay());
    }

    public function testItListsADiscountCodeThatHasNotEndedAndOneThatIsMissing(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        $connection->executeStatement("UPDATE pricing_discount_code SET ends_at = ?::timestamptz + interval '10 days' WHERE id = (SELECT id FROM pricing_discount_code ORDER BY id LIMIT 1)", [self::ANCHOR]);
        // The usages of the orders go with the code.
        $connection->executeStatement('DELETE FROM pricing_discount_code_usage WHERE code_id = (SELECT id FROM pricing_discount_code ORDER BY id DESC LIMIT 1)');
        $connection->executeStatement('DELETE FROM pricing_discount_code WHERE id = (SELECT id FROM pricing_discount_code ORDER BY id DESC LIMIT 1)');

        $tester = $this->verify();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A generated discount code has not ended before the anchor', $tester->getDisplay());
        self::assertStringContainsString('The generator plans 14 discount codes, the table has 13', $tester->getDisplay());
    }

    public function testASeedVariantWithoutAnyPriceIsNotAViolation(): void
    {
        $this->allSteps();
        $connection = $this->connection();
        $seedId = '9f0d1c7c-6a0b-5ad7-8ce1-8b0d6f4d3a22';
        $category = $connection->fetchOne('SELECT id FROM catalog_category LIMIT 1');
        $connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, variant_axes, status, primary_category_id, updated_at) VALUES (?, 'buty-szosowe-tarvik-asfalt-200', 'x', '', 'standard', '[]', '[]', 'draft', ?, now())",
            [$seedId, $category],
        );
        $connection->executeStatement("INSERT INTO catalog_product_variant (id, product_id, position, option_values, values_key) VALUES (?, ?, 0, '[]', '[]')", [$seedId, $seedId]);

        $tester = $this->verify();

        // The product has the slug of a seed product but not its id (a violation of the catalog check), and no price: the
        // price check does not count it as a generated variant.
        self::assertStringNotContainsString('A variant has no current price', $tester->getDisplay());
    }

    public function testAnUnknownProfileIsRefused(): void
    {
        $tester = $this->verify(['--demo-profile' => 'huge']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testTheAnalyzeStepRuns(): void
    {
        $this->allSteps();

        $tester = $this->step('demo-data:analyze', [], false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('catalog_product', $tester->getDisplay());
    }
}
