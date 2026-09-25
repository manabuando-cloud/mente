<?php

namespace Tests\Unit;

use App\Services\Drive\DriveLocator;
use App\Services\SimilarCaseFinder;
use App\Support\LegacyValue;
use PHPUnit\Framework\TestCase;

class NormalizationTest extends TestCase
{
    public function test_quote_numbers(): void
    {
        foreach (['est23285574', 'est_23285574', 'EST23285574.pdf', 'est23285574_事後見積り.pdf', '２３２８５５７４', '23285574'] as $v) {
            $this->assertSame('23285574', DriveLocator::normQuoteNo($v), $v);
        }
    }

    public function test_report_date_from_file_name(): void
    {
        $this->assertSame('2024-03-15', DriveLocator::reportDate('20240315_1030_ActivityReport.pdf'));
        $this->assertNull(DriveLocator::reportDate('20241345_ActivityReport.pdf'));
        $this->assertNull(DriveLocator::reportDate('ActivityReport.pdf'));
    }

    public function test_legacy_values(): void
    {
        $this->assertSame('2023-10-05', LegacyValue::date('2023/10/5'));
        $this->assertSame('2023-10-05', LegacyValue::date('2023年10月5日'));
        $this->assertSame('2023-10-05', LegacyValue::date('2023-10-04T15:00:00.000Z')); // JSTでは10/5
        $this->assertSame(12300, LegacyValue::int('¥12,300'));
        $this->assertSame(123, LegacyValue::int('１２３円'));
        $this->assertNull(LegacyValue::int(''));
        $this->assertSame('E1, E2', LegacyValue::str(['E1', 'E2']));
        $this->assertNull(LegacyValue::str('—'));
        $this->assertNull(LegacyValue::str(' ― '));
        $this->assertSame('-5', LegacyValue::str('-5'));
        $this->assertSame('650200', LegacyValue::id('650200.0'));
        $this->assertSame('650200', LegacyValue::id(650200.0));
        $this->assertSame('L_0987', LegacyValue::id('L_0987'));
        $this->assertSame('#B7032, 95100197', LegacyValue::list('["#B7032","95100197"]'));
        $this->assertNull(LegacyValue::list('[]'));
        $this->assertSame('TRUMPF', LegacyValue::stripCode('4501_TRUMPF'));
    }

    public function test_tokens_ignore_hiragana_particles(): void
    {
        $t = SimilarCaseFinder::tokens('レーザーが停止 E2105');
        $this->assertArrayHasKey('e2105', $t);
        $this->assertArrayHasKey('レー', $t);
        $this->assertArrayNotHasKey('しま', SimilarCaseFinder::tokens('停止しました'));
    }
}
