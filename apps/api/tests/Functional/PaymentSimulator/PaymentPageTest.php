<?php

declare(strict_types=1);

namespace App\Tests\Functional\PaymentSimulator;

use App\PaymentSimulator\DeliverSimulatedWebhook;
use App\PaymentSimulator\Provider;
use App\PaymentSimulator\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The payment page of the simulator: HTML without scripts, the states of a transaction, the decision POST with its
 * CSRF token, the redirect back to the shop.
 */
final class PaymentPageTest extends WebTestCase
{
    use SimulatorEndpointTestTrait;

    private const string ABOUT = 'To symulacja: żadne pieniądze nie zostaną pobrane.';
    private const string CLOSED = 'Ta płatność jest już zamknięta.';

    protected function setUp(): void
    {
        $this->startClient();
    }

    public function testAnOpenTransferPageShowsTheOrderTheAmountTheChoicesAndTheWayBack(): void
    {
        $transaction = $this->transfer('R-100042');

        $crawler = $this->client->request('GET', $this->pageUrl($transaction));

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $text = $crawler->filterXPath('//main')->text();
        self::assertStringContainsString(self::ABOUT, $text);
        self::assertStringContainsString('Szybki przelew (demo)', $text);
        self::assertStringContainsString('R-100042', $text);
        self::assertStringContainsString('123,45 PLN', $text);
        self::assertStringNotContainsString(self::CLOSED, $text);
        self::assertSame(['Zapłać', 'Odrzuć płatność'], $crawler->filterXPath('//form//button')->each(static fn ($button): string => $button->text()));
        self::assertSame(['pay', 'reject'], $crawler->filterXPath('//form//button')->each(static fn ($button): ?string => $button->attr('value')));
        self::assertSame($this->csrfToken($transaction->id, Provider::DemoTransfer), $crawler->filterXPath('//form//input[@name="_token"]')->attr('value'));
        self::assertSame('post', strtolower((string) $crawler->filterXPath('//form')->attr('method')));
        self::assertSame('https://shop.test/checkout/confirmation', $crawler->selectLink('Wróć do sklepu')->attr('href'));
        self::assertCount(0, $crawler->filterXPath('//script'), 'no JavaScript');
    }

    public function testAnOpenCardPageNamesTheProviderAndGoesBackToTheCancelUrl(): void
    {
        $transaction = $this->card();

        $crawler = $this->client->request('GET', $this->pageUrl($transaction));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Karta płatnicza (demo)', $crawler->filterXPath('//main')->text());
        self::assertSame('https://shop.test/checkout/confirmation?result=cancel', $crawler->selectLink('Wróć do sklepu')->attr('href'));
        self::assertSame($this->csrfToken($transaction->id, Provider::DemoCard), $crawler->filterXPath('//form//input[@name="_token"]')->attr('value'));
    }

    public function testTheValuesOfTheTransactionAreEscaped(): void
    {
        $transaction = $this->transfer('<script>alert("x")</script>&"\'');

        $this->client->request('GET', $this->pageUrl($transaction));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&amp;&quot;&#039;', $html);
    }

    public function testTheReturnLinkIsEscapedToo(): void
    {
        $transaction = $this->transfer();
        $this->connection()->executeStatement('UPDATE payment_simulator_transaction SET return_url = ? WHERE id = ?', ['https://shop.test/back?a=1&b="><i>', $transaction->id]);
        $this->entityManager()->clear();

        $this->client->request('GET', $this->pageUrl($transaction));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringContainsString('href="https://shop.test/back?a=1&amp;b=&quot;&gt;&lt;i&gt;"', $html);
    }

    /**
     * @return iterable<string, array{string}> how a transaction was closed
     */
    public static function closings(): iterable
    {
        yield 'paid' => ['paid'];
        yield 'rejected' => ['rejected'];
        yield 'cancelled' => ['cancelled'];
        yield 'expired' => ['expired'];
    }

    #[DataProvider('closings')]
    public function testAClosedTransactionShowsTheMessageWithoutButtonsAndTheWayBack(string $closing): void
    {
        $transaction = 'expired' === $closing ? $this->transfer(expiresAt: '-1 minute') : $this->transfer();
        if ('expired' !== $closing) {
            $decided = \in_array($closing, ['paid', 'rejected'], true) ? ', decided_at = now()' : '';
            $this->connection()->executeStatement('UPDATE payment_simulator_transaction SET status = ?'.$decided.' WHERE id = ?', [$closing, $transaction->id]);
            $this->entityManager()->clear();
        }

        $crawler = $this->client->request('GET', $this->pageUrl($transaction));

        self::assertResponseStatusCodeSame(200);
        $text = $crawler->filterXPath('//main')->text();
        self::assertStringContainsString(self::CLOSED, $text);
        self::assertStringContainsString(self::ABOUT, $text);
        self::assertCount(0, $crawler->filterXPath('//form'));
        self::assertCount(0, $crawler->filterXPath('//button'));
        self::assertSame('https://shop.test/checkout/confirmation', $crawler->selectLink('Wróć do sklepu')->attr('href'));
    }

    public function testAnUnknownTransactionIsAnHtml404(): void
    {
        foreach (['7d0e2a5e-0000-4000-8000-000000000000', 'not-a-uuid'] as $id) {
            $crawler = $this->client->request('GET', '/payment-simulator/demo-transfer/pay/'.$id);

            self::assertResponseStatusCodeSame(404, $id);
            self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
            self::assertStringContainsString('Nie znaleźliśmy tej płatności.', $crawler->filterXPath('//main')->text());
            self::assertCount(0, $crawler->filterXPath('//form'));
        }
    }

    public function testATransactionOfAnotherProviderIsNotFoundOnThisProvidersPage(): void
    {
        $transaction = $this->card();

        $crawler = $this->client->request('GET', '/payment-simulator/demo-transfer/pay/'.$transaction->id);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Nie znaleźliśmy tej płatności.', $crawler->filterXPath('//main')->text());
    }

    public function testPayingStoresThePaymentSendsOneDeliveryAndRedirectsBackToTheShop(): void
    {
        $transaction = $this->transfer();

        $this->post($transaction, 'pay');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', 'https://shop.test/checkout/confirmation');
        self::assertSame(['paid', true], $this->state($transaction->id));
        self::assertEquals([new DeliverSimulatedWebhook($transaction->id)], $this->sentDeliveries());
    }

    public function testRejectingStoresTheRejectionAndSendsOneDelivery(): void
    {
        $transaction = $this->transfer();

        $this->post($transaction, 'reject');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', 'https://shop.test/checkout/confirmation');
        self::assertSame(['rejected', true], $this->state($transaction->id));
        self::assertEquals([new DeliverSimulatedWebhook($transaction->id)], $this->sentDeliveries());
    }

    public function testACardPaymentGoesBackToTheSuccessUrlAndARejectionToTheCancelUrl(): void
    {
        $paid = $this->card();
        $rejected = $this->card();

        $this->post($paid, 'pay');
        self::assertResponseHeaderSame('Location', 'https://shop.test/checkout/confirmation?result=success');
        $this->post($rejected, 'reject');
        self::assertResponseHeaderSame('Location', 'https://shop.test/checkout/confirmation?result=cancel');

        self::assertSame(['paid', true], $this->state($paid->id));
        self::assertSame(['rejected', true], $this->state($rejected->id));
    }

    /**
     * @return iterable<string, array{?string}> the CSRF token to send, null for none
     */
    public static function wrongTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'an empty token' => [''];
        yield 'a wrong token' => [str_repeat('0', 64)];
        yield 'the token of another transaction' => ['other'];
    }

    #[DataProvider('wrongTokens')]
    public function testADecisionWithoutAValidCsrfTokenIsRefusedAndChangesNothing(?string $token): void
    {
        $transaction = $this->transfer();
        $other = $this->transfer();
        $token = 'other' === $token ? $this->csrfToken($other->id, Provider::DemoTransfer) : $token;

        $crawler = $this->post($transaction, 'pay', $token ?? false);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('Nieprawidłowy token formularza', $crawler->filterXPath('//main')->text());
        self::assertSame(['open', false], $this->state($transaction->id));
        self::assertSame([], $this->sentDeliveries());
    }

    public function testTheTokenOfTheOtherProvidersSecretIsRefused(): void
    {
        $transaction = $this->transfer();

        $this->post($transaction, 'pay', $this->csrfToken($transaction->id, Provider::DemoCard));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['open', false], $this->state($transaction->id));
    }

    public function testASecondDecisionOnlyRedirectsAndChangesNothing(): void
    {
        $transaction = $this->transfer();
        $this->post($transaction, 'pay');
        self::assertCount(1, $this->sentDeliveries(), 'one delivery for the decision');

        foreach (['reject', 'pay'] as $again) {
            $this->post($transaction, $again);

            self::assertResponseStatusCodeSame(303);
            self::assertResponseHeaderSame('Location', 'https://shop.test/checkout/confirmation');
            self::assertSame(['paid', true], $this->state($transaction->id));
            self::assertSame([], $this->sentDeliveries(), 'no delivery for the repeated decision');
        }
    }

    public function testADecisionOnACancelledOrAnExpiredTransactionOnlyRedirects(): void
    {
        $cancelled = $this->card();
        $this->connection()->executeStatement("UPDATE payment_simulator_transaction SET status = 'cancelled' WHERE id = ?", [$cancelled->id]);
        $expired = $this->transfer(expiresAt: '-1 minute');
        $this->entityManager()->clear();

        $this->post($cancelled, 'pay');
        self::assertResponseStatusCodeSame(303);
        self::assertResponseHeaderSame('Location', 'https://shop.test/checkout/confirmation?result=cancel');
        $this->post($expired, 'pay');
        self::assertResponseStatusCodeSame(303);

        self::assertSame(['cancelled', false], $this->state($cancelled->id));
        self::assertSame(['open', false], $this->state($expired->id));
        self::assertSame([], $this->sentDeliveries());
    }

    public function testAnUnknownDecisionIs400AndChangesNothing(): void
    {
        $transaction = $this->transfer();

        $this->post($transaction, 'refund');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['open', false], $this->state($transaction->id));
    }

    public function testADecisionOnAnUnknownTransactionIsAnHtml404(): void
    {
        $unknown = '7d0e2a5e-0000-4000-8000-000000000000';
        $crawler = $this->client->request('POST', '/payment-simulator/demo-transfer/pay/'.$unknown, ['_token' => $this->csrfToken($unknown, Provider::DemoTransfer), 'decision' => 'pay']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Nie znaleźliśmy tej płatności.', $crawler->filterXPath('//main')->text());
    }

    public function testAnUnknownProviderIs404(): void
    {
        $this->client->request('GET', '/payment-simulator/demo-bank/pay/7d0e2a5e-0000-4000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param string|false|null $token the CSRF token to send; null: the right one, false: none
     */
    private function post(Transaction $transaction, string $decision, string|false|null $token = null): Crawler
    {
        $fields = ['decision' => $decision];
        if (false !== $token) {
            $fields['_token'] = $token ?? $this->csrfToken($transaction->id, $transaction->provider);
        }

        return $this->client->request('POST', $this->pageUrl($transaction), $fields);
    }

    private function pageUrl(Transaction $transaction): string
    {
        return '/payment-simulator/'.$transaction->provider->value.'/pay/'.$transaction->id;
    }
}
