<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Mail;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The messages Mailpit holds for one recipient address, read through its HTTP API. A test uses an address of its own,
 * so messages of other tests and of the shared stack never count.
 */
final readonly class MailpitInbox
{
    private HttpClientInterface $http;

    public function __construct(public string $recipient)
    {
        $baseUrl = $_SERVER['MAILPIT_INTERNAL_URL'] ?? $_ENV['MAILPIT_INTERNAL_URL'] ?? getenv('MAILPIT_INTERNAL_URL');
        \assert(\is_string($baseUrl) && '' !== $baseUrl, 'MAILPIT_INTERNAL_URL is not set.');
        $this->http = HttpClient::create(['base_uri' => rtrim($baseUrl, '/').'/', 'timeout' => 5]);
    }

    /**
     * @return list<array{subject: string, from: string, to: list<string>, text: string}>
     */
    public function messages(): array
    {
        $found = $this->http->request('GET', 'api/v1/search', ['query' => ['query' => 'to:"'.$this->recipient.'"']])->toArray();
        $messages = [];
        $summaries = $found['messages'] ?? [];
        \assert(\is_array($summaries));
        foreach ($summaries as $summary) {
            \assert(\is_array($summary) && \is_string($summary['ID']));
            $message = $this->http->request('GET', 'api/v1/message/'.$summary['ID'])->toArray();
            \assert(\is_string($message['Subject']) && \is_string($message['Text']));
            \assert(\is_array($message['From']) && \is_string($message['From']['Name']) && \is_string($message['From']['Address']));
            \assert(\is_array($message['To']));
            $messages[] = [
                'subject' => $message['Subject'],
                'from' => '' === $message['From']['Name'] ? $message['From']['Address'] : $message['From']['Name'].' <'.$message['From']['Address'].'>',
                'to' => array_values(array_map(static fn (mixed $to): string => \is_array($to) && \is_string($to['Address']) ? $to['Address'] : '', $message['To'])),
                'text' => $message['Text'],
            ];
        }

        return $messages;
    }

    public function count(): int
    {
        return \count($this->messages());
    }

    public function clear(): void
    {
        $this->http->request('DELETE', 'api/v1/search', ['query' => ['query' => 'to:"'.$this->recipient.'"']])->getContent();
    }
}
