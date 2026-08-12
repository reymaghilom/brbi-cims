<?php

namespace Tests\Unit;

use App\Services\Reports\Data\ReportRenderOptions;
use PHPUnit\Framework\TestCase;

class ReportRenderOptionsTest extends TestCase
{
    public function test_the_brbi_default_uses_eight_and_a_half_by_thirteen_inch_paper(): void
    {
        $options = ReportRenderOptions::brbiDefault();

        $this->assertSame(8.5, $options->widthInches);
        $this->assertSame(13.0, $options->heightInches);
    }
}
