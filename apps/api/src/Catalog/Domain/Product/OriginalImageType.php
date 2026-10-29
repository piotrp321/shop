<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

enum OriginalImageType: string
{
    case Jpg = 'jpg';
    case Png = 'png';
    case Webp = 'webp';
    case Avif = 'avif';
    case Heic = 'heic';
}
