<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Infrastructure\Http\ClientIp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The address of the client that the rate limits count by (spec: docs/features/hardening/spec.md, D2 and D4).
 */
final class ClientIpTest extends TestCase
{
    private const string CONNECTION = '198.51.100.20';

    public function testWithoutTheHeaderConfiguredTheAddressOfTheConnectionCounts(): void
    {
        self::assertSame(self::CONNECTION, new ClientIp(null)->of(self::request()));
    }

    public function testAForgedHeaderIsIgnoredWhenNoHeaderIsConfigured(): void
    {
        self::assertSame(self::CONNECTION, new ClientIp(null)->of(self::request(['CF-Connecting-IP' => '203.0.113.7'])));
        self::assertSame(self::CONNECTION, new ClientIp('')->of(self::request(['CF-Connecting-IP' => '203.0.113.7'])), 'an empty variable is no header');
    }

    public function testTheConfiguredHeaderGivesTheAddress(): void
    {
        self::assertSame('203.0.113.7', new ClientIp('CF-Connecting-IP')->of(self::request(['CF-Connecting-IP' => '203.0.113.7'])));
    }

    public function testTheNameOfTheHeaderIsCaseInsensitive(): void
    {
        self::assertSame('203.0.113.7', new ClientIp('cf-connecting-ip')->of(self::request(['CF-Connecting-IP' => '203.0.113.7'])));
    }

    public function testOptionalWhitespaceAroundTheValueIsNotPartOfTheAddress(): void
    {
        self::assertSame('203.0.113.7', new ClientIp('CF-Connecting-IP')->of(self::request(['CF-Connecting-IP' => " 203.0.113.7\t"])));
    }

    public function testAnotherHeaderThanTheConfiguredOneIsIgnored(): void
    {
        self::assertSame(self::CONNECTION, new ClientIp('CF-Connecting-IP')->of(self::request(['X-Forwarded-For' => '203.0.113.7'])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNoSingleAddress(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'two addresses' => ['203.0.113.7, 198.51.100.1'];
        yield 'two addresses without a space' => ['203.0.113.7,198.51.100.1'];
        yield 'a host name' => ['example.com'];
        yield 'garbage' => ['not-an-address'];
        yield 'an address with a port' => ['203.0.113.7:443'];
        yield 'an IPv6 address in brackets' => ['[2001:db8::1]'];
        yield 'an IPv6 address with a zone' => ['fe80::1%eth0'];
        yield 'an address with a NUL' => ["203.0.113.7\0"];
        yield 'an address with a line separator' => ["203.0.113.7\u{2028}"];
        yield 'an address with a no-break space' => ["\u{00A0}203.0.113.7"];
        yield 'an IPv4 address out of range' => ['203.0.113.256'];
        yield 'a prefix' => ['2001:db8::/64'];
    }

    #[DataProvider('valuesThatAreNoSingleAddress')]
    public function testAValueThatIsNoSingleAddressFallsBackToTheConnection(string $value): void
    {
        self::assertSame(self::CONNECTION, new ClientIp('CF-Connecting-IP')->of(self::request(['CF-Connecting-IP' => $value])));
    }

    public function testTheHeaderSentTwiceFallsBackToTheConnection(): void
    {
        $request = self::request();
        $request->headers->set('CF-Connecting-IP', ['203.0.113.7', '198.51.100.1']);

        self::assertSame(self::CONNECTION, new ClientIp('CF-Connecting-IP')->of($request));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ipv6Addresses(): iterable
    {
        yield 'full' => ['2001:0db8:abcd:0012:0001:0002:0003:0004', '2001:db8:abcd:12::/64'];
        yield 'compressed, upper case' => ['2001:DB8:ABCD:12::1', '2001:db8:abcd:12::/64'];
        yield 'another host of the same /64' => ['2001:db8:abcd:12:ffff:ffff:ffff:ffff', '2001:db8:abcd:12::/64'];
        yield 'another /64' => ['2001:db8:abcd:13::1', '2001:db8:abcd:13::/64'];
        yield 'loopback' => ['::1', '::/64'];
    }

    #[DataProvider('ipv6Addresses')]
    public function testAnIpv6AddressCountsByItsSlash64Prefix(string $address, string $key): void
    {
        self::assertSame($key, new ClientIp('CF-Connecting-IP')->of(self::request(['CF-Connecting-IP' => $address])));
        self::assertSame($key, new ClientIp(null)->of(self::request(connection: $address)), 'the address of the connection too');
    }

    public function testAnIpv4MappedIpv6AddressCountsAsItsIpv4Address(): void
    {
        self::assertSame('203.0.113.7', new ClientIp('CF-Connecting-IP')->of(self::request(['CF-Connecting-IP' => '::ffff:203.0.113.7'])));
        self::assertSame('203.0.113.7', new ClientIp(null)->of(self::request(connection: '::ffff:203.0.113.7')));
    }

    public function testARequestWithoutTheAddressOfTheConnectionHasOneSharedKey(): void
    {
        $request = Request::create('/api/v1/orders', 'POST');
        $request->server->remove('REMOTE_ADDR');

        self::assertSame('unknown', new ClientIp(null)->of($request));
        self::assertSame('unknown', new ClientIp('CF-Connecting-IP')->of($request));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(array $headers = [], string $connection = self::CONNECTION): Request
    {
        $request = Request::create('/api/v1/orders', 'POST', server: ['REMOTE_ADDR' => $connection]);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
