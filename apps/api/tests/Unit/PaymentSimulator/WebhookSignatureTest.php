<?php

declare(strict_types=1);

namespace App\Tests\Unit\PaymentSimulator;

use App\PaymentSimulator\WebhookSignature;
use App\Tests\Support\WebhookSignatureVector as Vector;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    public function testTheTransferSignatureIsTheHmacOfTheRawBodyOfTheSharedVector(): void
    {
        self::assertSame(Vector::TRANSFER_SIGNATURE, WebhookSignature::transfer(Vector::TRANSFER_SECRET, Vector::TRANSFER_BODY));
    }

    public function testTheCardSignatureIsTheHmacOfTheTimestampAndTheRawBodyOfTheSharedVector(): void
    {
        self::assertSame(Vector::CARD_HEADER, WebhookSignature::card(Vector::CARD_SECRET, Vector::CARD_TIMESTAMP, Vector::CARD_BODY));
    }

    public function testAnotherSecretBodyOrTimestampGivesAnotherSignature(): void
    {
        self::assertNotSame(Vector::TRANSFER_SIGNATURE, WebhookSignature::transfer('other-secret', Vector::TRANSFER_BODY));
        self::assertNotSame(Vector::TRANSFER_SIGNATURE, WebhookSignature::transfer(Vector::TRANSFER_SECRET, Vector::TRANSFER_BODY.' '));
        self::assertNotSame(Vector::CARD_HEADER, WebhookSignature::card(Vector::CARD_SECRET, Vector::CARD_TIMESTAMP + 1, Vector::CARD_BODY));
    }
}
