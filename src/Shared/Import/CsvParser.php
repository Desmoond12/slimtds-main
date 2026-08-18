<?php

declare(strict_types=1);

namespace App\Shared\Import;

/**
 * Thin wrapper over fgetcsv() — parses raw CSV file content (not a stream
 * resource, so it's trivial to unit-test with literal strings and callers
 * don't need to manage file-handle lifecycle) into a header row + data rows.
 */
final class CsvParser
{
    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function parse(string $content): array
    {
        // Excel/Sheets exports commonly prepend a UTF-8 BOM — left in place,
        // it silently glues itself onto the first header's name.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('failed to open in-memory stream for CSV parsing');
        }
        fwrite($stream, $content);
        rewind($stream);

        // PHP 8.4 deprecates the implicit default for $escape — pass ""
        // explicitly to disable backslash-escaping (correct for RFC 4180
        // CSV; real-world report exports don't use backslash escapes).
        $headerRow = fgetcsv($stream, escape: '');
        $headers = is_array($headerRow) ? array_map(self::clean(...), $headerRow) : [];

        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            // fgetcsv returns [null] for a fully blank line — skip it rather
            // than emitting a phantom row with no real data.
            if ($row === [null]) continue;
            $rows[] = array_map(self::clean(...), $row);
        }
        fclose($stream);

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function clean(?string $v): string
    {
        return trim((string)$v);
    }
}
