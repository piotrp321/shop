<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The GIN index of the bigrams of the product name (`catalog_name_bigrams(name)`): the candidates it gives for a phrase
 * without a narrowing trigram must be a superset of what `name ILIKE '%phrase%'` matches, whatever the case and the
 * characters, because the exact condition is only checked on them.
 */
final class ProductNameBigramsTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
    }

    public function testTheBigramIndexOfTheNameExists(): void
    {
        $definition = $this->connection->fetchOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'catalog_product' AND indexname = 'catalog_product_name_bigrams_idx'");

        self::assertIsString($definition);
        self::assertStringContainsString('USING gin', $definition);
        self::assertStringContainsString('catalog_name_bigrams(', $definition);
    }

    public function testTheFunctionIsImmutableSoThatItCanBeIndexed(): void
    {
        self::assertSame('i', $this->connection->fetchOne("SELECT provolatile FROM pg_proc WHERE proname = 'catalog_name_bigrams'"));
    }

    public function testTheBigramsAreTheDistinctTwoCharacterSubstringsOfTheLoweredText(): void
    {
        $joined = $this->connection->fetchOne("SELECT array_to_string(catalog_name_bigrams('AbAb ŁÓ'), '|')");
        self::assertIsString($joined);
        $bigrams = explode('|', $joined);
        sort($bigrams, \SORT_STRING);

        $expected = ['ab', 'ba', 'b ', ' ł', 'łó'];
        sort($expected, \SORT_STRING);
        self::assertSame($expected, $bigrams);
        self::assertSame('{}', $this->connection->fetchOne("SELECT catalog_name_bigrams('a')::text"));
    }

    public function testTheCandidatesAreASupersetOfTheMatchesOfILikeOnNamesWithPolishCharactersAndCapitals(): void
    {
        $names = [
            'Termos stalowy 0,5 l', 'TERMOS STALOWY', 'Łódź kajakowa', 'ŁÓDŹ', 'łóDŹ turystyczna', 'Żółć i źdźbło', 'ŻÓŁW', 'Zażółć gęślą jaźń',
            'Kijki a.b', 'Bidon ab-cd', 'Zestaw #!# 1', 'Zestaw a_b', '100% bawełna', 'Plecak \\kółko', 'Namiot QX', 'Namiot qX', 'Ąę Ćń Śś',
            'İstanbul', 'ǅ title case', 'ß sharp', 'x', '  spacje  ', 'Ä ö ü',
        ];
        $pairs = [];
        foreach ($names as $name) {
            $length = mb_strlen($name);
            for ($size = 1; $size <= 4; ++$size) {
                for ($start = 0; $start + $size <= $length; ++$start) {
                    $part = mb_substr($name, $start, $size);
                    foreach ([$part, mb_strtoupper($part), mb_strtolower($part), mb_strtoupper(mb_substr($part, 0, 1)).mb_strtolower(mb_substr($part, 1))] as $term) {
                        $pairs[] = ['n' => $name, 'q' => $term];
                    }
                }
            }
        }

        $matches = $this->connection->fetchOne(
            "SELECT count(*) FROM jsonb_to_recordset(CAST(:pairs AS jsonb)) AS t(n text, q text)
             WHERE t.n ILIKE '%' || replace(replace(replace(t.q, '\\', '\\\\'), '%', '\\%'), '_', '\\_') || '%'",
            ['pairs' => json_encode($pairs, \JSON_THROW_ON_ERROR)],
        );
        $missed = $this->connection->fetchAllAssociative(
            "SELECT t.n, t.q FROM jsonb_to_recordset(CAST(:pairs AS jsonb)) AS t(n text, q text)
             WHERE t.n ILIKE '%' || replace(replace(replace(t.q, '\\', '\\\\'), '%', '\\%'), '_', '\\_') || '%'
               AND NOT (catalog_name_bigrams(t.n) @> catalog_name_bigrams(t.q))",
            ['pairs' => json_encode($pairs, \JSON_THROW_ON_ERROR)],
        );

        self::assertGreaterThan(1000, $matches);
        self::assertSame([], $missed);
    }
}
