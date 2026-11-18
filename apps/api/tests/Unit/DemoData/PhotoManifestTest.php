<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Photo\InvalidPhotoManifest;
use App\DemoData\Photo\PhotoManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The format of fixtures/demo-data/photos.yaml: what a valid manifest gives and every way the schema refuses one.
 */
final class PhotoManifestTest extends TestCase
{
    private const array GROUPS = ['run-road', 'bike-road'];

    public function testAValidManifestGivesTheVersionTheKeyAndTheFiles(): void
    {
        $manifest = PhotoManifest::fromYaml(Yaml::dump($this->valid()), self::GROUPS);

        self::assertSame(3, $manifest->version);
        self::assertSame('demo-photos/v3/photos.tar', $manifest->key);
        self::assertCount(2, $manifest->files);
        self::assertSame('run-road-001.jpg', $manifest->files[0]->file);
        self::assertSame(str_repeat('a', 64), $manifest->files[0]->sha256);
        self::assertSame('run-road', $manifest->files[0]->group);
        self::assertSame('Biegacz na asfaltowej drodze', $manifest->files[0]->alt);
        self::assertSame('commons:129176897', $manifest->files[0]->sourceId);
        self::assertSame('original', $manifest->files[0]->variant);
        self::assertSame('https://commons.wikimedia.org/wiki/File:Runner_on_road,_20240106_V%C3%A5lerenga_(1).jpg', $manifest->files[0]->source);
        self::assertSame('Zażółć Gęślą', $manifest->files[0]->author);
        self::assertSame('CC0 1.0', $manifest->files[0]->license);
        self::assertSame('Public domain', $manifest->files[1]->license);
        self::assertSame(['run-road-001.jpg', 'bike-road-001.jpg'], $manifest->fileNames());
        self::assertSame(['run-road' => ['run-road-001.jpg'], 'bike-road' => ['bike-road-001.jpg']], $manifest->fileNamesByGroup());
    }

    public function testTheSameSourceIdWithDifferentVariantsIsAllowed(): void
    {
        $manifest = $this->valid();
        $manifest['files'][1] = self::changed($manifest['files'][1], ['sourceId' => 'commons:129176897', 'variant' => 'mirror-crop-left']);

        $parsed = PhotoManifest::fromYaml(Yaml::dump($manifest), self::GROUPS);

        self::assertSame('original', $parsed->files[0]->variant);
        self::assertSame('mirror-crop-left', $parsed->files[1]->variant);
        self::assertSame($parsed->files[0]->sourceId, $parsed->files[1]->sourceId);
    }

    #[DataProvider('variants')]
    public function testEveryVariantOfTheListIsAccepted(string $variant): void
    {
        $manifest = $this->valid();
        $manifest['files'][0]['variant'] = $variant;

        self::assertSame($variant, PhotoManifest::fromYaml(Yaml::dump($manifest), self::GROUPS)->files[0]->variant);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function variants(): iterable
    {
        foreach (['original', 'mirror', 'crop-left', 'crop-right', 'crop-top', 'crop-bottom', 'crop-center', 'mirror-crop-left', 'mirror-crop-right', 'mirror-crop-center'] as $variant) {
            yield $variant => [$variant];
        }
    }

    public function testTheFileOfTheRepositoryFormatCanBeRead(): void
    {
        $path = sys_get_temp_dir().'/photo-manifest-'.bin2hex(random_bytes(6)).'.yaml';
        file_put_contents($path, Yaml::dump($this->valid(), 4));

        try {
            self::assertSame(3, PhotoManifest::fromFile($path, self::GROUPS)->version);
        } finally {
            unlink($path);
        }
    }

    public function testAMissingFileIsReportedByItsName(): void
    {
        $this->expectException(InvalidPhotoManifest::class);
        $this->expectExceptionMessage('no-such-manifest.yaml');

        PhotoManifest::fromFile('/nonexistent/no-such-manifest.yaml', self::GROUPS);
    }

    /**
     * @param array<string, mixed> $top    changes of the manifest (null removes the key)
     * @param array<string, mixed> $first  changes of the first file
     * @param array<string, mixed> $second changes of the second file
     */
    #[DataProvider('invalidManifests')]
    public function testEveryViolationOfTheSchemaIsReported(array $top, array $first, array $second, string $expected): void
    {
        $manifest = $this->valid();
        $files = $manifest['files'];
        $files[0] = self::changed($files[0], $first);
        $files[1] = self::changed($files[1], $second);
        $manifest = self::changed($manifest, $top + ['files' => $files]);

        try {
            PhotoManifest::fromYaml(Yaml::dump($manifest), self::GROUPS);
            self::fail('The manifest should have been refused.');
        } catch (InvalidPhotoManifest $e) {
            self::assertSame(
                1,
                \count(array_filter($e->violations, static fn (string $violation): bool => str_contains($violation, $expected))),
                $expected.' in: '.implode(' | ', $e->violations),
            );
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>, string}>
     */
    public static function invalidManifests(): iterable
    {
        yield 'the version is not a whole number' => [['version' => '3'], [], [], 'version: a whole'];
        yield 'the version is zero' => [['version' => 0], [], [], 'version: a whole'];
        yield 'the key of another version' => [['key' => 'demo-photos/v2/photos.tar'], [], [], 'key'];
        yield 'the key outside the prefix' => [['key' => '../demo-photos/v3/photos.tar'], [], [], 'key'];
        yield 'the key of another archive name' => [['key' => 'demo-photos/v3/other.tar'], [], [], 'key'];
        yield 'the key is missing' => [['key' => null], [], [], 'key'];
        yield 'no files' => [['files' => []], [], [], 'files'];
        yield 'files is not a list' => [['files' => ['a' => 1]], [], [], 'files'];
        yield 'an unknown key of the manifest (the bucket)' => [['bucket' => 'shop-demo-photos'], [], [], 'unknown key "bucket"'];
        yield 'an unknown key of the manifest (the endpoint)' => [['endpoint' => 'https://x.r2.cloudflarestorage.com'], [], [], 'unknown key "endpoint"'];
        yield 'an unknown key of the manifest (an access key)' => [['accessKeyId' => 'AKIA0000'], [], [], 'unknown key "accessKeyId"'];
        yield 'an unknown key of a file (a secret)' => [[], ['secretAccessKey' => 'shh'], [], 'unknown key "secretAccessKey"'];
        yield 'a missing key of a file' => [[], ['author' => null], [], 'files[0].author'];
        yield 'a file with a directory' => [[], ['file' => 'sub/run.jpg'], [], 'files[0].file'];
        yield 'a file with a parent directory' => [[], ['file' => '../run.jpg'], [], 'files[0].file'];
        yield 'a file starting with a dot' => [[], ['file' => '.hidden.jpg'], [], 'files[0].file'];
        yield 'a file with a space' => [[], ['file' => 'run road.jpg'], [], 'files[0].file'];
        yield 'a file with a Polish letter' => [[], ['file' => 'bieg-łąka.jpg'], [], 'files[0].file'];
        yield 'a file name over 100 characters' => [[], ['file' => str_repeat('a', 97).'.jpg'], [], 'files[0].file'];
        yield 'a file that is not text' => [[], ['file' => 12], [], 'files[0].file'];
        yield 'a short sum' => [[], ['sha256' => str_repeat('a', 63)], [], 'files[0].sha256'];
        yield 'a sum in capitals' => [[], ['sha256' => str_repeat('A', 64)], [], 'files[0].sha256'];
        yield 'a sum that is not hex' => [[], ['sha256' => str_repeat('g', 64)], [], 'files[0].sha256'];
        yield 'an unknown group' => [[], ['group' => 'no-such-group'], [], 'files[0].group'];
        yield 'a blank alt text' => [[], ['alt' => "\u{00A0}\u{2003}"], [], 'files[0].alt'];
        yield 'an alt text with a control character' => [[], ['alt' => "Biegacz\u{0000}"], [], 'files[0].alt'];
        yield 'an alt text with a line separator' => [[], ['alt' => "Biegacz\u{2028}droga"], [], 'files[0].alt'];
        yield 'an alt text with a tab' => [[], ['alt' => "Biegacz\tdroga"], [], 'files[0].alt'];
        yield 'an alt text with spaces at the ends' => [[], ['alt' => ' Biegacz'], [], 'files[0].alt'];
        yield 'an alt text over 300 characters' => [[], ['alt' => str_repeat('ą', 301)], [], 'files[0].alt'];
        yield 'an alt text with invalid UTF-8' => [[], ['alt' => "Bieg\xC3\x28acz"], [], 'files[0].alt'];
        yield 'a blank author' => [[], ['author' => '  '], [], 'files[0].author'];
        yield 'a source id of another prefix' => [[], ['sourceId' => 'unsplash:129176897'], [], 'files[0].sourceId'];
        yield 'a source id without a prefix' => [[], ['sourceId' => '129176897'], [], 'files[0].sourceId'];
        yield 'a source id that is not a number' => [[], ['sourceId' => 'commons:abc'], [], 'files[0].sourceId'];
        yield 'a source id with a leading zero' => [[], ['sourceId' => 'commons:0123'], [], 'files[0].sourceId'];
        yield 'a source id of zero' => [[], ['sourceId' => 'commons:0'], [], 'files[0].sourceId'];
        yield 'a source id with a sign' => [[], ['sourceId' => 'commons:-5'], [], 'files[0].sourceId'];
        yield 'an empty source id' => [[], ['sourceId' => ''], [], 'files[0].sourceId'];
        yield 'a source id that is a number, not text' => [[], ['sourceId' => 129176897], [], 'files[0].sourceId'];
        yield 'an unknown variant' => [[], ['variant' => 'crop-diagonal'], [], 'files[0].variant'];
        yield 'a variant in capitals' => [[], ['variant' => 'Mirror'], [], 'files[0].variant'];
        yield 'an empty variant' => [[], ['variant' => ''], [], 'files[0].variant'];
        yield 'a missing variant' => [[], ['variant' => null], [], 'files[0].variant'];
        yield 'a source on another host' => [[], ['source' => 'https://example.com/wiki/File:x.jpg'], [], 'files[0].source'];
        yield 'a source on Unsplash' => [[], ['source' => 'https://unsplash.com/photos/x'], [], 'files[0].source'];
        yield 'a source on a look-alike host' => [[], ['source' => 'https://commons.wikimedia.org.evil.com/wiki/File:x'], [], 'files[0].source'];
        yield 'a source on a sub-domain' => [[], ['source' => 'https://upload.commons.wikimedia.org/wiki/File:x.jpg'], [], 'files[0].source'];
        yield 'a source with credentials' => [[], ['source' => 'https://user@commons.wikimedia.org/wiki/File:x'], [], 'files[0].source'];
        yield 'a source with a user and a password' => [[], ['source' => 'https://user:pass@commons.wikimedia.org/wiki/File:x'], [], 'files[0].source'];
        yield 'a source over plain http' => [[], ['source' => 'http://commons.wikimedia.org/wiki/File:x.jpg'], [], 'files[0].source'];
        yield 'a source with a port' => [[], ['source' => 'https://commons.wikimedia.org:8443/wiki/File:x.jpg'], [], 'files[0].source'];
        yield 'a source with a query' => [[], ['source' => 'https://commons.wikimedia.org/wiki/File:x.jpg?action=raw'], [], 'files[0].source'];
        yield 'a source with a fragment' => [[], ['source' => 'https://commons.wikimedia.org/wiki/File:x.jpg#file'], [], 'files[0].source'];
        yield 'a source that is not a file page' => [[], ['source' => 'https://commons.wikimedia.org/wiki/Category:Running'], [], 'files[0].source'];
        yield 'a source without the File: prefix' => [[], ['source' => 'https://commons.wikimedia.org/wiki/x.jpg'], [], 'files[0].source'];
        yield 'a source with an empty file name' => [[], ['source' => 'https://commons.wikimedia.org/wiki/File:'], [], 'files[0].source'];
        yield 'a source outside /wiki/' => [[], ['source' => 'https://commons.wikimedia.org/w/File:x.jpg'], [], 'files[0].source'];
        yield 'a source with a further path' => [[], ['source' => 'https://commons.wikimedia.org/wiki/File:a/../../x'], [], 'files[0].source'];
        yield 'a source with a space' => [[], ['source' => 'https://commons.wikimedia.org/wiki/File:a b.jpg'], [], 'files[0].source'];
        yield 'a source that is the object store' => [[], ['source' => 'https://abc.r2.cloudflarestorage.com/shop-demo-photos/demo-photos/v3/photos.tar'], [], 'files[0].source'];
        yield 'the licence of Unsplash' => [[], ['license' => 'Unsplash License'], [], 'files[0].license'];
        yield 'another licence' => [[], ['license' => 'CC BY 4.0'], [], 'files[0].license'];
        yield 'a licence in another case' => [[], ['license' => 'cc0 1.0'], [], 'files[0].license'];
        yield 'the old key of the Unsplash id' => [[], ['unsplashId' => 'Ab12Cd34Ef5'], [], 'unknown key "unsplashId"'];
        yield 'a duplicated file' => [[], [], ['file' => 'run-road-001.jpg'], 'files[1].file: duplicate'];
        yield 'a file differing only in case' => [[], [], ['file' => 'RUN-ROAD-001.JPG'], 'files[1].file: duplicate'];
        yield 'a duplicated sum' => [[], [], ['sha256' => str_repeat('a', 64)], 'files[1].sha256: duplicate'];
        yield 'a repeated pair of the source id and the variant' => [[], [], ['sourceId' => 'commons:129176897', 'variant' => 'original'], 'files[1].sourceId: duplicate'];
        yield 'a credential variable in a text' => [[], ['alt' => 'DEMO_PHOTOS_SECRET_ACCESS_KEY=abc'], [], 'DEMO_PHOTOS_'];
    }

    public function testAllViolationsAreReportedAtOnceAndNeverRepeatTheValues(): void
    {
        $manifest = $this->valid();
        $files = $manifest['files'];
        $files[0] = self::changed($files[0], ['sha256' => 'not-a-sum']);
        $files[1] = self::changed($files[1], ['secretAccessKey' => 'secret-value-2']);
        $manifest = self::changed($manifest, ['files' => $files, 'accessKeyId' => 'AKIA-secret-value-1']);

        try {
            PhotoManifest::fromYaml(Yaml::dump($manifest), self::GROUPS);
            self::fail('The manifest should have been refused.');
        } catch (InvalidPhotoManifest $e) {
            self::assertCount(3, $e->violations);
            self::assertStringNotContainsString('AKIA-secret-value-1', $e->getMessage());
            self::assertStringNotContainsString('secret-value-2', $e->getMessage());
        }
    }

    #[DataProvider('notManifests')]
    public function testSomethingThatIsNotAMappingIsRefused(string $yaml): void
    {
        $this->expectException(InvalidPhotoManifest::class);

        PhotoManifest::fromYaml($yaml, self::GROUPS);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notManifests(): iterable
    {
        yield 'empty' => [''];
        yield 'a scalar' => ['just text'];
        yield 'a list' => ["- a\n- b\n"];
        yield 'broken yaml' => ["files: [a, \n  b: : :"];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $changes a null value removes the key
     *
     * @return array<string, mixed>
     */
    private static function changed(array $data, array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (null === $value) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array{version: int, key: string, files: list<array<string, string>>}
     */
    private function valid(): array
    {
        return [
            'version' => 3,
            'key' => 'demo-photos/v3/photos.tar',
            'files' => [
                [
                    'file' => 'run-road-001.jpg',
                    'sha256' => str_repeat('a', 64),
                    'group' => 'run-road',
                    'alt' => 'Biegacz na asfaltowej drodze',
                    'sourceId' => 'commons:129176897',
                    'variant' => 'original',
                    'source' => 'https://commons.wikimedia.org/wiki/File:Runner_on_road,_20240106_V%C3%A5lerenga_(1).jpg',
                    'author' => 'Zażółć Gęślą',
                    'license' => 'CC0 1.0',
                ],
                [
                    'file' => 'bike-road-001.jpg',
                    'sha256' => str_repeat('b', 64),
                    'group' => 'bike-road',
                    'alt' => 'Rowerzysta na szosie',
                    'sourceId' => 'commons:98765',
                    'variant' => 'original',
                    'source' => 'https://commons.wikimedia.org/wiki/File:Cyclist.jpg',
                    'author' => 'Jan Kowalski',
                    'license' => 'Public domain',
                ],
            ],
        ];
    }
}
