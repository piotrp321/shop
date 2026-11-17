<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Console\PhotosCheckCommand;
use App\DemoData\Photo\PhotoPoolVerifier;
use App\DemoData\Photo\PhotoRules;
use App\DemoData\Photo\TarExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * demo-data:photos:check, the check of bin/upload-demo-photos: the directory of photos against the manifest and, with
 * --archive, the archive that is uploaded.
 */
final class PhotosCheckCommandTest extends TestCase
{
    private string $dir;
    private Dictionaries $dictionaries;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/photos-check-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/photos', 0o700, true);
        $this->dictionaries = new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
    }

    protected function tearDown(): void
    {
        PoolFixture::remove($this->dir);
    }

    public function testACompletePoolPassesAndTheKeyIsPrinted(): void
    {
        $tester = $this->tester($this->manifest());

        self::assertSame(0, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml']), $tester->getDisplay());
        self::assertStringContainsString('37 files', $tester->getDisplay());
        self::assertStringContainsString('OBJECT_KEY=demo-photos/v1/photos.tar', $tester->getDisplay());
    }

    public function testWithoutTheManifestOptionTheManifestOfTheRepositoryIsRead(): void
    {
        $manifest = $this->manifest();
        rename($this->dir.'/photos.yaml', $this->dir.'/default-photos.yaml');
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['directory' => $this->dir.'/photos']), $tester->getDisplay());
        self::assertStringContainsString('OBJECT_KEY='.$manifest['key'], $tester->getDisplay());
    }

    public function testTheArchiveHoldsTheFilesOfTheManifestAndReadsBack(): void
    {
        $manifest = $this->manifest();
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml', '--archive' => $this->dir.'/photos.tar']), $tester->getDisplay());

        self::assertFileExists($this->dir.'/photos.tar');
        mkdir($this->dir.'/unpacked');
        (new TarExtractor(67108864))->extract($this->dir.'/photos.tar', $this->dir.'/unpacked', array_map(static fn (array $file): string => $file['file'], $manifest['files']));
        foreach ($manifest['files'] as $file) {
            self::assertSame($file['sha256'], hash_file('sha256', $this->dir.'/unpacked/'.$file['file']));
        }
        self::assertCount(37, glob($this->dir.'/unpacked/*') ?: []);
    }

    public function testAFileOfAnotherSumIsRefusedByNameAndNoArchiveIsBuilt(): void
    {
        $this->manifest();
        file_put_contents(glob($this->dir.'/photos/*')[0], 'changed');
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml', '--archive' => $this->dir.'/photos.tar']));
        self::assertStringContainsString('the SHA-256 does not match the manifest', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir.'/photos.tar');
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->manifest();
        unlink(glob($this->dir.'/photos/*')[0]);
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml']));
        self::assertStringContainsString(': missing', $tester->getDisplay());
    }

    public function testAFileThatBreaksTheUploadRulesIsRefused(): void
    {
        $manifest = $this->manifest();
        $first = $manifest['files'][0];
        file_put_contents($this->dir.'/photos/'.$first['file'], 'not an image');
        $manifest['files'][0]['sha256'] = hash('sha256', 'not an image');
        file_put_contents($this->dir.'/photos.yaml', Yaml::dump($manifest, 4));
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml']));
        self::assertStringContainsString($first['file'].': not an image type the catalog accepts', $tester->getDisplay());
    }

    public function testAnExtraFileIsRefused(): void
    {
        $this->manifest();
        file_put_contents($this->dir.'/photos/extra.jpg', 'x');
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml']));
        self::assertStringContainsString('extra.jpg: not in the manifest', $tester->getDisplay());
    }

    public function testAManifestThatBreaksTheSchemaIsRefusedWithoutRepeatingTheCredentialsInIt(): void
    {
        $manifest = $this->manifest();
        $manifest['secretAccessKey'] = 'secret-value-that-must-not-be-printed';
        file_put_contents($this->dir.'/photos.yaml', Yaml::dump($manifest, 4));
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml']));
        self::assertStringContainsString('unknown key "secretAccessKey"', $tester->getDisplay());
        self::assertStringNotContainsString('secret-value-that-must-not-be-printed', $tester->getDisplay());
    }

    public function testAPhotoGroupWithoutFilesIsRefused(): void
    {
        $manifest = $this->manifest();
        $manifest['files'] = array_values(array_filter($manifest['files'], static fn (array $file): bool => 'ski' !== $file['group']));
        file_put_contents($this->dir.'/photos.yaml', Yaml::dump($manifest, 4));
        foreach (glob($this->dir.'/photos/ski-*') ?: [] as $file) {
            unlink($file);
        }
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml']));
        self::assertStringContainsString('no files in the manifest: ski', $tester->getDisplay());
    }

    public function testAMissingManifestOrDirectoryIsRefused(): void
    {
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/none.yaml']));
        self::assertStringContainsString('none.yaml', $tester->getDisplay());

        $this->manifest();
        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/missing', '--manifest' => $this->dir.'/photos.yaml']));
        self::assertStringContainsString('not a directory', $tester->getDisplay());
    }

    public function testAnArchivePathThatIsNotATarFileIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->manifest();
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['directory' => $this->dir.'/photos', '--manifest' => $this->dir.'/photos.yaml', '--archive' => $this->dir.'/photos.zip']));
        self::assertStringContainsString('.tar', $tester->getDisplay());
        self::assertFileDoesNotExist($this->dir.'/photos.zip');
    }

    /**
     * Writes the pool and its manifest (the one the test works on).
     *
     * @return array{version: int, key: string, files: list<array<string, string>>}
     */
    private function manifest(): array
    {
        $manifest = PoolFixture::write($this->dir.'/photos', $this->dictionaries);
        file_put_contents($this->dir.'/photos.yaml', Yaml::dump($manifest, 4));

        return $manifest;
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new PhotosCheckCommand(new PhotoPoolVerifier(new PhotoRules(67108864, 50000000)), $this->dictionaries, $this->dir.'/default-photos.yaml'));

        return new CommandTester($application->find('demo-data:photos:check'));
    }
}
