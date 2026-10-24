<?php

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase
{
    public function test_formulas_and_control_prefixes_cannot_start_exported_text_cells(): void
    {
        $values = ['=1+1', '+1+1', '-1+1', '@SUM(A1)', "\t=1+1", "\r=1+1", "\n=1+1", " \t=1+1", '  =1+1', '＝1+1', '＋1+1', '－1+1', '＠SUM(A1)', "=1+1\xff"];
        $this->assertSame(array_map(fn ($value) => "'".$value, $values), Csv::row($values));
    }

    public function test_regular_text_and_numeric_values_remain_unchanged(): void
    {
        $values = ['Product name', '  Name', '2026-10-25', '100.25', '', null, 100, -100, 2.5];
        $this->assertSame($values, Csv::row($values));
    }

    public function test_quoted_separators_cannot_split_a_formula_into_a_new_cell(): void
    {
        $payload = "\t=HYPERLINK(\"https://example.test\");,\"=1+1";
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, Csv::row([$payload, 'unchanged']));
        rewind($stream);
        $this->assertSame(["'".$payload, 'unchanged'], fgetcsv($stream));
        fclose($stream);
    }
}
