<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Concurrency;

/**
 * What one participant's command ended with, as passed from the child process to the parent: the result of the
 * handler (its public properties, as JSON gives them) or the class and message of the exception.
 */
final readonly class Outcome
{
    public const string SUCCESS = 'success';
    private const string PARTICIPANT_FAILURE = 'participant_failure';

    /**
     * @param array<mixed>|null $result
     */
    private function __construct(
        public string $kind,
        public ?array $result,
        public ?string $message,
    ) {
    }

    public static function success(mixed $result): self
    {
        $decoded = json_decode(json_encode($result, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return new self(self::SUCCESS, \is_array($decoded) ? $decoded : null, null);
    }

    public static function failure(\Throwable $failure): self
    {
        return new self($failure::class, null, $failure->getMessage());
    }

    /**
     * The participant failed before or after its command (booting the kernel, reaching the barrier).
     */
    public static function participantFailure(string $message): self
    {
        return new self(self::PARTICIPANT_FAILURE, null, $message);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($data) && \is_string($data['kind']) && (null === $data['result'] || \is_array($data['result'])) && (null === $data['message'] || \is_string($data['message'])));

        return new self($data['kind'], $data['result'], $data['message']);
    }

    public function toJson(): string
    {
        return json_encode(['kind' => $this->kind, 'result' => $this->result, 'message' => $this->message], \JSON_THROW_ON_ERROR);
    }

    public function isParticipantFailure(): bool
    {
        return self::PARTICIPANT_FAILURE === $this->kind;
    }
}
