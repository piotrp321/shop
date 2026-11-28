<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Indexing;

use App\Shared\Infrastructure\Indexing\DocumentIndexer;
use App\Shared\Infrastructure\Indexing\IndexDocuments;
use App\Shared\Infrastructure\Indexing\IndexDocumentsHandler;
use App\Shared\Infrastructure\Indexing\UnknownDocumentType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class IndexDocumentsHandlerTest extends TestCase
{
    public function testTheBatchGoesToTheIndexerOfItsDocumentType(): void
    {
        $product = new RecordingDocumentIndexer('product');
        $other = new RecordingDocumentIndexer('category');
        $message = new IndexDocuments('product', ['00000000-0000-4000-8000-000000000001'], '00000000-0000-7000-8000-0000000000aa', 3);

        (new IndexDocumentsHandler([$other, $product]))($message);

        self::assertSame([$message], $product->indexed);
        self::assertSame([], $other->indexed);
    }

    public function testAnUnknownDocumentTypeIsAPermanentFailureThatInstantiatesNothing(): void
    {
        $product = new RecordingDocumentIndexer('product');

        try {
            (new IndexDocumentsHandler([$product]))(new IndexDocuments('App\Catalog\Infrastructure\Persistence\Doctrine\Record\ProductRecord', ['x'], 'run', 0));
            self::fail('Expected a permanent failure.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertInstanceOf(UnknownDocumentType::class, $e->getPrevious());
            self::assertStringNotContainsString('ProductRecord', $e->getMessage(), 'The key from the queue is not echoed.');
        }
        self::assertSame([], $product->indexed);
    }

    public function testTwoIndexersOfOneTypeAreAConfigurationError(): void
    {
        $this->expectException(\LogicException::class);

        new IndexDocumentsHandler([new RecordingDocumentIndexer('product'), new RecordingDocumentIndexer('product')]);
    }
}

final class RecordingDocumentIndexer implements DocumentIndexer
{
    /** @var list<IndexDocuments> */
    public array $indexed = [];

    public function __construct(private readonly string $type)
    {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function index(IndexDocuments $message): void
    {
        $this->indexed[] = $message;
    }
}
