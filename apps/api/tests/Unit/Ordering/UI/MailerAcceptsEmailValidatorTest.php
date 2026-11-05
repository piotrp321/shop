<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\UI;

use App\Ordering\UI\Http\Validation\MailerAcceptsEmail;
use App\Ordering\UI\Http\Validation\MailerAcceptsEmailValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<MailerAcceptsEmailValidator>
 */
final class MailerAcceptsEmailValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): MailerAcceptsEmailValidator
    {
        return new MailerAcceptsEmailValidator();
    }

    #[DataProvider('accepted')]
    public function testAnAddressTheMailerAcceptsIsValid(string $email): void
    {
        $this->validator->validate($email, new MailerAcceptsEmail());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'plain' => ['anna.nowak@example.com'];
        yield 'with a tag' => ['anna+sklep@example.com'];
        yield 'with surrounding spaces, which the field trims' => ['  anna@example.com '];
        yield 'with a hyphen and digits' => ['a-1@sub-domain.example.pl'];
    }

    #[DataProvider('refused')]
    public function testAnAddressTheMailerRefusesIsAViolation(string $email): void
    {
        $this->validator->validate($email, new MailerAcceptsEmail());

        $this->buildViolation('Podaj poprawny adres e-mail.')->assertRaised();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'two dots in a row' => ['a..b@example.com'];
        yield 'a leading dot' => ['.a@example.com'];
        yield 'a dot before the @' => ['a.@example.com'];
        yield 'no domain' => ['anna@'];
        yield 'no @' => ['anna.example.com'];
        yield 'a line break inside' => ["anna@exam\nple.com"];
    }

    public function testWhatIsNotAnAddressStringIsLeftToTheOtherConstraints(): void
    {
        foreach ([null, '', '   ', 5, ['a@example.com']] as $value) {
            $this->validator->validate($value, new MailerAcceptsEmail());
        }

        $this->assertNoViolation();
    }
}
