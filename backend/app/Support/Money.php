<?php

namespace App\Support;

/** Money is stored as integer paise. Helpers for Indian formatting and rounding. */
final class Money
{
    public static function format(int $paise, string $symbol = '₹'): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);
        $rupees = intdiv($paise, 100);
        $fraction = str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
        $digits = (string) $rupees;
        if (strlen($digits) > 3) {
            $last3 = substr($digits, -3);
            $rest = substr($digits, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $digits = $rest.','.$last3;
        }

        return ($negative ? '-' : '').$symbol.$digits.'.'.$fraction;
    }

    public static function percentOf(int $paise, float $percent): int
    {
        return (int) round($paise * $percent / 100);
    }
}
