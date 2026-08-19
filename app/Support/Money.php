<?php

namespace App\Support;

class Money
{
    public static function format(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    public static function toCents(string|int|float $value): int
    {
        $normalized = str_replace(['R$', ' '], '', (string) $value);
        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return (int) round(((float) $normalized) * 100);
    }
}
