<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bin;

use App\DemoData\Catalog\Dictionaries;
use App\Tests\Unit\DemoData\PoolFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs bin/upload-demo-photos from a temporary copy of the tree it expects (apps/api/bin, infra/k8s/charts/shop/values.yaml)
 * with a substituted `docker` first in PATH. The fake logs every call; `docker compose exec -T api …` runs the real command
 * in the directory that stands for apps/api (so the check applies the real rules to small files), and `docker run` (the
 * upload) only logs its arguments, its RCLONE_* environment and a copy of the archive it is given.
 */
final class UploadDemoPhotosScriptTest extends TestCase
{
    private const string ENDPOINT = 'https://account-1234.r2.example.invalid';
    private const string BUCKET = 'shop-demo-photos-test';
    private const string WRITE_KEY_ID = 'AKIA-write-key-id-of-the-test';
    private const string WRITE_SECRET = 'secret-write-value-of-the-test';
    private const string READ_KEY_ID = 'AKIA-read-key-id-of-the-test';
    private const string READ_SECRET = 'secret-read-value-of-the-test';

    private string $dir;
    private string $api;
    private Dictionaries $dictionaries;
    /** @var array{version: int, key: string, files: list<array<string, string>>} */
    private array $manifest;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/upload-demo-photos-test-'.bin2hex(random_bytes(6));
        $this->api = $this->dir.'/apps/api';
        mkdir($this->api.'/bin', 0o700, true);
        mkdir($this->api.'/var/photos', 0o700, true);
        mkdir($this->dir.'/infra/k8s/charts/shop', 0o700, true);
        mkdir($this->dir.'/fakebin', 0o700);
        mkdir($this->dir.'/home/.config/shop', 0o700, true);
        copy(\dirname(__DIR__, 3).'/bin/upload-demo-photos', $this->api.'/bin/upload-demo-photos');
        chmod($this->api.'/bin/upload-demo-photos', 0o700);
        // The chart is not mounted into the api container, so the test has the lines of values.yaml the script reads of its own.
        file_put_contents($this->dir.'/infra/k8s/charts/shop/values.yaml', <<<YAML
            # The images.
            images:
              postgres: postgres:18.6@sha256:e3d24f6906d0760bf4da0dae872415391ededefad23fd471c47dc53b0ddf3c42
              restic: restic/restic:0.19.1@sha256:136600b6ff6843d61d355f7f71f460a166429f35de6fd11b568fece3c9a4d510
              rclone: rclone/rclone:1.75.2@sha256:2687085f718d3c628f7fdfb77c52a1d344332aed543110bb88088f2c70d43eb5
            ingress:
              className: traefik
            YAML);

        file_put_contents($this->dir.'/fakebin/docker', <<<'SH'
            #!/bin/sh
            { printf 'docker'; for argument in "$@"; do printf ' %s' "$argument"; done; echo; } >> "$FAKE_DIR/docker.log"
            if [ "$1" = "compose" ] && [ "$2" = "exec" ]; then
                shift 2
                [ "$1" = "-T" ] && shift
                [ "$1" = "api" ] && shift
                cd "$FAKE_DIR/apps/api" || exit 99
                if [ "$1" = "bin/console" ]; then
                    shift
                    exec "$FAKE_CONSOLE" "$@"
                fi
                exec "$@"
            fi
            if [ "$1" = "run" ]; then
                env | grep '^RCLONE_' | sort >> "$FAKE_DIR/run-env.log"
                previous=""
                for argument in "$@"; do
                    if [ "$previous" = "-v" ]; then cp "${argument%%:*}" "$FAKE_DIR/uploaded.tar"; fi
                    previous="$argument"
                done
                if [ -n "${FAKE_RUN_FAILS:-}" ]; then
                    echo "docker: the upload failed" >&2
                    exit 125
                fi
                exit 0
            fi
            echo "unexpected docker call: $*" >&2
            exit 98
            SH);
        chmod($this->dir.'/fakebin/docker', 0o700);

        $this->dictionaries = new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
        $this->manifest = PoolFixture::write($this->api.'/var/photos', $this->dictionaries, 1, 'upload-test');
        $this->writeManifest();
        $this->writeCredentials();
    }

    protected function tearDown(): void
    {
        PoolFixture::remove($this->dir);
    }

    public function testItChecksTheDirectoryThenUploadsTheArchiveWithCopytoImmutableAndPrintsNoCredential(): void
    {
        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(0, $code, $stdout.$stderr);
        $calls = $this->dockerCalls();
        self::assertCount(3, $calls, implode("\n", $calls));
        self::assertSame('docker compose exec -T api bin/console demo-data:photos:check var/photos --manifest=manifest.yaml --archive=var/demo-photos-upload.tar', $calls[0]);
        self::assertStringStartsWith('docker run --rm ', $calls[1]);
        self::assertStringContainsString(' copyto --immutable ', $calls[1]);
        self::assertStringContainsString(' --s3-no-check-bucket ', $calls[1]);
        self::assertStringContainsString(' rclone/rclone:1.75.2@sha256:2687085f718d3c628f7fdfb77c52a1d344332aed543110bb88088f2c70d43eb5 ', $calls[1]);
        self::assertStringEndsWith(' /data/photos.tar r2:'.self::BUCKET.'/demo-photos/v1/photos.tar', $calls[1]);
        self::assertSame('docker compose exec -T api rm -f var/demo-photos-upload.tar', $calls[2], 'The archive is removed.');
        $this->assertNoSecrets($calls[1].$stdout.$stderr.implode("\n", $calls));

        $environment = (string) file_get_contents($this->dir.'/run-env.log');
        self::assertStringContainsString('RCLONE_CONFIG_R2_ENDPOINT='.self::ENDPOINT, $environment);
        self::assertStringContainsString('RCLONE_CONFIG_R2_ACCESS_KEY_ID='.self::WRITE_KEY_ID, $environment);
        self::assertStringContainsString('RCLONE_CONFIG_R2_SECRET_ACCESS_KEY='.self::WRITE_SECRET, $environment);
        self::assertStringContainsString('RCLONE_CONFIG_R2_PROVIDER=Cloudflare', $environment);
        self::assertStringNotContainsString(self::READ_KEY_ID, $environment, 'The token that only reads is not the one that writes.');
        self::assertStringNotContainsString(self::READ_SECRET, $environment);
        foreach (['-e RCLONE_CONFIG_R2_ENDPOINT ', '-e RCLONE_CONFIG_R2_ACCESS_KEY_ID ', '-e RCLONE_CONFIG_R2_SECRET_ACCESS_KEY '] as $name) {
            self::assertStringContainsString($name, $calls[1], 'The variables are passed by name, the values come from the environment.');
        }
    }

    public function testTheArchiveThatIsUploadedHoldsTheFilesOfTheManifest(): void
    {
        [$code] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(0, $code);
        exec(\sprintf('tar -tf %s 2>&1', escapeshellarg($this->dir.'/uploaded.tar')), $names, $tarCode);
        self::assertSame(0, $tarCode);
        sort($names);
        $expected = array_map(static fn (array $file): string => $file['file'], $this->manifest['files']);
        sort($expected);
        self::assertSame($expected, $names);
    }

    public function testTheKeyFollowsTheVersionOfTheManifest(): void
    {
        $this->manifest['version'] = 7;
        $this->manifest['key'] = 'demo-photos/v7/photos.tar';
        $this->writeManifest();

        [$code] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(0, $code);
        self::assertStringEndsWith(' r2:'.self::BUCKET.'/demo-photos/v7/photos.tar', $this->dockerCalls()[1]);
    }

    public function testTheImageIsTheRcloneOneOfTheChartPinnedByDigest(): void
    {
        [$code] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(0, $code);
        self::assertStringContainsString(' rclone/rclone:1.75.2@sha256:2687085f718d3c628f7fdfb77c52a1d344332aed543110bb88088f2c70d43eb5 ', $this->dockerCalls()[1]);
        self::assertStringNotContainsString('restic', $this->dockerCalls()[1]);
    }

    public function testPrintImageShowsTheImageAndTouchesNothing(): void
    {
        [$code, $stdout] = $this->execute(['--print-image']);

        self::assertSame(0, $code);
        self::assertSame("rclone/rclone:1.75.2@sha256:2687085f718d3c628f7fdfb77c52a1d344332aed543110bb88088f2c70d43eb5\n", $stdout);
        self::assertSame([], $this->dockerCalls());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpinnedImages(): iterable
    {
        yield 'a tag without a digest' => ['  rclone: rclone/rclone:1.75.2'];
        yield 'a short digest' => ['  rclone: rclone/rclone:1.75.2@sha256:abc'];
        yield 'a digest in capitals' => ['  rclone: rclone/rclone:1.75.2@sha256:2687085F718D3C628F7FDFB77C52A1D344332AED543110BB88088F2C70D43EB5'];
        yield 'no rclone line' => ['  restic: restic/restic:0.19.1'];
    }

    #[DataProvider('unpinnedImages')]
    public function testAnImageThatIsNotPinnedByDigestIsRefusedBeforeAnyDockerCall(string $line): void
    {
        file_put_contents($this->dir.'/infra/k8s/charts/shop/values.yaml', "images:\n".$line."\n");

        [$code, , $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('rclone', $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testAMissingValuesFileIsRefusedBeforeAnyDockerCall(): void
    {
        unlink($this->dir.'/infra/k8s/charts/shop/values.yaml');

        [$code, , $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('values.yaml', $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testAFileWithAnotherSumIsRefusedBeforeTheUpload(): void
    {
        $name = $this->manifest['files'][2]['file'];
        file_put_contents($this->api.'/var/photos/'.$name, 'changed after the manifest was written');

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(1, $code);
        self::assertStringContainsString($name.': the SHA-256 does not match the manifest', $stdout.$stderr);
        $this->assertNoUpload();
        $this->assertNoSecrets($stdout.$stderr.implode("\n", $this->dockerCalls()));
    }

    public function testAMissingFileIsRefusedBeforeTheUpload(): void
    {
        $name = $this->manifest['files'][0]['file'];
        unlink($this->api.'/var/photos/'.$name);

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(1, $code);
        self::assertStringContainsString($name.': missing', $stdout.$stderr);
        $this->assertNoUpload();
    }

    public function testAFileThatIsNotInTheManifestIsRefusedBeforeTheUpload(): void
    {
        file_put_contents($this->api.'/var/photos/extra.jpg', 'x');

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(1, $code);
        self::assertStringContainsString('extra.jpg: not in the manifest', $stdout.$stderr);
        $this->assertNoUpload();
    }

    /**
     * @return iterable<string, array{string, string}> the contents and the part of the reason that is expected
     */
    public static function filesBreakingTheUploadRules(): iterable
    {
        yield 'text that is not an image' => ['This is not a photo.', 'not an image type the catalog accepts'];
        yield 'a gif' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'not an image type the catalog accepts'];
        yield 'an image of 100 megapixels' => [self::pngHeader(10000, 10000), 'more than 50000000 pixels'];
        yield 'an image whose dimensions cannot be read' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", 'the dimensions cannot be read'];
    }

    #[DataProvider('filesBreakingTheUploadRules')]
    public function testAFileThatBreaksTheUploadRulesIsRefusedBeforeTheUploadEvenWithTheRightSum(string $contents, string $reason): void
    {
        $this->replaceFile(1, $contents);

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(1, $code);
        self::assertStringContainsString($this->manifest['files'][1]['file'].': '.$reason, $stdout.$stderr);
        $this->assertNoUpload();
    }

    public function testAFileOverTheSizeLimitIsRefusedBeforeTheUpload(): void
    {
        $path = $this->api.'/var/photos/'.$this->manifest['files'][1]['file'];
        file_put_contents($path, self::pngHeader(10, 10));
        $handle = fopen($path, 'a');
        self::assertNotFalse($handle);
        ftruncate($handle, 65 * 1024 * 1024);
        fclose($handle);
        $this->manifest['files'][1]['sha256'] = (string) hash_file('sha256', $path);
        $this->writeManifest();

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(1, $code);
        self::assertStringContainsString($this->manifest['files'][1]['file'].': larger than 67108864 bytes', $stdout.$stderr);
        $this->assertNoUpload();
    }

    public function testAManifestThatBreaksTheSchemaIsRefusedBeforeTheUpload(): void
    {
        $manifest = $this->manifest;
        $manifest['secretAccessKey'] = self::WRITE_SECRET;
        file_put_contents($this->api.'/manifest.yaml', Yaml::dump($manifest, 4));

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(1, $code);
        self::assertStringContainsString('unknown key "secretAccessKey"', $stdout.$stderr);
        $this->assertNoUpload();
        $this->assertNoSecrets($stdout.$stderr);
    }

    public function testADirectoryOutsideApiIsRefusedBeforeAnyDockerCall(): void
    {
        mkdir($this->dir.'/elsewhere');

        [$code, , $stderr] = $this->execute([$this->dir.'/elsewhere', '--manifest=manifest.yaml']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('apps/api', $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testADirectoryThatLeavesApiThroughDotsOrALinkIsRefused(): void
    {
        mkdir($this->dir.'/elsewhere');
        symlink($this->dir.'/elsewhere', $this->api.'/var/link');

        [$code, , $stderr] = $this->execute(['var/link', '--manifest=manifest.yaml']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('apps/api', $stderr);

        [$code] = $this->execute(['var/photos/../../../../elsewhere', '--manifest=manifest.yaml']);
        self::assertNotSame(0, $code);
        self::assertSame([], $this->dockerCalls());
    }

    public function testAMissingDirectoryIsRefusedBeforeAnyDockerCall(): void
    {
        [$code, , $stderr] = $this->execute(['var/nothing', '--manifest=manifest.yaml']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('var/nothing', $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testAManifestOutsideApiIsRefusedBeforeAnyDockerCall(): void
    {
        file_put_contents($this->dir.'/manifest.yaml', Yaml::dump($this->manifest, 4));

        [$code, , $stderr] = $this->execute(['var/photos', '--manifest='.$this->dir.'/manifest.yaml']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('apps/api', $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testAMissingCredentialsFileIsRefusedBeforeAnyDockerCallAndNamesIt(): void
    {
        unlink($this->dir.'/home/.config/shop/demo-photos.env');

        [$code, , $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('demo-photos.env', $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testTheCredentialsFileCanBeNamedWithAnOption(): void
    {
        rename($this->dir.'/home/.config/shop/demo-photos.env', $this->dir.'/other.env');

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml', '--env-file='.$this->dir.'/other.env']);

        self::assertSame(0, $code, $stdout.$stderr);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writeVariables(): iterable
    {
        yield 'the endpoint' => ['DEMO_PHOTOS_ENDPOINT'];
        yield 'the bucket' => ['DEMO_PHOTOS_BUCKET'];
        yield 'the write key id' => ['DEMO_PHOTOS_WRITE_ACCESS_KEY_ID'];
        yield 'the write secret' => ['DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY'];
    }

    #[DataProvider('writeVariables')]
    public function testAMissingOrEmptyVariableIsNamedWithoutItsValueBeforeAnyDockerCall(string $name): void
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->dir.'/home/.config/shop/demo-photos.env')), static fn (string $line): bool => !str_starts_with($line, $name.'='));
        file_put_contents($this->dir.'/home/.config/shop/demo-photos.env', implode("\n", $lines)."\n");

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString($name, $stderr);
        self::assertSame([], $this->dockerCalls());
        $this->assertNoSecrets($stdout.$stderr);

        file_put_contents($this->dir.'/home/.config/shop/demo-photos.env', implode("\n", $lines)."\n".$name."=\n");
        [$code, , $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString($name, $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testTheCredentialsFileIsReadAsDataNeverAsShell(): void
    {
        $marker = $this->dir.'/executed';
        file_put_contents($this->dir.'/home/.config/shop/demo-photos.env', implode("\n", [
            '# a comment with DEMO_PHOTOS_ENDPOINT=https://comment.example.invalid',
            'touch '.$marker,
            '$(touch '.$marker.')',
            'DEMO_PHOTOS_ENDPOINT="'.self::ENDPOINT.'"',
            "DEMO_PHOTOS_BUCKET='".self::BUCKET."'\r",
            'DEMO_PHOTOS_WRITE_ACCESS_KEY_ID='.self::WRITE_KEY_ID,
            'DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY=ab=cd$(touch '.$marker.')`touch '.$marker.'`;&|',
            'DEMO_PHOTOS_OTHER=ignored',
            '',
        ]));

        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml']);

        self::assertSame(0, $code, $stdout.$stderr);
        self::assertFileDoesNotExist($marker, 'Nothing in the file is executed.');
        $environment = (string) file_get_contents($this->dir.'/run-env.log');
        self::assertStringContainsString('RCLONE_CONFIG_R2_ENDPOINT='.self::ENDPOINT."\n", $environment);
        self::assertStringContainsString('RCLONE_CONFIG_R2_SECRET_ACCESS_KEY=ab=cd$(touch '.$marker.')`touch '.$marker.'`;&|'."\n", $environment);
        self::assertStringEndsWith(' r2:'.self::BUCKET.'/demo-photos/v1/photos.tar', $this->dockerCalls()[1]);
    }

    public function testAFailingUploadFailsTheScriptRemovesTheArchiveAndPrintsNoCredential(): void
    {
        [$code, $stdout, $stderr] = $this->execute(['var/photos', '--manifest=manifest.yaml'], ['FAKE_RUN_FAILS' => '1']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('upload', $stderr);
        $calls = $this->dockerCalls();
        self::assertSame('docker compose exec -T api rm -f var/demo-photos-upload.tar', end($calls));
        $this->assertNoSecrets($stdout.$stderr.implode("\n", $calls));
    }

    public function testWithoutADirectoryTheUsageIsPrintedWithCodeTwo(): void
    {
        [$code, , $stderr] = $this->execute([]);

        self::assertSame(2, $code);
        self::assertStringContainsString('Usage', $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testAnUnknownOptionIsRefusedWithCodeTwo(): void
    {
        [$code, , $stderr] = $this->execute(['var/photos', '--force']);

        self::assertSame(2, $code);
        self::assertStringContainsString('--force', $stderr);
        self::assertSame([], $this->dockerCalls());
    }

    public function testTheScriptNeverTracesOrEvaluatesWhatItReads(): void
    {
        $script = (string) file_get_contents($this->api.'/bin/upload-demo-photos');

        self::assertSame(0, preg_match('/^\s*set\s+-\w*x/m', $script), 'No set -x: it would print the environment.');
        self::assertSame(0, preg_match('/^\s*(eval|source)\b|^\s*\.\s/m', $script), 'No eval, no sourcing of the credentials file.');
        self::assertStringContainsString('set -eu', $script);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     *
     * @return array{int, string, string} the exit code, the output and the error output
     */
    private function execute(array $arguments, array $env = []): array
    {
        $process = proc_open(
            ['/bin/sh', $this->api.'/bin/upload-demo-photos', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->api,
            [
                ...getenv(),
                'PATH' => $this->dir.'/fakebin:'.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'HOME' => $this->dir.'/home',
                'FAKE_DIR' => $this->dir,
                'FAKE_CONSOLE' => \dirname(__DIR__, 3).'/bin/console',
                ...$env,
            ],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @return list<string>
     */
    private function dockerCalls(): array
    {
        $log = @file_get_contents($this->dir.'/docker.log');

        return false === $log ? [] : array_map('rtrim', explode("\n", trim($log)));
    }

    private function assertNoUpload(): void
    {
        foreach ($this->dockerCalls() as $call) {
            self::assertStringStartsNotWith('docker run', $call, 'The upload does not start when the check fails.');
        }
        self::assertFileDoesNotExist($this->dir.'/run-env.log');
        self::assertFileDoesNotExist($this->dir.'/uploaded.tar');
    }

    private function assertNoSecrets(string $text): void
    {
        foreach ([self::ENDPOINT, 'account-1234', self::WRITE_KEY_ID, self::WRITE_SECRET, self::READ_KEY_ID, self::READ_SECRET] as $value) {
            self::assertStringNotContainsString($value, $text);
        }
    }

    private function writeManifest(): void
    {
        file_put_contents($this->api.'/manifest.yaml', Yaml::dump($this->manifest, 4));
    }

    private function writeCredentials(): void
    {
        file_put_contents($this->dir.'/home/.config/shop/demo-photos.env', implode("\n", [
            '# Access to the private bucket of the photo pool',
            'DEMO_PHOTOS_ENDPOINT='.self::ENDPOINT,
            'DEMO_PHOTOS_BUCKET='.self::BUCKET,
            'DEMO_PHOTOS_WRITE_ACCESS_KEY_ID='.self::WRITE_KEY_ID,
            'DEMO_PHOTOS_WRITE_SECRET_ACCESS_KEY='.self::WRITE_SECRET,
            'DEMO_PHOTOS_ACCESS_KEY_ID='.self::READ_KEY_ID,
            'DEMO_PHOTOS_SECRET_ACCESS_KEY='.self::READ_SECRET,
            '',
        ]));
    }

    /**
     * Replaces a file of the pool and writes the sum of the new contents into the manifest, so only the rules can refuse it.
     */
    private function replaceFile(int $index, string $contents): void
    {
        file_put_contents($this->api.'/var/photos/'.$this->manifest['files'][$index]['file'], $contents);
        $this->manifest['files'][$index]['sha256'] = hash('sha256', $contents);
        $this->writeManifest();
    }

    /** A PNG whose header says the size and that has no image data: enough for the type and the dimensions. */
    private static function pngHeader(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }
}
