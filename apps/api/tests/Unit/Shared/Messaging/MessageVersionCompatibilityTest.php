<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Messaging;

use App\Shared\Domain\Event\DomainEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the shape of every message that waits on a Doctrine transport (the `routing` of messenger.yaml) and of every
 * domain event, so that the previous and the next version of the code read each other's messages during a rollout
 * (apps/api/CLAUDE.md, "Zgodność wersji"; spec docs/features/async-reliability/spec.md, D3 and W11).
 *
 * The golden records in tests/Fixtures/MessageSerialization hold what the production serializers wrote: the PHP
 * serializer of the Doctrine transports and, for the events, the JSON of the `events` transport, each in an envelope
 * with the stamps that production leaves on the message (without ErrorDetailsStamp, whose FlattenException is not
 * deterministic). `unserialize` does not call the constructor: a field added to a `readonly` class stays
 * uninitialized and an unknown field fails, so a changed shape must be a new class, sent only in the version after
 * the one that handles it.
 *
 * Records are only added, never overwritten. A new class (or a Symfony stamp whose serialization changed) gets a
 * record next to the old ones. A record of a class that is gone from the code is removed together with its handler.
 * To record a class, encode an envelope of a sample message with the stamps of the existing files through the
 * serializers of the container (PhpSerializer, `messenger.transport.symfony_serializer`) and write them in the format
 * of the existing files: {"class", "records": [{"serializer": "php"|"json", "variant", "encoded": {"body", "headers"?}}]}.
 */
final class MessageVersionCompatibilityTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/MessageSerialization';

    /**
     * @param class-string $class
     */
    #[DataProvider('messageClasses')]
    public function testEveryMessageClassHasGoldenRecords(string $class): void
    {
        $records = self::records()[$class] ?? [];

        self::assertNotSame([], array_filter($records, static fn (array $r): bool => 'php' === $r['serializer']), \sprintf('%s has no golden record of the PHP serializer; add one (see the docblock of this test).', $class));
        if (is_subclass_of($class, DomainEvent::class)) {
            self::assertNotSame([], array_filter($records, static fn (array $r): bool => 'json' === $r['serializer']), \sprintf('%s has no golden record of the JSON of `events`; add one (see the docblock of this test).', $class));
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('messageClasses')]
    public function testGoldenRecordsDecodeToFullyInitializedObjects(string $class): void
    {
        $records = self::records()[$class] ?? [];
        self::assertNotSame([], $records, $class.' has no golden records');
        foreach ($records as $record) {
            $envelope = $this->serializer($record['serializer'])->decode($record['encoded']);
            $label = \sprintf('%s, %s record "%s"', $class, $record['serializer'], $record['variant']);

            self::assertInstanceOf($class, $envelope->getMessage(), $label.': the message does not decode to the class');
            foreach (self::objectsOf($envelope) as $object) {
                if (str_starts_with($object::class, 'App\\')) {
                    self::assertAllPropertiesInitialized($object, $label);
                }
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('messageClasses')]
    public function testApplicationClassesEncodeToTheSameRecords(string $class): void
    {
        $records = self::records()[$class] ?? [];
        self::assertNotSame([], $records, $class.' has no golden records');
        foreach ($records as $record) {
            $serializer = $this->serializer($record['serializer']);
            $envelope = $serializer->decode($record['encoded']);
            $encoded = $serializer->encode($envelope);
            $label = \sprintf('%s, %s record "%s"', $class, $record['serializer'], $record['variant']);

            self::assertInstanceOf($class, $envelope->getMessage(), $label.': the message does not decode to the class');

            if ('php' === $record['serializer']) {
                $golden = stripslashes($record['encoded']['body']);
                foreach (self::objectsOf($envelope) as $object) {
                    if (str_starts_with($object::class, 'App\\')) {
                        self::assertStringContainsString(serialize($object), $golden, $label.': '.$object::class.' is serialized differently than in the record');
                    }
                }

                continue;
            }

            self::assertSame($record['encoded']['body'], $encoded['body'], $label.': the JSON body differs from the record');
            foreach ($record['encoded']['headers'] ?? [] as $name => $value) {
                if (str_starts_with($name, 'X-Message-Stamp-App\\')) {
                    self::assertSame($value, $encoded['headers'][$name] ?? null, $label.': the header '.$name.' differs from the record');
                }
            }
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function messageClasses(): iterable
    {
        foreach (self::discoverMessageClasses() as $class) {
            yield $class => [$class];
        }
    }

    private function serializer(string $kind): SerializerInterface
    {
        if ('php' === $kind) {
            return new PhpSerializer();
        }

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');

        return $serializer;
    }

    /**
     * @return list<object>
     */
    private static function objectsOf(Envelope $envelope): array
    {
        return [$envelope->getMessage(), ...array_merge([], ...array_values($envelope->all()))];
    }

    private static function assertAllPropertiesInitialized(object $object, string $label): void
    {
        foreach ((new \ReflectionObject($object))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            self::assertTrue($property->isInitialized($object), \sprintf('%s: %s::$%s is not initialized (a field added to the class)', $label, $object::class, $property->getName()));
        }
    }

    /**
     * Classes routed to a transport in messenger.yaml plus every implementation of DomainEvent.
     *
     * @return list<class-string>
     */
    private static function discoverMessageClasses(): array
    {
        /** @var array{framework: array{messenger: array{routing: array<string, string>}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/messenger.yaml');
        $classes = [];
        foreach (array_keys($config['framework']['messenger']['routing']) as $routed) {
            if (class_exists($routed)) {
                $classes[] = $routed;
            }
        }

        $source = \dirname(__DIR__, 4).'/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || !str_contains((string) file_get_contents($file->getPathname()), 'DomainEvent')) {
                continue;
            }
            $class = 'App\\'.str_replace('/', '\\', substr($file->getPathname(), \strlen($source) + 1, -4));
            if (class_exists($class) && is_subclass_of($class, DomainEvent::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * @return array<string, list<array{serializer: string, variant: string, encoded: array{body: string, headers?: array<string, string>}}>>
     */
    private static function records(): array
    {
        $records = [];
        foreach (glob(self::FIXTURES.'/*.json') ?: [] as $path) {
            /** @var array{class: string, records: list<array{serializer: string, variant: string, encoded: array{body: string, headers?: array<string, string>}}>} $file */
            $file = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
            $records[$file['class']] = [...($records[$file['class']] ?? []), ...$file['records']];
        }

        return $records;
    }
}
