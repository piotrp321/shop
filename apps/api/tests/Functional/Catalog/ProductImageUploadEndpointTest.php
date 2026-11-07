<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Infrastructure\Image\FlysystemProductImageStorage;
use App\Tests\Support\InMemory\InMemoryProductImageStorage;
use App\Tests\Support\Security\TestTokenFactory;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/admin/product-images on the test bucket of Garage. The limits of PHP (upload_max_filesize,
 * post_max_size) do not apply in the kernel of the tests: their effect is simulated (an upload error, a Content-Length
 * header) and the real ones are checked by the E2E test on the running stack.
 */
final class ProductImageUploadEndpointTest extends WebTestCase
{
    use Factories;
    use ProductAdminEndpointTestTrait;

    private const string PATH = '/api/v1/admin/product-images';

    private string $tmp;

    protected function tearDown(): void
    {
        if (isset($this->tmp) && is_file($this->tmp)) {
            unlink($this->tmp);
        }
        parent::tearDown();
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $this->upload($this->fixture('sample.jpg'), 'sample.jpg', null);

        $this->assertUnauthorized();
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $this->upload($this->fixture('sample.jpg'), 'sample.jpg', TestTokenFactory::customer());

        $this->assertUnauthorized();
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rolesWithoutTheRight(): iterable
    {
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
        yield 'no staff role' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesWithoutTheRight')]
    public function testStaffWithoutAdminOrMarketingIsForbidden(array $roles): void
    {
        $this->upload($this->fixture('sample.jpg'), 'sample.jpg', TestTokenFactory::staff($roles));

        $this->assertProblem(403);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function managingRoles(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'marketing' => [['marketing']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('managingRoles')]
    public function testAFiveMegabyteJpegIsStoredAndAnswersWithItsKeyAndAThumbnailPreview(array $roles): void
    {
        $contents = $this->fixture('sample.jpg').str_repeat("\0", 5 * 1024 * 1024);

        $body = $this->upload($contents, 'zdjecie.jpg', TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(201);
        $this->assertPrivateAndNotStored();
        $key = 'originals/'.hash('sha256', $contents).'.jpg';
        self::assertSame($key, $body['key']);
        self::assertIsArray($body['preview']);
        self::assertSame('thumbnail', $body['preview']['size']);
        self::assertSame('1:1', $body['preview']['aspectRatio']);
        self::assertArrayNotHasKey('alt', $body['preview']);
        self::assertIsArray($body['preview']['variants']);
        self::assertIsArray($body['preview']['variants']['webp']);
        self::assertSame([96, 192], array_column($body['preview']['variants']['webp'], 'width'));
        self::assertMatchesRegularExpression('#^https?://\S+/.+#', self::string(self::list($body['preview']['variants']['webp'])[0]['url']));
        $this->assertKeysMatchSchema($body, 'ImageUploadResponse');
        $this->assertKeysMatchSchema($body['preview'], 'ImagePreviewResponse');
        self::assertTrue($this->bucket()->fileExists($key));
    }

    public function testEveryAllowedTypeIsAcceptedAndTheKeyCarriesItsExtension(): void
    {
        foreach (['jpg', 'png', 'webp', 'avif', 'heic'] as $extension) {
            $body = $this->upload($this->fixture('sample.'.$extension), 'plik.'.$extension, TestTokenFactory::staff(['marketing']));

            self::assertResponseStatusCodeSame(201, $extension);
            self::assertStringEndsWith('.'.$extension, self::string($body['key']));
        }
    }

    public function testTheSameFileTwiceGivesTheSameKey(): void
    {
        $first = $this->upload($this->fixture('sample.png'), 'a.png', TestTokenFactory::staff(['admin']));
        $second = $this->upload($this->fixture('sample.png'), 'b.png', TestTokenFactory::staff(['admin']));

        self::assertSame($first['key'], $second['key']);
    }

    public function testTheTypeIsDecidedByTheContentNotByTheName(): void
    {
        $body = $this->upload($this->fixture('sample.png'), 'zdjecie.svg', TestTokenFactory::staff(['admin']));

        self::assertResponseStatusCodeSame(201);
        self::assertStringEndsWith('.png', self::string($body['key']));
    }

    /**
     * @return iterable<string, array{string, string, string}> fixture or contents, name, detail
     */
    public static function rejectedFiles(): iterable
    {
        yield 'an svg' => ['fixture:sample.svg', 'logo.svg', 'Nieobsługiwany typ pliku. Wyślij zdjęcie JPEG, PNG, WebP, AVIF albo HEIC.'];
        yield 'an svg named jpg' => ['fixture:sample.svg', 'logo.jpg', 'Nieobsługiwany typ pliku. Wyślij zdjęcie JPEG, PNG, WebP, AVIF albo HEIC.'];
        yield 'a text named jpg' => ['To nie jest zdjęcie, tylko tekst.', 'zdjecie.jpg', 'Nieobsługiwany typ pliku. Wyślij zdjęcie JPEG, PNG, WebP, AVIF albo HEIC.'];
        yield 'an empty file' => ['', 'puste.jpg', 'Nieobsługiwany typ pliku. Wyślij zdjęcie JPEG, PNG, WebP, AVIF albo HEIC.'];
        yield 'an image over 50 megapixels' => ['fixture:oversized.webp', 'ogromne.webp', 'Zdjęcie ma za dużą rozdzielczość (najwyżej 50 megapikseli).'];
        yield 'a truncated png' => ['truncated:sample.png', 'ucieta.png', 'Nie można odczytać wymiarów zdjęcia.'];
    }

    #[DataProvider('rejectedFiles')]
    public function testARejectedFileIsA422WithAPolishDetailAndNothingIsStored(string $source, string $name, string $detail): void
    {
        $contents = match (true) {
            str_starts_with($source, 'fixture:') => $this->fixture(substr($source, 8)),
            str_starts_with($source, 'truncated:') => substr($this->fixture(substr($source, 10)), 0, 16),
            default => $source,
        };
        $before = $this->storedObjects();

        $this->upload($contents, $name, TestTokenFactory::staff(['marketing']));

        $this->assertProblem(422, null, $detail);
        self::assertSame($before, $this->storedObjects());
    }

    public function testARequestWithoutAFileIsA422(): void
    {
        $this->client->request('POST', self::PATH, server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);

        $this->assertProblem(422, null, 'Wybierz plik ze zdjęciem.');
    }

    public function testAFieldWithAnotherNameIsNoFile(): void
    {
        $this->client->request('POST', self::PATH, files: ['photo' => $this->uploaded($this->fixture('sample.jpg'), 'a.jpg')], server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);

        $this->assertProblem(422, null, 'Wybierz plik ze zdjęciem.');
    }

    public function testAJsonBodyIsNoFileEither(): void
    {
        $this->client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])], content: '{"file":"x"}');

        $this->assertProblem(422, null, 'Wybierz plik ze zdjęciem.');
    }

    public function testAnUploadAboveTheLimitOfPhpIsTooLarge(): void
    {
        $this->tmp = (string) tempnam(sys_get_temp_dir(), 'upload');
        $file = new UploadedFile($this->tmp, 'duze.jpg', 'image/jpeg', \UPLOAD_ERR_INI_SIZE, true);
        $this->client->request('POST', self::PATH, files: ['file' => $file], server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);

        $this->assertProblem(422, null, 'Plik jest za duży.');
    }

    public function testAnUploadThatDidNotArriveWholeIsAskedAgain(): void
    {
        $this->tmp = (string) tempnam(sys_get_temp_dir(), 'upload');
        $file = new UploadedFile($this->tmp, 'urwane.jpg', 'image/jpeg', \UPLOAD_ERR_PARTIAL, true);
        $this->client->request('POST', self::PATH, files: ['file' => $file], server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);

        $this->assertProblem(422, null, 'Nie udało się odebrać pliku. Spróbuj ponownie.');
    }

    public function testARequestAbovePostMaxSizeIsTooLargeBeforeAnyFileIsLookedFor(): void
    {
        $limit = (int) ini_parse_quantity((string) \ini_get('post_max_size'));
        self::assertGreaterThan(0, $limit, 'post_max_size must be set and finite.');

        $this->client->request('POST', self::PATH, server: ['CONTENT_LENGTH' => (string) ($limit + 1), 'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);

        $this->assertProblem(422, null, 'Plik jest za duży.');
    }

    public function testARequestAtPostMaxSizeIsNotRefusedByTheHeader(): void
    {
        $limit = (int) ini_parse_quantity((string) \ini_get('post_max_size'));

        $this->client->request('POST', self::PATH, server: ['CONTENT_LENGTH' => (string) $limit, 'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])]);

        $this->assertProblem(422, null, 'Wybierz plik ze zdjęciem.');
    }

    public function testAnUnavailableImageStorageIsAServiceUnavailable(): void
    {
        $storage = new InMemoryProductImageStorage();
        $storage->unavailable = true;
        self::getContainer()->set(FlysystemProductImageStorage::class, $storage);

        $this->upload($this->fixture('sample.jpg'), 'sample.jpg', TestTokenFactory::staff(['admin']));

        $problem = $this->assertProblem(503);
        self::assertArrayNotHasKey('detail', $problem);
    }

    public function testTheKeyOfAnUploadIsAcceptedByTheProductForm(): void
    {
        $body = $this->upload($this->fixture('sample.webp'), 'a.webp', TestTokenFactory::staff(['marketing']));
        $key = self::string($body['key']);

        $view = $this->api('POST', '/api/v1/admin/products', $this->body(['images' => [['key' => $key, 'alt' => 'Z uploadu']]]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame($key, self::list($view['images'])[0]['key']);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/images/'.$name);
    }

    /**
     * @return array<mixed>
     */
    private function upload(string $contents, string $name, ?string $token): array
    {
        $server = null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $this->client->request('POST', self::PATH, files: ['file' => $this->uploaded($contents, $name)], server: $server);

        return $this->decoded();
    }

    private function uploaded(string $contents, string $name): UploadedFile
    {
        $this->tmp = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($this->tmp, $contents);

        return new UploadedFile($this->tmp, $name, null, \UPLOAD_ERR_OK, true);
    }

    private function bucket(): FilesystemOperator
    {
        $bucket = self::getContainer()->get('test.product_images.storage');
        self::assertInstanceOf(FilesystemOperator::class, $bucket);

        return $bucket;
    }

    /**
     * @return list<string>
     */
    private function storedObjects(): array
    {
        $paths = array_values($this->bucket()->listContents('', true)
            ->filter(static fn ($item): bool => $item->isFile())
            ->map(static fn ($item): string => $item->path())
            ->toArray());
        sort($paths);

        return $paths;
    }
}
