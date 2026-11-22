<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The language of the messages of the validator (docs/features/api-input-contract/spec.md, "Rozwiązanie", 1): Polish
 * by default, the catalogue of the framework for its constraints, the catalogue of the project for the type error that
 * #[MapQueryString] and #[MapRequestPayload] build (the same template for a query parameter and a field of a body),
 * and a message of its own unchanged.
 */
final class ValidatorMessagesLanguageTest extends KernelTestCase
{
    public function testTheTypeErrorHasOneTextWithoutTheNameOfAType(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        self::assertSame('pl', $translator->getLocale());
        foreach (['int', 'null|string', 'bool'] as $type) {
            self::assertSame('Ta wartość ma nieprawidłowy typ.', $translator->trans('This value should be of type {{ type }}.', ['{{ type }}' => $type], 'validators'));
        }
    }

    public function testAConstraintWithoutAMessageOfItsOwnSpeaksPolishAndOneWithItKeepsIt(): void
    {
        $validator = self::getContainer()->get('validator');
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $framework = $validator->validate('', new Assert\NotBlank());
        $own = $validator->validate('', new Assert\NotBlank(message: 'Kod rabatowy musi być tekstem.'));

        self::assertSame('Ta wartość nie powinna być pusta.', $framework->get(0)->getMessage());
        self::assertSame('Kod rabatowy musi być tekstem.', $own->get(0)->getMessage());
    }
}
