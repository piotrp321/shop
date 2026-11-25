<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\Ean;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The numbers of restricted circulation (RCN) of GS1 General Specifications 26.0, section 2.1.11, which the domain keeps
 * out of `gtin` (docs/features/product-data/spec.md, "EAN z puli obiegu ograniczonego (RCN)"). Every example of the table
 * of the spec is a row here, with its check digit; the codes that are no RCN are the rows that must keep their `gtin`.
 */
final class EanRestrictedCirculationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function restrictedCirculation(): iterable
    {
        // 2.1.11.1: RCN-8 with the prefix 0 or 2 (six zeros of the 14-digit form, the seventh digit is 0 or 2).
        yield 'RCN-8 with the prefix 0' => ['00000017'];
        yield 'RCN-8 with the prefix 2' => ['20000011'];
        // 2.1.11.2: RCN-13 with the GS1 prefix 04 and RCN-12 with the U.P.C. prefix 4.
        yield 'RCN-13 with the prefix 04' => ['0400000000008'];
        yield 'RCN-12 with the U.P.C. prefix 4' => ['400000000008'];
        // 2.1.11.3: RCN-12 with the U.P.C. prefix 0, the six extreme numbers of the figure 2-1.
        yield 'RCN-12 with the U.P.C. prefix 0, LAC' => ['001000000052'];
        yield 'RCN-12 with the U.P.C. prefix 0, the last prefix of RZSC' => ['007999000097'];
        yield 'RCN-12 with the U.P.C. prefix 0, the first prefix with 1004' => ['001000001004'];
        yield 'RCN-12 with the U.P.C. prefix 0, in the middle' => ['005000009992'];
        yield 'RCN-12 with the U.P.C. prefix 0, velocity code of all zeros' => ['000000000000'];
        yield 'RCN-12 with the U.P.C. prefix 0, velocity code' => ['000000009997'];
        yield 'the same RCN-12 as 13 digits with a zero on the left' => ['0001000000052'];
        yield 'the same RCN-12 of the figure as 13 digits' => ['0007999000097'];
        yield 'the same velocity RCN-12 as 13 digits' => ['0000000009997'];
        yield 'RCN-12 LAC as 14 digits' => ['00001000000052'];
        yield 'RCN-12 of the last company prefix as 14 digits' => ['00007999000097'];
        yield 'RCN-12 in the middle as 13 digits' => ['0005000009992'];
        yield 'RCN-12 in the middle as 14 digits' => ['00005000009992'];
        // 2.1.11.4: the GS1 prefixes 02 and 20-29, with the RCN-12 of the U.P.C. prefix 2.
        yield 'RCN-13 with the prefix 02' => ['0200000000004'];
        yield 'RCN-13 with the prefix 20' => ['2000000000008'];
        yield 'RCN-13 with the prefix 29' => ['2999999999991'];
        yield 'RCN-12 with the U.P.C. prefix 2' => ['200000000004'];
        // A 14-digit code has no RCN form: the last 13 digits tell (indicator 1 over an RCN-13 and an RCN-12).
        yield 'a 14-digit code over an RCN-13 of the prefix 04' => ['10400000000005'];
        yield 'a 14-digit code over an RCN-13 of the prefix 02' => ['10200000000001'];
        yield 'a 14-digit code over an RCN-13 of the prefix 2' => ['12000000000005'];
        yield 'a 14-digit code over an RCN-12 of the U.P.C. prefix 0' => ['10001000000059'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function globalTradeItemNumbers(): iterable
    {
        yield 'EAN-13 of Poland' => ['5901234123457'];
        yield 'EAN-13 with a check digit of 0' => ['0501234567890'];
        yield 'EAN-8' => ['59012344'];
        yield 'U.P.C.-A' => ['012345678905'];
        // 2.1.11 names only the prefixes above; the others are not RCN.
        yield 'the prefix 03' => ['0301234567896'];
        yield 'the prefix 05' => ['0501234567890'];
        yield 'the prefix 19' => ['1901234567897'];
        yield 'the prefix 30' => ['3012345678902'];
        yield 'the U.P.C. company prefix 000999' => ['000999000012'];
        yield 'the U.P.C. company prefix 008000' => ['008000000013'];
        yield 'EAN-8 starting with 1' => ['12345670'];
        yield 'EAN-8 starting with 9' => ['92345676'];
        yield 'a GTIN-14 with an indicator digit over an ordinary GTIN-13' => ['15901234123454'];
        // Ordinary EAN-13 whose figures 3-8 fall in 001000-007999 but that are no U.P.C.-12 (the 13-figure form does not start with 0).
        yield 'EAN-13 of Germany 400' => ['4006381333931'];
        yield 'EAN-13 of Germany 400 (second)' => ['4001686301265'];
        yield 'EAN-13 of France 300' => ['3001234567892'];
        yield 'EAN-13 of Italy 800' => ['8001505000009'];
        yield 'EAN-13 with the prefix 500' => ['5001234567890'];
        yield 'EAN-13 with the prefix 900' => ['9001123456782'];
        yield 'EAN-13 with the prefix 700' => ['7001234567898'];
        yield 'EAN-13 with the prefix 100' => ['1001234567894'];
        yield 'a GTIN-14 over an ordinary EAN-13 of Germany 400' => ['14001686301262'];
    }

    #[DataProvider('restrictedCirculation')]
    public function testARestrictedCirculationNumberHasNoGtin(string $code): void
    {
        $ean = Ean::optional($code);

        self::assertNotNull($ean, 'the example of the spec is a valid EAN');
        self::assertSame($code, $ean->value(), 'the panel and the shop show the code the seller typed');
        self::assertTrue($ean->isRestrictedCirculation());
        self::assertSame('', $ean->gtin());
    }

    #[DataProvider('globalTradeItemNumbers')]
    public function testAGlobalTradeItemNumberKeepsItsGtin(string $code): void
    {
        $ean = Ean::optional($code);

        self::assertNotNull($ean, 'the example is a valid EAN');
        self::assertFalse($ean->isRestrictedCirculation());
        self::assertSame($code, $ean->gtin());
    }
}
