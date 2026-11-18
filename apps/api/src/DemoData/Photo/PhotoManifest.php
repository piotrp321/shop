<?php

declare(strict_types=1);

namespace App\DemoData\Photo;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * fixtures/demo-data/photos.yaml: the version of the pool, the key of its archive in the private bucket and the files
 * of the pool (sum, photo group, alt text and the origin of every file: a photo of Wikimedia Commons under CC0 1.0 or in
 * the public domain, and the variant of it: the original, the mirror image or a crop). The schema is closed: an unknown key (an
 * endpoint, a bucket, a credential) is a violation, so the file cannot carry the access to the bucket.
 */
final readonly class PhotoManifest
{
    private const array LICENSES = ['CC0 1.0', 'Public domain'];
    private const array VARIANTS = [
        'original', 'mirror', 'crop-left', 'crop-right', 'crop-top', 'crop-bottom', 'crop-center',
        'mirror-crop-left', 'mirror-crop-right', 'mirror-crop-center',
    ];
    private const int MAX_TEXT = 300;
    private const int MAX_FILE_NAME = 100;
    private const array TOP_KEYS = ['version', 'key', 'files'];
    private const array FILE_KEYS = ['file', 'sha256', 'group', 'alt', 'sourceId', 'variant', 'source', 'author', 'license'];

    /**
     * @param non-empty-list<PhotoEntry> $files
     */
    private function __construct(
        public int $version,
        public string $key,
        public array $files,
    ) {
    }

    /**
     * @param list<string> $groups the photo groups of photo-groups.yaml
     *
     * @throws InvalidPhotoManifest
     */
    public static function fromFile(string $path, array $groups): self
    {
        $yaml = is_file($path) ? file_get_contents($path) : false;
        if (false === $yaml) {
            throw InvalidPhotoManifest::because(\sprintf('The manifest "%s" does not exist or cannot be read.', $path));
        }

        return self::fromYaml($yaml, $groups);
    }

    /**
     * @param list<string> $groups
     *
     * @throws InvalidPhotoManifest
     */
    public static function fromYaml(string $yaml, array $groups): self
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException) {
            throw InvalidPhotoManifest::because('The manifest is not valid YAML.');
        }
        if (!\is_array($data) || [] === $data || array_is_list($data)) {
            throw InvalidPhotoManifest::because('The manifest has to be a mapping with the keys version, key and files.');
        }

        $violations = [];
        foreach (array_keys($data) as $name) {
            if (!\in_array($name, self::TOP_KEYS, true)) {
                $violations[] = \sprintf('unknown key "%s"', self::name($name));
            }
        }

        $version = $data['version'] ?? null;
        if (!\is_int($version) || $version < 1) {
            $violations[] = 'version: a whole number from 1 is required';
            $version = 0;
        }
        $key = $data['key'] ?? null;
        if (!\is_string($key) || ($version > 0 && $key !== \sprintf('demo-photos/v%d/photos.tar', $version))) {
            $violations[] = 'key: has to be demo-photos/v<N>/photos.tar, N being the version';
            $key = '';
        }

        $entries = [];
        $files = $data['files'] ?? null;
        if (!\is_array($files) || [] === $files || !array_is_list($files)) {
            $violations[] = 'files: a non-empty list is required';
        } else {
            $seen = ['file' => [], 'sha256' => [], 'pair' => []];
            foreach ($files as $index => $row) {
                $entry = self::entry($index, $row, $groups, $seen, $violations);
                if (null !== $entry) {
                    $entries[] = $entry;
                }
            }
        }

        if ([] !== $violations || [] === $entries) {
            throw new InvalidPhotoManifest([] === $violations ? ['files: a non-empty list is required'] : $violations);
        }

        return new self($version, $key, $entries);
    }

    /**
     * @return non-empty-list<string> the names of the files, in the order of the manifest
     */
    public function fileNames(): array
    {
        return array_map(static fn (PhotoEntry $entry): string => $entry->file, $this->files);
    }

    /**
     * @return array<string, non-empty-list<string>> the names of the files of every photo group that has any
     */
    public function fileNamesByGroup(): array
    {
        $groups = [];
        foreach ($this->files as $entry) {
            $groups[$entry->group][] = $entry->file;
        }

        return $groups;
    }

    /**
     * @param list<string> $groups the photo groups of photo-groups.yaml
     *
     * @return list<string> the groups that no file of the manifest belongs to
     */
    public function groupsWithoutFiles(array $groups): array
    {
        return array_values(array_diff($groups, array_keys($this->fileNamesByGroup())));
    }

    /**
     * @param list<string>                                                                             $groups
     * @param array{file: array<string, true>, sha256: array<string, true>, pair: array<string, true>} $seen
     * @param list<string>                                                                             $violations
     */
    private static function entry(int $index, mixed $row, array $groups, array &$seen, array &$violations): ?PhotoEntry
    {
        $where = \sprintf('files[%d]', $index);
        if (!\is_array($row) || array_is_list($row)) {
            $violations[] = $where.': a mapping is required';

            return null;
        }

        $before = \count($violations);
        foreach (array_keys($row) as $name) {
            if (!\in_array($name, self::FILE_KEYS, true)) {
                $violations[] = \sprintf('%s: unknown key "%s"', $where, self::name($name));
            }
        }

        $file = self::value($row, 'file', $where, $violations);
        if (null !== $file && (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $file) || \strlen($file) > self::MAX_FILE_NAME)) {
            $violations[] = $where.'.file: a plain file name of letters, digits, dot, dash and underscore (up to '.self::MAX_FILE_NAME.' characters) is required';
            $file = null;
        }
        $sha256 = self::value($row, 'sha256', $where, $violations);
        if (null !== $sha256 && 1 !== preg_match('/^[0-9a-f]{64}$/D', $sha256)) {
            $violations[] = $where.'.sha256: 64 lower-case hex digits are required';
            $sha256 = null;
        }
        $group = self::value($row, 'group', $where, $violations);
        if (null !== $group && !\in_array($group, $groups, true)) {
            $violations[] = $where.'.group: not a photo group of photo-groups.yaml';
            $group = null;
        }
        $alt = self::text($row, 'alt', $where, $violations);
        $author = self::text($row, 'author', $where, $violations);
        $sourceId = self::value($row, 'sourceId', $where, $violations);
        if (null !== $sourceId && 1 !== preg_match('/^commons:[1-9][0-9]{0,17}$/D', $sourceId)) {
            $violations[] = $where.'.sourceId: commons:<page id> with a positive whole number without leading zeros is required';
            $sourceId = null;
        }
        $variant = self::value($row, 'variant', $where, $violations);
        if (null !== $variant && !\in_array($variant, self::VARIANTS, true)) {
            $violations[] = $where.'.variant: one of '.implode(', ', self::VARIANTS).' is required';
            $variant = null;
        }
        $source = self::value($row, 'source', $where, $violations);
        if (null !== $source && !self::isCommonsFilePage($source)) {
            $violations[] = $where.'.source: an https://commons.wikimedia.org/wiki/File:… page is required';
            $source = null;
        }
        $license = self::value($row, 'license', $where, $violations);
        if (null !== $license && !\in_array($license, self::LICENSES, true)) {
            $violations[] = $where.'.license: has to be one of "'.implode('", "', self::LICENSES).'"';
            $license = null;
        }

        $pair = null === $sourceId || null === $variant ? null : $sourceId.' '.$variant;
        foreach (['file' => $file, 'sha256' => $sha256, 'pair' => $pair] as $field => $value) {
            if (null === $value) {
                continue;
            }
            $normalized = 'sha256' === $field ? $value : strtolower($value);
            if (isset($seen[$field][$normalized])) {
                $violations[] = 'pair' === $field
                    ? $where.'.sourceId: duplicate of an earlier file with the same photo and variant'
                    : $where.'.'.$field.': duplicate of an earlier file';
            }
            $seen[$field][$normalized] = true;
        }

        if (\count($violations) > $before || null === $file || null === $sha256 || null === $group || null === $alt || null === $author || null === $sourceId || null === $variant || null === $source || null === $license) {
            return null;
        }

        return new PhotoEntry($file, $sha256, $group, $alt, $sourceId, $variant, $source, $author, $license);
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<string>            $violations
     */
    private static function value(array $row, string $field, string $where, array &$violations): ?string
    {
        $value = $row[$field] ?? null;
        if (!\is_string($value) || '' === $value) {
            $violations[] = $where.'.'.$field.': a non-empty text is required';

            return null;
        }
        if (str_contains($value, 'DEMO_PHOTOS_')) {
            $violations[] = $where.'.'.$field.': must not mention the DEMO_PHOTOS_ credential variables';

            return null;
        }

        return $value;
    }

    /**
     * A human text: valid UTF-8, no control, format or line-separator characters (NUL, U+2028, tab), not blank (also not
     * only Unicode spaces), no space at the ends, up to 300 characters.
     *
     * @param array<array-key, mixed> $row
     * @param list<string>            $violations
     */
    private static function text(array $row, string $field, string $where, array &$violations): ?string
    {
        $value = self::value($row, $field, $where, $violations);
        if (null === $value) {
            return null;
        }
        $problem = match (true) {
            1 !== preg_match('//u', $value) => 'valid UTF-8 text',
            1 === preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) => 'text without control characters',
            '' === preg_replace('/[\p{Z}\s]+/u', '', $value) => 'text that is not blank',
            1 === preg_match('/^[\p{Z}\s]|[\p{Z}\s]$/u', $value) => 'text without spaces at the ends',
            mb_strlen($value) > self::MAX_TEXT => 'text of up to '.self::MAX_TEXT.' characters',
            default => null,
        };
        if (null !== $problem) {
            $violations[] = $where.'.'.$field.': '.$problem.' is required';

            return null;
        }

        return $value;
    }

    /**
     * A file page of Wikimedia Commons: https, the host exactly commons.wikimedia.org and the path /wiki/File:<name>, the
     * name made of the characters of a percent-encoded title (letters, digits, % and the punctuation of titles), without
     * a further path, user, port, query or fragment.
     */
    private static function isCommonsFilePage(string $url): bool
    {
        $parts = parse_url($url);

        return false !== $parts
            && 'https' === ($parts['scheme'] ?? null)
            && 'commons.wikimedia.org' === ($parts['host'] ?? null)
            && [] === array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts))
            && 1 === preg_match('#^/wiki/File:[A-Za-z0-9_.,()%@!\'~+=&$*;:-]+$#D', $parts['path'] ?? '');
    }

    /**
     * The name of an unknown key for a message: printable and short, so a key cannot smuggle a line break or a long text.
     */
    private static function name(int|string $name): string
    {
        $text = (string) $name;

        return 1 === preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $text) ? $text : '(not a plain name)';
    }
}
