<?php

declare(strict_types=1);

namespace App\PaymentSimulator\Http;

use App\PaymentSimulator\Amounts;
use App\PaymentSimulator\Transaction;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTML of the payment page: plain markup and a little CSS, no script, no template engine. Every value that comes
 * from a transaction goes through {@see self::e()}.
 */
final class PaymentPageHtml
{
    private const string ABOUT = 'To symulacja: żadne pieniądze nie zostaną pobrane.';

    /** The page of an open transaction: the choices and the way back. */
    public static function open(Transaction $transaction, string $action, string $csrfToken): Response
    {
        $form = \sprintf(
            '<form method="post" action="%s"><input type="hidden" name="_token" value="%s"><button type="submit" name="decision" value="pay">Zapłać</button> <button type="submit" name="decision" value="reject" class="secondary">Odrzuć płatność</button></form>',
            self::e($action),
            self::e($csrfToken),
        );

        return self::page(Response::HTTP_OK, $transaction->provider->label(), self::summary($transaction).$form.self::backLink($transaction));
    }

    /** The page of a transaction that was decided, cancelled or has expired: no choices, the way back. */
    public static function closed(Transaction $transaction): Response
    {
        return self::page(Response::HTTP_OK, $transaction->provider->label(), self::summary($transaction).'<p class="closed">Ta płatność jest już zamknięta.</p>'.self::backLink($transaction));
    }

    public static function notFound(): Response
    {
        return self::page(Response::HTTP_NOT_FOUND, 'Płatność', '<p>Nie znaleźliśmy tej płatności.</p>');
    }

    public static function badToken(): Response
    {
        return self::page(Response::HTTP_FORBIDDEN, 'Płatność', '<p>Nieprawidłowy token formularza. Odśwież stronę płatności i spróbuj ponownie.</p>');
    }

    public static function badDecision(): Response
    {
        return self::page(Response::HTTP_BAD_REQUEST, 'Płatność', '<p>Nieznana decyzja. Odśwież stronę płatności i spróbuj ponownie.</p>');
    }

    private static function summary(Transaction $transaction): string
    {
        return \sprintf(
            '<dl><dt>Zamówienie</dt><dd>%s</dd><dt>Kwota</dt><dd>%s %s</dd></dl>',
            self::e($transaction->reference),
            self::e(str_replace('.', ',', Amounts::format($transaction->amount))),
            self::e($transaction->currency),
        );
    }

    private static function backLink(Transaction $transaction): string
    {
        return \sprintf('<p><a href="%s">Wróć do sklepu</a></p>', self::e($transaction->returnUrl()));
    }

    private static function page(int $status, string $title, string $content): Response
    {
        $html = \sprintf(
            '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>%1$s</title><style>%2$s</style></head><body><main><p class="about">%3$s</p><h1>%1$s</h1>%4$s</main></body></html>',
            self::e($title),
            'body{font-family:system-ui,sans-serif;background:#f4f4f5;margin:0}main{max-width:28rem;margin:3rem auto;padding:1.5rem;background:#fff;border-radius:.5rem}.about{background:#fef3c7;padding:.5rem .75rem;border-radius:.25rem}dt{color:#52525b}dd{margin:0 0 .75rem;font-weight:600}button{font:inherit;padding:.5rem 1rem;border:0;border-radius:.25rem;background:#18181b;color:#fff;cursor:pointer}button.secondary{background:#e4e4e7;color:#18181b}.closed{font-weight:600}',
            self::ABOUT,
            $content,
        );

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
