<?php

namespace App\Services\Reports\Data;

final readonly class ReportRenderOptions
{
    /**
     * @param  array{top?: float, right?: float, bottom?: float, left?: float}  $marginsInches
     */
    public function __construct(
        public float $widthInches,
        public float $heightInches,
        public array $marginsInches = [],
    ) {}

    public static function brbiDefault(): self
    {
        return new self(widthInches: 8.5, heightInches: 13.0);
    }
}
