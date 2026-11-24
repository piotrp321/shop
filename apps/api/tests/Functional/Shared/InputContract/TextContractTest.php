<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The contract of texts typed by people (spec: docs/features/api-input-contract/spec.md, "Etap 2: reguła tekstu",
 * "Test kontraktu tekstów").
 *
 * Every execution of every row of the table (TextRows, one file of rows per module that owns the operations) is a test
 * of its own: it builds the state of the row with its fixture from nothing (the transaction of the test is rolled back
 * after it) and sends the value in the field of the row, changing no other field. A 4xx has exactly one violation, at
 * the field with the title of the row; a 2xx of a row that reads its stored value compares that value.
 */
final class TextContractTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @return iterable<string, array{TextRow, TextExecution}>
     */
    public static function executions(): iterable
    {
        foreach (TextRows::all() as $row) {
            foreach ($row->executions() as $name => $execution) {
                yield $row->name().', '.$name => [$row, $execution];
            }
        }
    }

    #[DataProvider('executions')]
    public function testAnExecutionOfARowGivesTheAnswerOfTheContract(TextRow $row, TextExecution $execution): void
    {
        $placeholders = $this->placeholders($row->fixture);
        if (TextRow::PHRASE === $row->kind && TextExecution::EDGES === $execution->class) {
            $this->assertThePhraseBetweenEdgesFindsWhatTheCleanPhraseFinds($row, $placeholders);

            return;
        }

        $response = $this->send($row, $execution->value, $placeholders);
        $content = (string) $response->getContent();

        if ($execution->isRefused()) {
            self::assertSame($execution->status, $response->getStatusCode(), $content);
            self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'), $content);
            $problem = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($problem);
            self::assertSame([['propertyPath' => $row->field, 'title' => $execution->title]], $problem['violations'] ?? null, $content);

            return;
        }

        if (null !== $execution->status) {
            self::assertSame($execution->status, $response->getStatusCode(), $content);
        }
        self::assertGreaterThanOrEqual(200, $response->getStatusCode(), $content);
        self::assertLessThan(300, $response->getStatusCode(), $content);
        if ($execution->readsStored && null !== $row->stored) {
            $answer = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($answer);
            foreach ($answer as $key => $value) {
                if (\is_scalar($value)) {
                    $placeholders['{response.'.$key.'}'] = (string) $value;
                }
            }
            self::assertSame($execution->stored, ($row->stored)($this->entityManager()->getConnection(), $placeholders));
        }
    }

    /**
     * The clean phrase finds the record of the fixture; the same phrase between U+200B and between U+00A0 finds the
     * same items in the same order, with the same count.
     *
     * @param array<string, string> $placeholders
     */
    private function assertThePhraseBetweenEdgesFindsWhatTheCleanPhraseFinds(TextRow $row, array $placeholders): void
    {
        self::assertNotNull($row->result);
        $phrase = strtr($row->result['phrase'], $placeholders);
        $record = strtr($row->result['record'], $placeholders);

        $clean = $this->found($row, $phrase, $placeholders);
        self::assertContains($record, $clean['ids'], 'the clean phrase finds the record of the fixture');
        foreach (["\u{200B}", "\u{A0}"] as $edge) {
            self::assertSame($clean, $this->found($row, $edge.$phrase.$edge, $placeholders), \sprintf('the phrase between U+%04X', mb_ord($edge)));
        }
    }

    /**
     * @param array<string, string> $placeholders
     *
     * @return array{ids: list<mixed>, count: mixed}
     */
    private function found(TextRow $row, string $phrase, array $placeholders): array
    {
        self::assertNotNull($row->result);
        $response = $this->send($row, $phrase, $placeholders);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $answer = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $items = self::at($answer, $row->result['items']);
        self::assertIsArray($items);
        $ids = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $ids[] = $item[$row->result['id']] ?? null;
        }

        return ['ids' => $ids, 'count' => null === $row->result['count'] ? null : self::at($answer, $row->result['count'])];
    }

    /**
     * @param array<mixed> $answer
     */
    private static function at(array $answer, string $path): mixed
    {
        $value = $answer;
        foreach (explode('.', $path) as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value, $path);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string, string> $placeholders
     */
    private function send(TextRow $row, mixed $value, array $placeholders): Response
    {
        $placeholders += ['{idempotencyKey}' => Uuid::v4()->toRfc4122()];
        $server = [];
        foreach ($row->headers as $name => $header) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = strtr($header, $placeholders);
        }
        $token = match ($row->token) {
            InputCase::STAFF => TestTokenFactory::staff(['admin']),
            InputCase::CUSTOMER => TestTokenFactory::customer(),
            null => null,
        };
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $url = strtr($row->path, $placeholders);
        $content = null;
        if (TextRow::PHRASE === $row->kind) {
            \assert(\is_string($value));
            $query = strtr($row->query, $placeholders);
            $url .= '?'.('' === $query ? '' : $query.'&').'q='.rawurlencode($value);
        } else {
            self::assertNotNull($row->body);
            $body = self::withPlaceholders($row->body, $placeholders);
            self::assertIsArray($body);
            $content = json_encode(self::withField($body, self::keys($row->field), $value), \JSON_THROW_ON_ERROR);
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request($row->method, $url, server: $server, content: $content);

        return $this->client->getResponse();
    }

    /**
     * The keys of a path of a field: `variants[0].values[1]` is variants, 0, values, 1.
     *
     * @return list<string|int>
     */
    private static function keys(string $field): array
    {
        preg_match_all('/([^.\[\]]+)|\[(\d+)\]/', $field, $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $match): string|int => isset($match[2]) && '' !== $match[2] ? (int) $match[2] : $match[1], $matches);
    }

    /**
     * @param array<mixed>     $body
     * @param list<string|int> $keys
     *
     * @return array<mixed>
     */
    private static function withField(array $body, array $keys, mixed $value): array
    {
        $key = array_shift($keys);
        self::assertNotNull($key);
        if ([] === $keys) {
            self::assertArrayHasKey($key, $body, 'the body of the row has the field');
            $body[$key] = $value;

            return $body;
        }
        self::assertIsArray($body[$key] ?? null, 'the body of the row has the field');
        $body[$key] = self::withField($body[$key], $keys, $value);

        return $body;
    }

    /**
     * @param array<string, string> $placeholders
     */
    private static function withPlaceholders(mixed $value, array $placeholders): mixed
    {
        if (\is_string($value)) {
            return strtr($value, $placeholders);
        }
        if (\is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::withPlaceholders($item, $placeholders), $value);
        }

        return $value;
    }

    /**
     * Builds the state of the fixture.
     *
     * @return array<string, string> the value of each of its placeholders, by `{name}`
     */
    private function placeholders(string $fixture): array
    {
        $placeholders = [];
        foreach (new InputContractFixtures($this->entityManager())->build($fixture) as $name => $value) {
            $placeholders['{'.$name.'}'] = $value;
        }

        return $placeholders;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
