<?php

namespace App\Support;

final class Phone
{
    /** Normalise Indian mobile numbers to E.164 (+91XXXXXXXXXX); other inputs are kept digits-only with '+'. */
    public static function normalize(?string $input, string $defaultCountryCode = '91'): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $input);
        if (str_starts_with(trim($input), '+')) {
            return '+'.$digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return '+'.$defaultCountryCode.$digits;
        }

        return '+'.$digits;
    }
}
