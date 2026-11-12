<?php

namespace App\Support;

/** Money is calculated in integer paise so GST and discount splits never drift. */
final class Money
{
    public static function toPaise(string|int|float|null $amount): int
    {
        return (int) bcmul(number_format((float) ($amount ?? 0), 2, '.', ''), '100', 0);
    }

    public static function fromPaise(int $paise): string
    {
        return bcdiv((string) $paise, '100', 2);
    }

    /** Multiply a price by a decimal quantity, rounding half up to the paisa. */
    public static function lineTotal(string|float $unitPrice, string|float $quantity): string
    {
        $raw = bcmul(number_format((float) $unitPrice, 2, '.', ''), number_format((float) $quantity, 3, '.', ''), 5);

        return bcdiv((string) (int) round((float) bcmul($raw, '100', 3)), '100', 2);
    }
}
