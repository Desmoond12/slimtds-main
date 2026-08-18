<?php

declare(strict_types=1);

use App\Shared\Import\CsvParser;

beforeEach(function (): void {
    $this->parser = new CsvParser();
});

test('parses headers and rows', function (): void {
    $csv = "Date,Leads,Payout\n2026-01-01,5,25.00\n2026-01-02,3,15.00\n";
    $r = $this->parser->parse($csv);

    expect($r['headers'])->toBe(['Date', 'Leads', 'Payout']);
    expect($r['rows'])->toHaveCount(2);
    expect($r['rows'][0])->toBe(['2026-01-01', '5', '25.00']);
});

test('strips a leading UTF-8 BOM from the header row', function (): void {
    $csv = "\xEF\xBB\xBFDate,Leads\n2026-01-01,5\n";
    $r = $this->parser->parse($csv);
    expect($r['headers'][0])->toBe('Date');
});

test('skips fully blank lines', function (): void {
    $csv = "Date,Leads\n2026-01-01,5\n\n2026-01-02,3\n";
    $r = $this->parser->parse($csv);
    expect($r['rows'])->toHaveCount(2);
});

test('trims whitespace from header and cell values', function (): void {
    $csv = " Date , Leads \n 2026-01-01 , 5 \n";
    $r = $this->parser->parse($csv);
    expect($r['headers'])->toBe(['Date', 'Leads']);
    expect($r['rows'][0])->toBe(['2026-01-01', '5']);
});

test('empty content yields empty headers and rows', function (): void {
    $r = $this->parser->parse('');
    expect($r['headers'])->toBe([]);
    expect($r['rows'])->toBe([]);
});
