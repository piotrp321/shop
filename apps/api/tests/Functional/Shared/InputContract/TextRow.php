<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Tests\Support\InputConformance\InputValues;
use Doctrine\DBAL\Connection;

/**
 * One row of the table of the contract of texts (spec: docs/features/api-input-contract/spec.md, "Etap 2: reguła
 * tekstu", "Test kontraktu tekstów"): an operation, the token it is sent with, the fixture that builds its state, the
 * field under test (a path in the JSON body, or `q`), its kind, the characters the field allows, the messages of the
 * tables of the spec and the way to read the stored value. executions() turns it into the executions of the table of
 * executions; each runs as a test of its own (TextContractTest).
 *
 * The kinds:
 * - required() (W) and optional() (O): a text of the body; the classes empty, forbidden (inside and at the end),
 *   allowed in the field (only with allowed characters), edges, preserved and wrong type. The read of the stored value
 *   is required: every 2xx compares the stored value.
 * - format() (F): a text of the body with a format of its own; the class empty, each case of the tables "Pola
 *   z formatem: przypadki" in place of the classes forbidden, edges and preserved, the value of the first accepted
 *   case with U+2028 at its end (added to every such row), and wrong type; the read of the stored value is required.
 * - phrase(): the query parameter `q` of a list; the classes empty, forbidden (inside and at the end) and preserved,
 *   and edges as one execution that compares the answers to the clean phrase and to the phrase between U+200B and
 *   between U+00A0.
 *
 * Two rows of one operation, field and kind (the note of a cancellation and of a return, both required) differ by their
 * variant, a label that is part of the name of the row and so of the name of each of its executions as a test.
 *
 * The body, the path, the base query and the headers may hold placeholders `{name}`: the values of the fixture and
 * `{idempotencyKey}`, a new key in every request. The value of the field never takes placeholders, except the clean
 * phrase of the edges of a phrase. The read of the stored value takes the placeholders of the fixture and
 * `{response.<key>}` of each scalar key of the JSON answer.
 */
final readonly class TextRow
{
    public const string REQUIRED = 'W';
    public const string OPTIONAL = 'O';
    public const string FORMAT = 'F';
    public const string PHRASE = 'phrase';

    /**
     * @param self::REQUIRED|self::OPTIONAL|self::FORMAT|self::PHRASE                               $kind
     * @param InputCase::STAFF|InputCase::CUSTOMER|null                                             $token
     * @param array<string, mixed>|null                                                             $body
     * @param array<string, string>                                                                 $headers
     * @param (\Closure(Connection, array<string, string>): mixed)|null                             $stored    null only for a phrase
     * @param array<string, string>                                                                 $preserved
     * @param list<array{string, string, int, string}>                                              $cases     label, value sent, status, the stored value (2xx) or the title of the violation (4xx)
     * @param array{phrase: string, record: string, items: string, id: string, count: ?string}|null $result
     */
    private function __construct(
        public string $method,
        public string $path,
        public ?string $token,
        public string $fixture,
        public string $field,
        public string $kind,
        public string $sample,
        public ?array $body,
        public string $query,
        public array $headers,
        public string $allowed,
        public ?string $empty,
        public mixed $noValue,
        public ?string $forbidden,
        public ?string $wrongType,
        public ?\Closure $stored,
        public array $preserved,
        public array $cases,
        public ?array $result,
        public string $variant,
    ) {
    }

    /**
     * A required text of the body (W): a text empty by T3 is the violation `$empty`.
     *
     * @param string                                             $operation "METHOD /path" as in the OpenAPI documents
     * @param InputCase::STAFF|InputCase::CUSTOMER|null          $token
     * @param string                                             $field     the path of the field in the body, e.g. `name` or `images[0].alt`
     * @param string                                             $sample    a valid text of the field (at least two characters); the forbidden characters go inside it, the edges around it
     * @param array<string, mixed>                               $body      a valid body; the field is set in it
     * @param string                                             $allowed   the characters of T1 the field allows, each one execution of the class "allowed in the field"
     * @param \Closure(Connection, array<string, string>): mixed $stored    the read of the stored value of the field (see column()); every 2xx compares it
     * @param array<string, string>                              $headers   other headers of the request, by name
     * @param array<string, string>|null                         $preserved the texts of the class "preserved" when the field cannot take the common ones
     * @param string                                             $variant   the label that tells the row from another of the same operation, field and kind, empty for the only one
     */
    public static function required(string $operation, ?string $token, string $fixture, string $field, string $sample, array $body, string $empty, string $forbidden, string $wrongType, \Closure $stored, string $allowed = '', array $headers = [], ?array $preserved = null, string $variant = ''): self
    {
        [$method, $path] = explode(' ', $operation, 2);

        return new self($method, $path, $token, $fixture, $field, self::REQUIRED, $sample, $body, '', $headers, $allowed, $empty, null, $forbidden, $wrongType, $stored, $preserved ?? self::preservedTexts(), [], null, $variant);
    }

    /**
     * An optional text of the body (O): a text empty by T3 is accepted and stored as `$noValue` (e.g. '' or null).
     *
     * @param InputCase::STAFF|InputCase::CUSTOMER|null          $token
     * @param array<string, mixed>                               $body
     * @param \Closure(Connection, array<string, string>): mixed $stored
     * @param array<string, string>                              $headers
     * @param array<string, string>|null                         $preserved
     *
     * @see required() for the other parameters
     */
    public static function optional(string $operation, ?string $token, string $fixture, string $field, string $sample, array $body, mixed $noValue, string $forbidden, string $wrongType, \Closure $stored, string $allowed = '', array $headers = [], ?array $preserved = null, string $variant = ''): self
    {
        [$method, $path] = explode(' ', $operation, 2);

        return new self($method, $path, $token, $fixture, $field, self::OPTIONAL, $sample, $body, '', $headers, $allowed, null, $noValue, $forbidden, $wrongType, $stored, $preserved ?? self::preservedTexts(), [], null, $variant);
    }

    /**
     * A text of the body with a format of its own (F): a text empty by T3 is the violation `$empty`, and each case of
     * the tables of the spec is one execution.
     *
     * @param InputCase::STAFF|InputCase::CUSTOMER|null          $token
     * @param array<string, mixed>                               $body
     * @param list<array{string, string, int, string}>           $cases     label, value sent, status, the stored value (2xx) or the title of the violation (4xx)
     * @param string                                             $forbidden the title of the format, the answer to a forbidden character (T1)
     * @param \Closure(Connection, array<string, string>): mixed $stored
     * @param array<string, string>                              $headers
     */
    public static function format(string $operation, ?string $token, string $fixture, string $field, array $body, string $empty, string $forbidden, string $wrongType, array $cases, \Closure $stored, array $headers = [], string $variant = ''): self
    {
        [$method, $path] = explode(' ', $operation, 2);

        return new self($method, $path, $token, $fixture, $field, self::FORMAT, '', $body, '', $headers, '', $empty, null, $forbidden, $wrongType, $stored, [], $cases, null, $variant);
    }

    /**
     * The phrase `q` of a list: a phrase empty by T3 is the 400 `$empty`, a forbidden character the 400 `$forbidden`.
     * For the edges the fixture builds a record the clean phrase finds: the answer to the clean phrase has the record,
     * and the answers to the phrase between U+200B and between U+00A0 have the same items in the same order and the
     * same count.
     *
     * @param InputCase::STAFF|InputCase::CUSTOMER|null $token
     * @param string                                    $sample    a valid phrase (at least two characters) for the classes empty, forbidden and preserved
     * @param string                                    $phrase    the clean phrase of the edges; may hold placeholders of the fixture
     * @param string                                    $record    the id of the record the clean phrase finds, e.g. `{productId}`
     * @param string                                    $items     the path of the list of items in the answer
     * @param string                                    $id        the key of the id in an item
     * @param string|null                               $count     the path of the count in the answer, null for a list without one
     * @param string                                    $query     other parameters of the query, already URL-encoded
     * @param array<string, string>|null                $preserved
     */
    public static function phrase(string $operation, ?string $token, string $fixture, string $sample, string $empty, string $forbidden, string $phrase, string $record, string $items = 'items', string $id = 'id', ?string $count = 'total', string $query = '', ?array $preserved = null, string $variant = ''): self
    {
        [$method, $path] = explode(' ', $operation, 2);

        return new self($method, $path, $token, $fixture, 'q', self::PHRASE, $sample, null, $query, [], '', $empty, null, $forbidden, null, null, $preserved ?? self::preservedTexts(),
            [], ['phrase' => $phrase, 'record' => $record, 'items' => $items, 'id' => $id, 'count' => $count], $variant);
    }

    /**
     * A read of the stored value: the first column of the first row of the query.
     *
     * @param array<string, string> $parameters the parameters of the query; values may hold `{response.<key>}` and the
     *                                          placeholders of the fixture
     *
     * @return \Closure(Connection, array<string, string>): mixed
     */
    public static function column(string $sql, array $parameters): \Closure
    {
        return static fn (Connection $connection, array $placeholders): mixed => $connection->fetchOne(
            $sql,
            array_map(static fn (string $value): string => strtr($value, $placeholders), $parameters),
        );
    }

    /**
     * @return iterable<string, TextExecution> by the name of the execution
     */
    public function executions(): iterable
    {
        $code = self::PHRASE === $this->kind ? 400 : 422;
        $reads = null !== $this->stored;

        foreach (InputValues::emptyTextClass() as $label => [$blank]) {
            yield from self::named(self::OPTIONAL === $this->kind
                ? TextExecution::accepted(TextExecution::EMPTY, $label, $blank, $reads, $this->noValue)
                : TextExecution::refused(TextExecution::EMPTY, $label, $blank, $code, (string) $this->empty));
        }

        if (self::FORMAT === $this->kind) {
            $valid = null;
            foreach ($this->cases as [$label, $value, $status, $expected]) {
                $accepted = $status >= 200 && $status < 300;
                $valid ??= $accepted ? $value : null;
                yield from self::named($accepted
                    ? TextExecution::accepted(TextExecution::FORMAT, $label, $value, $reads, $expected, $status)
                    : TextExecution::refused(TextExecution::FORMAT, $label, $value, $status, $expected));
            }
            // Added to every row, so none can leave it out: T1 looks at the value as it came, before the trim of the
            // ends would drop the separator and leave a valid value.
            yield from self::named(TextExecution::refused(TextExecution::FORBIDDEN, 'U+2028 at the end of a valid value',
                ($valid ?? throw new \LogicException(\sprintf('The row %s has no accepted case.', $this->name())))."\u{2028}", $code, (string) $this->forbidden));
        } else {
            foreach (InputValues::forbiddenTextClass() as $label => [$character]) {
                yield from self::named(TextExecution::refused(TextExecution::FORBIDDEN, $label.' inside', $this->inside($character), $code, (string) $this->forbidden));
            }
            // T1 looks at the value as it came: a forbidden character a trim of the ends would drop is refused too.
            foreach (InputValues::forbiddenAtTheEndTextClass() as $label => [$character]) {
                yield from self::named(TextExecution::refused(TextExecution::FORBIDDEN, $label.' at the end', $this->sample.$character, $code, (string) $this->forbidden));
            }
            foreach (mb_str_split($this->allowed) as $character) {
                $text = $this->inside($character);
                yield from self::named(TextExecution::accepted(TextExecution::ALLOWED, \sprintf('U+%04X inside', mb_ord($character)), $text, $reads, $text));
            }
            if (self::PHRASE === $this->kind) {
                yield from self::named(TextExecution::accepted(TextExecution::EDGES, 'the clean phrase, between U+200B and between U+00A0', $this->result['phrase'] ?? '', false, null));
            } else {
                foreach (InputValues::edgeTextClass() as $label => [$edge]) {
                    yield from self::named(TextExecution::accepted(TextExecution::EDGES, 'between '.$label, $edge.$this->sample.$edge, $reads, $this->sample));
                }
            }
            foreach ($this->preserved as $label => $text) {
                yield from self::named(TextExecution::accepted(TextExecution::PRESERVED, $label, $text, $reads && self::PHRASE !== $this->kind, $text));
            }
        }

        if (null !== $this->wrongType) {
            yield from self::named(TextExecution::refused(TextExecution::WRONG_TYPE, 'a number', 123, 422, $this->wrongType));
            yield from self::named(TextExecution::refused(TextExecution::WRONG_TYPE, 'a list', ['tekst'], 422, $this->wrongType));
        }
    }

    public function name(): string
    {
        return \sprintf('%s %s, %s (%s)', $this->method, $this->path, $this->field, '' === $this->variant ? $this->kind : $this->kind.', '.$this->variant);
    }

    /**
     * The sample with the character between its halves.
     */
    private function inside(string $character): string
    {
        $half = intdiv(mb_strlen($this->sample), 2);

        return mb_substr($this->sample, 0, $half).$character.mb_substr($this->sample, $half);
    }

    /**
     * @return array<string, TextExecution>
     */
    private static function named(TextExecution $execution): array
    {
        return [$execution->name() => $execution];
    }

    /**
     * @return array<string, string>
     */
    private static function preservedTexts(): array
    {
        $texts = [];
        foreach (InputValues::preservedTexts() as $label => [$text]) {
            $texts[$label] = $text;
        }

        return $texts;
    }
}
