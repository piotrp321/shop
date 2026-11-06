<?php

declare(strict_types=1);

namespace App\Tests\Unit\PaymentSimulator;

use App\PaymentSimulator\CancelResult;
use App\PaymentSimulator\Transaction;
use App\PaymentSimulator\TransactionStatus;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    private const string NOW = '2026-11-06 12:00:00 UTC';

    public function testADecisionOnAnOpenTransactionIsStoredWithItsTime(): void
    {
        $paid = self::transfer();
        $rejected = self::card();

        self::assertTrue($paid->decide(true, self::at(self::NOW)));
        self::assertTrue($rejected->decide(false, self::at(self::NOW)));

        self::assertSame(TransactionStatus::Paid, $paid->status);
        self::assertEquals(self::at(self::NOW), $paid->decidedAt);
        self::assertSame(TransactionStatus::Rejected, $rejected->status);
        self::assertEquals(self::at(self::NOW), $rejected->decidedAt);
    }

    public function testASecondDecisionChangesNothing(): void
    {
        $transaction = self::transfer();
        $transaction->decide(true, self::at(self::NOW));

        self::assertFalse($transaction->decide(false, self::at('2026-11-06 12:01:00 UTC')));
        self::assertFalse($transaction->decide(true, self::at('2026-11-06 12:01:00 UTC')));

        self::assertSame(TransactionStatus::Paid, $transaction->status);
        self::assertEquals(self::at(self::NOW), $transaction->decidedAt);
    }

    public function testAnExpiredTransactionIsNotDecidedAndStaysOpen(): void
    {
        $transaction = self::transfer(expiresAt: '2026-11-06 12:30:00 UTC');

        self::assertFalse($transaction->decide(true, self::at('2026-11-06 12:30:00 UTC')));

        self::assertSame(TransactionStatus::Open, $transaction->status);
        self::assertNull($transaction->decidedAt);
    }

    public function testACancelledTransactionIsNotDecided(): void
    {
        $transaction = self::card();
        $transaction->cancel();

        self::assertFalse($transaction->decide(true, self::at(self::NOW)));

        self::assertSame(TransactionStatus::Cancelled, $transaction->status);
        self::assertNull($transaction->decidedAt);
    }

    public function testCancellingAnOpenTransactionCancelsItAndCancellingAgainIsStillASuccess(): void
    {
        $transaction = self::transfer();

        self::assertSame(CancelResult::Cancelled, $transaction->cancel());
        self::assertSame(CancelResult::Cancelled, $transaction->cancel());

        self::assertSame(TransactionStatus::Cancelled, $transaction->status);
    }

    public function testAnExpiredButOpenTransactionCanBeCancelled(): void
    {
        $transaction = self::transfer(expiresAt: '2026-11-06 12:30:00 UTC');

        self::assertSame(CancelResult::Cancelled, $transaction->cancel());
    }

    public function testAClosedTransactionIsNotCancelledAndReportsHowItEnded(): void
    {
        $paid = self::transfer();
        $paid->decide(true, self::at(self::NOW));
        $rejected = self::card();
        $rejected->decide(false, self::at(self::NOW));

        self::assertSame(CancelResult::Paid, $paid->cancel());
        self::assertSame(CancelResult::Rejected, $rejected->cancel());
        self::assertSame(TransactionStatus::Paid, $paid->status);
        self::assertSame(TransactionStatus::Rejected, $rejected->status);
    }

    public function testItIsClosedWhenDecidedCancelledOrExpired(): void
    {
        $open = self::transfer(expiresAt: '2026-11-06 12:30:00 UTC');
        self::assertFalse($open->isClosed(self::at('2026-11-06 12:29:59 UTC')));
        self::assertTrue($open->isClosed(self::at('2026-11-06 12:30:00 UTC')));

        $decided = self::transfer();
        $decided->decide(false, self::at(self::NOW));
        self::assertTrue($decided->isClosed(self::at(self::NOW)));

        $cancelled = self::transfer();
        $cancelled->cancel();
        self::assertTrue($cancelled->isClosed(self::at(self::NOW)));
    }

    public function testTheReturnUrlOfTransferIsOneAndOfCardDependsOnTheOutcome(): void
    {
        $transfer = self::transfer();
        self::assertSame('https://shop.test/return', $transfer->returnUrl());
        $transfer->decide(true, self::at(self::NOW));
        self::assertSame('https://shop.test/return', $transfer->returnUrl());

        $open = self::card();
        $paid = self::card();
        $paid->decide(true, self::at(self::NOW));
        $rejected = self::card();
        $rejected->decide(false, self::at(self::NOW));
        $cancelled = self::card();
        $cancelled->cancel();

        self::assertSame('https://shop.test/cancel', $open->returnUrl(), 'without a decision the customer goes back');
        self::assertSame('https://shop.test/success', $paid->returnUrl());
        self::assertSame('https://shop.test/cancel', $rejected->returnUrl());
        self::assertSame('https://shop.test/cancel', $cancelled->returnUrl());
    }

    private static function transfer(string $expiresAt = '2026-11-06 12:30:00 UTC'): Transaction
    {
        return Transaction::openTransfer('0f8b6c1e-2d3a-4b5c-8d9e-0a1b2c3d4e5f', 'R-100001', 12_345, 'PLN', 'https://shop.test/return', self::at($expiresAt), self::at('2026-11-06 11:59:00 UTC'));
    }

    private static function card(): Transaction
    {
        return Transaction::openCard('0f8b6c1e-2d3a-4b5c-8d9e-0a1b2c3d4e5f', 'R-100001', 12_345, 'PLN', 'https://shop.test/success', 'https://shop.test/cancel', self::at('2026-11-06 12:30:00 UTC'), self::at('2026-11-06 11:59:00 UTC'));
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }
}
