<?php

declare(strict_types=1);

namespace App\Ordering\Application;

/**
 * How the emails of Ordering write amounts and moments. Amounts as `1 234,56 zł` with plain spaces (a plain-text
 * email has no markup to keep the number together, and a no-break space may not survive a mail client); moments in
 * Warsaw time.
 */
final class MailFormat
{
    private const string TIME_ZONE = 'Europe/Warsaw';

    public static function amount(int $grosze): string
    {
        return \sprintf('%s,%02d zł', number_format(intdiv($grosze, 100), 0, '', ' '), $grosze % 100);
    }

    public static function inWarsaw(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone(self::TIME_ZONE));
    }
}
