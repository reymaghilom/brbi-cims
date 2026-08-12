<?php

namespace App\Services\Progress;

use App\Services\Progress\Contracts\ClientProgressCalculator;
use App\Services\Progress\Contracts\ProgressResult;

class RequiredItemsProgressCalculator implements ClientProgressCalculator
{
    public function calculate(iterable $applicableRequirements): ProgressResult
    {
        $completed = [];
        $incomplete = [];

        foreach ($applicableRequirements as $label => $isSatisfied) {
            if ($isSatisfied) {
                $completed[] = $label;
            } else {
                $incomplete[] = $label;
            }
        }

        $total = count($completed) + count($incomplete);

        return new ProgressResult(
            completed: $completed,
            incomplete: $incomplete,
            percentage: $total === 0 ? 0 : round(count($completed) / $total * 100, 2),
            isComplete: $total > 0 && $incomplete === [],
        );
    }
}
