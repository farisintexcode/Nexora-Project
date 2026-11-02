<?php

namespace App\Support;

/** Amounts are held as whole cents so that comparing a claim with a balance never meets a rounding error. */
class Money
{
    private const MAX_CENTS = 99_999_999_999_99;

    /**
     * Reads typed or workbook amounts such as 1234.5, 1,234.50 or RM 1,234.50.
     *
     * @return int|null whole cents, or null when the text is not a valid amount
     */
    public static function cents(mixed $value): ?int
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = trim((string) $value);
        if (! preg_match('/^(?:RM\s*)?((?:\d{1,3}(?:,\d{3})+|\d+))(?:\.(\d{1,2}))?$/i', $text, $match)) {
            return null;
        }
        $whole = str_replace(',', '', $match[1]);
        if (strlen(ltrim($whole, '0')) > 12) {
            return null;
        }
        $cents = (int) $whole * 100 + (int) str_pad($match[2] ?? '', 2, '0');

        return $cents <= self::MAX_CENTS ? $cents : null;
    }

    /** The plain form stored in encrypted columns and shown in report rows, such as 1234.50. */
    public static function plain(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** The form people read, such as RM 1,234.50. */
    public static function format(?int $cents): string
    {
        return $cents === null ? 'Not provided' : 'RM '.number_format($cents / 100, 2);
    }
}
