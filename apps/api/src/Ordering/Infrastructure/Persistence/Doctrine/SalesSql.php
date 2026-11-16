<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

/**
 * The SQL expressions of the row of ordering_sales_hour an order belongs to, the only definition of both: the addition
 * of a payment and the rebuild from the orders use them, so they always agree.
 */
final class SalesSql
{
    /**
     * The start of the hour (UTC) of a moment: whole hours of UTC are whole hours of the Polish clock too.
     */
    public static function hourOf(string $moment): string
    {
        return \sprintf("date_bin('1 hour', %s, TIMESTAMPTZ '2000-01-01 00:00:00+00')", $moment);
    }

    /**
     * One of the 16 shards of an order id (uuid): the low four bits of its last byte, random in a UUID v7.
     */
    public static function shardOf(string $orderId): string
    {
        return \sprintf('(get_byte(uuid_send(%s), 15) & 15)', $orderId);
    }
}
