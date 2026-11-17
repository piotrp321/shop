<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Console\PhotosCommand;
use App\DemoData\Copy\CopyWriter;
use App\DemoData\Photo\PhotoLoader;
use App\DemoData\Photo\PhotoPoolLoader;
use App\DemoData\Photo\PhotoRules;
use App\DemoData\Photo\PhotoSourceAccess;
use App\DemoData\Photo\ProductPhotos;
use App\DemoData\Profile\RunContextFactory;
use App\Tests\Unit\DemoData\PoolFixture;
use App\Tests\Unit\DemoData\TarBuilder;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * Step 2 for a profile other than small: the photo pool from an archive (a local filesystem stands in for the private
 * bucket) is checked against the manifest and only then stored once in the bucket of the originals (Garage) and assigned
 * to the products. The products step runs with the profile medium and 150 products, so the step has something to assign.
 */
final class PhotoPoolStepTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    private const string ENDPOINT = 'https://account-1234.r2.example.invalid';
    private const string BUCKET = 'bucket-of-the-test';
    private const string KEY_ID = 'AKIA-key-id-of-the-test';
    private const string SECRET = 'secret-value-of-the-test';
    private const array MEDIUM = ['--demo-profile' => 'medium', '--products' => 150];

    private string $dir;
    private Dictionaries $dictionaries;
    /** @var array{version: int, key: string, files: list<array<string, string>>} */
    private array $manifest;
    private RecordingFilesystem $source;
    private RecordingFilesystem $originals;
    private PhotoSourceAccess $access;

    protected function setUp(): void
    {
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_product')), 'shop_test has catalog rows that are not the ones of this test.');

        $this->dir = sys_get_temp_dir().'/photo-pool-step-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/pool', 0o700, true);
        mkdir($this->dir.'/work', 0o700);
        mkdir($this->dir.'/source/demo-photos/v1', 0o700, true);
        $this->dictionaries = new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
        $this->manifest = PoolFixture::write($this->dir.'/pool', $this->dictionaries, 1, bin2hex(random_bytes(8)));
        file_put_contents($this->dir.'/photos.yaml', Yaml::dump($this->manifest, 4));
        $this->writeArchive();

        $this->source = new RecordingFilesystem(new Filesystem(new LocalFilesystemAdapter($this->dir.'/source')));
        $originals = self::getContainer()->get('test.product_images.storage');
        self::assertInstanceOf(FilesystemOperator::class, $originals);
        $this->originals = new RecordingFilesystem($originals);
        $this->access = new PhotoSourceAccess(self::ENDPOINT, self::BUCKET, self::KEY_ID, self::SECRET);
    }

    protected function tearDown(): void
    {
        foreach ($this->expectedKeys() as $key) {
            // The files are salted: no other test stores them, so the test removes what it stored.
            $this->originals->delete($key);
        }
        PoolFixture::remove($this->dir);
    }

    public function testAMatchingArchiveStoresEveryFileOnceAndGivesTheProductsKeysOfTheirGroups(): void
    {
        $this->products();
        $expected = $this->expectedKeys();
        foreach ($expected as $key) {
            self::assertFalse($this->originals->fileExists($key), 'The test starts without the files of the pool in the bucket.');
        }

        $tester = $this->photos();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertEqualsCanonicalizing(array_values($expected), $this->originals->writes, 'Every file is written once, under originals/<sha256>.<extension>.');
        foreach ($expected as $key) {
            self::assertTrue($this->originals->fileExists($key), $key);
        }
        $this->assertProductsHaveKeysOfTheirGroups($expected);
        self::assertSame([], glob($this->dir.'/work/*'), 'The work directory is removed.');
        self::assertStringContainsString('37 files', $tester->getDisplay());
    }

    public function testRunningTheStepAgainStoresNothingNewAndAssignsTheSameKeys(): void
    {
        $this->products();
        $this->succeedsPhotos();
        $first = $this->text($this->connection()->fetchOne('SELECT md5(string_agg(id::text || images::text, \'|\' ORDER BY id)) FROM catalog_product'));
        $written = \count($this->originals->writes);

        $this->succeedsPhotos();

        self::assertSame(37, $written);
        self::assertCount($written, $this->originals->writes, 'The files that are in the bucket are not written again.');
        self::assertSame($first, $this->text($this->connection()->fetchOne('SELECT md5(string_agg(id::text || images::text, \'|\' ORDER BY id)) FROM catalog_product')));
    }

    public function testAFileWithAnotherSumFailsWithoutAnyChange(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        $name = $this->manifest['files'][3]['file'];
        $this->writeArchive([$name => str_repeat('x', 100)]);

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        self::assertStringContainsString($name.': ', $tester->getDisplay());
        self::assertSame(1, $this->source->reads, 'The archive was downloaded and checked.');
    }

    public function testAFileOfTheSameSizeButOtherContentsFails(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        $name = $this->manifest['files'][0]['file'];
        $contents = file_get_contents($this->dir.'/pool/'.$name);
        self::assertIsString($contents);
        $this->writeArchive([$name => strrev($contents)]);

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        self::assertStringContainsString($name.': ', $tester->getDisplay());
    }

    public function testAMissingFileFailsWithoutAnyChange(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        $this->writeArchive([], [$this->manifest['files'][5]['file']]);

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        self::assertStringContainsString($this->manifest['files'][5]['file'].': missing', $tester->getDisplay());
    }

    public function testAnExtraFileInTheArchiveFailsWithoutAnyChange(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        $this->writeArchive(['extra.jpg' => 'x']);

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        self::assertStringContainsString('extra.jpg: not in the manifest', $tester->getDisplay());
    }

    public function testAnArchiveThatIsNotATarFileFails(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        file_put_contents($this->dir.'/source/demo-photos/v1/photos.tar', str_repeat('not a tar archive ', 100));

        $this->assertNothingChanged($this->photos(), $before);
    }

    public function testAnArchiveMissingFromTheBucketFails(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        unlink($this->dir.'/source/demo-photos/v1/photos.tar');

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        self::assertStringContainsString('demo-photos/v1/photos.tar', $tester->getDisplay());
    }

    public function testErrorsOfTheSourceDoNotRepeatTheCredentialsTheAddressOrTheBucket(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        $this->source->failReads = new \RuntimeException(\sprintf('Unable to connect to %s/%s as %s with %s', self::ENDPOINT, self::BUCKET, self::KEY_ID, self::SECRET));

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        $this->assertNoCredentials($tester->getDisplay());
        self::assertStringContainsString('RuntimeException', $tester->getDisplay());
    }

    public function testTheOutputOfASuccessfulStepHasNoCredentials(): void
    {
        $this->products();

        $tester = $this->photos();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertNoCredentials($tester->getDisplay());
    }

    public function testTheCredentialsAreNotInTheDumpOfTheAccess(): void
    {
        $this->assertNoCredentials(print_r($this->access, true).var_export($this->access, true).json_encode($this->access, \JSON_THROW_ON_ERROR));
    }

    public function testWithoutTheVariablesTheStepFailsNamingThemBeforeReadingAnything(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        $this->access = new PhotoSourceAccess('', self::BUCKET, '  ', '');

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        self::assertStringContainsString('DEMO_PHOTOS_ENDPOINT', $tester->getDisplay());
        self::assertStringContainsString('DEMO_PHOTOS_ACCESS_KEY_ID', $tester->getDisplay());
        self::assertStringContainsString('DEMO_PHOTOS_SECRET_ACCESS_KEY', $tester->getDisplay());
        self::assertStringNotContainsString('DEMO_PHOTOS_BUCKET', $tester->getDisplay(), 'Only the variables that are empty are named.');
        self::assertSame(0, $this->source->reads);
    }

    public function testWithoutTheManifestTheStepFailsBeforeTheDownload(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        unlink($this->dir.'/photos.yaml');

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        self::assertStringContainsString('photos.yaml', $tester->getDisplay());
        self::assertSame(0, $this->source->reads);
    }

    public function testAPhotoGroupWithoutFilesInTheManifestFailsBeforeTheDownload(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        $manifest = $this->manifest;
        $manifest['files'] = array_values(array_filter($manifest['files'], static fn (array $file): bool => 'ski' !== $file['group']));
        file_put_contents($this->dir.'/photos.yaml', Yaml::dump($manifest, 4));

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        self::assertStringContainsString('ski', $tester->getDisplay());
        self::assertSame(0, $this->source->reads);
    }

    public function testAManifestThatBreaksTheSchemaFailsBeforeTheDownload(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());
        $manifest = $this->manifest;
        $manifest['secretAccessKey'] = self::SECRET;
        file_put_contents($this->dir.'/photos.yaml', Yaml::dump($manifest, 4));

        $tester = $this->photos();

        $this->assertNothingChanged($tester, $before);
        $this->assertNoCredentials($tester->getDisplay());
        self::assertSame(0, $this->source->reads);
    }

    public function testThePreflightPassesWhenTheArchiveIsThereAndChangesNothing(): void
    {
        $this->products();
        $before = $this->checksums($this->connection());

        $tester = $this->photos(['--preflight' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($before, $this->checksums($this->connection()));
        self::assertSame([], $this->originals->writes);
        self::assertSame([], glob($this->dir.'/work/*'));
    }

    public function testThePreflightFailsWhenTheArchiveIsMissingFromTheBucket(): void
    {
        unlink($this->dir.'/source/demo-photos/v1/photos.tar');

        $tester = $this->photos(['--preflight' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('demo-photos/v1/photos.tar', $tester->getDisplay());
    }

    public function testThePreflightFailsWithoutTheVariables(): void
    {
        $this->access = new PhotoSourceAccess('', '', '', '');

        $tester = $this->photos(['--preflight' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DEMO_PHOTOS_BUCKET', $tester->getDisplay());
        self::assertSame(0, $this->source->reads);
    }

    public function testTheSmallProfileNeedsNeitherTheVariablesNorTheArchive(): void
    {
        $this->access = new PhotoSourceAccess('', '', '', '');
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products');

        $tester = $this->photos(['--demo-profile' => 'small', '--products' => null]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(0, $this->source->reads);
    }

    private function products(): void
    {
        $this->succeeds('demo-data:categories', self::MEDIUM);
        $this->succeeds('demo-data:products', self::MEDIUM);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function photos(array $input = []): CommandTester
    {
        $connection = $this->connection();
        $products = new ProductPhotos($connection, new CopyWriter($connection));
        $rules = new PhotoRules(67108864, 50000000);
        $small = new PhotoLoader($this->dictionaries, $products, $this->originals, \dirname(__DIR__, 3).'/fixtures/images');
        $pool = new PhotoPoolLoader($products, $this->dictionaries, $rules, $this->access, $this->originals, $this->source, $this->dir.'/photos.yaml', $this->dir.'/work');
        $application = new Application();
        $application->addCommand(new PhotosCommand(new RunContextFactory(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml'), $small, $pool));

        $tester = new CommandTester($application->find('demo-data:photos'));
        $tester->execute(array_filter($input + self::MEDIUM + ['--anchor' => self::ANCHOR], static fn (mixed $value): bool => null !== $value));

        return $tester;
    }

    private function succeedsPhotos(): void
    {
        $tester = $this->photos();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * @param array<string, string> $before
     */
    private function assertNothingChanged(CommandTester $tester, array $before): void
    {
        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $this->originals->writes, 'Nothing was written to the bucket.');
        foreach ($this->expectedKeys() as $key) {
            self::assertFalse($this->originals->fileExists($key), $key);
        }
        self::assertSame($before, $this->checksums($this->connection()), 'The products are as they were.');
        self::assertSame([], glob($this->dir.'/work/*'), 'The work directory is removed.');
    }

    private function assertNoCredentials(string $text): void
    {
        foreach ([self::ENDPOINT, 'account-1234', self::BUCKET, self::KEY_ID, self::SECRET] as $value) {
            self::assertStringNotContainsString($value, $text);
        }
    }

    /**
     * @return array<string, string> the key of every file of the pool in the bucket, by file name
     */
    private function expectedKeys(): array
    {
        $keys = [];
        foreach ($this->manifest['files'] as $file) {
            $keys[$file['file']] = \sprintf('originals/%s.jpg', $file['sha256']);
        }

        return $keys;
    }

    /**
     * @param array<string, string> $expected
     */
    private function assertProductsHaveKeysOfTheirGroups(array $expected): void
    {
        $keysByGroup = [];
        foreach ($this->manifest['files'] as $file) {
            $keysByGroup[$file['group']][] = $expected[$file['file']];
        }
        $context = new RunContextFactory(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml')->create('medium', null, 150, self::ANCHOR);
        $generator = new ProductGenerator($this->dictionaries, $context->namespace, $context->anchor, $context->profile->products, $context->partSize);
        $withImages = 0;
        for ($part = 0; $part < $context->productParts(); ++$part) {
            foreach ($generator->part($part) as $spec) {
                $images = json_decode($this->text($this->connection()->fetchOne('SELECT images::text FROM catalog_product WHERE id = ?', [$spec->id])), true, 8, \JSON_THROW_ON_ERROR);
                self::assertIsArray($images);
                self::assertLessThanOrEqual(3, \count($images));
                $spec->imageless ? self::assertSame([], $images) : self::assertNotSame([], $images);
                foreach ($images as $image) {
                    self::assertIsArray($image);
                    self::assertContains($image['key'] ?? null, $keysByGroup[$spec->photoGroup], 'A product has the photos of the group of its type.');
                    ++$withImages;
                }
            }
        }
        self::assertGreaterThan(100, $withImages);
    }

    /**
     * Writes the archive of the pool to the source (the object of the manifest), optionally with other contents of some
     * files, extra files, and without some files.
     *
     * @param array<string, string> $contents contents by file name (replace the ones of the pool or add files)
     * @param list<string>          $without  files to leave out
     */
    private function writeArchive(array $contents = [], array $without = []): void
    {
        $path = $this->dir.'/source/demo-photos/v1/photos.tar';
        is_file($path) && unlink($path);
        $names = array_diff(array_map(static fn (array $file): string => $file['file'], $this->manifest['files']), $without);
        $entries = [];
        foreach (array_unique([...$names, ...array_keys($contents)]) as $name) {
            $entries[] = TarBuilder::entry($name, $contents[$name] ?? (string) file_get_contents($this->dir.'/pool/'.$name));
        }
        file_put_contents($path, TarBuilder::archive($entries));
    }
}
