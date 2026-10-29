<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Image;

use App\Catalog\Application\Image\ImageFormat;
use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Port\ImageUrlGenerator;

final readonly class ImgproxyUrlGenerator implements ImageUrlGenerator
{
    private string $publicUrl;
    private string $key;
    private string $salt;

    /**
     * @param string $key  imgproxy signing key, hex encoded
     * @param string $salt imgproxy signing salt, hex encoded
     */
    public function __construct(string $publicUrl, string $key, string $salt, private string $bucket)
    {
        $this->publicUrl = rtrim($publicUrl, '/');
        $this->key = self::decodeHex($key);
        $this->salt = self::decodeHex($salt);
    }

    public function url(string $imageKey, ImageSize $size, int $width, ImageFormat $format): string
    {
        if (!\in_array($width, $size->widths(), true)) {
            throw new \InvalidArgumentException('Width is not defined for the image size.');
        }
        if (!\in_array($format, $size->formats(), true)) {
            throw new \InvalidArgumentException('Format is not defined for the image size.');
        }

        [$ratioWidth, $ratioHeight] = array_map('intval', explode(':', $size->aspectRatio()));
        $height = intdiv($width * $ratioHeight, $ratioWidth);

        $source = self::base64Url(\sprintf('s3://%s/%s', $this->bucket, $imageKey));
        $path = \sprintf('/rs:%s:%d:%d/g:%s/%s.%s', $size->resize(), $width, $height, $size->gravity(), $source, $format->value);

        return $this->publicUrl.'/'.$this->sign($path).$path;
    }

    /**
     * imgproxy signature: base64url (no padding) of HMAC-SHA256(key, salt + path).
     */
    public function sign(string $path): string
    {
        return self::base64Url(hash_hmac('sha256', $this->salt.$path, $this->key, true));
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function decodeHex(string $hex): string
    {
        $binary = 0 === \strlen($hex) % 2 && ctype_xdigit($hex) ? hex2bin($hex) : false;
        if (false === $binary) {
            throw new \InvalidArgumentException('imgproxy key and salt must be hex encoded.');
        }

        return $binary;
    }
}
