<?php

namespace Tests\Unit;

use App\Domain\Reports\ReportService;
use PHPUnit\Framework\TestCase;

class CsvSafetyTest extends TestCase
{
    /** @dataProvider dangerous */
    public function test_formula_prefixes_are_neutralised(string $input): void
    {
        $this->assertSame("'".$input, ReportService::safeCell($input));
    }

    public static function dangerous(): array
    {
        return [['=SUM(A1:A9)'], ['+1+1'], ['-2+3cmd'], ['@SUM(1)'], ["\t=1"], ["=HYPERLINK(\"http://evil\",\"x\")"]];
    }

    public function test_normal_values_are_untouched(): void
    {
        $this->assertSame('Westlands Quickmart', ReportService::safeCell('Westlands Quickmart'));
        $this->assertSame('-12.5', ReportService::safeCell('-12.5'), 'negative numbers are legitimate');
        $this->assertSame('42', ReportService::safeCell(42));
        $this->assertSame('', ReportService::safeCell(null));
        $this->assertSame('yes', ReportService::safeCell(true));
        $this->assertSame('no', ReportService::safeCell(false));
    }
}
