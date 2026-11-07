<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The status of the promotion does not allow the change (decision Q5): after its start only the name and the end
 * change, an ended one changes no more, and only a planned one can be deleted.
 */
final class PromotionNotEditable extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/promotion-not-editable';

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function afterStart(): self
    {
        return new self('Po rozpoczęciu promocji można zmienić tylko jej nazwę i koniec.');
    }

    public static function ended(): self
    {
        return new self('Zakończonej promocji nie można zmienić.');
    }

    public static function notDeletable(): self
    {
        return new self('Usunąć można tylko zaplanowaną promocję.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
