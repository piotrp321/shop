<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cart;

use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Support\InputConformance\InputValues;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The rate limits of the guest carts and of the preview of a discount code (spec: docs/features/abuse-limits/spec.md,
 * L2, L3, D2, D3) with the small limits of `when@test` (config/packages/rate_limiter.yaml), which live as long as the
 * kernel of one test: guest_cart_create on the creation of a guest cart, guest_cart_write on the changes of its lines
 * (one counter for POST, PUT and DELETE), discount_preview on the reads of the guest and of the customer cart with a
 * `discountCode` other than the empty string. All count by the address of the client (ClientIp), before the
 * controller, and every attempt counts, also one that the validation refuses. On shop_test, rolled back after each
 * test.
 */
final class CartRateLimitEndpointTest extends WebTestCase
{
    use CartEndpointTestTrait;
    use Factories;

    /** The limits of `when@test`. */
    private const int CREATE_LIMIT = 10;
    private const int WRITE_LIMIT = 30;
    private const int PREVIEW_LIMIT = 20;

    private const int WINDOW = 15 * 60;

    private const string ADDRESS = '198.51.100.1';
    private const string OTHER_ADDRESS = '198.51.100.2';
    private const string TOO_MANY = 'Zbyt wiele prób. Spróbuj ponownie za kilka minut.';

    protected function tearDown(): void
    {
        unset($_SERVER['CLIENT_IP_HEADER'], $_ENV['CLIENT_IP_HEADER']);
        putenv('CLIENT_IP_HEADER');
        parent::tearDown();
    }

    public function testOverTheLimitOfNewGuestCartsTheCreationIsRefusedBeforeACartIsMadeAndAnotherAddressPasses(): void
    {
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= self::CREATE_LIMIT; ++$attempt) {
            $this->request('POST', '/api/v1/guest-carts');
            self::assertResponseStatusCodeSame(201);
        }
        $carts = $this->guestCarts();

        $this->assertTooManyRequests($this->request('POST', '/api/v1/guest-carts'));
        self::assertSame($carts, $this->guestCarts(), 'no cart is made');

        $this->from(self::OTHER_ADDRESS);
        $this->request('POST', '/api/v1/guest-carts');
        self::assertResponseStatusCodeSame(201, 'another address has its own limit');
    }

    public function testAddChangeAndRemoveOfLinesShareOneLimitPerAddressAcrossCartsAndARefusalChangesNothing(): void
    {
        $this->from(self::ADDRESS);
        $first = $this->guestCart();
        $second = $this->guestCart();
        $shoes = $this->variant(10_000, 500);
        $writes = [
            fn (): array => $this->request('POST', $first.'/items', self::item($shoes, 1)),
            fn (): array => $this->request('PUT', $first.'/items/'.$shoes, '{"quantity": 2}'),
            fn (): array => $this->request('DELETE', $first.'/items/'.$shoes),
            fn (): array => $this->request('POST', $second.'/items', self::item($shoes, 1)),
            // The id of the cart in another letter case is the same cart and the same address.
            fn (): array => $this->request('PUT', self::upperCaseId($second).'/items/'.$shoes, '{"quantity": 3}'),
            // Refused by the validation (422): still an attempt.
            fn (): array => $this->request('POST', $second.'/items', '{"quantity": 0}'),
        ];
        for ($attempt = 0; $attempt < self::WRITE_LIMIT; ++$attempt) {
            $writes[$attempt % \count($writes)]();
            self::assertContains($this->response()->getStatusCode(), [200, 422]);
        }
        $lines = CartFixture::lines($this->connection, $this->cartId($second));
        self::assertNotSame([], $lines);

        $this->assertTooManyRequests($this->request('POST', $second.'/items', self::item($shoes, 1)));
        $this->assertTooManyRequests($this->request('PUT', $second.'/items/'.$shoes, '{"quantity": 9}'));
        $this->assertTooManyRequests($this->request('DELETE', $second.'/items/'.$shoes));
        $this->assertTooManyRequests($this->request('POST', $this->guestCartFrom(self::OTHER_ADDRESS, self::ADDRESS).'/items', self::item($shoes, 1)), 'a cart made from another address is limited by the address of the change');
        self::assertSame($lines, CartFixture::lines($this->connection, $this->cartId($second)), 'the refused changes changed nothing');

        $this->request('GET', $second);
        self::assertResponseStatusCodeSame(200, 'a read without a code is not limited');
        $this->request('GET', $second.'/summary');
        self::assertResponseStatusCodeSame(200);
        $this->request('POST', '/api/v1/me/cart/items', self::item($shoes, 1), token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(200, 'the cart of a customer is not limited');

        $this->from(self::OTHER_ADDRESS);
        $this->request('POST', $second.'/items', self::item($shoes, 1));
        self::assertResponseStatusCodeSame(200, 'another address has its own limit, on the same cart');
    }

    public function testAForgedClientAddressHeaderIsIgnoredWithoutTheVariable(): void
    {
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= self::CREATE_LIMIT; ++$attempt) {
            $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.'.$attempt);
            $this->request('POST', '/api/v1/guest-carts');
            self::assertResponseStatusCodeSame(201);
        }

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.100');
        $this->assertTooManyRequests($this->request('POST', '/api/v1/guest-carts'));
    }

    public function testWithTheVariableTwoAddressesOrGarbageInTheHeaderCountByTheConnection(): void
    {
        $this->restartWithClientIpHeader('CF-Connecting-IP');
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= self::CREATE_LIMIT; ++$attempt) {
            $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', 0 === $attempt % 2 ? '203.0.113.7, 203.0.113.'.$attempt : 'not-an-address-'.$attempt);
            $this->request('POST', '/api/v1/guest-carts');
            self::assertResponseStatusCodeSame(201);
        }

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.7, 203.0.113.8');
        $this->assertTooManyRequests($this->request('POST', '/api/v1/guest-carts'));
        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.8');
        $this->request('POST', '/api/v1/guest-carts');
        self::assertResponseStatusCodeSame(201, 'a valid header is a client of its own behind the same connection');
    }

    public function testAnIpv6ClientCountsByItsSlash64(): void
    {
        $this->restartWithClientIpHeader('CF-Connecting-IP');
        $shoes = $this->variant(10_000, 500);
        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:12::1');
        $cart = $this->guestCart();
        for ($attempt = 1; $attempt <= self::WRITE_LIMIT; ++$attempt) {
            $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:12::'.dechex($attempt));
            $this->request('POST', $cart.'/items', self::item($shoes, 1));
            self::assertResponseStatusCodeSame(200);
        }

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:12:ffff::1');
        $this->assertTooManyRequests($this->request('POST', $cart.'/items', self::item($shoes, 1)));
        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:13::1');
        $this->request('POST', $cart.'/items', self::item($shoes, 1));
        self::assertResponseStatusCodeSame(200, 'another /64 is not limited');
    }

    public function testEveryPreviewOfACodeUsesOneLimitPerAddressOnBothCartsAndAReadWithoutACodeNone(): void
    {
        $this->from(self::ADDRESS);
        $this->code('WITAJ10');
        $shoes = $this->variant(10_000, 500);
        $first = $this->guestCartWith($shoes);
        $second = $this->guestCartWith($shoes);
        $customer = TestTokenFactory::customer();

        // No parameter, an empty one and one without a value are reads without a code: never limited.
        for ($attempt = 1; $attempt <= 2 * self::PREVIEW_LIMIT; ++$attempt) {
            foreach ([$first, $first.'?discountCode=', $first.'?discountCode'] as $read) {
                $view = $this->request('GET', $read);
                self::assertResponseStatusCodeSame(200);
                self::assertNull($view['discount'], 'no code');
            }
        }
        $this->request('GET', '/api/v1/me/cart?discountCode=', token: $customer);
        self::assertResponseStatusCodeSame(200);

        $blank = array_values(array_map(static fn (array $value): string => $value[0], iterator_to_array(InputValues::blankUnicodeTexts())));
        $previews = [
            fn (): array => $this->request('GET', $first.'?discountCode=WITAJ10'),
            fn (): array => $this->request('GET', $first.'?discountCode=witaj10'),
            fn (): array => $this->request('GET', $second.'?discountCode='.rawurlencode(' WITAJ10 ')),
            fn (): array => $this->request('GET', $second.'?discountCode='.rawurlencode("\u{00A0}WITAJ10\u{00A0}")),
            fn (): array => $this->request('GET', '/api/v1/me/cart?discountCode=WITAJ10', token: $customer),
            fn (): array => $this->request('GET', self::upperCaseId($first).'?discountCode=WITAJ10'),
            fn (): array => $this->request('GET', $first.'?discountCode='.rawurlencode(' ')),
            fn (): array => $this->request('GET', $first.'?discountCode='.rawurlencode($blank[0])),
            // Refused by the validation (400): still attempts.
            fn (): array => $this->request('GET', $first.'?discountCode[]='),
            fn (): array => $this->request('GET', '/api/v1/me/cart?discountCode='.str_repeat('A', 65), token: $customer),
        ];
        for ($attempt = 0; $attempt < self::PREVIEW_LIMIT; ++$attempt) {
            $previews[$attempt % \count($previews)]();
            self::assertContains($this->response()->getStatusCode(), [200, 400]);
        }

        $this->assertTooManyRequests($this->request('GET', $first.'?discountCode=WITAJ10'));
        $this->assertTooManyRequests($this->request('GET', $second.'?discountCode=INNY'));
        $this->assertTooManyRequests($this->request('GET', '/api/v1/me/cart?discountCode=WITAJ10', token: $customer), 'the route of the customer shares the limit of the address');
        $this->assertTooManyRequests($this->request('GET', '/api/v1/me/cart?discountCode='.rawurlencode($blank[1]), token: $customer));
        $this->assertTooManyRequests($this->request('GET', $first.'?discountCode[x]=1'));

        $view = $this->request('GET', $first);
        self::assertResponseStatusCodeSame(200, 'the cart without a code is still read');
        self::assertNull($view['discount']);
        self::assertIsArray($view['totals']);
        self::assertSame(10_000, $view['totals']['gross']);
        $this->request('GET', $first.'?discountCode=');
        self::assertResponseStatusCodeSame(200);
        $this->request('GET', '/api/v1/me/cart', token: $customer);
        self::assertResponseStatusCodeSame(200);

        $this->from(self::OTHER_ADDRESS);
        $view = $this->request('GET', $first.'?discountCode=WITAJ10');
        self::assertResponseStatusCodeSame(200, 'another address has its own limit');
        self::assertIsArray($view['discount']);
        self::assertSame('applied', $view['discount']['status']);
    }

    public function testAForgedClientAddressHeaderDoesNotResetTheLimitOfPreviews(): void
    {
        $this->from(self::ADDRESS);
        $cart = $this->guestCartWith($this->variant(10_000, 500));
        for ($attempt = 1; $attempt <= self::PREVIEW_LIMIT; ++$attempt) {
            $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.'.$attempt);
            $this->request('GET', $cart.'?discountCode=KOD'.$attempt);
            self::assertResponseStatusCodeSame(200);
        }

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.100');
        $this->assertTooManyRequests($this->request('GET', $cart.'?discountCode=KOD'));
    }

    public function testAnIpv6ClientCountsItsPreviewsByItsSlash64(): void
    {
        $this->restartWithClientIpHeader('CF-Connecting-IP');
        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:12::1');
        $cart = $this->guestCartWith($this->variant(10_000, 500));
        for ($attempt = 1; $attempt <= self::PREVIEW_LIMIT; ++$attempt) {
            $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:12::'.dechex($attempt));
            $this->request('GET', $cart.'?discountCode=KOD');
            self::assertResponseStatusCodeSame(200);
        }

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:12:ffff::1');
        $this->assertTooManyRequests($this->request('GET', $cart.'?discountCode=KOD'));
        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:13::1');
        $this->request('GET', $cart.'?discountCode=KOD');
        self::assertResponseStatusCodeSame(200, 'another /64 is not limited');
    }

    private function from(string $address): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', $address);
    }

    /**
     * A new kernel that reads CLIENT_IP_HEADER (the variable is read when the kernel starts).
     */
    private function restartWithClientIpHeader(string $header): void
    {
        $_SERVER['CLIENT_IP_HEADER'] = $_ENV['CLIENT_IP_HEADER'] = $header;
        putenv('CLIENT_IP_HEADER='.$header);
        self::ensureKernelShutdown();
        $this->setUp();
    }

    private function guestCart(): string
    {
        $cart = $this->request('POST', '/api/v1/guest-carts');
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($cart['id']);

        return '/api/v1/guest-carts/'.$cart['id'];
    }

    /**
     * A guest cart made from $address, after which requests come from $then again.
     */
    private function guestCartFrom(string $address, string $then): string
    {
        $this->from($address);
        $cart = $this->guestCart();
        $this->from($then);

        return $cart;
    }

    /**
     * A guest cart with one unit of the variant, as a fixture: it uses no limit.
     */
    private function guestCartWith(string $variant): string
    {
        return '/api/v1/guest-carts/'.CartFixture::cart($this->connection, lines: [[$variant, 1]]);
    }

    private function cartId(string $path): string
    {
        return substr($path, \strlen('/api/v1/guest-carts/'));
    }

    /**
     * The path of the cart with its id in an equivalent form (upper case): the same cart.
     */
    private static function upperCaseId(string $path): string
    {
        $id = substr($path, \strlen('/api/v1/guest-carts/'));
        $forms = iterator_to_array(InputValues::equivalentUuidForms($id));

        return '/api/v1/guest-carts/'.$forms['upper case'][0];
    }

    private static function item(string $variantId, int $quantity): string
    {
        return json_encode(['variantId' => $variantId, 'quantity' => $quantity], \JSON_THROW_ON_ERROR);
    }

    private function code(string $code): void
    {
        $stored = DiscountCodeFactory::createOne(['code' => $code]);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($stored);
        $this->entityManager->clear();
    }

    private function guestCarts(): int
    {
        $count = $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE owner_type = 'guest'");
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertTooManyRequests(array $problem, string $message = ''): void
    {
        self::assertResponseStatusCodeSame(429, $message);
        $this->assertProblem(429, self::TOO_MANY, $problem);
        self::assertSame(['type' => 'about:blank', 'title' => 'Too Many Requests', 'status' => 429, 'detail' => self::TOO_MANY], $problem, $message);
        $retryAfter = $this->response()->headers->get('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/D', $retryAfter, 'whole seconds');
        self::assertLessThanOrEqual(self::WINDOW + 1, (int) $retryAfter, 'at most the window, rounded up to whole seconds');
    }
}
