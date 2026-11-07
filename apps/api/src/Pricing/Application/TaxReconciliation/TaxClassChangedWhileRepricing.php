<?php

declare(strict_types=1);

namespace App\Pricing\Application\TaxReconciliation;

/**
 * The tax class of a product changed again while its prices were repriced to the class read before. The repricing
 * is to be run again (a retry of the message), so the prices end in the class the product has then.
 */
final class TaxClassChangedWhileRepricing extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The tax class of the product changed while its prices were repriced.');
    }
}
