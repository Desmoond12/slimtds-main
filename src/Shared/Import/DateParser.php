<?php

declare(strict_types=1);

namespace App\Shared\Import;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Parses a report row's date column against an explicit, network-configured
 * format (core.affiliate_networks.report_date_format) rather than guessing —
 * date format mismatches (DD/MM/YYYY vs MM/DD/YYYY vs Unix timestamp) are
 * the single most common way an import silently corrupts numbers, so a
 * malformed value throws with enough context to fix the mapping instead of
 * quietly landing on the wrong day.
 */
final class DateParser
{
    public static function parse(string $value, string $format, ?int $rowNumber = null): DateTimeImmutable
    {
        $value = trim($value);
        // Leading '!' resets every field the format doesn't mention to the
        // Unix epoch defaults (00:00:00) instead of "now" — otherwise a
        // date-only format like 'd/m/Y' would inherit the current time.
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = $errors !== false && ($errors['error_count'] > 0 || $errors['warning_count'] > 0);

        if ($dt === false || $hasErrors) {
            $where = $rowNumber !== null ? " (row {$rowNumber})" : '';
            throw new InvalidArgumentException(
                "Could not parse date \"{$value}\" using format \"{$format}\"{$where}",
            );
        }

        return $dt;
    }
}
