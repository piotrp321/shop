<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Photo\InvalidPhotoPool;
use App\DemoData\Photo\PhotoManifest;
use App\DemoData\Photo\PhotoPoolVerifier;
use App\DemoData\Photo\PhotoRules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The check of a directory of photos against the manifest: the complete set of files, the SHA-256 of each and the
 * rules of the upload of Catalog.
 */
final class PhotoPoolVerifierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/photo-pool-verifier-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->dir);
    }

    public function testACompletePoolThatMeetsTheRulesPasses(): void
    {
        $manifest = $this->populate(['run-road-001.jpg' => self::photo('runner-country-road.jpg'), 'bike-road-001.jpg' => self::photo('cyclist-mountain-road-romania.jpg')]);

        $this->verify($manifest);

        $this->addToAssertionCount(1);
    }

    public function testAFileWithAnotherSumIsNamed(): void
    {
        $manifest = $this->populate(['run-road-001.jpg' => self::photo('runner-country-road.jpg'), 'bike-road-001.jpg' => self::photo('cyclist-mountain-road-romania.jpg')]);
        file_put_contents($this->dir.'/bike-road-001.jpg', self::photo('hiker-reading-map.jpg'));

        self::assertSame(['bike-road-001.jpg: the SHA-256 does not match the manifest'], $this->violations($manifest));
    }

    public function testAMissingFileIsNamed(): void
    {
        $manifest = $this->populate(['run-road-001.jpg' => self::photo('runner-country-road.jpg'), 'bike-road-001.jpg' => self::photo('cyclist-mountain-road-romania.jpg')]);
        unlink($this->dir.'/run-road-001.jpg');

        self::assertSame(['run-road-001.jpg: missing'], $this->violations($manifest));
    }

    public function testAnExtraFileIsNamed(): void
    {
        $manifest = $this->populate(['run-road-001.jpg' => self::photo('runner-country-road.jpg'), 'bike-road-001.jpg' => self::photo('cyclist-mountain-road-romania.jpg')]);
        file_put_contents($this->dir.'/extra.jpg', 'x');
        mkdir($this->dir.'/nested');

        $violations = $this->violations($manifest);
        sort($violations);
        self::assertSame(['extra.jpg: not in the manifest', 'nested: not in the manifest'], $violations);
    }

    public function testAFileThatIsNotAnImageIsRefusedEvenWithTheRightSum(): void
    {
        $manifest = $this->populate(['run-road-001.jpg' => 'just text, not a photo', 'bike-road-001.jpg' => self::photo('cyclist-mountain-road-romania.jpg')]);

        $violations = $this->violations($manifest);

        self::assertCount(1, $violations);
        self::assertStringStartsWith('run-road-001.jpg: not an image type the catalog accepts', $violations[0]);
    }

    public function testAFileOverThePixelLimitIsRefused(): void
    {
        $manifest = $this->populate(['run-road-001.jpg' => self::photo('runner-country-road.jpg'), 'bike-road-001.jpg' => self::photo('cyclist-mountain-road-romania.jpg')]);

        $violations = $this->violations($manifest, new PhotoRules(67108864, 1000));

        self::assertCount(2, $violations);
        self::assertStringContainsString('run-road-001.jpg: more than 1000 pixels', $violations[0]);
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        $manifest = $this->populate(['run-road-001.jpg' => self::photo('runner-country-road.jpg'), 'bike-road-001.jpg' => self::photo('cyclist-mountain-road-romania.jpg')]);
        unlink($this->dir.'/run-road-001.jpg');
        file_put_contents($this->dir.'/bike-road-001.jpg', 'changed');
        file_put_contents($this->dir.'/extra.jpg', 'x');

        self::assertCount(3, $this->violations($manifest));
    }

    public function testAMissingDirectoryIsRefused(): void
    {
        $manifest = $this->populate(['run-road-001.jpg' => self::photo('runner-country-road.jpg'), 'bike-road-001.jpg' => self::photo('cyclist-mountain-road-romania.jpg')]);

        try {
            new PhotoPoolVerifier(new PhotoRules(67108864, 50000000))->verify($this->dir.'/nonexistent', $manifest);
            self::fail('The directory should have been refused.');
        } catch (InvalidPhotoPool $e) {
            self::assertStringContainsString('is not a directory', $e->violations[0]);
        }
    }

    private function verify(PhotoManifest $manifest, ?PhotoRules $rules = null): void
    {
        new PhotoPoolVerifier($rules ?? new PhotoRules(67108864, 50000000))->verify($this->dir, $manifest);
    }

    /**
     * @return list<string>
     */
    private function violations(PhotoManifest $manifest, ?PhotoRules $rules = null): array
    {
        try {
            $this->verify($manifest, $rules);
        } catch (InvalidPhotoPool $e) {
            return $e->violations;
        }
        self::fail('The pool should have been refused.');
    }

    /**
     * Writes the files and returns the manifest that describes them (the first file is in the group run-road, the
     * second in bike-road).
     *
     * @param array<string, string> $files contents by file name
     */
    private function populate(array $files): PhotoManifest
    {
        $entries = [];
        foreach (array_keys($files) as $index => $name) {
            file_put_contents($this->dir.'/'.$name, $files[$name]);
            $entries[] = [
                'file' => $name,
                'sha256' => hash('sha256', $files[$name]),
                'group' => 0 === $index ? 'run-road' : 'bike-road',
                'alt' => 'Zdjęcie '.$index,
                'unsplashId' => 'photo'.str_repeat((string) $index, 6),
                'source' => 'https://unsplash.com/photos/photo'.$index,
                'author' => 'Autor',
                'license' => 'Unsplash License',
            ];
        }

        return PhotoManifest::fromYaml(Yaml::dump(['version' => 1, 'key' => 'demo-photos/v1/photos.tar', 'files' => $entries]), ['run-road', 'bike-road']);
    }

    private static function photo(string $name): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/fixtures/images/'.$name);
        self::assertIsString($contents);

        return $contents;
    }
}
