<?php

declare(strict_types=1);

namespace App\Payment\Application;

/**
 * A webhook the shop refuses before reading what it says: a missing or wrong signature, a timestamp outside the
 * tolerance, or a body that breaks the format of its provider. The message names the cause for the log, never a
 * secret or the body; the response is a 400 with one fixed text for every cause.
 */
final class InvalidWebhook extends \RuntimeException
{
    public static function signature(string $cause): self
    {
        return new self('Webhook signature: '.$cause.'.');
    }

    public static function body(string $field): self
    {
        return new self('Webhook body: '.$field.' is missing or malformed.');
    }
}
