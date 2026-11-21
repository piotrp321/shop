<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * The rows of the header Idempotency-Key, the same on each operation that needs it (spec, section C): a new key is
 * accepted; no header, an empty one, one of 101 characters and one with a character outside ASCII are refused.
 */
final class IdempotencyKeyRows
{
    public const string HEADER = 'Idempotency-Key';
    public const string REQUIRED = 'Nagłówek Idempotency-Key jest wymagany.';
    public const string INVALID = 'Nieprawidłowy klucz żądania.';

    /**
     * @param InputCase::STAFF|InputCase::CUSTOMER|null $token
     * @param string                                    $body    the valid JSON body of the operation
     * @param array<string, string>                     $headers other headers the operation needs
     *
     * @return iterable<InputCase>
     */
    public static function of(string $operation, ?string $token, string $fixture, string $body, array $headers = []): iterable
    {
        $cases = [
            'no header' => [null, self::REQUIRED],
            'empty' => ['', self::INVALID],
            '101 characters' => [str_repeat('a', 101), self::INVALID],
            'a character outside ASCII' => ['klucz-ż', self::INVALID],
        ];
        foreach ($cases as $label => [$invalid, $title]) {
            yield InputCase::header($operation, $token, $fixture, self::HEADER, $label, '{idempotencyKey}', $invalid, [[self::HEADER, $title]], $body, $headers);
        }
    }
}
