<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Stacktrace;
use Sentry\Tracing\Span;

/**
 * What of an event, a transaction or a breadcrumb may leave the API for Sentry (spec: docs/features/sentry/spec.md, D4
 * and the threat model). It removes:
 * - from the request everything but the method, the address without its query and fragment, and the headers
 *   User-Agent, Content-Type and Accept: no cookies, body, query string or address of the client;
 * - the user;
 * - the extra data but `correlation_id`, and the values of the contexts but `trace` (Event never removes a context,
 *   so their keys stay);
 * - the query and fragment of the name of a transaction, of the name and the data of an HTTP span and of the address of
 *   a breadcrumb; the data of spans and breadcrumbs about headers, cookies, bodies, parameters of queries and the
 *   client;
 * - the arguments of the stack frames (`vars`).
 *
 * The texts (messages, values of exceptions, breadcrumbs, names of spans, also the SQL of Doctrine spans, which has
 * placeholders but may have literals, and the remaining string data) are masked with the formats of the log masking of
 * Alloy (infra/k8s/argocd/apps/monitoring.yaml): e-mail addresses, JWT, Bearer tokens, passwords in addresses, values of
 * keys of secrets, the rows and keys of PostgreSQL, the parameters of DBAL, IP addresses. A new format of customer data
 * in the logs or exceptions is a new pattern here and in Alloy (apps/api/CLAUDE.md).
 */
final readonly class SentryEventFilter
{
    public const string REDACTED = '[REDACTED]';

    /** The headers of a request that are kept, in lower case. */
    private const array KEPT_HEADERS = ['user-agent', 'content-type', 'accept'];

    /** The data of spans and breadcrumbs that is removed: queries, headers, cookies, bodies, parameters, the client. */
    private const string REMOVED_DATA = '/(^|\.)(query|fragment)$|header|cookie|body|^db\.query\.parameter|^user\.|^client\.|^net\.peer\.|client_ip/i';

    /** The data of spans and breadcrumbs that is an address: its query and fragment are removed. */
    private const string ADDRESS_DATA = '/(^|\.)(url|full|target)$/i';

    /** The names of the keys of secrets, as in Alloy. */
    private const string KEY = '[a-z0-9_-]*(?:password|passwd|secret|token|session|api[_-]?key|authorization|cookie)[a-z0-9_-]*';
    private const string KEY_OR_CODE = self::KEY.'|code(?:_id|_verifier)?|username';
    private const string QUOTED = '"(?:[^"\\\\]|\\\\.)*"';

    /**
     * The masks in the order of Alloy: a pattern and what replaces its first group. The lines of a text are masked
     * one by one (m), every pattern works on bytes, so a text that is not valid UTF-8 is masked too. DETAIL and
     * STATEMENT of PostgreSQL, which Alloy drops as whole lines, lose the text of the line.
     */
    private const array MASKS = [
        ['#(?:^|\]\s)\s*(?:DETAIL|STATEMENT):\s*(.+)$#m', self::REDACTED],
        // JWT.
        ['#(eyJ[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]*)+)#m', self::REDACTED],
        // Bearer tokens.
        ['#(?i)\bbearer\s+([A-Za-z0-9._~+/=-]{16,})#m', self::REDACTED],
        // Headers written as lines (the rest of the value).
        ['#(?i)\b(?:proxy-authorization|authorization|set-cookie|cookie)\s*:\s*([^\r\n"]+)#m', self::REDACTED],
        // A list of two or more strings under a key of a secret: all of them.
        ['#(?i)(?:^|[^a-z0-9_-])"?(?:'.self::KEY_OR_CODE.')"?\s*[:=]\s*\[\s*('.self::QUOTED.'(?:\s*,\s*'.self::QUOTED.')+)\s*\]#m', '"'.self::REDACTED.'"'],
        // A number under a key of a secret.
        ['#(?i)(?:^|[^a-z0-9_-])"(?:'.self::KEY.')"\s*:\s*(-?[0-9][0-9.eE+-]*)#m', self::REDACTED],
        // The value of a key of a secret in JSON (also a list of one value) and in logfmt with quotes.
        ['#(?i)(?:^|[^a-z0-9_-])"?(?:'.self::KEY_OR_CODE.')"?\s*[:=]\s*\[?\s*"((?:[^"\\\\]|\\\\.)*)"#m', self::REDACTED],
        // The same inside a string of JSON (the quotes are escaped).
        ['#(?i)(?:^|[^a-z0-9_-])\\\\"(?:'.self::KEY_OR_CODE.')\\\\"\s*:\s*\\\\"([^"\\\\]*)\\\\"#m', self::REDACTED],
        // The value of a key of a secret in a query string, a form and logfmt without quotes.
        ['#(?i)(?:^|[^a-z0-9_-])(?:'.self::KEY_OR_CODE.')=([^&\s"\';,)}\]\\\\]+)#m', self::REDACTED],
        // The password of an address (scheme://user:password@host).
        ['#://[^/\s:@"]*:([^@/\s"]+)@#m', self::REDACTED],
        // The parameters of a query in the message of DBAL (with params [...]).
        ['#with params \[((?:[^\]\\\\]|\\\\.)*)\]#m', self::REDACTED],
        // The values of a row and of a key of PostgreSQL outside a line DETAIL: up to the last parenthesis of the line
        // (a value may have its own), or the end of the line when it is cut.
        ['#(?:Failing row contains |Key \(.*?\)=)\((.*(?=\))|.*)#m', self::REDACTED],
        // E-mail addresses, also with the @ written as %40 in an address.
        ['#([^\s"\'<>(),;:\[\]\\\\=@/%&?]+@[^\s"\'<>(),;\[\]\\\\@/:]+\.[^\s"\'<>(),;\[\]\\\\@/:.&?]{2,})#m', self::REDACTED],
        ['#([^\s"\'<>(),;:\[\]\\\\=@/&?]+%40[^\s"\'<>(),;\[\]\\\\&]+)#m', self::REDACTED],
        // IPv6 addresses: the full form, the compressed form with :: and the loopback.
        ['#(?i)(?:^|[^0-9a-z:])((?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4})#m', self::REDACTED],
        ['#(?i)(?:^|[^0-9a-z:])((?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?)#m', self::REDACTED],
        ['#(?:^|[^0-9a-zA-Z:])(::1)\b#m', self::REDACTED],
        // IPv4 addresses.
        ['#\b([0-9]{1,3}(?:\.[0-9]{1,3}){3})\b#m', self::REDACTED],
    ];

    /**
     * Filters an event or a transaction in place.
     */
    public function filterEvent(Event $event): Event
    {
        $event->setRequest($this->request($event->getRequest()));
        $event->setUser(null);
        $event->setExtra(array_intersect_key($event->getExtra(), ['correlation_id' => true]));
        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, 'trace' === $name ? $this->traceContext($context) : array_fill_keys(array_keys($context), self::REDACTED));
        }

        $transaction = $event->getTransaction();
        if (null !== $transaction) {
            $event->setTransaction($this->mask(self::withoutQuery($transaction)));
        }

        $message = $event->getMessage();
        if (null !== $message) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                $this->mask($message),
                // Strings by the PHPDoc of the SDK, but nothing stops a caller from passing a number.
                array_map(fn (mixed $param): string => $this->mask((string) $param), $event->getMessageParams()),
                null === $formatted ? null : $this->mask($formatted),
            );
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($this->mask($exception->getValue()));
            self::removeVars($exception->getStacktrace());
        }
        self::removeVars($event->getStacktrace());

        $event->setBreadcrumb(array_map($this->filterBreadcrumb(...), $event->getBreadcrumbs()));

        foreach ($event->getSpans() as $span) {
            $this->filterSpan($span);
        }

        return $event;
    }

    public function filterBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();
        if (null !== $message) {
            $isRequest = Breadcrumb::TYPE_HTTP === $breadcrumb->getType() || Breadcrumb::TYPE_NAVIGATION === $breadcrumb->getType();
            $breadcrumb = $breadcrumb->withMessage($this->mask($isRequest ? self::withoutQuery($message) : $message));
        }

        foreach ($breadcrumb->getMetadata() as $name => $value) {
            $filtered = $this->data((string) $name, $value);
            $breadcrumb = null === $filtered ? $breadcrumb->withoutMetadata((string) $name) : $breadcrumb->withMetadata((string) $name, $filtered);
        }

        return $breadcrumb;
    }

    /**
     * The text with every value of the formats of the masks replaced. A text that a pattern cannot read (a PCRE
     * error such as its backtracking limit) is replaced as a whole: it never leaves unmasked.
     */
    public function mask(string $text): string
    {
        foreach (self::MASKS as [$pattern, $replacement]) {
            $text = preg_replace_callback($pattern, static function (array $match) use ($replacement): string {
                [$whole, $start] = $match[0];
                [$group, $groupStart] = $match[1];
                if ($groupStart < 0) {
                    return $whole;
                }
                $offset = $groupStart - $start;

                return substr($whole, 0, $offset).$replacement.substr($whole, $offset + \strlen($group));
            }, $text, flags: \PREG_OFFSET_CAPTURE);

            if (null === $text) {
                return self::REDACTED;
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function request(array $request): array
    {
        $filtered = [];
        if (isset($request['method'])) {
            $filtered['method'] = $request['method'];
        }
        if (isset($request['url']) && \is_string($request['url'])) {
            $filtered['url'] = $this->mask(self::withoutQuery($request['url']));
        }
        if (isset($request['headers']) && \is_array($request['headers'])) {
            $filtered['headers'] = array_filter(
                $request['headers'],
                static fn (int|string $name): bool => \in_array(strtolower((string) $name), self::KEPT_HEADERS, true),
                \ARRAY_FILTER_USE_KEY,
            );
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function traceContext(array $context): array
    {
        if (isset($context['description']) && \is_string($context['description'])) {
            $context['description'] = $this->description($context['op'] ?? null, $context['description']);
        }
        if (isset($context['data']) && \is_array($context['data'])) {
            $data = [];
            foreach ($context['data'] as $name => $value) {
                $filtered = $this->data((string) $name, $value);
                if (null !== $filtered) {
                    $data[$name] = $filtered;
                }
            }
            $context['data'] = $data;
        }

        return $context;
    }

    /**
     * Span::setData() merges, so a removed value becomes null.
     */
    private function filterSpan(Span $span): void
    {
        $description = $span->getDescription();
        if (null !== $description) {
            $span->setDescription($this->description($span->getOp(), $description));
        }

        $data = [];
        foreach ($span->getData() as $name => $value) {
            $data[$name] = $this->data((string) $name, $value);
        }
        $span->setData($data);
    }

    /**
     * The name of an HTTP span is a method and an address, which loses its query; others (the SQL of Doctrine, with
     * its ? placeholders, a cache key) are only masked.
     */
    private function description(mixed $op, string $description): string
    {
        if (\is_string($op) && str_starts_with($op, 'http')) {
            $description = self::withoutQuery($description);
        }

        return $this->mask($description);
    }

    /**
     * A value of the data of a span or a breadcrumb as it may leave, null when it may not.
     */
    private function data(string $name, mixed $value): mixed
    {
        if (1 === preg_match(self::REMOVED_DATA, $name)) {
            return null;
        }
        if (!\is_string($value)) {
            return \is_array($value) ? null : $value;
        }
        if (1 === preg_match(self::ADDRESS_DATA, $name)) {
            $value = self::withoutQuery($value);
        }

        return $this->mask($value);
    }

    /**
     * The text up to the first ? or #: an address without its query and fragment.
     */
    private static function withoutQuery(string $text): string
    {
        $end = strcspn($text, '?#');

        return substr($text, 0, $end);
    }

    private static function removeVars(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }
    }
}
